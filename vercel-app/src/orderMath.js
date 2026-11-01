export const BULK_DISCOUNT_MINIMUM_QUANTITY = 20;
export const BULK_DISCOUNT_PERCENTAGE = 10;
export const ORDER_DELIVERY_FEE = 20;

function roundToCents(amount) {
    // EPSILON avoids common floating-point errors, such as 1.005 rounding down.
    return Math.round((amount + Number.EPSILON) * 100) / 100;
}

export function calculateOrderTotals(items) {
    let subtotal = 0;
    let eligibleQuantity = 0;

    // Validate each cart item and round its line total before adding it.
    for (const item of items) {
        const quantity = Number(item.quantity);
        const price = Number(item.price);
        if (!Number.isFinite(quantity) || !Number.isFinite(price) || quantity <= 0 || price < 0) {
            throw new Error('One or more order quantities or prices are invalid.');
        }

        eligibleQuantity += quantity;
        const lineTotal = roundToCents(price * quantity);
        subtotal += lineTotal;
    }

    subtotal = roundToCents(subtotal);

    // The discount applies to the combined quantity of every cart item.
    const qualifiesForDiscount = eligibleQuantity >= BULK_DISCOUNT_MINIMUM_QUANTITY;
    eligibleQuantity = roundToCents(eligibleQuantity);
    const discountPercentage = qualifiesForDiscount
        ? BULK_DISCOUNT_PERCENTAGE
        : 0;
    const discountAmount = roundToCents(subtotal * discountPercentage / 100);
    const finalTotal = roundToCents(
        subtotal - discountAmount + ORDER_DELIVERY_FEE
    );

    return {
        subtotal,
        eligibleQuantity,
        discountPercentage,
        discountAmount,
        deliveryFee: ORDER_DELIVERY_FEE,
        finalTotal,
    };
}
