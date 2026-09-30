<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

session_start();
require_once '../includes/db.php';

// Parse raw JSON request payload
$input = json_decode(file_get_contents('php://input'), true);

$firstName = trim($input['first_name'] ?? '');
$lastName  = trim($input['last_name'] ?? '');
$phone     = trim($input['phone_number'] ?? '');
$email     = filter_var(trim($input['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$password  = $input['password'] ?? '';
$role      = trim($input['role'] ?? '');

// Server-side validation check
if (empty($firstName) || empty($lastName) || empty($phone) || !$email || empty($password) || empty($role)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'All fields are required and must be valid.']);
    exit();
}

if (!in_array($role, ['vendor', 'consumer'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid role selected.']);
    exit();
}

try {
    // 1. Ensure email does not already exist
    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $checkStmt->execute(['email' => $email]);
    if ($checkStmt->fetch()) {
        http_response_code(409); // Conflict
        echo json_encode(['success' => false, 'message' => 'An account with this email already exists.']);
        exit();
    }

    // 2. Hash password securely
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $fullName = $firstName . ' ' . $lastName;

    // 3. Insert record into database
    $insertStmt = $pdo->prepare("
        INSERT INTO users (name, phone_number, email, password_hash, role, created_at)
        VALUES (:name, :phone, :email, :password_hash, :role, NOW())
    ");

    $insertStmt->execute([
        'name'          => $fullName,
        'phone'         => $phone,
        'email'         => $email,
        'password_hash' => $passwordHash,
        'role'          => $role
    ]);

    $newUserId = $pdo->lastInsertId();

    // 4. Automatically initialize user session
    $_SESSION['user_id']   = $newUserId;
    $_SESSION['user_name'] = $fullName;
    $_SESSION['role']      = $role;

    // Define next page based on role
    $redirectUrl = ($role === 'vendor') ? 'vendor-details.html' : 'login.html';

    echo json_encode([
        'success'     => true,
        'message'     => 'Account created successfully!',
        'redirectUrl' => $redirectUrl,
        'data'        => [
            'user_id' => $newUserId,
            'role'    => $role
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred while creating your account.']);
}