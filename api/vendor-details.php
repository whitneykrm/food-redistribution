<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

session_start();
require_once '../includes/db.php';

// Authentication Guard
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'vendor') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Vendor account required.']);
    exit();
}

$userId = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare("SELECT business_name, city, address, verification_status FROM vendors WHERE user_id = :user_id LIMIT 1");
        $stmt->execute(['user_id' => $userId]);
        $vendor = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data'    => $vendor ?: null
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to fetch vendor data.']);
    }
    exit();
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $businessName = trim($input['business_name'] ?? '');
    $city         = trim($input['city'] ?? '');
    $address      = trim($input['address'] ?? '');

    if (empty($businessName) || empty($city) || empty($address)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit();
    }

    try {
        // Upsert logic: insert if profile doesn't exist, update if it does
        $stmt = $pdo->prepare("
            INSERT INTO vendors (user_id, business_name, city, address, verification_status)
            VALUES (:user_id, :business_name, :city, :address, 'pending')
            ON DUPLICATE KEY UPDATE
                business_name = VALUES(business_name),
                city = VALUES(city),
                address = VALUES(address)
        ");

        $stmt->execute([
            'user_id'       => $userId,
            'business_name' => $businessName,
            'city'          => $city,
            'address'       => $address
        ]);

        echo json_encode([
            'success'     => true,
            'message'     => 'Vendor profile updated successfully!',
            'redirectUrl' => 'vendor-dashboard.html'
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error saving vendor profile.']);
    }
    exit();
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);