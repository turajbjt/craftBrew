<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';

require_login();
$user = current_user();
$db = get_db();

$pageTitle = "Change Password - " . APP_NAME;
$activePage = 'profile';
$message = '';
$error = '';

$isForced = (!empty($user['must_change_password']));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $currentPass = $_POST['current_password'] ?? '';
    $newPass     = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    // Fetch user's current password hash
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $currentHash = $stmt->fetchColumn();

    if (!$isForced && !password_verify($currentPass, $currentHash)) {
        $error = "Incorrect current password.";
    } elseif ($newPass !== $confirmPass) {
        $error = "New password and confirmation password do not match.";
    } else {
        $valError = '';
        if (!validate_password_strength($newPass, $valError)) {
            $error = $valError;
        } else {
            $newHash = password_hash($newPass, PASSWORD_DEFAULT);
            $upStmt = $db->prepare("UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = NOW() WHERE id = ?");
            $upStmt->execute([$newHash, $user['id']]);

            // Clear session must_change flag
            $_SESSION['must_change_password'] = 0;

            header('Location: index.php?msg=password_updated');
            exit;
        }
    }
}

$csrfToken = generate_csrf_token();
require_once __DIR__ . '/includes/header.php';
?>

<div style="max-width: 500px; margin: 3rem auto;">
    <div class="card">
        <h2 class="card-title" style="text-align: center; margin-bottom: 0.5rem;">🔑 Change Your Password</h2>
        
        <?php if ($isForced): ?>
            <div style="background: #fef3c7; color: #92400e; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #fde68a; font-size: 0.9rem;">
                <strong>Security Requirement:</strong> You signed in with a temporary or expired password. Please set a new permanent password to continue.
            </div>
        <?php else: ?>
            <p style="color: var(--text-muted); text-align: center; font-size: 0.9rem; margin-bottom: 1.5rem;">
                Choose a strong new password for your account.
            </p>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div style="background: #ffe4e6; color: #9f1239; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #fecdd3;">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="change_password.php">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            
            <?php if (!$isForced): ?>
                <div class="form-group">
                    <label class="form-label" for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" class="form-control" required placeholder="••••••••">
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label class="form-label" for="new_password">New Password</label>
                <input type="password" id="new_password" name="new_password" class="form-control" required placeholder="••••••••">
                <small style="color: var(--text-muted);">
                    Minimum <?= (int)get_site_setting('password_min_length', 8) ?> characters
                    <?= get_site_setting('password_require_complex', 0) ? ' (requires uppercase, lowercase, numbers, and symbols)' : '' ?>
                </small>
            </div>

            <div class="form-group">
                <label class="form-label" for="confirm_password">Confirm New Password</label>
                <input type="password" id="confirm_password" name="confirm_password" class="form-control" required placeholder="••••••••">
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">Update Password</button>
        </form>

        <?php if (!$isForced): ?>
            <p style="text-align: center; margin-top: 1.5rem;">
                <a href="index.php" style="color: var(--text-muted);">&laquo; Cancel and Return to Dashboard</a>
            </p>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
