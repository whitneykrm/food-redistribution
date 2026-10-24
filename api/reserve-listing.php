<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

session_start();
require_once '../includes/db.php';

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit();
}
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

$input         = json_decode(file_get_contents('php://input'), true);
$listingId     = (int) ($input['listing_id'] ?? 0);
$paymentMethod = $input['payment_method'] ?? '';

if ($listingId <= 0 || !in_array($paymentMethod, ['M-Pesa', 'cash'], true)) {
    respond(400, ['success' => false, 'message' => 'A valid listing and payment method are required.']);
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT consumer_id FROM consumer WHERE user_id = :user_id LIMIT 1');
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $consumer = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($consumer) {
        $consumerId = (int) $consumer['consumer_id'];
    } else {
        $insertConsumer = $pdo->prepare("INSERT INTO consumer (user_id, preferences) VALUES (:user_id, '')");
        $insertConsumer->execute(['user_id' => $_SESSION['user_id']]);
        $consumerId = (int) $pdo->lastInsertId();
    }
    $stmt = $pdo->prepare(
        'SELECT vendor_id, item_name, quantity, discounted_price, (expiry_time > NOW()) AS is_live
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
         VALUES (:vendor_id, :order_id, :amount, :payment_method, 'pending')"
    );
    $insertPayment->execute([
        'vendor_id'      => $listing['vendor_id'],
        'order_id'       => $orderId,
        'amount'         => $listing['discounted_price'],
        'payment_method' => $paymentMethod,
    ]);

    $pdo->commit();

    echo json_encode([
        'success'  => true,
        'message'  => 'Reservation confirmed.',
        'order_id' => $orderId,
        'qr_code'  => $qrCode,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Reserve listing error: ' . $e->getMessage());
    respond(500, ['success' => false, 'message' => 'Something went wrong saving your reservation. Please try again.']);
}