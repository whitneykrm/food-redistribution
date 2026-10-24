<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

session_start();
$dbPath = __DIR__ . '/../includes/db.php';
if (!file_exists($dbPath)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server misconfiguration.',
        'debug' => "Expected db.php at: $dbPath — adjust the require path in login.php if your folder layout changes.",
    ]);
    exit();
}
require_once $dbPath;

$input = json_decode(file_get_contents('php://input'), true);

$email    = filter_var(trim($input['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$password = $input['password'] ?? '';

if (!$email || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please provide a valid email and password.']);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT user_id, firstname, lastname, email, password, role FROM users WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Verify account and password
    if ($user && password_verify($password, $user['password'])) {

        $storedRole = strtolower($user['role']);

        session_regenerate_id(true);
        $_SESSION['user_id']   = $user['user_id'];
        $_SESSION['firstname'] = $user['firstname'];
        $_SESSION['lastname']  = $user['lastname'];
        $_SESSION['user_name'] = $user['firstname'] . ' ' . $user['lastname'];
        $_SESSION['email']     = $user['email'];
        $_SESSION['role']      = $storedRole;

        $redirectUrl = 'consumer-dashboard.html';

        if ($storedRole === 'vendor') {
        
            $vStmt = $pdo->prepare("SELECT business_name FROM vendor WHERE user_id = :user_id LIMIT 1");
            $vStmt->execute(['user_id' => $user['user_id']]);
            $vendor = $vStmt->fetch(PDO::FETCH_ASSOC);

            $redirectUrl = (empty($vendor) || empty($vendor['business_name']))
                ? 'vendor-details.html'
                : 'vendor-dashboard.html';
        } elseif ($storedRole === 'admin') {
            $redirectUrl = 'admin-dashboard.html';
        }

        echo json_encode([
            'success'     => true,
            'message'     => 'Login successful.',
            'redirectUrl' => $redirectUrl,
            'data'        => [
                'user_id' => $user['user_id'],
                'name'    => $user['firstname'] . ' ' . $user['lastname'],
                'role'    => $storedRole,
            ],
        ]);
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    error_log('Login error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred.',
        'debug'   => $e->getMessage(),
    ]);
}