# ClassLink 1.0 — Platforme éducative OFPPT

[![Laravel 12](https://img.shields.io/badge/Laravel-12.69-red.svg)](https://laravel.com/)
[![React 19 + Vite 8](https://img.shields.io/badge/React-19%20%2B%20Vite-8-blue.svg)](https://vitejs.dev/)
[![PHP 8.4.1+](https://img.shields.io/badge/PHP-8.4.1%2B-777BB4.svg)](https://www.php.net/)
[![Node 22](https://img.shields.io/badge/Node-22.20-green.svg)](https://nodejs.org/)
[![CI](https://img.shields.io/badge/CI-tests%20%2B%20security%20%2B%20infrastructure-blue.svg)](./.github/workflows/ci.yml)

ClassLink est une plateforme d'apprentissage collaborative destinée à l'écosystème OFPPT (@ofppt-edu.ma). Elle permet aux enseignants et aux étudiants de partager des ressources, gérer des classes, des quiz, des devoirs, des flashcards et de bénéficier d'une génération IA pour la création de quiz (avec prévisualisation obligatoire). L'authentification s'appuie exclusivement sur Microsoft Entra ID et sur un code à usage unique (OTP) en secours ; aucun mot de passe n'est utilisé. Les règles d'accès (RG-01/RG-02) sont appliquées strictement côté serveur.

- **Cahier des charges** : [`cahier_des_charges/ClassLink_Cahier_des_Charges_Professionnel.pdf`](./cahier_des_charges/ClassLink_Cahier_des_Charges_Professionnel.pdf)
- **Maquettes & design** : [`design/`](./design/)
- **Documentation technique** : [`docs/`](./docs/)
- **API (Laravel 12)** : [`backend/`](./backend/)
- **Frontend (React + Vite + TS)** : [`frontend/`](./frontend/)

## Caractéristiques principales

- Authentification Microsoft Entra ID + OTP. Classification prudente côté serveur : identifiant entièrement numérique → candidat stagiaire ; adresse OFPPT non numérique → candidat formateur, sans privilège enseignant avant approbation du super admin. Hors domaine → refusé. L’OTP ne certifie jamais une identité Microsoft.
- Classement strict des accès : adhésion acceptée uniquement, politiques Laravel par modèle, isolation `pending/denied`.
- Fichiers privés (stockage local/S3 compatible), jamais servis publiquement ; téléchargement via URL signée authentifiée après vérification d'autorisation (RG-12).
- Quiz : notation automatique, tentatives limitées, anonymisation des réponses, résultats réservés aux enseignants, publication différée des brouillons IA.
- IA générative abstraite (trois fournisseurs ordonnancés, cache par document, quota quotidien, re-tentative sur JSON invalide, mode dégradé manuel).
- Traçabilité : journal d'audit immuable, notifications, tâches planifiées (prune, digest quotidien).
- Comptes OFPPT : domaine exact `ofppt-edu.ma`, convention numérique observée sans hypothèse de longueur ni date de naissance inférée.
- Identité Microsoft durable par couple tenant/objet Graph, distincte de l’autorisation ClassLink. Voir [`docs/OFPPT_MICROSOFT_AUTH.md`](./docs/OFPPT_MICROSOFT_AUTH.md).
- CORS restreint, tokens Sanctum 8h, révocation de sessions.

## Démarrage rapide (développement)

### Prérequis
- PHP 8.4.1+, Composer (Docker/CI use stable PHP 8.4; locked Symfony 8.1 packages are retained)
- Node.js 22.20.0, npm 10.9.3
- SQLite (local par défaut) ou PostgreSQL/MySQL

### 1) Backend Laravel
```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
touch database/database.sqlite  # ou configurer DB_*
php artisan migrate --force
php artisan db:seed --force  # données de démonstration (hors production)
php artisan serve --host=127.0.0.1 --port=8000
```

Comptes de démonstration (voir `database/seeders/DemoSeeder.php`) : `admin.classlink@ofppt-edu.ma`, `zakariyae.chergui@ofppt-edu.ma`, `hamza.bouzid@ofppt-edu.ma`, `2007031400094@ofppt-edu.ma`, `2007031400095@ofppt-edu.ma`.

### 2) Frontend React/Vite
```bash
cd frontend
cp .env.example .env
npm ci
npm run dev  # http://localhost:5173
```

Le proxy Vite relaye `/api` vers `http://127.0.0.1:8000` (cf. `.env.example`). En production, définir `VITE_API_URL`.

Sous Windows, après cette configuration, `.\scripts\start-local.ps1 -BackgroundJobs`
depuis la racine ouvre des terminaux persistants. Ouvrir
`http://127.0.0.1:5173/login` et choisir un rôle de démonstration pour tester
sans Microsoft ni SMTP. Garder les fenêtres des serveurs ouvertes.
Le rapport de vérification locale actuel est [`docs/LOCAL_POLISH_REPORT.md`](./docs/LOCAL_POLISH_REPORT.md).

## Tests & qualité

```bash
# Backend
cd backend
php artisan test

# Frontend
cd frontend
npm test -- --run
npx tsc -b --noEmit
npm run build
```

Les resultats courants et limites de verification sont consignes dans [`docs/LOCAL_POLISH_REPORT.md`](./docs/LOCAL_POLISH_REPORT.md). CI installe les dependances proprement, teste SQLite/PostgreSQL, construit le frontend et l'image, execute les audits de securite et les controles d'infrastructure. Un audit en echec bloque la livraison. Les deprecations PHP 8.5 locales ne sont pas une preuve de compatibilite du runtime PHP 8.4 de production.

## Sécurité & confidentialité

- Aucun mot de passe. Jeton Bearer Sanctum dans `sessionStorage`, jamais dans l'URL. 
- CORS strictement limité aux origines configurées (`FRONTEND_URL`). 
- Fichiers privés : téléchargement via route authentifiée (pas d'URL publique). 
- Emails étudiants masqués dans listes/membres/exports non autorisés. 
- Audit immuable, logs sans PII sensibles. 
- Développement : `DEV_AUTH_ENABLED` refusé si `APP_ENV=production`.

## Déploiement

Consulter [`docs/DEPLOYMENT.md`](./docs/DEPLOYMENT.md) pour Render (backend) et Vercel (frontend), variables d'environnement, CORS, stockage, Brevo, Microsoft Entra ID, fournisseurs IA.

La section 9 est le runbook actuel. L'image utilise nginx/PHP-FPM, une file database
et un processus `artisan schedule:run`; le frontend reste deploye separement.
La production fiable necessite un hote toujours actif: le blueprint Render est
payant, pas une promesse de 0 MAD. Aucune integration externe n'a ete deployee ici.

Pour Compose, generer `APP_KEY` dans `backend/.env`, puis lancer
`docker compose --env-file backend/.env up --build --wait`. La base PostgreSQL
locale et les fichiers prives sont persistants; la pile execute worker/scheduler.
Ne jamais utiliser ses identifiants locaux en production.

Pour un backend lance sans Docker, executer dans deux autres terminaux
`php artisan queue:work database --tries=1 --timeout=900` et
`php artisan schedule:work` depuis `backend`. Aucun worker signifie aucun
traitement IA asynchrone. Le runtime conteneurise utilise `schedule:run` directement.

Les outils independants vivent dans `scripts/`: `npm --prefix scripts ci`,
`npm --prefix scripts test`, `node scripts/validate-schema.mjs`, puis
`npm --prefix scripts run browser` apres installation des navigateurs Playwright.
Sauvegardes, restauration, alertes, recette et charge 200 utilisateurs sont
documentees dans le runbook. Voir aussi [`CONTRIBUTING.md`](./CONTRIBUTING.md).

## Notes importantes

- Dépendance PDF : `smalot/pdfparser` est installée et verrouillée. L’extraction réelle du texte et du nombre de pages est testée ; les fournisseurs IA restent à vérifier avec leurs clés externes.
- PHP 8.5 (environnement local) émet des dépréciations provenant de Laravel/collision (non liées au code applicatif ClassLink) : documentées dans `docs/ASSUMPTIONS.md`, non corrigées pour préserver la compatibilité PHP 8.4 production. Le plancher supporté est PHP 8.4.1 (Dockerfile `php:8.4-fpm-alpine`, CI `php-version: '8.4'`), imposé par les paquets Symfony 8.1 verrouillés.
- La construction de l'image de production n'a pas pu être exécutée sur cette machine Windows (ni Docker ni WSL) : la vérification correspondante est le job CI `infrastructure` (`docker compose up --build`, probes `/up` et `/ready`, probe de file, `schedule:run`, cycle sauvegarde/restauration).
- Ne pas modifier `design/` (source visuelle de référence). Règles métier inchangées, sécurité/privacité non affaiblies.
