<?php

/*
|--------------------------------------------------------------------------
| Group buying business rules
|--------------------------------------------------------------------------
|
| Every number here comes from a decision recorded in the MVP build spec.
| Change a value only when the decision changes, and say so in the PR.
|
*/

return [

    // Phase 1 sells in Saudi Arabia only.
    'currency' => 'SAR',

    // Times are stored in UTC and shown to people in Riyadh time.
    'display_timezone' => 'Asia/Riyadh',

    // Arabic first, English second. The first entry is the default.
    'locales' => ['ar', 'en'],

    // Saudi standard VAT, in basis points (1500 = 15%). Customer prices include VAT.
    'vat_rate_bp' => 1500,

    'pricing' => [
        // Decided 2 Nov 2026: every price tier must clear this margin on the
        // price before VAT, measured against landed cost. 1500 = 15%.
        'min_margin_bp' => (int) env('GROUPBUY_MIN_MARGIN_BP', 1500),
    ],

    'campaigns' => [
        // Decision 5: campaigns run for 7 days.
        'duration_days' => 7,

        // Decision 3: one extension, and only if the new deadline stays at
        // least this many days before the earliest active hold expires.
        'max_extensions' => 1,
        'extension_buffer_days' => 3,

        // Units are reserved while the customer is on the payment form.
        'reservation_minutes' => (int) env('GROUPBUY_RESERVATION_MINUTES', 15),
    ],

    'payments' => [
        // Mada holds must be captured or voided within 14 days (Moyasar docs,
        // checked 1 Nov 2026). Applied to every scheme until Moyasar confirms otherwise.
        'hold_lifetime_days' => 14,

        // No capture is attempted in the last 24 hours before a hold expires.
        'capture_cutoff_hours' => 24,

        // Finance and ops are alerted this many days before a hold expires.
        'expiry_alert_days' => 3,
    ],

    'auth' => [
        // Sign-in codes sent by SMS, for customers and for admins' second step.
        'otp' => [
            // Changing the length also means changing the code pattern in openapi/v1.yaml.
            'length' => 6,
            'ttl_minutes' => 5,
            // Wrong codes allowed before the code is spent.
            'max_attempts' => 5,
            // Minimum wait before another code can go to the same number.
            'resend_seconds' => 60,
            // Codes per phone number and per IP address, per hour.
            'per_phone_per_hour' => 5,
            'per_ip_per_hour' => 20,
            // Code checks per IP address, per minute.
            'verify_per_ip_per_minute' => 30,
            // Old challenges (they hold IP addresses) are deleted after this.
            'prune_after_days' => 30,
        ],

        // Admin password step: attempts per email (from any IP address), per
        // email and IP address, and per IP address alone, in each window.
        'admin_login' => [
            'per_email' => 10,
            'per_email_and_ip' => 5,
            'per_ip' => 20,
            'window_minutes' => 15,
        ],

        // How long a sign-in lasts before the person must sign in again.
        'customer_token_days' => 90,
        'admin_token_hours' => 12,
    ],

    'account' => [
        // Customers must accept this privacy policy version (PDPL) before joining
        // a campaign. Bump it when the policy changes; everyone is asked again.
        'privacy_policy_version' => '2026-11',

        'max_addresses' => 20,
    ],

    'media' => [
        // Product photos are public. Supplier quotation files are private and
        // shown through short-lived signed links.
        'disk' => env('MEDIA_DISK', 'public'),
        'documents_disk' => env('DOCUMENTS_DISK', 'local'),
        'image_max_kb' => 10240,
        'attachment_max_kb' => 20480,
        'attachment_url_minutes' => 30,
    ],

];
