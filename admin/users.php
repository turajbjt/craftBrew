<?php
/**
 * Sub-login Management & Audit Logs Portal (users.php)
 * Restricted to Owner Role
 */

$pageTitle = 'Sub-logins & System Audits';
require_once __DIR__ . '/../includes/header.php';
require_role(['owner']); // Strict RBAC Guard

$actionMsg = null;
$errorMsg = null;

// Handle Sub-login Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = $_POST['role'] ?? 'worker';

    if (!empty($username) && !empty($email) && !empty($password)) {
        if (in_array($role, ['manager', 'auditor', 'worker'], true)) {
            $pdo = Database::getConnection();
            try {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, email, role, status) VALUES (?, ?, ?, ?, 'active')");
                $stmt->execute([$username, $hash, $email, $role]);

                audit_log('create_sublogin', "Created sub-login '$username' with role '$role'");
                $actionMsg = "Sub-login '$username' created successfully with role " . strtoupper($role) . ".";
            } catch (PDOException $e) {
                $errorMsg = "Error creating sub-login: " . ($e->getCode() == 23000 ? "Username already exists." : $e->getMessage());
            }
        } else {
            $errorMsg = "Invalid role selected.";
        }
    } else {
        $errorMsg = "Please fill in all required fields.";
    }
}

// Fetch Sub-logins
$pdo = Database::getConnection();
$usersList = $pdo->query("SELECT id, username, email, role, status, created_at FROM users ORDER BY id ASC")->fetchAll();

// Fetch Audit Logs
$auditLogs = $pdo->query("SELECT * FROM audit_logs ORDER BY id DESC LIMIT 50")->fetchAll();
?>

<style>
    .grid-container {
        display: grid;
        grid-template-columns: 1fr 1.5fr;
        gap: 30px;
    }
    @media (max-width: 1000px) {
        .grid-container { grid-template-columns: 1fr; }
    }
    .panel-card {
        background: var(--panel-bg);
        border: 1px solid var(--panel-border);
        border-radius: 16px;
        padding: 26px;
    }
    .panel-title {
        font-family: 'Outfit', sans-serif;
        font-size: 1.2rem;
        font-weight: 600;
        margin-bottom: 20px;
    }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 0.82rem; color: var(--text-muted); margin-bottom: 6px; }
    .form-control {
        width: 100%;
        padding: 10px 14px;
        background: #0f172a;
        border: 1px solid var(--panel-border);
        border-radius: 8px;
        color: white;
        font-size: 0.9rem;
    }
</style>

<div style="margin-bottom: 25px;">
    <h1 style="font-family: 'Outfit', sans-serif; font-size: 1.8rem; font-weight: 700;">Sub-login Management & Audit Logs</h1>
    <p style="color: var(--text-muted);">Restricted Owner panel to provision sub-logins and inspect system security audit records.</p>
</div>

<?php if ($actionMsg): ?>
    <div style="background: rgba(16, 185, 129, 0.2); border: 1px solid #10b981; color: #34d399; padding: 14px; border-radius: 10px; margin-bottom: 20px;">
        ✓ <?= htmlspecialchars($actionMsg) ?>
    </div>
<?php endif; ?>
<?php if ($errorMsg): ?>
    <div style="background: rgba(239, 68, 68, 0.2); border: 1px solid #ef4444; color: #fca5a5; padding: 14px; border-radius: 10px; margin-bottom: 20px;">
        ⚠ <?= htmlspecialchars($errorMsg) ?>
    </div>
<?php endif; ?>

<div class="grid-container">
    <!-- Sub-login Creation Form & Active Logins -->
    <div>
        <div class="panel-card" style="margin-bottom: 25px;">
            <div class="panel-title">➕ Create Sub-Login Account</div>
            <form method="POST" action="/admin/users.php">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" class="form-control" required placeholder="e.g. manager_john">
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" class="form-control" required placeholder="john@example.com">
                </div>
                <div class="form-group">
                    <label>Bcrypt Initial Password</label>
                    <input type="password" name="password" class="form-control" required placeholder="Create secure password">
                </div>
                <div class="form-group">
                    <label>Role Assignment</label>
                    <select name="role" class="form-control" required>
                        <option value="manager">Manager (Full operational access, no user management)</option>
                        <option value="auditor">Auditor (Read-only review & export access)</option>
                        <option value="worker">Worker (View & manage customer profiles)</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; margin-top: 10px;">Create Sub-login</button>
            </form>
        </div>

        <div class="panel-card">
            <div class="panel-title">👥 Active User Logins</div>
            <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--panel-border); text-align: left;">
                        <th style="padding: 8px;">User</th>
                        <th style="padding: 8px;">Role</th>
                        <th style="padding: 8px;">Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usersList as $u): ?>
                        <tr style="border-bottom: 1px solid var(--panel-border);">
                            <td style="padding: 10px 8px;">
                                <div style="font-weight: 600; color: white;"><?= htmlspecialchars($u['username']) ?></div>
                                <div style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($u['email']) ?></div>
                            </td>
                            <td style="padding: 10px 8px;">
                                <span class="role-badge role-<?= htmlspecialchars($u['role']) ?>"><?= htmlspecialchars($u['role']) ?></span>
                            </td>
                            <td style="padding: 10px 8px; color: var(--text-muted); font-size: 0.78rem;">
                                <?= substr($u['created_at'], 0, 10) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- System Audit Logs Stream -->
    <div class="panel-card">
        <div class="panel-title">📜 Security Audit Log Stream</div>
        <div style="max-height: 620px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px;">
            <?php foreach ($auditLogs as $log): ?>
                <div style="background: rgba(15, 23, 42, 0.6); border: 1px solid var(--panel-border); border-radius: 10px; padding: 12px 16px; font-size: 0.85rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px; color: var(--text-muted); font-size: 0.78rem;">
                        <span>👤 <strong style="color: #a5b4fc;"><?= htmlspecialchars($log['username']) ?></strong> (IP: <?= htmlspecialchars($log['ipaddress']) ?>)</span>
                        <span><?= htmlspecialchars($log['timestamp']) ?></span>
                    </div>
                    <div style="font-weight: 600; color: #f1f5f9;"><?= htmlspecialchars($log['action']) ?></div>
                    <?php if (!empty($log['details'])): ?>
                        <div style="color: #94a3b8; font-size: 0.8rem; margin-top: 2px;"><?= htmlspecialchars($log['details']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
