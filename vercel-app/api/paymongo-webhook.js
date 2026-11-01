import { serviceClient, sendJson } from '../lib/supabaseServer.js';
import { getPayMongoEventValues, verifyPayMongoSignature } from '../lib/paymongo.js';

export const config = { api: { bodyParser: false } };

export default async function handler(request, response) {
    if (request.method !== 'POST') {
        response.setHeader('Allow', 'POST');
        return sendJson(response, 405, { error: 'Method not allowed.' });
    }
    if (!String(request.headers['content-type'] || '').toLowerCase().includes('application/json')) {
        return sendJson(response, 415, { error: 'Expected a JSON webhook payload.' });
    }
    const secret = process.env.PAYMONGO_WEBHOOK_SECRET;
    const key = process.env.PAYMONGO_SECRET_KEY || '';
    if (!secret || !key) {
        console.error('PayMongo webhook configuration is missing.');
        return sendJson(response, 503, { error: 'Payment webhook is not configured.' });
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
        const rawBody = Buffer.concat(rawChunks).toString('utf8');
        const signature = request.headers['paymongo-signature'] || '';
        const testMode = key.startsWith('sk_test_');
        if (!verifyPayMongoSignature(rawBody, signature, secret, testMode)) {
            return sendJson(response, 400, { error: 'Invalid webhook signature.' });
        }

        const event = JSON.parse(rawBody);
        const values = getPayMongoEventValues(event);
        const supported = [
            'checkout_session.payment.paid',
            'checkout_session.payment.failed',
            'payment.refunded',
        ];
        if (!supported.includes(values.eventType)) {
            return sendJson(response, 200, { received: true });
        }
        const requiresAmount = values.eventType !== 'checkout_session.payment.failed';
        if (!values.eventId || (requiresAmount && values.amountCents === null)) {
            return sendJson(response, 400, { error: 'Payment event is missing required details.' });
        }

        let sessionId = values.sessionId;
        if (!sessionId) {
            let orderQuery = serviceClient.from('orders').select('gateway_session_id');
            if (values.orderCode) {
                orderQuery = orderQuery.eq('order_code', values.orderCode);
            } else if (values.reference) {
                orderQuery = orderQuery.eq('payment_reference', values.reference);
            } else {
                return sendJson(response, 400, { error: 'Payment event does not match an order.' });
            }
            const { data: order, error } = await orderQuery.maybeSingle();
            if (error) {
                throw error;
            }
            sessionId = order?.gateway_session_id;
        }
        if (!sessionId) {
            return sendJson(response, 400, { error: 'Payment event does not match an order.' });
        }

        const { error } = await serviceClient.rpc('apply_paymongo_event', {
            p_event_id: values.eventId,
            p_event_type: values.eventType,
            p_session_id: sessionId,
            p_amount_cents: values.amountCents,
            p_reference: values.reference,
        });
        if (error) {
            console.error('PayMongo event processing failed:', error.message);
            return sendJson(response, 400, { error: 'Payment event could not be applied.' });
        }
        return sendJson(response, 200, { received: true });
    } catch (error) {
        if (error instanceof RangeError || error instanceof SyntaxError) {
            return sendJson(response, 400, { error: error.message || 'Invalid webhook payload.' });
        }
        console.error('PayMongo webhook error:', error.message);
        return sendJson(response, 500, { error: 'Webhook processing failed.' });
    }
}
