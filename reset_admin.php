<?php
/**
 * Utility Script to Reset / Ensure Default Admin Password
 */

require_once __DIR__ . '/db.php';

$username = 'admin';
$password = 'adminPassword123!';
$email    = 'owner@example.com';
$hash     = password_hash($password, PASSWORD_BCRYPT);

try {
    $pdo = Database::getConnection();
    
    // Check if admin exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user) {
        $updateStmt = $pdo->prepare("UPDATE users SET password_hash = ?, status = 'active', role = 'owner' WHERE username = ?");
        $updateStmt->execute([$hash, $username]);
        $msg = "Successfully updated password for existing 'admin' user to 'adminPassword123!'.";
    } else {
        $insertStmt = $pdo->prepare("INSERT INTO users (username, password_hash, email, role, status) VALUES (?, ?, ?, 'owner', 'active')");
        $insertStmt->execute([$username, $hash, $email]);
        $msg = "Successfully created default 'admin' user with password 'adminPassword123!'.";
    }

    if (php_sapi_name() === 'cli') {
        echo "[SUCCESS] " . $msg . "\n";
    } else {
        echo "<h1>Admin Password Reset</h1><p style='color:green;'>{$msg}</p><p><a href='/admin/login.php'>Go to Admin Login</a></p>";
    }
} catch (Exception $e) {
    if (php_sapi_name() === 'cli') {
        echo "[ERROR] " . $e->getMessage() . "\n";
    } else {
        echo "<h1>Error Resetting Admin Password</h1><p style='color:red;'>" . htmlspecialchars($e->getMessage()) . "</p>";
    }
}
