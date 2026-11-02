<?php
header('Content-Type: application/json');
session_start();
require_once '../includes/db.php';
require_once '../includes/geofence.php';

// Any signed-in user can browse listings (the dashboard redirects to login on 401)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

try {
    // Only unexpired listings with stock left, from verified vendors
    $sql = "SELECT l.listings_id, l.item_name, l.category, l.image_path, l.quantity,
                   l.original_price, l.discounted_price, l.expiry_time,
                   v.business_name, v.city, v.area, v.address, v.latitude, v.longitude,
                   CONCAT(u.firstname, ' ', u.lastname) AS vendor_name
            FROM listings l
            JOIN vendor v ON v.vendor_id = l.vendor_id
            JOIN users u ON u.user_id = v.user_id
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
            'image_path'       => $r['image_path'],
            'quantity'         => (int) $r['quantity'],
            'original_price'   => (float) $r['original_price'],
            'discounted_price' => $price,
            'expiry_time'      => $r['expiry_time'],
            'business_name'    => $r['business_name'],
            'vendor_name'      => $r['vendor_name'],
            'city'             => $r['city'],
            'area'             => $r['area'],
            'address'          => $r['address'],
            'latitude'         => $r['latitude']  !== null ? (float) $r['latitude']  : null,
            'longitude'        => $r['longitude'] !== null ? (float) $r['longitude'] : null,
            'points'           => (int) floor($price / 10),
            'points_cost'      => (int) ceil($price),
        ];
    }, $rows);

    echo json_encode(['success' => true, 'listings' => $listings, 'reserve_radius_km' => RESERVE_RADIUS_KM]);
} catch (PDOException $e) {
    error_log('get-public-listings error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to load listings.']);
}
?>