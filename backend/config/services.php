<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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
    | Sunbird AI — Mission Translator
    |--------------------------------------------------------------------------
    |
    | Credentials for the Sunbird AI translation, speech-to-text and
    | text-to-speech APIs used by the Mission Translator.
    |
    | The token is surfaced through config so it survives `config:cache`, which
    | production deployments run by default. Controllers must read it with
    | config('services.sunbird.token') — never env() directly.
    |
    */

    'sunbird' => [
        'token' => env('SUNBIRD_API_TOKEN'),
    ],

];
