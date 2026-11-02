<?php

// 0712345678, 712345678, 254712345678, +254 712 345 678  ->  +254712345678
// Returns null when the number is not a valid Kenyan mobile number.
function normalizeKenyanPhone($raw): ?string
{
    $n = preg_replace('/[\s\-().]/', '', (string) $raw);
    if (strpos($n, '+') === 0) { $n = substr($n, 1); }
    if (preg_match('/^0([17]\d{8})$/', $n, $m))   { $n = '254' . $m[1]; }
    elseif (preg_match('/^([17]\d{8})$/', $n, $m)) { $n = '254' . $m[1]; }
    return preg_match('/^254[17]\d{8}$/', $n) ? '+' . $n : null;
}

function smsConfig(): ?array
{
    static $config = false;
    if ($config === false) {
        $file = __DIR__ . '/sms-config.php';
        $config = is_file($file) ? require $file : null;
    }
    return is_array($config) ? $config : null;
}

// Every SMS attempt is recorded in the notificationlog table (channel = 'sms')
function logSms(PDO $pdo, ?int $userId, string $phone, string $message, string $status, string $response): void
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO notificationlog (user_id, channel, phone, message, status, provider_response)
             VALUES (?, 'sms', ?, ?, ?, ?)"
        );
        $stmt->execute([$userId, $phone, mb_substr($message, 0, 500), $status, mb_substr($response, 0, 2000)]);
    } catch (Throwable $e) {
        error_log('notificationlog insert failed: ' . $e->getMessage());
    }
}

// Sends one SMS. Returns ['ok' => bool, 'status' => string, 'raw' => string].
function sendSms(PDO $pdo, ?int $userId, string $phone, string $message): array
{
    $config = smsConfig();
    if (!$config || empty($config['enabled'])) {
        return ['ok' => false, 'status' => 'disabled', 'raw' => 'SMS is not configured (includes/sms-config.php).'];
    }

    $number = normalizeKenyanPhone($phone);
    if ($number === null) {
        logSms($pdo, $userId, (string) $phone, $message, 'invalid_number', 'Not a valid Kenyan mobile number');
        return ['ok' => false, 'status' => 'invalid_number', 'raw' => 'Invalid phone number'];
    }

    $url = !empty($config['sandbox'])
        ? 'https://api.sandbox.africastalking.com/version1/messaging'
        : 'https://api.africastalking.com/version1/messaging';

    $fields = ['username' => $config['username'], 'to' => $number, 'message' => $message];
    if (!empty($config['sender_id'])) { $fields['from'] = $config['sender_id']; }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'apiKey: ' . $config['api_key'],
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);
    $body  = curl_exec($ch);
    $error = curl_error($ch);
    $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        logSms($pdo, $userId, $number, $message, 'failed', 'cURL error: ' . $error);
        return ['ok' => false, 'status' => 'failed', 'raw' => $error];
    }

    $json = json_decode($body, true);
    $recipient = $json['SMSMessageData']['Recipients'][0] ?? null;
    $ok = $http >= 200 && $http < 300 && $recipient && (($recipient['status'] ?? '') === 'Success');
    $status = $ok ? 'sent' : 'failed';

    logSms($pdo, $userId, $number, $message, $status, $body);
    return ['ok' => $ok, 'status' => $status, 'raw' => $body];
}