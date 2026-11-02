<?php
require_once __DIR__ . '/sms.php';

// Texts every consumer who has this vendor as a favourite eatery.
// $listing: item_name, discounted_price, original_price, expiry_time
function notifyFavouriteConsumers(PDO $pdo, int $vendorId, array $listing): array
{
    $result = ['sent' => 0, 'failed' => 0];
    try {
        $stmt = $pdo->prepare('SELECT business_name FROM vendor WHERE vendor_id = ?');
        $stmt->execute([$vendorId]);
        $business = (string) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT u.user_id, u.phone_number
             FROM favourite_vendors f
             JOIN consumer c ON c.consumer_id = f.consumer_id
             JOIN users u ON u.user_id = c.user_id
             WHERE f.vendor_id = ? AND u.phone_number IS NOT NULL AND u.phone_number <> ''
             LIMIT 200"
        );
        $stmt->execute([$vendorId]);
        $people = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$people) { return $result; }

        $item = mb_strlen($listing['item_name']) > 30
            ? mb_substr($listing['item_name'], 0, 29) . '…' : $listing['item_name'];
        $message = sprintf(
            'Food Connect: %s just posted %s at KES %s (was KES %s). Reserve before %s. Open Food Connect to order.',
            $business,
            $item,
            number_format((float) $listing['discounted_price'], 0),
            number_format((float) $listing['original_price'], 0),
            date('D g:ia', strtotime($listing['expiry_time']))
        );

        foreach ($people as $p) {
            $r = sendSms($pdo, (int) $p['user_id'], $p['phone_number'], $message);
            $r['ok'] ? $result['sent']++ : $result['failed']++;
        }
    } catch (Throwable $e) {
        error_log('notifyFavouriteConsumers: ' . $e->getMessage());
    }
    return $result;
}