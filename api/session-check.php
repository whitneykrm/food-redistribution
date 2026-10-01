<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

session_start();
require_once '../includes/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success'  => false,
        'loggedIn' => false,
        'role'     => null,
        'data'     => null,
        'user'     => null,
        'message'  => 'Not authenticated',
    ]);
    exit();
}

try {
    // Real columns are user_id, firstname, lastname — not id/name.
    $stmt = $pdo->prepare('SELECT user_id, firstname, lastname, email, phone_number, role FROM users WHERE user_id = :user_id LIMIT 1');
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Session points at a user_id that no longer exists (e.g. deleted
        // by an admin while the session was still active).
        http_response_code(401);
        echo json_encode([
            'success'  => false,
            'loggedIn' => false,
            'role'     => null,
            'data'     => null,
            'user'     => null,
            'message'  => 'Session is no longer valid.',
        ]);
        exit();
    }

    $vendorData = null;
    if ($user['role'] === 'vendor') {
        // NOTE: table is `vendor` (singular), not `vendors`.
        $vStmt = $pdo->prepare('SELECT vendor_id, business_name, city, address, verification_status FROM vendor WHERE user_id = :user_id LIMIT 1');
        $vStmt->execute(['user_id' => $user['user_id']]);
        $vendorData = $vStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $profile = [
        'user_id'   => (int) $user['user_id'],
        'firstname' => $user['firstname'],
        'lastname'  => $user['lastname'],
        'email'     => $user['email'],
        'phone'     => $user['phone_number'],
        'role'      => $user['role'],
        'vendor'    => $vendorData,
    ];

    echo json_encode([
        'success'  => true,
        'loggedIn' => true,
        'role'     => $user['role'],
        // Both keys point at the same object — vendor-details.html reads
        // `data`, navbar.html reads `user`. Returning both avoids having to
        // keep two frontend files in sync with one backend shape.
        'data'     => $profile,
        'user'     => $profile,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('Session check error: ' . $e->getMessage());
    echo json_encode([
        'success'  => false,
        'loggedIn' => false,
        'role'     => null,
        'data'     => null,
        'user'     => null,
        'message'  => 'Failed to fetch user data.',
    ]);
}