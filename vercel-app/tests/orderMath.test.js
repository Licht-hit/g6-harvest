import test from 'node:test';
import assert from 'node:assert/strict';
import { calculateOrderTotals } from '../src/orderMath.js';

test('bulk discount applies at 20 combined units across different products', () => {
    const totals = calculateOrderTotals([
        { quantity: 12, price: 10 },
        { quantity: 8, price: 5 },
    ]);
    assert.deepEqual(totals, {
        subtotal: 160,
        eligibleQuantity: 20,
        discountPercentage: 10,
        discountAmount: 16,
        deliveryFee: 20,
        finalTotal: 164,
    });
});

test('line totals are rounded before the subtotal is summed', () => {
    const totals = calculateOrderTotals([
        { quantity: 1, price: 1.005 },
        { quantity: 1, price: 2.005 },
    ]);
    assert.equal(totals.subtotal, 3.02);
});

test('invalid quantities and prices are rejected', () => {
    assert.throws(() => calculateOrderTotals([{ quantity: 0, price: 5 }]));
    assert.throws(() => calculateOrderTotals([{ quantity: 1, price: Number.NaN }]));
});
