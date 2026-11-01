export const BULK_DISCOUNT_MINIMUM_QUANTITY = 20;
export const BULK_DISCOUNT_PERCENTAGE = 10;
export const ORDER_DELIVERY_FEE = 20;

export function calculateOrderTotals(items) {
    let subtotal = 0;
    let eligibleQuantity = 0;

    for (const item of items) {
        const quantity = Number(item.quantity);
        const price = Number(item.price);
        if (!Number.isFinite(quantity) || !Number.isFinite(price) || quantity <= 0 || price < 0) {
            throw new Error('One or more order quantities or prices are invalid.');
        }
        eligibleQuantity += quantity;
        subtotal += Math.round((price * quantity + Number.EPSILON) * 100) / 100;
    }

    subtotal = Math.round((subtotal + Number.EPSILON) * 100) / 100;
    const discountPercentage = eligibleQuantity >= BULK_DISCOUNT_MINIMUM_QUANTITY
        ? BULK_DISCOUNT_PERCENTAGE
        : 0;
    const discountAmount = Math.round((subtotal * discountPercentage / 100 + Number.EPSILON) * 100) / 100;

    return {
        subtotal,
        eligibleQuantity: Math.round((eligibleQuantity + Number.EPSILON) * 100) / 100,
        discountPercentage,
        discountAmount,
        deliveryFee: ORDER_DELIVERY_FEE,
        finalTotal: Math.round((subtotal - discountAmount + ORDER_DELIVERY_FEE + Number.EPSILON) * 100) / 100,
    };
}
