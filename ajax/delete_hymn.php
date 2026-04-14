<?php
require_once '../includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('success' => false, 'message' => 'Method not allowed.'));
    exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(array('success' => false, 'message' => 'Select a hymn to delete first.'));
    exit;
}

$stmt = $pdo->prepare("
    DELETE FROM hymn_db
    WHERE id = :id
");

$stmt->execute(array(
    ':id' => $id,
));

echo json_encode(array(
    'success' => true,
    'id' => $id,
));
