<?php
/**
 * API Authentication Endpoint: POST /api/v1/index.php?route=auth/login
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// 1. IP Blocklist check
if (is_ip_blocked()) {
    http_response_code(403);
    echo json_encode([
        'error'   => 'ip_blocked',
        'message' => 'Access blocked from your network.'
    ]);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?: $_POST;

$username = trim($input['username'] ?? '');
$password = trim($input['password'] ?? '');

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode([
        'error'   => 'missing_fields',
        'message' => 'Username and password are required'
    ]);
    exit;
}

// 2. Check Brute-force throttling
$lockoutSeconds = check_brute_force_lockout($username);
if ($lockoutSeconds > 0) {
    $lockoutMins = ceil($lockoutSeconds / 60);
    http_response_code(429);
    echo json_encode([
        'error'   => 'rate_limited',
        'message' => "Too many failed login attempts. Please try again in {$lockoutMins} minute(s)."
    ]);
    exit;
}

require_once __DIR__ . '/../../includes/TotpService.php';

$db = get_db();
$stmt = $db->prepare("SELECT id, username, email, password_hash, role, status, must_change_password, api_token, two_factor_enabled, two_factor_secret FROM users WHERE username = ? OR email = ?");
$stmt->execute([$username, $username]);
$user = $stmt->fetch();

if ($user && password_verify($password, $user['password_hash'])) {
    clear_failed_logins($username);

    // Check account status
    if ($user['status'] !== 'active') {
        http_response_code(403);
        echo json_encode([
            'error'   => 'account_suspended',
            'message' => 'Your account has been suspended or banned. Please contact the administrator.'
        ]);
        exit;
    }

    // Check mandatory password reset
    if (!empty($user['must_change_password'])) {
        http_response_code(403);
        echo json_encode([
            'error'   => 'password_change_required',
            'message' => 'Your account requires an immediate password update. Please sign into the web interface to change your password.'
        ]);
        exit;
    }

    // Enforce Two-Factor Authentication (2FA) if enabled on account
    if (!empty($user['two_factor_enabled'])) {
        $twoFactorCode = trim($input['two_factor_code'] ?? '');
        if (empty($twoFactorCode)) {
            http_response_code(401);
            echo json_encode([
                'error'               => 'two_factor_required',
                'message'             => 'Two-Factor Authentication is enabled on this account. Please provide two_factor_code.',
                'two_factor_required' => true
            ]);
            exit;
        }

        $isValidTotp = TotpService::verifyCode($user['two_factor_secret'] ?? '', $twoFactorCode);
        $isValidBackup = !$isValidTotp && TotpService::verifyAndConsumeBackupCode($user['id'], $twoFactorCode);

        if (!$isValidTotp && !$isValidBackup) {
            http_response_code(401);
            echo json_encode([
                'error'   => 'invalid_2fa_code',
                'message' => 'Invalid 6-digit authenticator code or backup recovery code.'
            ]);
            exit;
        }
    }

    if (empty($user['api_token'])) {
        $token = generate_api_token();
        $up = $db->prepare("UPDATE users SET api_token = ? WHERE id = ?");
        $up->execute([$token, $user['id']]);
        $user['api_token'] = $token;
    }

    echo json_encode([
        'status'  => 'success',
        'message' => 'Authenticated successfully',
        'user' => [
            'id'       => $user['id'],
            'username' => $user['username'],
            'email'    => $user['email'],
            'role'     => $user['role'],
            'status'   => $user['status']
        ],
        'api_token' => $user['api_token']
    ]);
} else {
    record_failed_login($username);
    http_response_code(401);
    echo json_encode([
        'error'   => 'invalid_credentials',
        'message' => 'Invalid username or password'
    ]);
}
