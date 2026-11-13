const ORDER_TRACKING_STEPS = Object.freeze([
    'confirmed',
    'preparing',
    'ready_for_delivery',
    'out_for_delivery',
    'delivered',
]);

export function getOrderTrackingState(orderStatus) {
    if (orderStatus === 'cancelled') {
        return { cancelled: true, steps: [] };
    }

    const currentIndex = orderStatus === 'pending'
        ? -1
        : ORDER_TRACKING_STEPS.indexOf(orderStatus);
    if (orderStatus !== 'pending' && currentIndex === -1) {
        throw new RangeError(`Unsupported order status: ${orderStatus}`);
    }

    return {
        cancelled: false,
        steps: ORDER_TRACKING_STEPS.map((status, index) => ({
            status,
            state: index < currentIndex ? 'complete' : index === currentIndex ? 'current' : 'upcoming',
        })),
    };
}
