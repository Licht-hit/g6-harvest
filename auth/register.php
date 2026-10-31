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
    $passwordConfirmation = $_POST['password_confirmation'] ?? '';

    if ($name === '' || !$email || strlen($password) < 10) {
        $error = 'Enter your name, a valid email address, and a password of at least 10 characters.';
    } elseif ($password !== $passwordConfirmation) {
        $error = 'The password confirmation does not match.';
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
$pageClass = 'auth-page';
require __DIR__ . '/../includes/header.php';
?>
<section class="auth-layout">
    <div class="auth-visual">
        <div class="auth-visual-content">
            <span class="auth-visual-tag"><span aria-hidden="true">✦</span> Better harvests, together</span>
            <h1>Grow your connection to fresh food.</h1>
            <p>Create an account to explore locally grown produce and follow your orders.</p>
        </div>
    </div>
    <div class="auth-form-panel">
        <div class="auth-form-content">
            <p class="dashboard-eyebrow">Join the community</p>
            <h2>Create your account</h2>
            <p class="auth-form-intro">A few details and you’ll be ready to shop the harvest.</p>

            <?php if ($error): ?>
                <div class="notice error" role="alert"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <?= csrf_field() ?>

                <div class="field">
                    <label for="name">Full name</label>
                    <input
                        id="name"
                        name="name"
                        autocomplete="name"
                        placeholder="Your name"
                        required
                        value="<?= h($_POST['name'] ?? '') ?>"
                    >
                </div>

                <div class="field">
                    <label for="email">Email address</label>
                    <input
                        id="email"
                        type="email"
                        name="email"
                        autocomplete="email"
                        placeholder="you@example.com"
                        required
                        value="<?= h($_POST['email'] ?? '') ?>"
                    >
                </div>

                <div class="field">
                    <label for="password">Password</label>
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
                    <small class="password-help">Use at least 10 characters.</small>
                </div>

                <div class="field">
                    <label for="password_confirmation">Confirm password</label>
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

                <button class="auth-submit" type="submit">Create account <span aria-hidden="true">→</span></button>
            </form>

            <p class="auth-footer">
                Already have an account?
                <a href="<?= h(app_url('auth/login.php')) ?>">Sign in</a>
            </p>
        </div>
    </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
