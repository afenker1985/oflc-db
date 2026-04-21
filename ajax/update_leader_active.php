<?php
require_once '../includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$isActive = isset($_POST['is_active']) ? (int) $_POST['is_active'] : null;

if ($id <= 0 || ($isActive !== 0 && $isActive !== 1)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid leader update.']);
    exit;
}

$statement = $pdo->prepare(
    'UPDATE leaders
     SET is_active = :is_active
     WHERE id = :id'
);

$statement->execute([
    ':is_active' => $isActive,
    ':id' => $id,
]);

echo json_encode([
    'success' => true,
    'id' => $id,
    'is_active' => $isActive,
]);
