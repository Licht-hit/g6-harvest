<?php
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect(app_url('index.php'));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? '';

    if ($name === '' || !$email || strlen($password) < 10) {
        $error = 'Enter your name, a valid email address, and a password of at least 10 characters.';
    } else {
        try {
            $statement = $db->prepare(
                "INSERT INTO users (name, email, password, role, email_verified_at)
                 VALUES (?, ?, ?, 'buyer', NULL)"
            );
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $statement->execute([$name, $email, $hashedPassword]);
        } catch (mysqli_sql_exception $exception) {
            if ((int) $exception->getCode() === 1062) {
                $error = 'That email address is already registered. '
                    . 'If it is not verified, request a new verification link.';
            } else {
                throw $exception;
            }
        }
        if ($error === '') {
            $newUserId = (int) $db->lastInsertId();
            log_activity('account_created', 'user', $newUserId, 'Buyer account registered.', $newUserId);
            $user = [
                'id' => $newUserId,
                'name' => $name,
                'email' => $email,
            ];

            try {
                $deliveryStatus = send_email_verification($db, $user);
            } catch (RuntimeException $exception) {
                error_log('Unable to prepare verification email: ' . $exception->getMessage());
                $deliveryStatus = 'failed';
                $deliveryError = $exception->getMessage();
            }

            if ($deliveryStatus === 'sent') {
                flash('success', 'Account created. Check your email for a verification link before logging in.');
            } elseif ($deliveryStatus === 'development') {
                flash('success', 'Account created. Use the local verification link on the next page. No email was sent.');
            } else {
                $message = 'Account created, but the verification email was not sent.';
                if (!empty($deliveryError)) {
                    $message .= ' ' . $deliveryError;
                }
                flash('error', $message);
            }
            redirect(app_url('auth/resend_verification.php'));
        }
    }
}

$pageTitle = 'Create buyer account';
require __DIR__ . '/../includes/header.php';
?>
<section class="form-card">
    <h1>Create buyer account</h1>

    <?php if ($error): ?>
        <div class="notice error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>

        <div class="field">
            <label for="name">Name</label>
            <input
                id="name"
                name="name"
                required
                value="<?= h($_POST['name'] ?? '') ?>"
            >
        </div>

        <div class="field">
            <label for="email">Email</label>
            <input
                id="email"
                type="email"
                name="email"
                autocomplete="email"
                required
                value="<?= h($_POST['email'] ?? '') ?>"
            >
        </div>

        <div class="field">
            <label for="password">Password (at least 10 characters)</label>
            <input
                id="password"
                type="password"
                name="password"
                minlength="10"
                autocomplete="new-password"
                required
            >
        </div>

        <button type="submit">Register</button>
    </form>

    <p class="auth-links">
        Already registered?
        <a href="<?= h(app_url('auth/login.php')) ?>">Log in</a>.
    </p>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
