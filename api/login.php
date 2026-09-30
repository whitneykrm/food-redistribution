<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

session_start();

// This file lives at api/login.php — one level below the project root — so
// '../includes/db.php' correctly resolves to <root>/includes/db.php.
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
$userRole = strtolower(trim($input['userRole'] ?? ''));

if (!$email || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please provide a valid email and password.']);
    exit();
}

try {
    // 1. Query user by email directly
    $stmt = $pdo->prepare("SELECT user_id, firstname, lastname, email, password, role FROM users WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Verify account and password
    if ($user && password_verify($password, $user['password'])) {

        // Normalize the stored role too, so casing differences in the DB
        // (e.g. 'Vendor' vs 'vendor') never cause a false mismatch.
        $storedRole = strtolower($user['role']);

        // Optional role lock verification
        if (!empty($userRole) && $storedRole !== $userRole) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Account role does not match selected role.']);
            exit();
        }

        // 3. Set session parameters
        $_SESSION['user_id']   = $user['user_id'];
        $_SESSION['firstname'] = $user['firstname'];
        $_SESSION['lastname']  = $user['lastname'];
        $_SESSION['user_name'] = $user['firstname'] . ' ' . $user['lastname'];
        $_SESSION['email']     = $user['email'];
        $_SESSION['role']      = $storedRole;

        // 4. Dynamic redirection logic
        $redirectUrl = 'foodlisting.html';

        if ($storedRole === 'vendor') {
            // vendor.business_name lives in its own table, keyed by user_id —
            // NOT a column on users.
            $vStmt = $pdo->prepare("SELECT business_name FROM vendor WHERE user_id = :user_id LIMIT 1");
            $vStmt->execute(['user_id' => $user['user_id']]);
            $vendor = $vStmt->fetch(PDO::FETCH_ASSOC);

            // Send to onboarding setup if missing business details, else go to dashboard
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
        // TEMPORARY — remove this "debug" key once things work. It leaks
        // real database structure/error info, which is fine on your own
        // machine while debugging but not once this is ever public.
        'debug'   => $e->getMessage(),
    ]);
}