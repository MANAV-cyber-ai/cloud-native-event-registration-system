<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

function appBaseUrl(): string
{
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $trimmed = trim($scriptName, '/');

    if ($trimmed === '') {
        return '';
    }

    $parts = explode('/', $trimmed);
    return '/' . $parts[0];
}

function urlFor(string $path): string
{
    $normalized = '/' . ltrim($path, '/');
    return appBaseUrl() . $normalized;
}

function redirectTo(string $path): never
{
    header('Location: ' . urlFor($path));
    exit;
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function consumeFlash(): ?array
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function currentAuthUser(): ?array
{
    if (!isset($_SESSION['auth_user']) || !is_array($_SESSION['auth_user'])) {
        return null;
    }

    return $_SESSION['auth_user'];
}

function isAuthenticated(): bool
{
    return currentAuthUser() !== null;
}

function hasRole(string $role): bool
{
    $user = currentAuthUser();
    return $user !== null && strtoupper((string) ($user['role'] ?? '')) === strtoupper($role);
}

function loginUser(array $userRow): void
{
    session_regenerate_id(true);
    $_SESSION['auth_user'] = [
        'auth_user_id' => (int) $userRow['auth_user_id'],
        'role' => (string) $userRow['role'],
        'username' => (string) $userRow['username'],
        'display_name' => (string) $userRow['display_name'],
        'email' => (string) $userRow['email'],
    ];
}

function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }
    session_destroy();
}

function requireLogin(?string $role = null): void
{
    if (!isAuthenticated()) {
        setFlash('error', 'Please login to continue.');
        redirectTo('login.php');
    }

    if ($role !== null && !hasRole($role)) {
        setFlash('error', 'You are not authorized to access that page.');
        redirectTo('login.php');
    }
}

function validatePasswordStrength(string $password): ?string
{
    if (strlen($password) < 5) {
        return 'Password must be at least 5 characters long.';
    }

    return null;
}

function ensureAuthUsersTable(PDO $pdo): void
{
    $pdo->exec(
        <<<SQL
            CREATE TABLE IF NOT EXISTS auth_users (
                auth_user_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                role ENUM('ADMIN', 'CLIENT') NOT NULL,
                username VARCHAR(80) NOT NULL,
                display_name VARCHAR(120) NOT NULL,
                email VARCHAR(120) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                last_login_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT uq_auth_users_username UNIQUE (username),
                CONSTRAINT uq_auth_users_email UNIQUE (email),
                INDEX idx_auth_users_role_active (role, is_active)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        SQL
    );
}

function ensureDefaultDemoUsers(PDO $pdo): void
{
    ensureAuthUsersTable($pdo);

    upsertDefaultUser(
        $pdo,
        'ADMIN',
        'Admin',
        'System Administrator',
        'admin@college.edu',
        'Admin'
    );

    upsertDefaultUser(
        $pdo,
        'CLIENT',
        'studentdemo',
        'Student Demo',
        'studentdemo@college.edu',
        'Student'
    );
}

function upsertDefaultUser(
    PDO $pdo,
    string $role,
    string $username,
    string $displayName,
    string $email,
    string $plainPassword
): void {
    $findStmt = $pdo->prepare(
        <<<SQL
            SELECT auth_user_id, password_hash
            FROM auth_users
            WHERE username = :username
            LIMIT 1
        SQL
    );
    $findStmt->execute([
        'username' => $username,
    ]);
    $existingUser = $findStmt->fetch();

    $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);

    if (!$existingUser) {
        $insertStmt = $pdo->prepare(
            <<<SQL
                INSERT INTO auth_users (
                    role,
                    username,
                    display_name,
                    email,
                    password_hash,
                    is_active
                )
                VALUES (
                    :role,
                    :username,
                    :display_name,
                    :email,
                    :password_hash,
                    1
                )
            SQL
        );
        $insertStmt->execute([
            'role' => $role,
            'username' => $username,
            'display_name' => $displayName,
            'email' => $email,
            'password_hash' => $passwordHash,
        ]);
        return;
    }

    $currentHash = (string) ($existingUser['password_hash'] ?? '');

    if (!password_verify($plainPassword, $currentHash)) {
        $updateStmt = $pdo->prepare(
            <<<SQL
                UPDATE auth_users
                SET
                    role = :role,
                    display_name = :display_name,
                    email = :email,
                    password_hash = :password_hash,
                    is_active = 1
                WHERE auth_user_id = :auth_user_id
            SQL
        );
        $updateStmt->execute([
            'role' => $role,
            'display_name' => $displayName,
            'email' => $email,
            'password_hash' => $passwordHash,
            'auth_user_id' => (int) $existingUser['auth_user_id'],
        ]);
    }
}

function ensureStudentProfileColumns(PDO $pdo): void
{
    $columns = [
        'date_of_birth' => 'DATE NULL',
        'address_line' => 'VARCHAR(255) NULL',
        'city' => 'VARCHAR(80) NULL',
        'state' => 'VARCHAR(80) NULL',
        'postal_code' => 'VARCHAR(20) NULL',
        'guardian_name' => 'VARCHAR(120) NULL',
        'linkedin_url' => 'VARCHAR(255) NULL',
        'github_url' => 'VARCHAR(255) NULL',
        'skills' => 'VARCHAR(255) NULL',
        'bio' => 'TEXT NULL',
    ];

    foreach ($columns as $columnName => $definition) {
        $columnStmt = $pdo->prepare(
            <<<SQL
                SELECT COUNT(*)
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'students'
                  AND COLUMN_NAME = :column_name
            SQL
        );
        $columnStmt->execute([
            'column_name' => $columnName,
        ]);

        if ((int) $columnStmt->fetchColumn() === 0) {
            $pdo->exec(sprintf('ALTER TABLE students ADD COLUMN %s %s', $columnName, $definition));
        }
    }
}

function findStudentByEmail(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare(
        <<<SQL
            SELECT *
            FROM students
            WHERE LOWER(email) = LOWER(:email)
            LIMIT 1
        SQL
    );
    $stmt->execute([
        'email' => $email,
    ]);

    $row = $stmt->fetch();
    return $row ?: null;
}
