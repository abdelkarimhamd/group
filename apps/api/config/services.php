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

    // Sign-in codes and customer messages. "unifonic" (the default choice,
    // see docs/architecture.md) or "taqnyat" send real SMS; "log" writes them
    // to the log and refuses to run in production.
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'log_channel' => env('SMS_LOG_CHANNEL'),
        // The approved transactional sender name (English, at most 11
        // characters). Unifonic falls back to the app's default when empty;
        // Taqnyat needs it.
        'sender' => env('SMS_SENDER', ''),
        // A send that has not answered by then is retried (notifications) or
        // reported (sign-in codes); Unifonic drops the repeat by its reference.
        'timeout_seconds' => (int) env('SMS_TIMEOUT_SECONDS', 10),
        'unifonic' => [
            'app_sid' => env('UNIFONIC_APP_SID', ''),
            'base_url' => env('UNIFONIC_BASE_URL', 'https://el.cloud.unifonic.com'),
        ],
        'taqnyat' => [
            'token' => env('TAQNYAT_TOKEN', ''),
            'base_url' => env('TAQNYAT_BASE_URL', 'https://api.taqnyat.sa'),
        ],
    ],

    // Push messages to the mobile app. "expo" sends through Expo's push
    // service (Firebase and APNs credentials live in EAS); "log" writes them
    // to the log and refuses to run in production.
    'push' => [
        'driver' => env('PUSH_DRIVER', 'log'),
        'log_channel' => env('PUSH_LOG_CHANNEL'),
        'expo' => [
            'url' => env('EXPO_PUSH_URL', 'https://exp.host/--/api/v2/push/send'),
            // Needed only when "enhanced push security" is on in the Expo project.
            'access_token' => env('EXPO_ACCESS_TOKEN', ''),
            'timeout_seconds' => (int) env('EXPO_PUSH_TIMEOUT_SECONDS', 10),
        ],
    ],

    // Card payments. "moyasar" calls Moyasar's API with the secret key (test
    // keys sk_test_/pk_test_ outside production, live keys in production).
    // Until the merchant account is approved the "fake" driver stands in: it
    // authorizes, captures and voids without any network call, and refuses
    // to run in production. See docs/architecture.md.
    'moyasar' => [
        'driver' => env('MOYASAR_DRIVER', 'fake'),
        'publishable_key' => env('MOYASAR_PUBLISHABLE_KEY', 'pk_test_fake'),
        'secret_key' => env('MOYASAR_SECRET_KEY', ''),
        'base_url' => env('MOYASAR_BASE_URL', 'https://api.moyasar.com/v1'),
        // A capture, void or refund that has not answered by then is an unknown
        // outcome: the caller asks Moyasar before trying again.
        'timeout_seconds' => (int) env('MOYASAR_TIMEOUT_SECONDS', 20),
        'connect_timeout_seconds' => (int) env('MOYASAR_CONNECT_TIMEOUT_SECONDS', 5),
        // The secret token set on the webhook in the Moyasar dashboard. Empty
        // means every webhook fails verification.
        'webhook_secret' => env('MOYASAR_WEBHOOK_SECRET', ''),
        // Where Moyasar sends the customer after the payment form.
        'callback_base_url' => env('WEB_URL', 'http://localhost:3000'),
    ],

];
