<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sprintlab GitHub App
    |--------------------------------------------------------------------------
    |
    | One GitHub App does three jobs: sign-in (its client id and secret live in
    | config/services.php), API access as the app (app id and private key) and
    | webhooks (the webhook secret).
    |
    */

    'app_id' => env('GITHUB_APP_ID'),

    'app_slug' => env('GITHUB_APP_SLUG', 'sprintlab'),

    // The app's private key: either the PEM text with "\n" for the line
    // breaks, or the whole .pem file base64-encoded on one line.
    'private_key' => env('GITHUB_APP_PRIVATE_KEY'),

    'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),

    'api_url' => env('GITHUB_API_URL', 'https://api.github.com'),

];
