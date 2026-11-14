<?php

function order_tracking_steps(): array
{
    return [
        [
            'status' => 'confirmed',
            'label' => 'Order confirmed',
            'description' => 'The farm has accepted your order.',
        ],
        [
            'status' => 'preparing',
            'label' => 'Harvesting in progress',
            'description' => 'Your fresh produce is being harvested.',
        ],
        [
            'status' => 'ready_for_delivery',
            'label' => 'Packed & quality check',
            'description' => 'Your order is packed and being checked for quality.',
        ],
        [
            'status' => 'out_for_delivery',
            'label' => 'Out for delivery',
            'description' => 'Your order is on its way to your address.',
        ],
        [
            'status' => 'delivered',
            'label' => 'Delivered',
            'description' => 'Your order has arrived.',
        ],
    ];
}

function order_tracking_step_index(string $status): int
{
    foreach (order_tracking_steps() as $index => $step) {
        if ($status === $step['status']) {
            return $index;
        }
    }

    return -1;
}

function order_tracking_is_visible(string $status): bool
{
    return $status !== 'delivered';
}

function order_tracking_html(array $order): string
{
    $status = is_string($order['order_status'] ?? null) ? $order['order_status'] : '';
    if (!order_tracking_is_visible($status)) {
        return '';
    }
    $orderCode = h($order['order_code'] ?? '');
    $statusLabel = ucwords(str_replace('_', ' ', $status));

    if ($status === 'cancelled') {
        return '<section class="panel section app-order-tracker is-cancelled" data-order-tracker-for="'
            . $orderCode . '" data-order-tracker-status="cancelled">'
            . '<div class="app-order-tracker-heading"><div><h2>Order tracking</h2>'
            . '<p>Follow your order from the farm to your door.</p></div>'
            . '<span class="status-badge status-cancelled">Order cancelled</span></div>'
            . '<p class="app-order-tracker-cancelled" role="status">'
            . 'Contact the farm if you need help with this order.</p></section>';
    }

    $currentStep = order_tracking_step_index($status);
    $steps = '';
    foreach (order_tracking_steps() as $index => $step) {
        $class = $index < $currentStep ? 'is-complete' : ($index === $currentStep ? 'is-current' : 'is-upcoming');
        $current = $index === $currentStep ? ' aria-current="step"' : '';
        $steps .= '<li class="app-order-tracker-step ' . $class . '"' . $current . '>'
            . '<span class="app-order-tracker-marker" aria-hidden="true">'
            . ($index < $currentStep ? '&#10003;' : (string) ($index + 1))
            . '</span><div><h3>' . h($step['label']) . '</h3><p>' . h($step['description']) . '</p></div></li>';
    }
    $statusLabels = [
        'pending' => 'Waiting for farm confirmation',
        'confirmed' => 'Order confirmed',
        'preparing' => 'Harvesting in progress',
        'ready_for_delivery' => 'Packed & quality check',
        'out_for_delivery' => 'Out for delivery',
    ];
    $statusCopy = $statusLabels[$status] ?? $statusLabel;

    return '<section class="panel section app-order-tracker" data-order-tracker-for="' . $orderCode
        . '" data-order-tracker-status="' . h($status) . '">'
        . '<div class="app-order-tracker-heading"><div><h2>Order tracking</h2>'
        . '<p>Follow your order from the farm to your door.</p></div>'
        . '<span class="status-badge status-' . h($status) . '">' . h($statusCopy) . '</span></div>'
        . ($status === 'pending'
            ? '<p class="app-order-tracker-pending" role="status">The farm will confirm your order before harvesting begins.</p>'
            : '')
        . '<ol class="app-order-tracker-list">' . $steps . '</ol>'
        . '<p class="app-order-tracker-live">Progress updates automatically when the farm updates your order.</p></section>';
}