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

$input   = json_decode(file_get_contents('php://input'), true);
$orderId = (int) ($input['order_id'] ?? 0);

if ($orderId <= 0) {
    respond(400, ['success' => false, 'message' => 'A valid order is required.']);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT consumer_id FROM consumer WHERE user_id = :user_id LIMIT 1');
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $consumer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$consumer) {
        abort($pdo, 404, 'Order not found.');
    }
    $stmt = $pdo->prepare(
        'SELECT order_id, listing_id, order_status
         FROM orders WHERE order_id = :order_id AND consumer_id = :consumer_id FOR UPDATE'
    );
    $stmt->execute(['order_id' => $orderId, 'consumer_id' => $consumer['consumer_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        abort($pdo, 404, 'Order not found.');
    }
    if ($order['order_status'] !== 'reserved') {
        abort($pdo, 409, 'Only reserved orders can be cancelled.');
    }

    $cancel = $pdo->prepare("UPDATE orders SET order_status = 'cancelled' WHERE order_id = :order_id");
    $cancel->execute(['order_id' => $orderId]);

    // Put the unit back so someone else can reserve it.
    $restore = $pdo->prepare('UPDATE listings SET quantity = quantity + 1 WHERE listings_id = :listing_id');
    $restore->execute(['listing_id' => $order['listing_id']]);

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Reservation cancelled.']);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Cancel order error: ' . $e->getMessage());
    respond(500, ['success' => false, 'message' => 'Could not cancel this order. Please try again.']);
}