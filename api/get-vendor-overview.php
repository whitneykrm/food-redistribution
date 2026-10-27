<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

session_start();
require_once '../includes/db.php';
require_once '../includes/payment-status.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'vendor') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Vendor account required.']);
    exit();
}

try {
    $stmt = $pdo->prepare('SELECT vendor_id FROM vendor WHERE user_id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $vendorId = $stmt->fetchColumn();

    if (!$vendorId) {
        echo json_encode(['success' => true, 'summary' => null, 'orders' => [], 'items' => []]);
        exit();
    }

    $paidValue = paidStatusValue($pdo);

    // Every order placed on this vendor's listings
    $stmt = $pdo->prepare(
        "SELECT o.order_id, o.order_status,
                l.listings_id, l.item_name,
                u.firstname, u.lastname,
                p.amount, p.payment_method, p.payment_status,
                (SELECT COUNT(*) FROM orders o2
                  WHERE o2.consumer_id = o.consumer_id AND o2.order_id <= o.order_id) AS order_no
         FROM orders o
         JOIN listings l ON l.listings_id = o.listing_id
         JOIN consumer c ON c.consumer_id = o.consumer_id
         JOIN users u ON u.user_id = c.user_id
         LEFT JOIN payment p ON p.order_id = o.order_id
         WHERE l.vendor_id = ?
         ORDER BY o.order_id DESC"
    );
    $stmt->execute([$vendorId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Listings still open to consumers
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS active_listings, COALESCE(SUM(quantity), 0) AS items_available
         FROM listings WHERE vendor_id = ? AND expiry_time > NOW() AND quantity > 0'
    );
    $stmt->execute([$vendorId]);
    $open = $stmt->fetch(PDO::FETCH_ASSOC);

    $summary = [
        'active_listings'  => (int) $open['active_listings'],
        'items_available'  => (int) $open['items_available'],
        'reserved'         => 0,
        'collected'        => 0,
        'cancelled'        => 0,
        'revenue_received' => 0.0,
        'payments_pending' => 0.0,
    ];
    $items  = [];
    $orders = [];

    foreach ($rows as $r) {
        $status = $r['order_status'];
        $amount = (float) ($r['amount'] ?? 0);
        $isPaid = $r['payment_status'] !== null && $paidValue !== null
                  && strcasecmp($r['payment_status'], $paidValue) === 0;

        $id = (int) $r['listings_id'];
        if (!isset($items[$id])) {
            $items[$id] = [
                'item_name' => $r['item_name'], 'reserved' => 0, 'collected' => 0,
                'cancelled' => 0, 'revenue_received' => 0.0, 'payments_pending' => 0.0,
            ];
        }

        if (isset($summary[$status])) { $summary[$status]++; }
        $items[$id][$status] = ($items[$id][$status] ?? 0) + 1;

        if ($status !== 'cancelled' && $r['payment_status'] !== null) {
            if ($isPaid) {
                $summary['revenue_received'] += $amount;
                $items[$id]['revenue_received'] += $amount;
            } else {
                $summary['payments_pending'] += $amount;
                $items[$id]['payments_pending'] += $amount;
            }
        }

        $orders[] = [
            'order_id'       => (int) $r['order_id'],
            'order_no'       => (int) $r['order_no'],
            'customer'       => trim($r['firstname'] . ' ' . $r['lastname']),
            'item_name'      => $r['item_name'],
            'amount'         => $amount,
            'payment_method' => $r['payment_method'],
            'payment_status' => $r['payment_status'],
            'payment_paid'   => $isPaid,
            'order_status'   => $status,
        ];
    }

    echo json_encode([
        'success' => true,
        'summary' => $summary,
        'orders'  => $orders,
        'items'   => array_values($items),
    ]);
} catch (PDOException $e) {
    error_log('Vendor overview error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load your orders and reports.']);
}