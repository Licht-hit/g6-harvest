import { serviceClient, getRequestUser, readJsonBody, sendJson } from '../lib/supabaseServer.js';
import { isValidXenditCheckoutHostname } from '../lib/xendit.js';
import { isFarmStaffRole } from '../src/roles.js';

function requiredEnvironmentValue(name) {
    const value = process.env[name];
    if (!value) {
        throw new Error(`${name} is not configured.`);
    }
    return value;
}

async function createPayMongoCheckout(order) {
    const secretKey = requiredEnvironmentValue('PAYMONGO_SECRET_KEY');
    let appUrl;
    try {
        appUrl = new URL(requiredEnvironmentValue('APP_URL'));
    } catch {
        throw new Error('APP_URL must be the public HTTPS address of this Vercel app.');
    }
    if (appUrl.protocol !== 'https:' || !appUrl.hostname || appUrl.username || appUrl.password
        || appUrl.search || appUrl.hash) {
        throw new Error('APP_URL must be a public HTTPS address without credentials or query parameters.');
    }
    const baseUrl = appUrl.toString().replace(/\/+$/, '');
    const response = await fetch('https://api.paymongo.com/v2/checkout_sessions', {
        method: 'POST',
        headers: {
            Authorization: `Basic ${Buffer.from(`${secretKey}:`).toString('base64')}`,
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        body: JSON.stringify({
            data: {
                attributes: {
                    line_items: [{
                        name: `Harvest order ${order.order_code} (discount and delivery included)`,
                        amount: Math.round(Number(order.final_total) * 100),
                        currency: 'PHP',
                        quantity: 1,
                    }],
                    payment_method_types: ['gcash', 'card'],
                    success_url: `${baseUrl}/?page=orders&payment=success&order=${encodeURIComponent(order.order_code)}`,
                    cancel_url: `${baseUrl}/?page=orders&payment=cancel&order=${encodeURIComponent(order.order_code)}`,
                    reference_number: order.order_code,
                    metadata: { order_code: order.order_code },
                },
            },
        }),
    });
    const result = await response.json().catch(() => null);
    if (!response.ok) {
        console.error('PayMongo checkout creation failed with HTTP status', response.status);
        throw new Error('PayMongo could not start checkout. Check payment methods and try again.');
    }

    const session = result?.data;
    const checkoutUrl = session?.attributes?.checkout_url;
    let parsedUrl;
    try {
        parsedUrl = new URL(checkoutUrl);
    } catch {
        throw new Error('PayMongo returned an invalid checkout URL.');
    }
    if (!session?.id || parsedUrl.protocol !== 'https:' || parsedUrl.hostname !== 'checkout.paymongo.com') {
        throw new Error('PayMongo returned an invalid checkout session.');
    }
    return { sessionId: session.id, checkoutUrl };
}

async function createXenditCheckout(order) {
    const secretKey = requiredEnvironmentValue('XENDIT_SECRET_KEY');
    let appUrl;
    try {
        appUrl = new URL(requiredEnvironmentValue('APP_URL'));
    } catch {
        throw new Error('APP_URL must be the public HTTPS address of this Vercel app.');
    }
    if (appUrl.protocol !== 'https:' || !appUrl.hostname || appUrl.username || appUrl.password
        || appUrl.search || appUrl.hash) {
        throw new Error('APP_URL must be a public HTTPS address without credentials or query parameters.');
    }
    const baseUrl = appUrl.toString().replace(/\/+$/, '');
    const response = await fetch('https://api.xendit.co/sessions', {
        method: 'POST',
        headers: {
            Authorization: `Basic ${Buffer.from(`${secretKey}:`).toString('base64')}`,
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        body: JSON.stringify({
            reference_id: order.order_code,
            session_type: 'PAY',
            mode: 'PAYMENT_LINK',
            amount: Number(order.final_total),
            currency: 'PHP',
            country: 'PH',
            description: `Harvest order ${order.order_code}`,
            success_return_url: `${baseUrl}/?page=orders&payment=success&order=${encodeURIComponent(order.order_code)}`,
            cancel_return_url: `${baseUrl}/?page=orders&payment=cancel&order=${encodeURIComponent(order.order_code)}`,
        }),
    });
    const result = await response.json().catch(() => null);
    if (!response.ok) {
        console.error('Xendit checkout creation failed with HTTP status', response.status);
        throw new Error('Xendit could not start checkout. Check payment methods and try again.');
    }

    const sessionId = result?.payment_session_id;
    const checkoutUrl = result?.payment_link_url;
    let parsedUrl;
    try {
        parsedUrl = new URL(checkoutUrl);
    } catch {
        throw new Error('Xendit returned an invalid checkout URL.');
    }
    const validHost = isValidXenditCheckoutHostname(parsedUrl.hostname);
    if (!sessionId || parsedUrl.protocol !== 'https:' || !validHost
        || parsedUrl.username || parsedUrl.password) {
        throw new Error('Xendit returned an invalid checkout session.');
    }
    return { sessionId, checkoutUrl };
}

async function expirePayMongoCheckout(sessionId) {
    const secretKey = requiredEnvironmentValue('PAYMONGO_SECRET_KEY');
    const response = await fetch(
        `https://api.paymongo.com/v1/checkout_sessions/${encodeURIComponent(sessionId)}/expire`,
        {
            method: 'POST',
            headers: {
                Authorization: `Basic ${Buffer.from(`${secretKey}:`).toString('base64')}`,
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
        }
    );
    if (!response.ok) {
        console.error('PayMongo could not expire checkout; HTTP status', response.status);
        throw new Error('PayMongo could not confirm that checkout is safe to cancel. Stock was not released.');
    }
}

async function cancelXenditCheckout(sessionId) {
    const secretKey = requiredEnvironmentValue('XENDIT_SECRET_KEY');
    const response = await fetch(
        `https://api.xendit.co/sessions/${encodeURIComponent(sessionId)}/cancel`,
        {
            method: 'POST',
            headers: {
                Authorization: `Basic ${Buffer.from(`${secretKey}:`).toString('base64')}`,
                Accept: 'application/json',
            },
        }
    );
    if (!response.ok) {
        console.error('Xendit could not cancel checkout; HTTP status', response.status);
        throw new Error('Xendit could not confirm that checkout is safe to cancel. Stock was not released.');
    }
}

async function getSavedCheckout(orderCode) {
    const { data, error } = await serviceClient
        .from('orders')
        .select('gateway_session_id, gateway_checkout_url, gateway_provider, payment_status, order_status')
        .eq('order_code', orderCode)
        .maybeSingle();
    if (error) throw error;
    return data;
}

async function releaseUnstartedCheckout(orderCode) {
    const { error } = await serviceClient.rpc('rollback_online_checkout', {
        p_order_code: orderCode,
    });
    if (error) {
        console.error('Online checkout rollback failed for order', orderCode, error.message);
        throw new Error('Checkout could not start and stock could not be released automatically. Contact farm staff with your order number.');
    }
}

export default async function handler(request, response) {
    if (request.method !== 'POST') {
        response.setHeader('Allow', 'POST');
        return sendJson(response, 405, { error: 'Method not allowed.' });
    }

    try {
        const requestUser = await getRequestUser(request);
        if (requestUser.error) {
            return sendJson(response, requestUser.status, { error: requestUser.error });
        }
        const payload = request.body && typeof request.body === 'object'
            ? request.body
            : await readJsonBody(request);
        const action = payload.action;

        if (action === 'checkout') {
            const { data: order, error: orderError } = await requestUser.client.rpc('create_order', {
                p_items: payload.items,
                p_delivery_address: payload.deliveryAddress,
                p_payment_method: payload.paymentMethod,
                p_checkout_token: payload.checkoutToken,
            });
            if (orderError) {
                return sendJson(response, 400, { error: orderError.message });
            }
            if (payload.paymentMethod === 'cod') {
                return sendJson(response, 200, { order });
            }
            if (order.payment_method !== 'online') {
                return sendJson(response, 400, { error: 'Payment method does not match the saved order.' });
            }
            if (order.gateway_session_id && order.gateway_checkout_url
                && order.payment_status === 'pending_payment') {
                return sendJson(response, 200, { order, checkoutUrl: order.gateway_checkout_url });
            }
            if (order.payment_status !== 'pending_payment' || order.order_status === 'cancelled') {
                return sendJson(response, 409, { error: 'This order is no longer available for payment.' });
            }

            let checkout;
            const createCheckout = order.gateway_provider === 'paymongo'
                ? createPayMongoCheckout
                : createXenditCheckout;
            const cancelCheckout = order.gateway_provider === 'paymongo'
                ? expirePayMongoCheckout
                : cancelXenditCheckout;
            try {
                checkout = await createCheckout(order);
            } catch (error) {
                const savedCheckout = await getSavedCheckout(order.order_code);
                if (savedCheckout?.gateway_session_id && savedCheckout.gateway_checkout_url
                    && savedCheckout.payment_status === 'pending_payment') {
                    return sendJson(response, 200, {
                        order,
                        checkoutUrl: savedCheckout.gateway_checkout_url,
                    });
                }
                await releaseUnstartedCheckout(order.order_code);
                throw error;
            }

            try {
                const { data: updatedOrder, error: updateError } = await serviceClient
                    .from('orders')
                    .update({
                        gateway_session_id: checkout.sessionId,
                        gateway_checkout_url: checkout.checkoutUrl,
                    })
                    .eq('order_code', order.order_code)
                    .eq('payment_status', 'pending_payment')
                    .is('gateway_session_id', null)
                    .select('order_code')
                    .maybeSingle();
                if (updateError || !updatedOrder) {
                    await cancelCheckout(checkout.sessionId);
                    if (updateError) throw updateError;
                    const savedCheckout = await getSavedCheckout(order.order_code);
                    if (savedCheckout?.gateway_session_id && savedCheckout.gateway_checkout_url
                        && savedCheckout.payment_status === 'pending_payment') {
                        return sendJson(response, 200, {
                            order,
                            checkoutUrl: savedCheckout.gateway_checkout_url,
                        });
                    }
                    return sendJson(response, 409, { error: 'The order changed before checkout could be saved.' });
                }
                return sendJson(response, 200, { order, checkoutUrl: checkout.checkoutUrl });
            } catch (error) {
                const savedCheckout = await getSavedCheckout(order.order_code);
                if (!savedCheckout?.gateway_session_id) await releaseUnstartedCheckout(order.order_code);
                throw error;
            }
        }

        if (action === 'status') {
            if (!isFarmStaffRole(requestUser.profile.role)) {
                return sendJson(response, 403, { error: 'Only farm staff can update order status.' });
            }
            const { error } = await requestUser.client.rpc('update_order_status', {
                p_order_code: payload.orderCode,
                p_status: payload.status,
            });
            if (error) {
                return sendJson(response, 400, { error: error.message });
            }
            return sendJson(response, 200, { ok: true });
        }

        if (action === 'collect-cod') {
            if (!isFarmStaffRole(requestUser.profile.role)) {
                return sendJson(response, 403, { error: 'Only farm staff can confirm cash collection.' });
            }
            const { error } = await requestUser.client.rpc('confirm_cod_collection', {
                p_order_code: payload.orderCode,
                p_reference: payload.reference || null,
            });
            if (error) {
                return sendJson(response, 400, { error: error.message });
            }
            return sendJson(response, 200, { ok: true });
        }

        if (action === 'cancel') {
            if (!isFarmStaffRole(requestUser.profile.role)) {
                return sendJson(response, 403, { error: 'Only farm staff can cancel orders.' });
            }
            const { data: order, error: orderError } = await serviceClient
                .from('orders')
                .select('order_code, payment_method, gateway_session_id, gateway_provider, gateway_expired_at, payment_status, order_status')
                .eq('order_code', payload.orderCode)
                .maybeSingle();
            if (orderError) {
                throw orderError;
            }
            if (!order) {
                return sendJson(response, 404, { error: 'Order not found.' });
            }
            if (order.payment_method === 'online') {
                if (!order.gateway_session_id) {
                    return sendJson(response, 409, { error: 'Online checkout is not ready to cancel.' });
                }
                if (!order.gateway_expired_at) {
                    if (order.gateway_provider === 'paymongo') {
                        await expirePayMongoCheckout(order.gateway_session_id);
                    } else {
                        await cancelXenditCheckout(order.gateway_session_id);
                    }
                }
                const { error: expirationError } = await serviceClient
                    .from('orders')
                    .update({ gateway_expired_at: new Date().toISOString() })
                    .eq('order_code', order.order_code)
                    .eq('payment_status', 'pending_payment');
                if (expirationError) {
                    throw expirationError;
                }
            }
            const { error } = await requestUser.client.rpc('update_order_status', {
                p_order_code: payload.orderCode,
                p_status: 'cancelled',
            });
            if (error) {
                return sendJson(response, 400, { error: error.message });
            }
            return sendJson(response, 200, { ok: true });
        }

        return sendJson(response, 400, { error: 'Unknown order action.' });
    } catch (error) {
        if (error instanceof RangeError || error instanceof SyntaxError) {
            return sendJson(response, 400, { error: error.message || 'Invalid request.' });
        }
        console.error('Order API error:', error.message);
        return sendJson(response, 500, { error: 'The request could not be completed. Try again or contact support.' });
    }
}
