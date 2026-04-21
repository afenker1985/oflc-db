<?php
require_once '../includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid leader removal.']);
    exit;
}

$statement = $pdo->prepare('DELETE FROM leaders WHERE id = :id');
$statement->execute([
    ':id' => $id,
]);

if ($statement->rowCount() < 1) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Leader not found.']);
    exit;
}

echo json_encode([
    'success' => true,
    'id' => $id,
]);
