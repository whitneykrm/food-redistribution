<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

session_start();
require_once '../includes/db.php';

$input = json_decode(file_get_contents('php://input'), true);

$email    = filter_var(trim($input['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$password = $input['password'] ?? '';
$userRole = trim($input['userRole'] ?? '');

if (!$email || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please provide a valid email and password.']);
    exit();
}

try {
    // 1. Query user by email directly
    $stmt = $pdo->prepare("SELECT id, name, email, password_hash, role FROM users WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Verify account and password
    if ($user && password_verify($password, $user['password_hash'])) {
        
        // Optional role lock verification
        if (!empty($userRole) && $user['role'] !== $userRole) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Account role does not match selected role.']);
            exit();
        }

        // 3. Set Session parameters
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['role']      = $user['role'];

        // 4. Dynamic Redirection Logic
        $redirectUrl = 'foodlisting.html';

        if ($user['role'] === 'vendor') {
            // Check if vendor has completed profile setup in vendors table
            $vStmt = $pdo->prepare("SELECT business_name FROM vendors WHERE user_id = :user_id LIMIT 1");
            $vStmt->execute(['user_id' => $user['id']]);
            $vendor = $vStmt->fetch(PDO::FETCH_ASSOC);

            // Send to onboarding setup if missing business details, else go to dashboard
            $redirectUrl = (empty($vendor) || empty($vendor['business_name'])) 
                ? 'vendor-details.html' 
                : 'vendor-dashboard.html';
        } elseif ($user['role'] === 'admin') {
            $redirectUrl = 'admin-dashboard.html';
        }

        echo json_encode([
            'success'     => true,
            'message'     => 'Login successful.',
            'redirectUrl' => $redirectUrl,
            'data'        => [
                'user_id' => $user['id'],
                'name'    => $user['name'],
                'role'    => $user['role']
            ]
        ]);
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred.']);
}