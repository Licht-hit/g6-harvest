<?php
require_once __DIR__ . '/../includes/auth.php';

$submitted = false;
$emailValue = $_POST['email'] ?? $_GET['email'] ?? $_SESSION['password_reset_email'] ?? '';
$email = is_string($emailValue) ? trim($emailValue) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $validatedEmail = filter_var($email, FILTER_VALIDATE_EMAIL);

    if ($validatedEmail) {
        $email = $validatedEmail;
        $statement = $db->prepare(
            'SELECT id, name, email FROM users WHERE email = ?'
        );
        $statement->execute([$validatedEmail]);
        $user = $statement->fetch();

        if ($user) {
            $cooldown = $db->prepare(
                'SELECT COUNT(*) FROM password_reset_tokens
                 WHERE user_id = ?
                    AND created_at > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 60 SECOND)'
            );
            $cooldown->execute([(int) $user['id']]);

            if ((int) $cooldown->fetchColumn() === 0) {
                try {
                    send_password_reset($db, $user);
                } catch (RuntimeException $exception) {
                    error_log('Unable to prepare password reset email: ' . $exception->getMessage());
                }
            }
        }
    }

    $submitted = true;
}

$developmentResetCode = $_SESSION['development_password_reset_code'] ?? null;
$developmentCodeExpires = (int) ($_SESSION['development_password_reset_code_expires'] ?? 0);
$resetCodeEmail = $_SESSION['password_reset_email'] ?? '';
if (
    !is_string($developmentResetCode)
    || !is_six_digit_code($developmentResetCode)
    || $developmentCodeExpires <= time()
    || !is_string($resetCodeEmail)
    || strcasecmp($email, $resetCodeEmail) !== 0
) {
    $developmentResetCode = null;
}

$pageTitle = 'Forgot password';
$pageClass = 'auth-page';
require __DIR__ . '/../includes/header.php';
?>
<section class="form-card auth-recovery-card">
    <?php if ($submitted): ?>
        <h1>Check your inbox</h1>
        <p class="muted" role="status">
            If an account exists for that email address, a password reset code will be sent.
            The code expires in 10 minutes.
        </p>
        <?php if (filter_var($email, FILTER_VALIDATE_EMAIL)): ?>
            <p><a class="button" href="<?= h(app_url('auth/reset_password.php?email=' . rawurlencode($email))) ?>">Enter reset code</a></p>
        <?php endif; ?>
    <?php else: ?>
        <p class="dashboard-eyebrow">Account recovery</p>
        <h1>Forgot your password?</h1>
        <p class="muted">
            Enter the email address linked to your Harvest account and we’ll send a 6-digit reset code.
        </p>
        <form method="post">
            <?= csrf_field() ?>
            <div class="field">
                <label for="email">Email address</label>
                <input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    placeholder="you@example.com"
                    required
                    value="<?= h($email) ?>"
                >
            </div>
            <button type="submit">Send reset code</button>
        </form>
    <?php endif; ?>

    <?php if ($developmentResetCode): ?>
        <div class="notice">
            <strong>Local development password reset code</strong>
            <p>Email is not sent in development mode. Enter this code on the password reset page:</p>
            <p><code><?= h($developmentResetCode) ?></code></p>
        </div>
    <?php endif; ?>

    <p class="auth-links">
        <a href="<?= h(app_url('auth/login.php')) ?>">Back to log in</a>
    </p>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
