<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

session_start();
require_once '../includes/db.php';
require_once '../includes/payment-status.php';

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit();
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'vendor') {
    respond(401, ['success' => false, 'message' => 'Unauthorized access. Vendor account required.']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$input   = json_decode(file_get_contents('php://input'), true);
$orderId = (int) ($input['order_id'] ?? 0);
if ($orderId <= 0) {
    respond(400, ['success' => false, 'message' => 'A valid order is required.']);
}

try {
    $stmt = $pdo->prepare('SELECT vendor_id FROM vendor WHERE user_id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $vendorId = $stmt->fetchColumn();
    if (!$vendorId) {
        respond(403, ['success' => false, 'message' => 'Vendor profile not completed.']);
    }

    $paidValue = paidStatusValue($pdo);
    if ($paidValue === null) {
        respond(500, ['success' => false, 'message' => 'The payment_status column has no value meaning "paid". Add one (for example "paid") and try again.']);
    }

    $pdo->beginTransaction();

    // The payment must belong to an order on one of THIS vendor's listings
    $stmt = $pdo->prepare(
        'SELECT p.payment_id, p.payment_status, o.order_status
         FROM payment p
         JOIN orders o ON o.order_id = p.order_id
         JOIN listings l ON l.listings_id = o.listing_id
         WHERE p.order_id = ? AND l.vendor_id = ?
         LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([$orderId, $vendorId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $pdo->rollBack();
        respond(404, ['success' => false, 'message' => 'Order not found.']);
    }
    if ($row['order_status'] === 'cancelled') {
        $pdo->rollBack();
        respond(409, ['success' => false, 'message' => 'This order was cancelled, so there is no payment to receive.']);
    }
    if (strcasecmp((string) $row['payment_status'], $paidValue) === 0) {
        $pdo->rollBack();
        respond(409, ['success' => false, 'message' => 'This payment was already recorded.']);
    }

    $pdo->prepare('UPDATE payment SET payment_status = ? WHERE payment_id = ?')
        ->execute([$paidValue, $row['payment_id']]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Payment recorded as received.']);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Mark payment error: ' . $e->getMessage());
    respond(500, ['success' => false, 'message' => 'Could not record the payment. Please try again.']);
}