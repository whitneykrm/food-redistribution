<?php
header('Content-Type: application/json');
session_start();
require_once '../includes/db.php';
require_once '../includes/upload-image.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vendor') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// The dashboard sends a form (so a photo can be attached); plain JSON still works too
$data = !empty($_POST) ? $_POST : (json_decode(file_get_contents('php://input'), true) ?: []);

if (empty($data['item_name']) || empty($data['category']) || empty($data['quantity']) || empty($data['original_price']) || empty($data['discounted_price']) || empty($data['expiry_time'])) {
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit;
}

// Only accept the categories offered in the vendor dashboard dropdown
$allowedCategories = [
    'Bakery & Pastries',
    'Cakes & Desserts',
    'Cooked Meals',
    'Sandwiches & Snacks',
    'Salads & Fresh Produce',
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
    // Optional photo
    $imagePath = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        [$ok, $result] = saveListingImage($_FILES['image']);
        if (!$ok) {
            echo json_encode(['success' => false, 'message' => $result]);
            exit;
        }
        $imagePath = $result;
    }

    $sql = "INSERT INTO listings (vendor_id, item_name, category, image_path, quantity, original_price, discounted_price, expiry_time)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $vendor['vendor_id'],
        $data['item_name'],
        $category,
        $imagePath,
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