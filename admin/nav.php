<?php
$adminSubPage = $adminSubPage ?? 'dashboard';
?>
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem; border-bottom: 2px solid var(--border); padding-bottom: 1rem;">
    <div>
        <h1 style="display: flex; align-items: center; gap: 0.5rem;">👑 Site Owner &amp; Admin Portal</h1>
        <p style="color: var(--text-muted); font-size: 0.95rem;">System configuration, user management, security governance &amp; brewing analytics.</p>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="../index.php" class="btn btn-secondary">&laquo; Brewer Dashboard</a>
    </div>
</div>

<div class="mode-nav" style="display: flex; background: var(--card-bg); padding: 0.35rem; border-radius: 8px; margin-bottom: 1.75rem; border: 1px solid var(--border); gap: 0.35rem; flex-wrap: wrap;">
    <a href="index.php" class="btn btn-sm <?= $adminSubPage === 'dashboard' ? 'btn-primary' : 'btn-secondary' ?>">📊 Admin Overview</a>
    <a href="users.php" class="btn btn-sm <?= $adminSubPage === 'users' ? 'btn-primary' : 'btn-secondary' ?>">👥 User Management</a>
    <a href="security.php" class="btn btn-sm <?= $adminSubPage === 'security' ? 'btn-primary' : 'btn-secondary' ?>">🛡️ Security &amp; IP Blocklist</a>
    <a href="analytics.php" class="btn btn-sm <?= $adminSubPage === 'analytics' ? 'btn-primary' : 'btn-secondary' ?>">📈 Demographics &amp; Analytics</a>
    <a href="settings.php" class="btn btn-sm <?= $adminSubPage === 'settings' ? 'btn-primary' : 'btn-secondary' ?>">⚙️ Policies &amp; Settings</a>
    <a href="backup.php" class="btn btn-sm <?= $adminSubPage === 'backup' ? 'btn-primary' : 'btn-secondary' ?>">💾 Database Backup</a>
    <a href="import.php" class="btn btn-sm <?= $adminSubPage === 'import' ? 'btn-primary' : 'btn-secondary' ?>">🚚 Legacy Importer</a>
</div>
