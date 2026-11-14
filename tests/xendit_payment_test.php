<?php
require_once __DIR__ . '/../includes/payments.php';

function assert_xendit_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
}

$validCheckoutUrls = [
    'https://xen.to/checkout/session',
    'https://dev.xen.to/checkout/session',
    'https://checkout.xendit.co/session',
];
foreach ($validCheckoutUrls as $checkoutUrl) {
    assert_xendit_same(true, xendit_is_valid_checkout_url($checkoutUrl), 'valid checkout host');
}

$invalidCheckoutUrls = [
    'http://checkout.xendit.co/session',
    'https://xen.to.attacker.example/session',
    'https://xendit.co.attacker.example/session',
    'https://user@checkout.xendit.co/session',
    'https://xendit.co/session',
];
foreach ($invalidCheckoutUrls as $checkoutUrl) {
    assert_xendit_same(false, xendit_is_valid_checkout_url($checkoutUrl), 'invalid checkout host');
}
assert_xendit_same(
    true,
    payment_checkout_url_is_valid('paymongo', 'https://checkout.paymongo.com/session'),
    'legacy PayMongo checkout URL'
);
assert_xendit_same(
    true,
    payment_checkout_url_is_valid('xendit', 'https://checkout.xendit.co/session'),
    'Xendit checkout URL'
);
assert_xendit_same(
    false,
    payment_checkout_url_is_valid('xendit', 'https://checkout.paymongo.com/session'),
    'checkout URL must match its recorded provider'
);
assert_xendit_same(
    'apply',
    paymongo_payment_transition_action('failed', 'paid', 'confirmed'),
    'legacy PayMongo payment before checkout expiry'
);
assert_xendit_same(
    'late_payment',
    paymongo_payment_transition_action('failed', 'paid', 'confirmed', true),
    'legacy PayMongo payment after checkout expiry'
);

assert_xendit_same(
    true,
    xendit_verify_callback_token('callback-token', 'callback-token'),
    'matching callback token'
);
assert_xendit_same(
    false,
    xendit_verify_callback_token('callback-token ', 'callback-token'),
    'callback token must match exactly'
);
assert_xendit_same(false, xendit_verify_callback_token(null, 'callback-token'), 'missing callback token');
assert_xendit_same(false, xendit_verify_callback_token('callback-token', ''), 'missing configured token');

$completed = xendit_webhook_event_values([
    'event' => 'payment_session.completed',
    'data' => [
        'payment_session_id' => 'session-1',
        'reference_id' => 'VS-0123456789',
        'payment_id' => 'payment-1',
        'status' => 'COMPLETED',
        'amount' => 123.45,
        'currency' => 'PHP',
    ],
]);
assert_xendit_same('xendit:payment-1', $completed['event_id'], 'completed event id');
assert_xendit_same('session-1', $completed['session_id'], 'completed session id');
assert_xendit_same('VS-0123456789', $completed['order_code'], 'completed order code');
assert_xendit_same(12345, $completed['amount_cents'], 'completed amount in cents');
assert_xendit_same('payment-1', $completed['payment_id'], 'completed payment id');

$expired = xendit_webhook_event_values([
    'event' => 'payment_session.expired',
    'data' => [
        'payment_session_id' => 'session-2',
        'reference_id' => 'VS-0123456789',
        'status' => 'EXPIRED',
        'currency' => 'PHP',
    ],
]);
assert_xendit_same('xendit:session:session-2:expired', $expired['event_id'], 'expired event id');
assert_xendit_same(null, $expired['amount_cents'], 'expired event can omit amount');

$refund = xendit_webhook_event_values([
    'event' => 'refund.succeeded',
    'data' => [
        'payment_id' => 'payment-1',
        'refund_id' => 'refund-1',
        'amount' => '123.45',
        'currency' => 'PHP',
    ],
]);
assert_xendit_same('xendit:refund.succeeded:refund-1', $refund['event_id'], 'refund event id');
assert_xendit_same('payment-1', $refund['session_id'], 'refund payment reference');
assert_xendit_same(12345, $refund['amount_cents'], 'refund amount in cents');
assert_xendit_same(null, xendit_amount_to_cents('100.001'), 'amount precision is limited to cents');
assert_xendit_same(null, xendit_amount_to_cents(INF), 'non-finite amount is rejected');

$actions = [
    ['completed payment', 'payment_session.completed', 'pending_payment', 'confirmed', true, 'paid'],
    ['expired payment', 'payment_session.expired', 'pending_payment', 'confirmed', true, 'failed'],
    ['completed payment after cancellation', 'payment_session.completed', 'failed', 'cancelled', true, 'manual_review'],
    ['completed payment with wrong amount', 'payment_session.completed', 'pending_payment', 'confirmed', false, 'manual_review'],
    ['full refund', 'refund.succeeded', 'paid', 'delivered', true, 'refunded'],
    ['partial refund', 'refund.succeeded', 'paid', 'delivered', false, 'manual_review'],
    ['refund for unpaid order', 'refund.succeeded', 'pending_payment', 'confirmed', true, 'manual_review'],
    ['failed refund', 'refund.failed', 'paid', 'delivered', true, 'record_only'],
];

foreach ($actions as [$name, $eventType, $paymentStatus, $orderStatus, $amountMatches, $expected]) {
    assert_xendit_same(
        $expected,
        xendit_payment_event_action($eventType, $paymentStatus, $orderStatus, $amountMatches),
        $name
    );
}
assert_xendit_same(
    'manual_review',
    xendit_payment_event_action('payment_session.completed', 'failed', 'confirmed', true, true),
    'completed payment after checkout expiry'
);

echo "Xendit payment tests passed.\n";