<?php
/**
 * Authentication, Authorization, and Security Utilities
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

/**
 * XSS prevention escaping helper
 */
function e($string) {
    return htmlspecialchars((string)($string ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * CSRF Protection Helpers
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf_token() {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verify_csrf_token($token)) {
        http_response_code(403);
        die("Security Violation: Invalid or missing CSRF token.");
    }
}

/**
 * Sanitize input strings
 */
function sanitize_text($input, $maxLength = 1000) {
    if (is_null($input)) return '';
    $clean = trim((string)$input);
    // Strip control characters except newline and carriage return
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $clean);
    if ($maxLength > 0 && mb_strlen($clean) > $maxLength) {
        $clean = mb_substr($clean, 0, $maxLength);
    }
    return $clean;
}

function sanitize_int($val, $default = 0) {
    return filter_var($val, FILTER_VALIDATE_INT) !== false ? (int)$val : $default;
}

function sanitize_float($val, $default = 0.0) {
    return filter_var($val, FILTER_VALIDATE_FLOAT) !== false ? (float)$val : $default;
}

/**
 * User Session Helpers
 */
function current_user() {
    if (isset($_SESSION['user_id'])) {
        return [
            'id'        => (int)$_SESSION['user_id'],
            'username'  => $_SESSION['username'],
            'email'     => $_SESSION['email'] ?? '',
            'role'      => $_SESSION['role'] ?? 'brewer',
            'api_token' => $_SESSION['api_token'] ?? ''
        ];
    }
    return null;
}

function require_login() {
    if (!current_user()) {
        header('Location: login.php?msg=login_required');
        exit;
    }
}

function generate_api_token() {
    return bin2hex(random_bytes(32));
}

function authenticate_api_request() {
    $token = null;
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s+(\S+)/i', $headers['Authorization'], $matches)) {
            $token = $matches[1];
        }
    }
    if (!$token && isset($_GET['api_token'])) {
        $token = sanitize_text($_GET['api_token'], 64);
    }
    if (!$token && isset($_POST['api_token'])) {
        $token = sanitize_text($_POST['api_token'], 64);
    }

    if (!$token) {
        http_response_code(401);
        echo json_encode(['error' => 'Missing API token. Use Authorization: Bearer <token>']);
        exit;
    }

    $db = get_db();
    $stmt = $db->prepare("SELECT id, username, email, role FROM users WHERE api_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired API token']);
        exit;
    }

    return $user;
}
