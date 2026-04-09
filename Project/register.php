<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
requireLogin('CLIENT');

$user = currentAuthUser();
$allowedYears = ['1st Year', '2nd Year', '3rd Year', '4th Year', 'Postgraduate'];
$allowedGender = ['Prefer not to say', 'Female', 'Male', 'Other'];
$shirtSizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];

$departments = [];
$events = [];
$messageType = null;
$messageText = null;

$formData = [
    'full_name' => (string) ($user['display_name'] ?? ''),
    'email' => (string) ($user['email'] ?? ''),
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
    'event_id' => '',
    'motivation_statement' => '',
    'prior_experience' => '',
    'tshirt_size' => 'M',
    'dietary_preferences' => '',
    'accommodation_required' => '0',
    'medical_notes' => '',
    'special_requirements' => '',
];

function ensureRegistrationDetailTable(PDO $pdo): void
{
    $pdo->exec(
        <<<SQL
            CREATE TABLE IF NOT EXISTS registration_form_details (
                detail_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                registration_id BIGINT UNSIGNED NOT NULL,
                motivation_statement TEXT NOT NULL,
                tshirt_size ENUM('XS', 'S', 'M', 'L', 'XL', 'XXL') NOT NULL DEFAULT 'M',
                dietary_preferences VARCHAR(120) NULL,
                accommodation_required TINYINT(1) NOT NULL DEFAULT 0,
                medical_notes TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT uq_registration_form_details_registration UNIQUE (registration_id),
                CONSTRAINT fk_registration_form_details_registration
                    FOREIGN KEY (registration_id) REFERENCES registrations (registration_id)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL
    );
}

function registrationNo(PDO $pdo): string
{
    $base = 'ER-' . date('Ymd') . '-';
    for ($i = 0; $i < 8; $i++) {
        $candidate = $base . strtoupper(bin2hex(random_bytes(2)));
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM registrations WHERE registration_no = :registration_no');
        $stmt->execute(['registration_no' => $candidate]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $candidate;
        }
    }
    return $base . strtoupper(substr(hash('sha256', uniqid((string) mt_rand(), true)), 0, 4));
}

try {
    $pdo = getDatabaseConnection();
    ensureStudentProfileColumns($pdo);
    ensureRegistrationDetailTable($pdo);

    $departments = $pdo->query("SELECT department_id, department_name, department_code FROM departments WHERE is_active = 1 ORDER BY department_name")->fetchAll();
    $events = $pdo->query(
        <<<SQL
            SELECT
                e.event_id,
                e.event_name,
                e.event_code,
                e.event_date,
                e.start_time,
                e.end_time,
                e.max_capacity,
                e.registration_deadline,
                v.venue_name,
                ec.category_name,
                COALESCE(cap.cnt, 0) AS occupied_slots,
                GREATEST(e.max_capacity - COALESCE(cap.cnt, 0), 0) AS remaining_seats
            FROM events e
            INNER JOIN venues v ON v.venue_id = e.venue_id
            INNER JOIN event_categories ec ON ec.category_id = e.category_id
            LEFT JOIN (
                SELECT event_id, COUNT(*) AS cnt
                FROM registrations
                WHERE current_status IN ('PENDING','CONFIRMED')
                GROUP BY event_id
            ) cap ON cap.event_id = e.event_id
            WHERE e.is_active = 1 AND e.registration_deadline >= CURDATE()
            ORDER BY e.event_date, e.start_time
        SQL
    )->fetchAll();

    $student = findStudentByEmail($pdo, (string) ($user['email'] ?? ''));
    if ($student !== null) {
        foreach (['full_name','phone','department_id','academic_year','gender','university_roll_no','emergency_contact','date_of_birth','address_line','city','state','postal_code','guardian_name','skills','bio'] as $field) {
            $formData[$field] = (string) ($student[$field] ?? '');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach ($formData as $field => $value) {
            $formData[$field] = cleanInput($_POST[$field] ?? '');
        }
        $formData['email'] = (string) ($user['email'] ?? '');
        $departmentId = filter_var($formData['department_id'], FILTER_VALIDATE_INT);
        $eventId = filter_var($formData['event_id'], FILTER_VALIDATE_INT);

        if (
            $formData['full_name'] === '' || $formData['phone'] === '' || $departmentId === false ||
            $formData['academic_year'] === '' || $formData['gender'] === '' || $formData['university_roll_no'] === '' ||
            $formData['emergency_contact'] === '' || $formData['date_of_birth'] === '' || $formData['address_line'] === '' ||
            $formData['city'] === '' || $formData['state'] === '' || $formData['postal_code'] === '' ||
            $formData['guardian_name'] === '' || $formData['skills'] === '' || $formData['bio'] === '' ||
            $eventId === false || $formData['motivation_statement'] === '' || $formData['prior_experience'] === '' ||
            $formData['tshirt_size'] === '' || $formData['dietary_preferences'] === ''
        ) {
            throw new RuntimeException('Please fill all mandatory fields.');
        }
        if (!in_array($formData['academic_year'], $allowedYears, true) || !in_array($formData['gender'], $allowedGender, true)) {
            throw new RuntimeException('Please select valid academic year and gender.');
        }
        if (!in_array($formData['tshirt_size'], $shirtSizes, true)) {
            throw new RuntimeException('Please select a valid t-shirt size.');
        }
        if (!preg_match('/^[0-9]{10,15}$/', $formData['phone']) || !preg_match('/^[0-9]{10,15}$/', $formData['emergency_contact'])) {
            throw new RuntimeException('Phone and emergency contact should contain 10 to 15 digits.');
        }

        $pdo->beginTransaction();

        $eventStmt = $pdo->prepare(
            <<<SQL
                SELECT e.event_id, e.event_name, e.max_capacity, e.registration_deadline, e.is_active,
                    (SELECT COUNT(*) FROM registrations r WHERE r.event_id = e.event_id AND r.current_status IN ('PENDING','CONFIRMED')) AS occupied_slots
                FROM events e
                WHERE e.event_id = :event_id
                LIMIT 1
                FOR UPDATE
            SQL
        );
        $eventStmt->execute(['event_id' => $eventId]);
        $eventRow = $eventStmt->fetch();
        if (!$eventRow || (int) $eventRow['is_active'] !== 1 || (string) $eventRow['registration_deadline'] < date('Y-m-d')) {
            throw new RuntimeException('Selected event is not available for registration.');
        }

        $studentRow = findStudentByEmail($pdo, $formData['email']);
        if ($studentRow === null) {
            $insStudent = $pdo->prepare(
                <<<SQL
                    INSERT INTO students (
                        full_name, email, phone, department_id, academic_year, gender, university_roll_no, emergency_contact,
                        date_of_birth, address_line, city, state, postal_code, guardian_name, skills, bio
                    )
                    VALUES (
                        :full_name, :email, :phone, :department_id, :academic_year, :gender, :university_roll_no, :emergency_contact,
                        :date_of_birth, :address_line, :city, :state, :postal_code, :guardian_name, :skills, :bio
                    )
                SQL
            );
            $insStudent->execute([
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
                'skills' => $formData['skills'],
                'bio' => $formData['bio'],
            ]);
            $studentId = (int) $pdo->lastInsertId();
        } else {
            $studentId = (int) $studentRow['student_id'];
            $updStudent = $pdo->prepare(
                <<<SQL
                    UPDATE students
                    SET full_name=:full_name, phone=:phone, department_id=:department_id, academic_year=:academic_year,
                        gender=:gender, university_roll_no=:university_roll_no, emergency_contact=:emergency_contact,
                        date_of_birth=:date_of_birth, address_line=:address_line, city=:city, state=:state,
                        postal_code=:postal_code, guardian_name=:guardian_name, skills=:skills, bio=:bio
                    WHERE student_id=:student_id
                SQL
            );
            $updStudent->execute([
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
                'skills' => $formData['skills'],
                'bio' => $formData['bio'],
                'student_id' => $studentId,
            ]);
        }

        $dupStmt = $pdo->prepare('SELECT registration_no FROM registrations WHERE student_id=:student_id AND event_id=:event_id LIMIT 1');
        $dupStmt->execute(['student_id' => $studentId, 'event_id' => $eventId]);
        if ($dupStmt->fetch()) {
            throw new RuntimeException('You are already registered for this event.');
        }

        $status = ((int) $eventRow['occupied_slots'] >= (int) $eventRow['max_capacity']) ? 'WAITLISTED' : 'CONFIRMED';
        $regNo = registrationNo($pdo);

        $insReg = $pdo->prepare(
            <<<SQL
                INSERT INTO registrations (
                    registration_no, student_id, event_id, current_status, attendance_state, source_channel,
                    prior_experience, special_requirements, consent_accepted, ip_address, user_agent
                )
                VALUES (
                    :registration_no, :student_id, :event_id, :current_status, 'NOT_MARKED', 'web_portal',
                    :prior_experience, :special_requirements, 1, :ip_address, :user_agent
                )
            SQL
        );
        $insReg->execute([
            'registration_no' => $regNo,
            'student_id' => $studentId,
            'event_id' => $eventId,
            'current_status' => $status,
            'prior_experience' => $formData['prior_experience'],
            'special_requirements' => $formData['special_requirements'],
            'ip_address' => cleanInput($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => substr(cleanInput($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
        $registrationId = (int) $pdo->lastInsertId();

        $insDetail = $pdo->prepare(
            <<<SQL
                INSERT INTO registration_form_details (
                    registration_id, motivation_statement, tshirt_size, dietary_preferences, accommodation_required, medical_notes
                )
                VALUES (
                    :registration_id, :motivation_statement, :tshirt_size, :dietary_preferences, :accommodation_required, :medical_notes
                )
            SQL
        );
        $insDetail->execute([
            'registration_id' => $registrationId,
            'motivation_statement' => $formData['motivation_statement'],
            'tshirt_size' => $formData['tshirt_size'],
            'dietary_preferences' => $formData['dietary_preferences'],
            'accommodation_required' => $formData['accommodation_required'] === '1' ? 1 : 0,
            'medical_notes' => $formData['medical_notes'] !== '' ? $formData['medical_notes'] : null,
        ]);

        $pdo->commit();
        $messageType = 'success';
        $messageText = $status === 'WAITLISTED'
            ? 'Registration submitted. You are waitlisted. Registration No: ' . $regNo
            : 'Registration successful. Seat confirmed. Registration No: ' . $regNo;
        $formData['event_id'] = '';
        $formData['motivation_statement'] = '';
        $formData['prior_experience'] = '';
        $formData['dietary_preferences'] = '';
        $formData['special_requirements'] = '';
        $formData['medical_notes'] = '';
    }
} catch (RuntimeException $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $messageType = 'error';
    $messageText = $exception->getMessage();
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $messageType = 'error';
    $messageText = 'Unable to submit registration right now. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Registration | College Event Portal</title>
    <link rel="stylesheet" href="assets/css/site.css">
</head>
<body>
    <header class="topbar glass">
        <div class="container topbar-inner">
            <a href="index.php" class="logo">College Event Portal</a>
            <nav class="nav-links">
                <a href="student-dashboard.php">Dashboard</a>
                <a href="register.php" class="active">Register Event</a>
                <a href="student-profile.php">My Profile</a>
                <a href="logout.php">Logout</a>
            </nav>
        </div>
    </header>
    <main class="container">
        <section class="feature-shell">
            <div class="feature-head">
                <h1>Detailed Event Registration</h1>
                <p>Account is required first. Fill complete details to participate.</p>
            </div>
            <?php if ($messageText !== null): ?>
                <div class="alert <?= e((string) $messageType); ?>"><?= e($messageText); ?></div>
            <?php endif; ?>
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
                    <label class="full">Bio *<textarea name="bio" rows="2" required><?= e($formData['bio']); ?></textarea></label>
                    <label class="full">Event *<select name="event_id" required><option value="">Select Event</option><?php foreach ($events as $event): ?><option value="<?= e((string) $event['event_id']); ?>" <?= $formData['event_id'] === (string) $event['event_id'] ? 'selected' : ''; ?>><?= e((string) $event['event_name']); ?> | Seats: <?= e((string) $event['remaining_seats']); ?></option><?php endforeach; ?></select></label>
                    <label class="full">Motivation Statement *<textarea name="motivation_statement" rows="3" required><?= e($formData['motivation_statement']); ?></textarea></label>
                    <label class="full">Prior Experience *<textarea name="prior_experience" rows="3" required><?= e($formData['prior_experience']); ?></textarea></label>
                    <label>T-Shirt Size *<select name="tshirt_size" required><?php foreach ($shirtSizes as $s): ?><option value="<?= e($s); ?>" <?= $formData['tshirt_size'] === $s ? 'selected' : ''; ?>><?= e($s); ?></option><?php endforeach; ?></select></label>
                    <label>Dietary Preferences *<input type="text" name="dietary_preferences" value="<?= e($formData['dietary_preferences']); ?>" required></label>
                    <label>Accommodation Required *<select name="accommodation_required"><option value="0" <?= $formData['accommodation_required'] !== '1' ? 'selected' : ''; ?>>No</option><option value="1" <?= $formData['accommodation_required'] === '1' ? 'selected' : ''; ?>>Yes</option></select></label>
                    <label>Medical Notes<input type="text" name="medical_notes" value="<?= e($formData['medical_notes']); ?>"></label>
                    <label class="full">Special Requirements<textarea name="special_requirements" rows="2"><?= e($formData['special_requirements']); ?></textarea></label>
                </div>
                <button class="btn btn-primary" type="submit">Submit Registration</button>
            </form>
        </section>
    </main>
</body>
</html>
