<?php
header('Content-Type: application/json');

require_once '../includes/db.php';
require_once '../includes/mpesa.php';

$reply = ['ResultCode' => 0, 'ResultDesc' => 'Accepted'];

$config = mpesaConfig();
$key    = (string) ($config['callback_key'] ?? '');
if (!$config || $key === '' || !hash_equals($key, (string) ($_GET['key'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Rejected']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$cb   = $data['Body']['stkCallback'] ?? null;

if ($cb && !empty($cb['CheckoutRequestID'])) {
    $receipt = null;
    foreach (($cb['CallbackMetadata']['Item'] ?? []) as $item) {
        if (($item['Name'] ?? '') === 'MpesaReceiptNumber') { $receipt = (string) $item['Value']; }
    }
    mpesaApplyResult($pdo, (string) $cb['CheckoutRequestID'], (int) ($cb['ResultCode'] ?? 1), (string) ($cb['ResultDesc'] ?? ''), $receipt);
}

echo json_encode($reply);