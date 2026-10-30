<?php
require_once __DIR__ . '/../includes/auth.php';

$email = trim($_POST['email'] ?? $_GET['email'] ?? '');
$submitted = false;
$developmentVerificationUrl = $_SESSION['development_verification_url'] ?? null;
$deliveryError = null;
unset($_SESSION['development_verification_url']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $validatedEmail = filter_var($email, FILTER_VALIDATE_EMAIL);
    if ($validatedEmail) {
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
                        error_log('Unable to send email verification message for a resend request.');
                    }
                } catch (RuntimeException $exception) {
                    error_log('Unable to prepare verification email: ' . $exception->getMessage());
                    $deliveryError = $exception->getMessage();
                }
            }
        }
    }

    $developmentVerificationUrl = $_SESSION['development_verification_url'] ?? null;
    unset($_SESSION['development_verification_url']);
    $submitted = true;
}

$pageTitle = 'Resend verification email';
require __DIR__ . '/../includes/header.php';
?>
<section class="form-card">
    <?php if ($submitted): ?>
        <h1>Check your inbox</h1>
        <p class="muted">
            If that address has an unverified account, the verification link will be sent.
            If no email arrives, check config/email.local.php and the PHP error log.
        </p>
        <?php if ($deliveryError): ?>
            <div class="notice error"><?= h($deliveryError) ?></div>
        <?php endif; ?>
    <?php else: ?>
        <h1>Resend verification email</h1>
        <p class="muted">
            Enter the email address used to register.
            If it has an unverified account, we will send a fresh link.
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
            <button type="submit">Send verification link</button>
        </form>
    <?php endif; ?>

    <?php if ($developmentVerificationUrl): ?>
        <div class="notice">
            <strong>Local development link</strong>
            <p>
                Email is not sent while the app is in local development mode.
                Open this one-time link to verify the account:
            </p>
            <p>
                <a href="<?= h($developmentVerificationUrl) ?>">
                    <?= h($developmentVerificationUrl) ?>
                </a>
            </p>
        </div>
    <?php endif; ?>

    <p class="auth-links">
        <a href="<?= h(app_url('auth/login.php')) ?>">Back to log in</a>
    </p>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
