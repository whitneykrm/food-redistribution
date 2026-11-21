<?php
// Loyalty points rules — the one place to change them for every endpoint.
const KES_PER_POINT     = 10;   // Earn: 1 point for every KES 10 spent (rounded down)
const POINT_VALUE_KES   = 1;    // Redeem: 1 point is worth KES 1
const MIN_REDEEM_POINTS = 100;  // Balance needed before redemption unlocks

function pointsForAmount($amount): int
{
    return (int) floor(((float) $amount) / KES_PER_POINT);
}

// Points needed to pay for something that costs this much money.
function pointsCost($amount): int
{
    return (int) ceil(((float) $amount) / POINT_VALUE_KES);
}

function pointsRules(): array
{
    return [
        'kes_per_point'   => KES_PER_POINT,
        'point_value_kes' => POINT_VALUE_KES,
        'min_redeem'      => MIN_REDEEM_POINTS,
    ];
}

// Reservations nobody collected before the listing's pickup time become 'expired'.
// Points spent on them are given back automatically, because points only count
// as spent on orders that are 'reserved' or 'collected'.
function expireOrders(PDO $pdo): void
{
    try {
        $pdo->exec(
            "UPDATE orders o
             JOIN listings l ON l.listings_id = o.listing_id
             SET o.order_status = 'expired'
             WHERE o.order_status = 'reserved' AND l.expiry_time <= NOW()"
        );
    } catch (Throwable $e) {
        error_log('expireOrders failed: ' . $e->getMessage());
    }
}

function summarizePoints(array $rows): array
{
    $earned = 0;
    $pending = 0;
    $spent = 0;

    foreach ($rows as $row) {
        if ($row['amount'] === null) {
            continue;
        }
        $status = $row['order_status'];

        if (($row['payment_method'] ?? '') === 'points') {
            if ($status === 'reserved' || $status === 'collected') {
                $spent += pointsCost($row['amount']);
            }
        } elseif ($status === 'collected') {
            $earned += pointsForAmount($row['amount']);
        } elseif ($status === 'reserved') {
            $pending += pointsForAmount($row['amount']);
        }
    }

    return [
        'earned'    => $earned,
        'pending'   => $pending,
        'spent'     => $spent,
        'available' => max(0, $earned - $spent),
    ];
}

function fetchPointsSummary(PDO $pdo, int $consumerId): array
{
    expireOrders($pdo);   // make sure expired reservations are not counted as spent

    $stmt = $pdo->prepare(
        'SELECT o.order_status, p.amount, p.payment_method
         FROM orders o
         LEFT JOIN payment p ON p.order_id = o.order_id
         WHERE o.consumer_id = :consumer_id'
    );
    $stmt->execute(['consumer_id' => $consumerId]);

    return summarizePoints($stmt->fetchAll(PDO::FETCH_ASSOC));
}