<?php
// The vendor's page calls this every few seconds to see whether the customer has paid.
// GET ?id=<mpesa_id>
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

session_start();
require_once '../includes/db.php';
require_once '../includes/mpesa.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'vendor') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Vendor account required.']);
    exit();
}

$config = mpesaConfig();
$id = (int) ($_GET['id'] ?? 0);
if (!$config || $id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit();
}

try {
    $stmt = $pdo->prepare(
        "SELECT t.* FROM mpesa_transactions t
         JOIN payment p ON p.order_id = t.order_id
         JOIN vendor v  ON v.vendor_id = p.vendor_id
         WHERE t.mpesa_id = ? AND v.user_id = ? LIMIT 1"
    );
    $stmt->execute([$id, $_SESSION['user_id']]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tx) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payment request not found.']);
        exit();
    }

    $note = '';
    if ($tx['status'] === 'pending') {
        // The callback may not reach a laptop, so ask Safaricom directly
        $q = mpesaStkQuery($config, $tx['checkout_request_id']);
        if ($q['state'] === 'pending') {
            $note = $q['desc'];
        } else {
            $updated = mpesaApplyResult($pdo, $tx['checkout_request_id'], (int) $q['code'], $q['desc'], null);
            if ($updated) { $tx = $updated; }
        }
    }

    $messages = [
        'success' => 'Payment received.',
        'failed'  => $tx['result_desc'] ?: 'The payment was not completed.',
        'pending' => 'Waiting for the customer to enter their PIN...',
    ];
    echo json_encode([
        'success' => true,
        'status'  => $tx['status'],
        'message' => $messages[$tx['status']] ?? $tx['status'],
        'receipt' => $tx['mpesa_receipt'],
        'detail'  => $note,
    ]);
} catch (PDOException $e) {
    error_log('mpesa-status: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not check the payment.']);
}