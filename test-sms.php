<?php
// TEMPORARY test page. Open:  http://localhost/food-redistribution/test-sms.php?to=0712345678
// Delete this file after testing.
require_once 'includes/db.php';
require_once 'includes/sms.php';

header('Content-Type: text/plain; charset=utf-8');

$to = $_GET['to'] ?? '';
if (normalizeKenyanPhone($to) === null) {
    exit("Add your number to the address, e.g. test-sms.php?to=0712345678\n");
}

$config = smsConfig();
echo "Config found: " . ($config ? 'yes' : 'NO (create includes/sms-config.php)') . "\n";
echo "Mode: " . (($config['sandbox'] ?? true) ? 'SANDBOX (simulator only)' : 'LIVE (real phone, costs credit)') . "\n";
echo "Sending to: " . normalizeKenyanPhone($to) . "\n\n";

$r = sendSms($pdo, null, $to, 'Food Connect test: if you can read this, SMS alerts work.');

echo "Result: " . ($r['ok'] ? 'SENT' : 'FAILED') . " (" . $r['status'] . ")\n\n";
echo "Provider response:\n" . $r['raw'] . "\n";