import { createHmac, timingSafeEqual } from 'node:crypto';

export function verifyPayMongoSignature(rawBody, signatureHeader, secret, testMode) {
    const parts = Object.fromEntries(
        String(signatureHeader || '')
            .split(',')
            .map((part) => part.trim().split('=', 2))
            .filter((part) => part.length === 2)
    );
    if (!/^\d+$/.test(parts.t || '')) return false;

    const received = parts[testMode ? 'te' : 'li'] || '';
    if (!/^[a-f0-9]{64}$/i.test(received)) return false;
    const expected = createHmac('sha256', secret).update(`${parts.t}.${rawBody}`).digest();
    const supplied = Buffer.from(received, 'hex');
    return supplied.length === expected.length && timingSafeEqual(supplied, expected);
}

export function getPayMongoEventValues(event) {
    const attributes = event?.data?.attributes;
    const resource = attributes?.data;
    const resourceAttributes = resource?.attributes || {};
    const paymentAttributes = resourceAttributes.payments?.[0]?.attributes
        || resourceAttributes.payment?.attributes
        || {};
    const eventType = attributes?.type;
    const orderCode = resourceAttributes.reference_number
        || resourceAttributes.metadata?.order_code
        || attributes?.metadata?.order_code;
    const sessionId = eventType?.startsWith('checkout_session.')
        ? resource?.id
        : resourceAttributes.checkout_session_id
            || paymentAttributes.checkout_session_id
            || resourceAttributes.metadata?.checkout_session_id;
    const amount = resourceAttributes.amount
        ?? paymentAttributes.amount
        ?? resourceAttributes.amount_paid;
    const amountCents = amount !== null && amount !== undefined && Number.isSafeInteger(Number(amount))
        ? Number(amount)
        : null;

    return {
        eventId: event?.data?.id,
        eventType,
        orderCode,
        sessionId,
        amountCents,
        reference: paymentAttributes.id || resource?.id || null,
    };
}
