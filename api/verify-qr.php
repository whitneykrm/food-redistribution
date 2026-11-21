<?php
// Vendor scans a consumer's QR code: marks that order as Collected.
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'vendor') {
    http_response_code(401);
    echo json_encode(['success' => false, 'code' => 'unauthorized', 'message' => 'Unauthorized access. Vendor account required.']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$raw   = (string) ($input['code'] ?? '');

// The QR text is several lines ("Code: abc..."); the code itself is 32 hex characters
if (!preg_match('/[0-9a-f]{32}/i', $raw, $m)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'code' => 'invalid', 'message' => 'That is not a Food Connect QR code.']);
    exit();
}
$qrCode = strtolower($m[0]);

function orderPayload(array $r): array
{
    $paidWords = ['paid', 'completed', 'complete', 'successful', 'success', 'received', 'confirmed'];
    $first = trim((string) $r['firstname']);
    $initial = trim((string) $r['lastname']) !== '' ? ' ' . strtoupper(substr(trim($r['lastname']), 0, 1)) . '.' : '';
    return [
        'order_id'       => (int) $r['order_id'],
        'order_no'       => (int) $r['order_no'],
        'item_name'      => $r['item_name'],
        'customer'       => $first . $initial,
        'amount'         => $r['amount'] !== null ? (float) $r['amount'] : null,
        'payment_method' => $r['payment_method'],
        'payment_status' => $r['payment_status'],
        'payment_paid'   => $r['payment_status'] !== null && in_array(strtolower($r['payment_status']), $paidWords, true),
        'order_status'   => $r['order_status'],
    ];
}

try {
    $stmt = $pdo->prepare('SELECT vendor_id FROM vendor WHERE user_id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $vendorId = $stmt->fetchColumn();
    if (!$vendorId) {
        echo json_encode(['success' => false, 'code' => 'no_vendor', 'message' => 'Vendor profile not found.']);
        exit();
    }

    $pdo->beginTransaction();

    // Lock the order so two scans at once cannot both succeed
    $stmt = $pdo->prepare(
        "SELECT o.order_id, o.order_status, l.vendor_id, l.item_name,
                (l.expiry_time <= NOW()) AS is_expired,
                u.firstname, u.lastname,
                p.amount, p.payment_method, p.payment_status,
                (SELECT COUNT(*) FROM orders o2
                  WHERE o2.consumer_id = o.consumer_id AND o2.order_id <= o.order_id) AS order_no
         FROM orders o
         JOIN listings l ON l.listings_id = o.listing_id
         JOIN consumer c ON c.consumer_id = o.consumer_id
         JOIN users u ON u.user_id = c.user_id
         LEFT JOIN payment p ON p.order_id = o.order_id
         WHERE o.qr_code = ?
         FOR UPDATE"
    );
    $stmt->execute([$qrCode]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'code' => 'not_found', 'message' => 'This QR code is not recognised.']);
        exit();
    }

    // Another vendor's order: do not reveal any of its details
    if ((int) $order['vendor_id'] !== (int) $vendorId) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'code' => 'not_yours', 'message' => 'This QR code is for another vendor\'s order.']);
        exit();
    }

    if ($order['order_status'] === 'cancelled') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'code' => 'cancelled', 'message' => 'This order was cancelled. Do not hand over any food.', 'order' => orderPayload($order)]);
        exit();
    }

    if ($order['order_status'] === 'collected') {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'code' => 'already_collected', 'message' => 'This order has already been collected.', 'order' => orderPayload($order)]);
        exit();
    }

    // The QR code stops working once the listing's pickup time has passed,
    // even though the consumer generated it in time.
    if ((int) $order['is_expired']) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'code' => 'expired', 'message' => 'This QR code has expired because the listing\'s pickup time has passed. Do not hand over any food.', 'order' => orderPayload($order)]);
        exit();
    }

    $pdo->prepare("UPDATE orders SET order_status = 'collected', collected_at = NOW() WHERE order_id = ? AND order_status = 'reserved'")
        ->execute([$order['order_id']]);
    $pdo->commit();

    $order['order_status'] = 'collected';
    echo json_encode([
        'success' => true,
        'code'    => 'ok',
        'message' => 'Verified. Hand over the food.',
        'order'   => orderPayload($order),
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('verify-qr: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'code' => 'error', 'message' => 'Could not verify the QR code.', 'debug' => $e->getMessage()]);
}