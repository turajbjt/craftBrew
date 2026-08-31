<?php
/**
 * API Authentication Endpoints:
 * - POST /api/v1/index.php?route=auth/login
 * - GET  /api/v1/index.php?route=auth/profile
 * - POST /api/v1/index.php?route=auth/token/regenerate
 * - POST /api/v1/index.php?route=auth/logout
 */

$routeAction = $subRoute ?? ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

// Handle Profile Inspection (GET)
if ($method === 'GET' || $routeAction === 'profile') {
    $user = authenticate_api_request();
    $db = get_db();

    // Fetch user statistics
    $recipeCount = (int)$db->query("SELECT COUNT(*) FROM recipes WHERE user_id = " . (int)$user['id'])->fetchColumn();
    $batchCount  = (int)$db->query("SELECT COUNT(*) FROM batches WHERE user_id = " . (int)$user['id'])->fetchColumn();
    $activeCount = (int)$db->query("SELECT COUNT(*) FROM batches WHERE user_id = " . (int)$user['id'] . " AND status IN ('Must Prep', 'Primary', 'Secondary', 'Bottling/Aging')")->fetchColumn();
    $invCount    = (int)$db->query("SELECT COUNT(*) FROM inventory WHERE user_id = " . (int)$user['id'])->fetchColumn();

    echo json_encode([
        'status' => 'success',
        'user'   => [
            'id'                  => (int)$user['id'],
            'username'            => $user['username'],
            'email'               => $user['email'],
            'role'                => $user['role'],
            'status'              => $user['status'],
            'can_manage_docs'     => !empty($user['can_manage_docs']),
            'two_factor_enabled'  => !empty($user['two_factor_enabled']),
            'api_token'           => $user['api_token']
        ],
        'statistics' => [
            'total_recipes'    => $recipeCount,
            'total_batches'    => $batchCount,
            'active_batches'   => $activeCount,
            'inventory_items'  => $invCount
        ]
    ]);
    exit;
}

// Handle Token Regeneration (POST)
if ($routeAction === 'token/regenerate') {
    $user = authenticate_api_request();
    $db = get_db();

    $newToken = generate_api_token();
    $up = $db->prepare("UPDATE users SET api_token = ? WHERE id = ?");
    $up->execute([$newToken, $user['id']]);

    echo json_encode([
        'status'    => 'success',
        'message'   => 'API token regenerated successfully',
        'api_token' => $newToken
    ]);
    exit;
}

// Handle Logout / Token Revocation (POST)
if ($routeAction === 'logout') {
    $user = authenticate_api_request();
    $db = get_db();

    $up = $db->prepare("UPDATE users SET api_token = NULL WHERE id = ?");
    $up->execute([$user['id']]);

    echo json_encode([
        'status'  => 'success',
        'message' => 'API session terminated and token revoked'
    ]);
    exit;
}

// Handle Login (POST)
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
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
$lockoutSeconds = check_login_throttle($username);
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
$stmt = $db->prepare("SELECT id, username, email, password_hash, role, status, can_manage_docs, must_change_password, api_token, two_factor_enabled, two_factor_secret FROM users WHERE username = ? OR email = ?");
$stmt->execute([$username, $username]);
$user = $stmt->fetch();

if ($user && password_verify($password, $user['password_hash'])) {
    clear_failed_login_attempts($username);

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
            'id'              => (int)$user['id'],
            'username'        => $user['username'],
            'email'           => $user['email'],
            'role'            => $user['role'],
            'status'          => $user['status'],
            'can_manage_docs' => !empty($user['can_manage_docs'])
        ],
        'api_token' => $user['api_token']
    ]);
} else {
    record_failed_login_attempt($username);
    http_response_code(401);
    echo json_encode([
        'error'   => 'invalid_credentials',
        'message' => 'Invalid username or password'
    ]);
}
