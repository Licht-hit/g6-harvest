<?php
require_once __DIR__ . '/../includes/payments.php';

$cases = [
    ['new payment', 'pending_payment', 'paid', 'confirmed', 'apply'],
    ['failed payment', 'pending_payment', 'failed', 'confirmed', 'apply'],
    ['late success', 'failed', 'paid', 'confirmed', 'apply'],
    ['payment after cancellation', 'failed', 'paid', 'cancelled', 'late_payment'],
    ['failure after cancellation', 'failed', 'failed', 'cancelled', 'ignore'],
    ['duplicate paid event', 'paid', 'paid', 'confirmed', 'ignore'],
    ['late failure after payment', 'paid', 'failed', 'confirmed', 'ignore'],
    ['payment after refund', 'refunded', 'paid', 'cancelled', 'ignore'],
];

foreach ($cases as [$name, $current, $incoming, $orderStatus, $expected]) {
    $actual = paymongo_payment_transition_action($current, $incoming, $orderStatus);
    if ($actual !== $expected) {
        throw new RuntimeException(
            $name . ': expected ' . $expected . ', got ' . $actual
        );
    }
}

echo "Payment flow transition tests passed.\n";
