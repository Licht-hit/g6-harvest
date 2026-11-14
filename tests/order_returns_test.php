<?php
require_once __DIR__ . '/../includes/order_returns.php';

$deliveredAt = date('Y-m-d H:i:s', 1700000000);
if (!order_return_window_is_open([
    'order_status' => 'delivered',
    'delivered_at' => $deliveredAt,
], 1700000000 + ORDER_RETURN_WINDOW_SECONDS)) {
    throw new RuntimeException('The return window should include its 24-hour boundary.');
}
if (order_return_window_is_open([
    'order_status' => 'delivered',
    'delivered_at' => $deliveredAt,
], 1700000001 + ORDER_RETURN_WINDOW_SECONDS)) {
    throw new RuntimeException('The return window should close after 24 hours.');
}
if (order_return_window_is_open([
    'order_status' => 'out_for_delivery',
    'delivered_at' => $deliveredAt,
], 1700000000)) {
    throw new RuntimeException('An undelivered order should not be returnable.');
}
if (order_returnable_quantity(5, 2.25) !== 2.75
    || order_returnable_quantity(2, 3) !== 0.0
    || order_returnable_quantity(INF, 1) !== 0.0) {
    throw new RuntimeException('Returnable quantity calculation is incorrect.');
}