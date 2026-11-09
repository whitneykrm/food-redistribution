<?php
// M-Pesa (Safaricom Daraja) STK Push helpers.

function mpesaConfig(): ?array
{
    static $config = false;
    if ($config === false) {
        $file = __DIR__ . '/mpesa-config.php';
        $config = is_file($file) ? require $file : null;
    }
    return is_array($config) && !empty($config['enabled']) ? $config : null;
}

function mpesaBaseUrl(array $c): string
{
    return (($c['environment'] ?? 'sandbox') === 'production')
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke';
}

// 0712345678, +254 712 345 678, 254712345678 -> 254712345678 (null if not a Kenyan mobile)
function normalizeMpesaPhone($raw): ?string
{
    $n = preg_replace('/[\s\-().+]/', '', (string) $raw);
    if (preg_match('/^0([17]\d{8})$/', $n, $m))   { $n = '254' . $m[1]; }
    elseif (preg_match('/^([17]\d{8})$/', $n, $m)) { $n = '254' . $m[1]; }
    return preg_match('/^254[17]\d{8}$/', $n) ? $n : null;
}

// Low-level call. Returns ['http' => int, 'json' => array|null, 'raw' => string, 'error' => string]
function mpesaHttp(array $c, string $method, string $url, array $headers, ?array $body = null, ?string $basicAuth = null): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => ($c['verify_ssl'] ?? true) ? true : false,
        CURLOPT_SSL_VERIFYHOST => ($c['verify_ssl'] ?? true) ? 2 : 0,
    ];
    if ($body !== null) { $opts[CURLOPT_POSTFIELDS] = json_encode($body); }
    if ($basicAuth !== null) { $opts[CURLOPT_USERPWD] = $basicAuth; }
    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http' => $http, 'json' => is_string($raw) ? json_decode($raw, true) : null, 'raw' => (string) $raw, 'error' => $err];
}

// Returns [token|null, errorMessage]
function mpesaAccessToken(array $c): array
{
    $r = mpesaHttp(
        $c, 'GET',
        mpesaBaseUrl($c) . '/oauth/v1/generate?grant_type=client_credentials',
        ['Accept: application/json'],
        null,
        ($c['consumer_key'] ?? '') . ':' . ($c['consumer_secret'] ?? '')
    );
    if ($r['error'] !== '') { return [null, 'Could not reach Safaricom: ' . $r['error']]; }
    if (!empty($r['json']['access_token'])) { return [$r['json']['access_token'], '']; }
    return [null, 'Safaricom rejected the consumer key/secret (HTTP ' . $r['http'] . '). Check includes/mpesa-config.php.'];
}

function mpesaTimestamp(): string
{
    return (new DateTime('now', new DateTimeZone('Africa/Nairobi')))->format('YmdHis');
}

function mpesaPassword(array $c, string $timestamp): string
{
    return base64_encode($c['shortcode'] . $c['passkey'] . $timestamp);
}

// Sends the prompt to the customer's phone.
// Returns ['ok' => bool, 'checkout_id' => ?string, 'merchant_id' => ?string, 'message' => string]
function mpesaStkPush(array $c, string $phone254, int $amount, string $reference): array
{
    [$token, $err] = mpesaAccessToken($c);
    if ($token === null) { return ['ok' => false, 'checkout_id' => null, 'merchant_id' => null, 'message' => $err]; }

    $ts  = mpesaTimestamp();
    $cb  = (string) ($c['callback_url'] ?? '');
    $key = (string) ($c['callback_key'] ?? '');
    if ($key !== '') { $cb .= (strpos($cb, '?') === false ? '?' : '&') . 'key=' . urlencode($key); }

    $r = mpesaHttp($c, 'POST', mpesaBaseUrl($c) . '/mpesa/stkpush/v1/processrequest', [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ], [
        'BusinessShortCode' => $c['shortcode'],
        'Password'          => mpesaPassword($c, $ts),
        'Timestamp'         => $ts,
        'TransactionType'   => $c['transaction_type'] ?? 'CustomerPayBillOnline',
        'Amount'            => $amount,
        'PartyA'            => $phone254,
        'PartyB'            => $c['shortcode'],
        'PhoneNumber'       => $phone254,
        'CallBackURL'       => $cb,
        'AccountReference'  => substr($reference, 0, 12),
        'TransactionDesc'   => 'Food Connect',
    ]);

    if ($r['error'] !== '') { return ['ok' => false, 'checkout_id' => null, 'merchant_id' => null, 'message' => 'Could not reach Safaricom: ' . $r['error']]; }

    $j = $r['json'] ?? [];
    if (($j['ResponseCode'] ?? '') === '0' && !empty($j['CheckoutRequestID'])) {
        return ['ok' => true, 'checkout_id' => $j['CheckoutRequestID'], 'merchant_id' => $j['MerchantRequestID'] ?? null,
                'message' => $j['CustomerMessage'] ?? 'Prompt sent.'];
    }
    $msg = $j['errorMessage'] ?? $j['ResponseDescription'] ?? ('HTTP ' . $r['http']);
    return ['ok' => false, 'checkout_id' => null, 'merchant_id' => null, 'message' => 'Safaricom refused the request: ' . $msg];
}

// Asks Safaricom what happened to a prompt (works without a public callback URL).
// Returns ['state' => 'success'|'failed'|'pending', 'code' => ?int, 'desc' => string]
function mpesaStkQuery(array $c, string $checkoutId): array
{
    [$token, $err] = mpesaAccessToken($c);
    if ($token === null) { return ['state' => 'pending', 'code' => null, 'desc' => $err]; }

    $ts = mpesaTimestamp();
    $r = mpesaHttp($c, 'POST', mpesaBaseUrl($c) . '/mpesa/stkpushquery/v1/query', [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ], [
        'BusinessShortCode' => $c['shortcode'],
        'Password'          => mpesaPassword($c, $ts),
        'Timestamp'         => $ts,
        'CheckoutRequestID' => $checkoutId,
    ]);

    $j = $r['json'] ?? [];
    if (isset($j['ResultCode'])) {
        $code = (int) $j['ResultCode'];
        return ['state' => $code === 0 ? 'success' : 'failed', 'code' => $code, 'desc' => (string) ($j['ResultDesc'] ?? '')];
    }
    // "The transaction is being processed" (500.001.1001) and any temporary error: keep waiting
    return ['state' => 'pending', 'code' => null, 'desc' => (string) ($j['errorMessage'] ?? $r['error'] ?: 'Waiting for the customer')];
}

// Records the outcome of one prompt. Safe to call twice (callback + polling): only the first call changes anything.
// On success the order's payment is marked paid. Returns the transaction row, or null if unknown.
function mpesaApplyResult(PDO $pdo, string $checkoutId, int $resultCode, string $desc, ?string $receipt): ?array
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM mpesa_transactions WHERE checkout_request_id = ? FOR UPDATE');
        $stmt->execute([$checkoutId]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tx) { $pdo->rollBack(); return null; }
        if ($tx['status'] !== 'pending') { $pdo->commit(); return $tx; }

        $status = $resultCode === 0 ? 'success' : 'failed';
        $pdo->prepare(
            'UPDATE mpesa_transactions SET status = ?, result_code = ?, result_desc = ?, mpesa_receipt = ? WHERE mpesa_id = ?'
        )->execute([$status, $resultCode, mb_substr($desc, 0, 255), $receipt, $tx['mpesa_id']]);

        if ($status === 'success') {
            require_once __DIR__ . '/payment-status.php';
            $paid = paidStatusValue($pdo);
            if ($paid !== null) {
                $pdo->prepare(
                    "UPDATE payment SET payment_status = ? WHERE order_id = ? AND payment_status <> ?"
                )->execute([$paid, $tx['order_id'], $paid]);
            } else {
                error_log('mpesa: no "paid" value allowed in payment.payment_status');
            }
        }

        $pdo->commit();
        $tx['status'] = $status; $tx['result_code'] = $resultCode; $tx['result_desc'] = $desc; $tx['mpesa_receipt'] = $receipt;
        return $tx;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('mpesaApplyResult: ' . $e->getMessage());
        return null;
    }
}