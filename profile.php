<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/TotpService.php';

require_login();
$user = current_user();
$db = get_db();

// Refetch fresh user data including 2FA fields
$uStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$uStmt->execute([$user['id']]);
$user = $uStmt->fetch();

$pageTitle = "My Profile & Settings - " . APP_NAME;
$activePage = 'profile';
$message = '';
$error = '';
$setup2faSecret = '';
$newBackupCodes = null;

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf_token();
    $action = $_POST['action'];

    // 1. Update Email
    if ($action === 'update_email') {
        $newEmail    = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $currentPass = $_POST['current_password'] ?? '';

        if (!$newEmail) {
            $error = "Please provide a valid email address.";
        } elseif (!password_verify($currentPass, $user['password_hash'])) {
            $error = "Incorrect current password. Password confirmation is required to update your email.";
        } else {
            $chk = $db->prepare("SELECT id FROM users WHERE LOWER(email) = ? AND id != ?");
            $chk->execute([strtolower($newEmail), $user['id']]);
            if ($chk->fetch()) {
                $error = "This email address is already in use by another account.";
            } else {
                $up = $db->prepare("UPDATE users SET email = ? WHERE id = ?");
                $up->execute([$newEmail, $user['id']]);
                $_SESSION['email'] = $newEmail;
                $user['email'] = $newEmail;
                $message = "Your email address has been updated successfully!";
            }
        }
    }

    // 2. Regenerate API Token
    if ($action === 'regenerate_token') {
        $newToken = generate_api_token();
        $up = $db->prepare("UPDATE users SET api_token = ? WHERE id = ?");
        $up->execute([$newToken, $user['id']]);
        $_SESSION['api_token'] = $newToken;
        $user['api_token'] = $newToken;
        $message = "Your REST API token has been regenerated. Update your companion mobile apps with the new token.";
    }

    // 3. Initiate 2FA Setup
    if ($action === 'init_2fa') {
        $setup2faSecret = TotpService::generateSecret(16);
        $_SESSION['pending_2fa_secret'] = $setup2faSecret;
        $_SESSION['pending_2fa_secret_ts'] = time();
    }

    // 4. Confirm & Enable 2FA
    if ($action === 'confirm_2fa') {
        $code = trim($_POST['totp_code'] ?? '');
        $secret = $_SESSION['pending_2fa_secret'] ?? '';
        $secretTs = (int)($_SESSION['pending_2fa_secret_ts'] ?? 0);

        if (empty($secret) || (time() - $secretTs) > 900) {
            unset($_SESSION['pending_2fa_secret'], $_SESSION['pending_2fa_secret_ts']);
            $error = "2FA setup session expired (15-minute limit). Please initiate setup again.";
        } elseif (!TotpService::verifyCode($secret, $code)) {
            $error = "Invalid 6-digit authentication code. Please ensure your authenticator app is synced and try again.";
            $setup2faSecret = $secret;
        } else {
            $backupCodes = TotpService::generateBackupCodes(8);
            $up = $db->prepare("UPDATE users SET two_factor_secret = ?, two_factor_enabled = 1, two_factor_backup_codes = ? WHERE id = ?");
            $up->execute([$secret, json_encode($backupCodes), $user['id']]);
            
            unset($_SESSION['pending_2fa_secret'], $_SESSION['pending_2fa_secret_ts']);
            $user['two_factor_enabled'] = 1;
            $user['two_factor_secret'] = $secret;
            $newBackupCodes = $backupCodes;
            $message = "Two-Factor Authentication (2FA) is now active on your account! Save your backup recovery codes below.";
            
            if ($user['role'] === 'admin') {
                log_admin_action('enable_2fa', "Enabled Two-Factor Authentication on admin account", 'user', $user['id']);
            }
        }
    }

    // 5. Disable 2FA
    if ($action === 'disable_2fa') {
        $currentPass = $_POST['current_password'] ?? '';
        $code = trim($_POST['disable_code'] ?? '');

        $codeValid = TotpService::verifyCode($user['two_factor_secret'] ?? '', $code) || TotpService::verifyAndConsumeBackupCode($user['id'], $code);

        if (!password_verify($currentPass, $user['password_hash'])) {
            $error = "Incorrect password. Password confirmation is required to disable 2FA.";
        } elseif (!$codeValid) {
            $error = "Invalid 2FA code or backup code. Security code required to disable 2FA.";
        } else {
            $up = $db->prepare("UPDATE users SET two_factor_secret = NULL, two_factor_enabled = 0, two_factor_backup_codes = NULL WHERE id = ?");
            $up->execute([$user['id']]);
            $user['two_factor_enabled'] = 0;
            $user['two_factor_secret'] = null;
            $message = "Two-Factor Authentication has been disabled.";

            if ($user['role'] === 'admin') {
                log_admin_action('disable_2fa', "Disabled Two-Factor Authentication on admin account", 'user', $user['id']);
            }
        }
    }

    // 6. Regenerate Backup Codes
    if ($action === 'regen_backup_codes') {
        $currentPass = $_POST['current_password'] ?? '';
        if (!password_verify($currentPass, $user['password_hash'])) {
            $error = "Incorrect password. Password verification is required to regenerate backup codes.";
        } else {
            $backupCodes = TotpService::generateBackupCodes(8);
            $up = $db->prepare("UPDATE users SET two_factor_backup_codes = ? WHERE id = ?");
            $up->execute([json_encode($backupCodes), $user['id']]);
            $newBackupCodes = $backupCodes;
            $message = "New emergency backup recovery codes have been generated. Old codes are now invalid.";
        }
    }
}

// Fetch live user stats
$recipeCount = (int)$db->query("SELECT COUNT(*) FROM recipes WHERE user_id = " . (int)$user['id'])->fetchColumn();
$batchCount  = (int)$db->query("SELECT COUNT(*) FROM batches WHERE user_id = " . (int)$user['id'])->fetchColumn();

$has2fa = !empty($user['two_factor_enabled']);
$csrfToken = generate_csrf_token();
require_once __DIR__ . '/includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1>👤 My Account &amp; Brewer Profile</h1>
        <p style="color: var(--text-muted);">Manage your personal credentials, Two-Factor Authentication, and mobile API access.</p>
    </div>
    <a href="index.php" class="btn btn-secondary">&laquo; Back to Dashboard</a>
</div>

<?php if (!empty($message)): ?>
    <div style="background: #dcfce7; color: #166534; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #bbf7d0;">
        <?= e($message) ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div style="background: #ffe4e6; color: #9f1239; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #fecdd3;">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<!-- Emergency Backup Codes Alert Modal/Box -->
<?php if (!empty($newBackupCodes)): ?>
    <div style="background: #f0fdf4; border: 2px solid #22c55e; border-radius: 12px; padding: 1.5rem; margin-bottom: 2rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
        <h3 style="color: #166534; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
            🛡️ Your Emergency 2FA Backup Recovery Codes
        </h3>
        <p style="color: #15803d; font-size: 0.9rem; margin-bottom: 1rem;">
            Store these <strong>one-time backup codes</strong> in a secure password manager or safe place. If you ever lose your authenticator device, you can use these codes to regain access to your account.
        </p>
        <div id="backupCodesBox" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 0.5rem; background: #ffffff; padding: 1rem; border-radius: 8px; border: 1px solid #bbf7d0; font-family: monospace; font-size: 1.1rem; font-weight: 700; color: #0f172a; margin-bottom: 1rem;">
            <?php foreach ($newBackupCodes as $c): ?>
                <div style="background: #f8fafc; padding: 0.35rem 0.5rem; border-radius: 4px; text-align: center; border: 1px dashed #cbd5e1;"><?= e($c) ?></div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-primary btn-sm" onclick="copyBackupCodes()">📋 Copy All Codes</button>
    </div>

    <script>
    function copyBackupCodes() {
        const codes = <?= json_encode($newBackupCodes) ?>.join('\n');
        navigator.clipboard.writeText(codes).then(() => {
            alert('Backup recovery codes copied to clipboard!');
        });
    }
    </script>
<?php endif; ?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
    <!-- Account Information & Email -->
    <div class="card">
        <h3 class="card-title" style="margin-bottom: 1.25rem;">📋 Account Details</h3>
        <table class="data-table" style="font-size: 0.9rem; margin-bottom: 1.5rem;">
            <tr>
                <td><strong>Username</strong></td>
                <td><code><?= e($user['username']) ?></code></td>
            </tr>
            <tr>
                <td><strong>Role</strong></td>
                <td><span class="badge <?= $user['role'] === 'admin' ? 'badge-primary' : 'badge-secondary' ?>"><?= ucfirst(e($user['role'])) ?></span></td>
            </tr>
            <tr>
                <td><strong>Account Status</strong></td>
                <td><span class="badge" style="background:#dcfce7; color:#166534;"><?= ucfirst(e($user['status'])) ?></span></td>
            </tr>
            <tr>
                <td><strong>Recipes Formulated</strong></td>
                <td><?= (int)$recipeCount ?> recipes</td>
            </tr>
            <tr>
                <td><strong>Batches Logged</strong></td>
                <td><?= (int)$batchCount ?> brew logs</td>
            </tr>
            <tr>
                <td><strong>Password Last Changed</strong></td>
                <td><?= !empty($user['password_changed_at']) ? date('M d, Y H:i', strtotime($user['password_changed_at'])) : 'Initial setup' ?></td>
            </tr>
        </table>

        <h4 style="margin-bottom: 0.75rem; font-size: 1rem;">Update Registered Email</h4>
        <form method="POST" action="profile.php">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="update_email">

            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" value="<?= e($user['email']) ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Current Password (Required to confirm)</label>
                <input type="password" name="current_password" class="form-control" placeholder="••••••••" required>
            </div>

            <button type="submit" class="btn btn-primary btn-sm">Save Email Address</button>
        </form>

        <div style="margin-top: 1.5rem; border-top: 1px solid var(--border); padding-top: 1rem;">
            <a href="change_password.php" class="btn btn-secondary btn-sm">🔑 Change Account Password &raquo;</a>
        </div>
    </div>

    <!-- Two-Factor Authentication (2FA) -->
    <div class="card">
        <h3 class="card-title" style="margin-bottom: 1rem;">🔐 Two-Factor Authentication (2FA)</h3>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.25rem; line-height: 1.5;">
            Add an extra layer of security using standard TOTP authenticator apps (Google Authenticator, Microsoft Authenticator, 1Password, Authy, Bitwarden).
        </p>

        <?php if ($has2fa): ?>
            <div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 1rem; margin-bottom: 1.25rem;">
                <div style="display: flex; align-items: center; gap: 0.5rem; color: #166534; font-weight: 700;">
                    <span>✅ Two-Factor Authentication is Active</span>
                </div>
                <p style="color: #15803d; font-size: 0.85rem; margin-top: 0.35rem;">Your account requires a 6-digit authenticator code on each login.</p>
            </div>

            <!-- Regenerate Backup Codes -->
            <div style="margin-bottom: 1.25rem; background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; padding: 1rem;">
                <h4 style="font-size: 0.9rem; margin-bottom: 0.5rem;">🔑 Emergency Recovery Codes</h4>
                <form method="POST" action="profile.php" onsubmit="return confirm('Regenerating codes will invalidate your existing backup codes. Continue?');">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="regen_backup_codes">
                    <div class="form-group" style="margin-bottom: 0.5rem;">
                        <input type="password" name="current_password" class="form-control" placeholder="Current Password" required style="font-size: 0.85rem;">
                    </div>
                    <button type="submit" class="btn btn-secondary btn-sm">Generate New Backup Codes</button>
                </form>
            </div>

            <!-- Disable 2FA Form -->
            <div style="background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px; padding: 1rem;">
                <h4 style="font-size: 0.9rem; margin-bottom: 0.5rem; color: #9f1239;">Disable Two-Factor Authentication</h4>
                <form method="POST" action="profile.php" onsubmit="return confirm('Are you sure you want to disable 2FA protection on your account?');">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="disable_2fa">
                    <div class="form-row" style="margin-bottom: 0.5rem;">
                        <div class="form-group" style="flex: 1;">
                            <input type="password" name="current_password" class="form-control" placeholder="Current Password" required style="font-size: 0.85rem;">
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <input type="text" name="disable_code" class="form-control" placeholder="6-digit Code" required style="font-size: 0.85rem;">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-logout btn-sm">Disable 2FA</button>
                </form>
            </div>

        <?php elseif (!empty($setup2faSecret)): ?>
            <!-- 2FA Enrollment Step 2: Scan QR & Confirm -->
            <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; padding: 1.25rem;">
                <h4 style="margin-bottom: 0.75rem;">Scan QR Code with Authenticator App</h4>
                
                <?php
                $otpUri = TotpService::getOtpAuthUri($user['username'], $setup2faSecret);
                $qrApiUrl = "https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=" . urlencode($otpUri);
                ?>
                <div style="text-align: center; margin-bottom: 1rem;">
                    <img src="<?= e($qrApiUrl) ?>" alt="2FA QR Code" style="border: 2px solid var(--border); border-radius: 8px; background: white; padding: 0.5rem;">
                </div>

                <div class="form-group">
                    <label class="form-label" style="font-size: 0.8rem;">Manual Secret Key</label>
                    <input type="text" value="<?= e($setup2faSecret) ?>" readonly class="form-control" style="font-family: monospace; font-weight: 700; text-align: center; background: #ffffff;">
                </div>

                <form method="POST" action="profile.php">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="confirm_2fa">
                    <div class="form-group">
                        <label class="form-label">Enter 6-Digit Code from App</label>
                        <input type="text" name="totp_code" class="form-control" placeholder="123456" maxlength="6" pattern="[0-9]{6}" required style="font-size: 1.25rem; letter-spacing: 4px; text-align: center;" autofocus>
                    </div>
                    <div style="display: flex; gap: 0.5rem;">
                        <a href="profile.php" class="btn btn-secondary btn-sm" style="flex: 1; text-align: center;">Cancel</a>
                        <button type="submit" class="btn btn-primary btn-sm" style="flex: 2;">Confirm &amp; Enable 2FA</button>
                    </div>
                </form>
            </div>

        <?php else: ?>
            <!-- 2FA Enrollment Step 1: Start -->
            <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; padding: 1.25rem;">
                <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1rem;">
                    Protect your brewing formulas and cellar data from unauthorized access by requiring an authenticator code on login.
                </p>
                <form method="POST" action="profile.php">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="init_2fa">
                    <button type="submit" class="btn btn-primary btn-sm">🛡️ Set Up Two-Factor Authentication</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Mobile Companion API Token Card -->
<div class="card" style="margin-bottom: 2rem;">
    <h3 class="card-title" style="margin-bottom: 1rem;">📱 Companion App REST API Access</h3>
    <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.25rem; line-height: 1.5;">
        Use this personal Bearer API token to connect the CraftBrew Android App or automated sensors to your account.
    </p>

    <div class="form-group">
        <label class="form-label">Personal Bearer API Token</label>
        <div style="display: flex; gap: 0.5rem;">
            <input type="text" id="apiTokenField" class="form-control" value="<?= e($user['api_token']) ?>" readonly style="font-family: monospace; font-size: 0.85rem; background: var(--bg);">
            <button type="button" class="btn btn-secondary" onclick="copyApiToken()">📋 Copy</button>
        </div>
        <small style="color: var(--text-muted); display: block; margin-top: 0.35rem;">
            REST Endpoint: <code><?= (defined('APP_URL') ? APP_URL : '') ?>/api/v1/</code>
        </small>
    </div>

    <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; padding: 1rem; margin-top: 1.5rem;">
        <h4 style="font-size: 0.9rem; margin-bottom: 0.5rem; color: #b45309;">⚠️ Lost Device or Leaked Token?</h4>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 0.75rem;">
            Regenerating your token will immediately disconnect any active mobile sessions until you enter the new token.
        </p>
        <form method="POST" action="profile.php" onsubmit="return confirm('Are you sure you want to regenerate your API token? All connected companion apps must be updated.');" style="margin: 0;">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="regenerate_token">
            <button type="submit" class="btn btn-logout btn-sm">🔄 Regenerate API Token</button>
        </form>
    </div>
</div>

<script>
function copyApiToken() {
    const field = document.getElementById('apiTokenField');
    field.select();
    field.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(field.value).then(() => {
        alert('API Token copied to clipboard!');
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
