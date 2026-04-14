<?php
require_once '../includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$hymnTitle = trim($_POST['hymn_title'] ?? '');
$hymnal = trim($_POST['hymnal'] ?? '');
$hymnNumber = trim($_POST['hymn_number'] ?? '');
$hymnTune = trim($_POST['hymn_tune'] ?? '');
$hymnSection = trim($_POST['hymn_section'] ?? '');
$kernliederTarget = $_POST['kernlieder_target'] ?? '0';
$insertUse = isset($_POST['insert_use']) ? 1 : 0;
$isActive = isset($_POST['is_active']) ? 1 : 0;

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
    $pdo->beginTransaction();

    $nextId = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM hymn_db")->fetchColumn();

    $stmt = $pdo->prepare("
        INSERT INTO hymn_db (
            id,
            hymn_title,
            hymnal,
            hymn_number,
            hymn_tune,
            hymn_section,
            kernlieder_target,
            insert_use,
            is_active
        ) VALUES (
            :id,
            :hymn_title,
            :hymnal,
            :hymn_number,
            :hymn_tune,
            :hymn_section,
            :kernlieder_target,
            :insert_use,
            :is_active
        )
    ");

    $stmt->execute([
        ':id' => $nextId,
        ':hymn_title' => $hymnTitle,
        ':hymnal' => $hymnal,
        ':hymn_number' => $hymnNumber,
        ':hymn_tune' => $hymnTune,
        ':hymn_section' => $hymnSection,
        ':kernlieder_target' => $kernliederTarget,
        ':insert_use' => $insertUse,
        ':is_active' => $isActive,
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'id' => $nextId,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $message = 'Failed to add hymn.';

    if ($e->getCode() === '23000') {
        $message = 'That hymnal and hymn number already exist.';
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $message,
    ]);
}
