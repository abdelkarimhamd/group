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

    // Sign-in codes. Only the "log" driver exists until a Saudi SMS provider
    // is chosen; it writes messages to the log and refuses to run in production.
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'log_channel' => env('SMS_LOG_CHANNEL'),
    ],

    // Firebase Cloud Messaging comes with the real integrations; until then
    // push messages are written to the log (refused in production).
    'push' => [
        'driver' => env('PUSH_DRIVER', 'log'),
        'log_channel' => env('PUSH_LOG_CHANNEL'),
    ],

    // Card payments. Until the merchant account is approved the "fake" driver
    // stands in for Moyasar: it authorizes, captures and voids without any
    // network call, and refuses to run in production. See docs/architecture.md.
    'moyasar' => [
        'driver' => env('MOYASAR_DRIVER', 'fake'),
        'publishable_key' => env('MOYASAR_PUBLISHABLE_KEY', 'pk_test_fake'),
        // The secret token set on the webhook in the Moyasar dashboard. Empty
        // means every webhook fails verification.
        'webhook_secret' => env('MOYASAR_WEBHOOK_SECRET', ''),
        // Where Moyasar sends the customer after the payment form.
        'callback_base_url' => env('WEB_URL', 'http://localhost:3000'),
    ],

];
