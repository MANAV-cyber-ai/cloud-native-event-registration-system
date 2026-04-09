<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

$flash = consumeFlash();
$formError = null;
$formSuccess = null;

$formData = [
    'full_name' => '',
    'username' => '',
    'email' => '',
    'phone' => '',
    'department_id' => '',
    'academic_year' => '',
    'gender' => 'Prefer not to say',
    'university_roll_no' => '',
    'emergency_contact' => '',
    'date_of_birth' => '',
    'address_line' => '',
    'city' => '',
    'state' => '',
    'postal_code' => '',
    'guardian_name' => '',
    'skills' => '',
    'bio' => '',
    'linkedin_url' => '',
    'github_url' => '',
];

$allowedYears = ['1st Year', '2nd Year', '3rd Year', '4th Year', 'Postgraduate'];
$allowedGender = ['Prefer not to say', 'Female', 'Male', 'Other'];
$departments = [];

if (isAuthenticated()) {
    if (hasRole('ADMIN')) {
        redirectTo('admin.php');
    }
    redirectTo('student-dashboard.php');
}

try {
    $pdo = getDatabaseConnection();
    ensureAuthUsersTable($pdo);
    ensureStudentProfileColumns($pdo);

    $departments = $pdo->query(
        <<<SQL
            SELECT department_id, department_name, department_code
            FROM departments
            WHERE is_active = 1
            ORDER BY department_name ASC
        SQL
    )->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach ($formData as $key => $value) {
            $formData[$key] = cleanInput($_POST[$key] ?? '');
        }

        $password = (string) ($_POST['password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        $email = filter_var(strtolower($formData['email']), FILTER_VALIDATE_EMAIL) ?: '';
        $departmentId = filter_var($formData['department_id'], FILTER_VALIDATE_INT);

        if (
            $formData['full_name'] === '' ||
            $formData['username'] === '' ||
            $email === '' ||
            $password === '' ||
            $confirmPassword === '' ||
            $formData['phone'] === '' ||
            $departmentId === false ||
            $formData['academic_year'] === '' ||
            $formData['gender'] === '' ||
            $formData['university_roll_no'] === '' ||
            $formData['emergency_contact'] === '' ||
            $formData['date_of_birth'] === '' ||
            $formData['address_line'] === '' ||
            $formData['city'] === '' ||
            $formData['state'] === '' ||
            $formData['postal_code'] === '' ||
            $formData['guardian_name'] === '' ||
            $formData['skills'] === '' ||
            $formData['bio'] === ''
        ) {
            throw new RuntimeException('Please fill all required fields.');
        }

        if (!in_array($formData['academic_year'], $allowedYears, true)) {
            throw new RuntimeException('Please select a valid academic year.');
        }

        if (!in_array($formData['gender'], $allowedGender, true)) {
            throw new RuntimeException('Please select a valid gender option.');
        }

        if (!preg_match('/^[0-9]{10,15}$/', $formData['phone'])) {
            throw new RuntimeException('Phone number should contain 10 to 15 digits only.');
        }

        if (!preg_match('/^[0-9]{10,15}$/', $formData['emergency_contact'])) {
            throw new RuntimeException('Emergency contact should contain 10 to 15 digits only.');
        }

        if (!preg_match('/^[0-9A-Za-z\-\/]{4,40}$/', $formData['university_roll_no'])) {
            throw new RuntimeException('University roll number should be 4 to 40 characters (letters, numbers, -, /).');
        }

        if (!preg_match('/^[0-9A-Za-z\- ]{4,20}$/', $formData['postal_code'])) {
            throw new RuntimeException('Postal code is invalid.');
        }

        if ($password !== $confirmPassword) {
            throw new RuntimeException('Password and confirm password do not match.');
        }

        $passwordError = validatePasswordStrength($password);
        if ($passwordError !== null) {
            throw new RuntimeException($passwordError);
        }

        if ($formData['linkedin_url'] !== '' && filter_var($formData['linkedin_url'], FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('LinkedIn URL is not valid.');
        }

        if ($formData['github_url'] !== '' && filter_var($formData['github_url'], FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('GitHub URL is not valid.');
        }

        $birthTimestamp = strtotime($formData['date_of_birth']);
        if ($birthTimestamp === false || $birthTimestamp > strtotime('-15 years')) {
            throw new RuntimeException('Please enter a valid date of birth (at least 15 years old).');
        }

        $pdo->beginTransaction();

        $userInsertStmt = $pdo->prepare(
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
                    'CLIENT',
                    :username,
                    :display_name,
                    :email,
                    :password_hash,
                    1
                )
            SQL
        );

        $userInsertStmt->execute([
            'username' => $formData['username'],
            'display_name' => $formData['full_name'],
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        $studentInsertStmt = $pdo->prepare(
            <<<SQL
                INSERT INTO students (
                    full_name,
                    email,
                    phone,
                    department_id,
                    academic_year,
                    gender,
                    university_roll_no,
                    emergency_contact,
                    date_of_birth,
                    address_line,
                    city,
                    state,
                    postal_code,
                    guardian_name,
                    linkedin_url,
                    github_url,
                    skills,
                    bio
                )
                VALUES (
                    :full_name,
                    :email,
                    :phone,
                    :department_id,
                    :academic_year,
                    :gender,
                    :university_roll_no,
                    :emergency_contact,
                    :date_of_birth,
                    :address_line,
                    :city,
                    :state,
                    :postal_code,
                    :guardian_name,
                    :linkedin_url,
                    :github_url,
                    :skills,
                    :bio
                )
            SQL
        );

        $studentInsertStmt->execute([
            'full_name' => $formData['full_name'],
            'email' => $email,
            'phone' => $formData['phone'],
            'department_id' => $departmentId,
            'academic_year' => $formData['academic_year'],
            'gender' => $formData['gender'],
            'university_roll_no' => $formData['university_roll_no'],
            'emergency_contact' => $formData['emergency_contact'],
            'date_of_birth' => $formData['date_of_birth'],
            'address_line' => $formData['address_line'],
            'city' => $formData['city'],
            'state' => $formData['state'],
            'postal_code' => $formData['postal_code'],
            'guardian_name' => $formData['guardian_name'],
            'linkedin_url' => $formData['linkedin_url'] !== '' ? $formData['linkedin_url'] : null,
            'github_url' => $formData['github_url'] !== '' ? $formData['github_url'] : null,
            'skills' => $formData['skills'],
            'bio' => $formData['bio'],
        ]);

        $pdo->commit();

        setFlash('success', 'Account created successfully. Please login.');
        redirectTo('login.php');
    }
} catch (RuntimeException $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $formError = $exception->getMessage();
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ((string) $exception->getCode() === '23000') {
        $formError = 'Username, email, or roll number already exists. Please use unique values.';
    } else {
        $formError = 'Could not create account right now. Please try again.';
    }
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $formError = 'Server error while creating account. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Signup | College Event Portal</title>
    <link rel="stylesheet" href="assets/css/site.css">
</head>
<body>
    <header class="topbar glass">
        <div class="container topbar-inner">
            <a href="index.php" class="logo">College Event Portal</a>
            <nav class="nav-links" aria-label="Main navigation">
                <a href="index.php">Home</a>
                <a href="login.php">Login</a>
                <a href="signup.php" class="active">Create Account</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <section class="feature-shell">
            <div class="feature-head">
                <h1>Create Student Account</h1>
                <p>Complete your profile once. After login, you can register for events quickly.</p>
            </div>

            <?php if ($flash !== null): ?>
                <div class="alert <?= e((string) $flash['type']); ?>"><?= e((string) $flash['message']); ?></div>
            <?php endif; ?>
            <?php if ($formError !== null): ?>
                <div class="alert error"><?= e($formError); ?></div>
            <?php endif; ?>
            <?php if ($formSuccess !== null): ?>
                <div class="alert success"><?= e($formSuccess); ?></div>
            <?php endif; ?>

            <form method="POST" class="stack-form" novalidate>
                <div class="grid-2">
                    <label>Full Name *
                        <input type="text" name="full_name" maxlength="120" value="<?= e($formData['full_name']); ?>" required>
                    </label>
                    <label>Username *
                        <input type="text" name="username" maxlength="80" value="<?= e($formData['username']); ?>" required>
                    </label>

                    <label>Email *
                        <input type="email" name="email" maxlength="120" value="<?= e($formData['email']); ?>" required>
                    </label>
                    <label>Phone *
                        <input type="tel" name="phone" maxlength="15" value="<?= e($formData['phone']); ?>" required>
                    </label>

                    <label>Password *
                        <input type="password" name="password" required>
                    </label>
                    <label>Confirm Password *
                        <input type="password" name="confirm_password" required>
                    </label>

                    <label>Department *
                        <select name="department_id" required>
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?= e((string) $department['department_id']); ?>" <?= $formData['department_id'] === (string) $department['department_id'] ? 'selected' : ''; ?>>
                                    <?= e((string) $department['department_name']); ?> (<?= e((string) $department['department_code']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Academic Year *
                        <select name="academic_year" required>
                            <option value="">Select Year</option>
                            <?php foreach ($allowedYears as $year): ?>
                                <option value="<?= e($year); ?>" <?= $formData['academic_year'] === $year ? 'selected' : ''; ?>><?= e($year); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>Gender *
                        <select name="gender" required>
                            <?php foreach ($allowedGender as $gender): ?>
                                <option value="<?= e($gender); ?>" <?= $formData['gender'] === $gender ? 'selected' : ''; ?>><?= e($gender); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Date of Birth *
                        <input type="date" name="date_of_birth" value="<?= e($formData['date_of_birth']); ?>" required>
                    </label>

                    <label>University Roll No *
                        <input type="text" name="university_roll_no" maxlength="40" value="<?= e($formData['university_roll_no']); ?>" required>
                    </label>
                    <label>Emergency Contact *
                        <input type="tel" name="emergency_contact" maxlength="15" value="<?= e($formData['emergency_contact']); ?>" required>
                    </label>

                    <label>Guardian Name *
                        <input type="text" name="guardian_name" maxlength="120" value="<?= e($formData['guardian_name']); ?>" required>
                    </label>
                    <label>Postal Code *
                        <input type="text" name="postal_code" maxlength="20" value="<?= e($formData['postal_code']); ?>" required>
                    </label>

                    <label class="full">Address *
                        <input type="text" name="address_line" maxlength="255" value="<?= e($formData['address_line']); ?>" required>
                    </label>

                    <label>City *
                        <input type="text" name="city" maxlength="80" value="<?= e($formData['city']); ?>" required>
                    </label>
                    <label>State *
                        <input type="text" name="state" maxlength="80" value="<?= e($formData['state']); ?>" required>
                    </label>

                    <label>LinkedIn URL
                        <input type="url" name="linkedin_url" maxlength="255" value="<?= e($formData['linkedin_url']); ?>">
                    </label>
                    <label>GitHub URL
                        <input type="url" name="github_url" maxlength="255" value="<?= e($formData['github_url']); ?>">
                    </label>

                    <label class="full">Skills *
                        <input type="text" name="skills" maxlength="255" value="<?= e($formData['skills']); ?>" placeholder="Cloud, Python, Design, Public Speaking" required>
                    </label>

                    <label class="full">Short Bio *
                        <textarea name="bio" rows="3" maxlength="1500" required><?= e($formData['bio']); ?></textarea>
                    </label>
                </div>

                <button class="btn btn-primary" type="submit">Create Account</button>
                <p class="form-note">Already have an account? <a href="login.php">Login</a></p>
            </form>
        </section>
    </main>
</body>
</html>
