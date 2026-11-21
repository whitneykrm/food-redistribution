<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

session_start();
require_once '../includes/db.php';
require_once '../includes/points.php';
require_once '../includes/payment-status.php';

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit();
}

// Roll back any open transaction, then send a JSON error.
function abort(PDO $pdo, int $status, string $message): void
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond($status, ['success' => false, 'message' => $message]);
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'consumer') {
    respond(401, ['success' => false, 'message' => 'Unauthorized access. Consumer account required.']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$input     = json_decode(file_get_contents('php://input'), true);
$listingId = (int) ($input['listing_id'] ?? 0);

if ($listingId <= 0) {
    respond(400, ['success' => false, 'message' => 'A valid listing is required.']);
}

try {
    // The value this database uses for "paid" (paid / completed / ...).
    $paidStatus = paidStatusValue($pdo);
    if ($paidStatus === null) {
        respond(500, ['success' => false, 'message' => 'Payment status is not set up correctly.']);
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT consumer_id FROM consumer WHERE user_id = :user_id LIMIT 1 FOR UPDATE');
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $consumer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$consumer) {
        abort($pdo, 400, "You don't have any points yet. Reserve and collect a meal to start earning.");
    }
    $consumerId = (int) $consumer['consumer_id'];

    $summary   = fetchPointsSummary($pdo, $consumerId);
    $available = $summary['available'];

    if ($available < MIN_REDEEM_POINTS) {
        abort($pdo, 400, 'You need at least ' . MIN_REDEEM_POINTS . " points to redeem. You have {$available}.");
    }

    $stmt = $pdo->prepare(
        'SELECT vendor_id, quantity, discounted_price, (expiry_time > NOW()) AS is_live
         FROM listings WHERE listings_id = :id FOR UPDATE'
    );
    $stmt->execute(['id' => $listingId]);
    $listing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$listing) {
        abort($pdo, 404, 'This listing no longer exists.');
    }
    if (!(int) $listing['is_live']) {
        abort($pdo, 409, 'This listing has expired.');
    }
    if ((int) $listing['quantity'] < 1) {
        abort($pdo, 409, 'Sorry, this item has just sold out.');
    }

    $stmt = $pdo->prepare('SELECT verification_status FROM vendor WHERE vendor_id = :vendor_id');
    $stmt->execute(['vendor_id' => $listing['vendor_id']]);
    $vendor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vendor || $vendor['verification_status'] !== 'verified') {
        abort($pdo, 403, 'This vendor is not verified yet.');
    }

    $cost = pointsCost($listing['discounted_price']);
    if ($available < $cost) {
        abort($pdo, 400, "This item costs {$cost} points and you have {$available}.");
    }

    $update = $pdo->prepare('UPDATE listings SET quantity = quantity - 1 WHERE listings_id = :id AND quantity > 0');
    $update->execute(['id' => $listingId]);
    if ($update->rowCount() === 0) {
        abort($pdo, 409, 'Sorry, this item has just sold out.');
    }

    $qrCode = bin2hex(random_bytes(16));

    $insertOrder = $pdo->prepare(
        "INSERT INTO orders (consumer_id, listing_id, qr_code, order_status)
         VALUES (:consumer_id, :listing_id, :qr_code, 'reserved')"
    );
    $insertOrder->execute([
        'consumer_id' => $consumerId,
        'listing_id'  => $listingId,
        'qr_code'     => $qrCode,
    ]);
    $orderId = (int) $pdo->lastInsertId();

    $insertPayment = $pdo->prepare(
        "INSERT INTO payment (vendor_id, order_id, amount, payment_method, payment_status)
         VALUES (:vendor_id, :order_id, :amount, 'points', :status)"
    );
    $insertPayment->execute([
        'vendor_id' => $listing['vendor_id'],
        'order_id'  => $orderId,
        'amount'    => $listing['discounted_price'],
        'status'    => $paidStatus,
    ]);

    $pdo->commit();

    echo json_encode([
        'success'      => true,
        'message'      => 'Points redeemed.',
        'order_id'     => $orderId,
        'qr_code'      => $qrCode,
        'points_spent' => $cost,
        'points_left'  => $available - $cost,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Redeem points error: ' . $e->getMessage());
    respond(500, ['success' => false, 'message' => 'Something went wrong redeeming your points. Please try again.']);
}