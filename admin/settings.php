<?php
/**
 * System Settings & Gateway Configuration Management (settings.php)
 * Restricted to Owner and Manager Roles
 */

$pageTitle = 'System & Gateway Settings';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/SettingsService.php';

require_role(['owner', 'manager']); // RBAC Guard

$actionMsg = null;
$errorMsg = null;

// Handle Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $updatedSettings = [
        'pnp_publisher_name'       => trim($_POST['pnp_publisher_name'] ?? ''),
        'pnp_api_key'              => trim($_POST['pnp_api_key'] ?? ''),
        'pnp_mock_mode'            => ($_POST['pnp_mock_mode'] ?? 'true') === 'true' ? 'true' : 'false',
        'pnp_authprev_url'         => trim($_POST['pnp_authprev_url'] ?? ''),
        'pnp_batch_upload_url'     => trim($_POST['pnp_batch_upload_url'] ?? ''),
        'pnp_query_trans_url'      => trim($_POST['pnp_query_trans_url'] ?? ''),
        'pnp_smart_screens_url'    => trim($_POST['pnp_smart_screens_url'] ?? ''),
        'alert_email_from'         => trim($_POST['alert_email_from'] ?? ''),
        'alert_email_to'           => trim($_POST['alert_email_to'] ?? ''),
        'send_email_notifications' => ($_POST['send_email_notifications'] ?? 'true') === 'true' ? 'true' : 'false',
        'app_name'                 => trim($_POST['app_name'] ?? ''),
        'app_url'                  => trim($_POST['app_url'] ?? ''),
    ];

    if (SettingsService::saveMultiple($updatedSettings)) {
        audit_log('update_settings', "Updated gateway credentials and system configuration settings.");
        $actionMsg = "System configuration settings updated successfully!";
        // Re-read settings into cache
        $currentSettings = SettingsService::getAll();
    } else {
        $errorMsg = "Failed to save some configuration settings. Please check database permissions.";
    }
}

$currentSettings = SettingsService::getAll();
?>

<style>
    .settings-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
        gap: 25px;
        margin-bottom: 40px;
    }

    .settings-card {
        background: var(--panel-bg);
        border: 1px solid var(--panel-border);
        backdrop-filter: blur(12px);
        border-radius: 16px;
        padding: 28px;
    }

    .card-title {
        font-family: 'Outfit', sans-serif;
        font-size: 1.25rem;
        font-weight: 700;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        color: var(--text-main);
        border-bottom: 1px solid var(--panel-border);
        padding-bottom: 12px;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        display: block;
        font-size: 0.88rem;
        font-weight: 600;
        color: var(--text-muted);
        margin-bottom: 8px;
    }

    .form-control {
        width: 100%;
        padding: 12px 16px;
        background: #0f172a;
        border: 1px solid var(--panel-border);
        border-radius: 10px;
        color: #ffffff;
        font-size: 0.95rem;
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    .form-control:focus {
        outline: none;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
    }

    .form-select {
        width: 100%;
        padding: 12px 16px;
        background: #0f172a;
        border: 1px solid var(--panel-border);
        border-radius: 10px;
        color: #ffffff;
        font-size: 0.95rem;
    }

    .help-text {
        font-size: 0.78rem;
        color: #64748b;
        margin-top: 6px;
    }

    .alert {
        padding: 14px 20px;
        border-radius: 12px;
        margin-bottom: 25px;
        font-size: 0.95rem;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .alert-success { background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: #6ee7b7; }
    .alert-danger  { background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: #fca5a5; }

    .btn-save {
        background: linear-gradient(135deg, var(--accent), #4f46e5);
        color: white;
        border: none;
        padding: 14px 30px;
        font-size: 1rem;
        font-weight: 600;
        border-radius: 12px;
        cursor: pointer;
        transition: transform 0.2s, box-shadow 0.2s;
        box-shadow: 0 4px 14px rgba(99, 102, 241, 0.35);
    }

    .btn-save:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(99, 102, 241, 0.5);
    }

    .badge-mode {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
    }
    .badge-sandbox { background: rgba(245, 158, 11, 0.2); color: #fde047; }
    .badge-live    { background: rgba(16, 185, 129, 0.2); color: #6ee7b7; }
</style>

<div style="margin-bottom: 30px;">
    <h1 style="font-family: 'Outfit', sans-serif; font-size: 2rem; font-weight: 700;">System & Gateway Settings</h1>
    <p style="color: var(--text-muted);">Manage Plug'n Pay API credentials, sandbox testing mode, notification alerts, and application parameters.</p>
</div>

<?php if ($actionMsg): ?>
    <div class="alert alert-success">
        ✅ <?= htmlspecialchars($actionMsg) ?>
    </div>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <div class="alert alert-danger">
        ⚠️ <?= htmlspecialchars($errorMsg) ?>
    </div>
<?php endif; ?>

<form method="POST">
    <div class="settings-grid">
        
        <!-- Plug'n Pay Credentials Card -->
        <div class="settings-card">
            <div class="card-title">
                💳 Plug'n Pay Gateway Credentials
            </div>

            <div class="form-group">
                <label>Publisher Name (Merchant Account ID)</label>
                <input type="text" name="pnp_publisher_name" class="form-control" required
                       value="<?= htmlspecialchars($currentSettings['pnp_publisher_name'] ?? PNP_PUBLISHER_NAME) ?>">
                <div class="help-text">Assigned Plug'n Pay publisher ID (e.g. <code>demo_publisher</code>).</div>
            </div>

            <div class="form-group">
                <label>Remote API Key / Password</label>
                <input type="password" name="pnp_api_key" class="form-control" required
                       value="<?= htmlspecialchars($currentSettings['pnp_api_key'] ?? PNP_API_KEY) ?>">
                <div class="help-text">Remote transaction API password / key.</div>
            </div>

            <div class="form-group">
                <label>Operation Mode</label>
                <select name="pnp_mock_mode" class="form-select">
                    <option value="true" <?= (defined('PNP_MOCK_MODE') && PNP_MOCK_MODE) ? 'selected' : '' ?>>
                        🧪 Sandbox / Mock Mode (Offline Simulation)
                    </option>
                    <option value="false" <?= (defined('PNP_MOCK_MODE') && !PNP_MOCK_MODE) ? 'selected' : '' ?>>
                        🚀 Live Production Mode (Real Card Processing)
                    </option>
                </select>
                <div class="help-text">Toggle between sandbox mock responses and live gateway submission.</div>
            </div>
        </div>

        <!-- Notification & Email Settings Card -->
        <div class="settings-card">
            <div class="card-title">
                📬 Notifications & Email Alerts
            </div>

            <div class="form-group">
                <label>Alert Email Sender Address (From)</label>
                <input type="email" name="alert_email_from" class="form-control" required
                       value="<?= htmlspecialchars($currentSettings['alert_email_from'] ?? ALERT_EMAIL_FROM) ?>">
                <div class="help-text">Outbound email address for automated billing failure alerts.</div>
            </div>

            <div class="form-group">
                <label>Alert Recipient Address (To)</label>
                <input type="email" name="alert_email_to" class="form-control" required
                       value="<?= htmlspecialchars($currentSettings['alert_email_to'] ?? ALERT_EMAIL_TO) ?>">
                <div class="help-text">Recipient email address for merchant notifications and EOD alerts.</div>
            </div>

            <div class="form-group">
                <label>Email Dispatch State</label>
                <select name="send_email_notifications" class="form-select">
                    <option value="true" <?= (defined('SEND_EMAIL_NOTIFICATIONS') && SEND_EMAIL_NOTIFICATIONS) ? 'selected' : '' ?>>
                        🔔 Enabled (Send email alerts on billing failure)
                    </option>
                    <option value="false" <?= (defined('SEND_EMAIL_NOTIFICATIONS') && !SEND_EMAIL_NOTIFICATIONS) ? 'selected' : '' ?>>
                        🔕 Disabled (Log only, skip email transport)
                    </option>
                </select>
            </div>
        </div>

        <!-- Plug'n Pay Endpoint URLs Card -->
        <div class="settings-card">
            <div class="card-title">
                🌐 Plug'n Pay API Endpoints
            </div>

            <div class="form-group">
                <label>Authprev Remote API URL</label>
                <input type="url" name="pnp_authprev_url" class="form-control" required
                       value="<?= htmlspecialchars($currentSettings['pnp_authprev_url'] ?? PNP_AUTHPREV_URL) ?>">
            </div>

            <div class="form-group">
                <label>Batch Upload API URL</label>
                <input type="url" name="pnp_batch_upload_url" class="form-control" required
                       value="<?= htmlspecialchars($currentSettings['pnp_batch_upload_url'] ?? PNP_BATCH_UPLOAD_URL) ?>">
            </div>

            <div class="form-group">
                <label>Query Trans API URL</label>
                <input type="url" name="pnp_query_trans_url" class="form-control" required
                       value="<?= htmlspecialchars($currentSettings['pnp_query_trans_url'] ?? PNP_QUERY_TRANS_URL) ?>">
            </div>

            <div class="form-group">
                <label>Smart Screens v2 Base URL</label>
                <input type="url" name="pnp_smart_screens_url" class="form-control" required
                       value="<?= htmlspecialchars($currentSettings['pnp_smart_screens_url'] ?? PNP_SMART_SCREENS_URL) ?>">
            </div>
        </div>

        <!-- Application & System Settings Card -->
        <div class="settings-card">
            <div class="card-title">
                ⚙️ Portal Branding & System Setup
            </div>

            <div class="form-group">
                <label>Application Name</label>
                <input type="text" name="app_name" class="form-control" required
                       value="<?= htmlspecialchars($currentSettings['app_name'] ?? APP_NAME) ?>">
            </div>

            <div class="form-group">
                <label>Application Base URL</label>
                <input type="url" name="app_url" class="form-control" required
                       value="<?= htmlspecialchars($currentSettings['app_url'] ?? APP_URL) ?>">
                <div class="help-text">Base URL used for Smart Screens callback generation.</div>
            </div>

            <div class="form-group" style="margin-top: 25px; padding-top: 15px; border-top: 1px dashed var(--panel-border);">
                <label>Active Database Storage Engine</label>
                <div style="font-family: monospace; font-size: 0.95rem; background: #020617; padding: 10px 14px; border-radius: 8px; color: #a5b4fc;">
                    Engine: <strong><?= strtoupper(defined('DB_ENGINE') ? DB_ENGINE : 'SQLITE') ?></strong><br>
                    <?php if (defined('DB_ENGINE') && strtolower(DB_ENGINE) === 'sqlite'): ?>
                        Database File: <?= htmlspecialchars(defined('DB_SQLITE_PATH') ? DB_SQLITE_PATH : '') ?>
                    <?php else: ?>
                        MySQL Host: <?= htmlspecialchars(DB_HOST) ?>:<?= DB_PORT ?> (Database: <?= htmlspecialchars(DB_NAME) ?>)
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>

    <div style="text-align: right; margin-bottom: 50px;">
        <button type="submit" class="btn-save">💾 Save All Configuration Changes</button>
    </div>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
