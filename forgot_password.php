<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';

require_once __DIR__ . '/includes/EmailService.php';

$pageTitle = "Reset Password - " . APP_NAME;
$activePage = 'login';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    $username = sanitize_text($_POST['username'] ?? '', 100);
    $email    = sanitize_text($_POST['email'] ?? '', 100);

    // Rate limiting check
    $lockout = check_recovery_throttle('password', $username . '|' . $email);
    if ($lockout > 0) {
        $mins = ceil($lockout / 60);
        $error = "Too many password reset requests from your network. Please try again in {$mins} minute(s).";
    } elseif (empty($username) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please provide both your account username and registered email address.";
    } else {
        record_recovery_attempt('password', $username . '|' . $email);

        try {
            $db = get_db();
            $stmt = $db->prepare("SELECT id, username, email, status FROM users WHERE username = ? AND email = ?");
            $stmt->execute([$username, $email]);
            $user = $stmt->fetch();

            if ($user && $user['status'] === 'active') {
                // Generate a cryptographically secure 1-time temporary password (12 characters)
                $tempPassword = bin2hex(random_bytes(6));
                $newHash = password_hash($tempPassword, PASSWORD_DEFAULT);

                $updateStmt = $db->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?");
                $updateStmt->execute([$newHash, $user['id']]);

                // Dispatch notification email via EmailService
                $subject = "Your " . APP_NAME . " Temporary Password";
                $body = "Hello " . $user['username'] . ",\n\nA password reset request was processed for your account.\n\nYour one-time temporary password is: " . $tempPassword . "\n\nYou will be required to choose a new permanent password immediately upon signing in.\n\nLog in here: " . (defined('APP_URL') ? APP_URL : '') . "/login.php\n\nIf you did not request this reset, please notify your administrator immediately.";
                EmailService::send($user['email'], $subject, $body);
            }
        } catch (Exception $e) {}

        // Anti-enumeration: Generic response ALWAYS returned
        $message = "If the username and email match our records, an email with a secure one-time temporary password has been dispatched. Please check your inbox and spam folder.";
    }
}

$csrfToken = generate_csrf_token();
require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width: 450px; margin: 3rem auto;">
    <div class="card">
        <h2 class="card-title" style="text-align: center; margin-bottom: 0.5rem;">🔒 Reset Password</h2>
        <p style="color: var(--text-muted); text-align: center; font-size: 0.9rem; margin-bottom: 1.5rem;">
            Enter your username and email address to receive a secure one-time temporary password.
        </p>

        <?php if (!empty($message)): ?>
            <div style="background: #dcfce7; color: #166534; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #bbf7d0; font-size: 0.95rem;">
                <?= e($message) ?>
            </div>
            <p style="text-align: center;">
                <a href="login.php" class="btn btn-primary" style="display: block;">Return to Login</a>
            </p>
        <?php else: ?>

            <?php if (!empty($error)): ?>
                <div style="background: #ffe4e6; color: #9f1239; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #fecdd3;">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="forgot_password.php">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                
                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-control" required placeholder="brewer" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="email">Registered Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" required placeholder="brewer@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">Generate Temporary Password</button>
            </form>

            <p style="text-align: center; margin-top: 1.5rem; color: var(--text-muted); font-size: 0.9rem;">
                Remember your password? <a href="login.php" style="color: var(--primary-color);">Back to Login</a>
            </p>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
