# ClassLink 1.0 — Platforme éducative OFPPT

[![Laravel 11](https://img.shields.io/badge/Laravel-11.57-red.svg)](https://laravel.com/)
[![React 19 + Vite 8](https://img.shields.io/badge/React-19%20%2B%20Vite-8-blue.svg)](https://vitejs.dev/)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](https://www.php.net/)
[![Node 22](https://img.shields.io/badge/Node-22.20-green.svg)](https://nodejs.org/)
[![Tests](https://img.shields.io/badge/Tests-Laravel%20246%20+%20Vitest%2031-success.svg)](./README.md)

ClassLink est une plateforme d'apprentissage collaborative destinée à l'écosystème OFPPT (@ofppt-edu.ma). Elle permet aux enseignants et aux étudiants de partager des ressources, gérer des classes, des quiz, des devoirs, des flashcards et de bénéficier d'une génération IA pour la création de quiz (avec prévisualisation obligatoire). L'authentification s'appuie exclusivement sur Microsoft Entra ID et sur un code à usage unique (OTP) en secours ; aucun mot de passe n'est utilisé. Les règles d'accès (RG-01/RG-02) sont appliquées strictement côté serveur.

- **Cahier des charges** : [`cahier_des_charges/ClassLink_Cahier_des_charges.docx`](./cahier_des_charges/ClassLink_Cahier_des_charges.docx)
- **Maquettes & design** : [`design/`](./design/)
- **Documentation technique** : [`docs/`](./docs/)
- **API (Laravel 11)** : [`backend/`](./backend/)
- **Frontend (React + Vite + TS)** : [`frontend/`](./frontend/)

## Caractéristiques principales

- Authentification Microsoft Entra ID + OTP. Rôle déterminé côté serveur (exactement 13 chiffres → étudiant, motif enseignant → enseignant, autre format → en attente, domaine externe → refusé).
- Classement strict des accès : adhésion acceptée uniquement, politiques Laravel par modèle, isolation `pending/denied`.
- Fichiers privés (stockage local/S3 compatible), jamais servis publiquement ; téléchargement via URL signée authentifiée après vérification d'autorisation (RG-12).
- Quiz : notation automatique, tentatives limitées, anonymisation des réponses, résultats réservés aux enseignants, publication différée des brouillons IA.
- IA générative abstraite (trois fournisseurs ordonnancés, cache par document, quota quotidien, re-tentative sur JSON invalide, mode dégradé manuel).
- Traçabilité : journal d'audit immuable, notifications, tâches planifiées (prune, digest quotidien).
- Détection de rôle RG-01/RG-02, CORS restreint, tokens Sanctum 8h, révocation de sessions.

## Démarrage rapide (développement)

### Prérequis
- PHP 8.2+, Composer
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

Résultats (état de livraison) : backend 246 tests, 641 assertions, 0 échec ; frontend 31 tests, 0 échec ; typecheck et build de production sans avertissement. Les mentions de dépréciation visibles en PHP 8.5 proviennent des dépendances, pas du code ClassLink — voir [`docs/ASSUMPTIONS.md`](./docs/ASSUMPTIONS.md).

## Sécurité & confidentialité

- Aucun mot de passe. Jeton Bearer Sanctum dans `sessionStorage`, jamais dans l'URL. 
- CORS strictement limité aux origines configurées (`FRONTEND_URL`). 
- Fichiers privés : téléchargement via route authentifiée (pas d'URL publique). 
- Emails étudiants masqués dans listes/membres/exports non autorisés. 
- Audit immuable, logs sans PII sensibles. 
- Développement : `DEV_AUTH_ENABLED` refusé si `APP_ENV=production`.

## Déploiement

Consulter [`docs/DEPLOYMENT.md`](./docs/DEPLOYMENT.md) pour Render (backend) et Vercel (frontend), variables d'environnement, CORS, stockage, Brevo, Microsoft Entra ID, fournisseurs IA.

## Notes importantes

- Dépendance PDF : `smalot/pdf-parser` est optionnelle (absente du `composer.lock`/install courant). L'extracteur lève une exception explicite (« PDF non configuré ») pour forcer une création manuelle plutôt qu'un échec silencieux. Voir `docs/ASSUMPTIONS.md`.
- PHP 8.5 (environnement local) émet des dépréciations provenant de Laravel/collision (non liées au code applicatif ClassLink) : documentées dans `docs/ASSUMPTIONS.md`, non corrigées pour préserver la compatibilité PHP 8.3 production.
- Ne pas modifier `design/` (source visuelle de référence). Règles métier inchangées, sécurité/privacité non affaiblies.
