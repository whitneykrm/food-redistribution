<?php
function paidStatusValue(PDO $pdo): ?string
{
    $col = $pdo->query("SHOW COLUMNS FROM payment LIKE 'payment_status'")->fetch(PDO::FETCH_ASSOC);
    if (!$col || stripos($col['Type'], 'enum') !== 0) {
        return 'paid';   
    }
    preg_match_all("/'([^']*)'/", $col['Type'], $m);
    $allowed = $m[1];
    foreach (['paid', 'completed', 'complete', 'successful', 'success', 'received', 'confirmed'] as $word) {
        foreach ($allowed as $value) {
            if (strcasecmp($value, $word) === 0) {
                return $value;
            }
        }
    }
    return null;
}