<?php
/**
 * Admin Portal - System Legacy Importer
 * Exclusively for Site Administrators
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_login();
require_admin();
$user = current_user();
$db = get_db();

$sourceDir = __DIR__ . '/../legacy_import';
$logFilePath = $sourceDir . '/homebrew_log.txt';
$importLogs = [];
$error = '';
$isDone = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_import') {
    require_csrf_token();
    try {
        init_schema();
        $userId = $user['id'];

        // 1. Process Beer Recipes from homebrew_log.txt
        if (file_exists($logFilePath)) {
            $rawContent = file_get_contents($logFilePath);
            $sections = preg_split('/\n(?=[A-Z0-9\s\-]+(?:\s-\s[A-Z0-9\s]+)?\n={3,})/u', $rawContent);

            $beerCat = $db->query("SELECT id FROM categories WHERE name = 'Beer' LIMIT 1")->fetch();
            $catId = $beerCat ? (int)$beerCat['id'] : 1;

            $importedRecipes = 0;
            foreach ($sections as $section) {
                $lines = array_map('trim', explode("\n", trim($section)));
                if (empty($lines) || count($lines) < 3) continue;

                $titleLine = $lines[0];
                if (strpos($lines[1], '===') !== false) {
                    $recipeName = trim($titleLine);
                    if (empty($recipeName) || stripos($recipeName, 'BREW LOG') !== false) continue;

                    $chk = $db->prepare("SELECT id FROM recipes WHERE name = ?");
                    $chk->execute([$recipeName]);
                    if (!$chk->fetch()) {
                        $ins = $db->prepare("INSERT INTO recipes (user_id, category_id, name, style, batch_size_gal, ingredients, instructions, is_public) VALUES (?, ?, ?, 'Ale', 5.00, ?, ?, 1)");
                        $ins->execute([$userId, $catId, $recipeName, 'Imported from legacy log', $section]);
                        $importedRecipes++;
                    }
                }
            }
            $importLogs[] = "Imported {$importedRecipes} legacy beer recipes.";
        }

        // 2. Process Cider Guide from homebrew_cider.txt
        $ciderFile = $sourceDir . '/homebrew_cider.txt';
        if (file_exists($ciderFile)) {
            $ciderContent = file_get_contents($ciderFile);
            $ciderCat = $db->query("SELECT id FROM categories WHERE name = 'Cider' LIMIT 1")->fetch();
            $ciderCatId = $ciderCat ? (int)$ciderCat['id'] : 1;

            $chkCider = $db->prepare("SELECT id FROM recipes WHERE name = 'Simple Hard Cider (Classic Recipe)'");
            $chkCider->execute();
            if (!$chkCider->fetch()) {
                $insCider = $db->prepare("INSERT INTO recipes (user_id, category_id, name, style, batch_size_gal, target_og, target_fg, target_abv, ingredients, instructions, is_public) VALUES (?, ?, 'Simple Hard Cider (Classic Recipe)', 'Hard Cider', 5.00, 1.064, 1.005, 9.00, ?, ?, 1)");
                $insCider->execute([
                    $userId,
                    $ciderCatId,
                    "- 5 gal Fresh Apple Juice\n- 4 lbs Table Sugar\n- 1 pkt Nottingham Ale Yeast",
                    $ciderContent
                ]);
                $importLogs[] = "Imported Simple Hard Cider recipe guide.";
            }
        }

        // 3. Import Reference Documents to assets/docs/
        if (!is_dir(DOC_UPLOAD_DIR)) {
            @mkdir(DOC_UPLOAD_DIR, 0777, true);
            @chmod(DOC_UPLOAD_DIR, 0777);
        }

        $docFiles = glob($sourceDir . '/*.*');
        $importedDocs = 0;

        foreach ($docFiles as $file) {
            $basename = basename($file);
            if ($basename === 'homebrew_log.txt') continue;

            $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'txt', 'png', 'jpg', 'jpeg', 'gif', 'xls'])) continue;

            $targetPath = DOC_UPLOAD_DIR . $basename;
            @copy($file, $targetPath);
            @chmod($targetPath, 0666);

            $fileType = $ext === 'pdf' ? 'PDF Document' : ($ext === 'txt' ? 'Text Note' : ($ext === 'xls' ? 'Spreadsheet' : 'Image'));
            $title = ucwords(str_replace(['_', '-'], ' ', pathinfo($basename, PATHINFO_FILENAME)));

            $chkDoc = $db->prepare("SELECT id FROM documents WHERE filename = ?");
            $chkDoc->execute([$basename]);
            if (!$chkDoc->fetch()) {
                $insDoc = $db->prepare("INSERT INTO documents (user_id, title, category, filename, original_filename, file_type, description) VALUES (?, ?, 'Reference', ?, ?, ?, ?)");
                $desc = "Imported reference file: " . $basename;
                if ($basename === 'homebrew_cider.txt') {
                    $desc = "Simple Hard Cider Recipe Guide & Tips";
                }
                $insDoc->execute([$userId, $title, $basename, $basename, $fileType, $desc]);
                $importedDocs++;
            }
        }
        $importLogs[] = "Imported {$importedDocs} reference files into Document Library.";
        $isDone = true;
        log_admin_action('legacy_import', "Executed legacy batch/recipe and reference file import");
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$csrfToken = generate_csrf_token();
$pageTitle = "Legacy Importer - Admin Portal";
$activePage = 'admin';
require_once __DIR__ . '/../includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
    <div>
        <h1>🚚 Legacy Brew Log & Document Importer</h1>
        <p style="color: var(--text-muted);">Admin tool to scan and import historical logs, cider guides, and reference documents from <code>legacy_import/</code>.</p>
    </div>
    <a href="index.php" class="btn btn-secondary">&laquo; Back to Admin Dashboard</a>
</div>

<div class="card" style="max-width: 650px;">
    <?php if ($isDone): ?>
        <div style="background: #dcfce7; color: #166534; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #bbf7d0;">
            <h3 style="margin-bottom: 0.5rem;">🎉 Import Finished Successfully!</h3>
            <ul style="margin-left: 1.5rem;">
                <?php foreach ($importLogs as $log): ?>
                    <li><?= e($log) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div style="display: flex; gap: 0.75rem;">
            <a href="../recipes.php" class="btn btn-primary">View Recipes</a>
            <a href="../documents.php" class="btn btn-secondary">View Documents</a>
        </div>
    <?php else: ?>
        <?php if (!empty($error)): ?>
            <div style="background: #ffe4e6; color: #9f1239; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem;">
                Error: <?= e($error) ?>
            </div>
        <?php endif; ?>

        <p style="margin-bottom: 1.5rem; line-height: 1.6;">
            This tool parses raw historical text logs in <code>legacy_import/homebrew_log.txt</code> and copies reference PDF handbooks into the system Document Library.
        </p>

        <form method="POST" action="import.php" onsubmit="return confirm('Run legacy import now? Existing matching records will be skipped.');">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="run_import">
            <button type="submit" class="btn btn-primary">🚀 Run Legacy Data Import</button>
        </form>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
