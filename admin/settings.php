<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/EmailService.php';

require_login();
require_admin();
$adminUser = current_user();
$db = get_db();

$message = '';
$error = '';
$testEmailLog = '';
$testEmailSuccess = null;

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf_token();
    $action = $_POST['action'];

    // 1. Save Policy & Mail Settings
    if ($action === 'save_settings') {
        $rotationDays     = sanitize_int($_POST['password_rotation_days'] ?? 0);
        $minLen           = max(6, min(32, sanitize_int($_POST['password_min_length'] ?? 8)));
        $requireComplex   = !empty($_POST['password_require_complex']) ? '1' : '0';
        $requireAlphaNum  = !empty($_POST['username_require_alphanumeric']) ? '1' : '0';
        $regMode          = validate_enum($_POST['registration_mode'] ?? '', ['open', 'invite', 'closed'], 'open');
        $maxLogin         = max(3, min(20, sanitize_int($_POST['max_login_attempts'] ?? 5)));
        $lockoutMins      = max(5, min(1440, sanitize_int($_POST['lockout_minutes'] ?? 15)));
        $maxRec           = max(1, min(10, sanitize_int($_POST['max_recovery_attempts'] ?? 3)));
        $recLockoutMins   = max(5, min(1440, sanitize_int($_POST['recovery_lockout_minutes'] ?? 15)));

        // SMTP settings
        $smtpEnabled      = !empty($_POST['smtp_enabled']) ? '1' : '0';
        $smtpHost         = sanitize_text($_POST['smtp_host'] ?? '', 100);
        $smtpPort         = sanitize_int($_POST['smtp_port'] ?? 587);
        $smtpEncryption   = validate_enum($_POST['smtp_encryption'] ?? '', ['tls', 'ssl', 'none'], 'tls');
        $smtpUser         = sanitize_text($_POST['smtp_user'] ?? '', 100);
        $smtpPass         = $_POST['smtp_pass'] ?? '';
        $smtpFromEmail    = filter_var(trim($_POST['smtp_from_email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: '';
        $smtpFromName     = sanitize_text($_POST['smtp_from_name'] ?? APP_NAME, 100);
        $maxDocUploadMb   = max(1, min(500, sanitize_int($_POST['max_doc_upload_mb'] ?? 25)));

        set_site_setting('password_rotation_days', (string)$rotationDays);
        set_site_setting('password_min_length', (string)$minLen);
        set_site_setting('password_require_complex', $requireComplex);
        set_site_setting('username_require_alphanumeric', $requireAlphaNum);
        set_site_setting('registration_mode', $regMode);
        set_site_setting('max_login_attempts', (string)$maxLogin);
        set_site_setting('lockout_minutes', (string)$lockoutMins);
        set_site_setting('max_recovery_attempts', (string)$maxRec);
        set_site_setting('recovery_lockout_minutes', (string)$recLockoutMins);

        set_site_setting('smtp_enabled', $smtpEnabled);
        set_site_setting('smtp_host', $smtpHost);
        set_site_setting('smtp_port', (string)$smtpPort);
        set_site_setting('smtp_encryption', $smtpEncryption);
        set_site_setting('smtp_user', $smtpUser);
        if ($smtpPass !== '') {
            set_site_setting('smtp_pass', $smtpPass);
        }
        set_site_setting('smtp_from_email', $smtpFromEmail);
        set_site_setting('smtp_from_name', $smtpFromName);
        set_site_setting('max_doc_upload_mb', (string)$maxDocUploadMb);

        log_admin_action('update_settings', "Updated platform security, mailer, and storage policies");
        $message = "Security policies, SMTP mailer, and storage settings saved successfully!";
    }

    // 2. Send Test Diagnostic Email
    if ($action === 'send_test_email') {
        $testTo = filter_var(trim($_POST['test_recipient'] ?? ''), FILTER_VALIDATE_EMAIL);
        if (!$testTo) {
            $error = "Please provide a valid test recipient email address.";
        } else {
            $diagLog = '';
            $testEmailSuccess = EmailService::testConnection($testTo, $diagLog);
            $testEmailLog = $diagLog;

            if ($testEmailSuccess) {
                $message = "Test message delivered successfully to {$testTo}!";
                log_admin_action('test_email', "Sent test email to {$testTo} (Success)");
            } else {
                $error = "Failed to dispatch test message. Review the socket diagnostic log below.";
                log_admin_action('test_email', "Failed test email to {$testTo}");
            }
        }
    }
}

// Current values
$currentRotation         = (int)get_site_setting('password_rotation_days', 0);
$currentMinLen           = (int)get_site_setting('password_min_length', 8);
$currentComplex          = (bool)get_site_setting('password_require_complex', 0);
$currentRequireAlphaNum  = (bool)get_site_setting('username_require_alphanumeric', 0);
$currentRegMode          = get_site_setting('registration_mode', 'open');
$currentMaxLogin         = (int)get_site_setting('max_login_attempts', 5);
$currentLockout          = (int)get_site_setting('lockout_minutes', 15);
$currentMaxRec           = (int)get_site_setting('max_recovery_attempts', 3);
$currentRecLockout       = (int)get_site_setting('recovery_lockout_minutes', 15);

$currentSmtpEnabled      = (bool)get_site_setting('smtp_enabled', 0);
$currentSmtpHost         = get_site_setting('smtp_host', '');
$currentSmtpPort         = (int)get_site_setting('smtp_port', 587);
$currentSmtpEncryption   = get_site_setting('smtp_encryption', 'tls');
$currentSmtpUser         = get_site_setting('smtp_user', '');
$currentSmtpFromEmail    = get_site_setting('smtp_from_email', '');
$currentSmtpFromName     = get_site_setting('smtp_from_name', APP_NAME);
$currentMaxDocUploadMb   = (int)get_site_setting('max_doc_upload_mb', 25);

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

<?php if (!empty($error)): ?>
    <div style="background: #ffe4e6; color: #9f1239; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #fecdd3;">
        <?= e($error) ?>
    </div>
<?php endif; ?>

<!-- Test Email Diagnostics Log -->
<?php if (!empty($testEmailLog)): ?>
    <div style="background: <?= $testEmailSuccess ? '#f0fdf4' : '#fef2f2' ?>; border: 1px solid <?= $testEmailSuccess ? '#86efac' : '#fca5a5' ?>; border-radius: 8px; padding: 1.25rem; margin-bottom: 1.5rem;">
        <h4 style="color: <?= $testEmailSuccess ? '#166534' : '#991b1b' ?>; margin-bottom: 0.5rem;">
            <?= $testEmailSuccess ? '✅ SMTP Socket Diagnostic Log' : '❌ SMTP Error Diagnostic Log' ?>
        </h4>
        <pre style="background: #ffffff; padding: 1rem; border-radius: 6px; font-size: 0.8rem; overflow-x: auto; max-height: 250px; border: 1px solid var(--border);"><?= e($testEmailLog) ?></pre>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 1.5rem;">
    <!-- Platform Security Policies -->
    <div class="card">
        <h3 class="card-title" style="margin-bottom: 1.5rem;">🛡️ Authentication &amp; Governance</h3>

        <form method="POST" action="settings.php">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="save_settings">

            <!-- Password Rotation Policy -->
            <div class="form-group" style="border-bottom: 1px solid var(--border); padding-bottom: 1.25rem; margin-bottom: 1.25rem;">
                <label class="form-label" style="font-size: 0.95rem; font-weight: 700;">🔄 Password Rotation Policy</label>
                <select name="password_rotation_days" class="form-control">
                    <option value="0" <?= $currentRotation === 0 ? 'selected' : '' ?>>Disabled (Never Expire)</option>
                    <option value="60" <?= $currentRotation === 60 ? 'selected' : '' ?>>Every 60 Days</option>
                    <option value="90" <?= $currentRotation === 90 ? 'selected' : '' ?>>Every 90 Days</option>
                    <option value="180" <?= $currentRotation === 180 ? 'selected' : '' ?>>Every 180 Days (6 Months)</option>
                    <option value="365" <?= $currentRotation === 365 ? 'selected' : '' ?>>Every 365 Days (1 Year)</option>
                </select>
            </div>

            <!-- Password Complexity Standards -->
            <div class="form-group" style="border-bottom: 1px solid var(--border); padding-bottom: 1.25rem; margin-bottom: 1.25rem;">
                <label class="form-label" style="font-size: 0.95rem; font-weight: 700;">🔒 Password Complexity</label>
                
                <div class="form-group" style="margin-bottom: 0.5rem;">
                    <label class="form-label" style="font-size: 0.85rem;">Minimum Length</label>
                    <input type="number" name="password_min_length" class="form-control" min="6" max="32" value="<?= $currentMinLen ?>">
                </div>

                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.85rem;">
                    <input type="checkbox" name="password_require_complex" value="1" <?= $currentComplex ? 'checked' : '' ?>>
                    <span>Require uppercase, lowercase, numbers, and symbols</span>
                </label>
            </div>

            <!-- Username Policy -->
            <div class="form-group" style="border-bottom: 1px solid var(--border); padding-bottom: 1.25rem; margin-bottom: 1.25rem;">
                <label class="form-label" style="font-size: 0.95rem; font-weight: 700;">🏷️ Username Policy</label>
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.85rem;">
                    <input type="checkbox" name="username_require_alphanumeric" value="1" <?= $currentRequireAlphaNum ? 'checked' : '' ?>>
                    <span>Require both letters (A-Z) and numbers (0-9)</span>
                </label>
            </div>

            <!-- Registration Governance -->
            <div class="form-group" style="border-bottom: 1px solid var(--border); padding-bottom: 1.25rem; margin-bottom: 1.25rem;">
                <label class="form-label" style="font-size: 0.95rem; font-weight: 700;">🚪 User Registration</label>
                <select name="registration_mode" class="form-control">
                    <option value="open" <?= $currentRegMode === 'open' ? 'selected' : '' ?>>Open Public Registration</option>
                    <option value="invite" <?= $currentRegMode === 'invite' ? 'selected' : '' ?>>Invite-Only (Requires Admin Approval)</option>
                    <option value="closed" <?= $currentRegMode === 'closed' ? 'selected' : '' ?>>Closed (Disabled)</option>
                </select>
            </div>

            <!-- Brute-Force & Recovery Throttling -->
            <div class="form-group" style="border-bottom: 1px solid var(--border); padding-bottom: 1.25rem; margin-bottom: 1.25rem;">
                <label class="form-label" style="font-size: 0.95rem; font-weight: 700;">🛡️ Lockout Thresholds</label>
                
                <div class="form-row" style="margin-bottom: 0.5rem;">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label" style="font-size: 0.8rem;">Max Login Fails</label>
                        <input type="number" name="max_login_attempts" class="form-control" min="3" max="20" value="<?= $currentMaxLogin ?>">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label" style="font-size: 0.8rem;">Lockout (Mins)</label>
                        <input type="number" name="lockout_minutes" class="form-control" min="5" max="1440" value="<?= $currentLockout ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label" style="font-size: 0.8rem;">Max Recovery Reqs</label>
                        <input type="number" name="max_recovery_attempts" class="form-control" min="1" max="10" value="<?= $currentMaxRec ?>">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label" style="font-size: 0.8rem;">Recovery Lock (Mins)</label>
                        <input type="number" name="recovery_lockout_minutes" class="form-control" min="5" max="1440" value="<?= $currentRecLockout ?>">
                    </div>
                </div>
            </div>

            <!-- Storage Quota -->
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-size: 0.95rem; font-weight: 700;">📁 Document Upload Size Limit</label>
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <input type="number" name="max_doc_upload_mb" class="form-control" min="1" max="500" value="<?= $currentMaxDocUploadMb ?>" style="max-width: 120px;">
                    <span>MB per file</span>
                </div>
            </div>

            <!-- Hidden SMTP fields to preserve on submit -->
            <input type="hidden" name="smtp_enabled" value="<?= $currentSmtpEnabled ? '1' : '0' ?>">
            <input type="hidden" name="smtp_host" value="<?= e($currentSmtpHost) ?>">
            <input type="hidden" name="smtp_port" value="<?= $currentSmtpPort ?>">
            <input type="hidden" name="smtp_encryption" value="<?= e($currentSmtpEncryption) ?>">
            <input type="hidden" name="smtp_user" value="<?= e($currentSmtpUser) ?>">
            <input type="hidden" name="smtp_from_email" value="<?= e($currentSmtpFromEmail) ?>">
            <input type="hidden" name="smtp_from_name" value="<?= e($currentSmtpFromName) ?>">

            <button type="submit" class="btn btn-primary" style="width: 100%;">Save Security Policies</button>
        </form>
    </div>

    <!-- SMTP Mail Server Configuration -->
    <div class="card">
        <h3 class="card-title" style="margin-bottom: 1rem;">✉️ SMTP Mail Server Settings</h3>
        <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1.25rem; line-height: 1.5;">
            Configure an authenticated SMTP relay (Gmail, SendGrid, Amazon SES, Mailgun, Postmark, or private mail server) to guarantee recovery emails reach inboxes.
        </p>

        <form method="POST" action="settings.php" style="margin-bottom: 1.75rem;">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="save_settings">

            <!-- Hidden policy fields to preserve on submit -->
            <input type="hidden" name="password_rotation_days" value="<?= $currentRotation ?>">
            <input type="hidden" name="password_min_length" value="<?= $currentMinLen ?>">
            <input type="hidden" name="password_require_complex" value="<?= $currentComplex ? '1' : '0' ?>">
            <input type="hidden" name="username_require_alphanumeric" value="<?= $currentRequireAlphaNum ? '1' : '0' ?>">
            <input type="hidden" name="registration_mode" value="<?= e($currentRegMode) ?>">
            <input type="hidden" name="max_login_attempts" value="<?= $currentMaxLogin ?>">
            <input type="hidden" name="lockout_minutes" value="<?= $currentLockout ?>">
            <input type="hidden" name="max_recovery_attempts" value="<?= $currentMaxRec ?>">
            <input type="hidden" name="recovery_lockout_minutes" value="<?= $currentRecLockout ?>">
            <input type="hidden" name="max_doc_upload_mb" value="<?= $currentMaxDocUploadMb ?>">

            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.95rem; font-weight: 700;">
                    <input type="checkbox" name="smtp_enabled" value="1" <?= $currentSmtpEnabled ? 'checked' : '' ?>>
                    <span>Enable Authenticated SMTP Relay</span>
                </label>
                <small style="color: var(--text-muted);">If unchecked, the platform falls back to PHP's internal <code>mail()</code>.</small>
            </div>

            <div class="form-row">
                <div class="form-group" style="flex: 2;">
                    <label class="form-label">SMTP Host / Server</label>
                    <input type="text" name="smtp_host" class="form-control" placeholder="smtp.mailgun.org" value="<?= e($currentSmtpHost) ?>">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Port</label>
                    <input type="number" name="smtp_port" class="form-control" value="<?= $currentSmtpPort ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Encryption</label>
                    <select name="smtp_encryption" class="form-control">
                        <option value="tls" <?= $currentSmtpEncryption === 'tls' ? 'selected' : '' ?>>STARTTLS (Port 587)</option>
                        <option value="ssl" <?= $currentSmtpEncryption === 'ssl' ? 'selected' : '' ?>>SSL / TLS (Port 465)</option>
                        <option value="none" <?= $currentSmtpEncryption === 'none' ? 'selected' : '' ?>>None (Port 25)</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">SMTP Username</label>
                    <input type="text" name="smtp_user" class="form-control" placeholder="apikey or user@domain.com" value="<?= e($currentSmtpUser) ?>">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">SMTP Password</label>
                    <input type="password" name="smtp_pass" class="form-control" placeholder="Leave blank to keep existing">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">From Email Address</label>
                    <input type="email" name="smtp_from_email" class="form-control" placeholder="brewhouse@yourdomain.com" value="<?= e($currentSmtpFromEmail) ?>">
                </div>
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Sender Name</label>
                    <input type="text" name="smtp_from_name" class="form-control" value="<?= e($currentSmtpFromName) ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%;">Save SMTP Configuration</button>
        </form>

        <!-- Test Email Dispatcher -->
        <div style="background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; padding: 1rem;">
            <h4 style="font-size: 0.9rem; margin-bottom: 0.5rem;">🧪 Send Diagnostic Test Email</h4>
            <form method="POST" action="settings.php" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="send_test_email">
                <input type="email" name="test_recipient" class="form-control" placeholder="your-email@example.com" required style="flex: 1; min-width: 200px;">
                <button type="submit" class="btn btn-secondary" style="white-space: nowrap;">Send Test Email</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
