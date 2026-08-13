<?php
/**
 * Sub-login & Owner User Management & Audit Logs Portal (users.php)
 * Restricted to Owner Role
 */

$pageTitle = 'Sub-logins & System Audits';
require_once __DIR__ . '/../includes/header.php';
require_role(['owner']); // Strict RBAC Guard

$actionMsg = null;
$errorMsg = null;

// Handle User Management Actions (Create, Edit, Delete User)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create_sublogin';
    $pdo = Database::getConnection();

    if ($action === 'create_sublogin') {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'worker';

        if (!empty($username) && !empty($email) && !empty($password)) {
            if (in_array($role, ['owner', 'manager', 'auditor', 'worker'], true)) {
                try {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, email, role, status) VALUES (?, ?, ?, ?, 'active')");
                    $stmt->execute([$username, $hash, $email, $role]);

                    audit_log('create_sublogin', "Created user account '$username' with role '$role'");
                    $actionMsg = "User account '$username' created successfully with role " . strtoupper($role) . ".";
                } catch (PDOException $e) {
                    $errorMsg = "Error creating account: " . ($e->getCode() == 23000 ? "Username already exists." : $e->getMessage());
                }
            } else {
                $errorMsg = "Invalid role selected.";
            }
        } else {
            $errorMsg = "Please fill in all required fields.";
        }
    } elseif ($action === 'edit_user') {
        $userId   = (int)($_POST['user_id'] ?? 0);
        $email    = trim($_POST['email'] ?? '');
        $role     = $_POST['role'] ?? 'worker';
        $status   = $_POST['status'] ?? 'active';
        $newPass  = $_POST['password'] ?? '';

        if ($userId > 0 && !empty($email)) {
            $stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmtUser->execute([$userId]);
            $targetUser = $stmtUser->fetch();

            if ($targetUser) {
                if (in_array($role, ['owner', 'manager', 'auditor', 'worker'], true) && in_array($status, ['active', 'disabled'], true)) {
                    try {
                        if (!empty($newPass)) {
                            $hash = password_hash($newPass, PASSWORD_BCRYPT);
                            $stmt = $pdo->prepare("UPDATE users SET email = ?, role = ?, status = ?, password_hash = ? WHERE id = ?");
                            $stmt->execute([$email, $role, $status, $hash, $userId]);
                            audit_log('edit_user', "Updated user '{$targetUser['username']}' email, role, status, and reset password");
                        } else {
                            $stmt = $pdo->prepare("UPDATE users SET email = ?, role = ?, status = ? WHERE id = ?");
                            $stmt->execute([$email, $role, $status, $userId]);
                            audit_log('edit_user', "Updated user '{$targetUser['username']}' profile info (Role: $role, Status: $status)");
                        }
                        $actionMsg = "User profile for '{$targetUser['username']}' updated successfully.";
                    } catch (PDOException $e) {
                        $errorMsg = "Error updating user profile: " . $e->getMessage();
                    }
                } else {
                    $errorMsg = "Invalid role or status provided.";
                }
            } else {
                $errorMsg = "Target user account not found.";
            }
        } else {
            $errorMsg = "Please provide a valid email address.";
        }
    } elseif ($action === 'delete_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $loggedUser = get_logged_user();

        $stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmtUser->execute([$userId]);
        $targetUser = $stmtUser->fetch();

        if ($targetUser) {
            if ($targetUser['username'] === $loggedUser['username']) {
                $errorMsg = "You cannot delete your own active owner account.";
            } else {
                $stmtDel = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmtDel->execute([$userId]);
                audit_log('delete_user', "Deleted user account '{$targetUser['username']}'");
                $actionMsg = "User account '{$targetUser['username']}' deleted successfully.";
            }
        }
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
    .status-badge {
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
    }
    .status-active { background: rgba(16, 185, 129, 0.2); color: #34d399; }
    .status-disabled { background: rgba(239, 68, 68, 0.2); color: #fca5a5; }

    .role-badge {
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 0.72rem;
        font-weight: 600;
        text-transform: capitalize;
    }
    .role-owner { background: rgba(168, 85, 247, 0.2); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.4); }
    .role-manager { background: rgba(59, 130, 246, 0.2); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.4); }
    .role-auditor { background: rgba(245, 158, 11, 0.2); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.4); }
    .role-worker { background: rgba(100, 116, 139, 0.2); color: #94a3b8; border: 1px solid rgba(100, 116, 139, 0.4); }

    .btn-xs {
        padding: 6px 12px;
        font-size: 0.8rem;
        border-radius: 6px;
        cursor: pointer;
        border: none;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .btn-edit { background: #3b82f6 !important; color: #ffffff !important; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
    .btn-edit:hover { background: #2563eb !important; }
    .btn-delete { background: rgba(239, 68, 68, 0.2); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.3); }
    .btn-delete:hover { background: #ef4444; color: white; }

    /* Edit User Modal */
    .modal {
        display: none;
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.75);
        backdrop-filter: blur(8px);
        z-index: 1000;
        align-items: center;
        justify-content: center;
    }
    .modal-content {
        background: #1e293b;
        border: 1px solid var(--panel-border);
        border-radius: 20px;
        padding: 28px;
        max-width: 500px;
        width: 90%;
        color: white;
    }
</style>

<div style="margin-bottom: 25px;">
    <h1 style="font-family: 'Outfit', sans-serif; font-size: 1.8rem; font-weight: 700;">Sub-login Management & Audit Logs</h1>
    <p style="color: var(--text-muted);">Restricted Owner panel to provision sub-logins, edit user profile details, reset passwords, and inspect security audit logs.</p>
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
                <input type="hidden" name="action" value="create_sublogin">
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
                        <option value="owner">Owner (Full system control & user admin)</option>
                        <option value="manager" selected>Manager (Full operational access, no user management)</option>
                        <option value="auditor">Auditor (Read-only review & export access)</option>
                        <option value="worker">Worker (View & manage customer profiles)</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; margin-top: 10px;">Create Account</button>
            </form>
        </div>

        <div class="panel-card">
            <div class="panel-title">👥 Active User Accounts</div>
            <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--panel-border); text-align: left;">
                        <th style="padding: 8px;">User</th>
                        <th style="padding: 8px;">Role & Status</th>
                        <th style="padding: 8px; text-align: right;">Actions</th>
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
                                <div style="display: flex; flex-direction: column; gap: 4px; align-items: flex-start;">
                                    <span class="role-badge role-<?= htmlspecialchars($u['role']) ?>"><?= htmlspecialchars($u['role']) ?></span>
                                    <span class="status-badge status-<?= htmlspecialchars($u['status']) ?>"><?= htmlspecialchars($u['status']) ?></span>
                                </div>
                            </td>
                            <td style="padding: 10px 8px; text-align: right;">
                                <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                    <button type="button" class="btn-xs btn-edit" onclick="openEditUserModal(<?= htmlspecialchars(json_encode($u)) ?>)">✏️ Edit</button>
                                    <?php if ($u['username'] !== $user['username']): ?>
                                        <form method="POST" action="/admin/users.php" style="display: inline;" onsubmit="return confirm('Delete user account \'<?= htmlspecialchars($u['username']) ?>\'?');">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn-xs btn-delete">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
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

<!-- Edit User Profile Modal -->
<div id="editUserModal" class="modal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="margin: 0; font-size: 1.25rem;">✏️ Edit User Profile: <span id="modalUsername" style="color: #60a5fa;"></span></h3>
            <button type="button" onclick="closeEditUserModal()" style="background: none; border: none; color: #94a3b8; font-size: 1.5rem; cursor: pointer;">&times;</button>
        </div>
        <form method="POST" action="/admin/users.php">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="editUserId">

            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" id="editEmail" class="form-control" required>
            </div>

            <div class="form-group">
                <label>Role Assignment</label>
                <select name="role" id="editRole" class="form-control" required>
                    <option value="owner">Owner (Full system control & user admin)</option>
                    <option value="manager">Manager (Full operational access, no user management)</option>
                    <option value="auditor">Auditor (Read-only review & export access)</option>
                    <option value="worker">Worker (View & manage customer profiles)</option>
                </select>
            </div>

            <div class="form-group">
                <label>Account Status</label>
                <select name="status" id="editStatus" class="form-control" required>
                    <option value="active">Active</option>
                    <option value="disabled">Disabled</option>
                </select>
            </div>

            <div class="form-group">
                <label>New Password (leave blank to keep unchanged)</label>
                <input type="password" name="password" class="form-control" placeholder="Enter new password to reset">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 25px;">
                <button type="button" class="btn btn-secondary" onclick="closeEditUserModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditUserModal(user) {
    document.getElementById('editUserId').value = user.id;
    document.getElementById('modalUsername').textContent = user.username;
    document.getElementById('editEmail').value = user.email;
    document.getElementById('editRole').value = user.role;
    document.getElementById('editStatus').value = user.status;
    document.getElementById('editUserModal').style.display = 'flex';
}

function closeEditUserModal() {
    document.getElementById('editUserModal').style.display = 'none';
}

window.onclick = function(event) {
    var modal = document.getElementById('editUserModal');
    if (event.target === modal) {
        closeEditUserModal();
    }
};
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
