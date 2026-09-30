<?php
header('Content-Type: application/json');
session_start();

require_once '../includes/db.php';

$input = json_decode(file_get_contents('php://input'), true);

$email    = trim($input['email'] ?? '');
$password = $input['password'] ?? '';
$role     = trim($input['userRole'] ?? '');

if (empty($email) || empty($password) || empty($role)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit();
}

try {
    // Query matching role and email
    $stmt = $pdo->prepare("SELECT id, name, password_hash, role FROM users WHERE email = :email AND role = :role LIMIT 1");
    $stmt->execute(['email' => $email, 'role' => $role]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        // Set PHP session parameters
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['role']      = $user['role'];

        $redirectUrl = 'foodlisting.html';
        if ($user['role'] === 'vendor') $redirectUrl = 'vendor-dashboard.html';
        if ($user['role'] === 'admin')  $redirectUrl = 'admin-dashboard.html';

        echo json_encode([
            'success'     => true,
            'message'     => 'Login successful.',
            'redirectUrl' => $redirectUrl,
            'data'        => [
                'user_id' => $user['id'],
                'role'    => $user['role']
            ]
        ]);
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid email, password, or account type.']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred.']);
}