<?php

// This file holds common helper functions used across the project.
// The code is written in a simple beginner-friendly way to reduce confusion.

// Build a URL relative to the project root, even from a nested page.
function app_url(string $path = ''): string
{
    $scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if (in_array(basename($scriptDirectory), ['auth', 'modules', 'buyer'], true)) {
        $scriptDirectory = str_replace('\\', '/', dirname($scriptDirectory));
    }

    $base = rtrim($scriptDirectory, '/');
    return $base . '/' . ltrim($path, '/');
}

// Escape text before placing it in an HTML page.
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function dashboard_path_for_role(string $role): string
{
    if ($role === 'buyer') {
        return 'buyer/dashboard.php';
    }

    if (in_array($role, ['admin', 'staff', 'farmer'], true)) {
        return 'dashboard.php';
    }

    throw new RuntimeException('The signed-in account has an unsupported role.');
}

function user_has_role(array $user, string ...$roles): bool
{
    return isset($user['role'])
        && is_string($user['role'])
        && in_array($user['role'], $roles, true);
}

function log_activity(
    string $action,
    string $entityType,
    ?int $entityId = null,
    string $details = '',
    ?int $userId = null
): void {
    global $db;

    $user = current_user();
    $actorId = $userId ?? ($user ? (int) $user['id'] : null);
    $statement = $db->prepare(
        'INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details)
         VALUES (?, ?, ?, ?, ?)'
    );
    $statement->execute([$actorId, $action, $entityType, $entityId, $details ?: null]);
}

function is_valid_date($value): bool
{
    if (!is_string($value)) {
        return false;
    }

    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function require_login(): void
{
    if (!is_logged_in()) {
        flash('error', 'Please log in to continue.');
        redirect(app_url('auth/login.php'));
    }

    // Check the database so an old session cannot bypass email verification.
    global $db;
    $statement = $db->prepare('SELECT email_verified_at FROM users WHERE id = ?');
    $statement->execute([(int) current_user()['id']]);
    $verifiedAt = $statement->fetchColumn();
    if (!$verifiedAt) {
        unset($_SESSION['user']);
        flash('error', 'Please verify your email address before continuing.');
        redirect(app_url('auth/login.php'));
    }
}

function require_role(string ...$roles): void
{
    require_login();

    if (!user_has_role(current_user(), ...$roles)) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }
}

function csrf_token(): string
{
    // Create a random token once and save it in the session.
    // This helps prevent fake form submissions from another website.
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    // Add the hidden field to every form so the server can check it.
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void
{
    // Simple check: the form must submit the same token we created.
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || empty($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(400);
        exit('Invalid form token. Reload the page and try again.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return $message;
}

function money($amount): string
{
    // Always format money the same way so prices look clean in the UI.
    return '₱' . number_format((float) $amount, 2);
}

function vegetable_categories(): array
{
    return [
        'Cruciferous vegetables',
        'Cucurbits',
        'Fruiting vegetables',
        'Leafy greens',
        'Legumes',
        'Root vegetables',
        'Other',
    ];
}

function product_picture(string $productName, ?string $imagePath = null): array
{
    static $pictures = [
        'tomato' => ['file' => 'tomato.webp', 'icon' => '🍅'],
        'carrot' => ['file' => 'OIP (2).webp', 'icon' => '🥕'],
        'lettuce' => ['file' => 'OIP (3).webp', 'icon' => '🥬'],
        'cucumber' => ['file' => 'cart.jpeg', 'icon' => '🥒'],
        'spinach' => ['file' => 'spinach.jpg', 'icon' => '🥬'],
        'bell pepper' => ['file' => 'OIP.webp', 'icon' => '🫑'],
        'zucchini' => ['file' => 'imrs.webp', 'icon' => '🥒'],
        'broccoli' => ['file' => 'OIP (1).webp', 'icon' => '🥦'],
        'kale' => ['file' => 'OIP (4).webp', 'icon' => '🥬'],
        'green beans' => ['file' => 'R.jpeg', 'icon' => '🫛'],
    ];

    $picture = $pictures[strtolower(trim($productName))] ?? [
        'file' => null,
        'icon' => '🥬',
    ];
    if ($imagePath !== null
        && preg_match('/\A[a-f0-9]{64}\.(?:jpg|png|webp)\z/', $imagePath) === 1) {
        $uploadedImage = __DIR__ . '/../assets/uploads/vegetables/' . $imagePath;
        if (is_file($uploadedImage)) {
            $picture['url'] = app_url('assets/uploads/vegetables/' . rawurlencode($imagePath));
            return $picture;
        }
    }

    $imagePath = $picture['file']
        ? __DIR__ . '/../assets/images/' . $picture['file']
        : null;

    $picture['url'] = $imagePath && is_file($imagePath)
        ? app_url('assets/images/' . rawurlencode($picture['file']))
        : null;

    return $picture;
}

function store_vegetable_photo(array $upload): string
{
    $uploadError = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($uploadError !== UPLOAD_ERR_OK) {
        $message = match ($uploadError) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Vegetable photos must be 5 MB or smaller.',
            UPLOAD_ERR_NO_FILE => 'Choose a photo to upload.',
            default => 'The photo could not be uploaded. Please try again.',
        };
        throw new RuntimeException($message);
    }

    $temporaryPath = $upload['tmp_name'] ?? '';
    $fileSize = $upload['size'] ?? null;
    if (!is_string($temporaryPath)
        || !is_uploaded_file($temporaryPath)
        || !is_int($fileSize)
        || $fileSize < 1
        || $fileSize > 5 * 1024 * 1024) {
        throw new RuntimeException('Choose a valid photo that is 5 MB or smaller.');
    }

    if (!class_exists('finfo')) {
        throw new RuntimeException('Photo uploads require the PHP fileinfo extension.');
    }
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($temporaryPath);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!is_string($mimeType) || !isset($extensions[$mimeType]) || @getimagesize($temporaryPath) === false) {
        throw new RuntimeException('Choose a JPEG, PNG, or WebP image.');
    }

    $filename = bin2hex(random_bytes(32)) . '.' . $extensions[$mimeType];
    $destination = __DIR__ . '/../assets/uploads/vegetables/' . $filename;
    if (!move_uploaded_file($temporaryPath, $destination)) {
        throw new RuntimeException('The photo could not be saved. Check the uploads folder permissions.');
    }

    return $filename;
}

function delete_vegetable_photo(?string $imagePath): void
{
    if ($imagePath === null || preg_match('/\A[a-f0-9]{64}\.(?:jpg|png|webp)\z/', $imagePath) !== 1) {
        return;
    }

    $uploadedImage = __DIR__ . '/../assets/uploads/vegetables/' . $imagePath;
    if (is_file($uploadedImage) && !unlink($uploadedImage)) {
        throw new RuntimeException('The old vegetable photo could not be removed.');
    }
}

function auth_token_url(string $page, string $token): string
{
    $emailConfig = require __DIR__ . '/../config/email.php';
    $configuredAppUrl = $emailConfig['app_url'];

    if ($configuredAppUrl) {
        $parts = parse_url($configuredAppUrl);
        $validScheme = $parts && in_array($parts['scheme'] ?? '', ['http', 'https'], true);
        $hasHost = $parts && !empty($parts['host']);
        $hasNoCredentials = $parts && !isset($parts['user']) && !isset($parts['pass']);

        if (!$validScheme || !$hasHost || !$hasNoCredentials) {
            throw new RuntimeException(
                'APP_URL must be an absolute HTTP or HTTPS URL for this application.'
            );
        }

        $baseUrl = rtrim($configuredAppUrl, '/');
        return $baseUrl . '/auth/' . $page . '?token=' . rawurlencode($token);
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $validHost = preg_match(
        '/^(?:[a-zA-Z0-9.-]+|\[[0-9a-fA-F:]+\])(?::\d{1,5})?$/',
        $host
    );

    if (!$validHost) {
        throw new RuntimeException(
            'Set APP_URL to the public base URL of this application to send verification emails.'
        );
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host
        . app_url('auth/' . $page)
        . '?token=' . rawurlencode($token);
}

function email_verification_url(string $token): string
{
    return auth_token_url('verify_email.php', $token);
}

function password_reset_url(string $token): string
{
    return auth_token_url('reset_password.php', $token);
}

function send_password_reset(MySQLiDatabase $db, array $user): string
{
    $token = bin2hex(random_bytes(32));
    $resetUrl = password_reset_url($token);
    $emailConfig = require __DIR__ . '/../config/email.php';
    $tokenHash = hash('sha256', $token);

    $statement = $db->prepare(
        'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at)
         VALUES (?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 HOUR), CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE
            token_hash = VALUES(token_hash),
            expires_at = VALUES(expires_at),
            created_at = VALUES(created_at)'
    );
    $statement->execute([(int) $user['id'], $tokenHash]);

    if ($emailConfig['mode'] === 'development') {
        $_SESSION['development_password_reset_url'] = $resetUrl;
        return 'development';
    }

    try {
        $host = trim((string) $emailConfig['host']);
        $port = (int) $emailConfig['port'];
        $username = trim((string) $emailConfig['username']);
        $password = preg_replace('/\s+/', '', (string) $emailConfig['password']);
        $fromAddress = $emailConfig['from'];

        if ($host === '' || $port < 1 || $port > 65535) {
            throw new RuntimeException('Set a valid SMTP host and port in config/email.local.php.');
        }

        if (!filter_var($username, FILTER_VALIDATE_EMAIL) || $password === '') {
            throw new RuntimeException('Set your Gmail address and Google App Password in config/email.local.php.');
        }

        if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL) || strcasecmp($fromAddress, $username) !== 0) {
            throw new RuntimeException('For Gmail, the sender email must match the SMTP username.');
        }

        $body = "Hello " . $user['name'] . ",\n\n"
            . "Reset your Harvest password using the link below within one hour:\n"
            . $resetUrl . "\n\n"
            . "If you did not request a password reset, you can ignore this message.\n";

        smtp_send_email(
            $host,
            $port,
            $username,
            $password,
            $fromAddress,
            $user['email'],
            'Reset your Harvest password',
            $body
        );
    } catch (RuntimeException $exception) {
        error_log('Password reset email delivery failed: ' . $exception->getMessage());
        $deleteToken = $db->prepare(
            'DELETE FROM password_reset_tokens WHERE user_id = ? AND token_hash = ?'
        );
        $deleteToken->execute([(int) $user['id'], $tokenHash]);
        throw $exception;
    }

    return 'sent';
}

function send_email_verification(MySQLiDatabase $db, array $user): string
{
    $token = bin2hex(random_bytes(32));
    $verificationUrl = email_verification_url($token);
    $emailConfig = require __DIR__ . '/../config/email.php';

    if ($emailConfig['mode'] === 'development') {
        $tokenHash = hash('sha256', $token);

        $statement = $db->prepare(
            'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at, created_at)
             VALUES (?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR), CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
                token_hash = VALUES(token_hash),
                expires_at = VALUES(expires_at),
                created_at = VALUES(created_at)'
        );
        $statement->execute([(int) $user['id'], $tokenHash]);

        $_SESSION['development_verification_url'] = $verificationUrl;
        return 'development';
    }

    $host = trim((string) $emailConfig['host']);
    $port = (int) $emailConfig['port'];
    $username = trim((string) $emailConfig['username']);
    $password = preg_replace('/\s+/', '', (string) $emailConfig['password']);
    $fromAddress = $emailConfig['from'];

    if ($host === '' || $port < 1 || $port > 65535) {
        throw new RuntimeException('Set a valid SMTP host and port in config/email.local.php.');
    }

    if (!filter_var($username, FILTER_VALIDATE_EMAIL) || $password === '') {
        throw new RuntimeException('Set your Gmail address and Google App Password in config/email.local.php.');
    }

    if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL) || strcasecmp($fromAddress, $username) !== 0) {
        throw new RuntimeException('For Gmail, the sender email must match the SMTP username.');
    }

    $subject = 'Verify your Vegetable System email address';
    $body = "Hello " . $user['name'] . ",\n\n"
        . "Please verify your email address by opening this link within 24 hours:\n"
        . $verificationUrl . "\n\n"
        . "If you did not create this account, you can ignore this message.\n";

    // Store only the token hash so the original link is not saved in the database.
    $tokenHash = hash('sha256', $token);
    $statement = $db->prepare(
        'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at, created_at)
         VALUES (?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR), CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE
            token_hash = VALUES(token_hash),
            expires_at = VALUES(expires_at),
            created_at = VALUES(created_at)'
    );
    $statement->execute([(int) $user['id'], $tokenHash]);

    try {
        smtp_send_email(
            $host,
            $port,
            $username,
            $password,
            $fromAddress,
            $user['email'],
            $subject,
            $body
        );
    } catch (RuntimeException $exception) {
        error_log('Verification email delivery failed: ' . $exception->getMessage());

        // Remove the token if delivery fails, so a failed email cannot leave an unusable link.
        $deleteToken = $db->prepare('DELETE FROM email_verification_tokens WHERE user_id = ? AND token_hash = ?');
        $deleteToken->execute([(int) $user['id'], $tokenHash]);
        throw $exception;
    }

    return 'sent';
}

function smtp_send_email(
    string $host,
    int $port,
    string $username,
    string $password,
    string $fromAddress,
    string $toAddress,
    string $subject,
    string $body
): void {
    if (!extension_loaded('openssl')) {
        throw new RuntimeException(
            'PHP OpenSSL is required for secure Gmail SMTP. Enable the openssl extension in php.ini.'
        );
    }

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $implicitTls = $port === 465;
    $transport = $implicitTls ? 'ssl://' : 'tcp://';
    $socket = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errorNumber,
        $errorMessage,
        15,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        throw new RuntimeException(
            'Could not connect to ' . $host . ':' . $port
            . '. Check the host, port, firewall, and internet connection.'
        );
    }

    stream_set_timeout($socket, 15);

    try {
        smtp_expect_response($socket, [220]);
        smtp_send_command($socket, 'EHLO vegetable-system.local', [250]);

        if (!$implicitTls) {
            smtp_send_command($socket, 'STARTTLS', [220]);

            $tlsEnabled = stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );
            if ($tlsEnabled !== true) {
                throw new RuntimeException(
                    'Could not start secure TLS. Enable the OpenSSL extension in php.ini.'
                );
            }

            smtp_send_command($socket, 'EHLO vegetable-system.local', [250]);
        }

        smtp_send_command($socket, 'AUTH LOGIN', [334]);
        smtp_send_command($socket, base64_encode($username), [334]);
        smtp_send_command($socket, base64_encode($password), [235]);
        smtp_send_command($socket, 'MAIL FROM:<' . $fromAddress . '>', [250]);
        smtp_send_command($socket, 'RCPT TO:<' . $toAddress . '>', [250, 251]);
        smtp_send_command($socket, 'DATA', [354]);

        // Encode the subject so names with special characters display correctly.
        $safeSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $message = 'From: Vegetable System <' . $fromAddress . ">\r\n"
            . 'To: <' . $toAddress . ">\r\n"
            . 'Subject: ' . $safeSubject . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n"
            . "\r\n"
            . str_replace(["\r\n", "\r"], "\n", $body);
        $message = str_replace("\n", "\r\n", $message);
        $message = preg_replace('/(?m)^\./', '..', $message);
        smtp_write($socket, $message . "\r\n.\r\n");
        smtp_expect_response($socket, [250]);
        smtp_send_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function smtp_send_command($socket, string $command, array $expectedCodes): string
{
    smtp_write($socket, $command . "\r\n");
    return smtp_expect_response($socket, $expectedCodes);
}

function smtp_write($socket, string $data): void
{
    $remaining = $data;
    while ($remaining !== '') {
        $written = fwrite($socket, $remaining);
        if ($written === false || $written === 0) {
            throw new RuntimeException('The connection to the email server closed unexpectedly.');
        }
        $remaining = substr($remaining, $written);
    }
}

function smtp_expect_response($socket, array $expectedCodes): string
{
    $response = '';
    do {
        $line = fgets($socket, 515);
        if ($line === false) {
            $metadata = stream_get_meta_data($socket);
            if (!empty($metadata['timed_out'])) {
                throw new RuntimeException('The email server timed out while responding.');
            }
            throw new RuntimeException('The email server closed the connection unexpectedly.');
        }
        $response .= $line;
    } while (isset($line[3]) && $line[3] === '-');

    // Each SMTP reply starts with a three-digit status code.
    $responseCode = (int) substr($response, 0, 3);
    if (!in_array($responseCode, $expectedCodes, true)) {
        $message = trim(preg_replace('/\s+/', ' ', $response));
        throw new RuntimeException(
            'The email server rejected a request (SMTP '
            . $responseCode . '): ' . $message
        );
    }

    return $response;
}

function cart_contents(): array
{
    return $_SESSION['cart'] ?? [];
}

function cart_count(): int
{
    // The badge counts product lines, not kilograms or other fractional quantities.
    return count(cart_contents());
}
