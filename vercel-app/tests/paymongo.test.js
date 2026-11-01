import test from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { getPayMongoEventValues, verifyPayMongoSignature } from '../lib/paymongo.js';

test('PayMongo webhook signatures cover the exact timestamp and raw body', () => {
    const body = '{"event":"paid"}';
    const timestamp = '1790000000';
    const secret = 'test-webhook-secret';
    const signature = createHmac('sha256', secret).update(`${timestamp}.${body}`).digest('hex');
    assert.equal(verifyPayMongoSignature(body, `t=${timestamp},te=${signature}`, secret, true), true);
    assert.equal(verifyPayMongoSignature(`${body} `, `t=${timestamp},te=${signature}`, secret, true), false);
    assert.equal(verifyPayMongoSignature(body, `t=${timestamp},li=${signature}`, secret, true), false);
});

test('paid checkout events expose their session, reference, and cents amount', () => {
    const values = getPayMongoEventValues({
        data: {
            id: 'evt-1',
            attributes: {
                type: 'checkout_session.payment.paid',
                data: {
                    id: 'cs-1',
                    attributes: {
                        reference_number: 'VS-ORDER',
                        amount: 16400,
                        payments: [{ attributes: { id: 'pay-1' } }],
                    },
                },
            },
        },
    });
    assert.equal(values.eventId, 'evt-1');
    assert.equal(values.sessionId, 'cs-1');
    assert.equal(values.orderCode, 'VS-ORDER');
    assert.equal(values.amountCents, 16400);
});

test('refund event can identify its checkout by order metadata', () => {
    const values = getPayMongoEventValues({
        data: {
            id: 'evt-refund',
            attributes: {
                type: 'payment.refunded',
                data: {
                    id: 'pay-1',
                    attributes: {
                        amount: 16400,
                        metadata: { order_code: 'VS-ORDER' },
                    },
                },
            },
        },
    });
    assert.equal(values.sessionId, undefined);
    assert.equal(values.orderCode, 'VS-ORDER');
    assert.equal(values.amountCents, 16400);
});
