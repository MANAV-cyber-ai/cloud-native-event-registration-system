<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
requireLogin('CLIENT');

$currentUser = currentAuthUser();
$stats = ['total' => 0, 'confirmed' => 0, 'waitlisted' => 0, 'upcoming' => 0];
$events = [];
$message = null;

try {
    $pdo = getDatabaseConnection();
    ensureStudentProfileColumns($pdo);

    $student = findStudentByEmail($pdo, (string) ($currentUser['email'] ?? ''));
    if ($student === null) {
        $message = 'Complete your profile first, then register for events.';
    } else {
        $stmt = $pdo->prepare(
            <<<SQL
                SELECT
                    e.event_name,
                    e.event_code,
                    e.event_mode,
                    e.event_date,
                    e.start_time,
                    e.end_time,
                    r.registration_no,
                    r.current_status,
                    r.registered_at
                FROM registrations r
                INNER JOIN events e ON e.event_id = r.event_id
                WHERE r.student_id = :student_id
                ORDER BY e.event_date ASC, e.start_time ASC
            SQL
        );
        $stmt->execute([
            'student_id' => (int) $student['student_id'],
        ]);
        $events = $stmt->fetchAll();

        $stats['total'] = count($events);
        foreach ($events as $event) {
            if ((string) $event['current_status'] === 'CONFIRMED') {
                $stats['confirmed']++;
            }
            if ((string) $event['current_status'] === 'WAITLISTED') {
                $stats['waitlisted']++;
            }
            if ((string) $event['event_date'] >= date('Y-m-d')) {
                $stats['upcoming']++;
            }
        }
    }
} catch (Throwable $exception) {
    $message = 'Unable to load dashboard right now. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard | College Event Portal</title>
    <link rel="stylesheet" href="assets/css/site.css">
</head>
<body>
    <header class="topbar glass">
        <div class="container topbar-inner">
            <a href="index.php" class="logo">College Event Portal</a>
            <nav class="nav-links">
                <a href="student-dashboard.php" class="active">Dashboard</a>
                <a href="register.php">Register Event</a>
                <a href="student-profile.php">My Profile</a>
                <a href="logout.php">Logout</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <section class="feature-shell">
            <div class="feature-head row-between">
                <div>
                    <h1>Welcome, <?= e((string) ($currentUser['display_name'] ?? 'Student')); ?></h1>
                    <p>Track your participation, status, and upcoming event schedule.</p>
                </div>
                <div class="inline-actions">
                    <a href="register.php" class="btn btn-primary">Register New Event</a>
                    <a href="student-profile.php" class="btn btn-ghost">Edit Profile</a>
                </div>
            </div>

            <?php if ($message !== null): ?>
                <div class="alert error"><?= e($message); ?></div>
            <?php endif; ?>

            <div class="stats-grid">
                <article><p>Total Registrations</p><h3><?= e((string) $stats['total']); ?></h3></article>
                <article><p>Confirmed</p><h3><?= e((string) $stats['confirmed']); ?></h3></article>
                <article><p>Waitlisted</p><h3><?= e((string) $stats['waitlisted']); ?></h3></article>
                <article><p>Upcoming Events</p><h3><?= e((string) $stats['upcoming']); ?></h3></article>
            </div>

            <div class="event-card-list">
                <?php if (count($events) === 0): ?>
                    <article class="event-tile empty">No events registered yet. Use "Register New Event" to participate.</article>
                <?php else: ?>
                    <?php foreach ($events as $event): ?>
                        <article class="event-tile">
                            <p class="event-title"><?= e((string) $event['event_name']); ?></p>
                            <p class="event-meta">Code: <?= e((string) $event['event_code']); ?> | Mode: <?= e((string) $event['event_mode']); ?></p>
                            <p class="event-meta">Status: <strong><?= e((string) $event['current_status']); ?></strong> | Reg No: <?= e((string) $event['registration_no']); ?></p>
                            <p class="event-meta">Date: <?= e((string) date('d M Y', strtotime((string) $event['event_date']))); ?> | <?= e((string) date('h:i A', strtotime((string) $event['start_time']))); ?> - <?= e((string) date('h:i A', strtotime((string) $event['end_time']))); ?></p>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>
</body>
</html>
