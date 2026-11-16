import test from 'node:test';
import assert from 'node:assert/strict';
import { canBuyerCancelOrder } from '../src/orderCancellation.js';

const cancellableCodOrder = {
    order_status: 'pending',
    payment_status: 'pending_payment',
    payment_method: 'cod',
};

test('buyers can cancel unpaid orders before dispatch', () => {
    for (const order_status of ['pending', 'confirmed', 'preparing', 'ready_for_delivery']) {
        assert.equal(canBuyerCancelOrder({ ...cancellableCodOrder, order_status }), true);
    }
});

test('buyers cannot cancel paid, dispatched, or completed orders', () => {
    assert.equal(canBuyerCancelOrder({ ...cancellableCodOrder, payment_status: 'paid' }), false);
    assert.equal(canBuyerCancelOrder({ ...cancellableCodOrder, payment_status: 'refunded' }), false);
    assert.equal(canBuyerCancelOrder({ ...cancellableCodOrder, order_status: 'out_for_delivery' }), false);
    assert.equal(canBuyerCancelOrder({ ...cancellableCodOrder, order_status: 'delivered' }), false);
    assert.equal(canBuyerCancelOrder({ ...cancellableCodOrder, order_status: 'cancelled' }), false);
});

test('online orders need a checkout session before buyer cancellation', () => {
    assert.equal(canBuyerCancelOrder({
        ...cancellableCodOrder,
        payment_method: 'online',
    }), false);
    assert.equal(canBuyerCancelOrder({
        ...cancellableCodOrder,
        payment_method: 'online',
        gateway_session_id: 'session-123',
    }), true);
    assert.equal(canBuyerCancelOrder({
        ...cancellableCodOrder,
        payment_status: 'failed',
        payment_method: 'online',
        gateway_session_id: 'session-123',
    }), true);
    assert.equal(canBuyerCancelOrder(null), false);
});
