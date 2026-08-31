<?php
require_once __DIR__ . '/auth_check.php';
$user = current_user();
$activePage = $activePage ?? '';
$inAdmin = (strpos($_SERVER['PHP_SELF'] ?? '', '/admin/') !== false);
$basePrefix = $inAdmin ? '../' : '';
$adminPrefix = $inAdmin ? '' : 'admin/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= $basePrefix ?>assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="<?= $basePrefix ?>assets/js/app.js" defer></script>
</head>
<body>
    <nav class="navbar">
        <a href="<?= $basePrefix ?>index.php" class="navbar-brand">
            🍺 <span><?= APP_NAME ?></span>
        </a>
        <button class="mobile-toggle" aria-label="Toggle navigation">☰</button>
        <ul class="navbar-nav">
            <?php if ($user): ?>
                <li><a href="<?= $basePrefix ?>index.php" class="nav-link <?= $activePage === 'dashboard' ? 'active' : '' ?>">Dashboard</a></li>
                <li><a href="<?= $basePrefix ?>batches.php" class="nav-link <?= $activePage === 'batches' ? 'active' : '' ?>">Brew Logs / Batches</a></li>
                <li><a href="<?= $basePrefix ?>recipes.php" class="nav-link <?= $activePage === 'recipes' ? 'active' : '' ?>">Recipes</a></li>
                <li><a href="<?= $basePrefix ?>inventory.php" class="nav-link <?= $activePage === 'inventory' ? 'active' : '' ?>">Inventory</a></li>
                <li><a href="<?= $basePrefix ?>calculators.php" class="nav-link <?= $activePage === 'calculators' ? 'active' : '' ?>">Calculators</a></li>
                <li><a href="<?= $basePrefix ?>documents.php" class="nav-link <?= $activePage === 'documents' ? 'active' : '' ?>">Document Library</a></li>
                
                <?php if ($user['role'] === 'admin'): ?>
                    <li><a href="<?= $adminPrefix ?>index.php" class="nav-link <?= $activePage === 'admin' ? 'active' : '' ?>" style="font-weight: 700; color: #f59e0b;">👑 Admin Portal</a></li>
                <?php endif; ?>

                <li class="nav-user">
                    <a href="<?= $basePrefix ?>profile.php" style="color: var(--text-main); text-decoration: none; font-size: 0.9rem;" title="My Profile & Settings">👤 <?= htmlspecialchars($user['username']) ?></a>
                    <a href="<?= $basePrefix ?>logout.php" class="btn-logout">Logout</a>
                </li>
            <?php else: ?>
                <li><a href="<?= $basePrefix ?>index.php" class="nav-link <?= $activePage === 'home' ? 'active' : '' ?>">Home</a></li>
                <li><a href="<?= $basePrefix ?>index.php#features" class="nav-link">Features</a></li>
                <li><a href="<?= $basePrefix ?>login.php" class="nav-link <?= $activePage === 'login' ? 'active' : '' ?>" style="font-weight: 700; color: #fbbf24;">🔐 Login</a></li>
                <?php if (get_site_setting('registration_mode', 'open') !== 'closed'): ?>
                    <li><a href="<?= $basePrefix ?>register.php" class="nav-link <?= $activePage === 'register' ? 'active' : '' ?>">Register</a></li>
                <?php endif; ?>
            <?php endif; ?>
        </ul>
    </nav>
    <div class="container">
