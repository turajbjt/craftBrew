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
$generatedTempPass = null;
$tempPassTargetUser = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf_token();
    $action = $_POST['action'];

    // 1. Add User
    if ($action === 'add_user') {
        $username = sanitize_text($_POST['username'] ?? '', 50);
        $email    = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $role     = validate_enum($_POST['role'] ?? '', ['admin', 'brewer'], 'brewer');
        $canDocs  = !empty($_POST['can_manage_docs']) ? 1 : 0;
        $password = $_POST['password'] ?? '';
        $autoPass = !empty($_POST['auto_password']);

        if (empty($username) || !$email) {
            $error = "Valid username and email are required.";
        } else {
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $error = "Username or email is already registered.";
            } else {
                if ($autoPass || empty($password)) {
                    $password = bin2hex(random_bytes(6)); // 12 chars
                    $generatedTempPass = $password;
                    $tempPassTargetUser = $username;
                }
                $valErr = '';
                if (!$autoPass && !validate_password_strength($password, $valErr)) {
                    $error = $valErr;
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $token = generate_api_token();
                    $ins = $db->prepare("INSERT INTO users (username, email, password_hash, role, status, can_manage_docs, must_change_password, api_token) VALUES (?, ?, ?, ?, 'active', ?, ?, ?)");
                    $ins->execute([$username, $email, $hash, $role, $canDocs, $autoPass ? 1 : 0, $token]);
                    $message = "User '{$username}' created successfully!";
                }
            }
        }
    }

    // 2. Edit User
    if ($action === 'edit_user') {
        $targetId = sanitize_int($_POST['user_id'] ?? 0);
        $email    = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $role     = validate_enum($_POST['role'] ?? '', ['admin', 'brewer'], 'brewer');
        $status   = validate_enum($_POST['status'] ?? '', ['active', 'suspended', 'banned'], 'active');
        $canDocs  = !empty($_POST['can_manage_docs']) ? 1 : 0;

        if ($targetId <= 0 || !$email) {
            $error = "Invalid user ID or email address.";
        } else {
            // Prevent owner from demoting/banning their own account
            if ($targetId === $adminUser['id'] && ($role !== 'admin' || $status !== 'active')) {
                $error = "You cannot demote or suspend your own active administrator account.";
            } else {
                $up = $db->prepare("UPDATE users SET email = ?, role = ?, status = ?, can_manage_docs = ? WHERE id = ?");
                $up->execute([$email, $role, $status, $canDocs, $targetId]);
                $message = "User details updated successfully!";
            }
        }
    }

    // 3. Direct Password Reset
    if ($action === 'direct_password_reset') {
        $targetId = sanitize_int($_POST['user_id'] ?? 0);
        $newPass  = $_POST['new_password'] ?? '';
        $forceNext = !empty($_POST['force_change_next']) ? 1 : 0;

        $valErr = '';
        if ($targetId <= 0 || empty($newPass)) {
            $error = "User ID and new password are required.";
        } elseif (!validate_password_strength($newPass, $valErr)) {
            $error = $valErr;
        } else {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $up = $db->prepare("UPDATE users SET password_hash = ?, must_change_password = ?, password_changed_at = NOW() WHERE id = ?");
            $up->execute([$hash, $forceNext, $targetId]);
            $message = "Password updated directly for user ID #{$targetId}.";
        }
    }

    // 4. Generate Temporary 1-Time Password
    if ($action === 'generate_temp_password') {
        $targetId = sanitize_int($_POST['user_id'] ?? 0);
        $uStmt = $db->prepare("SELECT username FROM users WHERE id = ?");
        $uStmt->execute([$targetId]);
        $targetUsername = $uStmt->fetchColumn();

        if (!$targetUsername) {
            $error = "User not found.";
        } else {
            $tempPass = bin2hex(random_bytes(6));
            $hash = password_hash($tempPass, PASSWORD_DEFAULT);
            $up = $db->prepare("UPDATE users SET password_hash = ?, must_change_password = 1, password_changed_at = NOW() WHERE id = ?");
            $up->execute([$hash, $targetId]);

            $generatedTempPass = $tempPass;
            $tempPassTargetUser = $targetUsername;
            $message = "Temporary 1-time password generated for user '{$targetUsername}'.";
        }
    }

    // 5. Toggle Force Password Reset
    if ($action === 'toggle_force_reset') {
        $targetId = sanitize_int($_POST['user_id'] ?? 0);
        $up = $db->prepare("UPDATE users SET must_change_password = 1 WHERE id = ?");
        $up->execute([$targetId]);
        $message = "User ID #{$targetId} is now flagged to change password upon next login.";
    }

    // 6. Delete User
    if ($action === 'delete_user') {
        $targetId = sanitize_int($_POST['user_id'] ?? 0);
        if ($targetId === $adminUser['id']) {
            $error = "You cannot delete your own account.";
        } else {
            $del = $db->prepare("DELETE FROM users WHERE id = ?");
            $del->execute([$targetId]);
            $message = "User account and associated records deleted.";
        }
    }
}

// Search & Filter Query
$search = sanitize_text($_GET['q'] ?? '', 50);
$roleFilter = validate_enum($_GET['role'] ?? '', ['admin', 'brewer'], '');
$statusFilter = validate_enum($_GET['status'] ?? '', ['active', 'suspended', 'banned'], '');

$sql = "SELECT u.*, 
        (SELECT COUNT(*) FROM recipes r WHERE r.user_id = u.id) as recipe_count,
        (SELECT COUNT(*) FROM batches b WHERE b.user_id = u.id) as batch_count
        FROM users u WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (u.username LIKE ? OR u.email LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if (!empty($roleFilter)) {
    $sql .= " AND u.role = ?";
    $params[] = $roleFilter;
}
if (!empty($statusFilter)) {
    $sql .= " AND u.status = ?";
    $params[] = $statusFilter;
}
$sql .= " ORDER BY u.id ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$userList = $stmt->fetchAll();

$csrfToken = generate_csrf_token();
$pageTitle = "User Management - Admin Portal";
$activePage = 'admin';
$adminSubPage = 'users';
require_once __DIR__ . '/../includes/header.php';
?>

<?php require_once __DIR__ . '/nav.php'; ?>

<!-- Temp Password Display Modal / Alert -->
<?php if ($generatedTempPass): ?>
    <div style="background: #eff6ff; border: 2px solid #3b82f6; border-radius: 8px; padding: 1.25rem; margin-bottom: 1.5rem;">
        <h3 style="color: #1e40af; margin-bottom: 0.5rem;">🔑 Temporary Password Generated for '<?= e($tempPassTargetUser) ?>'</h3>
        <p style="font-size: 0.95rem; margin-bottom: 0.75rem;">
            Provide this temporary password to the user. They will be forced to choose a new permanent password immediately upon login:
        </p>
        <div style="background: #ffffff; padding: 0.75rem 1rem; border-radius: 6px; border: 1px dashed #3b82f6; font-family: monospace; font-size: 1.25rem; font-weight: bold; color: #1e3a8a; display: inline-block;">
            <?= e($generatedTempPass) ?>
        </div>
    </div>
<?php endif; ?>

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

<!-- Search, Filter & Add Bar -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <form method="GET" action="users.php" style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin: 0;">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search username or email..." class="form-control" style="width: 220px;">
        <select name="role" class="form-control" style="width: 120px;">
            <option value="">All Roles</option>
            <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
            <option value="brewer" <?= $roleFilter === 'brewer' ? 'selected' : '' ?>>Brewer</option>
        </select>
        <select name="status" class="form-control" style="width: 130px;">
            <option value="">All Statuses</option>
            <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
            <option value="banned" <?= $statusFilter === 'banned' ? 'selected' : '' ?>>Banned</option>
        </select>
        <button type="submit" class="btn btn-secondary">Filter</button>
        <?php if (!empty($search) || !empty($roleFilter) || !empty($statusFilter)): ?>
            <a href="users.php" class="btn btn-secondary">Clear</a>
        <?php endif; ?>
    </form>

    <button type="button" class="btn btn-primary" onclick="openAddUserModal()">➕ Add New User</button>
</div>

<!-- Users Table -->
<div class="card" style="padding: 0; overflow-x: auto;">
    <table class="data-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>User</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Doc Perms</th>
                <th>Recipes / Batches</th>
                <th>Must Reset</th>
                <th style="text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($userList)): ?>
                <tr>
                    <td colspan="9" style="text-align: center; color: var(--text-muted); padding: 2rem;">No matching user accounts found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($userList as $u): ?>
                    <tr>
                        <td>#<?= (int)$u['id'] ?></td>
                        <td>
                            <strong><?= e($u['username']) ?></strong>
                            <?php if ($u['id'] === $adminUser['id']): ?>
                                <span class="badge badge-primary" style="font-size: 0.7rem;">You</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($u['email']) ?></td>
                        <td>
                            <span class="badge <?= $u['role'] === 'admin' ? 'badge-primary' : 'badge-secondary' ?>">
                                <?= ucfirst(e($u['role'])) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($u['status'] === 'active'): ?>
                                <span class="badge" style="background:#dcfce7; color:#166534;">Active</span>
                            <?php elseif ($u['status'] === 'suspended'): ?>
                                <span class="badge" style="background:#fef3c7; color:#92400e;">Suspended</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Banned</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= ($u['role'] === 'admin' || !empty($u['can_manage_docs'])) ? '✓ Yes' : '<span style="color:var(--text-muted);">-</span>' ?>
                        </td>
                        <td><?= (int)$u['recipe_count'] ?> / <?= (int)$u['batch_count'] ?></td>
                        <td>
                            <?= !empty($u['must_change_password']) ? '<span style="color:#d97706; font-weight:bold;">Yes</span>' : '<span style="color:var(--text-muted);">No</span>' ?>
                        </td>
                        <td style="text-align: right; white-space: nowrap;">
                            <div style="display: inline-flex; gap: 0.25rem;">
                                <button type="button" class="btn btn-secondary btn-sm" onclick='openEditModal(<?= json_encode($u) ?>)'>Edit</button>
                                <button type="button" class="btn btn-secondary btn-sm" onclick='openPasswordModal(<?= (int)$u["id"] ?>, "<?= e($u["username"]) ?>")'>Pass</button>
                                <?php if ($u['id'] !== $adminUser['id']): ?>
                                    <form method="POST" action="users.php" onsubmit="return confirm('Permanently delete user <?= e($u['username']) ?>?');" style="margin: 0; display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" class="btn btn-logout btn-sm">Del</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Add User Modal -->
<div id="addUserModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 1000; padding: 1rem;">
    <div style="background: #ffffff; width: 100%; max-width: 500px; padding: 1.5rem; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
        <h3 style="margin-bottom: 1rem;">➕ Add New User</h3>
        <form method="POST" action="users.php">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="add_user">

            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control" required placeholder="newbrewer">
            </div>

            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" required placeholder="user@example.com">
            </div>

            <div class="form-row">
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Role</label>
                    <select name="role" class="form-control">
                        <option value="brewer">Brewer</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Custom Password</label>
                    <input type="password" name="password" class="form-control" placeholder="Leave blank to auto-generate">
                </div>
            </div>

            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.9rem;">
                    <input type="checkbox" name="auto_password" value="1" checked>
                    <span>Auto-generate secure temporary password &amp; require change on 1st login</span>
                </label>
            </div>

            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.9rem;">
                    <input type="checkbox" name="can_manage_docs" value="1">
                    <span>Grant Document Library management &amp; upload permissions</span>
                </label>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addUserModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 1000; padding: 1rem;">
    <div style="background: #ffffff; width: 100%; max-width: 500px; padding: 1.5rem; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
        <h3 style="margin-bottom: 1rem;">✏️ Edit User: <span id="editModalUsername"></span></h3>
        <form method="POST" action="users.php">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="editUserId">

            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" id="editEmail" class="form-control" required>
            </div>

            <div class="form-row">
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Role</label>
                    <select name="role" id="editRole" class="form-control">
                        <option value="brewer">Brewer</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>
                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Account Status</label>
                    <select name="status" id="editStatus" class="form-control">
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                        <option value="banned">Banned</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.9rem;">
                    <input type="checkbox" name="can_manage_docs" id="editCanDocs" value="1">
                    <span>Grant Document Library management permissions</span>
                </label>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.5rem;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editUserModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Password Management Modal -->
<div id="passUserModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 1000; padding: 1rem;">
    <div style="background: #ffffff; width: 100%; max-width: 500px; padding: 1.5rem; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
        <h3 style="margin-bottom: 1rem;">🔑 Password Operations: <span id="passModalUsername"></span></h3>
        
        <!-- Direct Change -->
        <form method="POST" action="users.php" style="margin-bottom: 1.5rem; border-bottom: 1px solid var(--border); padding-bottom: 1rem;">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="direct_password_reset">
            <input type="hidden" name="user_id" id="passUserIdDirect">

            <label class="form-label">Set New Password Directly</label>
            <div style="display: flex; gap: 0.5rem;">
                <input type="password" name="new_password" class="form-control" required placeholder="New password">
                <button type="submit" class="btn btn-primary" style="white-space: nowrap;">Change Password</button>
            </div>
            <label style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.5rem; font-size: 0.85rem;">
                <input type="checkbox" name="force_change_next" value="1">
                <span>Force user to change this password upon next sign-in</span>
            </label>
        </form>

        <!-- Quick 1-Time Temp Pass or Force Reset -->
        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
            <form method="POST" action="users.php" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="generate_temp_password">
                <input type="hidden" name="user_id" id="passUserIdTemp">
                <button type="submit" class="btn btn-secondary" style="width: 100%; text-align: left;">
                    ⚡ Generate Instant 1-Time Temporary Password
                </button>
            </form>

            <form method="POST" action="users.php" style="margin: 0;">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="toggle_force_reset">
                <input type="hidden" name="user_id" id="passUserIdForce">
                <button type="submit" class="btn btn-secondary" style="width: 100%; text-align: left;">
                    🔄 Flag Account for Mandatory Password Reset on Next Login
                </button>
            </form>
        </div>

        <div style="text-align: right; margin-top: 1.5rem;">
            <button type="button" class="btn btn-secondary" onclick="closeModal('passUserModal')">Close</button>
        </div>
    </div>
</div>

<script>
function openAddUserModal() {
    document.getElementById('addUserModal').style.display = 'flex';
}
function openEditModal(user) {
    document.getElementById('editUserId').value = user.id;
    document.getElementById('editModalUsername').textContent = user.username;
    document.getElementById('editEmail').value = user.email;
    document.getElementById('editRole').value = user.role;
    document.getElementById('editStatus').value = user.status;
    document.getElementById('editCanDocs').checked = (user.can_manage_docs == 1);
    document.getElementById('editUserModal').style.display = 'flex';
}
function openPasswordModal(userId, username) {
    document.getElementById('passModalUsername').textContent = username;
    document.getElementById('passUserIdDirect').value = userId;
    document.getElementById('passUserIdTemp').value = userId;
    document.getElementById('passUserIdForce').value = userId;
    document.getElementById('passUserModal').style.display = 'flex';
}
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
