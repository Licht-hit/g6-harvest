<?php
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect(app_url('index.php'));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic beginner-friendly login flow:
    // 1. verify the hidden CSRF token
    // 2. read the email and password
    // 3. check the user in the database
    // 4. verify the password using password_verify()
    // 5. check if the email is verified
    // 6. create a new session after login
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
            <span class="auth-visual-tag">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none">
                    <path d="M19.5 4.5C12.2 4.8 6.5 7.3 6.5 13.4c0 2.4 1.6 4 3.8 4 6.5 0 8.6-6.5 9.2-12.9Z" fill="currentColor"/>
                    <path d="M4.5 20c3.4-5.5 6.8-8.1 12-11.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
                Grown with care
            </span>
            <h1>Fresh Harvest, Fresh Choices</h1>
            <p>Discover fresh vegetables and farm-grown favorites, thoughtfully harvested and ready for your table.</p>
        </div>
    </div>
    <div class="auth-form-panel">
        <div class="auth-form-content">
            <p class="dashboard-eyebrow">Welcome to Harvest</p>
            <h2>Welcome back</h2>
            <p class="auth-form-intro">Log in to manage your farm or continue shopping fresh from the harvest.</p>

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

                <p class="auth-forgot-link">
                    <a href="<?= h(app_url('auth/forgot_password.php')) ?>">Forgot password?</a>
                </p>

                <button class="auth-submit" type="submit">Log in <span aria-hidden="true">→</span></button>
            </form>

            <p class="auth-footer">
                New to Harvest?
                <a href="<?= h(app_url('auth/register.php')) ?>">Create a buyer account</a>
            </p>
        </div>
    </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
