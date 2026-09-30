<?php
header('Content-Type: application/json');
session_start();
require_once '../includes/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vendor') {
    echo json_encode([
        'success' => false, 
        'message' => 'Unauthorized access', 
        'redirectUrl' => 'login.html'
    ]);
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    
    $stmt = $pdo->prepare("SELECT vendor_id FROM vendor WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $vendor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vendor) {
        echo json_encode([
            'success' => false, 
            'message' => 'Vendor profile not found', 
            'redirectUrl' => 'vendor-details.html'
        ]);
        exit;
    }

    // Fetch listings for this vendor sorted by listings_id descending
    $stmt = $pdo->prepare("SELECT * FROM listings WHERE vendor_id = ? ORDER BY listings_id DESC");
    $stmt->execute([$vendor['vendor_id']]);
    $listings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true, 
        'listings' => $listings
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Database query failed: ' . $e->getMessage()
    ]);
}
?>