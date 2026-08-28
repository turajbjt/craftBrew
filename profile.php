<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';

require_login();
$user = current_user();
$db = get_db();

$pageTitle = "My Profile & Settings - " . APP_NAME;
$activePage = 'profile';
$message = '';
$error = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_csrf_token();
    $action = $_POST['action'];

    // 1. Update Email
    if ($action === 'update_email') {
        $newEmail    = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $currentPass = $_POST['current_password'] ?? '';

        // Fetch current password hash
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $currentHash = $stmt->fetchColumn();

        if (!$newEmail) {
            $error = "Please provide a valid email address.";
        } elseif (!password_verify($currentPass, $currentHash)) {
            $error = "Incorrect current password. Password confirmation is required to update your email.";
        } else {
            // Check if email taken by another user
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
}

// Fetch live user stats
$recipeCount = (int)$db->prepare("SELECT COUNT(*) FROM recipes WHERE user_id = ?")->execute([$user['id']]) ? $db->query("SELECT COUNT(*) FROM recipes WHERE user_id = " . (int)$user['id'])->fetchColumn() : 0;
$batchCount  = (int)$db->prepare("SELECT COUNT(*) FROM batches WHERE user_id = ?")->execute([$user['id']]) ? $db->query("SELECT COUNT(*) FROM batches WHERE user_id = " . (int)$user['id'])->fetchColumn() : 0;

$csrfToken = generate_csrf_token();
require_once __DIR__ . '/includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1>👤 My Account &amp; Brewer Profile</h1>
        <p style="color: var(--text-muted);">Manage your personal details, credentials, and mobile API connection.</p>
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

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem;">
    <!-- Account Information & Email -->
    <div class="card">
        <h3 class="card-title" style="margin-bottom: 1.25rem;">📋 Account Information</h3>
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

    <!-- Mobile Companion API Token -->
    <div class="card">
        <h3 class="card-title" style="margin-bottom: 1rem;">📱 Companion App API Access</h3>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.25rem; line-height: 1.5;">
            Use this secure personal API token to authenticate the CraftBrew Android App or custom automation tools to your account.
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
