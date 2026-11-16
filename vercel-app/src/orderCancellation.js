const buyerCancellableStatuses = new Set([
    'pending',
    'confirmed',
    'preparing',
    'ready_for_delivery',
]);

export function canBuyerCancelOrder(order) {
    return Boolean(order)
        && buyerCancellableStatuses.has(order.order_status)
        && order.payment_status !== 'paid'
        && order.payment_status !== 'refunded'
        && (order.payment_method !== 'online' || Boolean(order.gateway_session_id));
}
