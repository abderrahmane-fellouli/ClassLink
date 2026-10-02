# syntax=docker/dockerfile:1
# =============================================================================
# ClassLink 1.0 — Image de production Laravel 11 (API)
# -----------------------------------------------------------------------------
# Utilisation :
#   docker build -t classlink-api .
#   docker run -p 8000:8000 --env-file backend/.env classlink-api
#
# Le frontend (React/Vite) est un SPA statique deploye separement (Vercel) :
# il n'est PAS inclus dans cette image. Voir docs/DEPLOYMENT.md.
# =============================================================================

# -----------------------------------------------------------------------------
# Etape 1 — Dependances Composer (cache de couches)
# -----------------------------------------------------------------------------
FROM composer:2.7 AS vendor

WORKDIR /app

# Les manifestes d'abord : la couche n'est reconstruite que si les
# dependances changent, pas a chaque deploiement.
COPY backend/composer.json backend/composer.lock ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

# -----------------------------------------------------------------------------
# Etape 2 — Dependances de production
# -----------------------------------------------------------------------------
FROM php:8.3-cli-alpine AS app

# `docker-php-ext-install pdo_pdo` : SQLite (dev/tests).
# `pdo_pgsql` / `pdo_mysql` sont actives selon la base cible ; Render utilise
# generalement PostgreSQL. On installe les deux pour rester portable.
# `$PHPIZE_DEPS` et les en-tetes de developpement sont regroupes dans un lot
# virtuel supprime a la fin : l'image finale ne contient que les bibliotheques
# d'execution. `pcntl` et `posix` sont requis par Laravel pour les commandes
# en arriere-plan.
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        postgresql-dev \
    && apk add --no-cache \
        git \
        libpq \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        posix \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=vendor /app/vendor /app/vendor

# -----------------------------------------------------------------------------
# Etape 3 — Code applicatif
# -----------------------------------------------------------------------------
WORKDIR /app

# `.env` n'est jamais copie : les variables viennent de la plateforme
# (Render) ou de `--env-file`. Aucune cle n'est donc presente dans l'image.
COPY backend/ ./

# Permissions d'ecriture pour le cache et les journaux. Les fichiers deposes
# restent sur le disque prive configure (FILESYSTEM_DISK), pas dans l'image.
RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Optimisation : autoloader et metadonnees de paquet, mis en cache.
# `key:generate` est volontairement EXCLU : APP_KEY est un secret de la
# plateforme, il ne doit jamais etre genere ni conserve dans une image.
#
# L'autoloader doit etre genere APRES la copie du code, sinon il ignore les
# classes de `app/`. Le binaire composer n'existe pas dans l'image PHP : il est
# copie depuis l'etape `vendor`.
COPY --from=vendor /usr/bin/composer /usr/bin/composer

RUN composer dump-autoload --optimize --no-dev --no-scripts --classmap-authoritative \
    && php artisan package:discover --ansi

# `package:discover` s'execute en root et ecrit `bootstrap/cache/packages.php`
# et `services.php` : on rend de nouveau le cache a `www-data`, sinon le
# `config:cache` du CMD echoue sur un fichier root.
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# -----------------------------------------------------------------------------
# Etape 4 — Serveur
# -----------------------------------------------------------------------------
# `php -S` (serveur integre) convient a un service conteneurise unique. Pour une
# charge superieure, remplacer par nginx + php-fpm ; le document root reste
# `public/`.
EXPOSE 8000

# `storage:link` cree un lien symbolique dans `public/` vers le disque prive.
# Il est tolere a l'echec (le disque peut deja etre monte, et `public/` peut
# etre en lecture seule sur certains hebergeurs) : l'application sert les
# fichiers via `FILESYSTEM_DISK`, pas via le lien public.
RUN php artisan storage:link --force || true

USER www-data

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1:8000/up') === false ? 1 : 0);"

CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && php artisan view:cache && exec php -S 0.0.0.0:8000 -t public public/index.php"]
