<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/liturgical.php';
require_once __DIR__ . '/../includes/db/service-db-read.php';
require_once __DIR__ . '/../includes/db/chapel-schedule-db.php';

$date = trim((string) ($_GET['date'] ?? ''));

echo json_encode(
    oflc_chapel_schedule_db_build_observance_suggestion_payload($pdo, $date),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);
