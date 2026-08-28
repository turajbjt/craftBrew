<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/EmailService.php';

$pageTitle = "Forgot Username - " . APP_NAME;
$activePage = 'login';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    $botErr = '';

    if (!verify_bot_trap($botErr)) {
        $error = $botErr;
    } else {
        $email = sanitize_text($_POST['email'] ?? '', 100);

        // Rate limiting check (e.g. max 3 requests per 15 minutes)
        $lockout = check_recovery_throttle('username', $email);
        if ($lockout > 0) {
            $mins = ceil($lockout / 60);
            $error = "Too many recovery attempts from your network. Please try again in {$mins} minute(s).";
        } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            record_recovery_attempt('username', $email);

            try {
                $db = get_db();
                $stmt = $db->prepare("SELECT id, username, email, status FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user && $user['status'] === 'active') {
                    // Dispatch notification email via EmailService
                    $subject = "Your " . APP_NAME . " Username";
                    $body = "Hello,\n\nA username reminder was requested for your account.\n\nYour username is: " . $user['username'] . "\n\nYou can log in here: " . (defined('APP_URL') ? APP_URL : '') . "/login.php\n\nIf you did not request this, please ignore this email.";
                    EmailService::send($user['email'], $subject, $body);
                }
            } catch (Exception $e) {}

            // Anti-enumeration: Generic response ALWAYS returned
            $message = "If an account is associated with that email address, an email containing your username has been sent. Please check your inbox and spam folder.";
        }
    }
}

$csrfToken = generate_csrf_token();
require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width: 450px; margin: 3rem auto;">
    <div class="card">
        <h2 class="card-title" style="text-align: center; margin-bottom: 0.5rem;">🔍 Recover Username</h2>
        <p style="color: var(--text-muted); text-align: center; font-size: 0.9rem; margin-bottom: 1.5rem;">
            Enter your registered email address to receive your account username.
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

            <form method="POST" action="forgot_username.php">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <?= render_bot_trap() ?>
                
                <div class="form-group">
                    <label class="form-label" for="email">Account Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" required placeholder="brewer@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">Send Username Reminder</button>
            </form>

            <p style="text-align: center; margin-top: 1.5rem; color: var(--text-muted); font-size: 0.9rem;">
                Remember your details? <a href="login.php" style="color: var(--primary-color);">Back to Login</a>
            </p>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
