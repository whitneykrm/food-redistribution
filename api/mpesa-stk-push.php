<?php
// Vendor presses "Request M-Pesa" on an order: send the payment prompt to the customer's phone.
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

session_start();
require_once '../includes/db.php';
require_once '../includes/payment-status.php';
require_once '../includes/mpesa.php';

function fail(string $message, int $http = 200): void
{
    http_response_code($http);
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'vendor') {
    fail('Vendor account required.', 401);
}

$config = mpesaConfig();
if (!$config) { fail('M-Pesa is not set up yet (includes/mpesa-config.php).', 500); }

$input   = json_decode(file_get_contents('php://input'), true) ?: [];
$orderId = (int) ($input['order_id'] ?? 0);
if ($orderId <= 0) { fail('Invalid order.', 400); }

try {
    // The order must belong to this vendor
    $stmt = $pdo->prepare(
        "SELECT p.payment_id, p.amount, p.payment_method, p.payment_status,
                o.order_status, u.phone_number
         FROM payment p
         JOIN orders o   ON o.order_id = p.order_id
         JOIN vendor v   ON v.vendor_id = p.vendor_id
         JOIN consumer c ON c.consumer_id = o.consumer_id
         JOIN users u    ON u.user_id = c.user_id
         WHERE p.order_id = ? AND v.user_id = ?
         LIMIT 1"
    );
    $stmt->execute([$orderId, $_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) { fail('Order not found for your business.', 404); }
    if ($row['order_status'] === 'cancelled') { fail('This order was cancelled.'); }

    $paid = paidStatusValue($pdo);
    if ($paid !== null && strcasecmp((string) $row['payment_status'], $paid) === 0) { fail('This order is already paid.'); }
    if (stripos((string) $row['payment_method'], 'pesa') === false) { fail('This order is not an M-Pesa order.'); }

    $phone = normalizeMpesaPhone(trim((string) ($input['phone'] ?? '')) !== '' ? $input['phone'] : $row['phone_number']);
    if ($phone === null) { fail('That is not a valid Safaricom number. Use the format 0712345678.'); }

    $amount = (int) ceil((float) $row['amount']);
    if ($amount < 1) { fail('The order amount is too small to request.'); }

    // Do not double-prompt: one prompt per order per minute
    $stmt = $pdo->prepare(
        "SELECT mpesa_id FROM mpesa_transactions
         WHERE order_id = ? AND status = 'pending' AND created_at > (NOW() - INTERVAL 60 SECOND)
         ORDER BY mpesa_id DESC LIMIT 1"
    );
    $stmt->execute([$orderId]);
    if ($existing = $stmt->fetchColumn()) {
        echo json_encode(['success' => true, 'mpesa_id' => (int) $existing, 'message' => 'A prompt was just sent. Waiting for the customer.']);
        exit();
    }

    $push = mpesaStkPush($config, $phone, $amount, 'FC' . $orderId);
    if (!$push['ok']) { fail($push['message']); }

    $pdo->prepare(
        "INSERT INTO mpesa_transactions (order_id, payment_id, phone, amount, merchant_request_id, checkout_request_id, status)
         VALUES (?, ?, ?, ?, ?, ?, 'pending')"
    )->execute([$orderId, $row['payment_id'], $phone, $amount, $push['merchant_id'], $push['checkout_id']]);

    echo json_encode([
        'success'  => true,
        'mpesa_id' => (int) $pdo->lastInsertId(),
        'message'  => 'Prompt sent. Ask the customer to enter their M-Pesa PIN.',
    ]);
} catch (PDOException $e) {
    error_log('mpesa-stk-push: ' . $e->getMessage());
    fail('Could not start the payment: ' . $e->getMessage(), 500);
}