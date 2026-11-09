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

    /*
    |--------------------------------------------------------------------------
    | Google Gemini — AI Learning Companion
    |--------------------------------------------------------------------------
    |
    | Credentials for the Google Gemini AI API used by the AI Learning Companion.
    |
    | The API key is surfaced through config so it survives `config:cache`.
    | Controllers must read it with config('services.gemini.api_key') — never env() directly.
    |
    */

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-1.5-flash'),
        'timeout' => env('GEMINI_TIMEOUT', 120),
        'embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-2'),
        'embedding_dimensions' => env('GEMINI_EMBEDDING_DIMENSIONS', 768),
        'embedding_timeout' => env('GEMINI_EMBEDDING_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Service Provider Selection
    |--------------------------------------------------------------------------
    |
    | The AI provider to use for the AI Learning Companion.
    | Currently supported: 'gemini'
    |
    */

    'ai' => [
        'provider' => env('AI_PROVIDER', 'gemini'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Book Retrieval Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for hybrid book retrieval using vector search and
    | full-text search with Reciprocal Rank Fusion (RRF).
    |
    */

    'retrieval' => [
        'vector_limit' => env('RETRIEVAL_VECTOR_LIMIT', 20),
        'fulltext_limit' => env('RETRIEVAL_FULLTEXT_LIMIT', 20),
        'final_limit' => env('RETRIEVAL_FINAL_LIMIT', 10),
        'rrf_k' => env('RETRIEVAL_RRF_K', 60),
        'min_similarity' => env('RETRIEVAL_MIN_SIMILARITY', 0.5),
        'max_passages' => env('RETRIEVAL_MAX_PASSAGES', 5),
        'require_book_context' => env('RETRIEVAL_REQUIRE_BOOK_CONTEXT', true),
        'max_tokens' => env('RETRIEVAL_MAX_TOKENS', 2000),
    ],

];
