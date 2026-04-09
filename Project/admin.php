<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
requireLogin('ADMIN');

$currentUser = currentAuthUser();
$messageType = null;
$messageText = null;

$stats = [
    'students' => 0,
    'events' => 0,
    'registrations' => 0,
    'waitlisted' => 0,
];

$categories = [];
$venues = [];
$events = [];
$registrations = [];

try {
    $pdo = getDatabaseConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = cleanInput($_POST['action'] ?? '');

        if ($action === 'add_event') {
            $eventCode = strtoupper(cleanInput($_POST['event_code'] ?? ''));
            $eventName = cleanInput($_POST['event_name'] ?? '');
            $categoryId = filter_var($_POST['category_id'] ?? '', FILTER_VALIDATE_INT);
            $venueId = filter_var($_POST['venue_id'] ?? '', FILTER_VALIDATE_INT);
            $eventMode = strtoupper(cleanInput($_POST['event_mode'] ?? 'OFFLINE'));
            $eventDate = cleanInput($_POST['event_date'] ?? '');
            $startTime = cleanInput($_POST['start_time'] ?? '');
            $endTime = cleanInput($_POST['end_time'] ?? '');
            $maxCapacity = filter_var($_POST['max_capacity'] ?? '', FILTER_VALIDATE_INT);
            $deadline = cleanInput($_POST['registration_deadline'] ?? '');
            $description = cleanInput($_POST['description'] ?? '');

            if (
                $eventCode === '' || $eventName === '' || $categoryId === false || $venueId === false ||
                $eventDate === '' || $startTime === '' || $endTime === '' || $maxCapacity === false || $deadline === ''
            ) {
                throw new RuntimeException('Please fill all required event fields.');
            }

            if (!in_array($eventMode, ['OFFLINE', 'ONLINE', 'HYBRID'], true)) {
                throw new RuntimeException('Invalid event mode.');
            }

            $insertEvent = $pdo->prepare(
                <<<SQL
                    INSERT INTO events (
                        event_code,
                        event_name,
                        category_id,
                        description,
                        venue_id,
                        event_mode,
                        event_date,
                        start_time,
                        end_time,
                        max_capacity,
                        registration_deadline,
                        is_active
                    )
                    VALUES (
                        :event_code,
                        :event_name,
                        :category_id,
                        :description,
                        :venue_id,
                        :event_mode,
                        :event_date,
                        :start_time,
                        :end_time,
                        :max_capacity,
                        :registration_deadline,
                        1
                    )
                SQL
            );
            $insertEvent->execute([
                'event_code' => $eventCode,
                'event_name' => $eventName,
                'category_id' => $categoryId,
                'description' => $description !== '' ? $description : null,
                'venue_id' => $venueId,
                'event_mode' => $eventMode,
                'event_date' => $eventDate,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'max_capacity' => $maxCapacity,
                'registration_deadline' => $deadline,
            ]);

            $messageType = 'success';
            $messageText = 'Event added successfully.';
        }

        if ($action === 'delete_event') {
            $eventId = filter_var($_POST['event_id'] ?? '', FILTER_VALIDATE_INT);
            if ($eventId === false) {
                throw new RuntimeException('Invalid event ID.');
            }

            $deleteEvent = $pdo->prepare('DELETE FROM events WHERE event_id = :event_id');
            $deleteEvent->execute([
                'event_id' => $eventId,
            ]);

            $messageType = 'success';
            $messageText = 'Event deleted successfully.';
        }

        if ($action === 'delete_registration') {
            $registrationId = filter_var($_POST['registration_id'] ?? '', FILTER_VALIDATE_INT);
            if ($registrationId === false) {
                throw new RuntimeException('Invalid registration ID.');
            }

            $deleteReg = $pdo->prepare('DELETE FROM registrations WHERE registration_id = :registration_id');
            $deleteReg->execute([
                'registration_id' => $registrationId,
            ]);

            $messageType = 'success';
            $messageText = 'Student participation removed successfully.';
        }
    }

    $categories = $pdo->query('SELECT category_id, category_name FROM event_categories WHERE is_active = 1 ORDER BY display_order, category_name')->fetchAll();
    $venues = $pdo->query('SELECT venue_id, venue_name, building_name FROM venues WHERE is_active = 1 ORDER BY venue_name')->fetchAll();

    $statsQuery = $pdo->query(
        <<<SQL
            SELECT
                (SELECT COUNT(*) FROM students) AS students,
                (SELECT COUNT(*) FROM events WHERE is_active = 1) AS events,
                (SELECT COUNT(*) FROM registrations) AS registrations,
                (SELECT COUNT(*) FROM registrations WHERE current_status = 'WAITLISTED') AS waitlisted
        SQL
    );
    $stats = $statsQuery->fetch() ?: $stats;

    $events = $pdo->query(
        <<<SQL
            SELECT
                e.event_id,
                e.event_code,
                e.event_name,
                ec.category_name,
                e.event_mode,
                e.event_date,
                e.max_capacity,
                e.registration_deadline,
                COALESCE(cap.occupied_slots, 0) AS occupied_slots,
                GREATEST(e.max_capacity - COALESCE(cap.occupied_slots, 0), 0) AS remaining_seats
            FROM events e
            INNER JOIN event_categories ec ON ec.category_id = e.category_id
            LEFT JOIN (
                SELECT event_id, COUNT(*) AS occupied_slots
                FROM registrations
                WHERE current_status IN ('PENDING', 'CONFIRMED')
                GROUP BY event_id
            ) cap ON cap.event_id = e.event_id
            ORDER BY e.event_date DESC
        SQL
    )->fetchAll();

    $registrations = $pdo->query(
        <<<SQL
            SELECT
                r.registration_id,
                r.registration_no,
                s.full_name,
                s.email,
                s.phone,
                e.event_name,
                e.event_code,
                r.current_status,
                r.registered_at
            FROM registrations r
            INNER JOIN students s ON s.student_id = r.student_id
            INNER JOIN events e ON e.event_id = r.event_id
            ORDER BY r.registered_at DESC
            LIMIT 600
        SQL
    )->fetchAll();
} catch (RuntimeException $exception) {
    $messageType = 'error';
    $messageText = $exception->getMessage();
} catch (PDOException $exception) {
    if ((string) $exception->getCode() === '23000') {
        $messageType = 'error';
        $messageText = 'Duplicate event code/name or linked records prevent delete. Please verify data.';
    } else {
        $messageType = 'error';
        $messageText = 'Database error occurred while processing admin action.';
    }
} catch (Throwable $exception) {
    $messageType = 'error';
    $messageText = 'Unable to load admin dashboard right now.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Control Center | College Event Portal</title>
    <link rel="stylesheet" href="assets/css/site.css">
</head>
<body>
    <header class="topbar glass">
        <div class="container topbar-inner">
            <a href="index.php" class="logo">College Event Portal</a>
            <nav class="nav-links">
                <a href="admin.php" class="active">Admin Dashboard</a>
                <a href="admin-profile.php">Admin Profile</a>
                <a href="logout.php">Logout</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <section class="feature-shell">
            <div class="feature-head row-between">
                <div>
                    <h1>Admin Control Center</h1>
                    <p>Manage events, review participation, and control registrations.</p>
                </div>
                <p class="chip">Logged in as <?= e((string) ($currentUser['username'] ?? 'Admin')); ?></p>
            </div>

            <?php if ($messageText !== null): ?>
                <div class="alert <?= e((string) $messageType); ?>"><?= e($messageText); ?></div>
            <?php endif; ?>

            <div class="stats-grid">
                <article><p>Total Students</p><h3><?= e((string) $stats['students']); ?></h3></article>
                <article><p>Active Events</p><h3><?= e((string) $stats['events']); ?></h3></article>
                <article><p>Total Registrations</p><h3><?= e((string) $stats['registrations']); ?></h3></article>
                <article><p>Waitlisted</p><h3><?= e((string) $stats['waitlisted']); ?></h3></article>
            </div>

            <section class="panel-soft">
                <h3>Add New Event</h3>
                <form method="POST" class="stack-form" novalidate>
                    <input type="hidden" name="action" value="add_event">
                    <div class="grid-2">
                        <label>Event Code *<input type="text" name="event_code" maxlength="20" required></label>
                        <label>Event Name *<input type="text" name="event_name" maxlength="140" required></label>
                        <label>Category *<select name="category_id" required><option value="">Select</option><?php foreach ($categories as $category): ?><option value="<?= e((string) $category['category_id']); ?>"><?= e((string) $category['category_name']); ?></option><?php endforeach; ?></select></label>
                        <label>Venue *<select name="venue_id" required><option value="">Select</option><?php foreach ($venues as $venue): ?><option value="<?= e((string) $venue['venue_id']); ?>"><?= e((string) $venue['venue_name']); ?><?= $venue['building_name'] ? ' - ' . e((string) $venue['building_name']) : ''; ?></option><?php endforeach; ?></select></label>
                        <label>Mode *<select name="event_mode"><option value="OFFLINE">OFFLINE</option><option value="ONLINE">ONLINE</option><option value="HYBRID">HYBRID</option></select></label>
                        <label>Max Capacity *<input type="number" name="max_capacity" min="1" required></label>
                        <label>Event Date *<input type="date" name="event_date" required></label>
                        <label>Registration Deadline *<input type="date" name="registration_deadline" required></label>
                        <label>Start Time *<input type="time" name="start_time" required></label>
                        <label>End Time *<input type="time" name="end_time" required></label>
                        <label class="full">Description<input type="text" name="description" maxlength="500"></label>
                    </div>
                    <button class="btn btn-primary" type="submit">Add Event</button>
                </form>
            </section>

            <section class="panel-soft">
                <h3>Event List</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Event</th>
                                <th>Category</th>
                                <th>Date</th>
                                <th>Capacity</th>
                                <th>Seats Left</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($events) === 0): ?>
                                <tr><td colspan="7" class="empty-cell">No events found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($events as $event): ?>
                                    <tr>
                                        <td><?= e((string) $event['event_code']); ?></td>
                                        <td><?= e((string) $event['event_name']); ?></td>
                                        <td><?= e((string) $event['category_name']); ?></td>
                                        <td><?= e((string) date('d M Y', strtotime((string) $event['event_date']))); ?></td>
                                        <td><?= e((string) $event['occupied_slots']); ?>/<?= e((string) $event['max_capacity']); ?></td>
                                        <td><?= e((string) $event['remaining_seats']); ?></td>
                                        <td>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="delete_event">
                                                <input type="hidden" name="event_id" value="<?= e((string) $event['event_id']); ?>">
                                                <button class="btn btn-danger" type="submit">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel-soft">
                <h3>Student Participation List</h3>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Reg No</th>
                                <th>Student</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Event</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($registrations) === 0): ?>
                                <tr><td colspan="8" class="empty-cell">No registrations yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($registrations as $registration): ?>
                                    <tr>
                                        <td><?= e((string) $registration['registration_no']); ?></td>
                                        <td><?= e((string) $registration['full_name']); ?></td>
                                        <td><?= e((string) $registration['email']); ?></td>
                                        <td><?= e((string) $registration['phone']); ?></td>
                                        <td><?= e((string) $registration['event_name']); ?> (<?= e((string) $registration['event_code']); ?>)</td>
                                        <td><?= e((string) $registration['current_status']); ?></td>
                                        <td><?= e((string) date('d M Y, h:i A', strtotime((string) $registration['registered_at']))); ?></td>
                                        <td>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="delete_registration">
                                                <input type="hidden" name="registration_id" value="<?= e((string) $registration['registration_id']); ?>">
                                                <button class="btn btn-danger" type="submit">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>
    </main>
</body>
</html>
