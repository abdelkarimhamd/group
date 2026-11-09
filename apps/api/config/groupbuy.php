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

        // Estimated Moyasar fee per unit for the margin check, until Moyasar
        // confirms its fees (open question in the spec's Pricing rules). A
        // share of the VAT-inclusive price plus a fixed amount, both charged
        // per unit so a one-unit join is never underestimated.
        'payment_fee_bp' => (int) env('GROUPBUY_PAYMENT_FEE_BP', 275),
        'payment_fee_fixed_halalas' => (int) env('GROUPBUY_PAYMENT_FEE_FIXED_HALALAS', 100),

        // Delivery cost per unit the platform pays on top of landed cost. The
        // landed cost breakdown already includes last-mile delivery, so this
        // stays 0 until a separate delivery charge is decided (Q12).
        'delivery_cost_estimate_halalas' => (int) env('GROUPBUY_DELIVERY_COST_ESTIMATE_HALALAS', 0),
    ],

    'campaigns' => [
        // Decision 5: campaigns run for 7 days.
        'duration_days' => 7,

        // Decision 3: one extension (campaigns.extended_at records it, so the
        // limit is not configurable), and only if the new deadline stays at
        // least this many days before the earliest active hold expires.
        'extension_buffer_days' => 3,

        // The supplier must confirm the order this long before the capture
        // deadline (capture_by), shown to admins as supplier_decision_due_at.
        'supplier_decision_lead_hours' => 24,

        // Units are reserved while the customer is on the payment form.
        'reservation_minutes' => (int) env('GROUPBUY_RESERVATION_MINUTES', 15),

        // Join attempts per customer per minute, in front of the row lock.
        'joins_per_minute' => 10,

        // The customer progress endpoint is cached this long (contract: at most 2 seconds).
        'progress_cache_seconds' => 2,

        // Proposed answer to Q16, shown to customers before they pay: a join
        // can be cancelled only while the campaign is active and before the
        // deal is confirmed.
        'cancellation_rule' => 'before_deal_confirmed',
    ],

    'payments' => [
        // Mada holds must be captured or voided within 14 days (Moyasar docs,
        // checked 1 Nov 2026). Applied to every scheme until Moyasar confirms otherwise.
        'hold_lifetime_days' => 14,

        // No capture is attempted in the last 24 hours before a hold expires.
        'capture_cutoff_hours' => 24,

        // A capture whose outcome is unknown (timeout, Moyasar down) is
        // retried with backoff for up to this long, never past the cutoff,
        // and ops are alerted once it has been failing for this many minutes.
        'capture_retry_hours' => 24,
        'capture_alert_after_minutes' => 60,

        // Finance and ops are alerted this many days before a hold expires.
        'expiry_alert_days' => 3,

        // Payment checks after the payment form, per customer per minute.
        // A customer needs one or two; the limit stops a script hammering Moyasar.
        'syncs_per_minute' => 20,

        // Webhook requests per sending IP per minute. Moyasar sends one per
        // payment event; the limit only stops floods of forged events.
        'webhooks_per_ip_per_minute' => 300,

        // Largest webhook body stored. Moyasar's events are a few kilobytes.
        'webhook_max_bytes' => 65536,

        // A verified webhook still unprocessed after this long is queued again.
        'webhook_requeue_after_minutes' => 5,

        // A full refund this soon after capture is sent as a void (no fee,
        // nothing settles); later it is a refund (spec, Payments: "Refunds").
        'void_after_capture_minutes' => 120,
    ],

    'finance' => [
        // An approved refund or campaign cancellation not finished this long
        // after it last changed is queued again by finance:requeue-approved
        // (its job ran out of tries or was lost). A copy is dropped while the
        // job's unique lock is held (ExecuteRefund 4 h, the cancellation 3 h
        // from dispatch), so a job that died mid-way resumes when that lock
        // expires; one that ran out of tries, at the next sweep.
        'requeue_after_minutes' => 15,

        // A hold we released (or whose capture failed) is voided right away;
        // reconciliation reports it as stuck only once this long has passed.
        // Longer than VoidPayment's retries (about 81 minutes) plus queue time.
        'void_grace_minutes' => 120,

        // A reconciliation run that has not moved for this long was cut off
        // (a deploy, a crash); the next run marks it failed. Also the
        // scheduler's overlap lock, so a killed run never blocks the next day.
        'reconciliation_timeout_minutes' => 180,
    ],

    'orders' => [
        // Spec, Orders: a customer may return within 7 days of delivery.
        'return_window_days' => 7,
    ],

    // Spec, E13: the last mile. The courier itself is services.courier.
    'couriers' => [
        // The warehouse parcels leave from, as the courier's booking needs it.
        'shipper' => [
            'name' => env('WAREHOUSE_CONTACT_NAME', 'Warehouse contact not set'),
            'company' => env('GROUPBUY_SELLER_NAME_EN', 'Seller name not set'),
            'phone' => env('WAREHOUSE_PHONE', '+966500000000'),
            'email' => env('WAREHOUSE_EMAIL', ''),
            'line1' => env('WAREHOUSE_ADDRESS_LINE1', 'Warehouse address not set'),
            'line2' => env('WAREHOUSE_ADDRESS_LINE2', ''),
            'city' => env('WAREHOUSE_CITY', 'Riyadh'),
            'postal_code' => env('WAREHOUSE_POSTAL_CODE', ''),
        ],
        // Used for a variant without its own weight.
        'default_unit_weight_grams' => 1000,
        // A booking still unanswered this long after it was asked for (its
        // job was lost) is queued again by shipments:track.
        'booking_requeue_after_minutes' => 30,
        // A booking sent this long ago with no answer recorded is cancelled
        // for staff to check in the courier's portal. Longer than the
        // booking job can run (BookShipment::$timeout), so a slow answer is
        // never thrown away.
        'booking_answer_within_minutes' => 15,
        // Parcels read per run of shipments:track (every thirty minutes) and
        // per call to the courier.
        'track_per_run' => 500,
        'track_per_call' => 50,
        // Where a customer follows a parcel, by courier name in lower case;
        // {number} is the tracking number. Used when staff give no link.
        'tracking_urls' => [
            'aramex' => 'https://www.aramex.com/sa/en/track/results?ShipmentNumber={number}',
        ],
        // The courier's update codes as our shipment statuses. A code listed
        // under `ignore` is not a movement (the booking itself). Any other
        // code not listed means the parcel moved: `picked_up` for a parcel
        // not yet collected, otherwise `in_transit`. Staff record a parcel
        // the courier brings back by hand. Aramex's codes are from its
        // guide: check them against real tracking before launch.
        'aramex' => [
            'codes' => [
                'SH003' => 'out_for_delivery',
                'SH005' => 'delivered',
                'SH006' => 'delivered',
            ],
            'ignore' => ['SH014'],
        ],
        'fake' => [
            'codes' => [
                'PU' => 'picked_up',
                'IT' => 'in_transit',
                'OD' => 'out_for_delivery',
                'DL' => 'delivered',
            ],
            'ignore' => ['CR'],
        ],
    ],

    // Spec, E14: sends run on the queue with retries, and every send is logged.
    'notifications' => [
        // Tries per message before it is marked failed, and the wait before
        // each retry (minutes). notifications:dispatch-due sends retries.
        'max_attempts' => 5,
        'retry_after_minutes' => [1, 5, 15, 60],
        // A message not sent this long after it was written, or after a try
        // started, lost its job and is tried again.
        'stuck_after_minutes' => 10,
        // Devices kept per customer; older tokens are dropped.
        'max_devices_per_user' => 10,
    ],

    // The seller of record printed on every VAT invoice. Real values come from
    // the commercial registration and VAT certificate (E1); the defaults mark
    // invoices made before then as not for use.
    'seller' => [
        'legal_name_ar' => env('GROUPBUY_SELLER_NAME_AR', 'اسم البائع غير محدد'),
        'legal_name_en' => env('GROUPBUY_SELLER_NAME_EN', 'Seller name not set'),
        'vat_number' => env('GROUPBUY_SELLER_VAT_NUMBER', '300000000000003'),
        'cr_number' => env('GROUPBUY_SELLER_CR_NUMBER'),
        'address_ar' => env('GROUPBUY_SELLER_ADDRESS_AR', 'الرياض، المملكة العربية السعودية'),
        'address_en' => env('GROUPBUY_SELLER_ADDRESS_EN', 'Riyadh, Saudi Arabia'),
        // The national address in parts, as the e-invoice XML (ZATCA phase 2)
        // carries it: a 4-digit building number and a 5-digit postal code.
        'street' => env('GROUPBUY_SELLER_STREET', ''),
        'building_number' => env('GROUPBUY_SELLER_BUILDING_NUMBER', ''),
        'district' => env('GROUPBUY_SELLER_DISTRICT', ''),
        'city' => env('GROUPBUY_SELLER_CITY', 'Riyadh'),
        'postal_code' => env('GROUPBUY_SELLER_POSTAL_CODE', ''),
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
        // A token's last_used_at is written at most this often, not on every
        // request (Sanctum's default), so reading a page is not a database write.
        'token_last_used_minutes' => 15,
    ],

    'account' => [
        // Customers must accept this privacy policy version (PDPL) before joining
        // a campaign. Bump it when the policy changes; everyone is asked again.
        'privacy_policy_version' => '2026-11',

        'max_addresses' => 20,
    ],

    'media' => [
        // Product photos are public. Supplier quotation files, purchase order
        // papers, warehouse receipt photos and invoice PDFs are private and
        // shown through short-lived signed links. Receipt photos and invoice
        // PDFs keep their path without the disk, so DOCUMENTS_DISK stays the
        // same once files are kept: moving buckets means copying the files.
        'disk' => env('MEDIA_DISK', 'public'),
        'documents_disk' => env('DOCUMENTS_DISK', 'local'),
        'image_max_kb' => 10240,
        // Product photos are stored as WebP no larger than this on their long
        // side (enough for the campaign page on a large screen). Listing
        // photos often weigh several megabytes, which is what a customer on a
        // weak mobile network waits for. A photo with more pixels than
        // photo_max_source_pixels is kept as uploaded, because decoding it
        // would not fit in PHP's memory limit (deploy/api/php.ini).
        'photo_max_px' => 1600,
        'photo_webp_quality' => 80,
        'photo_max_source_pixels' => 25_000_000,
        // Each photo gets a new file name and is never overwritten, so
        // browsers and the CDN may keep it for a year.
        'photo_cache_control' => 'public, max-age=31536000, immutable',
        'attachment_max_kb' => 20480,
        'attachment_url_minutes' => 30,
        // Limits that keep one record's files reviewable on one screen.
        'documents_per_purchase_order' => 50,
        'photos_per_receipt' => 30,
        // A purchase order paper can be removed this long after upload, to
        // correct a wrong file; after that it is kept as evidence.
        'document_removal_hours' => 24,
    ],

    'invoices' => [
        // Each invoice and credit note gets an Arabic and English PDF,
        // rendered on the queue once it is issued. invoices:render-pdfs
        // queues again any still missing this long after issue (a job that
        // ran out of tries or was lost).
        'pdf_requeue_after_minutes' => 10,
        // Older invoices without a PDF are not queued again on their own
        // (their render keeps failing): the sweep warns, and
        // `invoices:render-pdfs --all` queues them once the cause is fixed.
        'pdf_requeue_within_days' => 7,
    ],

    // ZATCA e-invoicing phase 2 (integration): each invoice and credit note
    // is signed by our EGS unit and reported to Fatoora within 24 hours.
    // Off until the EGS unit is onboarded (`php artisan zatca:onboard`);
    // phase 1 QR codes are printed meanwhile. Whether and when phase 2
    // applies to the company is set by ZATCA's wave notice.
    'zatca' => [
        'enabled' => (bool) env('ZATCA_ENABLED', false),
        // developer-portal (sandbox, any OTP), simulation, or core (production).
        'environment' => env('ZATCA_ENV', 'simulation'),
        'base_urls' => [
            'developer-portal' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal',
            'simulation' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation',
            'core' => 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core',
        ],
        'timeout_seconds' => 30,
        // The EGS unit (this system) as its CSR names it.
        'egs' => [
            'solution_name' => env('ZATCA_SOLUTION_NAME', 'GroupBuy'),
            'model' => env('ZATCA_EGS_MODEL', 'API'),
            // The branch name, or the 10-digit TIN for a VAT group member.
            'organization_unit' => env('ZATCA_ORGANIZATION_UNIT', 'Main Branch'),
            // Where the unit is: a short address or city.
            'location' => env('ZATCA_LOCATION', 'Riyadh'),
            'industry' => env('ZATCA_INDUSTRY', 'Retail'),
        ],
        // ZATCA wants each simplified invoice reported within 24 hours of
        // issue; past this many hours unreported, the sweep raises an alert.
        'report_alert_after_hours' => 20,
        // zatca:report queues again an invoice still unsigned or unreported
        // this long after issue (its job ran out of tries or was lost).
        'requeue_after_minutes' => 10,
        // Warn this many days before the EGS unit's certificate runs out
        // (onboard a new unit in time: zatca:onboard --fresh).
        'certificate_alert_days' => 30,
    ],

];
