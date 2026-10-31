<?php

// This file handles the PayMongo integration.
// The code is written in a simple way so beginners can understand how the app checks
// if payment is configured before creating a real checkout session.
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
