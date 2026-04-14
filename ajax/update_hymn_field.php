<?php
require_once '../includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$field = $_POST['field'] ?? '';
$value = $_POST['value'] ?? null;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid hymn id.']);
    exit;
}

$allowedFields = [
    'is_active' => 'checkbox',
    'insert_use' => 'checkbox',
    'kernlieder_target' => 'number',
];

if (!isset($allowedFields[$field])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid hymn field.']);
    exit;
}

if ($allowedFields[$field] === 'checkbox') {
    $value = (int) $value;

    if ($value !== 0 && $value !== 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid checkbox value.']);
        exit;
    }
}

if ($allowedFields[$field] === 'number') {
    if ($value === '' || $value === null || !is_numeric($value)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid Kernlieder value.']);
        exit;
    }

    $value = (int) $value;
}

$stmt = $pdo->prepare("
    UPDATE hymn_db
    SET {$field} = :value
    WHERE id = :id
");

$stmt->execute([
    ':value' => $value,
    ':id' => $id,
]);

echo json_encode([
    'success' => true,
    'id' => $id,
    'field' => $field,
    'value' => $value,
]);
