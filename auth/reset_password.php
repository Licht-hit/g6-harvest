<?php
require_once __DIR__ . '/../includes/auth.php';

$emailValue = $_POST['email'] ?? $_GET['email'] ?? $_SESSION['password_reset_email'] ?? '';
$email = is_string($emailValue) ? trim($emailValue) : '';
$submitted = $_SERVER['REQUEST_METHOD'] === 'POST';
$error = '';
$inputError = '';
$invalidCode = false;
$attemptsExceeded = false;
$resetComplete = false;

if ($submitted) {
    verify_csrf();
    $validatedEmail = filter_var($email, FILTER_VALIDATE_EMAIL);
    $code = $_POST['code'] ?? '';
    $password = $_POST['password'] ?? '';
    $passwordConfirmation = $_POST['password_confirmation'] ?? '';

    if (!$validatedEmail) {
        $inputError = 'Enter a valid email address.';
    } elseif (!is_six_digit_code($code)) {
        $inputError = 'Enter the 6-digit password reset code from your email.';
    } elseif (!is_string($password) || strlen($password) < 10) {
        $error = 'Choose a password with at least 10 characters.';
    } elseif (!is_string($passwordConfirmation) || $password !== $passwordConfirmation) {
        $error = 'The password confirmation does not match.';
    } else {
        try {
            $db->beginTransaction();
            $statement = $db->prepare(
                'SELECT t.user_id, t.token_hash, t.attempt_count,
                        (t.expires_at <= CURRENT_TIMESTAMP) AS expired
                 FROM password_reset_tokens AS t
                 JOIN users AS u ON u.id = t.user_id
                 WHERE u.email = ?
                 FOR UPDATE'
            );
            $statement->execute([$validatedEmail]);
            $record = $statement->fetch();

            if (!$record) {
                $db->rollBack();
                $invalidCode = true;
            } elseif ((int) $record['expired'] === 1) {
                $db->rollBack();
                $invalidCode = true;
            } elseif ((int) $record['attempt_count'] >= 5) {
                $db->rollBack();
                $attemptsExceeded = true;
            } elseif (password_verify($code, $record['token_hash'])) {
                $userId = (int) $record['user_id'];
                $update = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
                $update->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);

                $delete = $db->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?');
                $delete->execute([$userId]);
                log_activity(
                    'password_reset',
                    'user',
                    $userId,
                    'Password reset using an emailed recovery code.',
                    $userId
                );
                $db->commit();
                $resetComplete = true;
                unset(
                    $_SESSION['password_reset_email'],
                    $_SESSION['development_password_reset_code'],
                    $_SESSION['development_password_reset_code_expires']
                );
            } else {
                $attempts = (int) $record['attempt_count'] + 1;
                $update = $db->prepare(
                    'UPDATE password_reset_tokens
                     SET attempt_count = ?
                     WHERE user_id = ?'
                );
                $update->execute([$attempts, (int) $record['user_id']]);
                $db->commit();
                $attemptsExceeded = $attempts >= 5;
                $invalidCode = !$attemptsExceeded;
            }
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }
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
    || $resetComplete
) {
    $developmentResetCode = null;
}

$pageTitle = 'Reset password';
$pageClass = 'auth-page';
require __DIR__ . '/../includes/header.php';
?>
<section class="form-card auth-recovery-card">
    <?php if ($resetComplete): ?>
        <h1>Password updated</h1>
        <p class="muted" role="status">Your password has been reset. You can now sign in with your new password.</p>
        <a class="button" href="<?= h(app_url('auth/login.php')) ?>">Back to log in</a>
    <?php else: ?>
        <p class="dashboard-eyebrow">Account recovery</p>
        <h1>Choose a new password</h1>
        <p class="muted">Enter the 6-digit code sent to your email and choose a password with at least 10 characters. The code expires in 10 minutes.</p>

        <?php if ($inputError): ?>
            <div class="notice error" role="alert"><?= h($inputError) ?></div>
        <?php elseif ($attemptsExceeded): ?>
            <div class="notice error" role="alert">
                Too many incorrect attempts. Request a new password reset code.
            </div>
        <?php elseif ($invalidCode): ?>
            <div class="notice error" role="alert">
                That code is invalid or expired. Check it or request a new one.
            </div>
        <?php elseif ($error): ?>
            <div class="notice error" role="alert"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <div class="field">
                <label for="reset-email">Email address</label>
                <input
                    id="reset-email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    required
                    value="<?= h($email) ?>"
                >
            </div>
            <div class="field">
                <label for="reset-code">6-digit password reset code</label>
                <input
                    id="reset-code"
                    type="text"
                    name="code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    pattern="[0-9]{6}"
                    minlength="6"
                    maxlength="6"
                    required
                >
            </div>
            <div class="field">
                <label for="password">New password</label>
                <div class="password-field">
                    <input
                        id="password"
                        type="password"
                        name="password"
                        minlength="10"
                        autocomplete="new-password"
                        required
                    >
                    <button class="password-toggle" type="button" data-password-toggle="password" aria-label="Show password">Show</button>
                </div>
            </div>
            <div class="field">
                <label for="password_confirmation">Confirm new password</label>
                <div class="password-field">
                    <input
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        minlength="10"
                        autocomplete="new-password"
                        required
                    >
                    <button class="password-toggle" type="button" data-password-toggle="password_confirmation" aria-label="Show password">Show</button>
                </div>
            </div>
            <button type="submit">Reset password</button>
        </form>

        <?php if ($developmentResetCode): ?>
            <div class="notice">
                <strong>Local development password reset code</strong>
                <p>Email is not sent in development mode. Enter this code to reset the password:</p>
                <p><code><?= h($developmentResetCode) ?></code></p>
            </div>
        <?php endif; ?>

        <p class="auth-links">
            Need a new code?
            <a href="<?= h(app_url('auth/forgot_password.php?email=' . rawurlencode($email))) ?>">Request another reset code</a>
        </p>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
