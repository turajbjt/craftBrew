<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/TotpService.php';

$pageTitle = "Login - " . APP_NAME;
$activePage = 'login';
$error = '';

if (current_user()) {
    header('Location: index.php');
    exit;
}

// Handle Cancel 2FA Challenge
if (isset($_GET['cancel_2fa'])) {
    unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_username']);
    header('Location: login.php');
    exit;
}

$is2faPending = !empty($_SESSION['2fa_pending_user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    $botErr = '';

    // Step 2: 2FA Code Verification
    if ($is2faPending) {
        $twoFactorCode = trim($_POST['two_factor_code'] ?? '');
        $pendingUserId = (int)$_SESSION['2fa_pending_user_id'];

        $db = get_db();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$pendingUserId]);
        $user = $stmt->fetch();

        if (!$user) {
            unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_username']);
            $error = "Session expired. Please log in again.";
            $is2faPending = false;
        } else {
            // Check brute-force throttle on 2FA attempts
            $lockoutSeconds = check_login_throttle($user['username']);
            if ($lockoutSeconds > 0) {
                $mins = ceil($lockoutSeconds / 60);
                $error = "Too many failed authentication attempts. Account temporarily locked for security. Please try again in {$mins} minute(s).";
            } else {
                $isValidTotp = TotpService::verifyCode($user['two_factor_secret'] ?? '', $twoFactorCode);
                $isValidBackup = !$isValidTotp && TotpService::verifyAndConsumeBackupCode($user['id'], $twoFactorCode);

                if ($isValidTotp || $isValidBackup) {
                    // Success - clear failed attempts and complete login
                    clear_failed_login_attempts($user['username']);
                    unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_username']);
                    session_regenerate_id(true);

                    if (empty($user['api_token'])) {
                        $token = generate_api_token();
                        $updateStmt = $db->prepare("UPDATE users SET api_token = ? WHERE id = ?");
                        $updateStmt->execute([$token, $user['id']]);
                        $user['api_token'] = $token;
                    }

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['api_token'] = $user['api_token'];
                    $_SESSION['last_activity'] = time();

                    header('Location: index.php');
                    exit;
                } else {
                    record_failed_login_attempt($user['username']);
                    $remaining = check_login_throttle($user['username']);
                    if ($remaining > 0) {
                        $error = "Too many failed attempts. Account locked for 15 minutes.";
                    } else {
                        $error = "Invalid 6-digit authenticator code or backup recovery code.";
                    }
                }
            }
        }
    } else {
        // Step 1: Standard Password Verification
        if (!verify_bot_trap($botErr)) {
            $error = $botErr;
        } else {
            $username = sanitize_text($_POST['username'] ?? '', 100);
            $password = $_POST['password'] ?? '';

            // Check brute-force login throttle
            $lockoutSeconds = check_login_throttle($username);
            if ($lockoutSeconds > 0) {
                $mins = ceil($lockoutSeconds / 60);
                $error = "Too many failed login attempts. Account temporarily locked for security. Please try again in {$mins} minute(s).";
            } elseif (empty($username) || empty($password)) {
                $error = "Please enter both username and password.";
            } else {
                try {
                    $db = get_db();
                    $stmt = $db->prepare("SELECT id, username, email, password_hash, role, status, api_token, two_factor_enabled, two_factor_secret FROM users WHERE username = ? OR email = ?");
                    $stmt->execute([$username, $username]);
                    $user = $stmt->fetch();

                    if ($user && password_verify($password, $user['password_hash'])) {
                        // Clear any recorded failed attempts
                        clear_failed_login_attempts($username);

                        if ($user['status'] !== 'active') {
                            $error = "Your account is suspended or inactive. Please contact the site administrator.";
                        } elseif (!empty($user['two_factor_enabled'])) {
                            // Proceed to Step 2 (2FA Challenge)
                            $_SESSION['2fa_pending_user_id'] = $user['id'];
                            $_SESSION['2fa_pending_username'] = $user['username'];
                            $is2faPending = true;
                        } else {
                            // Standard Direct Login
                            session_regenerate_id(true);

                            if (empty($user['api_token'])) {
                                $token = generate_api_token();
                                $updateStmt = $db->prepare("UPDATE users SET api_token = ? WHERE id = ?");
                                $updateStmt->execute([$token, $user['id']]);
                                $user['api_token'] = $token;
                            }

                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['username'] = $user['username'];
                            $_SESSION['email'] = $user['email'];
                            $_SESSION['role'] = $user['role'];
                            $_SESSION['api_token'] = $user['api_token'];
                            $_SESSION['last_activity'] = time();

                            header('Location: index.php');
                            exit;
                        }
                    } else {
                        record_failed_login_attempt($username);
                        $remaining = check_login_throttle($username);
                        if ($remaining > 0) {
                            $error = "Too many failed login attempts. Account locked for 15 minutes.";
                        } else {
                            $error = "Invalid username/email or password.";
                        }
                    }
                } catch (Exception $e) {
                    $error = "An authentication error occurred. Please try again.";
                }
            }
        }
    }
}

$csrfToken = generate_csrf_token();
require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width: 450px; margin: 3rem auto;">
    <div class="card">
        <?php if ($is2faPending): ?>
            <!-- Step 2: 2FA Challenge Form -->
            <h2 class="card-title" style="text-align: center; margin-bottom: 0.5rem;">🔐 Two-Factor Authentication</h2>
            <p style="color: var(--text-muted); text-align: center; font-size: 0.9rem; margin-bottom: 1.5rem;">
                Enter the 6-digit code from your authenticator app (or a single-use emergency backup code).
            </p>

            <?php if (!empty($error)): ?>
                <div style="background: #ffe4e6; color: #9f1239; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem;">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                
                <div class="form-group">
                    <label class="form-label" for="two_factor_code" style="text-align: center; display: block;">Authentication Code</label>
                    <input type="text" id="two_factor_code" name="two_factor_code" class="form-control" required placeholder="123456 or XXXX-XXXX" autofocus style="font-size: 1.3rem; letter-spacing: 3px; text-align: center; font-family: monospace;">
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">Verify &amp; Sign In</button>
            </form>

            <p style="text-align: center; margin-top: 1.5rem;">
                <a href="login.php?cancel_2fa=1" style="color: var(--text-muted); font-size: 0.85rem;">&laquo; Cancel and sign in as different user</a>
            </p>

        <?php else: ?>
            <!-- Step 1: Standard Credentials Form -->
            <h2 class="card-title" style="text-align: center; margin-bottom: 1.5rem;">🍺 Brewer Login</h2>

            <?php if (!empty($_GET['msg']) && $_GET['msg'] === 'registered'): ?>
                <div style="background: #dcfce7; color: #166534; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem;">
                    Account registered successfully! You can now log in.
                </div>
            <?php endif; ?>

            <?php if (!empty($_GET['msg']) && $_GET['msg'] === 'session_timeout'): ?>
                <div style="background: #fef3c7; color: #92400e; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem;">
                    Your session timed out due to 60 minutes of inactivity. Please log in again.
                </div>
            <?php endif; ?>

            <?php if (!empty($_GET['msg']) && $_GET['msg'] === 'account_suspended'): ?>
                <div style="background: #ffe4e6; color: #9f1239; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #fecdd3;">
                    Your account is suspended or inactive. Please contact the site administrator.
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div style="background: #ffe4e6; color: #9f1239; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem;">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <?= render_bot_trap() ?>
                
                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <label class="form-label" for="username" style="margin-bottom: 0;">Username or Email</label>
                        <a href="forgot_username.php" style="font-size: 0.8rem; color: var(--primary-color);">Forgot Username?</a>
                    </div>
                    <input type="text" id="username" name="username" class="form-control" required placeholder="brewer" autocomplete="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" style="margin-top: 0.35rem;">
                </div>

                <div class="form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <label class="form-label" for="password" style="margin-bottom: 0;">Password</label>
                        <a href="forgot_password.php" style="font-size: 0.8rem; color: var(--primary-color);">Forgot Password?</a>
                    </div>
                    <input type="password" id="password" name="password" class="form-control" required placeholder="••••••••" autocomplete="current-password" style="margin-top: 0.35rem;">
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">Log In</button>
            </form>

            <p style="text-align: center; margin-top: 1.5rem; color: var(--text-muted);">
                Don't have an account? <a href="register.php" style="color: var(--primary-color);">Register here</a>
            </p>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
