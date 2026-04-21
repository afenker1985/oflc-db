<?php
require_once '../includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$firstName = trim($_POST['first_name'] ?? '');
$lastName = trim($_POST['last_name'] ?? '');

if ($firstName === '' || $lastName === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'First name and last name are required.']);
    exit;
}

try {
    $statement = $pdo->prepare(
        'INSERT INTO leaders (
            first_name,
            last_name,
            is_active
        ) VALUES (
            :first_name,
            :last_name,
            1
        )'
    );

    $statement->execute([
        ':first_name' => $firstName,
        ':last_name' => $lastName,
    ]);

    echo json_encode([
        'success' => true,
        'leader' => [
            'id' => (int) $pdo->lastInsertId(),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'is_active' => 1,
        ],
    ]);
} catch (PDOException $exception) {
    $message = 'Failed to add leader.';

    if ($exception->getCode() === '23000') {
        $message = 'That leader already exists.';
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $message,
    ]);
}
