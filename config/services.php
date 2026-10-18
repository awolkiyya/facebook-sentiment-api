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

    'facebook' => [
        'client_id' => env('META_APP_ID'),
        'client_secret' => env('META_APP_SECRET'),
        'graph_version' => env('META_GRAPH_VERSION', 'v25.0'),
        'redirect_uri' => env('META_REDIRECT_URI'),
        'log_full_response' => true,

        'verify_post_persistence' => false,
    ],
    
    'ai' => [
        'url' => env(
            'AI_SERVICE_URL',
            'http://127.0.0.1:8000',
        ),
    
        'key' => env(
            'AI_SERVICE_KEY',
        ),
    
        'timeout' => (int) env(
            'AI_SERVICE_TIMEOUT',
            30,
        ),
    ],
    
    'degertu_ai' => [
        'chat_url' => env('DEGERTU_AI_CHAT_URL'),
        'api_key' => env('DEGERTU_AI_API_KEY'),
    
        'default_model' => env(
            'DEGERTU_AI_DEFAULT_MODEL',
            'qwen2.5:7b'
        ),
        'num_predict' => (int) env('DEGERTU_AI_NUM_PREDICT', 1024),
        'timeout' => (int) env('DEGERTU_AI_TIMEOUT', 120),
        'think' => (bool) env('DEGERTU_AI_THINK', false),
        'allowed_models' => array_values(array_filter(array_map(
            'trim',
            explode(
                ',',
                env(
                    'DEGERTU_AI_ALLOWED_MODELS',
                    'qwen2.5:7b,qwen3:32b'
                )
            )
                ))),
    ],

];
