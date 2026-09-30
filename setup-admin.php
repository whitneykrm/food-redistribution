<?php
// setup-admin.php
// Run this ONCE to create your admin account, then delete this file
// (or move it outside the web root) so no one else can ever run it again.

require 'includes/db.php';

$error = '';
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone_number'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($firstName === '' || $lastName === '' || $phone === '' || $email === '' || $password === '') {
        $error = 'Please fill in every field.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        $check = $pdo->prepare('SELECT user_id FROM users WHERE email = ?');
        $check->execute([$email]);

        if ($check->fetch()) {
            $error = 'An account with that email already exists.';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $insert = $pdo->prepare(
                'INSERT INTO users (firstname, lastname, phone_number, email, password, role)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([$firstName, $lastName, $phone, $email, $hashed, 'admin']);
            $success = 'Admin account created. Delete this file now, then log in at login.php.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>One-Time Admin Setup</title>
</head>
<body style="font-family: sans-serif; max-width: 420px; margin: 60px auto;">
    <h2>Create Your Admin Account</h2>
    <p style="color:#b91c1c;"><strong>Delete this file immediately after use.</strong></p>

    <?php if ($error): ?>
        <p style="color:#b91c1c;"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if ($success): ?>
        <p style="color:#15803d;"><?= htmlspecialchars($success) ?></p>
    <?php else: ?>
        <form method="POST">
            <p><input type="text" name="first_name" placeholder="First name" required style="width:100%;padding:8px;"></p>
            <p><input type="text" name="last_name" placeholder="Last name" required style="width:100%;padding:8px;"></p>
            <p><input type="text" name="phone_number" placeholder="Phone number" required style="width:100%;padding:8px;"></p>
            <p><input type="email" name="email" placeholder="Email" required style="width:100%;padding:8px;"></p>
            <p><input type="password" name="password" placeholder="Password you'll remember" required style="width:100%;padding:8px;"></p>
            <button type="submit" style="padding:8px 16px;">Create Admin</button>
        </form>
    <?php endif; ?>
</body>
</html>