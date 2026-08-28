<?php
/**
 * Authentication, Authorization, Rate Limiting, and Input Validation Suite
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
 * IP Resolution Helper
 */
function get_client_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $candidate = trim($forwarded[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            $ip = $candidate;
        }
    }
    return substr($ip, 0, 45);
}

/**
 * Brute-Force Rate Limiting Helpers
 * 5 attempts within 15 minutes (900 seconds) lockout
 */
function check_login_throttle($username) {
    try {
        $db = get_db();
        $ip = get_client_ip();
        $username = sanitize_text($username, 50);

        // Clean attempts older than 24 hours
        $db->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");

        // Count failed attempts in the last 15 minutes
        $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE (ip_address = ? OR username = ?) AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $stmt->execute([$ip, $username]);
        $attempts = (int)$stmt->fetchColumn();

        if ($attempts >= 5) {
            // Find when the oldest attempt in the window was made to calculate remaining cooldown
            $tStmt = $db->prepare("SELECT MIN(attempted_at) FROM login_attempts WHERE (ip_address = ? OR username = ?) AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
            $tStmt->execute([$ip, $username]);
            $oldest = strtotime($tStmt->fetchColumn() ?: 'now');
            $remaining = max(1, 900 - (time() - $oldest));
            return $remaining;
        }
    } catch (Exception $e) {}
    return 0;
}

function record_failed_login_attempt($username) {
    try {
        $db = get_db();
        $ip = get_client_ip();
        $username = sanitize_text($username, 50);
        $stmt = $db->prepare("INSERT INTO login_attempts (ip_address, username, attempted_at) VALUES (?, ?, NOW())");
        $stmt->execute([$ip, $username]);
    } catch (Exception $e) {}
}

function clear_failed_login_attempts($username) {
    try {
        $db = get_db();
        $ip = get_client_ip();
        $username = sanitize_text($username, 50);
        $stmt = $db->prepare("DELETE FROM login_attempts WHERE ip_address = ? OR username = ?");
        $stmt->execute([$ip, $username]);
    } catch (Exception $e) {}
}

/**
 * Session Security & Inactivity Timeout
 */
function enforce_session_timeout() {
    if (isset($_SESSION['user_id'])) {
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT_SECONDS) {
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            session_destroy();
            header('Location: login.php?msg=session_timeout');
            exit;
        }
        $_SESSION['last_activity'] = time();
    }
}

/**
 * User Session Helpers
 */
function current_user() {
    enforce_session_timeout();
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

/**
 * Deep Input Sanitization & Validation Suite
 */
function sanitize_text($input, $maxLength = 1000) {
    if (is_null($input)) return '';
    $clean = trim((string)$input);
    // Strip NULL bytes and dangerous non-printable control chars
    $clean = str_replace(chr(0), '', $clean);
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $clean);
    // Normalize UTF-8 characters
    if (function_exists('normalizer_normalize')) {
        $clean = normalizer_normalize($clean, Normalizer::FORM_C) ?: $clean;
    }
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

function validate_gravity($val, $default = null) {
    if ($val === '' || $val === null) return $default;
    $f = filter_var($val, FILTER_VALIDATE_FLOAT);
    if ($f === false) return $default;
    return ($f >= 0.980 && $f <= 1.250) ? round($f, 3) : $default;
}

function validate_temp($val, $default = '') {
    if ($val === '' || $val === null) return $default;
    $clean = trim((string)$val);
    $clean = preg_replace('/[^0-9\.\-Ff°C]/u', '', $clean);
    return sanitize_text($clean, 10);
}

function validate_date($dateStr, $default = null) {
    if (empty($dateStr)) return $default;
    $clean = trim((string)$dateStr);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $clean, $m)) {
        if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return $clean;
        }
    }
    return $default;
}

function validate_batch_size($val, $default = 5.0) {
    $f = filter_var($val, FILTER_VALIDATE_FLOAT);
    if ($f === false || $f <= 0 || $f > 10000.0) return $default;
    return round($f, 2);
}

function validate_rating($val, $default = 0) {
    $i = filter_var($val, FILTER_VALIDATE_INT);
    if ($i === false) return $default;
    return max(0, min(10, $i));
}

function validate_enum($val, array $allowed, $default = '') {
    $val = trim((string)$val);
    return in_array($val, $allowed, true) ? $val : $default;
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
    $stmt = $db->prepare("SELECT id, username, email, role, api_token FROM users WHERE api_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user || !hash_equals($user['api_token'], $token)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired API token']);
        exit;
    }

    return $user;
}
