<?php
header('Content-Type: application/json');
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vendor') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['listing_id']) || empty($data['item_name']) || empty($data['category'])
    || empty($data['quantity']) || !isset($data['original_price']) || !isset($data['discounted_price'])
    || empty($data['expiry_time'])) {
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit;
}

$allowedCategories = [
    'Bakery & Pastries', 'Cakes & Desserts', 'Cooked Meals',
    'Sandwiches & Snacks', 'Salads & Fresh Produce', 'Beverages', 'Other'
];
$category = in_array($data['category'], $allowedCategories, true) ? $data['category'] : 'Other';

$quantity   = (int) $data['quantity'];
$original   = (float) $data['original_price'];
$discounted = (float) $data['discounted_price'];

if ($quantity < 1) {
    echo json_encode(['success' => false, 'message' => 'Quantity must be at least 1.']);
    exit;
}
if ($original <= 0 || $discounted <= 0) {
    echo json_encode(['success' => false, 'message' => 'Prices must be greater than zero.']);
    exit;
}
if ($discounted > $original) {
    echo json_encode(['success' => false, 'message' => 'The discounted price cannot be higher than the original price.']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT vendor_id, verification_status FROM vendor WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $vendor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vendor) {
        echo json_encode(['success' => false, 'message' => 'Vendor profile not completed.']);
        exit;
    }
    if ($vendor['verification_status'] !== 'verified') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your business is pending verification.']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE listings
                           SET item_name = ?, category = ?, quantity = ?,
                               original_price = ?, discounted_price = ?, expiry_time = ?
                           WHERE listings_id = ? AND vendor_id = ?");
    $stmt->execute([
        $data['item_name'],
        $category,
        $quantity,
        $original,
        $discounted,
        $data['expiry_time'],
        (int) $data['listing_id'],
        $vendor['vendor_id']
    ]);
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare("SELECT 1 FROM listings WHERE listings_id = ? AND vendor_id = ?");
        $check->execute([(int) $data['listing_id'], $vendor['vendor_id']]);
        if (!$check->fetchColumn()) {
            echo json_encode(['success' => false, 'message' => 'Listing not found.']);
            exit;
        }
    }

    echo json_encode(['success' => true, 'message' => 'Listing updated successfully']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to update listing: ' . $e->getMessage()]);
}
?>