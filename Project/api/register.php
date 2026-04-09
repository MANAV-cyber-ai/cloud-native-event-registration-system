<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed. Use POST.', [], 405);
}

function generateRegistrationNumber(PDO $pdo): string
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
$sourceChannel = cleanInput($_POST['source_channel'] ?? 'web_portal');
$consent = cleanInput($_POST['consent'] ?? '');

$allowedYears = ['1st Year', '2nd Year', '3rd Year', '4th Year', 'Postgraduate'];
$allowedGender = ['Prefer not to say', 'Female', 'Male', 'Other'];
$allowedSources = ['web_portal', 'mobile_app', 'admin_panel', 'imported'];

if (
    $fullName === '' ||
    $email === '' ||
    $phone === '' ||
    $departmentId === false ||
    $academicYear === '' ||
    $eventId === false ||
    $consent === ''
) {
    jsonResponse(false, 'Please fill all required fields before submitting.', [], 422);
}

if (!in_array($academicYear, $allowedYears, true)) {
    jsonResponse(false, 'Please select a valid academic year.', [], 422);
}

if (!in_array($gender, $allowedGender, true)) {
    jsonResponse(false, 'Please select a valid gender option.', [], 422);
}

if (!preg_match('/^[0-9]{10,15}$/', $phone)) {
    jsonResponse(false, 'Phone number should contain only digits (10 to 15 characters).', [], 422);
}

if ($emergencyContact !== '' && !preg_match('/^[0-9]{10,15}$/', $emergencyContact)) {
    jsonResponse(false, 'Emergency contact should contain only digits (10 to 15 characters).', [], 422);
}

if (!in_array($sourceChannel, $allowedSources, true)) {
    $sourceChannel = 'web_portal';
}

$clientIp = cleanInput($_SERVER['REMOTE_ADDR'] ?? '');
$userAgent = cleanInput($_SERVER['HTTP_USER_AGENT'] ?? '');

$pdo = null;

try {
    $pdo = getDatabaseConnection();
    $pdo->beginTransaction();

    $departmentStmt = $pdo->prepare(
        <<<SQL
            SELECT department_id, department_name
            FROM departments
            WHERE department_id = :department_id
              AND is_active = 1
            LIMIT 1
        SQL
    );
    $departmentStmt->execute(['department_id' => $departmentId]);
    $department = $departmentStmt->fetch();

    if (!$department) {
        $pdo->rollBack();
        jsonResponse(false, 'Selected department is not valid.', [], 422);
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
                v.venue_name
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
        $pdo->rollBack();
        jsonResponse(false, 'Selected event was not found.', [], 404);
    }

    if ((int) $event['is_active'] !== 1) {
        $pdo->rollBack();
        jsonResponse(false, 'This event is currently inactive.', [], 400);
    }

    if ($event['registration_deadline'] < date('Y-m-d')) {
        $pdo->rollBack();
        jsonResponse(false, 'Registration deadline for this event has passed.', [], 400);
    }

    $occupiedStmt = $pdo->prepare(
        <<<SQL
            SELECT COUNT(*)
            FROM registrations
            WHERE event_id = :event_id
              AND current_status IN ('PENDING', 'CONFIRMED')
        SQL
    );
    $occupiedStmt->execute(['event_id' => $eventId]);
    $occupiedSlots = (int) $occupiedStmt->fetchColumn();

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
        $pdo->rollBack();
        jsonResponse(
            false,
            sprintf(
                'You have already registered for this event. Registration No: %s (%s)',
                $duplicateRegistration['registration_no'],
                $duplicateRegistration['current_status']
            ),
            [],
            409
        );
    }

    $maxCapacity = (int) $event['max_capacity'];
    $status = $occupiedSlots >= $maxCapacity ? 'WAITLISTED' : 'CONFIRMED';

    $registrationNo = generateRegistrationNumber($pdo);

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
                :source_channel,
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
        'source_channel' => $sourceChannel,
        'prior_experience' => $priorExperience !== '' ? $priorExperience : null,
        'special_requirements' => $specialRequirements !== '' ? $specialRequirements : null,
        'ip_address' => $clientIp !== '' ? substr($clientIp, 0, 45) : null,
        'user_agent' => $userAgent !== '' ? substr($userAgent, 0, 255) : null,
    ]);
    $registrationId = (int) $pdo->lastInsertId();

    $metadata = json_encode(
        [
            'source_channel' => $sourceChannel,
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
                :action_type,
                :action_note,
                :metadata_json,
                'public_portal'
            )
        SQL
    );
    $logStmt->execute([
        'registration_id' => $registrationId,
        'action_type' => 'CREATED',
        'action_note' => 'Registration created through public portal.',
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

    $message = $status === 'WAITLISTED'
        ? 'Registration submitted. Event is currently full, so you are on the waitlist.'
        : 'Registration successful. Your seat is confirmed.';

    jsonResponse(true, $message, [
        'registration_no' => $registrationNo,
        'status' => $status,
        'event_name' => $event['event_name'],
        'category' => $event['category_name'],
        'venue' => $event['venue_name'],
    ]);
} catch (PDOException $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ((string) $exception->getCode() === '23000') {
        jsonResponse(
            false,
            'A duplicate record was detected (email/roll number/registration). Please verify your details.',
            [],
            409
        );
    }

    jsonResponse(
        false,
        'We could not complete your registration right now. Please try again.',
        [],
        500
    );
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    jsonResponse(
        false,
        'We could not complete your registration right now. Please try again.',
        [],
        500
    );
}
