<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

session_start();
require_once '../includes/db.php';
require_once '../includes/geofence.php';

// Authentication guard
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'vendor') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Vendor account required.']);
    exit();
}

$userId = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        // NOTE: table is `vendor` (singular) — this was the bug causing a
        // "table doesn't exist" error on this endpoint.
        $stmt = $pdo->prepare("SELECT vendor_id, business_name, city, area, address, latitude, longitude, verification_status FROM vendor WHERE user_id = :user_id LIMIT 1");
        $stmt->execute(['user_id' => $userId]);
        $vendor = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($vendor) {
            $vendor['latitude']  = $vendor['latitude']  !== null ? (float) $vendor['latitude']  : null;
            $vendor['longitude'] = $vendor['longitude'] !== null ? (float) $vendor['longitude'] : null;
        }

        echo json_encode([
            'success' => true,
            'data'    => $vendor ?: null,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        error_log('Vendor fetch error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to fetch vendor data.']);
    }
    exit();
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $businessName = trim($input['business_name'] ?? '');
    $city         = trim($input['city'] ?? '');
    $area         = trim($input['area'] ?? '');
    $address      = trim($input['address'] ?? '');
    $latitude     = $input['latitude'] ?? null;
    $longitude    = $input['longitude'] ?? null;

    if ($businessName === '' || $city === '' || $area === '' || $address === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit();
    }

    // The shop's pin on the map is what the distance rule uses
    if (!validCoordinates($latitude, $longitude)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please mark your shop location on the map.']);
        exit();
    }
    $latitude  = round((float) $latitude, 6);
    $longitude = round((float) $longitude, 6);

    try {
        // Check for an existing row first 
        $lookup = $pdo->prepare('SELECT vendor_id FROM vendor WHERE user_id = :user_id LIMIT 1');
        $lookup->execute(['user_id' => $userId]);
        $existing = $lookup->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $update = $pdo->prepare(
                'UPDATE vendor SET business_name = :business_name, city = :city, area = :area, address = :address,
                                   latitude = :latitude, longitude = :longitude
                 WHERE vendor_id = :vendor_id'
            );
            $update->execute([
                'business_name' => $businessName,
                'city'          => $city,
                'area'          => $area,
                'address'       => $address,
                'latitude'      => $latitude,
                'longitude'     => $longitude,
                'vendor_id'     => $existing['vendor_id'],
            ]);
        } else {
            $insert = $pdo->prepare(
                "INSERT INTO vendor (user_id, business_name, city, area, address, latitude, longitude, verification_status)
                 VALUES (:user_id, :business_name, :city, :area, :address, :latitude, :longitude, 'pending')"
            );
            $insert->execute([
                'user_id'       => $userId,
                'business_name' => $businessName,
                'city'          => $city,
                'area'          => $area,
                'address'       => $address,
                'latitude'      => $latitude,
                'longitude'     => $longitude,
            ]);
        }

        echo json_encode([
            'success'     => true,
            'message'     => 'Vendor profile saved successfully!',
            'redirectUrl' => 'vendor-dashboard.html',
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        error_log('Vendor save error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error saving vendor profile.']);
    }
    exit();
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);