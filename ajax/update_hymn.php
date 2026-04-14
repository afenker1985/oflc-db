<?php
require_once '../includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$hymnTitle = trim($_POST['hymn_title'] ?? '');
$hymnal = trim($_POST['hymnal'] ?? '');
$hymnNumber = trim($_POST['hymn_number'] ?? '');
$hymnTune = trim($_POST['hymn_tune'] ?? '');
$hymnSection = trim($_POST['hymn_section'] ?? '');
$kernliederTarget = $_POST['kernlieder_target'] ?? '0';
$insertUse = isset($_POST['insert_use']) ? 1 : 0;
$isActive = isset($_POST['is_active']) ? 1 : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Select a hymn to update first.']);
    exit;
}

if ($hymnTitle === '' || $hymnal === '' || $hymnNumber === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Title, hymnal, and hymn number are required.']);
    exit;
}

if ($kernliederTarget === '' || !is_numeric($kernliederTarget)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Kernlieder must be a number.']);
    exit;
}

$kernliederTarget = (int) $kernliederTarget;
$hymnTune = $hymnTune === '' ? null : $hymnTune;
$hymnSection = $hymnSection === '' ? null : $hymnSection;

try {
    $stmt = $pdo->prepare("
        UPDATE hymn_db
        SET
            hymn_title = :hymn_title,
            hymnal = :hymnal,
            hymn_number = :hymn_number,
            hymn_tune = :hymn_tune,
            hymn_section = :hymn_section,
            kernlieder_target = :kernlieder_target,
            insert_use = :insert_use,
            is_active = :is_active
        WHERE id = :id
    ");

    $stmt->execute([
        ':id' => $id,
        ':hymn_title' => $hymnTitle,
        ':hymnal' => $hymnal,
        ':hymn_number' => $hymnNumber,
        ':hymn_tune' => $hymnTune,
        ':hymn_section' => $hymnSection,
        ':kernlieder_target' => $kernliederTarget,
        ':insert_use' => $insertUse,
        ':is_active' => $isActive,
    ]);

    echo json_encode([
        'success' => true,
        'id' => $id,
    ]);
} catch (PDOException $e) {
    $message = 'Failed to update hymn.';

    if ($e->getCode() === '23000') {
        $message = 'That hymnal and hymn number already exist.';
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $message,
    ]);
}
