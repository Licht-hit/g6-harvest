<?php
require_once __DIR__ . '/../includes/auth.php';

$submitted = false;
$developmentResetUrl = null;
$email = '';
unset($_SESSION['development_password_reset_url']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $rawEmail = $_POST['email'] ?? '';
    $validatedEmail = is_string($rawEmail)
        ? filter_var(trim($rawEmail), FILTER_VALIDATE_EMAIL)
        : false;

    if ($validatedEmail) {
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

    $developmentResetUrl = $_SESSION['development_password_reset_url'] ?? null;
    unset($_SESSION['development_password_reset_url']);
    $submitted = true;
} elseif (is_string($_GET['email'] ?? null)) {
    $email = trim($_GET['email']);
}

$pageTitle = 'Forgot password';
$pageClass = 'auth-page';
require __DIR__ . '/../includes/header.php';
?>
<section class="form-card auth-recovery-card">
    <?php if ($submitted): ?>
        <h1>Check your inbox</h1>
        <p class="muted" role="status">
            If an account exists for that email address, we’ll send a password reset link.
            The link expires in one hour.
        </p>
    <?php else: ?>
        <p class="dashboard-eyebrow">Account recovery</p>
        <h1>Forgot your password?</h1>
        <p class="muted">
            Enter the email address linked to your Harvest account and we’ll send a reset link.
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
            <button type="submit">Send reset link</button>
        </form>
    <?php endif; ?>

    <?php if ($developmentResetUrl): ?>
        <div class="notice">
            <strong>Local development link</strong>
            <p>Email is not sent in development mode. Open this one-time link to reset the password:</p>
            <p><a href="<?= h($developmentResetUrl) ?>">Reset your password</a></p>
        </div>
    <?php endif; ?>

    <p class="auth-links">
        <a href="<?= h(app_url('auth/login.php')) ?>">Back to log in</a>
    </p>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
