<?php

// This file loads payment settings from environment variables or a local config file.
// Beginner reminder: do not put real secret keys in the app code if you can help it.
$localSettingsFile = __DIR__ . '/payment.local.php';
$localSettings = file_exists($localSettingsFile) ? require $localSettingsFile : [];

if (!is_array($localSettings)) {
    throw new RuntimeException('config/payment.local.php must return an array of payment settings.');
}

$getSetting = static function (string $environmentName, string $settingName) use ($localSettings): string {
    $environmentValue = getenv($environmentName);
    if ($environmentValue !== false && $environmentValue !== '') {
        return trim($environmentValue);
    }

    return trim((string) ($localSettings[$settingName] ?? ''));
};

return [
    'paymongo_secret_key' => $getSetting('PAYMONGO_SECRET_KEY', 'paymongo_secret_key'),
    'paymongo_webhook_secret' => $getSetting('PAYMONGO_WEBHOOK_SECRET', 'paymongo_webhook_secret'),
    'xendit_secret_key' => $getSetting('XENDIT_SECRET_KEY', 'xendit_secret_key'),
    'xendit_webhook_token' => $getSetting('XENDIT_WEBHOOK_TOKEN', 'xendit_webhook_token'),
    'app_url' => rtrim($getSetting('APP_URL', 'app_url'), '/'),
];
