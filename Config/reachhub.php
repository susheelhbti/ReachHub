<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Authentication
    |--------------------------------------------------------------------------
    */
    'api_token'  => env('REACHHUB_API_TOKEN', null),
    'auth_mode'  => env('REACHHUB_AUTH_MODE', 'single_token'), // single_token | api_keys

    /*
    |--------------------------------------------------------------------------
    | Route Prefix & Middleware
    |--------------------------------------------------------------------------
    */
    'route_prefix' => env('REACHHUB_ROUTE_PREFIX', 'api/reachhub'),
    'middleware'   => ['api', 'reachhub.auth'],

    /*
    |--------------------------------------------------------------------------
    | Queue Settings
    |--------------------------------------------------------------------------
    */
    'queue'            => env('REACHHUB_QUEUE', 'campaigns'),
    'queue_connection' => env('REACHHUB_QUEUE_CONNECTION', 'sync'),

    /*
    |--------------------------------------------------------------------------
    | Duplicate Detection
    |--------------------------------------------------------------------------
    | Prevents sending the same content to the same contact within 7 days.
    | Disable if you intentionally resend the same campaigns.
    */
    'duplicate_detection' => env('REACHHUB_DUPLICATE_DETECTION', true),

    /*
    |--------------------------------------------------------------------------
    | AI Engine
    |--------------------------------------------------------------------------
    | driver: 'none' (disabled) | 'ollama' (local, free) | 'openai' (cloud)
    |
    | Ollama setup: https://ollama.com — run `ollama pull mistral` first.
    */
    'ai' => [
        'driver' => env('REACHHUB_AI_DRIVER', 'none'),
        'ollama' => [
            'url'   => env('OLLAMA_URL',   'http://localhost:11434'),
            'model' => env('OLLAMA_MODEL', 'mistral'),
        ],
        'openai' => [
            'key'   => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-3.5-turbo'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Channel Rate Limits (per second)
    |--------------------------------------------------------------------------
    | Max sends per channel per second. Set 0 to disable limiting.
    | These protect you from exceeding provider API rate limits.
    */
    'rate_limits' => [
        'email'    => ['max' => env('REACHHUB_EMAIL_RATE', 100), 'per_seconds' => 1],
        'whatsapp' => ['max' => env('REACHHUB_WA_RATE',   80),  'per_seconds' => 1],
        'sms'      => ['max' => env('REACHHUB_SMS_RATE',   1),   'per_seconds' => 1],
        'push'     => ['max' => env('REACHHUB_PUSH_RATE',  500), 'per_seconds' => 1],
    ],

    /*
    |--------------------------------------------------------------------------
    | GDPR / Privacy
    |--------------------------------------------------------------------------
    */
    'privacy' => [
        'auto_erase_after_years' => env('REACHHUB_GDPR_YEARS', 2),
        'encrypt_pii'            => env('REACHHUB_ENCRYPT_PII', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Channel
    |--------------------------------------------------------------------------
    */
    'email' => [
        'enabled'      => env('REACHHUB_EMAIL_ENABLED', true),
        'from_address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'from_name'    => env('MAIL_FROM_NAME', 'ReachHub'),
        'batch_size'   => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Channel (Meta Cloud API)
    |--------------------------------------------------------------------------
    */
    'whatsapp' => [
        'enabled'              => env('REACHHUB_WHATSAPP_ENABLED', false),
        'phone_id'             => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token'         => env('WHATSAPP_ACCESS_TOKEN'),
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN', 'reachhub-verify'),
        'api_version'          => 'v19.0',
        'base_url'             => 'https://graph.facebook.com',
        'batch_size'           => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS Channel (Twilio or Vonage)
    |--------------------------------------------------------------------------
    */
    'sms' => [
        'enabled'   => env('REACHHUB_SMS_ENABLED', false),
        'provider'  => env('REACHHUB_SMS_PROVIDER', 'twilio'),
        'max_parts' => env('REACHHUB_SMS_MAX_PARTS', 3),
        'twilio' => [
            'account_sid' => env('TWILIO_ACCOUNT_SID'),
            'auth_token'  => env('TWILIO_AUTH_TOKEN'),
            'from'        => env('TWILIO_FROM_NUMBER'),
        ],
        'vonage' => [
            'api_key'    => env('VONAGE_API_KEY'),
            'api_secret' => env('VONAGE_API_SECRET'),
            'from'       => env('VONAGE_FROM'),
        ],
        'batch_size' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Push Notification Channel (Firebase FCM)
    |--------------------------------------------------------------------------
    */
    'push' => [
        'enabled'        => env('REACHHUB_PUSH_ENABLED', false),
        'fcm_server_key' => env('FCM_SERVER_KEY'),
        'batch_size'     => 500,
    ],

];
