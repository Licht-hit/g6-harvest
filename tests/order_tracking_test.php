<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/order_tracking.php';

if (order_tracking_step_index('confirmed') !== 0
    || order_tracking_step_index('preparing') !== 1
    || order_tracking_step_index('ready_for_delivery') !== 2
    || order_tracking_step_index('out_for_delivery') !== 3
    || order_tracking_step_index('delivered') !== 4
    || count(order_tracking_steps()) !== 5) {
    throw new RuntimeException('Order tracking status mapping is incorrect.');
}
if (order_tracking_is_visible('delivered') || !order_tracking_is_visible('out_for_delivery')) {
    throw new RuntimeException('Order tracking visibility does not stop after delivery.');
}

$tracker = order_tracking_html(['order_code' => 'VS-0123456789', 'order_status' => 'preparing']);
if (!str_contains($tracker, 'Harvesting in progress') || !str_contains($tracker, 'is-current')) {
    throw new RuntimeException('The tracker did not render the current order milestone.');
}
if (order_tracking_html(['order_code' => 'VS-0123456789', 'order_status' => 'delivered']) !== '') {
    throw new RuntimeException('A delivered order should not display tracking.');
}