<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/db/chapel-schedule-db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

try {
    oflc_chapel_schedule_db_ensure_tables($pdo);

    $chapelScheduleId = (int) ($_POST['id'] ?? 0);
    if ($chapelScheduleId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unable to delete an unsaved chapel week.']);
        exit;
    }

    $schoolYearStmt = $pdo->prepare('SELECT school_year FROM chapel_schedule_db WHERE id = ? AND is_active = 1');
    $schoolYearStmt->execute([$chapelScheduleId]);
    $schoolYear = trim((string) $schoolYearStmt->fetchColumn());

    if ($schoolYear === '') {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Chapel week not found.']);
        exit;
    }

    oflc_chapel_schedule_db_delete_row($pdo, $chapelScheduleId);
    oflc_chapel_schedule_db_renumber_school_year($pdo, $schoolYear);

    echo json_encode([
        'success' => true,
        'school_year' => $schoolYear,
        'message' => 'Chapel week deleted.',
    ]);
} catch (Throwable $e) {
    error_log('Chapel week delete failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to delete chapel week: ' . $e->getMessage()]);
}
