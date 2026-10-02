<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| Le frontend est deploye sur un autre domaine que l'API (Vercel pour le
| SPA, Render pour Laravel). Sans ce fichier, Laravel 11 applique le defaut
| du framework, `allowed_origins => ['*']`, ce qui autoriserait n'importe
| quel site a appeler l'API. Les origines autorisees proviennent donc de
| `classlink.allowed_origins`, lui-meme derive de FRONTEND_URL : une seule
| variable d'environnement a renseigner des deux cotes.
|
| L'API est sans etat (§16 « Vol de jeton ») : elle n'emploie que l'en-tete
| `Authorization: Bearer`. `supports_credentials` reste donc a `false` et
| aucun cookie de session n'est accepte.
|
| Reference : docs/DEPLOYMENT.md § « CORS ».
|
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => config('classlink.allowed_origins', []),

    /*
     | Un joker de sous-domaine est utile pour previsualiser plusieurs
     | branches (ex. `*.vercel.app`). Il reste desactive par defaut : avec
     | un jeton Bearer, unorigine en trop ne constitue pas une escalade,
     | mais la liste blanche explicite reste la reference.
     */
    'allowed_origins_patterns' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', '')))
    )),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
