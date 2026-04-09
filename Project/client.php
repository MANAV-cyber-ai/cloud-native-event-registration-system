<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
$flash = consumeFlash();

function generateRegistrationNumberForPage(PDO $pdo): string
{
    $base = 'ER-' . date('Ymd') . '-';

    for ($attempt = 0; $attempt < 8; $attempt++) {
        $candidate = $base . strtoupper(bin2hex(random_bytes(2)));

        $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM registrations WHERE registration_no = :registration_no');
        $checkStmt->execute(['registration_no' => $candidate]);

        if ((int) $checkStmt->fetchColumn() === 0) {
            return $candidate;
        }
    }

    return $base . strtoupper(substr(hash('sha256', uniqid((string) mt_rand(), true)), 0, 4));
}

function oldInput(string $key, string $fallback = ''): string
{
    if (isset($_POST[$key])) {
        return cleanInput((string) $_POST[$key]);
    }

    return $fallback;
}

$feedbackType = null;
$feedbackMessage = null;

if ($flash !== null) {
    $feedbackType = (string) ($flash['type'] ?? 'success');
    $feedbackMessage = (string) ($flash['message'] ?? '');
}

$departments = [];
$events = [];

$allowedYears = ['1st Year', '2nd Year', '3rd Year', '4th Year', 'Postgraduate'];
$allowedGender = ['Prefer not to say', 'Female', 'Male', 'Other'];

$fullNameDefault = '';
$emailDefault = '';

try {
    $pdo = getDatabaseConnection();

    $departments = $pdo->query(
        <<<SQL
            SELECT
                department_id,
                department_code,
                department_name
            FROM departments
            WHERE is_active = 1
            ORDER BY department_name ASC
        SQL
    )->fetchAll();

    $events = $pdo->query(
        <<<SQL
            SELECT
                e.event_id,
                e.event_code,
                e.event_name,
                ec.category_name AS category,
                e.description,
                v.venue_name,
                v.building_name,
                e.event_mode,
                e.event_date,
                e.start_time,
                e.end_time,
                e.max_capacity,
                e.registration_deadline,
                COALESCE(cap.occupied_slots, 0) AS occupied_slots,
                GREATEST(e.max_capacity - COALESCE(cap.occupied_slots, 0), 0) AS remaining_seats
            FROM events e
            INNER JOIN event_categories ec ON ec.category_id = e.category_id
            INNER JOIN venues v ON v.venue_id = e.venue_id
            LEFT JOIN (
                SELECT
                    event_id,
                    COUNT(*) AS occupied_slots
                FROM registrations
                WHERE current_status IN ('PENDING', 'CONFIRMED')
                GROUP BY event_id
            ) cap ON cap.event_id = e.event_id
            WHERE e.is_active = 1
              AND e.registration_deadline >= CURDATE()
            ORDER BY e.event_date ASC, e.start_time ASC
        SQL
    )->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $fullName = cleanInput($_POST['full_name'] ?? '');
        $emailRaw = cleanInput($_POST['email'] ?? '');
        $email = filter_var(strtolower($emailRaw), FILTER_VALIDATE_EMAIL) ?: '';
        $phone = cleanInput($_POST['phone'] ?? '');
        $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
        $academicYear = cleanInput($_POST['academic_year'] ?? '');
        $gender = cleanInput($_POST['gender'] ?? 'Prefer not to say');
        $rollNo = cleanInput($_POST['university_roll_no'] ?? '');
        $eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
        $emergencyContact = cleanInput($_POST['emergency_contact'] ?? '');
        $priorExperience = cleanInput($_POST['prior_experience'] ?? '');
        $specialRequirements = cleanInput($_POST['special_requirements'] ?? '');
        $consent = cleanInput($_POST['consent'] ?? '');

        if (
            $fullName === '' ||
            $email === '' ||
            $phone === '' ||
            $departmentId === false ||
            $academicYear === '' ||
            $eventId === false ||
            $consent === ''
        ) {
            throw new RuntimeException('Please fill all required fields before submitting.');
        }

        if (!in_array($academicYear, $allowedYears, true)) {
            throw new RuntimeException('Please select a valid academic year.');
        }

        if (!in_array($gender, $allowedGender, true)) {
            throw new RuntimeException('Please select a valid gender option.');
        }

        if (!preg_match('/^[0-9]{10,15}$/', $phone)) {
            throw new RuntimeException('Phone number should contain only digits (10 to 15 characters).');
        }

        if ($emergencyContact !== '' && !preg_match('/^[0-9]{10,15}$/', $emergencyContact)) {
            throw new RuntimeException('Emergency contact should contain only digits (10 to 15 characters).');
        }

        $clientIp = cleanInput($_SERVER['REMOTE_ADDR'] ?? '');
        $userAgent = cleanInput($_SERVER['HTTP_USER_AGENT'] ?? '');

        $pdo->beginTransaction();

        $departmentStmt = $pdo->prepare(
            <<<SQL
                SELECT department_id
                FROM departments
                WHERE department_id = :department_id
                  AND is_active = 1
                LIMIT 1
            SQL
        );
        $departmentStmt->execute(['department_id' => $departmentId]);
        $department = $departmentStmt->fetch();

        if (!$department) {
            throw new RuntimeException('Selected department is not valid.');
        }

        $eventStmt = $pdo->prepare(
            <<<SQL
                SELECT
                    e.event_id,
                    e.event_name,
                    e.max_capacity,
                    e.registration_deadline,
                    e.is_active,
                    ec.category_name,
                    v.venue_name,
                    (
                        SELECT COUNT(*)
                        FROM registrations r
                        WHERE r.event_id = e.event_id
                          AND r.current_status IN ('PENDING', 'CONFIRMED')
                    ) AS occupied_slots
                FROM events e
                INNER JOIN event_categories ec ON ec.category_id = e.category_id
                INNER JOIN venues v ON v.venue_id = e.venue_id
                WHERE e.event_id = :event_id
                LIMIT 1
                FOR UPDATE
            SQL
        );
        $eventStmt->execute(['event_id' => $eventId]);
        $event = $eventStmt->fetch();

        if (!$event) {
            throw new RuntimeException('Selected event was not found.');
        }

        if ((int) $event['is_active'] !== 1) {
            throw new RuntimeException('This event is currently inactive.');
        }

        if ((string) $event['registration_deadline'] < date('Y-m-d')) {
            throw new RuntimeException('Registration deadline for this event has passed.');
        }

        $studentLookupStmt = $pdo->prepare(
            'SELECT student_id FROM students WHERE email = :email LIMIT 1 FOR UPDATE'
        );
        $studentLookupStmt->execute(['email' => $email]);
        $student = $studentLookupStmt->fetch();

        if ($student) {
            $studentId = (int) $student['student_id'];
            $studentUpdateStmt = $pdo->prepare(
                <<<SQL
                    UPDATE students
                    SET
                        full_name = :full_name,
                        phone = :phone,
                        department_id = :department_id,
                        academic_year = :academic_year,
                        gender = :gender,
                        university_roll_no = :university_roll_no,
                        emergency_contact = :emergency_contact
                    WHERE student_id = :student_id
                SQL
            );
            $studentUpdateStmt->execute([
                'full_name' => $fullName,
                'phone' => $phone,
                'department_id' => $departmentId,
                'academic_year' => $academicYear,
                'gender' => $gender,
                'university_roll_no' => $rollNo !== '' ? $rollNo : null,
                'emergency_contact' => $emergencyContact !== '' ? $emergencyContact : null,
                'student_id' => $studentId,
            ]);
        } else {
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
                        emergency_contact
                    )
                    VALUES (
                        :full_name,
                        :email,
                        :phone,
                        :department_id,
                        :academic_year,
                        :gender,
                        :university_roll_no,
                        :emergency_contact
                    )
                SQL
            );
            $studentInsertStmt->execute([
                'full_name' => $fullName,
                'email' => $email,
                'phone' => $phone,
                'department_id' => $departmentId,
                'academic_year' => $academicYear,
                'gender' => $gender,
                'university_roll_no' => $rollNo !== '' ? $rollNo : null,
                'emergency_contact' => $emergencyContact !== '' ? $emergencyContact : null,
            ]);
            $studentId = (int) $pdo->lastInsertId();
        }

        $duplicateCheckStmt = $pdo->prepare(
            <<<SQL
                SELECT registration_no, current_status
                FROM registrations
                WHERE student_id = :student_id
                  AND event_id = :event_id
                LIMIT 1
            SQL
        );
        $duplicateCheckStmt->execute([
            'student_id' => $studentId,
            'event_id' => $eventId,
        ]);
        $duplicateRegistration = $duplicateCheckStmt->fetch();

        if ($duplicateRegistration) {
            throw new RuntimeException(
                sprintf(
                    'You have already registered for this event. Registration No: %s (%s)',
                    $duplicateRegistration['registration_no'],
                    $duplicateRegistration['current_status']
                )
            );
        }

        $occupiedSlots = (int) $event['occupied_slots'];
        $maxCapacity = (int) $event['max_capacity'];
        $status = $occupiedSlots >= $maxCapacity ? 'WAITLISTED' : 'CONFIRMED';

        $registrationNo = generateRegistrationNumberForPage($pdo);

        $registrationInsertStmt = $pdo->prepare(
            <<<SQL
                INSERT INTO registrations (
                    registration_no,
                    student_id,
                    event_id,
                    current_status,
                    attendance_state,
                    source_channel,
                    prior_experience,
                    special_requirements,
                    consent_accepted,
                    ip_address,
                    user_agent
                )
                VALUES (
                    :registration_no,
                    :student_id,
                    :event_id,
                    :current_status,
                    'NOT_MARKED',
                    'web_portal',
                    :prior_experience,
                    :special_requirements,
                    1,
                    :ip_address,
                    :user_agent
                )
            SQL
        );
        $registrationInsertStmt->execute([
            'registration_no' => $registrationNo,
            'student_id' => $studentId,
            'event_id' => $eventId,
            'current_status' => $status,
            'prior_experience' => $priorExperience !== '' ? $priorExperience : null,
            'special_requirements' => $specialRequirements !== '' ? $specialRequirements : null,
            'ip_address' => $clientIp !== '' ? substr($clientIp, 0, 45) : null,
            'user_agent' => $userAgent !== '' ? substr($userAgent, 0, 255) : null,
        ]);
        $registrationId = (int) $pdo->lastInsertId();

        $metadata = json_encode(
            [
                'source_channel' => 'web_portal',
                'status' => $status,
                'event_id' => $eventId,
                'student_id' => $studentId,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $logStmt = $pdo->prepare(
            <<<SQL
                INSERT INTO registration_activity_log (
                    registration_id,
                    action_type,
                    action_note,
                    metadata_json,
                    changed_by
                )
                VALUES (
                    :registration_id,
                    'CREATED',
                    :action_note,
                    :metadata_json,
                    'client_portal_form'
                )
            SQL
        );
        $logStmt->execute([
            'registration_id' => $registrationId,
            'action_note' => 'Registration created via HTML client page.',
            'metadata_json' => $metadata,
        ]);

        $notificationPayload = json_encode(
            [
                'student_name' => $fullName,
                'student_email' => $email,
                'event_name' => $event['event_name'],
                'status' => $status,
                'registration_no' => $registrationNo,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $notificationStmt = $pdo->prepare(
            <<<SQL
                INSERT INTO notification_outbox (
                    registration_id,
                    notification_channel,
                    template_key,
                    payload_json,
                    delivery_status,
                    scheduled_at
                )
                VALUES (
                    :registration_id,
                    'EMAIL',
                    'registration_confirmation',
                    :payload_json,
                    'PENDING',
                    NOW()
                )
            SQL
        );
        $notificationStmt->execute([
            'registration_id' => $registrationId,
            'payload_json' => $notificationPayload,
        ]);

        $pdo->commit();

        if ($status === 'WAITLISTED') {
            $feedbackType = 'success';
            $feedbackMessage = 'Registration submitted. Event is full, you are on waitlist. Registration No: ' . $registrationNo;
        } else {
            $feedbackType = 'success';
            $feedbackMessage = 'Registration successful. Seat confirmed. Registration No: ' . $registrationNo;
        }
    }
} catch (RuntimeException $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $feedbackType = 'error';
    $feedbackMessage = $exception->getMessage();
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $feedbackType = 'error';
    $feedbackMessage = 'Unable to process request right now. Please try again.';
}

$statsEvents = count($events);
$statsSeats = 0;
$statsOpenSeats = 0;

foreach ($events as $eventRow) {
    $statsSeats += (int) $eventRow['max_capacity'];
    $statsOpenSeats += (int) $eventRow['remaining_seats'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Portal - Cloud Event Registration</title>
    <meta name="description" content="Client event registration page with server-side HTML forms.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
    <div class="bg-orb orb-one" aria-hidden="true"></div>
    <div class="bg-orb orb-two" aria-hidden="true"></div>

    <div class="page-shell">
        <header class="top-nav">
            <div class="brand-wrap">
                <div class="brand-badge">U</div>
                <div>
                    <p class="brand-title">Client Event Portal</p>
                    <p class="brand-subtitle">Public Student Registration Page</p>
                </div>
            </div>
            <nav class="top-links">
                <a href="#events">Events</a>
                <a href="#register">Register</a>
                <a href="login.php?role=CLIENT">Credential Check</a>
                <a href="index.php">Home</a>
            </nav>
        </header>

        <main>
            <section class="hero-block">
                <div class="hero-copy">
                    <p class="eyebrow">Student Registration Workspace</p>
                    <h1>Register for upcoming university events from one place.</h1>
                    <p class="hero-text">
                        This client portal is fully HTML/PHP form based and runs directly in browser.
                        No JavaScript is required for registration submission.
                    </p>
                    <div class="pill-row">
                        <span>Client Access</span>
                        <span>PHP + MySQL</span>
                        <span>Server-side Form</span>
                    </div>
                </div>

                <div class="hero-insights">
                    <article class="insight-card">
                        <p class="insight-label">Access Mode</p>
                        <p class="insight-value">Open Access</p>
                        <p class="insight-note">No forced authentication on this page.</p>
                    </article>
                    <article class="insight-card">
                        <p class="insight-label">Validation</p>
                        <p class="insight-value">Server Checked</p>
                        <p class="insight-note">All form data is validated in PHP before saving.</p>
                    </article>
                </div>
            </section>

            <section class="event-block" id="events">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Upcoming Opportunities</p>
                        <h2>Featured Campus Events</h2>
                    </div>
                    <p class="section-copy">
                        Events are server-rendered from database on page load.
                    </p>
                </div>
                <div class="stats-row">
                    <article><span><?= e((string) $statsEvents); ?></span> Active Events</article>
                    <article><span><?= e((string) $statsSeats); ?></span> Total Seats</article>
                    <article><span><?= e((string) $statsOpenSeats); ?></span> Open Seats</article>
                </div>
                <div class="event-grid">
                    <?php if (count($events) === 0): ?>
                        <article class="event-placeholder">No active events found. Please update event dates in database.</article>
                    <?php else: ?>
                        <?php foreach ($events as $event): ?>
                            <?php $venueLabel = $event['building_name'] !== null && $event['building_name'] !== ''
                                ? $event['venue_name'] . ', ' . $event['building_name']
                                : $event['venue_name']; ?>
                            <article class="event-card">
                                <p class="event-name"><?= e($event['event_name']); ?></p>
                                <p class="event-meta"><strong>Category:</strong> <?= e($event['category']); ?> | <strong>Mode:</strong> <?= e($event['event_mode']); ?></p>
                                <p class="event-meta"><strong>Date:</strong> <?= e((string) date('d M Y', strtotime((string) $event['event_date']))); ?> | <?= e((string) date('h:i A', strtotime((string) $event['start_time']))); ?> - <?= e((string) date('h:i A', strtotime((string) $event['end_time']))); ?></p>
                                <p class="event-meta"><strong>Venue:</strong> <?= e((string) $venueLabel); ?></p>
                                <p class="event-meta"><strong>Available Seats:</strong> <?= e((string) $event['remaining_seats']); ?> / <?= e((string) $event['max_capacity']); ?></p>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="registration-block" id="register">
                <article class="register-panel">
                    <div class="section-head">
                        <div>
                            <p class="eyebrow">Student Form</p>
                            <h2>Register in Under 2 Minutes</h2>
                        </div>
                    </div>

                    <?php if ($feedbackMessage !== null && $feedbackMessage !== ''): ?>
                        <div class="message-box show <?= e($feedbackType ?? 'success'); ?>" aria-live="polite"><?= e($feedbackMessage); ?></div>
                    <?php endif; ?>

                    <form class="register-form" method="POST" novalidate>
                        <div class="form-grid">
                            <label>
                                Full Name *
                                <input type="text" name="full_name" maxlength="120" value="<?= e(oldInput('full_name', $fullNameDefault)); ?>" required>
                            </label>

                            <label>
                                University Email *
                                <input type="email" name="email" maxlength="120" value="<?= e(oldInput('email', $emailDefault)); ?>" required>
                            </label>

                            <label>
                                Phone Number *
                                <input type="tel" name="phone" maxlength="15" value="<?= e(oldInput('phone')); ?>" required>
                            </label>

                            <label>
                                Department *
                                <select name="department_id" required>
                                    <option value="">Select department</option>
                                    <?php foreach ($departments as $department): ?>
                                        <option value="<?= e((string) $department['department_id']); ?>" <?= oldInput('department_id') === (string) $department['department_id'] ? 'selected' : ''; ?>>
                                            <?= e($department['department_name']); ?> (<?= e($department['department_code']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label>
                                Academic Year *
                                <select name="academic_year" required>
                                    <option value="">Select year</option>
                                    <?php foreach ($allowedYears as $year): ?>
                                        <option value="<?= e($year); ?>" <?= oldInput('academic_year') === $year ? 'selected' : ''; ?>><?= e($year); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label>
                                Gender
                                <select name="gender">
                                    <?php foreach ($allowedGender as $genderOption): ?>
                                        <option value="<?= e($genderOption); ?>" <?= oldInput('gender', 'Prefer not to say') === $genderOption ? 'selected' : ''; ?>><?= e($genderOption); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label>
                                University Roll Number
                                <input type="text" name="university_roll_no" maxlength="40" value="<?= e(oldInput('university_roll_no')); ?>">
                            </label>

                            <label>
                                Event Name *
                                <select name="event_id" required>
                                    <option value="">Select an event</option>
                                    <?php foreach ($events as $event): ?>
                                        <option value="<?= e((string) $event['event_id']); ?>" <?= oldInput('event_id') === (string) $event['event_id'] ? 'selected' : ''; ?>>
                                            <?= e($event['event_name']); ?> (<?= e($event['event_code']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label>
                                Emergency Contact
                                <input type="tel" name="emergency_contact" maxlength="15" value="<?= e(oldInput('emergency_contact')); ?>">
                            </label>

                            <label class="full-width">
                                Prior Experience
                                <textarea name="prior_experience" rows="3" maxlength="800"><?= e(oldInput('prior_experience')); ?></textarea>
                            </label>

                            <label class="full-width">
                                Special Requirements
                                <textarea name="special_requirements" rows="2" maxlength="500"><?= e(oldInput('special_requirements')); ?></textarea>
                            </label>
                        </div>

                        <label class="checkbox-row">
                            <input type="checkbox" name="consent" value="yes" <?= oldInput('consent') === 'yes' ? 'checked' : ''; ?> required>
                            I confirm that the submitted information is correct and can be used for event coordination.
                        </label>

                        <button type="submit" class="submit-btn">
                            <span class="btn-label">Submit Registration</span>
                        </button>
                    </form>
                </article>

                <aside class="spotlight-panel">
                    <p class="eyebrow">How It Works</p>
                    <h3>HTML/PHP Form Workflow</h3>
                    <p class="spotlight-text">This page works with normal browser form submit:</p>
                    <ul class="spotlight-list">
                        <li><strong>Step 1:</strong> Fill student details</li>
                        <li><strong>Step 2:</strong> Choose event</li>
                        <li><strong>Step 3:</strong> Submit form</li>
                        <li><strong>Step 4:</strong> Server validates and stores data</li>
                        <li><strong>Step 5:</strong> You see success/error message on same page</li>
                    </ul>
                </aside>
            </section>
        </main>

        <footer class="footer">
            <p>Cloud Event Registration Portal | Client Workspace</p>
            <p>Admin users can open <a href="admin.php">Admin Dashboard</a></p>
        </footer>
    </div>
</body>
</html>
