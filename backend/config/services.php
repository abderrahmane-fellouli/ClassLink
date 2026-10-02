<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Microsoft Entra ID (Azure AD) - §17.3 / §17.6
    |--------------------------------------------------------------------------
    */

    'azure' => [
        'client_id' => env('AZURE_CLIENT_ID'),
        'client_secret' => env('AZURE_CLIENT_SECRET'),
        'redirect' => env('AZURE_REDIRECT_URI'),
        'tenant' => env('AZURE_TENANT_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fournisseurs IA - §15.2
    |--------------------------------------------------------------------------
    | Les cles secrètes restent dans les variables d'environnement. La base
    | ne stocke que le nom, la priorite, l'activation et le quota, ce qui
    | permet a l'administrateur de reordonner les fournisseurs sans jamais
    | exposer une cle.
    */

    'ai' => [
        'openai' => [
            'key' => env('AI_PROVIDER_1_KEY'),
            'base_url' => env('AI_PROVIDER_1_BASE_URL', 'https://api.openai.com/v1'),
            'model' => env('AI_PROVIDER_1_MODEL', 'gpt-4o-mini'),
        ],
        'groq' => [
            'key' => env('AI_PROVIDER_2_KEY'),
            'base_url' => env('AI_PROVIDER_2_BASE_URL', 'https://api.groq.com/openai/v1'),
            'model' => env('AI_PROVIDER_2_MODEL', 'llama-3.3-70b-versatile'),
        ],
        'mistral' => [
            'key' => env('AI_PROVIDER_3_KEY'),
            'base_url' => env('AI_PROVIDER_3_BASE_URL', 'https://api.mistral.ai/v1'),
            'model' => env('AI_PROVIDER_3_MODEL', 'mistral-small-latest'),
        ],
    ],

];
