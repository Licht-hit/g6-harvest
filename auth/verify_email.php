<?php
require_once __DIR__ . '/../includes/auth.php';

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$tokenValidFormat = is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token);
$verified = false;
$alreadyVerified = false;

if ($tokenValidFormat && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $db->beginTransaction();

        // Lock the token row while changing verification status.
        $statement = $db->prepare(
            'SELECT t.user_id, u.email_verified_at
             FROM email_verification_tokens AS t
             JOIN users AS u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.expires_at > CURRENT_TIMESTAMP
             FOR UPDATE'
        );
        $statement->execute([hash('sha256', $token)]);
        $record = $statement->fetch();

        if ($record) {
            $alreadyVerified = $record['email_verified_at'] !== null;
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
        } else {
            $db->rollBack();
        }
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $exception;
    }
}

$tokenRecord = null;
if ($tokenValidFormat && !$verified) {
    $statement = $db->prepare(
        'SELECT u.email_verified_at, t.expires_at
         FROM email_verification_tokens AS t
         JOIN users AS u ON u.id = t.user_id
         WHERE t.token_hash = ?'
    );
    $statement->execute([hash('sha256', $token)]);
    $tokenRecord = $statement->fetch() ?: null;
}

$tokenExpired = $tokenRecord
    && strtotime($tokenRecord['expires_at']) <= time();

$pageTitle = 'Verify email';
$pageClass = 'auth-page';
require __DIR__ . '/../includes/header.php';
?>
<section class="form-card">
    <?php if ($verified): ?>
        <h1>Email verified</h1>
        <p>
            <?= $alreadyVerified
                ? 'This email address was already verified.'
                : 'Your email address has been verified.' ?>
        </p>
        <a class="button" href="<?= h(app_url('auth/login.php')) ?>">Continue to log in</a>
    <?php elseif (!$tokenValidFormat || !$tokenRecord || $tokenExpired): ?>
        <h1>Verification link unavailable</h1>
        <p class="muted">
            This link is invalid, expired, or has already been used.
            Request a new verification email to continue.
        </p>
        <a class="button" href="<?= h(app_url('auth/resend_verification.php')) ?>">Request a new link</a>
    <?php elseif ($tokenRecord['email_verified_at'] !== null): ?>
        <h1>Email already verified</h1>
        <p>This email address is verified. You can log in.</p>
        <a class="button" href="<?= h(app_url('auth/login.php')) ?>">Continue to log in</a>
    <?php else: ?>
        <h1>Verify your email address</h1>
        <p class="muted">
            Confirm this email address to activate your account.
        </p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <button type="submit">Verify email</button>
        </form>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
