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

];
