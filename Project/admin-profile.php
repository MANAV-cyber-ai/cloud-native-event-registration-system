<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
requireLogin('ADMIN');

$currentUser = currentAuthUser();
$messageType = null;
$messageText = null;

$formData = [
    'display_name' => (string) ($currentUser['display_name'] ?? ''),
    'email' => (string) ($currentUser['email'] ?? ''),
];

try {
    $pdo = getDatabaseConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $displayName = cleanInput($_POST['display_name'] ?? '');
        $emailRaw = cleanInput($_POST['email'] ?? '');
        $email = filter_var(strtolower($emailRaw), FILTER_VALIDATE_EMAIL) ?: '';
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($displayName === '' || $email === '') {
            throw new RuntimeException('Display name and email are required.');
        }

        if ($newPassword !== '' || $confirmPassword !== '') {
            if ($newPassword !== $confirmPassword) {
                throw new RuntimeException('Password and confirm password do not match.');
            }
            $passwordError = validatePasswordStrength($newPassword);
            if ($passwordError !== null) {
                throw new RuntimeException($passwordError);
            }
        }

        if ($newPassword !== '') {
            $updateStmt = $pdo->prepare(
                'UPDATE auth_users SET display_name = :display_name, email = :email, password_hash = :password_hash WHERE auth_user_id = :auth_user_id'
            );
            $updateStmt->execute([
                'display_name' => $displayName,
                'email' => $email,
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'auth_user_id' => (int) ($currentUser['auth_user_id'] ?? 0),
            ]);
        } else {
            $updateStmt = $pdo->prepare(
                'UPDATE auth_users SET display_name = :display_name, email = :email WHERE auth_user_id = :auth_user_id'
            );
            $updateStmt->execute([
                'display_name' => $displayName,
                'email' => $email,
                'auth_user_id' => (int) ($currentUser['auth_user_id'] ?? 0),
            ]);
        }

        $_SESSION['auth_user']['display_name'] = $displayName;
        $_SESSION['auth_user']['email'] = $email;

        $formData['display_name'] = $displayName;
        $formData['email'] = $email;

        $messageType = 'success';
        $messageText = 'Admin profile updated successfully.';
    }
} catch (RuntimeException $exception) {
    $messageType = 'error';
    $messageText = $exception->getMessage();
} catch (PDOException $exception) {
    if ((string) $exception->getCode() === '23000') {
        $messageType = 'error';
        $messageText = 'This email is already used by another account.';
    } else {
        $messageType = 'error';
        $messageText = 'Unable to update profile right now.';
    }
} catch (Throwable $exception) {
    $messageType = 'error';
    $messageText = 'Server error while updating admin profile.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile | College Event Portal</title>
    <link rel="stylesheet" href="assets/css/site.css">
</head>
<body>
    <header class="topbar glass">
        <div class="container topbar-inner">
            <a href="index.php" class="logo">College Event Portal</a>
            <nav class="nav-links">
                <a href="admin.php">Admin Dashboard</a>
                <a href="admin-profile.php" class="active">Admin Profile</a>
                <a href="logout.php">Logout</a>
            </nav>
        </div>
    </header>

    <main class="container page-center">
        <section class="feature-shell max-w-720">
            <div class="feature-head">
                <h1>Admin Profile</h1>
                <p>Update your admin contact information and password.</p>
            </div>

            <?php if ($messageText !== null): ?>
                <div class="alert <?= e((string) $messageType); ?>"><?= e($messageText); ?></div>
            <?php endif; ?>

            <form method="POST" class="stack-form" novalidate>
                <div class="grid-2">
                    <label>Display Name *
                        <input type="text" name="display_name" maxlength="120" value="<?= e($formData['display_name']); ?>" required>
                    </label>
                    <label>Email *
                        <input type="email" name="email" maxlength="120" value="<?= e($formData['email']); ?>" required>
                    </label>
                    <label>New Password
                        <input type="password" name="new_password">
                    </label>
                    <label>Confirm Password
                        <input type="password" name="confirm_password">
                    </label>
                </div>
                <button class="btn btn-primary" type="submit">Save Admin Profile</button>
            </form>
        </section>
    </main>
</body>
</html>
