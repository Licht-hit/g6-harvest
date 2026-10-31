<?php

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
    'app_url' => rtrim($getSetting('APP_URL', 'app_url'), '/'),
];
