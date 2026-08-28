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

<!-- KPI Metrics Grid -->
<div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-bottom: 2rem;">
    <div class="card stat-card">
        <div class="stat-val"><?= $totalUsers ?></div>
        <div class="stat-lbl">👥 Registered Users</div>
    </div>
    <div class="card stat-card">
        <div class="stat-val"><?= $activeUsers ?></div>
        <div class="stat-lbl">🔥 Active Brewers (30d)</div>
    </div>
    <div class="card stat-card">
        <div class="stat-val"><?= $totalRecipes ?></div>
        <div class="stat-lbl">📖 Total Recipes</div>
    </div>
    <div class="card stat-card">
        <div class="stat-val"><?= $totalBatches ?></div>
        <div class="stat-lbl">🧪 Batches Brewed</div>
    </div>
    <div class="card stat-card">
        <div class="stat-val"><?= number_format($totalGallons, 1) ?> Gal</div>
        <div class="stat-lbl">🍺 Total Volume Brewed</div>
    </div>
    <div class="card stat-card">
        <div class="stat-val"><?= $blockedIpCount ?></div>
        <div class="stat-lbl">🚫 Active Blocked IPs</div>
    </div>
</div>

<!-- System Status & Quick Actions -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem;">
    <div class="card">
        <h3 class="card-title" style="margin-bottom: 1rem;">⚙️ Platform Security Governance</h3>
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
                <td><strong>Brute-Force Lockout Threshold</strong></td>
                <td><?= (int)get_site_setting('max_login_attempts', 5) ?> attempts / <?= (int)get_site_setting('lockout_minutes', 15) ?> mins</td>
            </tr>
            <tr>
                <td><strong>HTTPS Encrypted Mode</strong></td>
                <td><?= IS_HTTPS ? '<span class="badge badge-primary" style="background:#dcfce7; color:#166534;">Active (SSL/TLS)</span>' : '<span class="badge badge-secondary">HTTP</span>' ?></td>
            </tr>
        </table>
        <div style="margin-top: 1rem;">
            <a href="settings.php" class="btn btn-secondary btn-sm">Edit Security Policies &raquo;</a>
        </div>
    </div>

    <div class="card">
        <h3 class="card-title" style="margin-bottom: 1rem;">⚡ Quick Admin Actions</h3>
        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
            <a href="users.php" class="btn btn-primary" style="text-align: left;">👥 Manage Users &amp; Passwords</a>
            <a href="security.php" class="btn btn-secondary" style="text-align: left;">🛡️ IP Blocklist &amp; Security Logs</a>
            <a href="analytics.php" class="btn btn-secondary" style="text-align: left;">📈 Brewing Demographics &amp; Analytics</a>
            <a href="import.php" class="btn btn-secondary" style="text-align: left;">🚚 Import Legacy Brew Logs &amp; References</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
