<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Admin account required.']);
    exit();
}

try {
    $totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $totalVendors = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'vendor'")->fetchColumn();
    $totalConsumers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'consumer'")->fetchColumn();
    $pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM vendor WHERE verification_status = 'pending'")->fetchColumn();

    $pendingVendors = $pdo->query(
        "SELECT v.vendor_id, v.business_name, u.firstname, u.lastname, u.email
         FROM vendor v
         JOIN users u ON u.user_id = v.user_id
         WHERE v.verification_status = 'pending'
         ORDER BY u.lastname"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pendingVendors as &$pv) {
        $pv['vendor_id'] = (int) $pv['vendor_id'];
    }
    unset($pv);

    // Money each vendor has recovered from food that would have gone to waste:
    // the amount of every order that was collected (QR code scanned).
    $vendorRevenue = $pdo->query(
        "SELECT v.business_name, u.firstname, u.lastname,
                COALESCE(SUM(p.amount), 0) AS recovered,
                COUNT(o.order_id) AS meals
         FROM vendor v
         JOIN users u ON u.user_id = v.user_id
         LEFT JOIN listings l ON l.vendor_id = v.vendor_id
         LEFT JOIN orders o ON o.listing_id = l.listings_id AND o.order_status = 'collected'
         LEFT JOIN payment p ON p.order_id = o.order_id
         GROUP BY v.vendor_id, v.business_name, u.firstname, u.lastname
         ORDER BY recovered DESC, v.business_name"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($vendorRevenue as &$vr) {
        $vr['recovered'] = (float) $vr['recovered'];
        $vr['meals'] = (int) $vr['meals'];
    }
    unset($vr);

    $users = $pdo->query(
        'SELECT user_id, firstname, lastname, email, role FROM users ORDER BY role, lastname'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($users as &$u) {
        $u['user_id'] = (int) $u['user_id'];
    }
    unset($u);

    // Real transactions = payment, joined through orders back to the
    // listing, the vendor's owner, and the consumer's owner.
    // Payments for cancelled orders are left out of the list.
    $payments = $pdo->query(
        "SELECT l.item_name,
                vu.firstname AS vendor_first, vu.lastname AS vendor_last,
                cu.firstname AS consumer_first, cu.lastname AS consumer_last,
                p.amount, p.payment_method, p.payment_status
         FROM payment p
         JOIN orders o ON o.order_id = p.order_id
         JOIN listings l ON l.listings_id = o.listing_id
         JOIN vendor v ON v.vendor_id = p.vendor_id
         JOIN users vu ON vu.user_id = v.user_id
         JOIN consumer c ON c.consumer_id = o.consumer_id
         JOIN users cu ON cu.user_id = c.user_id
         WHERE o.order_status <> 'cancelled'
         ORDER BY p.payment_id DESC
         LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'currentAdminId' => (int) $_SESSION['user_id'],
        'stats' => [
            'totalUsers' => $totalUsers,
            'totalVendors' => $totalVendors,
            'totalConsumers' => $totalConsumers,
            'pendingCount' => $pendingCount,
        ],
        'pendingVendors' => $pendingVendors,
        'vendorRevenue' => $vendorRevenue,
        'users' => $users,
        'payments' => $payments,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('Admin data error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to load dashboard data.']);
}