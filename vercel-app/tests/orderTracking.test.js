import test from 'node:test';
import assert from 'node:assert/strict';
import { getOrderTrackingState, shouldShowOrderTracking } from '../src/orderTracking.js';

test('tracking is hidden only after an order is delivered', () => {
    assert.equal(shouldShowOrderTracking('delivered'), false);
    assert.equal(shouldShowOrderTracking('preparing'), true);
    assert.equal(shouldShowOrderTracking('cancelled'), true);
});

test('a pending order waits for confirmation before starting the tracker', () => {
    const tracking = getOrderTrackingState('pending');
    assert.equal(tracking.cancelled, false);
    assert.deepEqual(tracking.steps.map((step) => step.state), [
        'upcoming',
        'upcoming',
        'upcoming',
        'upcoming',
        'upcoming',
    ]);
});

test('the current status completes prior steps and advances the tracker', () => {
    const tracking = getOrderTrackingState('ready_for_delivery');
    assert.deepEqual(tracking.steps.map((step) => step.state), [
        'complete',
        'complete',
        'current',
        'upcoming',
        'upcoming',
    ]);
});

test('delivered orders reach the final step', () => {
    const tracking = getOrderTrackingState('delivered');
    assert.equal(tracking.steps.at(-1).state, 'current');
    assert.ok(tracking.steps.slice(0, -1).every((step) => step.state === 'complete'));
});

test('cancelled orders do not show delivery progress', () => {
    assert.deepEqual(getOrderTrackingState('cancelled'), { cancelled: true, steps: [] });
});

test('unsupported order statuses are rejected', () => {
    assert.throws(() => getOrderTrackingState('unknown'), RangeError);
});
