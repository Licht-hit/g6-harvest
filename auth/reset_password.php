<?php
require_once __DIR__ . '/../includes/auth.php';

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$tokenIsValidFormat = is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token) === 1;
$error = '';
$resetComplete = false;

if ($tokenIsValidFormat && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $password = $_POST['password'] ?? '';
    $passwordConfirmation = $_POST['password_confirmation'] ?? '';

    if (!is_string($password) || strlen($password) < 10) {
        $error = 'Choose a password with at least 10 characters.';
    } elseif (!is_string($passwordConfirmation) || $password !== $passwordConfirmation) {
        $error = 'The password confirmation does not match.';
    } else {
        try {
            $db->beginTransaction();
            $statement = $db->prepare(
                'SELECT user_id
                 FROM password_reset_tokens
                 WHERE token_hash = ? AND expires_at > CURRENT_TIMESTAMP
                 FOR UPDATE'
            );
            $statement->execute([hash('sha256', $token)]);
            $tokenRecord = $statement->fetch();

            if (!$tokenRecord) {
                $db->rollBack();
                $error = 'This reset link is invalid, expired, or already used. Request a new link to continue.';
            } else {
                $userId = (int) $tokenRecord['user_id'];
                $update = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
                $update->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);

                $delete = $db->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?');
                $delete->execute([$userId]);
                log_activity('password_reset', 'user', $userId, 'Password reset using an emailed recovery link.', $userId);
                $db->commit();
                $resetComplete = true;
            }
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }
}

$tokenAvailable = false;
if ($tokenIsValidFormat && !$resetComplete) {
    $statement = $db->prepare(
        'SELECT 1 FROM password_reset_tokens
         WHERE token_hash = ? AND expires_at > CURRENT_TIMESTAMP'
    );
    $statement->execute([hash('sha256', $token)]);
    $tokenAvailable = (bool) $statement->fetchColumn();
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
    <?php elseif (!$tokenIsValidFormat || !$tokenAvailable): ?>
        <h1>Reset link unavailable</h1>
        <p class="muted">
            This reset link is invalid, expired, or already used. Request a new link to continue.
        </p>
        <a class="button" href="<?= h(app_url('auth/forgot_password.php')) ?>">Request a new link</a>
    <?php else: ?>
        <p class="dashboard-eyebrow">Account recovery</p>
        <h1>Choose a new password</h1>
        <p class="muted">Use at least 10 characters for your new password.</p>

        <?php if ($error): ?>
            <div class="notice error" role="alert"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= h($token) ?>">
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
    <?php endif; ?>

    <?php if (!$resetComplete): ?>
        <p class="auth-links"><a href="<?= h(app_url('auth/login.php')) ?>">Back to log in</a></p>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
