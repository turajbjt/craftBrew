<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';

$user = current_user();

if (!$user) {
    $pageTitle = APP_NAME . " - Home & Craft Brewing Platform";
    $activePage = 'home';
    $regMode = get_site_setting('registration_mode', 'open');
    require_once __DIR__ . '/includes/header.php';
    ?>
    <!-- Splash Page Hero Section -->
    <section class="splash-hero">
        <div class="splash-hero-badge">🍺 Beer &bull; 🍷 Wine &bull; 🍏 Cider &bull; 🍯 Mead</div>
        <h1>Formulate Recipes. Track Fermentations.<br><span>Master Your Craft Brews.</span></h1>
        <p>
            The dedicated digital brewing journal and recipe manager designed for homebrewers and artisanal craft makers.
            Track gravity drops, calculate precise ABV, manage cellar stock, and export printable brew sheets.
        </p>
        <div class="splash-cta-group">
            <a href="login.php" class="btn btn-primary btn-lg">🔐 Sign In to Your Cellar</a>
            <?php if ($regMode !== 'closed'): ?>
                <a href="register.php" class="btn btn-outline-light btn-lg">✨ Create Account</a>
            <?php endif; ?>
        </div>
    </section>

    <!-- Supported Craft Beverages -->
    <div class="splash-beverage-pills">
        <div class="beverage-pill">
            <span style="font-size: 1.5rem;">🍺</span>
            <div>
                <div>Craft Beers</div>
                <small style="color: var(--text-muted); font-weight: normal;">All-Grain, Extract, IPAs & Stouts</small>
            </div>
        </div>
        <div class="beverage-pill">
            <span style="font-size: 1.5rem;">🍷</span>
            <div>
                <div>Wines & Fruit Wines</div>
                <small style="color: var(--text-muted); font-weight: normal;">Varietals, Berry & Country Wines</small>
            </div>
        </div>
        <div class="beverage-pill">
            <span style="font-size: 1.5rem;">🍏</span>
            <div>
                <div>Hard Ciders</div>
                <small style="color: var(--text-muted); font-weight: normal;">Dry, Sweet, Heritage & Spiced</small>
            </div>
        </div>
        <div class="beverage-pill">
            <span style="font-size: 1.5rem;">🍯</span>
            <div>
                <div>Mead & Melomels</div>
                <small style="color: var(--text-muted); font-weight: normal;">Traditional, Cyser & Metheglin</small>
            </div>
        </div>
    </div>

    <!-- Platform Features -->
    <section id="features" style="margin-bottom: 3rem;">
        <div class="splash-section-header">
            <h2>Everything You Need for Brew Day & Beyond</h2>
            <p>From formulating grain bills to tracking active fermentation curves, CraftBrew gives you total control.</p>
        </div>

        <div class="card-grid">
            <div class="splash-feature-card">
                <div class="splash-feature-icon">📉</div>
                <h3 class="splash-feature-title">Fermentation & Gravity Curves</h3>
                <p class="splash-feature-desc">
                    Log hydrometer & refractometer readings across Primary, Secondary, and Aging stages with interactive <strong>Chart.js</strong> gravity drop curves and auto-calculated ABV.
                </p>
            </div>

            <div class="splash-feature-card">
                <div class="splash-feature-icon">📝</div>
                <h3 class="splash-feature-title">Recipe Formulations & BeerXML</h3>
                <p class="splash-feature-desc">
                    Build multi-stage recipes with structured fermentables, hops, yeasts, and additions. Full 1-click import/export compatibility with <strong>BeerXML (.xml)</strong> and <strong>JSON</strong>.
                </p>
            </div>

            <div class="splash-feature-card">
                <div class="splash-feature-icon">🧮</div>
                <h3 class="splash-feature-title">Brewing Calculators Suite</h3>
                <p class="splash-feature-desc">
                    Integrated tools for ABV & attenuation, sample temperature hydrometer correction, priming sugar for bottle carbonation, and gravity boost mash additions.
                </p>
            </div>

            <div class="splash-feature-card">
                <div class="splash-feature-icon">🎯</div>
                <h3 class="splash-feature-title">BJCP Style Guidelines</h3>
                <p class="splash-feature-desc">
                    Formulate against official <strong>BJCP 2021 guidelines</strong> with live visual compliance target gauges for OG, FG, ABV, IBU, and SRM color matching.
                </p>
            </div>

            <div class="splash-feature-card">
                <div class="splash-feature-icon">⚖️</div>
                <h3 class="splash-feature-title">Recipe Auto-Scaling Tool</h3>
                <p class="splash-feature-desc">
                    Scale any recipe proportionally for 1 to 15.5+ gallon systems or custom brewhouse efficiencies with automatic grain, hop, and strike water adjustments.
                </p>
            </div>

            <div class="splash-feature-card">
                <div class="splash-feature-icon">🏷️</div>
                <h3 class="splash-feature-title">Bottle &amp; Keg Label Designer</h3>
                <p class="splash-feature-desc">
                    Generate printable 12oz/22oz bottle label sheets and Cornelius keg collar tags complete with SRM color bands and dynamic offline QR codes.
                </p>
            </div>

            <div class="splash-feature-card">
                <div class="splash-feature-icon">📦</div>
                <h3 class="splash-feature-title">Cellar &amp; Stock Inventory</h3>
                <p class="splash-feature-desc">
                    Track grains, hops, yeast strains, additives, and equipment quantities in real time so you're always prepared before firing up the kettle.
                </p>
            </div>

            <div class="splash-feature-card">
                <div class="splash-feature-icon">📄</div>
                <h3 class="splash-feature-title">Document Library &amp; PDF Export</h3>
                <p class="splash-feature-desc">
                    Organize reference manuals, guides, and logbooks in your library, and generate formatted printable PDF brew day summary sheets with one click.
                </p>
            </div>

            <div class="splash-feature-card">
                <div class="splash-feature-icon">📱</div>
                <h3 class="splash-feature-title">REST API &amp; Companion App</h3>
                <p class="splash-feature-desc">
                    Includes RESTful JSON endpoints at <code>/api/v1/</code> with Bearer API token authentication for seamless logging from companion mobile devices.
                </p>
            </div>
        </div>
    </section>

    <!-- Bottom Call To Action Banner -->
    <div class="splash-bottom-banner">
        <div>
            <h3 style="font-size: 1.5rem; margin-bottom: 0.5rem;">Ready to track your brew logs?</h3>
            <p style="color: #cbd5e1; margin: 0;">Sign in to access your formulations, active fermentations, and cellar inventory.</p>
        </div>
        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <a href="login.php" class="btn btn-primary btn-lg">🔐 Log In Now</a>
            <?php if ($regMode !== 'closed'): ?>
                <a href="register.php" class="btn btn-secondary btn-lg">Create Account</a>
            <?php endif; ?>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$db = get_db();
init_schema();

// Fetch summary metrics
$totalBatches = $db->query("SELECT COUNT(*) FROM batches WHERE user_id = {$user['id']}")->fetchColumn();
$activeBatches = $db->query("SELECT COUNT(*) FROM batches WHERE user_id = {$user['id']} AND status IN ('Must Prep', 'Primary', 'Secondary', 'Bottling/Aging')")->fetchColumn();
$totalRecipes = $db->query("SELECT COUNT(*) FROM recipes WHERE user_id = {$user['id']}")->fetchColumn();
$totalDocs = $db->query("SELECT COUNT(*) FROM documents WHERE user_id = {$user['id']}")->fetchColumn();

// Fetch active fermentations
$stmtActive = $db->prepare("
    SELECT b.*, c.name as category_name
    FROM batches b
    JOIN categories c ON b.category_id = c.id
    WHERE b.user_id = ? AND b.status IN ('Must Prep', 'Primary', 'Secondary', 'Bottling/Aging')
    ORDER BY b.date_start DESC
    LIMIT 5
");
$stmtActive->execute([$user['id']]);
$activeList = $stmtActive->fetchAll();

// Fetch recent completed batches
$stmtRecent = $db->prepare("
    SELECT b.*, c.name as category_name
    FROM batches b
    JOIN categories c ON b.category_id = c.id
    WHERE b.user_id = ?
    ORDER BY b.created_at DESC
    LIMIT 6
");
$stmtRecent->execute([$user['id']]);
$recentList = $stmtRecent->fetchAll();

$pageTitle = "Dashboard - " . APP_NAME;
$activePage = 'dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1>🍺 Brewer Dashboard</h1>
        <p style="color: var(--text-muted);">Welcome back, <strong><?= htmlspecialchars($user['username']) ?></strong>! Track your beer, wine, and cider craft brews.</p>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="batch_edit.php?action=new" class="btn btn-primary">+ New Brew Batch</a>
        <a href="recipe_edit.php?action=new" class="btn btn-secondary">+ New Recipe</a>
        <a href="data_manager.php" class="btn btn-secondary">💾 Backup &amp; Export</a>
    </div>
</div>

<!-- Stats Row -->
<div class="card-grid">
    <div class="card">
        <div class="card-subtitle">Active Fermentations</div>
        <div style="font-size: 2rem; font-weight: 800; color: var(--primary-color);"><?= $activeBatches ?></div>
        <small style="color: var(--text-muted);">In primary, secondary or aging</small>
    </div>
    <div class="card">
        <div class="card-subtitle">Total Brew Logs</div>
        <div style="font-size: 2rem; font-weight: 800; color: #1e293b;"><?= $totalBatches ?></div>
        <small style="color: var(--text-muted);">Recorded brew batches</small>
    </div>
    <div class="card">
        <div class="card-subtitle">Saved Recipes</div>
        <div style="font-size: 2rem; font-weight: 800; color: #3b82f6;"><?= $totalRecipes ?></div>
        <small style="color: var(--text-muted);">Beer, Wine & Cider formulas</small>
    </div>
    <div class="card">
        <div class="card-subtitle">Reference Library</div>
        <div style="font-size: 2rem; font-weight: 800; color: #10b981;"><?= $totalDocs ?></div>
        <small style="color: var(--text-muted);">Imported PDFs & brewing guides</small>
    </div>
</div>

<!-- Active Fermentations Section -->
<div style="margin-bottom: 2.5rem;">
    <h2>🔥 Active Fermentations</h2>
    <?php if (empty($activeList)): ?>
        <div class="card" style="text-align: center; color: var(--text-muted); padding: 2rem;">
            No active fermentations currently in progress. <a href="batch_edit.php?action=new">Start a new batch</a> to track gravity and logs!
        </div>
    <?php else: ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Batch Name</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Start Date</th>
                        <th>OG</th>
                        <th>Latest SG</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activeList as $b): ?>
                        <tr>
                            <td>
                                <strong><a href="batch_detail.php?id=<?= $b['id'] ?>"><?= htmlspecialchars($b['batch_name']) ?></a></strong>
                                <br><small style="color: var(--text-muted);"><?= htmlspecialchars($b['batch_style']) ?></small>
                            </td>
                            <td>
                                <span class="badge badge-<?= strtolower($b['category_name']) ?>"><?= htmlspecialchars($b['category_name']) ?></span>
                            </td>
                            <td>
                                <span class="badge badge-<?= strtolower(str_replace(['/', ' '], '', $b['status'])) ?>"><?= htmlspecialchars($b['status']) ?></span>
                            </td>
                            <td><?= $b['date_start'] ? date('M d, Y', strtotime($b['date_start'])) : 'N/A' ?></td>
                            <td><?= $b['gravity_og'] ? sprintf('%.3f', $b['gravity_og']) : '--' ?></td>
                            <td><?= $b['gravity_sg'] ? sprintf('%.3f', $b['gravity_sg']) : ($b['gravity_og'] ? sprintf('%.3f', $b['gravity_og']) : '--') ?></td>
                            <td>
                                <a href="batch_detail.php?id=<?= $b['id'] ?>" class="btn btn-primary btn-sm">Log Readings</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Recent Brew Batches Grid -->
<div>
    <h2>📚 Recent Brew Logs</h2>
    <?php if (empty($recentList)): ?>
        <p style="color: var(--text-muted);">No brew logs found. Run <a href="import_legacy_logs.php">Import Legacy Data</a> to seed your historical 10 batches!</p>
    <?php else: ?>
        <div class="card-grid">
            <?php foreach ($recentList as $b): ?>
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                        <span class="badge badge-<?= strtolower($b['category_name']) ?>"><?= htmlspecialchars($b['category_name']) ?></span>
                        <span class="badge badge-<?= strtolower(str_replace(['/', ' '], '', $b['status'])) ?>"><?= htmlspecialchars($b['status']) ?></span>
                    </div>
                    <h3 class="card-title"><a href="batch_detail.php?id=<?= $b['id'] ?>" style="color: inherit; text-decoration: none;"><?= htmlspecialchars($b['batch_name']) ?></a></h3>
                    <p class="card-subtitle"><?= htmlspecialchars($b['batch_style'] ?: 'Craft Brew') ?> &bull; <?= $b['batch_size_gal'] ?> Gal</p>
                    
                    <div style="font-size: 0.9rem; margin-bottom: 1rem; background: #f8fafc; padding: 0.5rem; border-radius: 6px;">
                        <div><strong>Start:</strong> <?= $b['date_start'] ? date('M d, Y', strtotime($b['date_start'])) : 'N/A' ?></div>
                        <div><strong>ABV:</strong> <?= $b['calculated_abv'] ? $b['calculated_abv'] . '%' : 'N/A' ?></div>
                        <div><strong>Rating:</strong> <?= $b['rating'] > 0 ? "⭐ {$b['rating']}/10" : "Unrated" ?></div>
                    </div>

                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <a href="batch_detail.php?id=<?= $b['id'] ?>" class="btn btn-secondary btn-sm">View Log</a>
                        <a href="export_pdf.php?type=batch&id=<?= $b['id'] ?>" class="btn btn-primary btn-sm" target="_blank">📄 Export PDF</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
