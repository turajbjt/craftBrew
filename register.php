<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';

$pageTitle = "Register - " . APP_NAME;
$activePage = 'register';
$error = '';

if (current_user()) {
    header('Location: index.php');
    exit;
}

$regMode = get_site_setting('registration_mode', 'open');

if ($regMode === 'closed') {
    $error = "Public registration is currently disabled by the site administrator.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $regMode !== 'closed') {
    require_csrf_token();
    
    $username = sanitize_text($_POST['username'] ?? '', 50);
    $email    = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    $valError = '';
    $userValError = '';
    if (empty($username) || !$email || empty($password)) {
        $error = "Please provide a valid username, email address, and password.";
    } elseif (!validate_username($username, $userValError)) {
        $error = $userValError;
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (!validate_password_strength($password, $valError)) {
        $error = $valError;
    } else {
        try {
            $db = get_db();

            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $error = "This email address is already registered.";
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $apiToken = generate_api_token();
                $status = ($regMode === 'invite') ? 'suspended' : 'active';

                $insertStmt = $db->prepare("INSERT INTO users (username, email, password_hash, role, status, api_token) VALUES (?, ?, ?, 'brewer', ?, ?)");
                $insertStmt->execute([$username, $email, $hash, $status, $apiToken]);

                if ($regMode === 'invite') {
                    header('Location: login.php?msg=pending_approval');
                } else {
                    header('Location: login.php?msg=registered');
                }
                exit;
            }
        } catch (Exception $e) {
            $error = "Registration failed. Please try again.";
        }
    }
}

$csrfToken = generate_csrf_token();
require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width: 480px; margin: 2.5rem auto;">
    <div class="card">
        <h2 class="card-title" style="text-align: center; margin-bottom: 1.5rem;">✨ Join CraftBrew Community</h2>

        <?php if ($regMode === 'closed'): ?>
            <div style="background: #fef3c7; color: #92400e; padding: 1.5rem; border-radius: 8px; text-align: center; border: 1px solid #fde68a;">
                <h3 style="margin-bottom: 0.5rem;">Registration Closed</h3>
                <p>Public registration is currently closed. If you already have an account, please <a href="login.php" style="color: var(--primary-color);">log in here</a>.</p>
            </div>
        <?php else: ?>

            <?php if (!empty($error)): ?>
                <div style="background: #ffe4e6; color: #9f1239; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #fecdd3;">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($regMode === 'invite'): ?>
                <div style="background: #eff6ff; color: #1e40af; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #bfdbfe; font-size: 0.85rem;">
                    <strong>Note:</strong> Admin approval is required. New registrations will be reviewed before account activation.
                </div>
            <?php endif; ?>

            <form method="POST" action="register.php">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                
                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-control" required placeholder="craftbrewer" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" required placeholder="brewer@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control" required placeholder="••••••••">
                    <small style="color: var(--text-muted);">
                        Minimum <?= (int)get_site_setting('password_min_length', 8) ?> characters
                        <?= get_site_setting('password_require_complex', 0) ? ' (requires uppercase, lowercase, numbers, and symbols)' : '' ?>
                    </small>
                </div>

                <div class="form-group">
                    <label class="form-label" for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required placeholder="••••••••">
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">Create Free Account</button>
            </form>

            <p style="text-align: center; margin-top: 1.5rem; color: var(--text-muted);">
                Already have an account? <a href="login.php" style="color: var(--primary-color);">Log in here</a>
            </p>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
