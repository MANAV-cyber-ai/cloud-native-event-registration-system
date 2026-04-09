<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

$flash = consumeFlash();
$formError = null;
$formSuccess = null;

$roleInput = strtoupper(cleanInput($_POST['role'] ?? $_GET['role'] ?? 'CLIENT'));
$role = in_array($roleInput, ['ADMIN', 'CLIENT'], true) ? $roleInput : 'CLIENT';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = cleanInput($_POST['identifier'] ?? '');
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($identifier === '' || $currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $formError = 'Please fill all required fields.';
    } elseif ($newPassword !== $confirmPassword) {
        $formError = 'New password and confirm password do not match.';
    } else {
        $passwordError = validatePasswordStrength($newPassword);
        if ($passwordError !== null) {
            $formError = $passwordError;
        } else {
            try {
                $pdo = getDatabaseConnection();

                $stmt = $pdo->prepare(
                    <<<SQL
                        SELECT auth_user_id, username, password_hash
                        FROM auth_users
                        WHERE (username = :identifier OR email = :identifier)
                          AND role = :role
                        LIMIT 1
                    SQL
                );
                $stmt->execute([
                    'identifier' => $identifier,
                    'role' => $role,
                ]);
                $row = $stmt->fetch();

                if (!$row || !password_verify($currentPassword, (string) $row['password_hash'])) {
                    $formError = 'Current credentials are incorrect.';
                } else {
                    $pdo->beginTransaction();

                    $updateStmt = $pdo->prepare(
                        'UPDATE auth_users SET password_hash = :password_hash WHERE auth_user_id = :auth_user_id'
                    );
                    $updateStmt->execute([
                        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                        'auth_user_id' => (int) $row['auth_user_id'],
                    ]);

                    $logStmt = $pdo->prepare(
                        <<<SQL
                            INSERT INTO auth_password_reset_log (
                                auth_user_id,
                                reset_mode,
                                reset_by,
                                reset_note
                            )
                            VALUES (
                                :auth_user_id,
                                'SELF_SERVICE',
                                :reset_by,
                                :reset_note
                            )
                        SQL
                    );
                    $logStmt->execute([
                        'auth_user_id' => (int) $row['auth_user_id'],
                        'reset_by' => (string) $row['username'],
                        'reset_note' => 'Password changed using current credential check.',
                    ]);

                    $pdo->commit();
                    $formSuccess = 'Password updated successfully.';
                }
            } catch (Throwable $exception) {
                if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $formError = 'Unable to change password right now. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>
    <div class="bg-shape shape-a" aria-hidden="true"></div>
    <div class="bg-shape shape-b" aria-hidden="true"></div>

    <main class="form-shell">
        <section class="form-card">
            <div class="top-row">
                <div>
                    <p class="eyebrow">Security</p>
                    <h1>Change Password</h1>
                </div>
                <a href="index.php">Home</a>
            </div>

            <?php if ($flash !== null): ?>
                <div class="flash <?= e((string) $flash['type']); ?>"><?= e((string) $flash['message']); ?></div>
            <?php endif; ?>

            <?php if ($formSuccess !== null): ?>
                <div class="flash success"><?= e($formSuccess); ?></div>
            <?php endif; ?>

            <?php if ($formError !== null): ?>
                <div class="flash error"><?= e($formError); ?></div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <div class="form-grid">
                    <label class="full">
                        Role *
                        <select name="role" required>
                            <option value="CLIENT" <?= $role === 'CLIENT' ? 'selected' : ''; ?>>CLIENT</option>
                            <option value="ADMIN" <?= $role === 'ADMIN' ? 'selected' : ''; ?>>ADMIN</option>
                        </select>
                    </label>

                    <label class="full">
                        Username or Email *
                        <input type="text" name="identifier" maxlength="120" required>
                    </label>

                    <label class="full">
                        Current Password *
                        <input type="password" name="current_password" required>
                    </label>

                    <label>
                        New Password *
                        <input type="password" name="new_password" required>
                    </label>

                    <label>
                        Confirm New Password *
                        <input type="password" name="confirm_password" required>
                    </label>
                </div>

                <div class="btn-row">
                    <button class="btn primary" type="submit">Update Password</button>
                </div>
            </form>

            <div class="meta-links">
                <a href="login.php">Credential Check</a>
                <a href="forgot-password.php">Forgot Password</a>
            </div>
        </section>
    </main>
</body>
</html>