<?php
header('Content-Type: application/json');
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vendor') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['item_name']) || empty($data['category']) || empty($data['quantity']) || empty($data['original_price']) || empty($data['discounted_price']) || empty($data['expiry_time'])) {
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit;
}
$allowedCategories = [
    'Bakery & Pastries',
    'Cakes & Desserts',
    'Cooked Meals',
    'Sandwiches & Snacks',
    'Salads & Fresh Produce',
    'Beverages',
    'Other'
];
$category = $data['category'];
if (!in_array($category, $allowedCategories, true)) {
    $category = 'Other';
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
        echo json_encode(['success' => false, 'message' => 'Your business is pending verification. You can add listings once an admin verifies your account.']);
        exit;
    }


    $sql = "INSERT INTO listings (vendor_id, item_name, category, quantity, original_price, discounted_price, expiry_time)
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $vendor['vendor_id'],
        $data['item_name'],
        $category,
        $data['quantity'],
        $data['original_price'],
        $data['discounted_price'],
        $data['expiry_time']
    ]);

    echo json_encode(['success' => true, 'message' => 'Listing added successfully']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to add listing: ' . $e->getMessage()]);
}
?>