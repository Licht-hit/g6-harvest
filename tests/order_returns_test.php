<?php
require_once __DIR__ . '/../includes/order_returns.php';

if (!order_return_is_available([
    'order_status' => 'delivered',
])) {
    throw new RuntimeException('A delivered order should be eligible without a delivery timestamp.');
}
if (order_return_is_available([
    'order_status' => 'out_for_delivery',
])) {
    throw new RuntimeException('An undelivered order should not be returnable.');
}
if (order_return_is_available([
    'order_status' => 'cancelled',
])) {
    throw new RuntimeException('A cancelled order should not be returnable.');
}
if (order_returnable_quantity(5, 2.25) !== 2.75
    || order_returnable_quantity(2, 3) !== 0.0
    || order_returnable_quantity(INF, 1) !== 0.0) {
    throw new RuntimeException('Returnable quantity calculation is incorrect.');
}