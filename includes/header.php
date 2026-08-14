<?php
/**
 * Shared Admin Portal Header
 */

require_once __DIR__ . '/auth_check.php';
require_login();

$user = get_logged_user();
$currentScript = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Admin Portal') ?> - <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bg-color: #0b0f19;
            --panel-bg: rgba(23, 32, 54, 0.7);
            --panel-border: rgba(255, 255, 255, 0.08);
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --accent: #6366f1;
            --accent-hover: #4f46e5;
            --accent-light: rgba(99, 102, 241, 0.15);
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            background-image: 
                radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.12) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(192, 132, 252, 0.12) 0px, transparent 50%);
            background-attachment: fixed;
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Top Navigation Bar */
        .navbar {
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--panel-border);
            padding: 0 30px;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .brand {
            font-family: 'Outfit', sans-serif;
            font-size: 1.3rem;
            font-weight: 700;
            background: linear-gradient(to right, #818cf8, #c084fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .nav-links {
            display: flex;
            gap: 5px;
            list-style: none;
        }

        .nav-links a {
            color: var(--text-muted);
            text-decoration: none;
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 0.92rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .nav-links a:hover, .nav-links a.active {
            color: var(--text-main);
            background: var(--accent-light);
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .role-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .role-owner   { background: rgba(239, 68, 68, 0.2); color: #fca5a5; }
        .role-manager { background: rgba(99, 102, 241, 0.2); color: #a5b4fc; }
        .role-auditor { background: rgba(245, 158, 11, 0.2); color: #fde047; }
        .role-worker  { background: rgba(16, 185, 129, 0.2); color: #6ee7b7; }

        .btn-logout {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.85rem;
            padding: 6px 12px;
            border: 1px solid var(--panel-border);
            border-radius: 8px;
            transition: background 0.2s;
        }
        .btn-logout:hover { background: rgba(255, 255, 255, 0.05); color: #ef4444; }

        /* Main Content Workspace */
        .main-content {
            flex: 1;
            padding: 35px 30px;
            max-width: 1400px;
            width: 100%;
            margin: 0 auto;
        }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="/admin/dashboard.php" class="brand">
        ⚡ PnP SaaS Manager
    </a>

    <ul class="nav-links">
        <li><a href="/admin/dashboard.php" class="<?= $currentScript === 'dashboard.php' ? 'active' : '' ?>">📊 Dashboard</a></li>
        <li><a href="/admin/customers.php" class="<?= $currentScript === 'customers.php' ? 'active' : '' ?>">👥 Customers</a></li>
        <li><a href="/admin/history.php" class="<?= $currentScript === 'history.php' ? 'active' : '' ?>">📜 Reports & History</a></li>
        <li><a href="/admin/query_trans.php" class="<?= $currentScript === 'query_trans.php' ? 'active' : '' ?>">🔍 API Query</a></li>
        <li><a href="/admin/export.php" class="<?= $currentScript === 'export.php' ? 'active' : '' ?>">📥 Export Data</a></li>
        <?php if (in_array($user['role'], ['owner', 'manager'], true)): ?>
            <li><a href="/admin/plans.php" class="<?= $currentScript === 'plans.php' ? 'active' : '' ?>">💳 Payment Plans</a></li>
            <li><a href="/admin/settings.php" class="<?= $currentScript === 'settings.php' ? 'active' : '' ?>">⚙️ Settings</a></li>
        <?php endif; ?>
        <?php if ($user['role'] === 'owner'): ?>
            <li><a href="/admin/users.php" class="<?= $currentScript === 'users.php' ? 'active' : '' ?>">🔐 Sub-Logins & Audits</a></li>
        <?php endif; ?>
    </ul>

    <div class="user-profile">
        <div style="text-align: right;">
            <div style="font-weight: 600; font-size: 0.9rem;"><?= htmlspecialchars($user['username']) ?></div>
            <span class="role-badge role-<?= htmlspecialchars($user['role']) ?>"><?= htmlspecialchars($user['role']) ?></span>
        </div>
        <a href="/admin/logout.php" class="btn-logout">Logout</a>
    </div>
</nav>

<div class="main-content">
