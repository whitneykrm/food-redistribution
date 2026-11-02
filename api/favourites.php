<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

session_start();
require_once '../includes/db.php';
require_once '../includes/sms.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'consumer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in as a consumer.']);
    exit();
}

$userId = (int) $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare('SELECT consumer_id FROM consumer WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $consumerId = $stmt->fetchColumn();
    if (!$consumerId) {
        echo json_encode(['success' => false, 'message' => 'Consumer profile not found.']);
        exit();
    }

    function currentPhone(PDO $pdo, int $userId): string
    {
        $s = $pdo->prepare('SELECT phone_number FROM users WHERE user_id = ?');
        $s->execute([$userId]);
        return (string) $s->fetchColumn();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input  = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = $input['action'] ?? '';

        // Add or remove a favourite eatery
        if ($action === 'toggle') {
            $vendorId = (int) ($input['vendor_id'] ?? 0);
            $want     = !empty($input['favourite']);

            $v = $pdo->prepare("SELECT business_name FROM vendor WHERE vendor_id = ? AND verification_status = 'verified'");
            $v->execute([$vendorId]);
            $business = $v->fetchColumn();
            if (!$business) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'That eatery was not found.']);
                exit();
            }

            if ($want) {
                $pdo->prepare('INSERT IGNORE INTO favourite_vendors (consumer_id, vendor_id) VALUES (?, ?)')
                    ->execute([$consumerId, $vendorId]);
            } else {
                $pdo->prepare('DELETE FROM favourite_vendors WHERE consumer_id = ? AND vendor_id = ?')
                    ->execute([$consumerId, $vendorId]);
            }

            echo json_encode([
                'success'    => true,
                'business'   => $business,
                'favourite'  => $want,
                'has_phone'  => normalizeKenyanPhone(currentPhone($pdo, $userId)) !== null,
            ]);
            exit();
        }

        // Update the number SMS alerts are sent to
        if ($action === 'save_phone') {
            $phone = normalizeKenyanPhone($input['phone'] ?? '');
            if ($phone === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Enter a valid Kenyan mobile number, e.g. 0712345678.']);
                exit();
            }
            $pdo->prepare('UPDATE users SET phone_number = ? WHERE user_id = ?')->execute([$phone, $userId]);
            echo json_encode(['success' => true, 'message' => 'Phone number saved.', 'phone' => $phone]);
            exit();
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        exit();
    }

    // GET: the consumer's favourite eateries
    $stmt = $pdo->prepare(
        "SELECT v.vendor_id, v.business_name, v.city, v.area, v.address,
                (SELECT COUNT(*) FROM listings l
                  WHERE l.vendor_id = v.vendor_id AND l.quantity > 0 AND l.expiry_time > NOW()) AS active_listings
         FROM favourite_vendors f
         JOIN vendor v ON v.vendor_id = f.vendor_id
         WHERE f.consumer_id = ?
         ORDER BY v.business_name"
    );
    $stmt->execute([$consumerId]);
    $favourites = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($favourites as &$f) {
        $f['vendor_id']       = (int) $f['vendor_id'];
        $f['active_listings'] = (int) $f['active_listings'];
    }
    unset($f);

    $phone = normalizeKenyanPhone(currentPhone($pdo, $userId));
    $config = smsConfig();

    echo json_encode([
        'success'     => true,
        'favourites'  => $favourites,
        'phone'       => $phone,
        'sms_enabled' => (bool) ($config && !empty($config['enabled'])),
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('Favourites error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load your favourites.']);
}