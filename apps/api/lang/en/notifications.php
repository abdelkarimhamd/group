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
    'joined_closing' => [
        'title' => "You're in",
        'body' => 'You joined :campaign for :qty. We placed a hold of :hold SAR on your card. The campaign is closing now: if the deal goes ahead you pay the final price, which may be lower; if not, the hold is released.',
    ],
    'deal_confirmed' => [
        'title' => 'Deal confirmed',
        'body' => ':campaign reached its target, so the deal is on. The price can still drop until :deadline; then you are charged the final price.',
    ],
    'deal_confirmed_closing' => [
        'title' => 'Deal confirmed',
        'body' => ':campaign reached its target and is closing now, so the deal is on. You will be charged the final price shortly.',
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
    'hold_voided' => [
        'title' => 'Hold released',
        'body' => 'Your payment for :campaign came through after your place was no longer held, so we released the hold of :hold SAR and nothing was charged. Your bank may take a few days to show it.',
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
    'refunded' => [
        'title' => 'Refund sent',
        'body' => 'We refunded :amount SAR for order :order to your card. Your bank may take up to 14 days to show it.',
    ],
    'delivery_failed' => [
        'title' => 'Delivery failed',
        'body' => 'The courier could not deliver order :order and brought it back to us. We will contact you to deliver it again.',
    ],
    'return_accepted' => [
        'title' => 'Return accepted',
        'body' => 'We accepted the return of order :order. We will refund :amount SAR to your card once the refund is approved.',
    ],
    'return_declined' => [
        'title' => 'Return not accepted',
        'body' => 'We could not accept the return of order :order. Contact us if you have any questions.',
    ],
];
