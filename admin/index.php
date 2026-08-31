<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_login();
require_admin();
$user = current_user();
$db = get_db();

// 1. Fetch KPI Metrics
$totalUsers = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$activeUsers = (int)$db->query("SELECT COUNT(DISTINCT user_id) FROM batches WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
$totalRecipes = (int)$db->query("SELECT COUNT(*) FROM recipes")->fetchColumn();
$totalBatches = (int)$db->query("SELECT COUNT(*) FROM batches")->fetchColumn();
$totalGallons = (float)$db->query("SELECT SUM(batch_size_gal) FROM batches")->fetchColumn() ?: 0.0;
$blockedIpCount = (int)$db->query("SELECT COUNT(*) FROM blocked_ips WHERE expires_at IS NULL OR expires_at > NOW()")->fetchColumn();

// Document storage disk usage
$docUsageBytes = 0;
$docCount = 0;
if (is_dir(DOC_UPLOAD_DIR)) {
    foreach (glob(DOC_UPLOAD_DIR . '*.*') as $file) {
        $docUsageBytes += @filesize($file);
        $docCount++;
    }
}
$docUsageMb = round($docUsageBytes / (1024 * 1024), 2);

// 2. Check for Security Alerts (High Failure / Recovery Thresholds)
$recentFailedLogins = (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
$recentRecoveryReqs = (int)$db->query("SELECT COUNT(*) FROM recovery_attempts WHERE attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();

$suspiciousIps = $db->query("
    SELECT ip_address, COUNT(*) as fail_count 
    FROM login_attempts 
    WHERE attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) 
    GROUP BY ip_address 
    HAVING fail_count >= 5
")->fetchAll();

$suspiciousRecoveryIps = $db->query("
    SELECT ip_address, COUNT(*) as req_count 
    FROM recovery_attempts 
    WHERE attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) 
    GROUP BY ip_address 
    HAVING req_count >= 3
")->fetchAll();

$hasSecurityAlerts = (!empty($suspiciousIps) || !empty($suspiciousRecoveryIps));

$pageTitle = "Admin Overview - " . APP_NAME;
$activePage = 'admin';
$adminSubPage = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<?php require_once __DIR__ . '/nav.php'; ?>

<!-- Threat & Security Alert Banner -->
<?php if ($hasSecurityAlerts): ?>
    <div style="background: #fee2e2; border: 1px solid #f87171; border-left: 6px solid #dc2626; border-radius: 8px; padding: 1.25rem; margin-bottom: 1.75rem;">
        <h3 style="color: #991b1b; display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
            🚨 Security Alert: Elevated Authentication &amp; Recovery Activity Detected
        </h3>
        <p style="color: #7f1d1d; font-size: 0.95rem; margin-bottom: 0.75rem;">
            The system detected high-frequency failed attempts in the last 24 hours. Review these events and consider blocking offending IPs:
        </p>
        <ul style="color: #991b1b; margin-left: 1.5rem; font-size: 0.9rem; margin-bottom: 1rem;">
            <?php foreach ($suspiciousIps as $s): ?>
                <li>IP <code><?= e($s['ip_address']) ?></code> triggered <strong><?= (int)$s['fail_count'] ?></strong> failed login attempts.</li>
            <?php endforeach; ?>
            <?php foreach ($suspiciousRecoveryIps as $sr): ?>
                <li>IP <code><?= e($sr['ip_address']) ?></code> triggered <strong><?= (int)$sr['req_count'] ?></strong> account recovery requests.</li>
            <?php endforeach; ?>
        </ul>
        <a href="security.php" class="btn btn-sm btn-danger" style="display: inline-block;">🛡️ Manage Blocked IPs &amp; View Audit Logs &raquo;</a>
    </div>
<?php endif; ?>

<!-- Consolidated Single-Row KPI Metrics Bar -->
<div class="card" style="display: flex; flex-direction: row; justify-content: space-between; align-items: center; padding: 1rem 1.5rem; margin-bottom: 1.75rem; gap: 1rem; overflow-x: auto;">
    <div style="text-align: center; flex: 1; min-width: 100px;">
        <div style="font-size: 1.6rem; font-weight: 800; color: var(--primary-color); line-height: 1.1;"><?= $totalUsers ?></div>
        <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 0.25rem; font-weight: 600;">👥 Users</div>
    </div>
    <div style="border-left: 1px solid var(--border); height: 35px;"></div>
    <div style="text-align: center; flex: 1; min-width: 100px;">
        <div style="font-size: 1.6rem; font-weight: 800; color: #10b981; line-height: 1.1;"><?= $activeUsers ?></div>
        <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 0.25rem; font-weight: 600;">🔥 Active (30d)</div>
    </div>
    <div style="border-left: 1px solid var(--border); height: 35px;"></div>
    <div style="text-align: center; flex: 1; min-width: 100px;">
        <div style="font-size: 1.6rem; font-weight: 800; color: var(--primary-color); line-height: 1.1;"><?= $totalRecipes ?></div>
        <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 0.25rem; font-weight: 600;">📖 Recipes</div>
    </div>
    <div style="border-left: 1px solid var(--border); height: 35px;"></div>
    <div style="text-align: center; flex: 1; min-width: 100px;">
        <div style="font-size: 1.6rem; font-weight: 800; color: var(--primary-color); line-height: 1.1;"><?= $totalBatches ?></div>
        <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 0.25rem; font-weight: 600;">🧪 Batches</div>
    </div>
    <div style="border-left: 1px solid var(--border); height: 35px;"></div>
    <div style="text-align: center; flex: 1; min-width: 110px;">
        <div style="font-size: 1.6rem; font-weight: 800; color: #f59e0b; line-height: 1.1;"><?= number_format($totalGallons, 1) ?> <span style="font-size: 0.85rem; font-weight: normal; color: var(--text-muted);">Gal</span></div>
        <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 0.25rem; font-weight: 600;">🍺 Volume</div>
    </div>
    <div style="border-left: 1px solid var(--border); height: 35px;"></div>
    <div style="text-align: center; flex: 1; min-width: 100px;">
        <div style="font-size: 1.6rem; font-weight: 800; color: <?= $blockedIpCount > 0 ? '#ef4444' : 'var(--text-muted)' ?>; line-height: 1.1;"><?= $blockedIpCount ?></div>
        <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 0.25rem; font-weight: 600;">🚫 Blocked IPs</div>
    </div>
</div>

<!-- System Status & Quick Actions -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem;">
    <div class="card">
        <h3 class="card-title" style="margin-bottom: 1rem;">⚙️ Platform Security &amp; Infrastructure</h3>
        <table class="data-table" style="font-size: 0.9rem;">
            <tr>
                <td><strong>Registration Mode</strong></td>
                <td><span class="badge badge-primary"><?= ucfirst(e(get_site_setting('registration_mode', 'open'))) ?></span></td>
            </tr>
            <tr>
                <td><strong>Password Rotation Policy</strong></td>
                <td><?= (int)get_site_setting('password_rotation_days', 0) > 0 ? (int)get_site_setting('password_rotation_days') . ' Days' : 'Disabled (Never)' ?></td>
            </tr>
            <tr>
                <td><strong>Minimum Password Length</strong></td>
                <td><?= (int)get_site_setting('password_min_length', 8) ?> characters</td>
            </tr>
            <tr>
                <td><strong>Brute-Force Lockout</strong></td>
                <td><?= (int)get_site_setting('max_login_attempts', 5) ?> attempts / <?= (int)get_site_setting('lockout_minutes', 15) ?> mins</td>
            </tr>
            <tr>
                <td><strong>SMTP Mail Relay</strong></td>
                <td><?= get_site_setting('smtp_enabled', 0) ? '<span class="badge" style="background:#dcfce7; color:#166534;">Authenticated SMTP</span>' : '<span class="badge badge-secondary">Internal PHP mail()</span>' ?></td>
            </tr>
            <tr>
                <td><strong>Admin 2FA Requirement</strong></td>
                <td><?= get_site_setting('enforce_admin_2fa', 0) ? '<span class="badge" style="background:#dcfce7; color:#166534;">Enforced</span>' : '<span class="badge badge-secondary">Optional</span>' ?></td>
            </tr>
            <tr>
                <td><strong>Document Storage Disk</strong></td>
                <td><?= $docUsageMb ?> MB (<?= $docCount ?> files, max <?= (int)get_site_setting('max_doc_upload_mb', 25) ?>MB/file)</td>
            </tr>
            <tr>
                <td><strong>HTTPS Encrypted Mode</strong></td>
                <td><?= IS_HTTPS ? '<span class="badge badge-primary" style="background:#dcfce7; color:#166534;">Active (SSL/TLS)</span>' : '<span class="badge badge-secondary">HTTP</span>' ?></td>
            </tr>
        </table>
        <div style="margin-top: 1rem;">
            <a href="settings.php" class="btn btn-secondary btn-sm">Edit Platform Policies &raquo;</a>
        </div>
    </div>

    <div class="card">
        <h3 class="card-title" style="margin-bottom: 1rem;">⚡ Quick Admin Actions</h3>
        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
            <a href="users.php" class="btn btn-primary" style="text-align: left;">👥 Manage Users &amp; Passwords</a>
            <a href="transfer.php" class="btn btn-secondary" style="text-align: left;">🔄 Migrate / Copy Records Between Users</a>
            <a href="backup.php" class="btn btn-secondary" style="text-align: left;">💾 Download Full Database Backup (.sql)</a>
            <a href="security.php" class="btn btn-secondary" style="text-align: left;">🛡️ IP Blocklist &amp; Security Logs</a>
            <a href="analytics.php" class="btn btn-secondary" style="text-align: left;">📈 Brewing Demographics &amp; Analytics</a>
            <a href="import.php" class="btn btn-secondary" style="text-align: left;">🚚 Import Legacy Brew Logs &amp; References</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
