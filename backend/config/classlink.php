<?php

$production = env('APP_ENV', 'production') === 'production';
$origins = array_values(array_filter(array_map('trim', explode(',', (string) env('FRONTEND_URL', $production ? '' : 'http://localhost:5173')))));
if ($production) {
    foreach (array_merge([env('APP_URL', '')], $origins ?: ['']) as $url) {
        $parts = parse_url($url);
        if (!$parts || !filter_var($url, FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with(strtolower($parts['host']), '.invalid')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('Production APP_URL and FRONTEND_URL require explicit HTTPS origins.');
        }
    }
}

return [

    /*
    |--------------------------------------------------------------------------
    | Connexion au frontend (redirections OAuth, liens de deconnexion)
    |--------------------------------------------------------------------------
    */

    'frontend_url' => $origins[0] ?? '',

    /*
    |--------------------------------------------------------------------------
    | Chemins de retour vers le frontend
    |--------------------------------------------------------------------------
    | Doivent correspondre exactement aux routes declarees dans
    | `frontend/src/router.tsx`. Une divergence ici se traduit par une
    | connexion Microsoft quiatterrit sur la page 404.
    */

    'frontend_routes' => [
        // §17.6 : reception du jeton par fragment (#token=...).
        'callback' => '/auth/microsoft/callback',
        // RG-01 / §17.6 A : « Acces refuse ».
        'denied' => '/denied',
        // §17.6 B : format inconnu -> « Compte en attente ».
        'pending' => '/pending',
    ],

    /*
    |--------------------------------------------------------------------------
    | Regle de detection du role - §7 RG-01 / RG-02, §17.5
    |--------------------------------------------------------------------------
    | Cette regle est appliquee exclusivement cote serveur. Le navigateur
    | n'envoie jamais un role et l'API ne l'accepte pas en entree.
    |
    | Ordre d'evaluation (strictement celui de la specification) :
    |   1. le domaine doit etre @ofppt-edu.ma, sinon "denied"  (RG-01)
    |   2. partie locale = exactement 13 chiffres  -> "student" (RG-02)
    |   3. partie locale = motif enseignant        -> "teacher" (RG-02)
    |   4. sinon, domaine valide                   -> "pending"
    */

    'role_detection' => [

        // Domaine autorise. RG-01 : seules ces adresses accedent a ClassLink.
        'domain' => env('CLASSLINK_ALLOWED_DOMAIN', 'ofppt-edu.ma'),

        // Etudiant : exactement 13 chiffres avant le "@".
        'student_local_regex' => '/^\d{13}$/',

        // Enseignant : mots uniquement lettres, separes par des points.
        // Tirets et apostrophes admis a l'interieur d'un mot (valeur exacte
        // de la specification §17.5).
        'teacher_local_regex' => "/^[a-z][a-z'\-]*(\.[a-z][a-z'\-]*)+$/",

        // Emplacements interdits dans la partie locale (RG-01).
        'denied_local_regex' => '/(\.\.|^\.)/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Connexion par code a usage unique - §16, F-AUTH-02
    |--------------------------------------------------------------------------
    */

    'otp' => [
        'digits' => 6,
        'ttl_minutes' => 10,      // validite du code
        'max_attempts' => 5,      // 6e essai = refus, aucun jeton emis (T-06)
        'resend_cooldown_seconds' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | duree de vie du jeton d'acces - §7 RG-19, F-AUTH-07
    |--------------------------------------------------------------------------
    */

    'session' => [
        'token_ttl_hours' => 8,
        'token_name' => 'web',
    ],

    /*
    |--------------------------------------------------------------------------
    | Adhesions - §7 RG-06 a RG-10
    |--------------------------------------------------------------------------
    */

    'membership' => [
        // RG-07 : delai impose a l'etudiant apres un rejet.
        'rejection_cooldown_hours' => 24,
        // F-CLS-02 / §17.7 : le code de classe est valide sur exactement 8 caracteres.
        'join_code_length' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fichiers deposes - §16 "Fichiers dangereux", T-21
    |--------------------------------------------------------------------------
    */

    'files' => [
        'max_kb' => 10240, // 10 Mo

        // Liste blanche. Tout autre type est refuse avec 422.
        'material_mimes' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.oasis.opendocument.text',
            'application/vnd.oasis.opendocument.presentation',
            'text/plain',
            'text/csv',
        ],

        'material_extensions' => [
            'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx',
            'odt', 'odp', 'txt', 'csv',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Intelligence artificielle - §15
    |--------------------------------------------------------------------------
    | Valeurs marquees "[a fixer]" dans la specification, resolues ici pour
    | que le superviseur puisse les valider (cf. docs/ASSUMPTIONS.md).
    | Elles restent configurables.
    */

    'ai' => [
        // §15.3 : PDF uniquement en version 1.0.
        'accepted_mimes' => ['application/pdf'],
        'max_kb' => 10240,            // [a fixer] 10 Mo
        'max_pages' => 30,            // [a fixer] 30 pages
        'daily_quota_per_teacher' => 10, // [a fixer] 10 generations / jour
        'max_questions' => 30,
        'cache_days' => 30,
        'request_timeout' => 60,
        // §15.2 : un fournisseur en echec peut etre ignore quelques minutes.
        'cooldown_minutes' => 5,
        // §15.3 : une nouvelle tentative si le JSON est invalide.
        'max_retries_on_invalid_json' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tâches planifiées - §17.11
    |--------------------------------------------------------------------------
    | Secret partagé entre l'API et la tâche GitHub Actions qui appelle
    | POST /api/internal/daily-digest et /api/internal/prune.
    */

    'digest_token' => env('DIGEST_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Limitation de débit - §16 "Force brute et abus"
    |--------------------------------------------------------------------------
    */

    /*
     | Format `<tentatives>,<minutes>`. Le middleware `throttle` sans limiteur
     | nommé s'appuie sur l'adresse IP de l'appelant, pas sur l'e-mail fourni :
     | la protection anti-abus reste donc effective meme si l'adresse est
     | fausse ou unknown. Le quota par enseignant de la generation IA est, lui,
     | gère en base (`AiProvider::used_today`).
     */
    'throttle' => [
        'otp_request' => '3,1',  // 3 demandes par minute et par IP
        'otp_verify' => '10,1',  // 10 verifications par minute et par IP
        'oauth_redirect' => '30,1',
        'ai_generate' => '20,1', // 20 generations par minute et par IP
        'join_request' => '10,1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Mode developpement - §25
    |--------------------------------------------------------------------------
    | Autorise un jeton sur un compte de demonstration sans Microsoft ni
    | email. Refuse categoriquement en production.
    */

    'dev_auth' => [
        'enabled' => env('DEV_AUTH_ENABLED', false) && env('APP_ENV') !== 'production',
    ],

    /*
    |--------------------------------------------------------------------------
    | CORS
    |--------------------------------------------------------------------------
    | Les origines autorisees sont derivees de `classlink.allowed_origins`
    | (lui-meme issu de FRONTEND_URL) afin de n'avoir qu'une seule source de
    | verite. Voir `config/cors.php` : sans ce fichier, Laravel 11 applique
    | son defaut `allowed_origins => ['*']`.
    */

    'allowed_origins' => $origins,
];
