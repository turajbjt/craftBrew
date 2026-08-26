<?php
require_once __DIR__ . '/auth_check.php';
$user = current_user();
$activePage = $activePage ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="assets/js/app.js" defer></script>
</head>
<body>
    <nav class="navbar">
        <a href="index.php" class="navbar-brand">
            🍺 <span><?= APP_NAME ?></span>
        </a>
        <button class="mobile-toggle" aria-label="Toggle navigation">☰</button>
        <ul class="navbar-nav">
            <li><a href="index.php" class="nav-link <?= $activePage === 'dashboard' ? 'active' : '' ?>">Dashboard</a></li>
            <li><a href="batches.php" class="nav-link <?= $activePage === 'batches' ? 'active' : '' ?>">Brew Logs / Batches</a></li>
            <li><a href="recipes.php" class="nav-link <?= $activePage === 'recipes' ? 'active' : '' ?>">Recipes</a></li>
            <li><a href="inventory.php" class="nav-link <?= $activePage === 'inventory' ? 'active' : '' ?>">Inventory</a></li>
            <li><a href="calculators.php" class="nav-link <?= $activePage === 'calculators' ? 'active' : '' ?>">Calculators</a></li>
            <li><a href="documents.php" class="nav-link <?= $activePage === 'documents' ? 'active' : '' ?>">Document Library</a></li>
            <?php if ($user): ?>
                <li class="nav-user">
                    <span>👤 <?= htmlspecialchars($user['username']) ?></span>
                    <a href="logout.php" class="btn-logout">Logout</a>
                </li>
            <?php else: ?>
                <li><a href="login.php" class="nav-link <?= $activePage === 'login' ? 'active' : '' ?>">Login</a></li>
                <li><a href="register.php" class="nav-link <?= $activePage === 'register' ? 'active' : '' ?>">Register</a></li>
            <?php endif; ?>
        </ul>
    </nav>
    <div class="container">
