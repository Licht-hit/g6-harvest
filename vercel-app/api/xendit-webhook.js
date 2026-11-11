import { serviceClient, sendJson } from '../lib/supabaseServer.js';
import { getXenditEventValues, verifyXenditCallbackToken } from '../lib/xendit.js';

export const config = { api: { bodyParser: false } };

export default async function handler(request, response) {
    if (request.method !== 'POST') {
        response.setHeader('Allow', 'POST');
        return sendJson(response, 405, { error: 'Method not allowed.' });
    }
    if (!String(request.headers['content-type'] || '').toLowerCase().includes('application/json')) {
        return sendJson(response, 415, { error: 'Expected a JSON webhook payload.' });
    }

    const callbackToken = process.env.XENDIT_WEBHOOK_TOKEN;
    if (!callbackToken) {
        console.error('Xendit webhook configuration is missing.');
        return sendJson(response, 503, { error: 'Payment webhook is not configured.' });
    }
    if (!verifyXenditCallbackToken(request.headers['x-callback-token'], callbackToken)) {
        return sendJson(response, 401, { error: 'Invalid webhook token.' });
    }

    try {
        const rawChunks = [];
        let bodySize = 0;
        for await (const chunk of request) {
            bodySize += chunk.length;
            if (bodySize > 1024 * 1024) {
                return sendJson(response, 413, { error: 'Webhook payload is too large.' });
            }
            rawChunks.push(chunk);
        }

        const event = JSON.parse(Buffer.concat(rawChunks).toString('utf8'));
        const values = getXenditEventValues(event);
        const supported = [
            'payment_session.completed',
            'payment_session.expired',
            'refund.succeeded',
            'refund.failed',
        ];
        if (!supported.includes(values.eventType)) {
            return sendJson(response, 200, { received: true });
        }
        if (!values.eventId || !values.sessionId) {
            return sendJson(response, 400, { error: 'Payment event is missing its session or event identifier.' });
        }
        if (values.eventType === 'payment_session.completed'
            && (values.status !== 'COMPLETED' || values.currency !== 'PHP'
                || values.amountCents === null || !values.paymentId)) {
            return sendJson(response, 400, { error: 'Completed payment event is missing valid payment details.' });
        }
        if (values.eventType === 'payment_session.expired'
            && (values.status !== 'EXPIRED' || values.currency !== 'PHP')) {
            return sendJson(response, 400, { error: 'Expired payment event is missing valid session details.' });
        }
        if (values.eventType.startsWith('refund.')
            && (values.currency !== 'PHP' || values.amountCents === null)) {
            return sendJson(response, 400, { error: 'Refund event is missing valid payment details.' });
        }

        if (values.orderCode) {
            const { data: order, error } = await serviceClient.from('orders')
                .select('order_code, gateway_provider')
                .eq('order_code', values.orderCode)
                .maybeSingle();
            if (error) throw error;
            if (!order || order.gateway_provider !== 'xendit') {
                return sendJson(response, 400, { error: 'Payment event does not match an Xendit order.' });
            }
        }

        const { error } = await serviceClient.rpc('apply_xendit_event', {
            p_event_id: values.eventId,
            p_event_type: values.eventType,
            p_session_id: values.sessionId,
            p_amount_cents: values.amountCents,
            p_reference: values.reference,
        });
        if (error) {
            console.error('Xendit event processing failed:', error.message);
            return sendJson(response, 500, { error: 'Payment event could not be applied.' });
        }
        return sendJson(response, 200, { received: true });
    } catch (error) {
        if (error instanceof RangeError || error instanceof SyntaxError) {
            return sendJson(response, 400, { error: error.message || 'Invalid webhook payload.' });
        }
        console.error('Xendit webhook error:', error.message);
        return sendJson(response, 500, { error: 'Webhook processing failed.' });
    }
}
