<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_login();
require_admin();
$adminUser = current_user();
$db = get_db();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    require_csrf_token();

    $rotationDays   = sanitize_int($_POST['password_rotation_days'] ?? 0);
    $minLen         = max(6, min(32, sanitize_int($_POST['password_min_length'] ?? 8)));
    $requireComplex = !empty($_POST['password_require_complex']) ? '1' : '0';
    $regMode        = validate_enum($_POST['registration_mode'] ?? '', ['open', 'invite', 'closed'], 'open');
    $maxLogin       = max(3, min(20, sanitize_int($_POST['max_login_attempts'] ?? 5)));
    $lockoutMins    = max(5, min(1440, sanitize_int($_POST['lockout_minutes'] ?? 15)));
    $maxRec         = max(1, min(10, sanitize_int($_POST['max_recovery_attempts'] ?? 3)));
    $recLockoutMins = max(5, min(1440, sanitize_int($_POST['recovery_lockout_minutes'] ?? 15)));

    set_site_setting('password_rotation_days', (string)$rotationDays);
    set_site_setting('password_min_length', (string)$minLen);
    set_site_setting('password_require_complex', $requireComplex);
    set_site_setting('registration_mode', $regMode);
    set_site_setting('max_login_attempts', (string)$maxLogin);
    set_site_setting('lockout_minutes', (string)$lockoutMins);
    set_site_setting('max_recovery_attempts', (string)$maxRec);
    set_site_setting('recovery_lockout_minutes', (string)$recLockoutMins);

    $message = "Security policies and site settings saved successfully!";
}

// Current values
$currentRotation   = (int)get_site_setting('password_rotation_days', 0);
$currentMinLen     = (int)get_site_setting('password_min_length', 8);
$currentComplex    = (bool)get_site_setting('password_require_complex', 0);
$currentRegMode    = get_site_setting('registration_mode', 'open');
$currentMaxLogin   = (int)get_site_setting('max_login_attempts', 5);
$currentLockout    = (int)get_site_setting('lockout_minutes', 15);
$currentMaxRec     = (int)get_site_setting('max_recovery_attempts', 3);
$currentRecLockout = (int)get_site_setting('recovery_lockout_minutes', 15);

$csrfToken = generate_csrf_token();
$pageTitle = "Security & Policy Settings - Admin Portal";
$activePage = 'admin';
$adminSubPage = 'settings';
require_once __DIR__ . '/../includes/header.php';
?>

<?php require_once __DIR__ . '/nav.php'; ?>

<?php if (!empty($message)): ?>
    <div style="background: #dcfce7; color: #166534; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #bbf7d0;">
        <?= e($message) ?>
    </div>
<?php endif; ?>

<div class="card" style="max-width: 680px;">
    <h3 class="card-title" style="margin-bottom: 1.5rem;">⚙️ Platform Security Policies</h3>

    <form method="POST" action="settings.php">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="action" value="save_settings">

        <!-- Password Rotation Policy -->
        <div class="form-group" style="border-bottom: 1px solid var(--border); padding-bottom: 1.25rem; margin-bottom: 1.25rem;">
            <label class="form-label" style="font-size: 1rem; font-weight: 700;">🔄 Password Rotation Policy</label>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 0.5rem;">
                Require all users to change their password periodically. When expired, users are forced to choose a new password upon next sign-in.
            </p>
            <select name="password_rotation_days" class="form-control" style="max-width: 280px;">
                <option value="0" <?= $currentRotation === 0 ? 'selected' : '' ?>>Disabled (Passwords never expire)</option>
                <option value="60" <?= $currentRotation === 60 ? 'selected' : '' ?>>Every 60 Days</option>
                <option value="90" <?= $currentRotation === 90 ? 'selected' : '' ?>>Every 90 Days</option>
                <option value="180" <?= $currentRotation === 180 ? 'selected' : '' ?>>Every 180 Days (6 Months)</option>
                <option value="365" <?= $currentRotation === 365 ? 'selected' : '' ?>>Every 365 Days (1 Year)</option>
            </select>
        </div>

        <!-- Password Complexity Standards -->
        <div class="form-group" style="border-bottom: 1px solid var(--border); padding-bottom: 1.25rem; margin-bottom: 1.25rem;">
            <label class="form-label" style="font-size: 1rem; font-weight: 700;">🔒 Password Complexity Standards</label>
            
            <div class="form-row" style="margin-bottom: 0.75rem;">
                <div class="form-group" style="flex: 0 0 200px;">
                    <label class="form-label">Minimum Length</label>
                    <input type="number" name="password_min_length" class="form-control" min="6" max="32" value="<?= $currentMinLen ?>">
                </div>
            </div>

            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.9rem;">
                <input type="checkbox" name="password_require_complex" value="1" <?= $currentComplex ? 'checked' : '' ?>>
                <span>Enforce mixed-case letters (A-Z, a-z), numbers (0-9), and special characters (!@#$%^&*)</span>
            </label>
        </div>

        <!-- Registration Governance -->
        <div class="form-group" style="border-bottom: 1px solid var(--border); padding-bottom: 1.25rem; margin-bottom: 1.25rem;">
            <label class="form-label" style="font-size: 1rem; font-weight: 700;">🚪 User Registration Governance</label>
            <select name="registration_mode" class="form-control" style="max-width: 320px;">
                <option value="open" <?= $currentRegMode === 'open' ? 'selected' : '' ?>>Open Public Registration</option>
                <option value="invite" <?= $currentRegMode === 'invite' ? 'selected' : '' ?>>Invite-Only / Require Admin Approval</option>
                <option value="closed" <?= $currentRegMode === 'closed' ? 'selected' : '' ?>>Closed (Public registration disabled)</option>
            </select>
        </div>

        <!-- Brute-Force & Recovery Throttling -->
        <div class="form-group" style="margin-bottom: 1.5rem;">
            <label class="form-label" style="font-size: 1rem; font-weight: 700;">🛡️ Brute-Force &amp; Recovery Thresholds</label>
            
            <div class="form-row">
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Max Failed Logins</label>
                    <input type="number" name="max_login_attempts" class="form-control" min="3" max="20" value="<?= $currentMaxLogin ?>">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Login Lockout (Minutes)</label>
                    <input type="number" name="lockout_minutes" class="form-control" min="5" max="1440" value="<?= $currentLockout ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Max Recovery Requests</label>
                    <input type="number" name="max_recovery_attempts" class="form-control" min="1" max="10" value="<?= $currentMaxRec ?>">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Recovery Lockout (Minutes)</label>
                    <input type="number" name="recovery_lockout_minutes" class="form-control" min="5" max="1440" value="<?= $currentRecLockout ?>">
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary" style="padding: 0.75rem 2rem;">Save Security Settings</button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
