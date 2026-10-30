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
require __DIR__ . '/../includes/header.php';
?>
<section class="form-card">
    <h1>Welcome back</h1>
    <p class="muted">Log in to manage your account or browse farm produce.</p>

    <?php if ($error): ?>
        <div class="notice error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($unverifiedEmail)): ?>
        <p>
            <a href="<?= h(app_url('auth/resend_verification.php?email=' . rawurlencode($unverifiedEmail))) ?>">
                Resend verification email
            </a>
        </p>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>

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
            <label for="password">Password</label>
            <input
                id="password"
                type="password"
                name="password"
                autocomplete="current-password"
                required
            >
        </div>

        <button type="submit">Log in</button>
    </form>

    <p class="auth-links">
        New buyer?
        <a href="<?= h(app_url('auth/register.php')) ?>">Create an account</a>.
    </p>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
