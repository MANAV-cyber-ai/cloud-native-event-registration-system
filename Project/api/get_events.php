<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Method not allowed. Use GET.', [], 405);
}

try {
    $pdo = getDatabaseConnection();

    $query = <<<SQL
        SELECT
            e.event_id,
            e.event_code,
            e.event_name,
            ec.category_name AS category,
            e.description,
            v.venue_name AS venue,
            v.building_name,
            v.campus_zone,
            e.event_mode,
            e.meeting_link,
            e.event_date,
            e.start_time,
            e.end_time,
            e.max_capacity,
            e.registration_deadline,
            COALESCE(cap.occupied_slots, 0) AS occupied_slots,
            GREATEST(e.max_capacity - COALESCE(cap.occupied_slots, 0), 0) AS remaining_seats,
            COALESCE(coord.coordinator_names, 'To Be Announced') AS coordinator_names
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
        LEFT JOIN (
            SELECT
                m.event_id,
                GROUP_CONCAT(c.full_name ORDER BY c.full_name SEPARATOR ', ') AS coordinator_names
            FROM event_coordinator_map m
            INNER JOIN event_coordinators c ON c.coordinator_id = m.coordinator_id
            WHERE c.is_active = 1
            GROUP BY m.event_id
        ) coord ON coord.event_id = e.event_id
        WHERE e.is_active = 1
          AND e.registration_deadline >= CURDATE()
        ORDER BY e.event_date ASC, e.start_time ASC
    SQL;

    $events = $pdo->query($query)->fetchAll();

    jsonResponse(true, 'Active events fetched successfully.', ['events' => $events]);
} catch (Throwable $exception) {
    jsonResponse(false, 'Unable to fetch events at the moment.', [], 500);
}
