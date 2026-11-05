<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mailer.php';

$cfgPath = __DIR__ . '/../includes/mail-config.php';
$cfg     = file_exists($cfgPath) ? require $cfgPath : [];
$debug   = !empty($cfg['debug']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$email = filter_var(trim($input['email'] ?? ''), FILTER_VALIDATE_EMAIL);

if (!$email) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit();
}
// form to find out who has an account.
$genericReply = [
    'success' => true,
    'message' => 'If an account exists for that email, a reset link has been sent. Check your inbox (and spam folder). The link works for 30 minutes.',
];

try {
    $stmt = $pdo->prepare('SELECT user_id, firstname FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode($genericReply);
        exit();
    }

    // Allow one request per minute per account
    $recent = $pdo->prepare(
        'SELECT COUNT(*) FROM password_resets
         WHERE user_id = :uid AND created_at > (NOW() - INTERVAL 60 SECOND)'
    );
    $recent->execute(['uid' => $user['user_id']]);
    if ((int) $recent->fetchColumn() > 0) {
        echo json_encode($genericReply);
        exit();
    }

    // Only the newest link works: remove older unused ones
    $pdo->prepare('DELETE FROM password_resets WHERE user_id = :uid AND used_at IS NULL')
        ->execute(['uid' => $user['user_id']]);

    // The link carries the random token; the database keeps only its hash
    $token     = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    $pdo->prepare(
        'INSERT INTO password_resets (user_id, token_hash, expires_at)
         VALUES (:uid, :hash, DATE_ADD(NOW(), INTERVAL 30 MINUTE))'
    )->execute(['uid' => $user['user_id'], 'hash' => $tokenHash]);

    $base = rtrim($cfg['base_url'] ?? 'http://localhost/food-redistribution', '/');
    $link = $base . '/reset-password.html?token=' . $token;
    $name = htmlspecialchars($user['firstname'], ENT_QUOTES, 'UTF-8');

    $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto;color:#222">'
          . '<h2 style="color:#2d6a4f">Food Connect</h2>'
          . "<p>Hi {$name},</p>"
          . '<p>We received a request to reset your password. Click the button below to choose a new one. '
          . 'The link works for 30 minutes and can be used once.</p>'
          . '<p style="margin:24px 0"><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" '
          . 'style="background:#28a745;color:#fff;padding:12px 22px;border-radius:6px;text-decoration:none;font-weight:bold">Reset my password</a></p>'
          . '<p style="font-size:13px;color:#666">If the button does not work, copy this address into your browser:<br>'
          . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</p>'
          . '<p style="font-size:13px;color:#666">If you did not ask for this, you can ignore this email. Your password stays the same.</p>'
          . '</div>';
    $text = "Hi {$user['firstname']},\n\nReset your Food Connect password (valid 30 minutes, one use):\n{$link}\n\n"
          . "If you did not ask for this, ignore this email.";

    [$sent, $err] = sendMail($email, $user['firstname'], 'Reset your Food Connect password', $html, $text);

    if (!$sent) {
        error_log('Password reset email failed: ' . $err);
        if ($debug) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Email could not be sent.', 'debug' => $err]);
            exit();
        }
    }

    echo json_encode($genericReply);
} catch (Throwable $e) {
    error_log('Forgot password error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Something went wrong. Please try again.',
        'debug'   => $debug ? $e->getMessage() : null,
    ]);
}