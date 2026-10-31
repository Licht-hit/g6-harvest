<?php
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect(app_url('index.php'));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? '';

    $statement = $db->prepare(
        'SELECT id, name, email, password, role, email_verified_at
         FROM users
         WHERE email = ?'
    );
    $statement->execute([$email ?: '']);
    $user = $statement->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        $error = 'The email or password was not recognized.';
    } elseif ($user['email_verified_at'] === null) {
        $unverifiedEmail = $user['email'];
        $error = 'Please verify your email address before logging in.';
    } else {
        // Change the session ID after login to prevent session fixation.
        session_regenerate_id(true);

        unset($user['password'], $user['email_verified_at']);
        $_SESSION['user'] = $user;
        log_activity('login', 'user', (int) $user['id'], 'User signed in.');

        redirect(app_url('index.php'));
    }
}

$pageTitle = 'Log in';
$pageClass = 'auth-page';
require __DIR__ . '/../includes/header.php';
?>
<section class="auth-layout">
    <div class="auth-visual">
        <div class="auth-visual-content">
            <span class="auth-visual-tag"><span aria-hidden="true">✦</span> Grown with care</span>
            <h1>Good food starts close to home.</h1>
            <p>Fresh produce and thoughtful farm management, all in one place.</p>
        </div>
    </div>
    <div class="auth-form-panel">
        <div class="auth-form-content">
            <p class="dashboard-eyebrow">Welcome to Harvest</p>
            <h2>Welcome back</h2>
            <p class="auth-form-intro">Sign in to continue to your farm account.</p>

            <?php if ($error): ?>
                <div class="notice error" role="alert"><?= h($error) ?></div>
            <?php endif; ?>

            <?php if (!empty($unverifiedEmail)): ?>
                <p class="notice">
                    Need a new verification link?
                    <a href="<?= h(app_url('auth/resend_verification.php?email=' . rawurlencode($unverifiedEmail))) ?>">
                        Resend verification email
                    </a>
                </p>
            <?php endif; ?>

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
                            autocomplete="current-password"
                            placeholder="Enter your password"
                            required
                        >
                        <button class="password-toggle" type="button" data-password-toggle="password" aria-label="Show password">Show</button>
                    </div>
                </div>

                <button class="auth-submit" type="submit">Sign in <span aria-hidden="true">→</span></button>
            </form>

            <p class="auth-footer">
                New to Harvest?
                <a href="<?= h(app_url('auth/register.php')) ?>">Create a buyer account</a>
            </p>
        </div>
    </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
