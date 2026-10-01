<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Admin account required.']);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit();
}

$adminId = (int) $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

if ($action === 'verify_vendor') {
    $vendorId = (int) ($input['vendor_id'] ?? 0);

    if ($vendorId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A valid vendor_id is required.']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("UPDATE vendor SET verification_status = 'verified' WHERE vendor_id = :vendor_id");
        $stmt->execute(['vendor_id' => $vendorId]);
        echo json_encode(['success' => true, 'message' => 'Vendor verified.']);
    } catch (PDOException $e) {
        http_response_code(500);
        error_log('Verify vendor error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to verify vendor.']);
    }
    exit();
}

if ($action === 'delete_user') {
    $targetId = (int) ($input['user_id'] ?? 0);

    if ($targetId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A valid user_id is required.']);
        exit();
    }

    if ($targetId === $adminId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "You can't delete your own admin account while logged in."]);
        exit();
    }

    try {
        $stmt = $pdo->prepare('DELETE FROM users WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $targetId]);
        echo json_encode(['success' => true, 'message' => 'User removed.']);
    } catch (PDOException $e) {
        // Likely a foreign-key block: the user still has a vendor, consumer,
        // orders, or payment row referencing them.
        error_log('Delete user error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'This user still has related records (listings, orders, or payments), so it cannot be deleted.',
        ]);
    }
    exit();
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action.']);