import test from 'node:test';
import assert from 'node:assert/strict';
import { isOrderReturnWindowOpen, returnWindowMilliseconds } from '../src/orderReturns.js';

test('return window includes delivery and the exact 24-hour boundary', () => {
    const deliveredAt = Date.parse('2026-02-01T10:00:00Z');
    const order = { order_status: 'delivered', delivered_at: new Date(deliveredAt).toISOString() };

    assert.equal(isOrderReturnWindowOpen(order, deliveredAt), true);
    assert.equal(isOrderReturnWindowOpen(order, deliveredAt + returnWindowMilliseconds), true);
    assert.equal(isOrderReturnWindowOpen(order, deliveredAt + returnWindowMilliseconds + 1), false);
});

test('return window requires a delivered order with a valid past delivery time', () => {
    const now = Date.parse('2026-02-01T10:00:00Z');

    assert.equal(isOrderReturnWindowOpen({ order_status: 'preparing', delivered_at: new Date(now).toISOString() }, now), false);
    assert.equal(isOrderReturnWindowOpen({ order_status: 'delivered' }, now), false);
    assert.equal(isOrderReturnWindowOpen({ order_status: 'delivered', delivered_at: 'invalid' }, now), false);
    assert.equal(isOrderReturnWindowOpen({ order_status: 'delivered', delivered_at: new Date(now + 1).toISOString() }, now), false);
});
