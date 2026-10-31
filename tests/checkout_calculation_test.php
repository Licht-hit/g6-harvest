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

echo "Checkout calculation tests passed.\n";
