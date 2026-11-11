import { timingSafeEqual } from 'node:crypto';

export function verifyXenditCallbackToken(received, expected) {
    if (typeof received !== 'string' || typeof expected !== 'string' || !expected) return false;
    const supplied = Buffer.from(received);
    const configured = Buffer.from(expected);
    return supplied.length === configured.length && timingSafeEqual(supplied, configured);
}

function amountToCents(value) {
    const amount = Number(value);
    if (!Number.isFinite(amount) || amount < 0 || amount > 99999999.99
        || !Number.isSafeInteger(Math.round(amount * 100))) return null;
    return Math.round(amount * 100);
}

export function getXenditEventValues(event) {
    const data = event?.data || {};
    const eventType = event?.event;
    const isSessionEvent = eventType === 'payment_session.completed'
        || eventType === 'payment_session.expired';
    const isRefundEvent = eventType === 'refund.succeeded'
        || eventType === 'refund.failed';
    const sessionId = isSessionEvent
        ? data.payment_session_id
        : isRefundEvent
            ? data.payment_id
            : null;
    const paymentId = data.payment_id || null;
    const eventId = eventType === 'payment_session.completed'
        ? `xendit:${paymentId || `session:${data.payment_session_id}:completed`}`
        : eventType === 'payment_session.expired'
            ? `xendit:session:${data.payment_session_id}:expired`
            : isRefundEvent
                ? `xendit:${eventType}:${data.refund_id || ''}`
                : null;

    return {
        eventId: eventId && !eventId.endsWith(':') ? eventId : null,
        eventType,
        sessionId,
        orderCode: isSessionEvent ? data.reference_id : null,
        amountCents: amountToCents(data.amount),
        currency: data.currency,
        paymentId,
        reference: paymentId,
        status: data.status,
    };
}
