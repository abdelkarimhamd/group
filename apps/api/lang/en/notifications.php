<?php

// Customer messages (spec, E14), sent by SMS and push. The body is the SMS
// text. Placeholders come from the message payload (see
// App\Modules\Notifications\Services\Templates); amounts are in SAR and
// times in Riyadh time.
return [
    'joined' => [
        'title' => "You're in",
        'body' => 'You joined :campaign for :qty. We placed a hold of :hold SAR on your card. If the deal goes ahead after :deadline you pay the final price, which may be lower; if not, the hold is released.',
    ],
    'deal_confirmed' => [
        'title' => 'Deal confirmed',
        'body' => ':campaign reached its target, so the deal is on. The price can still drop until :deadline; then you are charged the final price.',
    ],
    'extended' => [
        'title' => 'More time to join',
        'body' => ':campaign now closes on :deadline. Your hold stays in place.',
    ],
    'campaign_failed' => [
        'title' => "The deal didn't go ahead",
        'body' => ":campaign didn't go ahead. Your hold of :hold SAR is released and nothing was charged. Your bank may take a few days to show it.",
    ],
    'campaign_cancelled' => [
        'title' => 'Campaign cancelled',
        'body' => ':campaign was cancelled. Your hold of :hold SAR is released and nothing was charged. Your bank may take a few days to show it.',
    ],
    'join_cancelled' => [
        'title' => 'Join cancelled',
        'body' => 'You cancelled your join for :campaign. Your hold of :hold SAR is released and nothing was charged.',
    ],
    'capture_failed' => [
        'title' => "Payment didn't go through",
        'body' => "We couldn't charge your card for :campaign, so no order was made. Your hold of :hold SAR is released.",
    ],
    'charged' => [
        'title' => 'Payment taken',
        'body' => 'We charged :total SAR for :qty of :campaign at the final price. Your order number is :order. The rest of your hold, if any, is released.',
    ],
    'shipped' => [
        'title' => 'Your order is on its way',
        'body' => 'Order :order is on its way with :courier. Tracking number: :tracking.',
    ],
    'delivered' => [
        'title' => 'Delivered',
        'body' => 'Order :order was delivered. You can return unused items until :return_by.',
    ],
];
