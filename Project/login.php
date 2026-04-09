<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

$flash = consumeFlash();
$formError = null;
$formData = [
    'identifier' => '',
];

if (isAuthenticated()) {
    if (hasRole('ADMIN')) {
        redirectTo('admin.php');
    }
    if (hasRole('CLIENT')) {
        redirectTo('student-dashboard.php');
    }
    logoutUser();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = cleanInput($_POST['identifier'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $formData['identifier'] = $identifier;

    if ($identifier === '' || $password === '') {
        $formError = 'Please enter username/email and password.';
    } else {
        try {
            $pdo = getDatabaseConnection();
            ensureDefaultDemoUsers($pdo);

            $stmt = $pdo->prepare(
                <<<SQL
                    SELECT
                        auth_user_id,
                        role,
                        username,
                        display_name,
                        email,
                        password_hash,
                        is_active
                    FROM auth_users
                    WHERE
                        (LOWER(email) = LOWER(:identifier_email) OR LOWER(username) = LOWER(:identifier_username))
                    LIMIT 1
                SQL
            );
            $stmt->execute([
                'identifier_email' => $identifier,
                'identifier_username' => $identifier,
            ]);
            $user = $stmt->fetch();

            if (!$user || (int) $user['is_active'] !== 1 || !password_verify($password, (string) $user['password_hash'])) {
                $formError = 'Invalid credentials. Please try again.';
            } else {
                loginUser($user);

                $updateStmt = $pdo->prepare(
                    'UPDATE auth_users SET last_login_at = NOW() WHERE auth_user_id = :auth_user_id'
                );
                $updateStmt->execute([
                    'auth_user_id' => (int) $user['auth_user_id'],
                ]);

                if (strtoupper((string) $user['role']) === 'ADMIN') {
                    redirectTo('admin.php');
                }

                redirectTo('student-dashboard.php');
            }
        } catch (Throwable $exception) {
            $formError = 'Login failed because of a server error. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | College Event Portal</title>
    <link rel="stylesheet" href="assets/css/site.css">
</head>
<body>
    <header class="topbar">
        <div class="container topbar-inner">
            <a href="index.php" class="logo">College Event Portal</a>
            <nav class="nav-links" aria-label="Main navigation">
                <a href="index.php">Home</a>
                <a href="signup.php">Create Account</a>
                <a href="login.php" class="active">Login</a>
            </nav>
        </div>
    </header>

    <main class="container page-center">
        <section class="auth-card">
            <div class="card-head">
                <h1>User Login</h1>
                <p>Admin and students can login from this page.</p>
            </div>

            <?php if ($flash !== null): ?>
                <div class="alert <?= e((string) $flash['type']); ?>"><?= e((string) $flash['message']); ?></div>
            <?php endif; ?>

            <?php if ($formError !== null): ?>
                <div class="alert error"><?= e($formError); ?></div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <label for="identifier">Username or Email</label>
                <input
                    id="identifier"
                    name="identifier"
                    type="text"
                    value="<?= e($formData['identifier']); ?>"
                    placeholder="Enter username or email"
                    required
                >

                <label for="password">Password</label>
                <input id="password" name="password" type="password" placeholder="Enter password" required>

                <button class="btn btn-primary btn-block" type="submit">Login</button>
            </form>

            <p class="form-note">New student? <a href="signup.php">Create account</a></p>
            <p class="hint">Default demo: Admin/Admin and studentdemo/Student</p>
        </section>
    </main>
</body>
</html>
