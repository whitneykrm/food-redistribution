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

    // Last 7 days (oldest first) for the waste-recovery graph.
    $daily = [];
    for ($i = 6; $i >= 0; $i--) {
        $ts  = strtotime("-$i day");
        $key = date('Y-m-d', $ts);
        $daily[$key] = [
            'date' => $key, 'label' => $i === 0 ? 'Today' : date('D j', $ts),
            'meals' => 0, 'items_listed' => 0, 'at_risk' => 0.0, 'recovered' => 0.0,
        ];
    }
    $firstDay = array_key_first($daily);
    $lastDay  = array_key_last($daily);

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

    // Listings that expired in the last 7 days, plus food still on offer
    $stmt = $pdo->prepare(
        'SELECT listings_id, quantity, original_price, DATE(expiry_time) AS day
         FROM listings
         WHERE vendor_id = ? AND DATE(expiry_time) >= ?'
    );
    $stmt->execute([$vendorId, $firstDay]);
    $windowListings = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
        // Food that has not expired yet is at risk right now, so it counts under today
        if ($l['day'] > $lastDay) { $l['day'] = $lastDay; }
        $windowListings[(int) $l['listings_id']] = $l;
    }
    $activeOrders = [];   // listing id => orders that were not cancelled

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

        $lid = (int) $r['listings_id'];
        if ($status !== 'cancelled' && isset($windowListings[$lid])) {
            $activeOrders[$lid] = ($activeOrders[$lid] ?? 0) + 1;
            $day = $windowListings[$lid]['day'];
            $daily[$day]['meals']++;
            $daily[$day]['recovered'] += $amount;
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
    foreach ($windowListings as $lid => $l) {
        $originalQty = (int) $l['quantity'] + ($activeOrders[$lid] ?? 0);
        $daily[$l['day']]['items_listed'] += $originalQty;
        $daily[$l['day']]['at_risk'] += $originalQty * (float) $l['original_price'];
    }

    echo json_encode([
        'success' => true,
        'summary' => $summary,
        'orders'  => $orders,
        'items'   => array_values($items),
        'daily'   => array_values($daily),
    ]);
} catch (PDOException $e) {
    error_log('Vendor overview error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load your orders and reports.']);
}