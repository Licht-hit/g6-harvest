<?php
require_once __DIR__ . '/../includes/orders.php';

function expect_order_totals(string $name, array $items, array $expected): void
{
    $actual = calculate_order_totals($items);
    foreach ($expected as $key => $value) {
        if ($actual[$key] !== $value) {
            throw new RuntimeException(
                $name . ': expected ' . $key . ' to be ' . $value
                . ', got ' . $actual[$key]
            );
        }
    }
}

expect_order_totals(
    'Below minimum',
    [['quantity' => 19.99, 'price' => 50]],
    [
        'subtotal' => 999.5,
        'eligible_quantity' => 19.99,
        'discount_percentage' => 0.0,
        'discount_amount' => 0.0,
        'delivery_fee' => 20.0,
        'final_total' => 1019.5,
    ]
);

expect_order_totals(
    'At minimum',
    [['quantity' => 20, 'price' => 50]],
    [
        'subtotal' => 1000.0,
        'eligible_quantity' => 20.0,
        'discount_percentage' => 10.0,
        'discount_amount' => 100.0,
        'delivery_fee' => 20.0,
        'final_total' => 920.0,
    ]
);

expect_order_totals(
    'Combined quantities across units',
    [
        ['quantity' => 10, 'price' => 50],
        ['quantity' => 10, 'price' => 50],
    ],
    [
        'subtotal' => 1000.0,
        'eligible_quantity' => 20.0,
        'discount_percentage' => 10.0,
        'discount_amount' => 100.0,
        'delivery_fee' => 20.0,
        'final_total' => 920.0,
    ]
);

expect_order_totals(
    'Discount rounds to cents',
    [['quantity' => 20, 'price' => 0.99]],
    [
        'subtotal' => 19.8,
        'eligible_quantity' => 20.0,
        'discount_percentage' => 10.0,
        'discount_amount' => 1.98,
        'delivery_fee' => 20.0,
        'final_total' => 37.82,
    ]
);

expect_order_totals(
    'Subtotal matches rounded sale lines',
    [
        ['quantity' => 0.5, 'price' => 0.01],
        ['quantity' => 0.5, 'price' => 0.01],
    ],
    [
        'subtotal' => 0.02,
        'eligible_quantity' => 1.0,
        'discount_percentage' => 0.0,
        'discount_amount' => 0.0,
        'delivery_fee' => 20.0,
        'final_total' => 20.02,
    ]
);

$checkoutToken = str_repeat('a', 64);
if (checkout_token_hash($checkoutToken) !== hash('sha256', $checkoutToken)) {
    throw new RuntimeException('Checkout token hashing failed.');
}

$firstFingerprint = checkout_request_fingerprint(
    [2 => 1.5, 1 => 3],
    '123 Farm Road, Barangay Green, Sample City',
    'cod'
);
$sameRequestFingerprint = checkout_request_fingerprint(
    [1 => 3.0, 2 => 1.5],
    '123 Farm Road, Barangay Green, Sample City',
    'cod'
);
if ($firstFingerprint !== $sameRequestFingerprint) {
    throw new RuntimeException('Checkout fingerprints should ignore cart insertion order.');
}

$changedRequestFingerprint = checkout_request_fingerprint(
    [1 => 3.0, 2 => 1.5],
    '123 Farm Road, Barangay Green, Sample City',
    'online'
);
if ($firstFingerprint === $changedRequestFingerprint) {
    throw new RuntimeException('Checkout fingerprints should distinguish payment methods.');
}

$changedCartFingerprint = checkout_request_fingerprint(
    [1 => 3.0, 2 => 1.4],
    '123 Farm Road, Barangay Green, Sample City',
    'cod'
);
if ($firstFingerprint === $changedCartFingerprint) {
    throw new RuntimeException('Checkout fingerprints should distinguish cart quantities.');
}

$changedAddressFingerprint = checkout_request_fingerprint(
    [1 => 3.0, 2 => 1.5],
    '456 Farm Road, Barangay Green, Sample City',
    'cod'
);
if ($firstFingerprint === $changedAddressFingerprint) {
    throw new RuntimeException('Checkout fingerprints should distinguish delivery addresses.');
}

$invalidTokenRejected = false;
try {
    checkout_token_hash('not-a-checkout-token');
} catch (RuntimeException $exception) {
    $invalidTokenRejected = true;
}
if (!$invalidTokenRejected) {
    throw new RuntimeException('Invalid checkout tokens must be rejected.');
}

$stockIssues = checkout_stock_issues(
    [1 => 2.0, 2 => 4.0, 3 => 1.0, 4 => 3.0, 5 => 1.0],
    [
        ['id' => 1, 'name' => 'Tomato', 'unit' => 'kg', 'stock' => 5, 'active' => 1],
        ['id' => 2, 'name' => 'Carrot', 'unit' => 'kg', 'stock' => 2, 'active' => 1],
        ['id' => 3, 'name' => 'Lettuce', 'unit' => 'piece', 'stock' => 10, 'active' => 0],
        ['id' => 4, 'name' => 'Spinach', 'unit' => 'bunch', 'stock' => 0, 'active' => 1],
    ]
);
if (count($stockIssues) !== 4) {
    throw new RuntimeException('Checkout should identify every unavailable or over-stock cart item.');
}
if (
    $stockIssues[0]['name'] !== 'Carrot'
    || $stockIssues[0]['available'] !== 2.0
    || $stockIssues[0]['reason'] !== 'insufficient_stock'
) {
    throw new RuntimeException('Checkout should report current stock for over-stock cart items.');
}
if ($stockIssues[1]['reason'] !== 'unavailable' || $stockIssues[2]['available'] !== 0.0) {
    throw new RuntimeException('Checkout should identify inactive and out-of-stock items.');
}
if ($stockIssues[3]['name'] !== 'Unavailable produce' || $stockIssues[3]['reason'] !== 'unavailable') {
    throw new RuntimeException('Checkout should identify deleted products in the cart.');
}

echo "Checkout calculation tests passed.\n";
