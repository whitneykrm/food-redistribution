<?php
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../includes/db.php';

function findValidReset(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT reset_id, user_id FROM password_resets
         WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
    );
    $stmt->execute(['hash' => hash('sha256', $token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
function passwordProblem(string $password): string
{
    if (strlen($password) < 6)             return 'Password must be at least 6 characters long.';
    if (!preg_match('/[A-Z]/', $password)) return 'Password must include an uppercase letter.';
    if (!preg_match('/[a-z]/', $password)) return 'Password must include a lowercase letter.';
    if (!preg_match('/[0-9]/', $password)) return 'Password must include a number.';
    if (!preg_match('/[^A-Za-z0-9]/', $password)) return 'Password must include a special character, such as ! @ # $ %.';
    return '';
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';

    // GET: the page asks whether the link in the email is still good
    if ($method === 'GET') {
        $valid = findValidReset($pdo, (string) ($_GET['token'] ?? '')) !== null;
        echo json_encode(['success' => true, 'valid' => $valid]);
        exit();
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit();
    }

    $input    = json_decode(file_get_contents('php://input'), true);
    $token    = (string) ($input['token'] ?? '');
    $password = (string) ($input['password'] ?? '');
    $confirm  = (string) ($input['confirm_password'] ?? '');

    if ($password === '' || !hash_equals($password, $confirm)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
        exit();
    }
    $passwordIssue = passwordProblem($password);
    if ($passwordIssue !== '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $passwordIssue]);
        exit();
    }

    $pdo->beginTransaction();

    $reset = findValidReset($pdo, $token);
    if (!$reset) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'This reset link is invalid or has expired. Please request a new one.']);
        exit();
    }

    $pdo->prepare('UPDATE users SET password = :pw WHERE user_id = :uid')
        ->execute(['pw' => password_hash($password, PASSWORD_DEFAULT), 'uid' => $reset['user_id']]);

    // The link can only be used once; clear any other open links for this user
    $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE reset_id = :id')
        ->execute(['id' => $reset['reset_id']]);
    $pdo->prepare('DELETE FROM password_resets WHERE user_id = :uid AND used_at IS NULL')
        ->execute(['uid' => $reset['user_id']]);

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Your password has been updated. You can now sign in.']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Reset password error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again.']);
}