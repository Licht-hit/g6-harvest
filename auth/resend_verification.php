<?php
require_once __DIR__ . '/../includes/auth.php';

$emailValue = $_POST['email'] ?? $_GET['email'] ?? $_SESSION['verification_email'] ?? '';
$email = is_string($emailValue) ? trim($emailValue) : '';
$submitted = false;
$verificationEmailReady = false;
$deliveryError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $validatedEmail = filter_var($email, FILTER_VALIDATE_EMAIL);
    if ($validatedEmail) {
        $email = $validatedEmail;
        $verificationEmailReady = true;
        $_SESSION['verification_email'] = $validatedEmail;
        $statement = $db->prepare(
            'SELECT id, name, email
             FROM users
             WHERE email = ? AND email_verified_at IS NULL'
        );
        $statement->execute([$validatedEmail]);
        $user = $statement->fetch();

        if ($user) {
            $cooldown = $db->prepare(
                'SELECT COUNT(*) FROM email_verification_tokens
                 WHERE user_id = ? AND created_at > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 60 SECOND)'
            );
            $cooldown->execute([(int) $user['id']]);

            if ((int) $cooldown->fetchColumn() === 0) {
                try {
                    $deliveryStatus = send_email_verification($db, $user);
                    if ($deliveryStatus === 'failed') {
                        error_log('Unable to send a confirmation code for a resend request.');
                    }
                } catch (RuntimeException $exception) {
                    error_log('Unable to prepare confirmation email: ' . $exception->getMessage());
                    $deliveryError = $exception->getMessage();
                }
            }
        }
    }

    $submitted = true;
}

$developmentVerificationCode = $_SESSION['development_verification_code'] ?? null;
$developmentCodeExpires = (int) ($_SESSION['development_verification_code_expires'] ?? 0);
if (
    !is_string($developmentVerificationCode)
    || !is_six_digit_code($developmentVerificationCode)
    || $developmentCodeExpires <= time()
) {
    $developmentVerificationCode = null;
}

$pageTitle = 'Get confirmation code';
$pageClass = 'auth-page';
require __DIR__ . '/../includes/header.php';
?>
<section class="form-card">
    <?php if ($submitted): ?>
        <h1>Check your inbox</h1>
        <p class="muted">
            If that address has an unverified account and is not rate-limited, a confirmation code will be sent.
            If no email arrives, check config/email.local.php and the PHP error log.
        </p>
        <?php if ($deliveryError): ?>
            <div class="notice error"><?= h($deliveryError) ?></div>
        <?php endif; ?>
        <?php if ($verificationEmailReady): ?>
            <p><a class="button" href="<?= h(app_url('auth/verify_email.php')) ?>">Enter confirmation code</a></p>
        <?php endif; ?>
    <?php else: ?>
        <h1>Get a confirmation code</h1>
        <p class="muted">
            Enter the email address used to register.
            If it has an unverified account, we will send a fresh 6-digit confirmation code.
        </p>
        <form method="post">
            <?= csrf_field() ?>
            <div class="field">
                <label for="email">Email</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    required
                    value="<?= h($email) ?>"
                >
            </div>
            <button type="submit">Send confirmation code</button>
        </form>
    <?php endif; ?>

    <?php if ($developmentVerificationCode): ?>
        <div class="notice">
            <strong>Local development confirmation code</strong>
            <p>
                Email is not sent while the app is in local development mode.
                Enter this code to verify the account:
            </p>
            <p><code><?= h($developmentVerificationCode) ?></code></p>
        </div>
    <?php endif; ?>

    <p class="auth-links">
        <a href="<?= h(app_url('auth/login.php')) ?>">Back to log in</a>
    </p>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
