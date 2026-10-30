<?php
// Admin-only CSV download: api/export.php?type=users  or  ?type=payments
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(401);
    exit('Unauthorized access. Admin account required.');
}

$type = $_GET['type'] ?? '';

try {
    if ($type === 'users') {
        $headers = ['Name', 'Email', 'Role'];
        $rows = [];
        $stmt = $pdo->query('SELECT firstname, lastname, email, role FROM users ORDER BY role, lastname');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $rows[] = [trim($u['firstname'] . ' ' . $u['lastname']), $u['email'], ucfirst($u['role'])];
        }
    } elseif ($type === 'payments') {
        $headers = ['Item', 'Vendor', 'Consumer', 'Amount (KES)', 'Method', 'Status'];
        $rows = [];
        // Same query as the dashboard, but with every payment (no LIMIT)
        $stmt = $pdo->query(
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
             ORDER BY p.payment_id DESC"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $rows[] = [
                $p['item_name'],
                trim($p['vendor_first'] . ' ' . $p['vendor_last']),
                trim($p['consumer_first'] . ' ' . $p['consumer_last']),
                number_format((float) $p['amount'], 2, '.', ''),
                $p['payment_method'],
                $p['payment_status'],
            ];
        }
    } else {
        http_response_code(400);
        exit('Unknown export type.');
    }
} catch (PDOException $e) {
    error_log('Export error: ' . $e->getMessage());
    http_response_code(500);
    exit('Could not generate the export.');
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="food-connect-' . $type . '-' . date('Y-m-d') . '.csv"');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");            // BOM so Excel reads it correctly
fputcsv($out, $headers);
foreach ($rows as $r) {
    // stop spreadsheet formula injection from user-entered text
    foreach ($r as &$cell) {
        if (is_string($cell) && $cell !== '' && strpos('=+-@', $cell[0]) !== false) {
            $cell = "'" . $cell;
        }
    }
    unset($cell);
    fputcsv($out, $r);
}
fclose($out);