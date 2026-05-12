<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/db/service-db-read.php';
require_once __DIR__ . '/../includes/db/chapel-schedule-db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

try {
    $schoolYear = trim((string) ($_POST['school_year'] ?? ''));
    $confirmation = trim((string) ($_POST['confirmation'] ?? ''));

    if (!preg_match('/^\d{2}-\d{2}$/', $schoolYear)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Choose a valid school year.']);
        exit;
    }

    if ($confirmation !== 'DELETE') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Type DELETE to remove this school year.']);
        exit;
    }

    $deletedCount = oflc_chapel_schedule_db_delete_school_year($pdo, $schoolYear);
    $rows = oflc_chapel_schedule_db_fetch_rows($pdo, '', 'asc');
    $lastSchoolYear = '';
    $lastSchoolYearDates = [];

    foreach ($rows as $row) {
        $rowSchoolYear = trim((string) ($row['school_year'] ?? ''));
        if ($rowSchoolYear !== '' && ($lastSchoolYear === '' || $rowSchoolYear > $lastSchoolYear)) {
            $lastSchoolYear = $rowSchoolYear;
        }
    }

    if ($lastSchoolYear !== '') {
        foreach ($rows as $row) {
            $rowDate = trim((string) ($row['date'] ?? ''));
            if ((string) ($row['school_year'] ?? '') === $lastSchoolYear && $rowDate !== '') {
                $lastSchoolYearDates[] = $rowDate;
            }
        }
    }

    sort($lastSchoolYearDates, SORT_STRING);
    $nextStartSuggestion = '';
    $nextEndSuggestion = '';
    $firstDateObject = isset($lastSchoolYearDates[0]) ? DateTimeImmutable::createFromFormat('Y-m-d', $lastSchoolYearDates[0]) : null;
    $lastDateObject = $lastSchoolYearDates !== [] ? DateTimeImmutable::createFromFormat('Y-m-d', $lastSchoolYearDates[count($lastSchoolYearDates) - 1]) : null;
    if ($firstDateObject instanceof DateTimeImmutable) {
        $nextStartSuggestion = $firstDateObject->modify('+1 year')->format('Y-m-d');
    }
    if ($lastDateObject instanceof DateTimeImmutable) {
        $nextEndSuggestion = $lastDateObject->modify('+1 year')->format('Y-m-d');
    }

    echo json_encode([
        'success' => true,
        'school_year' => $schoolYear,
        'deleted_count' => $deletedCount,
        'next_start_suggestion' => $nextStartSuggestion,
        'next_end_suggestion' => $nextEndSuggestion,
        'message' => 'School year removed.',
    ]);
} catch (Throwable $e) {
    error_log('Chapel school year delete failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to remove school year: ' . $e->getMessage()]);
}
