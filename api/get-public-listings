<?php
header('Content-Type: application/json');
session_start();
require_once '../includes/db.php';

// Any signed-in user can browse listings (the dashboard redirects to login on 401)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

try {
    // Only unexpired listings with stock left, from verified vendors
    $sql = "SELECT l.listings_id, l.item_name, l.category, l.quantity,
                   l.original_price, l.discounted_price, l.expiry_time,
                   v.business_name, v.city, v.address
            FROM listings l
            JOIN vendor v ON v.vendor_id = l.vendor_id
            WHERE v.verification_status = 'verified'
              AND l.quantity > 0
              AND l.expiry_time > NOW()
            ORDER BY l.expiry_time ASC";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // Points rules: 1 point per KES 10 spent; 1 point = KES 1 when redeeming
    $listings = array_map(function ($r) {
        $price = (float) $r['discounted_price'];
        return [
            'listings_id'      => (int) $r['listings_id'],
            'item_name'        => $r['item_name'],
            'category'         => $r['category'],
            'quantity'         => (int) $r['quantity'],
            'original_price'   => (float) $r['original_price'],
            'discounted_price' => $price,
            'expiry_time'      => $r['expiry_time'],
            'business_name'    => $r['business_name'],
            'city'             => $r['city'],
            'address'          => $r['address'],
            'points'           => (int) floor($price / 10),
            'points_cost'      => (int) ceil($price),
        ];
    }, $rows);

    echo json_encode(['success' => true, 'listings' => $listings]);
} catch (PDOException $e) {
    error_log('get-public-listings error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to load listings.']);
}
?>