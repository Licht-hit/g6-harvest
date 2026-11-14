<?php

// Shared configuration and hosted-checkout helpers for online payment providers.
function paymongo_config(): array
{
    static $configuration;
    if ($configuration === null) {
        $configuration = require __DIR__ . '/../config/payment.php';
    }

    return $configuration;
}

function paymongo_is_configured(): bool
{
    // This is a simple safety check before we create a real online payment.
    // If anything is missing, the app refuses to start online checkout.
    $configuration = paymongo_config();
    $url = parse_url($configuration['app_url']);

    $hasSecretKey = str_starts_with($configuration['paymongo_secret_key'], 'sk_');
    $hasWebhookSecret = $configuration['paymongo_webhook_secret'] !== '';
    $hasValidUrl = $url !== false
        && ($url['scheme'] ?? '') === 'https'
        && !empty($url['host'])
        && !isset($url['user'])
        && !isset($url['pass'])
        && !isset($url['query'])
        && !isset($url['fragment']);
    $hasOpenSsl = extension_loaded('openssl');
    $canUseFopen = (bool) ini_get('allow_url_fopen');

    return $hasSecretKey
        && $hasWebhookSecret
        && $hasValidUrl
        && $hasOpenSsl
        && $canUseFopen;
}

function paymongo_unavailable_message(): string
{
    return 'Online payment is unavailable. Configure PAYMONGO_SECRET_KEY, '
        . 'PAYMONGO_WEBHOOK_SECRET, a public HTTPS APP_URL, PHP OpenSSL, and allow_url_fopen first.';
}

function paymongo_checkout_url(string $orderCode, float $amount): array
{
    if (!paymongo_is_configured()) {
        throw new RuntimeException(paymongo_unavailable_message());
    }

    $configuration = paymongo_config();
    $baseUrl = $configuration['app_url'];
    $returnUrl = $baseUrl . '/buyer/payment_return.php?order=' . rawurlencode($orderCode);
    $response = paymongo_api_request('POST', '/v2/checkout_sessions', [
        'data' => [
            'attributes' => [
                'line_items' => [[
                    'name' => 'Vegetable order ' . $orderCode . ' (bulk discount and delivery included)',
                    'amount' => (int) round($amount * 100),
                    'currency' => 'PHP',
                    'quantity' => 1,
                ]],
                'payment_method_types' => ['gcash', 'card'],
                'success_url' => $returnUrl . '&result=success',
                'cancel_url' => $returnUrl . '&result=cancel',
                'reference_number' => $orderCode,
                'metadata' => ['order_code' => $orderCode],
            ],
        ],
    ]);

    $session = $response['data'] ?? [];
    $attributes = $session['attributes'] ?? [];
    $checkoutUrl = $attributes['checkout_url'] ?? '';
    $checkoutHost = is_string($checkoutUrl) ? parse_url($checkoutUrl, PHP_URL_HOST) : false;
    $checkoutScheme = is_string($checkoutUrl) ? parse_url($checkoutUrl, PHP_URL_SCHEME) : false;

    if (
        !is_string($session['id'] ?? null)
        || !is_string($checkoutUrl)
        || $checkoutScheme !== 'https'
        || $checkoutHost !== 'checkout.paymongo.com'
    ) {
        throw new RuntimeException('The payment provider returned an invalid checkout session.');
    }

    return [$session['id'], $checkoutUrl];
}

function paymongo_expire_checkout_session(string $sessionId): void
{
    if (!paymongo_is_configured()) {
        throw new RuntimeException(paymongo_unavailable_message());
    }

    try {
        paymongo_api_request(
            'POST',
            '/v1/checkout_sessions/' . rawurlencode($sessionId) . '/expire',
            []
        );
    } catch (RuntimeException $exception) {
        throw new RuntimeException(
            'PayMongo could not confirm that checkout is safe to cancel. No stock was released; check payment status and try again.'
        );
    }
}

function paymongo_payment_transition_action(
    string $currentStatus,
    string $incomingStatus,
    string $orderStatus,
    bool $gatewayExpired = false
): string {
    if ($incomingStatus === 'paid' && in_array($currentStatus, ['pending_payment', 'failed'], true)) {
        return $orderStatus === 'cancelled' || $gatewayExpired ? 'late_payment' : 'apply';
    }

    if ($incomingStatus === 'failed' && $currentStatus === 'pending_payment' && $orderStatus !== 'cancelled') {
        return 'apply';
    }

    return 'ignore';
}

function paymongo_api_request(string $method, string $path, array $payload): array
{
    $configuration = paymongo_config();
    $body = $payload ? json_encode($payload, JSON_THROW_ON_ERROR) : '';
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => [
                'Authorization: Basic ' . base64_encode($configuration['paymongo_secret_key'] . ':'),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            'content' => $body,
            'timeout' => 20,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $warning = null;
    set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
        $warning = $message;
        return true;
    });
    try {
        $responseBody = file_get_contents('https://api.paymongo.com' . $path, false, $context);
    } finally {
        restore_error_handler();
    }

    if ($responseBody === false) {
        error_log('PayMongo request failed: ' . ($warning ?? 'no response'));
        throw new RuntimeException('Could not connect to the payment provider. Please try again.');
    }

    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $statusMatch);
    $statusCode = (int) ($statusMatch[1] ?? 0);
    try {
        $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        error_log('PayMongo returned invalid JSON with HTTP status ' . $statusCode . '.');
        throw new RuntimeException('The payment provider returned an invalid response.');
    }

    if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded)) {
        error_log('PayMongo API returned HTTP status ' . $statusCode . '.');
        throw new RuntimeException(
            'The payment provider could not start checkout. Verify your enabled payment methods and try again.'
        );
    }

    return $decoded;
}

function paymongo_verify_webhook(string $rawBody, string $signatureHeader): bool
{
    $configuration = paymongo_config();
    if (!paymongo_is_configured() || $signatureHeader === '') {
        return false;
    }

    $signatureParts = [];
    foreach (explode(',', $signatureHeader) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) === 2) {
            $signatureParts[$pair[0]] = $pair[1];
        }
    }

    $timestamp = $signatureParts['t'] ?? '';
    $isTestMode = str_starts_with($configuration['paymongo_secret_key'], 'sk_test_');
    $providedSignature = $signatureParts[$isTestMode ? 'te' : 'li'] ?? '';
    if ($timestamp === '' || !ctype_digit($timestamp) || $providedSignature === '') {
        return false;
    }

    $signedPayload = $timestamp . '.' . $rawBody;
    $expectedSignature = hash_hmac(
        'sha256',
        $signedPayload,
        $configuration['paymongo_webhook_secret']
    );

    return hash_equals($expectedSignature, $providedSignature);
}

function xendit_is_configured(): bool
{
    $configuration = paymongo_config();
    $url = parse_url($configuration['app_url']);
    $hasValidUrl = $url !== false
        && ($url['scheme'] ?? '') === 'https'
        && !empty($url['host'])
        && !isset($url['user'])
        && !isset($url['pass'])
        && !isset($url['query'])
        && !isset($url['fragment']);

    return $configuration['xendit_secret_key'] !== ''
        && $configuration['xendit_webhook_token'] !== ''
        && $hasValidUrl
        && extension_loaded('openssl')
        && (bool) ini_get('allow_url_fopen');
}

function xendit_unavailable_message(): string
{
    return 'Online payment is unavailable. Configure XENDIT_SECRET_KEY, '
        . 'XENDIT_WEBHOOK_TOKEN, a public HTTPS APP_URL, PHP OpenSSL, and allow_url_fopen first.';
}

function xendit_checkout_url(string $orderCode, float $amount): array
{
    if (!xendit_is_configured()) {
        throw new RuntimeException(xendit_unavailable_message());
    }
    if (!is_finite($amount) || $amount <= 0 || $amount > 99999999.99) {
        throw new RuntimeException('The order total is invalid for online payment.');
    }

    $configuration = paymongo_config();
    $baseUrl = $configuration['app_url'];
    $response = xendit_api_request('POST', '/sessions', [
        'reference_id' => $orderCode,
        'session_type' => 'PAY',
        'mode' => 'PAYMENT_LINK',
        'amount' => (float) number_format($amount, 2, '.', ''),
        'currency' => 'PHP',
        'country' => 'PH',
        'description' => 'Harvest order ' . $orderCode,
        'success_return_url' => $baseUrl . '/buyer/payment_return.php?order=' . rawurlencode($orderCode) . '&result=success',
        'cancel_return_url' => $baseUrl . '/buyer/payment_return.php?order=' . rawurlencode($orderCode) . '&result=cancel',
    ]);

    $sessionId = $response['payment_session_id'] ?? null;
    $checkoutUrl = $response['payment_link_url'] ?? null;
    if (
        !is_string($sessionId)
        || $sessionId === ''
        || !is_string($checkoutUrl)
        || !xendit_is_valid_checkout_url($checkoutUrl)
    ) {
        throw new RuntimeException('Xendit returned an invalid checkout session.');
    }

    return [$sessionId, $checkoutUrl];
}

function xendit_cancel_checkout_session(string $sessionId): void
{
    if ($sessionId === '') {
        throw new RuntimeException('The Xendit checkout session is missing.');
    }

    try {
        xendit_api_request(
            'POST',
            '/sessions/' . rawurlencode($sessionId) . '/cancel'
        );
    } catch (RuntimeException $exception) {
        throw new RuntimeException(
            'Xendit could not confirm that checkout is safe to cancel. No stock was released; check payment status and try again.',
            0,
            $exception
        );
    }
}

function online_payment_provider_is_configured(?string $provider): bool
{
    return match ($provider) {
        'xendit' => xendit_is_configured(),
        'paymongo' => paymongo_is_configured(),
        default => false,
    };
}

function cancel_online_payment_session(array $order): string
{
    $sessionId = $order['gateway_session_id'] ?? '';
    if (!is_string($sessionId) || $sessionId === '') {
        throw new RuntimeException('The online checkout session is missing.');
    }

    $provider = $order['gateway_provider'] ?? '';
    if ($provider === 'xendit') {
        xendit_cancel_checkout_session($sessionId);
        return 'Xendit';
    }
    if ($provider === 'paymongo') {
        paymongo_expire_checkout_session($sessionId);
        return 'PayMongo';
    }

    throw new RuntimeException('The online payment provider for this order is not recognized.');
}

function xendit_is_valid_checkout_url(string $checkoutUrl): bool
{
    $scheme = parse_url($checkoutUrl, PHP_URL_SCHEME);
    $host = parse_url($checkoutUrl, PHP_URL_HOST);
    if (
        $scheme !== 'https'
        || !is_string($host)
        || parse_url($checkoutUrl, PHP_URL_USER) !== null
        || parse_url($checkoutUrl, PHP_URL_PASS) !== null
    ) {
        return false;
    }

    $host = strtolower($host);
    return $host === 'xen.to'
        || $host === 'dev.xen.to'
        || str_ends_with($host, '.xendit.co');
}

function payment_checkout_url_is_valid(?string $provider, string $checkoutUrl): bool
{
    if ($provider === 'xendit') {
        return xendit_is_valid_checkout_url($checkoutUrl);
    }

    return $provider === 'paymongo'
        && parse_url($checkoutUrl, PHP_URL_SCHEME) === 'https'
        && parse_url($checkoutUrl, PHP_URL_HOST) === 'checkout.paymongo.com'
        && parse_url($checkoutUrl, PHP_URL_USER) === null
        && parse_url($checkoutUrl, PHP_URL_PASS) === null;
}

function xendit_verify_callback_token($received, string $expected): bool
{
    return is_string($received)
        && $expected !== ''
        && hash_equals($expected, $received);
}

function xendit_amount_to_cents($amount): ?int
{
    if (!is_int($amount) && !is_float($amount) && !(is_string($amount) && is_numeric($amount))) {
        return null;
    }

    $numericAmount = (float) $amount;
    if (!is_finite($numericAmount) || $numericAmount < 0 || $numericAmount > 99999999.99) {
        return null;
    }

    $amountCents = (int) round($numericAmount * 100);
    return abs($numericAmount * 100 - $amountCents) < 0.000001 ? $amountCents : null;
}

function xendit_webhook_event_values(array $event): array
{
    $data = is_array($event['data'] ?? null) ? $event['data'] : [];
    $eventType = is_string($event['event'] ?? null) ? $event['event'] : '';
    $isSessionEvent = in_array($eventType, ['payment_session.completed', 'payment_session.expired'], true);
    $isRefundEvent = in_array($eventType, ['refund.succeeded', 'refund.failed'], true);
    $sessionId = $isSessionEvent
        ? ($data['payment_session_id'] ?? null)
        : ($isRefundEvent ? ($data['payment_id'] ?? null) : null);
    $paymentId = is_string($data['payment_id'] ?? null) && $data['payment_id'] !== ''
        ? $data['payment_id']
        : null;
    $orderCode = $isSessionEvent && is_string($data['reference_id'] ?? null)
        ? $data['reference_id']
        : null;

    $eventId = null;
    if ($eventType === 'payment_session.completed' && $paymentId !== null) {
        $eventId = 'xendit:' . $paymentId;
    } elseif ($eventType === 'payment_session.expired' && is_string($sessionId) && $sessionId !== '') {
        $eventId = 'xendit:session:' . $sessionId . ':expired';
    } elseif ($isRefundEvent && is_string($data['refund_id'] ?? null) && $data['refund_id'] !== '') {
        $eventId = 'xendit:' . $eventType . ':' . $data['refund_id'];
    }

    return [
        'event_id' => $eventId,
        'event_type' => $eventType,
        'session_id' => is_string($sessionId) && $sessionId !== '' ? $sessionId : null,
        'order_code' => $orderCode,
        'amount_cents' => xendit_amount_to_cents($data['amount'] ?? null),
        'currency' => is_string($data['currency'] ?? null) ? $data['currency'] : null,
        'payment_id' => $paymentId,
        'status' => is_string($data['status'] ?? null) ? $data['status'] : null,
    ];
}

function xendit_payment_event_action(
    string $eventType,
    string $paymentStatus,
    string $orderStatus,
    bool $amountMatches,
    bool $gatewayExpired = false
): string {
    if ($eventType === 'payment_session.completed') {
        if (
            !$amountMatches
            || $orderStatus === 'cancelled'
            || $gatewayExpired
            || in_array($paymentStatus, ['paid', 'refunded'], true)
        ) {
            return 'manual_review';
        }

        return 'paid';
    }

    if ($eventType === 'payment_session.expired') {
        if (
            $orderStatus === 'cancelled'
            || in_array($paymentStatus, ['paid', 'refunded'], true)
        ) {
            return 'manual_review';
        }

        return 'failed';
    }

    if ($eventType === 'refund.succeeded') {
        return !$amountMatches || $paymentStatus !== 'paid'
            ? 'manual_review'
            : 'refunded';
    }

    return $eventType === 'refund.failed' ? 'record_only' : 'ignore';
}

function xendit_api_request(string $method, string $path, ?array $payload = null): array
{
    $configuration = paymongo_config();
    if ($configuration['xendit_secret_key'] === '') {
        throw new RuntimeException('XENDIT_SECRET_KEY is not configured.');
    }

    $headers = [
        'Authorization: Basic ' . base64_encode($configuration['xendit_secret_key'] . ':'),
        'Accept: application/json',
    ];
    $body = '';
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $headers,
            'content' => $body,
            'timeout' => 20,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $warning = null;
    set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
        $warning = $message;
        return true;
    });
    try {
        $responseBody = file_get_contents('https://api.xendit.co' . $path, false, $context);
    } finally {
        restore_error_handler();
    }

    if ($responseBody === false) {
        error_log('Xendit request failed: ' . ($warning ?? 'no response'));
        throw new RuntimeException('Could not connect to Xendit. Please try again.');
    }

    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $statusMatch);
    $statusCode = (int) ($statusMatch[1] ?? 0);
    if (trim($responseBody) === '' && $statusCode >= 200 && $statusCode < 300) {
        return [];
    }

    try {
        $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        error_log('Xendit returned invalid JSON with HTTP status ' . $statusCode . '.');
        throw new RuntimeException('Xendit returned an invalid response.');
    }

    if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded)) {
        error_log('Xendit API returned HTTP status ' . $statusCode . '.');
        throw new RuntimeException('Xendit could not complete the payment request. Verify the account configuration and try again.');
    }

    return $decoded;
}
