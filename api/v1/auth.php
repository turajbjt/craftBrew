<?php
/**
 * API Authentication Endpoint: POST /api/v1/index.php?route=auth/login
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
    echo json_encode(['error' => 'Username and password are required']);
    exit;
}

$db = get_db();
$stmt = $db->prepare("SELECT id, username, email, password_hash, role, api_token FROM users WHERE username = ? OR email = ?");
$stmt->execute([$username, $username]);
$user = $stmt->fetch();

if ($user && password_verify($password, $user['password_hash'])) {
    if (empty($user['api_token'])) {
        $token = generate_api_token();
        $up = $db->prepare("UPDATE users SET api_token = ? WHERE id = ?");
        $up->execute([$token, $user['id']]);
        $user['api_token'] = $token;
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Authenticated successfully',
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role']
        ],
        'api_token' => $user['api_token']
    ]);
} else {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid username or password']);
}
