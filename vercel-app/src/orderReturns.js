export const returnWindowMilliseconds = 24 * 60 * 60 * 1000;

export function isOrderReturnWindowOpen(order, now = Date.now()) {
    const deliveredAt = Date.parse(order?.delivered_at || '');
    return order?.order_status === 'delivered'
        && Number.isFinite(deliveredAt)
        && deliveredAt <= now
        && now - deliveredAt <= returnWindowMilliseconds;
}
