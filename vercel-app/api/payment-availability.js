function sendJson(response, status, body) {
    response.status(status).setHeader('Content-Type', 'application/json; charset=utf-8');
    response.end(JSON.stringify(body));
}

export default function handler(request, response) {
    if (request.method !== 'GET') {
        response.setHeader('Allow', 'GET');
        return sendJson(response, 405, { error: 'Method not allowed.' });
    }

    let appUrl;
    try {
        appUrl = new URL(process.env.APP_URL || '');
    } catch {
        return sendJson(response, 200, { onlinePaymentEnabled: false });
    }
    const onlinePaymentEnabled = Boolean(
        process.env.XENDIT_SECRET_KEY
        && process.env.XENDIT_WEBHOOK_TOKEN
        && appUrl.protocol === 'https:'
        && appUrl.hostname
        && !appUrl.username
        && !appUrl.password
        && !appUrl.search
        && !appUrl.hash
    );
    return sendJson(response, 200, { onlinePaymentEnabled });
}
