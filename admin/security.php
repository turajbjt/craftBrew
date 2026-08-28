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

// Handle IP Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf_token();
    $action = $_POST['action'];

    // 1. Block IP
    if ($action === 'block_ip') {
        $ip = trim($_POST['ip_address'] ?? '');
        $reason = sanitize_text($_POST['reason'] ?? 'Administrator block', 255);
        $durationHours = sanitize_int($_POST['duration_hours'] ?? 0);

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $error = "Please provide a valid IPv4 or IPv6 address.";
        } else {
            $expiresAt = ($durationHours > 0) ? date('Y-m-d H:i:s', time() + ($durationHours * 3600)) : null;
            $ins = $db->prepare("INSERT INTO blocked_ips (ip_address, reason, blocked_by_admin_id, expires_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE reason=VALUES(reason), expires_at=VALUES(expires_at)");
            $ins->execute([$ip, $reason, $adminUser['id'], $expiresAt]);

            log_admin_action('block_ip', "Blocked IP {$ip}. Reason: {$reason}" . ($durationHours ? " ({$durationHours}h)" : " (Permanent)"), 'ip');
            $message = "IP address '{$ip}' has been blocked successfully.";
        }
    }

    // 2. Unblock IP
    if ($action === 'unblock_ip') {
        $blockId = sanitize_int($_POST['block_id'] ?? 0);
        $stmt = $db->prepare("SELECT ip_address FROM blocked_ips WHERE id = ?");
        $stmt->execute([$blockId]);
        $targetIp = $stmt->fetchColumn();

        $del = $db->prepare("DELETE FROM blocked_ips WHERE id = ?");
        $del->execute([$blockId]);

        if ($targetIp) {
            log_admin_action('unblock_ip', "Unblocked IP {$targetIp}", 'ip');
        }
        $message = "IP address unblocked successfully.";
    }

    // 3. Clear Login Attempts
    if ($action === 'clear_login_logs') {
        $db->exec("DELETE FROM login_attempts");
        log_admin_action('clear_logs', 'Cleared login failure logs');
        $message = "Login attempt audit logs cleared.";
    }

    // 4. Clear Recovery Logs
    if ($action === 'clear_recovery_logs') {
        $db->exec("DELETE FROM recovery_attempts");
        log_admin_action('clear_logs', 'Cleared recovery request logs');
        $message = "Recovery request audit logs cleared.";
    }

    // 5. Clear Admin Audit Logs
    if ($action === 'clear_admin_logs') {
        $db->exec("DELETE FROM admin_audit_logs");
        log_admin_action('clear_logs', 'Cleared admin activity audit trail');
        $message = "Admin activity audit trail reset.";
    }
}

// Fetch Blocked IPs
$blockedList = $db->query("SELECT b.*, u.username as admin_name FROM blocked_ips b LEFT JOIN users u ON b.blocked_by_admin_id = u.id ORDER BY b.id DESC")->fetchAll();

// Fetch Recent Failed Logins (Top 25)
$failedLogins = $db->query("SELECT * FROM login_attempts ORDER BY attempted_at DESC LIMIT 25")->fetchAll();

// Fetch Recent Recovery Requests (Top 25)
$recoveryLogs = $db->query("SELECT * FROM recovery_attempts ORDER BY attempted_at DESC LIMIT 25")->fetchAll();

// Fetch Admin Audit Trail (Top 50)
$adminLogs = $db->query("SELECT a.*, u.username as admin_name FROM admin_audit_logs a LEFT JOIN users u ON a.admin_id = u.id ORDER BY a.created_at DESC LIMIT 50")->fetchAll();

$csrfToken = generate_csrf_token();
$pageTitle = "Security & IP Firewall - Admin Portal";
$activePage = 'admin';
$adminSubPage = 'security';
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

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
    <!-- Manual Block IP Form -->
    <div class="card">
        <h3 class="card-title" style="margin-bottom: 1rem;">🚫 Block an IP Address</h3>
        <form method="POST" action="security.php">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="block_ip">

            <div class="form-group">
                <label class="form-label">IP Address</label>
                <input type="text" name="ip_address" class="form-control" placeholder="192.168.1.100" required>
            </div>

            <div class="form-group">
                <label class="form-label">Reason / Notes</label>
                <input type="text" name="reason" class="form-control" placeholder="Excessive failed logins or scraping">
            </div>

            <div class="form-group">
                <label class="form-label">Duration</label>
                <select name="duration_hours" class="form-control">
                    <option value="0">Permanent (No expiration)</option>
                    <option value="24">24 Hours</option>
                    <option value="72">3 Days</option>
                    <option value="168">7 Days</option>
                    <option value="720">30 Days</option>
                </select>
            </div>

            <button type="submit" class="btn btn-danger" style="margin-top: 0.5rem; width: 100%;">Add to Blocklist</button>
        </form>
    </div>

    <!-- Active Blocked IPs List -->
    <div class="card" style="padding: 0; overflow-x: auto;">
        <div style="padding: 1.25rem 1.25rem 0.5rem 1.25rem;">
            <h3 class="card-title">🛡️ Active IP Blocklist (<?= count($blockedList) ?>)</h3>
        </div>
        <table class="data-table" style="font-size: 0.85rem;">
            <thead>
                <tr>
                    <th>IP</th>
                    <th>Reason</th>
                    <th>Expires</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($blockedList)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 2rem;">No blocked IP addresses.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($blockedList as $b): ?>
                        <tr>
                            <td><code><?= e($b['ip_address']) ?></code></td>
                            <td><?= e($b['reason'] ?: 'Manual Block') ?></td>
                            <td><?= !empty($b['expires_at']) ? date('M d, H:i', strtotime($b['expires_at'])) : 'Permanent' ?></td>
                            <td style="text-align: right;">
                                <form method="POST" action="security.php" style="margin: 0; display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="unblock_ip">
                                    <input type="hidden" name="block_id" value="<?= (int)$b['id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-sm">Unblock</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Security Audit Streams -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
    <!-- Failed Logins Stream -->
    <div class="card" style="padding: 0; overflow-x: auto;">
        <div style="padding: 1.25rem; display: flex; justify-content: space-between; align-items: center;">
            <h3 class="card-title">⚠️ Failed Login Attempts</h3>
            <?php if (!empty($failedLogins)): ?>
                <form method="POST" action="security.php" onsubmit="return confirm('Clear login audit log?');" style="margin: 0;">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="clear_login_logs">
                    <button type="submit" class="btn btn-secondary btn-sm">Clear Logs</button>
                </form>
            <?php endif; ?>
        </div>
        <table class="data-table" style="font-size: 0.85rem;">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Target User</th>
                    <th>Source IP</th>
                    <th style="text-align: right;">Block</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($failedLogins)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 2rem;">No failed login attempts logged.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($failedLogins as $fl): ?>
                        <tr>
                            <td><?= date('M d, H:i:s', strtotime($fl['attempted_at'])) ?></td>
                            <td><strong><?= e($fl['username']) ?></strong></td>
                            <td><code><?= e($fl['ip_address']) ?></code></td>
                            <td style="text-align: right;">
                                <form method="POST" action="security.php" style="margin: 0; display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="block_ip">
                                    <input type="hidden" name="ip_address" value="<?= e($fl['ip_address']) ?>">
                                    <input type="hidden" name="reason" value="Suspicious failed logins for <?= e($fl['username']) ?>">
                                    <button type="submit" class="btn btn-logout btn-sm">🚫 Block</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Recovery Requests Stream -->
    <div class="card" style="padding: 0; overflow-x: auto;">
        <div style="padding: 1.25rem; display: flex; justify-content: space-between; align-items: center;">
            <h3 class="card-title">🔑 Account Recovery Attempts</h3>
            <?php if (!empty($recoveryLogs)): ?>
                <form method="POST" action="security.php" onsubmit="return confirm('Clear recovery audit log?');" style="margin: 0;">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="clear_recovery_logs">
                    <button type="submit" class="btn btn-secondary btn-sm">Clear Logs</button>
                </form>
            <?php endif; ?>
        </div>
        <table class="data-table" style="font-size: 0.85rem;">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Type</th>
                    <th>Identifier</th>
                    <th>Source IP</th>
                    <th style="text-align: right;">Block</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recoveryLogs)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 2rem;">No recovery requests logged.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recoveryLogs as $rl): ?>
                        <tr>
                            <td><?= date('M d, H:i:s', strtotime($rl['attempted_at'])) ?></td>
                            <td><span class="badge badge-secondary"><?= ucfirst(e($rl['request_type'])) ?></span></td>
                            <td><?= e($rl['identifier']) ?></td>
                            <td><code><?= e($rl['ip_address']) ?></code></td>
                            <td style="text-align: right;">
                                <form method="POST" action="security.php" style="margin: 0; display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="block_ip">
                                    <input type="hidden" name="ip_address" value="<?= e($rl['ip_address']) ?>">
                                    <input type="hidden" name="reason" value="Excessive recovery requests">
                                    <button type="submit" class="btn btn-logout btn-sm">🚫 Block</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Admin Action Audit Trail Table -->
<div class="card" style="padding: 0; overflow-x: auto;">
    <div style="padding: 1.25rem; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h3 class="card-title">👑 Administrator Action Audit Trail</h3>
            <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 0.25rem;">Live security log tracking administrative changes, password resets, and policy modifications.</p>
        </div>
        <?php if (!empty($adminLogs)): ?>
            <form method="POST" action="security.php" onsubmit="return confirm('Clear admin action audit trail?');" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="clear_admin_logs">
                <button type="submit" class="btn btn-secondary btn-sm">Clear Audit Trail</button>
            </form>
        <?php endif; ?>
    </div>
    <table class="data-table" style="font-size: 0.85rem;">
        <thead>
            <tr>
                <th>Timestamp</th>
                <th>Administrator</th>
                <th>Action</th>
                <th>Details</th>
                <th>IP Address</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($adminLogs)): ?>
                <tr>
                    <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 2rem;">No administrator actions recorded yet.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($adminLogs as $al): ?>
                    <tr>
                        <td><?= date('M d, Y H:i:s', strtotime($al['created_at'])) ?></td>
                        <td><strong><?= e($al['admin_name'] ?: 'System') ?></strong></td>
                        <td><span class="badge badge-primary"><?= e($al['action']) ?></span></td>
                        <td><?= e($al['details']) ?></td>
                        <td><code><?= e($al['ip_address']) ?></code></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
