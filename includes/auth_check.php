<?php
/**
 * Authentication Guard & RBAC Authorization Helper
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db.php';

function is_logged_in(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['username']);
}

function get_logged_user(): ?array {
    if (!is_logged_in()) return null;
    return [
        'id'       => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'email'    => $_SESSION['email'] ?? '',
        'role'     => $_SESSION['role'] ?? 'worker',
    ];
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: /admin/login.php');
        exit;
    }
}

function require_role(array $allowedRoles): void {
    require_login();
    $user = get_logged_user();
    if (!in_array($user['role'], $allowedRoles, true)) {
        http_response_code(403);
        die("<h1>403 Forbidden</h1><p>You do not have permission to access this resource. Required Role: " . implode(', ', $allowedRoles) . "</p>");
    }
}

function audit_log(string $action, string $details = ''): void {
    try {
        $pdo = Database::getConnection();
        $username = $_SESSION['username'] ?? 'SYSTEM';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (username, action, details, ipaddress)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$username, $action, $details, $ip]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
    }
}
