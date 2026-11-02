<?php

return [
    'health' => [
        'ok' => 'All systems are working.',
        'degraded' => 'Some systems are not responding.',
    ],

    // API error messages, keyed by the `code` in the error body.
    'errors' => [
        'unauthenticated' => 'Please sign in to continue.',
        'forbidden' => 'You do not have permission to do this.',
        'not_found' => 'We could not find what you were looking for.',
        'validation_failed' => 'Please check the highlighted fields.',
        'too_many_requests' => 'Too many attempts. Please wait and try again.',
        'sms_unavailable' => 'We could not send the code right now. Please try again in a few minutes.',
        'otp_invalid' => 'That code is not right. Check the SMS and try again.',
        'otp_expired' => 'That code has expired. Ask for a new one.',
        'otp_attempts_exhausted' => 'Too many wrong codes. Ask for a new one.',
        'account_blocked' => 'This account is suspended. Please contact support.',
        'invalid_credentials' => 'The email or password is not right.',
        'privacy_policy_outdated' => 'The privacy policy has changed. Please read and accept the current version.',
        'address_limit_reached' => 'You have reached the maximum number of saved addresses. Remove one to add another.',
        'nothing_to_update' => 'Send at least one field to change.',
        'category_cycle' => 'A category cannot sit inside itself or one of its own subcategories.',
        'product_needs_variant' => 'Add an active variant before making the product active.',
        'last_active_variant' => 'An active product needs at least one active variant. Archive the product first.',
        'supplier_blocked' => 'This supplier is blocked. Its quotes cannot be added or accepted.',
        'quotation_expired' => 'This quote is past its validity date. Update the date before accepting it.',
        'quotation_frozen' => 'A campaign already relies on this quote, so it can no longer change. Record a new quote instead.',
        'cannot_grant_role' => 'You can only give roles whose permissions you hold yourself.',
        'cannot_manage_admin' => 'This admin has permissions you do not hold. You can block them; any other change must be made on the server.',
        'cannot_change_own_access' => 'You cannot change your own roles, status or password here. Ask another admin, or change your password from your profile.',
    ],

    'sms' => [
        'otp' => 'Your sign-in code is :code. It expires in :minutes minutes. Never share it with anyone.',
    ],
];
