<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Method not allowed. Use GET.', [], 405);
}

try {
    $pdo = getDatabaseConnection();

    $departmentsStmt = $pdo->query(
        <<<SQL
            SELECT
                department_id,
                department_code,
                department_name
            FROM departments
            WHERE is_active = 1
            ORDER BY department_name ASC
        SQL
    );
    $departments = $departmentsStmt->fetchAll();

    $academicYears = ['1st Year', '2nd Year', '3rd Year', '4th Year', 'Postgraduate'];

    jsonResponse(
        true,
        'Form metadata fetched successfully.',
        [
            'departments' => $departments,
            'academic_years' => $academicYears,
        ]
    );
} catch (Throwable $exception) {
    jsonResponse(false, 'Unable to fetch form metadata.', [], 500);
}

