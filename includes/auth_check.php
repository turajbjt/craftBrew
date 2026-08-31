<?php
/**
 * Authentication, Authorization, RBAC, Security Firewall, Rate Limiting, and Validation Suite
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
 * Global IP Firewall Check
 */
function check_ip_blocklist() {
    try {
        $ip = get_client_ip();
        $db = get_db();
        $stmt = $db->prepare("SELECT reason, expires_at FROM blocked_ips WHERE ip_address = ? AND (expires_at IS NULL OR expires_at > NOW())");
        $stmt->execute([$ip]);
        $blocked = $stmt->fetch();
        if ($blocked) {
            http_response_code(403);
            die("<!DOCTYPE html><html><head><title>Access Denied</title><style>body{font-family:sans-serif;text-align:center;padding:4rem;background:#f8fafc;color:#0f172a;}.box{background:#fff;padding:2rem;max-width:500px;margin:auto;border-radius:12px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);border:1px solid #fee2e2;}</style></head><body><div class='box'><h1 style='color:#dc2626;'>🚫 Access Denied</h1><p style='color:#64748b;margin:1rem 0;'>Your IP address (<strong>" . htmlspecialchars($ip) . "</strong>) has been restricted by the site administrator.</p><p><small>Reason: " . htmlspecialchars($blocked['reason'] ?: 'Security Policy Violation') . "</small></p></div></body></html>");
        }
    } catch (Exception $e) {}
}
// Execute IP check automatically
check_ip_blocklist();

/**
 * Site Settings Helpers
 */
$GLOBAL_SETTINGS_CACHE = [];

function get_site_setting($key, $default = '') {
    global $GLOBAL_SETTINGS_CACHE;
    if (isset($GLOBAL_SETTINGS_CACHE[$key])) {
        return $GLOBAL_SETTINGS_CACHE[$key];
    }
    try {
        $db = get_db();
        $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        $GLOBAL_SETTINGS_CACHE[$key] = ($val !== false) ? $val : $default;
        return $GLOBAL_SETTINGS_CACHE[$key];
    } catch (Exception $e) {
        return $default;
    }
}

function set_site_setting($key, $value) {
    global $GLOBAL_SETTINGS_CACHE;
    try {
        $db = get_db();
        $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$key, (string)$value]);
        $GLOBAL_SETTINGS_CACHE[$key] = (string)$value;
        return true;
    } catch (Exception $e) {
        return false;
    }
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
 * Zero-Dependency Invisible Honeypot & Time-Trap Bot Defense
 */
function render_bot_trap() {
    $ts = time();
    $token = hash_hmac('sha256', (string)$ts, $_SESSION['csrf_token'] ?? 'cb_trap_seed');
    return '<div style="display:none !important; visibility:hidden !important; opacity:0 !important; position:absolute !important; left:-9999px !important;" aria-hidden="true">' .
           '<label for="website_url_hp">Leave this field blank</label>' .
           '<input type="text" id="website_url_hp" name="website_url_hp" tabindex="-1" autocomplete="off" value="">' .
           '</div>' .
           '<input type="hidden" name="bot_ts" value="' . $ts . '">' .
           '<input type="hidden" name="bot_token" value="' . $token . '">';
}

function verify_bot_trap(&$errorMsg = '') {
    // 1. Honeypot check: Bots autofill hidden inputs
    if (!empty($_POST['website_url_hp'])) {
        $errorMsg = "Automated request blocked.";
        return false;
    }

    // 2. Time-Trap check: Reject instant sub-second submissions (< 1s) or ancient expired submissions (> 24h)
    if (isset($_POST['bot_ts']) && isset($_POST['bot_token'])) {
        $ts = (int)$_POST['bot_ts'];
        $token = $_POST['bot_token'];
        $expectedToken = hash_hmac('sha256', (string)$ts, $_SESSION['csrf_token'] ?? 'cb_trap_seed');

        if (!hash_equals($expectedToken, $token)) {
            $errorMsg = "Invalid form submission signature. Please reload and try again.";
            return false;
        }

        $elapsed = time() - $ts;
        if ($elapsed < 1) {
            $errorMsg = "Submission was processed too quickly. Please take a moment and try again.";
            return false;
        }
        if ($elapsed > 86400) {
            $errorMsg = "This form session has expired. Please refresh the page.";
            return false;
        }
    }

    return true;
}

/**
 * Brute-Force Login Rate Limiting
 */
function check_login_throttle($username) {
    try {
        $db = get_db();
        $ip = get_client_ip();
        $username = sanitize_text($username, 50);

        $maxAttempts = (int)get_site_setting('max_login_attempts', 5);
        $lockoutMins = (int)get_site_setting('lockout_minutes', 15);
        $lockoutSecs = $lockoutMins * 60;

        // Clean attempts older than 24 hours
        $db->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");

        // Count failed attempts in the lockout window
        $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE (ip_address = ? OR username = ?) AND attempted_at > DATE_SUB(NOW(), INTERVAL {$lockoutMins} MINUTE)");
        $stmt->execute([$ip, $username]);
        $attempts = (int)$stmt->fetchColumn();

        if ($attempts >= $maxAttempts) {
            $tStmt = $db->prepare("SELECT MIN(attempted_at) FROM login_attempts WHERE (ip_address = ? OR username = ?) AND attempted_at > DATE_SUB(NOW(), INTERVAL {$lockoutMins} MINUTE)");
            $tStmt->execute([$ip, $username]);
            $oldest = strtotime($tStmt->fetchColumn() ?: 'now');
            $remaining = max(1, $lockoutSecs - (time() - $oldest));
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
 * Account Recovery Rate Limiting
 */
function check_recovery_throttle($type, $identifier) {
    try {
        $db = get_db();
        $ip = get_client_ip();
        $type = sanitize_text($type, 20);
        $identifier = sanitize_text($identifier, 100);

        $maxAttempts = (int)get_site_setting('max_recovery_attempts', 3);
        $lockoutMins = (int)get_site_setting('recovery_lockout_minutes', 15);
        $lockoutSecs = $lockoutMins * 60;

        // Clean older than 24 hours
        $db->exec("DELETE FROM recovery_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");

        $stmt = $db->prepare("SELECT COUNT(*) FROM recovery_attempts WHERE (ip_address = ? OR identifier = ?) AND request_type = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL {$lockoutMins} MINUTE)");
        $stmt->execute([$ip, $identifier, $type]);
        $attempts = (int)$stmt->fetchColumn();

        if ($attempts >= $maxAttempts) {
            $tStmt = $db->prepare("SELECT MIN(attempted_at) FROM recovery_attempts WHERE (ip_address = ? OR identifier = ?) AND request_type = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL {$lockoutMins} MINUTE)");
            $tStmt->execute([$ip, $identifier, $type]);
            $oldest = strtotime($tStmt->fetchColumn() ?: 'now');
            $remaining = max(1, $lockoutSecs - (time() - $oldest));
            return $remaining;
        }
    } catch (Exception $e) {}
    return 0;
}

function record_recovery_attempt($type, $identifier) {
    try {
        $db = get_db();
        $ip = get_client_ip();
        $type = sanitize_text($type, 20);
        $identifier = sanitize_text($identifier, 100);
        $stmt = $db->prepare("INSERT INTO recovery_attempts (ip_address, request_type, identifier, attempted_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$ip, $type, $identifier]);
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
 * User Session & RBAC Helpers
 */
function current_user() {
    enforce_session_timeout();
    if (isset($_SESSION['user_id'])) {
        $userId = (int)$_SESSION['user_id'];
        
        // Periodic verification against live DB to catch instant bans or role updates
        try {
            $db = get_db();
            $stmt = $db->prepare("SELECT id, username, email, role, status, can_manage_docs, must_change_password, password_changed_at, api_token FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $liveUser = $stmt->fetch();

            if (!$liveUser || $liveUser['status'] !== 'active') {
                $_SESSION = [];
                session_destroy();
                header('Location: login.php?msg=account_suspended');
                exit;
            }

            // Check if password rotation policy requires change
            $rotationDays = (int)get_site_setting('password_rotation_days', 0);
            $isExpired = false;
            if ($rotationDays > 0 && !empty($liveUser['password_changed_at'])) {
                $daysSince = (time() - strtotime($liveUser['password_changed_at'])) / 86400;
                if ($daysSince >= $rotationDays) {
                    $isExpired = true;
                }
            }

            // If must change password, redirect to change_password.php
            if (($liveUser['must_change_password'] == 1 || $isExpired)) {
                $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
                if (!in_array($currentPage, ['change_password.php', 'logout.php'])) {
                    header('Location: change_password.php?msg=forced_reset');
                    exit;
                }
            }

            return $liveUser;
        } catch (Exception $e) {
            return [
                'id'                   => $userId,
                'username'             => $_SESSION['username'] ?? '',
                'email'                => $_SESSION['email'] ?? '',
                'role'                 => $_SESSION['role'] ?? 'brewer',
                'status'               => 'active',
                'can_manage_docs'      => 0,
                'must_change_password' => 0,
                'api_token'            => $_SESSION['api_token'] ?? ''
            ];
        }
    }
    return null;
}

function require_login() {
    if (!current_user()) {
        header('Location: login.php?msg=login_required');
        exit;
    }
}

function require_admin() {
    $user = current_user();
    if (!$user || $user['role'] !== 'admin') {
        http_response_code(403);
        die("<!DOCTYPE html><html><head><title>Access Denied</title><style>body{font-family:sans-serif;text-align:center;padding:4rem;background:#f8fafc;color:#0f172a;}.box{background:#fff;padding:2rem;max-width:500px;margin:auto;border-radius:12px;border:1px solid #fee2e2;}</style></head><body><div class='box'><h1 style='color:#dc2626;'>🔒 Administrator Access Required</h1><p style='color:#64748b;margin:1rem 0;'>You must be signed in as a Site Administrator to access this section.</p><p><a href='index.php'>&laquo; Back to Brewer Dashboard</a></p></div></body></html>");
    }
}

function can_manage_documents($user = null) {
    if ($user === null) {
        $user = current_user();
    }
    if (!$user) return false;
    return ($user['role'] === 'admin' || !empty($user['can_manage_docs']));
}

/**
 * Reserved & Disallowed Usernames List
 * System commands, staff titles, URL endpoints, and keywords
 */
function get_reserved_usernames() {
    return [
        // Staff, roles, and administrative
        'admin', 'administrator', 'root', 'owner', 'moderator', 'mod', 'staff',
        'support', 'help', 'billing', 'security', 'official', 'master', 'manager',
        'sysadmin', 'webmaster', 'hostmaster', 'postmaster', 'abuse', 'contact',
        'info', 'team', 'craftbrew', 'guest', 'system', 'superuser', 'operator',
        'tester', 'developer', 'dev', 'service', 'daemon',
        // System routes, actions, and API commands
        'api', 'login', 'logout', 'register', 'install', 'setup', 'config', 'settings',
        'index', 'dashboard', 'recipes', 'batches', 'calculators', 'documents',
        'inventory', 'export', 'import', 'account', 'profile', 'user', 'users',
        'search', 'auth', 'download', 'upload', 'edit', 'delete', 'update', 'status',
        // System keywords & code execution words
        'null', 'undefined', 'void', 'eval', 'exec', 'system', 'passthru', 'shell',
        'test', 'true', 'false', 'localhost', 'anonymous'
    ];
}

/**
 * Username Policy & Governance Validation
 * - Length: 3 to 30 characters
 * - Characters: Alphanumeric, dashes (-), underscores (_)
 * - Disallows commands and company/staff reserved names
 * - Optional enforcement: requires both letters & numbers (disabled by default)
 * - Case-insensitive database uniqueness check
 */
function validate_username($username, &$errorMsg = '', $ignoreUserId = null) {
    $username = trim((string)$username);
    $len = strlen($username);

    if ($len < 3 || $len > 30) {
        $errorMsg = "Username must be between 3 and 30 characters in length.";
        return false;
    }

    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) {
        $errorMsg = "Username may only contain letters, numbers, hyphens (-), and underscores (_).";
        return false;
    }

    // Check reserved names
    $lower = strtolower($username);
    $reserved = get_reserved_usernames();
    if (in_array($lower, $reserved, true)) {
        $errorMsg = "The chosen username is unavailable. Please choose a different username.";
        return false;
    }

    // Optional rule: require both letters AND numbers
    $requireAlphaNum = (bool)get_site_setting('username_require_alphanumeric', 0);
    if ($requireAlphaNum) {
        $hasLetter = (bool)preg_match('/[a-zA-Z]/', $username);
        $hasDigit  = (bool)preg_match('/[0-9]/', $username);
        if (!$hasLetter || !$hasDigit) {
            $errorMsg = "Username policy requires at least one letter and at least one number.";
            return false;
        }
    }

    // Check database uniqueness
    try {
        $db = get_db();
        if ($ignoreUserId) {
            $stmt = $db->prepare("SELECT id FROM users WHERE LOWER(username) = ? AND id != ?");
            $stmt->execute([$lower, (int)$ignoreUserId]);
        } else {
            $stmt = $db->prepare("SELECT id FROM users WHERE LOWER(username) = ?");
            $stmt->execute([$lower]);
        }
        if ($stmt->fetch()) {
            $errorMsg = "The chosen username is unavailable. Please choose a different username.";
            return false;
        }
    } catch (Exception $e) {}

    return true;
}

/**
 * Password Strength Validation
 */
function validate_password_strength($password, &$errorMsg = '') {
    $minLen = (int)get_site_setting('password_min_length', 8);
    $requireComplex = (int)get_site_setting('password_require_complex', 0);

    if (strlen($password) < $minLen) {
        $errorMsg = "Password must be at least {$minLen} characters long.";
        return false;
    }

    if ($requireComplex) {
        if (!preg_match('/[A-Z]/', $password)) {
            $errorMsg = "Password must contain at least one uppercase letter (A-Z).";
            return false;
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errorMsg = "Password must contain at least one lowercase letter (a-z).";
            return false;
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errorMsg = "Password must contain at least one number (0-9).";
            return false;
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errorMsg = "Password must contain at least one special character (!@#$%^&*).";
            return false;
        }
    }

    return true;
}

/**
 * Deep Input Sanitization & Validation Suite
 */
function sanitize_text($input, $maxLength = 1000) {
    if (is_null($input)) return '';
    $clean = trim((string)$input);
    $clean = str_replace(chr(0), '', $clean);
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $clean);
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

/**
 * CSV Formula Injection (CSV Injection) Guard - OWASP A03
 * Prevents Excel/LibreOffice execution of formula commands (=, +, -, @, \t, \r, %)
 */
function sanitize_csv_cell($val) {
    if ($val === null || $val === '') return '';
    $str = (string)$val;
    $firstChar = substr($str, 0, 1);
    if (in_array($firstChar, ['=', '+', '-', '@', "\t", "\r", '%'], true)) {
        return "'" . $str;
    }
    return $str;
}

/**
 * Secure HTTP Header Filename Sanitizer - OWASP A05
 * Prevents CRLF Response Splitting and Header Injection
 */
function sanitize_header_filename($filename) {
    $clean = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
    $clean = str_replace(['..', '/', '\\'], '_', $clean);
    return substr($clean, 0, 100) ?: 'export_data';
}

/**
 * XXE (XML External Entity) Injection Guard - OWASP A03 / A08
 * Disables external entity resolution, DTD loading, and network access in XML parsing
 */
function parse_xml_safely($xmlString, &$errorMsg = '') {
    if (empty($xmlString) || !is_string($xmlString)) {
        $errorMsg = "Empty or invalid XML payload.";
        return null;
    }

    // Limit XML size to prevent memory exhaustion / XML entity expansion bomb
    if (strlen($xmlString) > 10 * 1024 * 1024) { // 10 MB limit
        $errorMsg = "XML payload exceeds maximum permitted size (10 MB).";
        return null;
    }

    // Basic heuristic check for entity declarations to prevent XML bombs early
    if (stripos($xmlString, '<!ENTITY') !== false || stripos($xmlString, '<!DOCTYPE') !== false) {
        // Disable DTD loading
        $xmlString = preg_replace('/<!DOCTYPE[^>]*>/i', '', $xmlString);
    }

    libxml_use_internal_errors(true);
    $flags = LIBXML_NONET | LIBXML_NOBLANKS;
    if (defined('LIBXML_NOENT')) {
        // Ensure entity substitute is off
        $flags = $flags & ~LIBXML_NOENT;
    }

    $xml = @simplexml_load_string($xmlString, 'SimpleXMLElement', $flags);
    if ($xml === false) {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        $errorMsg = !empty($errors) ? trim($errors[0]->message) : "Failed to parse XML.";
        return null;
    }

    return $xml;
}

function log_admin_action($action, $details = '', $targetType = '', $targetId = null) {
    try {
        $admin = current_user();
        if (!$admin) return;
        $db = get_db();
        $ip = get_client_ip();
        $stmt = $db->prepare("INSERT INTO admin_audit_logs (admin_id, action, target_type, target_id, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $admin['id'],
            sanitize_text($action, 100),
            sanitize_text($targetType, 50),
            $targetId ? (int)$targetId : null,
            sanitize_text($details, 2000),
            $ip
        ]);
    } catch (Exception $e) {}
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
        echo json_encode([
            'error'   => 'missing_token',
            'message' => 'Missing API token. Provide header Authorization: Bearer <token>'
        ]);
        exit;
    }

    $db = get_db();
    $stmt = $db->prepare("SELECT id, username, email, role, status, must_change_password, api_token FROM users WHERE api_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user || !hash_equals($user['api_token'] ?? $token, $token)) {
        http_response_code(401);
        echo json_encode([
            'error'   => 'invalid_token',
            'message' => 'Invalid or expired API token'
        ]);
        exit;
    }

    if ($user['status'] !== 'active') {
        http_response_code(403);
        echo json_encode([
            'error'   => 'account_suspended',
            'message' => 'Account is suspended or deactivated. Contact administrator.'
        ]);
        exit;
    }

    if (!empty($user['must_change_password'])) {
        http_response_code(403);
        echo json_encode([
            'error'   => 'password_change_required',
            'message' => 'Your account requires a password reset. Please sign in via web browser to update your password.'
        ]);
        exit;
    }

    return $user;
}
