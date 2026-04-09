<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
requireLogin('CLIENT');

$currentUser = currentAuthUser();
$messageType = null;
$messageText = null;

$allowedYears = ['1st Year', '2nd Year', '3rd Year', '4th Year', 'Postgraduate'];
$allowedGender = ['Prefer not to say', 'Female', 'Male', 'Other'];
$departments = [];
$eventCards = [];

$formData = [
    'full_name' => (string) ($currentUser['display_name'] ?? ''),
    'email' => (string) ($currentUser['email'] ?? ''),
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

try {
    $pdo = getDatabaseConnection();
    ensureStudentProfileColumns($pdo);

    $departments = $pdo->query('SELECT department_id, department_name, department_code FROM departments WHERE is_active = 1 ORDER BY department_name')->fetchAll();

    $student = findStudentByEmail($pdo, (string) $formData['email']);
    if ($student !== null) {
        foreach (array_keys($formData) as $key) {
            if ($key === 'email') {
                continue;
            }
            $formData[$key] = (string) ($student[$key] ?? $formData[$key]);
        }

        $eventStmt = $pdo->prepare(
            <<<SQL
                SELECT
                    e.event_name,
                    e.event_code,
                    e.event_date,
                    e.start_time,
                    e.end_time,
                    e.event_mode,
                    r.registration_no,
                    r.current_status,
                    r.registered_at
                FROM registrations r
                INNER JOIN events e ON e.event_id = r.event_id
                WHERE r.student_id = :student_id
                ORDER BY r.registered_at DESC
            SQL
        );
        $eventStmt->execute(['student_id' => (int) $student['student_id']]);
        $eventCards = $eventStmt->fetchAll();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach ($formData as $key => $value) {
            if ($key === 'email') {
                continue;
            }
            $formData[$key] = cleanInput($_POST[$key] ?? '');
        }

        $departmentId = filter_var($formData['department_id'], FILTER_VALIDATE_INT);
        if (
            $formData['full_name'] === '' || $formData['phone'] === '' || $departmentId === false ||
            $formData['academic_year'] === '' || $formData['gender'] === '' || $formData['university_roll_no'] === '' ||
            $formData['emergency_contact'] === '' || $formData['date_of_birth'] === '' || $formData['address_line'] === '' ||
            $formData['city'] === '' || $formData['state'] === '' || $formData['postal_code'] === '' ||
            $formData['guardian_name'] === '' || $formData['skills'] === '' || $formData['bio'] === ''
        ) {
            throw new RuntimeException('Please fill all required profile fields.');
        }

        if (!in_array($formData['academic_year'], $allowedYears, true) || !in_array($formData['gender'], $allowedGender, true)) {
            throw new RuntimeException('Please select valid academic year and gender.');
        }

        if (!preg_match('/^[0-9]{10,15}$/', $formData['phone']) || !preg_match('/^[0-9]{10,15}$/', $formData['emergency_contact'])) {
            throw new RuntimeException('Phone and emergency contact should contain 10 to 15 digits.');
        }

        $student = findStudentByEmail($pdo, (string) $formData['email']);
        if ($student === null) {
            $insertStmt = $pdo->prepare(
                <<<SQL
                    INSERT INTO students (
                        full_name, email, phone, department_id, academic_year, gender, university_roll_no,
                        emergency_contact, date_of_birth, address_line, city, state, postal_code,
                        guardian_name, linkedin_url, github_url, skills, bio
                    ) VALUES (
                        :full_name, :email, :phone, :department_id, :academic_year, :gender, :university_roll_no,
                        :emergency_contact, :date_of_birth, :address_line, :city, :state, :postal_code,
                        :guardian_name, :linkedin_url, :github_url, :skills, :bio
                    )
                SQL
            );
            $insertStmt->execute([
                'full_name' => $formData['full_name'],
                'email' => $formData['email'],
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
        } else {
            $updateStmt = $pdo->prepare(
                <<<SQL
                    UPDATE students
                    SET
                        full_name = :full_name,
                        phone = :phone,
                        department_id = :department_id,
                        academic_year = :academic_year,
                        gender = :gender,
                        university_roll_no = :university_roll_no,
                        emergency_contact = :emergency_contact,
                        date_of_birth = :date_of_birth,
                        address_line = :address_line,
                        city = :city,
                        state = :state,
                        postal_code = :postal_code,
                        guardian_name = :guardian_name,
                        linkedin_url = :linkedin_url,
                        github_url = :github_url,
                        skills = :skills,
                        bio = :bio
                    WHERE student_id = :student_id
                SQL
            );
            $updateStmt->execute([
                'full_name' => $formData['full_name'],
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
                'student_id' => (int) $student['student_id'],
            ]);
        }

        $nameUpdateStmt = $pdo->prepare('UPDATE auth_users SET display_name = :display_name WHERE auth_user_id = :auth_user_id');
        $nameUpdateStmt->execute([
            'display_name' => $formData['full_name'],
            'auth_user_id' => (int) ($currentUser['auth_user_id'] ?? 0),
        ]);

        $messageType = 'success';
        $messageText = 'Profile updated successfully.';
    }
} catch (RuntimeException $exception) {
    $messageType = 'error';
    $messageText = $exception->getMessage();
} catch (Throwable $exception) {
    $messageType = 'error';
    $messageText = 'Could not load profile right now. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | College Event Portal</title>
    <link rel="stylesheet" href="assets/css/site.css">
</head>
<body>
    <header class="topbar glass">
        <div class="container topbar-inner">
            <a href="index.php" class="logo">College Event Portal</a>
            <nav class="nav-links">
                <a href="student-dashboard.php">Dashboard</a>
                <a href="register.php">Register Event</a>
                <a href="student-profile.php" class="active">My Profile</a>
                <a href="logout.php">Logout</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <section class="feature-shell">
            <div class="feature-head">
                <h1>Student Profile</h1>
                <p>Update your information and review your registered events.</p>
            </div>

            <?php if ($messageText !== null): ?>
                <div class="alert <?= e((string) $messageType); ?>"><?= e($messageText); ?></div>
            <?php endif; ?>

            <div class="content-grid">
                <form method="POST" class="stack-form" novalidate>
                    <div class="grid-2">
                        <label>Full Name *<input type="text" name="full_name" value="<?= e($formData['full_name']); ?>" required></label>
                        <label>Email *<input type="email" name="email" value="<?= e($formData['email']); ?>" readonly></label>
                        <label>Phone *<input type="tel" name="phone" value="<?= e($formData['phone']); ?>" required></label>
                        <label>Department *<select name="department_id" required><option value="">Select</option><?php foreach ($departments as $d): ?><option value="<?= e((string) $d['department_id']); ?>" <?= $formData['department_id'] === (string) $d['department_id'] ? 'selected' : ''; ?>><?= e((string) $d['department_name']); ?></option><?php endforeach; ?></select></label>
                        <label>Academic Year *<select name="academic_year" required><option value="">Select</option><?php foreach ($allowedYears as $y): ?><option value="<?= e($y); ?>" <?= $formData['academic_year'] === $y ? 'selected' : ''; ?>><?= e($y); ?></option><?php endforeach; ?></select></label>
                        <label>Gender *<select name="gender" required><?php foreach ($allowedGender as $g): ?><option value="<?= e($g); ?>" <?= $formData['gender'] === $g ? 'selected' : ''; ?>><?= e($g); ?></option><?php endforeach; ?></select></label>
                        <label>Date of Birth *<input type="date" name="date_of_birth" value="<?= e($formData['date_of_birth']); ?>" required></label>
                        <label>University Roll No *<input type="text" name="university_roll_no" value="<?= e($formData['university_roll_no']); ?>" required></label>
                        <label>Emergency Contact *<input type="tel" name="emergency_contact" value="<?= e($formData['emergency_contact']); ?>" required></label>
                        <label>Guardian Name *<input type="text" name="guardian_name" value="<?= e($formData['guardian_name']); ?>" required></label>
                        <label class="full">Address *<input type="text" name="address_line" value="<?= e($formData['address_line']); ?>" required></label>
                        <label>City *<input type="text" name="city" value="<?= e($formData['city']); ?>" required></label>
                        <label>State *<input type="text" name="state" value="<?= e($formData['state']); ?>" required></label>
                        <label>Postal Code *<input type="text" name="postal_code" value="<?= e($formData['postal_code']); ?>" required></label>
                        <label>Skills *<input type="text" name="skills" value="<?= e($formData['skills']); ?>" required></label>
                        <label>LinkedIn URL<input type="url" name="linkedin_url" value="<?= e($formData['linkedin_url']); ?>"></label>
                        <label>GitHub URL<input type="url" name="github_url" value="<?= e($formData['github_url']); ?>"></label>
                        <label class="full">Bio *<textarea name="bio" rows="3" required><?= e($formData['bio']); ?></textarea></label>
                    </div>
                    <button class="btn btn-primary" type="submit">Save Profile</button>
                </form>

                <aside class="panel-soft">
                    <h3>My Events</h3>
                    <?php if (count($eventCards) === 0): ?>
                        <p>No event registrations yet. <a href="register.php">Register now</a>.</p>
                    <?php else: ?>
                        <div class="event-card-list">
                            <?php foreach ($eventCards as $card): ?>
                                <article class="event-tile">
                                    <p class="event-title"><?= e((string) $card['event_name']); ?></p>
                                    <p class="event-meta">Code: <?= e((string) $card['event_code']); ?> | Status: <strong><?= e((string) $card['current_status']); ?></strong></p>
                                    <p class="event-meta"><?= e((string) date('d M Y', strtotime((string) $card['event_date']))); ?> | <?= e((string) date('h:i A', strtotime((string) $card['start_time']))); ?> - <?= e((string) date('h:i A', strtotime((string) $card['end_time']))); ?></p>
                                    <p class="event-meta">Reg No: <?= e((string) $card['registration_no']); ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </aside>
            </div>
        </section>
    </main>
</body>
</html>
