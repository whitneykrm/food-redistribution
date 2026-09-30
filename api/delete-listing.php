<?php
header('Content-Type: application/json');
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vendor') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['listing_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid listing ID']);
    exit;
}

try {
    // Retrieve vendor_id using user_id
    $stmt = $pdo->prepare("SELECT vendor_id FROM vendors WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $vendor = $stmt->fetch(PDO::FETCH_ASSOC);

    // Delete listing belonging to this vendor
    $stmt = $pdo->prepare("DELETE FROM listings WHERE id = ? AND vendor_id = ?");
    $stmt->execute([$data['listing_id'], $vendor['vendor_id']]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Listing deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Listing not found or access denied']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>