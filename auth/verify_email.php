<?php
require_once __DIR__ . '/../includes/auth.php';

$emailValue = $_POST['email'] ?? $_GET['email'] ?? $_SESSION['verification_email'] ?? '';
$email = is_string($emailValue) ? trim($emailValue) : '';
$submitted = $_SERVER['REQUEST_METHOD'] === 'POST';
$verified = false;
$alreadyVerified = false;
$invalidCode = false;
$attemptsExceeded = false;
$inputError = '';

$developmentVerificationCode = $_SESSION['development_verification_code'] ?? null;
$developmentCodeExpires = (int) ($_SESSION['development_verification_code_expires'] ?? 0);
if (
    !is_string($developmentVerificationCode)
    || !is_email_verification_code($developmentVerificationCode)
    || $developmentCodeExpires <= time()
) {
    $developmentVerificationCode = null;
}

if ($submitted) {
    verify_csrf();
    $validatedEmail = filter_var($email, FILTER_VALIDATE_EMAIL);
    $code = $_POST['code'] ?? '';

    if (!$validatedEmail) {
        $inputError = 'Enter a valid email address.';
    } elseif (!is_email_verification_code($code)) {
        $inputError = 'Enter the 6-digit confirmation code from your email.';
    } else {
        try {
            $db->beginTransaction();
            $statement = $db->prepare(
                'SELECT t.user_id, t.token_hash, t.attempt_count,
                        (t.expires_at <= CURRENT_TIMESTAMP) AS expired,
                        u.email_verified_at
                 FROM email_verification_tokens AS t
                 JOIN users AS u ON u.id = t.user_id
                 WHERE u.email = ?
                 FOR UPDATE'
            );
            $statement->execute([$validatedEmail]);
            $record = $statement->fetch();

            if (!$record) {
                $db->rollBack();
                $invalidCode = true;
            } elseif ($record['email_verified_at'] !== null) {
                $delete = $db->prepare('DELETE FROM email_verification_tokens WHERE user_id = ?');
                $delete->execute([(int) $record['user_id']]);
                $db->commit();
                $alreadyVerified = true;
                unset(
                    $_SESSION['verification_email'],
                    $_SESSION['development_verification_code'],
                    $_SESSION['development_verification_code_expires']
                );
            } elseif ((int) $record['expired'] === 1) {
                $db->rollBack();
                $invalidCode = true;
            } elseif ((int) $record['attempt_count'] >= 5) {
                $db->rollBack();
                $attemptsExceeded = true;
            } elseif (password_verify($code, $record['token_hash'])) {
                $update = $db->prepare(
                    'UPDATE users
                     SET email_verified_at = COALESCE(email_verified_at, CURRENT_TIMESTAMP)
                     WHERE id = ?'
                );
                $update->execute([(int) $record['user_id']]);

                $delete = $db->prepare('DELETE FROM email_verification_tokens WHERE user_id = ?');
                $delete->execute([(int) $record['user_id']]);
                log_activity(
                    'email_verified',
                    'user',
                    (int) $record['user_id'],
                    'Email address verified.',
                    (int) $record['user_id']
                );

                $db->commit();
                $verified = true;
                unset(
                    $_SESSION['verification_email'],
                    $_SESSION['development_verification_code'],
                    $_SESSION['development_verification_code_expires']
                );
                $developmentVerificationCode = null;
            } else {
                $attempts = (int) $record['attempt_count'] + 1;
                $update = $db->prepare(
                    'UPDATE email_verification_tokens
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

$pageTitle = 'Verify email';
$pageClass = 'auth-page';
require __DIR__ . '/../includes/header.php';
?>
<section class="form-card">
    <?php if ($verified): ?>
        <h1>Email verified</h1>
        <p>Your email address has been verified. You can now log in.</p>
        <a class="button" href="<?= h(app_url('auth/login.php')) ?>">Continue to log in</a>
    <?php elseif ($alreadyVerified): ?>
        <h1>Email already verified</h1>
        <p>This email address is verified. You can log in.</p>
        <a class="button" href="<?= h(app_url('auth/login.php')) ?>">Continue to log in</a>
    <?php else: ?>
        <h1>Verify your email address</h1>
        <p class="muted">Enter the 6-digit code sent to your email. The code expires after 10 minutes.</p>

        <?php if ($inputError): ?>
            <div class="notice error" role="alert"><?= h($inputError) ?></div>
        <?php elseif ($attemptsExceeded): ?>
            <div class="notice error" role="alert">
                Too many incorrect attempts. Request a new confirmation code.
            </div>
        <?php elseif ($invalidCode): ?>
            <div class="notice error" role="alert">
                That code is invalid or expired. Check the code or request a new one.
            </div>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <div class="field">
                <label for="verification-email">Email address</label>
                <input
                    id="verification-email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    required
                    value="<?= h($email) ?>"
                >
            </div>
            <div class="field">
                <label for="verification-code">6-digit confirmation code</label>
                <input
                    id="verification-code"
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
            <button type="submit">Verify email</button>
        </form>

        <?php if ($developmentVerificationCode): ?>
            <div class="notice">
                <strong>Local development confirmation code</strong>
                <p>Email is not sent in local development mode. Enter this code to verify the account:</p>
                <p><code><?= h($developmentVerificationCode) ?></code></p>
            </div>
        <?php endif; ?>

        <p class="auth-links">
            Need a new code?
            <a href="<?= h(app_url('auth/resend_verification.php')) ?>">Request another confirmation code</a>
        </p>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
