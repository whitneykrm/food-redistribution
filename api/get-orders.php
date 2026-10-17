<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

session_start();
require_once '../includes/db.php';
require_once '../includes/points.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'consumer') {
    http_response_code(401);
    echo json_encode([
        'success'     => false,
        'message'     => 'Unauthorized access. Consumer account required.',
        'redirectUrl' => 'login.html',
    ]);
    exit();
}

try {
    $stmt = $pdo->prepare('SELECT consumer_id FROM consumer WHERE user_id = :user_id LIMIT 1');
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $consumer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$consumer) {
        echo json_encode([
            'success' => true,
            'orders'  => [],
            'points'  => summarizePoints([]),
            'rules'   => pointsRules(),
        ]);
        exit();
    }

    $stmt = $pdo->prepare(
        'SELECT o.order_id, o.qr_code, o.order_status,
                l.item_name, l.expiry_time,
                v.business_name, v.city, v.address,
                p.amount, p.payment_method, p.payment_status
         FROM orders o
         JOIN listings l ON l.listings_id = o.listing_id
         JOIN vendor v ON v.vendor_id = l.vendor_id
         LEFT JOIN payment p ON p.order_id = o.order_id
         WHERE o.consumer_id = :consumer_id
         ORDER BY o.order_id DESC'
    );
    $stmt->execute(['consumer_id' => $consumer['consumer_id']]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($orders as &$o) {
        $o['order_id'] = (int) $o['order_id'];
        $o['amount']   = $o['amount'] !== null ? (float) $o['amount'] : null;

        $paidWithPoints = ($o['payment_method'] ?? '') === 'points';
        $o['paid_with_points'] = $paidWithPoints;
        // Points this order earns (none if it was paid for with points)
        $o['points'] = (!$paidWithPoints && $o['amount'] !== null) ? pointsForAmount($o['amount']) : 0;
        $o['points_cost'] = ($paidWithPoints && $o['amount'] !== null) ? pointsCost($o['amount']) : 0;
    }
    unset($o);

    echo json_encode([
        'success' => true,
        'orders'  => $orders,
        'points'  => summarizePoints($orders),
        'rules'   => pointsRules(),
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('My orders error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to load your orders.']);
}