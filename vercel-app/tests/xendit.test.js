import test from 'node:test';
import assert from 'node:assert/strict';
import {
    getXenditEventValues,
    isValidXenditCheckoutHostname,
    verifyXenditCallbackToken,
} from '../lib/xendit.js';

test('Xendit checkout URLs allow the documented test and production hosts only', () => {
    assert.equal(isValidXenditCheckoutHostname('dev.xen.to'), true);
    assert.equal(isValidXenditCheckoutHostname('xen.to'), true);
    assert.equal(isValidXenditCheckoutHostname('checkout.xendit.co'), true);
    assert.equal(isValidXenditCheckoutHostname('dev.xen.to.attacker.example'), false);
    assert.equal(isValidXenditCheckoutHostname('xendit.co.attacker.example'), false);
    assert.equal(isValidXenditCheckoutHostname(undefined), false);
});

test('Xendit callback token comparison requires an exact match', () => {
    assert.equal(verifyXenditCallbackToken('callback-secret', 'callback-secret'), true);
    assert.equal(verifyXenditCallbackToken('callback-secret ', 'callback-secret'), false);
    assert.equal(verifyXenditCallbackToken('wrong', 'callback-secret'), false);
    assert.equal(verifyXenditCallbackToken(undefined, 'callback-secret'), false);
});

test('completed Xendit session events expose order, payment, and PHP cents', () => {
    const values = getXenditEventValues({
        event: 'payment_session.completed',
        data: {
            payment_session_id: 'session-1',
            payment_id: 'payment-1',
            reference_id: 'VS-ORDER',
            amount: 164.5,
            currency: 'PHP',
            status: 'COMPLETED',
        },
    });
    assert.equal(values.eventId, 'xendit:payment-1');
    assert.equal(values.sessionId, 'session-1');
    assert.equal(values.orderCode, 'VS-ORDER');
    assert.equal(values.amountCents, 16450);
    assert.equal(values.reference, 'payment-1');
});

test('refund events identify their original payment and refund event', () => {
    const values = getXenditEventValues({
        event: 'refund.succeeded',
        data: {
            refund_id: 'refund-1',
            payment_id: 'payment-1',
            amount: 164.5,
            currency: 'PHP',
        },
    });
    assert.equal(values.eventId, 'xendit:refund.succeeded:refund-1');
    assert.equal(values.sessionId, 'payment-1');
    assert.equal(values.amountCents, 16450);
});

test('invalid Xendit amounts are rejected', () => {
    assert.equal(getXenditEventValues({
        event: 'payment_session.completed',
        data: { amount: 100000000 },
    }).amountCents, null);
});
