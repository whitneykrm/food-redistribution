<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

session_start();
require_once '../includes/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'loggedIn' => false, 'message' => 'Not authenticated']);
    exit();
}

try {
    // Query database for up-to-date user profile data
    $stmt = $pdo->prepare("SELECT id, name, email, phone_number, role, created_at FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    $vendorData = null;
    if ($user['role'] === 'vendor') {
        $vStmt = $pdo->prepare("SELECT business_name, city, address, verification_status FROM vendors WHERE user_id = :user_id LIMIT 1");
        $vStmt->execute(['user_id' => $user['id']]);
        $vendorData = $vStmt->fetch() ?: null;
    }

    echo json_encode([
        'success'  => true,
        'loggedIn' => true,
        'data'     => [
            'id'     => $user['id'],
            'name'   => $user['name'],
            'email'  => $user['email'],
            'phone'  => $user['phone_number'],
            'role'   => $user['role'],
            'vendor' => $vendorData
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to fetch user data.']);
}