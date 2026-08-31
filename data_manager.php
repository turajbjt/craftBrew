<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/ZipHelper.php';

require_login();
$user = current_user();
$db = get_db();

$message = '';
$error = '';
$importStats = null;

// Limits to prevent Resource Exhaustion / Denial of Service (OWASP A04)
define('MAX_IMPORT_RECIPES', 500);
define('MAX_IMPORT_BATCHES', 1000);
define('MAX_IMPORT_READINGS_PER_BATCH', 200);
define('MAX_IMPORT_INVENTORY', 1000);
define('MAX_IMPORT_DOCUMENTS', 200);
define('MAX_BACKUP_FILE_BYTES', 15 * 1024 * 1024); // 15 MB

// Helper: Generate JSON data array strictly scoped to authenticated user
function get_user_export_data($db, $userId, $userUsername, $userEmail, $incRecipes, $incBatches, $incInventory, $incDocuments) {
    $exportData = [
        'craftbrew_version' => APP_VERSION,
        'exported_at'       => date('c'),
        'user'              => [
            'username' => sanitize_text($userUsername, 50),
            'email'    => sanitize_text($userEmail, 100)
        ],
        'recipes'   => [],
        'batches'   => [],
        'inventory' => [],
        'documents' => []
    ];

    if ($incRecipes) {
        $rStmt = $db->prepare("SELECT r.*, c.name AS category_name FROM recipes r LEFT JOIN categories c ON r.category_id = c.id WHERE r.user_id = ? ORDER BY r.id ASC");
        $rStmt->execute([$userId]);
        $recipes = $rStmt->fetchAll();

        $ingStmt = $db->prepare("SELECT name, ingredient_type, amount, unit, stage_addition, notes FROM recipe_ingredients WHERE recipe_id = ? ORDER BY id ASC");
        $supStmt = $db->prepare("SELECT item_name, category, quantity, is_required, notes FROM recipe_supplies WHERE recipe_id = ? ORDER BY id ASC");
        $stpStmt = $db->prepare("SELECT step_number, phase, title, duration, target_temp, instructions FROM recipe_steps WHERE recipe_id = ? ORDER BY step_number ASC, id ASC");

        foreach ($recipes as $r) {
            $rId = (int)$r['id'];
            $ingStmt->execute([$rId]);
            $supStmt->execute([$rId]);
            $stpStmt->execute([$rId]);

            $exportData['recipes'][] = [
                'id'              => $rId,
                'name'            => sanitize_text($r['name'], 100),
                'category'        => sanitize_text($r['category_name'] ?: 'Beer', 50),
                'style'           => sanitize_text($r['style'] ?? '', 100),
                'batch_size_gal'  => (float)($r['batch_size_gal'] ?? 5.0),
                'target_pre_og'   => $r['target_pre_og'] ? (float)$r['target_pre_og'] : null,
                'target_og'       => $r['target_og'] ? (float)$r['target_og'] : null,
                'target_fg'       => $r['target_fg'] ? (float)$r['target_fg'] : null,
                'target_abv'      => $r['target_abv'] ? (float)$r['target_abv'] : null,
                'ingredients_raw' => sanitize_text($r['ingredients'] ?? '', 5000),
                'instructions'    => sanitize_text($r['instructions'] ?? '', 5000),
                'is_public'       => (int)($r['is_public'] ?? 1),
                'ingredients'     => $ingStmt->fetchAll(),
                'supplies'        => $supStmt->fetchAll(),
                'steps'           => $stpStmt->fetchAll()
            ];
        }
    }

    if ($incBatches) {
        $bStmt = $db->prepare("
            SELECT b.*, c.name AS category_name, r.name AS linked_recipe_name 
            FROM batches b 
            LEFT JOIN categories c ON b.category_id = c.id 
            LEFT JOIN recipes r ON b.recipe_id = r.id 
            WHERE b.user_id = ? 
            ORDER BY b.id ASC
        ");
        $bStmt->execute([$userId]);
        $batches = $bStmt->fetchAll();

        $readStmt = $db->prepare("SELECT reading_date, gravity, temp_f, notes FROM fermentation_readings WHERE batch_id = ? ORDER BY reading_date ASC, id ASC");

        foreach ($batches as $b) {
            $bId = (int)$b['id'];
            $readStmt->execute([$bId]);

            $exportData['batches'][] = [
                'id'               => $bId,
                'batch_name'       => sanitize_text($b['batch_name'], 100),
                'category'         => sanitize_text($b['category_name'] ?: 'Beer', 50),
                'linked_recipe'    => sanitize_text($b['linked_recipe_name'] ?? '', 100),
                'batch_type'       => sanitize_text($b['batch_type'] ?? '', 50),
                'batch_style'      => sanitize_text($b['batch_style'] ?? '', 100),
                'batch_size_gal'   => (float)($b['batch_size_gal'] ?? 5.0),
                'date_start'       => $b['date_start'],
                'date_rack'        => $b['date_rack'],
                'date_rack_2'      => $b['date_rack_2'],
                'date_rack_3'      => $b['date_rack_3'],
                'date_bottle'      => $b['date_bottle'],
                'pitch_temp_f'     => sanitize_text($b['pitch_temp_f'] ?? '', 10),
                'ferment_temp_f'   => sanitize_text($b['ferment_temp_f'] ?? '', 10),
                'gravity_pre_og'   => $b['gravity_pre_og'] ? (float)$b['gravity_pre_og'] : null,
                'gravity_og'       => $b['gravity_og'] ? (float)$b['gravity_og'] : null,
                'gravity_sg'       => $b['gravity_sg'] ? (float)$b['gravity_sg'] : null,
                'gravity_tertiary' => $b['gravity_tertiary'] ? (float)$b['gravity_tertiary'] : null,
                'gravity_fg'       => $b['gravity_fg'] ? (float)$b['gravity_fg'] : null,
                'calculated_abv'   => $b['calculated_abv'] ? (float)$b['calculated_abv'] : null,
                'ingredients'      => sanitize_text($b['ingredients'] ?? '', 5000),
                'boil_notes'       => sanitize_text($b['boil_notes'] ?? '', 5000),
                'reflections'      => sanitize_text($b['reflections'] ?? '', 5000),
                'rating'           => (int)($b['rating'] ?? 0),
                'status'           => sanitize_text($b['status'] ?? 'Primary', 30),
                'readings'         => $readStmt->fetchAll()
            ];
        }
    }

    if ($incInventory) {
        $iStmt = $db->prepare("SELECT item_name, category, quantity, unit, notes FROM inventory WHERE user_id = ? ORDER BY category ASC, item_name ASC");
        $iStmt->execute([$userId]);
        $exportData['inventory'] = $iStmt->fetchAll();
    }

    if ($incDocuments) {
        $dStmt = $db->prepare("SELECT title, category, filename, original_filename, file_type, description FROM documents WHERE user_id = ? ORDER BY title ASC");
        $dStmt->execute([$userId]);
        $exportData['documents'] = $dStmt->fetchAll();
    }

    return $exportData;
}

// Helper: Generate CSV string with Formula Injection Guard (OWASP A03)
function generate_recipes_csv($db, $userId) {
    $stmt = $db->prepare("SELECT r.*, c.name AS category_name FROM recipes r LEFT JOIN categories c ON r.category_id = c.id WHERE r.user_id = ? ORDER BY r.id ASC");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    $f = fopen('php://temp', 'r+');
    fputcsv($f, ['ID', 'Recipe Name', 'Category', 'Style', 'Batch Size (Gal)', 'Base Juice OG (Raw)', 'Target Starting OG', 'Target FG', 'Target ABV (%)', 'Public', 'Ingredients Raw', 'Instructions']);
    foreach ($rows as $r) {
        fputcsv($f, [
            sanitize_csv_cell($r['id']),
            sanitize_csv_cell($r['name']),
            sanitize_csv_cell($r['category_name'] ?: 'Beer'),
            sanitize_csv_cell($r['style'] ?? ''),
            sanitize_csv_cell($r['batch_size_gal'] ?? 5.0),
            sanitize_csv_cell($r['target_pre_og'] ?? ''),
            sanitize_csv_cell($r['target_og'] ?? ''),
            sanitize_csv_cell($r['target_fg'] ?? ''),
            sanitize_csv_cell($r['target_abv'] ?? ''),
            sanitize_csv_cell($r['is_public'] ? 'Yes' : 'No'),
            sanitize_csv_cell($r['ingredients'] ?? ''),
            sanitize_csv_cell($r['instructions'] ?? '')
        ]);
    }
    rewind($f);
    $csv = stream_get_contents($f);
    fclose($f);
    return $csv;
}

// Helper: Generate Batches CSV with Formula Injection Guard (OWASP A03)
function generate_batches_csv($db, $userId) {
    $stmt = $db->prepare("
        SELECT b.*, c.name AS category_name, r.name AS linked_recipe_name 
        FROM batches b 
        LEFT JOIN categories c ON b.category_id = c.id 
        LEFT JOIN recipes r ON b.recipe_id = r.id 
        WHERE b.user_id = ? 
        ORDER BY b.id ASC
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    $f = fopen('php://temp', 'r+');
    fputcsv($f, ['ID', 'Batch Name', 'Category', 'Linked Recipe', 'Style', 'Type', 'Batch Size (Gal)', 'Date Start', 'Date Racked', 'Date Bottled', 'Raw Must OG (Pre-Sugar)', 'Starting OG (Post-Sugar)', 'Latest SG', 'FG', 'ABV (%)', 'Rating (0-10)', 'Status', 'Tasting Reflections', 'Boil Notes']);
    foreach ($rows as $b) {
        fputcsv($f, [
            sanitize_csv_cell($b['id']),
            sanitize_csv_cell($b['batch_name']),
            sanitize_csv_cell($b['category_name'] ?: 'Beer'),
            sanitize_csv_cell($b['linked_recipe_name'] ?? ''),
            sanitize_csv_cell($b['batch_style'] ?? ''),
            sanitize_csv_cell($b['batch_type'] ?? ''),
            sanitize_csv_cell($b['batch_size_gal'] ?? 5.0),
            sanitize_csv_cell($b['date_start'] ?? ''),
            sanitize_csv_cell($b['date_rack'] ?? ''),
            sanitize_csv_cell($b['date_bottle'] ?? ''),
            sanitize_csv_cell($b['gravity_pre_og'] ?? ''),
            sanitize_csv_cell($b['gravity_og'] ?? ''),
            sanitize_csv_cell($b['gravity_sg'] ?? ''),
            sanitize_csv_cell($b['gravity_fg'] ?? ''),
            sanitize_csv_cell($b['calculated_abv'] ?? ''),
            sanitize_csv_cell($b['rating'] ?? 0),
            sanitize_csv_cell($b['status'] ?? 'Primary'),
            sanitize_csv_cell($b['reflections'] ?? ''),
            sanitize_csv_cell($b['boil_notes'] ?? '')
        ]);
    }
    rewind($f);
    $csv = stream_get_contents($f);
    fclose($f);
    return $csv;
}

// Helper: Generate Fermentation Readings CSV with Formula Injection Guard
function generate_readings_csv($db, $userId) {
    $stmt = $db->prepare("
        SELECT fr.*, b.batch_name, b.batch_style 
        FROM fermentation_readings fr 
        JOIN batches b ON fr.batch_id = b.id 
        WHERE b.user_id = ? 
        ORDER BY fr.batch_id ASC, fr.reading_date ASC
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    $f = fopen('php://temp', 'r+');
    fputcsv($f, ['Reading ID', 'Batch ID', 'Batch Name', 'Batch Style', 'Date & Time', 'Gravity (SG)', 'Temp (F)', 'Notes']);
    foreach ($rows as $fr) {
        fputcsv($f, [
            sanitize_csv_cell($fr['id']),
            sanitize_csv_cell($fr['batch_id']),
            sanitize_csv_cell($fr['batch_name']),
            sanitize_csv_cell($fr['batch_style'] ?? ''),
            sanitize_csv_cell($fr['reading_date']),
            sanitize_csv_cell($fr['gravity']),
            sanitize_csv_cell($fr['temp_f'] ?? ''),
            sanitize_csv_cell($fr['notes'] ?? '')
        ]);
    }
    rewind($f);
    $csv = stream_get_contents($f);
    fclose($f);
    return $csv;
}

// Helper: Generate Inventory CSV with Formula Injection Guard
function generate_inventory_csv($db, $userId) {
    $stmt = $db->prepare("SELECT * FROM inventory WHERE user_id = ? ORDER BY category ASC, item_name ASC");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    $f = fopen('php://temp', 'r+');
    fputcsv($f, ['ID', 'Item Name', 'Category', 'Quantity', 'Unit', 'Notes', 'Created At']);
    foreach ($rows as $i) {
        fputcsv($f, [
            sanitize_csv_cell($i['id']),
            sanitize_csv_cell($i['item_name']),
            sanitize_csv_cell($i['category'] ?? 'Fermentable'),
            sanitize_csv_cell($i['quantity'] ?? 0),
            sanitize_csv_cell($i['unit'] ?? ''),
            sanitize_csv_cell($i['notes'] ?? ''),
            sanitize_csv_cell($i['created_at'])
        ]);
    }
    rewind($f);
    $csv = stream_get_contents($f);
    fclose($f);
    return $csv;
}

// Helper: Generate XML with safe entity encoding
function generate_beerxml($db, $userId, $username) {
    $stmt = $db->prepare("SELECT * FROM recipes WHERE user_id = ? ORDER BY id ASC");
    $stmt->execute([$userId]);
    $recipes = $stmt->fetchAll();

    $ingStmt = $db->prepare("SELECT * FROM recipe_ingredients WHERE recipe_id = ? ORDER BY id ASC");

    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<RECIPES>\n";
    foreach ($recipes as $r) {
        $batchLiters = round(((float)($r['batch_size_gal'] ?: 5.0)) * 3.78541, 2);
        $ingStmt->execute([$r['id']]);
        $ingredients = $ingStmt->fetchAll();

        $xml .= "  <RECIPE>\n";
        $xml .= "    <NAME>" . htmlspecialchars($r['name'], ENT_XML1, 'UTF-8') . "</NAME>\n";
        $xml .= "    <VERSION>1</VERSION>\n";
        $xml .= "    <TYPE>All Grain</TYPE>\n";
        $xml .= "    <STYLE><NAME>" . htmlspecialchars($r['style'] ?: 'Craft Beer', ENT_XML1, 'UTF-8') . "</NAME><CATEGORY_NUMBER>1</CATEGORY_NUMBER><STYLE_LETTER>A</STYLE_LETTER><STYLE_GUIDE>BJCP 2021</STYLE_GUIDE><TYPE>Ale</TYPE><OG_MIN>1.040</OG_MIN><OG_MAX>1.070</OG_MAX><FG_MIN>1.008</FG_MIN><FG_MAX>1.018</FG_MAX><IBU_MIN>20</IBU_MIN><IBU_MAX>70</IBU_MAX><COLOR_MIN>3</COLOR_MIN><COLOR_MAX>20</COLOR_MAX></STYLE>\n";
        $xml .= "    <BREWER>" . htmlspecialchars($username, ENT_XML1, 'UTF-8') . "</BREWER>\n";
        $xml .= "    <BATCH_SIZE>{$batchLiters}</BATCH_SIZE>\n";
        $xml .= "    <BOIL_SIZE>" . round($batchLiters * 1.25, 2) . "</BOIL_SIZE>\n";
        $xml .= "    <BOIL_TIME>60.0</BOIL_TIME>\n";
        if ($r['target_og']) $xml .= "    <OG>" . sprintf('%.3f', $r['target_og']) . "</OG>\n";
        if ($r['target_fg']) $xml .= "    <FG>" . sprintf('%.3f', $r['target_fg']) . "</FG>\n";
        if ($r['target_abv']) $xml .= "    <EST_ABV>" . sprintf('%.2f', $r['target_abv']) . "</EST_ABV>\n";
        $xml .= "    <NOTES>" . htmlspecialchars($r['instructions'] ?: ($r['ingredients'] ?: ''), ENT_XML1, 'UTF-8') . "</NOTES>\n";

        $xml .= "    <FERMENTABLES>\n";
        foreach ($ingredients as $ing) {
            if ($ing['ingredient_type'] === 'Fermentable') {
                $amountKg = strtolower($ing['unit']) === 'oz' ? round($ing['amount'] * 0.0283495, 3) : round($ing['amount'] * 0.453592, 3);
                $xml .= "      <FERMENTABLE>\n";
                $xml .= "        <NAME>" . htmlspecialchars($ing['name'], ENT_XML1, 'UTF-8') . "</NAME>\n";
                $xml .= "        <VERSION>1</VERSION>\n";
                $xml .= "        <TYPE>Grain</TYPE>\n";
                $xml .= "        <AMOUNT>{$amountKg}</AMOUNT>\n";
                $xml .= "        <YIELD>75.0</YIELD>\n";
                $xml .= "        <COLOR>3.0</COLOR>\n";
                $xml .= "      </FERMENTABLE>\n";
            }
        }
        $xml .= "    </FERMENTABLES>\n";

        $xml .= "    <HOPS>\n";
        foreach ($ingredients as $ing) {
            if ($ing['ingredient_type'] === 'Hop') {
                $amountKg = strtolower($ing['unit']) === 'lbs' ? round($ing['amount'] * 0.453592, 4) : round($ing['amount'] * 0.0283495, 4);
                $xml .= "      <HOP>\n";
                $xml .= "        <NAME>" . htmlspecialchars($ing['name'], ENT_XML1, 'UTF-8') . "</NAME>\n";
                $xml .= "        <VERSION>1</VERSION>\n";
                $xml .= "        <ALPHA>10.0</ALPHA>\n";
                $xml .= "        <AMOUNT>{$amountKg}</AMOUNT>\n";
                $xml .= "        <USE>Boil</USE>\n";
                $xml .= "        <TIME>60.0</TIME>\n";
                $xml .= "      </HOP>\n";
            }
        }
        $xml .= "    </HOPS>\n";

        $xml .= "    <YEASTS>\n";
        foreach ($ingredients as $ing) {
            if ($ing['ingredient_type'] === 'Yeast') {
                $xml .= "      <YEAST>\n";
                $xml .= "        <NAME>" . htmlspecialchars($ing['name'], ENT_XML1, 'UTF-8') . "</NAME>\n";
                $xml .= "        <VERSION>1</VERSION>\n";
                $xml .= "        <TYPE>Ale</TYPE>\n";
                $xml .= "        <FORM>Dry</FORM>\n";
                $xml .= "        <AMOUNT>0.011</AMOUNT>\n";
                $xml .= "      </YEAST>\n";
            }
        }
        $xml .= "    </YEASTS>\n";

        $xml .= "  </RECIPE>\n";
    }
    $xml .= "</RECIPES>\n";
    return $xml;
}

// =========================================================================
// 1. HANDLE DIRECT FILE EXPORTS (ZIP, JSON, CSV, BEERXML)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    $format = validate_enum($_GET['format'] ?? 'zip', ['zip', 'json', 'csv_recipes', 'csv_batches', 'csv_readings', 'csv_inventory', 'beerxml'], 'zip');

    $incRecipes   = isset($_GET['inc_recipes']) ? (bool)$_GET['inc_recipes'] : true;
    $incBatches   = isset($_GET['inc_batches']) ? (bool)$_GET['inc_batches'] : true;
    $incInventory = isset($_GET['inc_inventory']) ? (bool)$_GET['inc_inventory'] : true;
    $incDocuments = isset($_GET['inc_documents']) ? (bool)$_GET['inc_documents'] : true;

    $timestamp = date('Y-m-d_His');
    $sanitizedUser = sanitize_header_filename($user['username']);

    // ---------------------------------------------------------------------
    // A. 1-CLICK COMPLETE ZIP BUNDLE (ALL FORMATS + JSON ARCHIVE)
    // ---------------------------------------------------------------------
    if ($format === 'zip') {
        $zip = new ZipHelper();

        // 1. Add complete lossless JSON backup (import-compatible)
        $jsonData = get_user_export_data($db, $user['id'], $user['username'], $user['email'], $incRecipes, $incBatches, $incInventory, $incDocuments);
        $zip->addFromString('craftbrew_backup.json', json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // 2. Add CSV Spreadsheets
        if ($incRecipes) {
            $zip->addFromString('recipes.csv', generate_recipes_csv($db, $user['id']));
        }
        if ($incBatches) {
            $zip->addFromString('batches.csv', generate_batches_csv($db, $user['id']));
            $zip->addFromString('fermentation_readings.csv', generate_readings_csv($db, $user['id']));
        }
        if ($incInventory) {
            $zip->addFromString('inventory.csv', generate_inventory_csv($db, $user['id']));
        }

        // 3. Add BeerXML recipes
        if ($incRecipes) {
            $zip->addFromString('recipes_all.xml', generate_beerxml($db, $user['id'], $user['username']));
        }

        // 4. Add Readme with instructions
        $readme = "CraftBrew Platform - Complete User Data Export\n"
                . "===============================================\n"
                . "Exported for User: " . sanitize_text($user['username'], 50) . " (" . sanitize_text($user['email'], 100) . ")\n"
                . "Date: " . date('Y-m-d H:i:s T') . "\n"
                . "Platform Version: " . APP_VERSION . "\n\n"
                . "Archive Contents:\n"
                . "-----------------\n"
                . "1. craftbrew_backup.json    - Complete structured backup archive. Upload this file in CraftBrew (under 'Restore / Import Data') to restore all recipes, brew logs, gravity curves, and cellar inventory in 1 click!\n"
                . "2. recipes.csv              - All formulated recipes with gravity targets, batch sizes, and instructions.\n"
                . "3. batches.csv              - All brew batch logs with start/rack/bottling dates, ABV, ratings, and reflections.\n"
                . "4. fermentation_readings.csv- Complete time-series hydrometer specific gravity drop curve data.\n"
                . "5. inventory.csv            - Current cellar stock of grains, hops, yeast strains, and additives.\n"
                . "6. recipes_all.xml          - Standard BeerXML format compatible with Beersmith, Brewfather, and Grainfather.\n\n"
                . "To restore your data:\n"
                . "Log into CraftBrew -> Go to Profile / Data Center -> Upload 'craftbrew_backup.json'.\n";
        $zip->addFromString('README.txt', $readme);

        $zip->download(sanitize_header_filename('craftbrew_complete_backup_' . $sanitizedUser . '_' . $timestamp . '.zip'));
        exit;
    }

    // ---------------------------------------------------------------------
    // B. JSON ARCHIVE
    // ---------------------------------------------------------------------
    if ($format === 'json') {
        $exportData = get_user_export_data($db, $user['id'], $user['username'], $user['email'], $incRecipes, $incBatches, $incInventory, $incDocuments);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_header_filename('craftbrew_backup_' . $sanitizedUser . '_' . $timestamp . '.json') . '"');
        echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------------------------------------------------------------------
    // C. CSV EXPORTS
    // ---------------------------------------------------------------------
    if ($format === 'csv_recipes') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_header_filename('craftbrew_recipes_' . $sanitizedUser . '_' . $timestamp . '.csv') . '"');
        echo generate_recipes_csv($db, $user['id']);
        exit;
    }

    if ($format === 'csv_batches') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_header_filename('craftbrew_batches_' . $sanitizedUser . '_' . $timestamp . '.csv') . '"');
        echo generate_batches_csv($db, $user['id']);
        exit;
    }

    if ($format === 'csv_readings') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_header_filename('craftbrew_fermentation_readings_' . $sanitizedUser . '_' . $timestamp . '.csv') . '"');
        echo generate_readings_csv($db, $user['id']);
        exit;
    }

    if ($format === 'csv_inventory') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_header_filename('craftbrew_inventory_' . $sanitizedUser . '_' . $timestamp . '.csv') . '"');
        echo generate_inventory_csv($db, $user['id']);
        exit;
    }

    // ---------------------------------------------------------------------
    // D. BEERXML EXPORT
    // ---------------------------------------------------------------------
    if ($format === 'beerxml') {
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_header_filename('craftbrew_recipes_' . $sanitizedUser . '_' . $timestamp . '.xml') . '"');
        echo generate_beerxml($db, $user['id'], $user['username']);
        exit;
    }
}

// =========================================================================
// 2. HANDLE DATA IMPORT (STRICT SCHEMA VALIDATION & ATTACK DEFENSE)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_data') {
    require_csrf_token();
    $importMode = validate_enum($_POST['import_mode'] ?? 'merge', ['merge', 'replace'], 'merge');

    if (empty($_FILES['backup_file']['tmp_name']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
        $error = "Please choose a valid CraftBrew backup file (.json) to import.";
    } elseif ($_FILES['backup_file']['size'] > MAX_BACKUP_FILE_BYTES) {
        $error = "The uploaded file exceeds the maximum allowed size (15 MB).";
    } else {
        $tmpFile = $_FILES['backup_file']['tmp_name'];
        $origName = basename($_FILES['backup_file']['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if ($ext !== 'json') {
            $error = "Unsupported file extension. Please upload a CraftBrew JSON backup file (.json).";
        } else {
            $content = file_get_contents($tmpFile);
            
            // Validate JSON syntax strictly with depth limit (OWASP A04/A08)
            $data = null;
            try {
                $data = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
            } catch (Exception $je) {
                $error = "Malformed JSON syntax in uploaded file: " . htmlspecialchars($je->getMessage(), ENT_QUOTES, 'UTF-8');
            }

            if ($data && (!is_array($data) || (!isset($data['recipes']) && !isset($data['batches']) && !isset($data['inventory'])))) {
                $error = "Invalid backup schema. The file must contain valid CraftBrew export structures.";
            } elseif ($data) {
                // Check entity volume limits to prevent DoS / Memory Exhaustion
                $recipeCount = is_array($data['recipes'] ?? null) ? count($data['recipes']) : 0;
                $batchCount  = is_array($data['batches'] ?? null) ? count($data['batches']) : 0;
                $invCount    = is_array($data['inventory'] ?? null) ? count($data['inventory']) : 0;
                $docCount    = is_array($data['documents'] ?? null) ? count($data['documents']) : 0;

                if ($recipeCount > MAX_IMPORT_RECIPES || $batchCount > MAX_IMPORT_BATCHES || $invCount > MAX_IMPORT_INVENTORY || $docCount > MAX_IMPORT_DOCUMENTS) {
                    $error = "Import payload exceeds safety boundaries (Max " . MAX_IMPORT_RECIPES . " recipes, " . MAX_IMPORT_BATCHES . " batches).";
                } else {
                    try {
                        // Ensure all latest columns & tables exist before importing
                        init_schema();

                        $db->beginTransaction();

                        // If REPLACE mode, clear existing user records
                        if ($importMode === 'replace') {
                            $db->prepare("DELETE FROM recipes WHERE user_id = ?")->execute([$user['id']]);
                            $db->prepare("DELETE FROM batches WHERE user_id = ?")->execute([$user['id']]);
                            $db->prepare("DELETE FROM inventory WHERE user_id = ?")->execute([$user['id']]);
                            $db->prepare("DELETE FROM documents WHERE user_id = ?")->execute([$user['id']]);
                        }

                        $stats = [
                            'recipes'     => 0,
                            'ingredients' => 0,
                            'supplies'    => 0,
                            'steps'       => 0,
                            'batches'     => 0,
                            'readings'    => 0,
                            'inventory'   => 0,
                            'documents'   => 0
                        ];

                        $recipeMap = []; // old_recipe_id => new_recipe_id

                        // 1. Import Recipes with strict validation
                        if (!empty($data['recipes']) && is_array($data['recipes'])) {
                            $insR = $db->prepare("
                                INSERT INTO recipes (user_id, category_id, name, style, batch_size_gal, target_pre_og, target_og, target_fg, target_abv, ingredients, instructions, is_public) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $insIng = $db->prepare("
                                INSERT INTO recipe_ingredients (recipe_id, name, ingredient_type, amount, unit, stage_addition, notes) 
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");
                            $insSup = $db->prepare("
                                INSERT INTO recipe_supplies (recipe_id, item_name, category, quantity, is_required, notes) 
                                VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            $insStp = $db->prepare("
                                INSERT INTO recipe_steps (recipe_id, step_number, phase, title, duration, target_temp, instructions) 
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");

                            foreach ($data['recipes'] as $r) {
                                if (!is_array($r)) continue;

                                $catName = sanitize_text($r['category'] ?? 'Beer', 50);
                                $cStmt = $db->prepare("SELECT id FROM categories WHERE name = ?");
                                $cStmt->execute([$catName]);
                                $catId = (int)$cStmt->fetchColumn() ?: 1;

                                $rName = sanitize_text($r['name'] ?? 'Imported Recipe', 100);
                                if (empty($rName)) $rName = 'Imported Recipe';

                                $rAbv = sanitize_float($r['target_abv'] ?? null);
                                if ($rAbv < 0 || $rAbv > 100) $rAbv = null;

                                $insR->execute([
                                    $user['id'],
                                    $catId,
                                    $rName,
                                    sanitize_text($r['style'] ?? '', 100),
                                    validate_batch_size($r['batch_size_gal'] ?? 5.0),
                                    validate_gravity($r['target_pre_og'] ?? null),
                                    validate_gravity($r['target_og'] ?? null),
                                    validate_gravity($r['target_fg'] ?? null),
                                    $rAbv ? round($rAbv, 2) : null,
                                    sanitize_text($r['ingredients_raw'] ?? ($r['ingredients'] ?? ''), 5000),
                                    sanitize_text($r['instructions'] ?? '', 5000),
                                    !empty($r['is_public']) ? 1 : 0
                                ]);
                                $newRId = (int)$db->lastInsertId();
                                $stats['recipes']++;

                                if (!empty($r['id'])) {
                                    $recipeMap[(string)$r['id']] = $newRId;
                                }
                                if (!empty($r['name'])) {
                                    $recipeMap[(string)$r['name']] = $newRId;
                                }

                                // Structured Ingredients
                                if (!empty($r['ingredients']) && is_array($r['ingredients'])) {
                                    $ingCount = 0;
                                    foreach ($r['ingredients'] as $idx => $ing) {
                                        if (!is_array($ing) || $ingCount++ >= 100) break;
                                        $insIng->execute([
                                            $newRId,
                                            sanitize_text($ing['name'] ?? 'Ingredient', 100),
                                            validate_enum($ing['ingredient_type'] ?? 'Fermentable', ['Fermentable', 'Hop', 'Yeast', 'Additive', 'Fining', 'Water', 'Other'], 'Fermentable'),
                                            sanitize_float($ing['amount'] ?? 0),
                                            sanitize_text($ing['unit'] ?? 'lbs', 20),
                                            sanitize_text($ing['stage_addition'] ?? 'Primary', 50),
                                            sanitize_text($ing['notes'] ?? '', 255)
                                        ]);
                                        $stats['ingredients']++;
                                    }
                                }

                                // Structured Supplies
                                if (!empty($r['supplies']) && is_array($r['supplies'])) {
                                    $supCount = 0;
                                    foreach ($r['supplies'] as $idx => $sup) {
                                        if (!is_array($sup) || $supCount++ >= 50) break;
                                        $insSup->execute([
                                            $newRId,
                                            sanitize_text($sup['item_name'] ?? 'Supply', 100),
                                            sanitize_text($sup['category'] ?? 'Equipment', 50),
                                            sanitize_text($sup['quantity'] ?? '1 unit', 50),
                                            !empty($sup['is_required']) ? 1 : 0,
                                            sanitize_text($sup['notes'] ?? '', 255)
                                        ]);
                                        $stats['supplies']++;
                                    }
                                }

                                // Structured Steps
                                if (!empty($r['steps']) && is_array($r['steps'])) {
                                    $stepCount = 0;
                                    foreach ($r['steps'] as $idx => $st) {
                                        if (!is_array($st) || $stepCount++ >= 50) break;
                                        $insStp->execute([
                                            $newRId,
                                            sanitize_int($st['step_number'] ?? ($idx + 1)),
                                            sanitize_text($st['phase'] ?? 'Brew Day', 50),
                                            sanitize_text($st['title'] ?? ($st['step_name'] ?? 'Step'), 150),
                                            sanitize_text($st['duration'] ?? ($st['duration_minutes'] ?? ''), 50),
                                            sanitize_text($st['target_temp'] ?? ($st['target_temp_f'] ?? ''), 30),
                                            sanitize_text($st['instructions'] ?? ($st['description'] ?? ''), 2000)
                                        ]);
                                        $stats['steps']++;
                                    }
                                }
                            }
                        }

                        // 2. Import Batches with strict validation
                        if (!empty($data['batches']) && is_array($data['batches'])) {
                            $insB = $db->prepare("
                                INSERT INTO batches (user_id, recipe_id, category_id, batch_name, batch_type, batch_style, batch_size_gal, date_start, date_rack, date_rack_2, date_rack_3, date_bottle, pitch_temp_f, ferment_temp_f, gravity_pre_og, gravity_og, gravity_sg, gravity_tertiary, gravity_fg, calculated_abv, ingredients, boil_notes, reflections, rating, status) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $insRead = $db->prepare("
                                INSERT INTO fermentation_readings (batch_id, reading_date, gravity, temp_f, notes) 
                                VALUES (?, ?, ?, ?, ?)
                            ");

                            foreach ($data['batches'] as $b) {
                                if (!is_array($b)) continue;

                                $catName = sanitize_text($b['category'] ?? 'Beer', 50);
                                $cStmt = $db->prepare("SELECT id FROM categories WHERE name = ?");
                                $cStmt->execute([$catName]);
                                $catId = (int)$cStmt->fetchColumn() ?: 1;

                                $linkedRecipeId = null;
                                if (!empty($b['linked_recipe']) && isset($recipeMap[(string)$b['linked_recipe']])) {
                                    $linkedRecipeId = $recipeMap[(string)$b['linked_recipe']];
                                } elseif (!empty($b['recipe_id']) && isset($recipeMap[(string)$b['recipe_id']])) {
                                    $linkedRecipeId = $recipeMap[(string)$b['recipe_id']];
                                }

                                $bName = sanitize_text($b['batch_name'] ?? 'Imported Batch', 100);
                                if (empty($bName)) $bName = 'Imported Batch';

                                $bAbv = sanitize_float($b['calculated_abv'] ?? null);
                                if ($bAbv < 0 || $bAbv > 100) $bAbv = null;

                                $insB->execute([
                                    $user['id'],
                                    $linkedRecipeId,
                                    $catId,
                                    $bName,
                                    sanitize_text($b['batch_type'] ?? '', 50),
                                    sanitize_text($b['batch_style'] ?? '', 100),
                                    validate_batch_size($b['batch_size_gal'] ?? 5.0),
                                    validate_date($b['date_start'] ?? null),
                                    validate_date($b['date_rack'] ?? null),
                                    validate_date($b['date_rack_2'] ?? null),
                                    validate_date($b['date_rack_3'] ?? null),
                                    validate_date($b['date_bottle'] ?? null),
                                    validate_temp($b['pitch_temp_f'] ?? ''),
                                    validate_temp($b['ferment_temp_f'] ?? ''),
                                    validate_gravity($b['gravity_pre_og'] ?? null),
                                    validate_gravity($b['gravity_og'] ?? null),
                                    validate_gravity($b['gravity_sg'] ?? null),
                                    validate_gravity($b['gravity_tertiary'] ?? null),
                                    validate_gravity($b['gravity_fg'] ?? null),
                                    $bAbv ? round($bAbv, 2) : null,
                                    sanitize_text($b['ingredients'] ?? '', 5000),
                                    sanitize_text($b['boil_notes'] ?? '', 5000),
                                    sanitize_text($b['reflections'] ?? '', 5000),
                                    validate_rating($b['rating'] ?? 0),
                                    validate_enum($b['status'] ?? 'Primary', ['Planning', 'Primary', 'Secondary', 'Bottling/Aging', 'Completed'], 'Primary')
                                ]);
                                $newBId = (int)$db->lastInsertId();
                                $stats['batches']++;

                                // Fermentation Readings
                                if (!empty($b['readings']) && is_array($b['readings'])) {
                                    $readCount = 0;
                                    foreach ($b['readings'] as $rd) {
                                        if (!is_array($rd) || $readCount++ >= MAX_IMPORT_READINGS_PER_BATCH) break;
                                        $rDate = sanitize_text($rd['reading_date'] ?? date('Y-m-d H:i:s'), 30);
                                        $insRead->execute([
                                            $newBId,
                                            $rDate,
                                            validate_gravity($rd['gravity'] ?? 1.000) ?: 1.000,
                                            validate_temp($rd['temp_f'] ?? ''),
                                            sanitize_text($rd['notes'] ?? '', 1000)
                                        ]);
                                        $stats['readings']++;
                                    }
                                }
                            }
                        }

                        // 3. Import Inventory with strict validation
                        if (!empty($data['inventory']) && is_array($data['inventory'])) {
                            $insInv = $db->prepare("
                                INSERT INTO inventory (user_id, item_name, category, quantity, unit, notes) 
                                VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            $invCount = 0;
                            foreach ($data['inventory'] as $inv) {
                                if (!is_array($inv) || $invCount++ >= MAX_IMPORT_INVENTORY) break;
                                $insInv->execute([
                                    $user['id'],
                                    sanitize_text($inv['item_name'] ?? 'Item', 100),
                                    validate_enum($inv['category'] ?? 'Fermentable', ['Fermentable', 'Hop', 'Yeast', 'Additive', 'Equipment', 'Supply', 'Other'], 'Fermentable'),
                                    max(0, sanitize_float($inv['quantity'] ?? 0)),
                                    sanitize_text($inv['unit'] ?? '', 20),
                                    sanitize_text($inv['notes'] ?? '', 2000)
                                ]);
                                $stats['inventory']++;
                            }
                        }

                        // 4. Import Documents Metadata with strict validation
                        if (!empty($data['documents']) && is_array($data['documents'])) {
                            $insDoc = $db->prepare("
                                INSERT INTO documents (user_id, title, category, filename, original_filename, file_type, description) 
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");
                            $docCount = 0;
                            foreach ($data['documents'] as $doc) {
                                if (!is_array($doc) || $docCount++ >= MAX_IMPORT_DOCUMENTS) break;
                                $fType = strtolower(sanitize_text($doc['file_type'] ?? 'pdf', 10));
                                if (!in_array($fType, ['pdf', 'txt', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'xls', 'xlsx'], true)) {
                                    $fType = 'pdf';
                                }

                                $insDoc->execute([
                                    $user['id'],
                                    sanitize_text($doc['title'] ?? 'Document', 255),
                                    sanitize_text($doc['category'] ?? 'General', 50),
                                    sanitize_header_filename($doc['filename'] ?? 'document.pdf'),
                                    sanitize_header_filename($doc['original_filename'] ?? 'document.pdf'),
                                    $fType,
                                    sanitize_text($doc['description'] ?? '', 2000)
                                ]);
                                $stats['documents']++;
                            }
                        }

                        $db->commit();
                        $importStats = $stats;
                        $message = "Backup data successfully verified and imported into your account!";

                        if ($user['role'] === 'admin') {
                            log_admin_action('import_user_backup', "Imported {$stats['recipes']} recipes, {$stats['batches']} batches, {$stats['inventory']} inventory items", 'user', $user['id']);
                        }

                    } catch (Exception $e) {
                        $db->rollBack();
                        $error = "Data import transaction rolled back due to error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
                    }
                }
            }
        }
    }
}

// Fetch current user counts for live display
$myRecipeCount = (int)$db->query("SELECT COUNT(*) FROM recipes WHERE user_id = " . (int)$user['id'])->fetchColumn();
$myBatchCount  = (int)$db->query("SELECT COUNT(*) FROM batches WHERE user_id = " . (int)$user['id'])->fetchColumn();
$myInvCount    = (int)$db->query("SELECT COUNT(*) FROM inventory WHERE user_id = " . (int)$user['id'])->fetchColumn();
$myDocCount    = (int)$db->query("SELECT COUNT(*) FROM documents WHERE user_id = " . (int)$user['id'])->fetchColumn();

$csrfToken = generate_csrf_token();
$pageTitle = "Data Export & Backup Center - " . APP_NAME;
$activePage = 'profile';
require_once __DIR__ . '/includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
    <div>
        <h1>💾 Data Export &amp; Backup Center</h1>
        <p style="color: var(--text-muted);">Download a complete copy of your brewing records or restore data from an existing backup.</p>
    </div>
    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
        <a href="data_manager.php?action=export&format=zip" class="btn btn-primary" style="font-weight: 700;">⚡ 1-Click Export Everything (ZIP)</a>
        <a href="profile.php" class="btn btn-secondary">&laquo; My Profile</a>
        <a href="index.php" class="btn btn-secondary">Dashboard</a>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div style="background: #dcfce7; color: #166534; padding: 1.25rem; border-radius: 10px; margin-bottom: 1.5rem; border: 1px solid #bbf7d0; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
        <div style="display: flex; align-items: center; gap: 0.5rem; font-weight: 700; font-size: 1.1rem; margin-bottom: 0.5rem;">
            <span>✅</span> <?= e($message) ?>
        </div>
        <?php if (!empty($importStats)): ?>
            <ul style="margin-left: 1.5rem; font-size: 0.95rem; line-height: 1.6;">
                <li><strong>Recipes:</strong> <?= (int)$importStats['recipes'] ?> <?= $importStats['recipes'] > 0 ? "({$importStats['ingredients']} ingredients, {$importStats['supplies']} supplies, {$importStats['steps']} steps)" : "" ?></li>
                <li><strong>Brew Batches:</strong> <?= (int)$importStats['batches'] ?> <?= $importStats['batches'] > 0 ? "({$importStats['readings']} fermentation readings)" : "" ?></li>
                <li><strong>Inventory Items:</strong> <?= (int)$importStats['inventory'] ?> items</li>
                <li><strong>Document Library:</strong> <?= (int)$importStats['documents'] ?> items</li>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!empty($error)): ?>
    <div style="background: #ffe4e6; color: #9f1239; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #fecdd3;">
        <strong>⚠️ Error:</strong> <?= e($error) ?>
    </div>
<?php endif; ?>

<!-- 1-Click Master Export Hero Banner -->
<div class="card" style="background: linear-gradient(135deg, #1c1917 0%, #292524 50%, #451a03 100%); color: #ffffff; border: 1px solid rgba(251, 191, 36, 0.3); border-radius: 14px; padding: 1.75rem 2rem; margin-bottom: 2rem; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1.5rem;">
        <div style="max-width: 650px;">
            <div style="display: inline-block; background: rgba(245, 158, 11, 0.2); border: 1px solid rgba(245, 158, 11, 0.5); color: #fbbf24; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.8rem; font-weight: 700; text-transform: uppercase; margin-bottom: 0.5rem;">
                📦 Single-Click Complete Export
            </div>
            <h2 style="font-size: 1.5rem; color: #ffffff; margin-bottom: 0.35rem;">Download All Your Brewing Records (ZIP)</h2>
            <p style="color: #d6d3d1; font-size: 0.95rem; margin: 0; line-height: 1.5;">
                Generates a complete ZIP archive bundle containing universal CSV spreadsheets (Excel/Google Sheets), standard BeerXML files, and the full lossless CraftBrew JSON backup file for 1-click restoring.
            </p>
        </div>
        <a href="data_manager.php?action=export&format=zip" class="btn btn-primary btn-lg" style="font-size: 1.1rem; padding: 0.85rem 1.75rem; white-space: nowrap; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.2);">
            ⚡ Export Everything (ZIP)
        </a>
    </div>
</div>

<!-- Summary Metrics Pills -->
<div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 2rem;">
    <div class="card" style="flex: 1; min-width: 160px; padding: 1rem; text-align: center;">
        <div style="font-size: 1.5rem; font-weight: 800; color: var(--primary-color);"><?= $myRecipeCount ?></div>
        <small style="color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Recipes</small>
    </div>
    <div class="card" style="flex: 1; min-width: 160px; padding: 1rem; text-align: center;">
        <div style="font-size: 1.5rem; font-weight: 800; color: #10b981;"><?= $myBatchCount ?></div>
        <small style="color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Brew Batches</small>
    </div>
    <div class="card" style="flex: 1; min-width: 160px; padding: 1rem; text-align: center;">
        <div style="font-size: 1.5rem; font-weight: 800; color: #3b82f6;"><?= $myInvCount ?></div>
        <small style="color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Inventory Stock</small>
    </div>
    <div class="card" style="flex: 1; min-width: 160px; padding: 1rem; text-align: center;">
        <div style="font-size: 1.5rem; font-weight: 800; color: #8b5cf6;"><?= $myDocCount ?></div>
        <small style="color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Documents</small>
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 2rem; margin-bottom: 2rem;">

    <!-- ================================================================= -->
    <!-- SECTION 1: CUSTOM EXPORT -->
    <!-- ================================================================= -->
    <div class="card">
        <h2 style="font-size: 1.35rem; margin-bottom: 0.5rem; color: var(--text-dark); display: flex; align-items: center; gap: 0.5rem;">
            <span>📥</span> Custom Selective Export
        </h2>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.5rem;">
            Select specific categories of data you wish to export in individual file formats.
        </p>

        <form method="GET" action="data_manager.php" id="exportForm">
            <input type="hidden" name="action" value="export">

            <!-- Checkboxes for data types -->
            <div style="background: #f8fafc; padding: 1rem; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 1.5rem;">
                <label style="font-weight: 700; display: block; margin-bottom: 0.5rem; font-size: 0.9rem;">Include Data Types:</label>
                <div style="display: flex; flex-direction: column; gap: 0.5rem; font-size: 0.9rem;">
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        <input type="checkbox" name="inc_recipes" value="1" checked>
                        <span><strong>📜 Recipes &amp; Formulations</strong> (<?= $myRecipeCount ?> items)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        <input type="checkbox" name="inc_batches" value="1" checked>
                        <span><strong>🍺 Brew Batches &amp; Gravity Readings</strong> (<?= $myBatchCount ?> logs)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        <input type="checkbox" name="inc_inventory" value="1" checked>
                        <span><strong>📦 Cellar &amp; Stock Inventory</strong> (<?= $myInvCount ?> items)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                        <input type="checkbox" name="inc_documents" value="1" checked>
                        <span><strong>📄 Reference Document Library</strong> (<?= $myDocCount ?> items)</span>
                    </label>
                </div>
            </div>

            <!-- Format Option 1: Complete JSON (Recommended) -->
            <div style="border: 2px solid var(--primary-color); background: #fffbeb; border-radius: 10px; padding: 1.25rem; margin-bottom: 1.25rem;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap;">
                    <div>
                        <strong style="color: #92400e; font-size: 1.05rem; display: flex; align-items: center; gap: 0.5rem;">
                            <span>📦</span> Complete JSON Archive (.json)
                        </strong>
                        <p style="color: #78350f; font-size: 0.85rem; margin: 0.35rem 0 0; line-height: 1.4;">
                            Full-fidelity backup format. Preserves all structured ingredients, mashing steps, and fermentation readings. <strong>Can be re-imported back into CraftBrew anytime!</strong>
                        </p>
                    </div>
                    <button type="submit" name="format" value="json" class="btn btn-primary btn-sm" style="white-space: nowrap;">
                        📥 Download JSON
                    </button>
                </div>
            </div>

            <!-- Format Option 2: Tabular CSV Files -->
            <div style="border: 1px solid var(--border-color); background: #ffffff; border-radius: 10px; padding: 1.25rem; margin-bottom: 1.25rem;">
                <strong style="color: var(--text-dark); font-size: 1rem; display: block; margin-bottom: 0.25rem;">
                    📊 Individual Spreadsheets (CSV)
                </strong>
                <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 0.75rem;">
                    Universal tabular spreadsheet format protected against formula injection (OWASP A03):
                </p>
                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <button type="submit" name="format" value="csv_recipes" class="btn btn-secondary btn-sm">Recipes CSV</button>
                    <button type="submit" name="format" value="csv_batches" class="btn btn-secondary btn-sm">Batches CSV</button>
                    <button type="submit" name="format" value="csv_readings" class="btn btn-secondary btn-sm">Readings CSV</button>
                    <button type="submit" name="format" value="csv_inventory" class="btn btn-secondary btn-sm">Inventory CSV</button>
                </div>
            </div>

            <!-- Format Option 3: BeerXML -->
            <div style="border: 1px solid var(--border-color); background: #ffffff; border-radius: 10px; padding: 1.25rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
                    <div>
                        <strong style="color: var(--text-dark); font-size: 1rem; display: block;">
                            🍺 Standard BeerXML (.xml)
                        </strong>
                        <p style="color: var(--text-muted); font-size: 0.85rem; margin: 0.25rem 0 0;">
                            Industry format compatible with Beersmith, Brewfather, and Grainfather.
                        </p>
                    </div>
                    <button type="submit" name="format" value="beerxml" class="btn btn-secondary btn-sm">
                        Export BeerXML
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- ================================================================= -->
    <!-- SECTION 2: IMPORT / RESTORE DATA -->
    <!-- ================================================================= -->
    <div class="card">
        <h2 style="font-size: 1.35rem; margin-bottom: 0.5rem; color: var(--text-dark); display: flex; align-items: center; gap: 0.5rem;">
            <span>📤</span> Restore / Import Data
        </h2>
        <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 1.5rem;">
            Upload a CraftBrew backup file (<code>craftbrew_backup.json</code> from your ZIP bundle or direct JSON export) to restore records to your account.
        </p>

        <form method="POST" action="data_manager.php" enctype="multipart/form-data" onsubmit="return confirmImport();">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="action" value="import_data">

            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label class="form-label" style="font-weight: 700;">Select CraftBrew Backup File (.json)</label>
                <input type="file" name="backup_file" id="backupFile" class="form-control" accept=".json,application/json" required>
                <small style="color: var(--text-muted);">Choose a <code>.json</code> file (e.g. <code>craftbrew_backup.json</code> extracted from your ZIP).</small>
            </div>

            <div style="background: #f8fafc; padding: 1.25rem; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 700; margin-bottom: 0.75rem;">Import Strategy:</label>

                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <label style="display: flex; align-items: flex-start; gap: 0.75rem; cursor: pointer;">
                        <input type="radio" name="import_mode" value="merge" id="modeMerge" checked style="margin-top: 0.25rem;">
                        <div>
                            <strong style="color: var(--text-dark);">➕ Merge &amp; Append (Safe)</strong>
                            <p style="margin: 0; font-size: 0.85rem; color: var(--text-muted);">
                                Adds all imported recipes, brew logs, readings, and inventory items to your account alongside your existing records.
                            </p>
                        </div>
                    </label>

                    <label style="display: flex; align-items: flex-start; gap: 0.75rem; cursor: pointer;">
                        <input type="radio" name="import_mode" value="replace" id="modeReplace" style="margin-top: 0.25rem;">
                        <div>
                            <strong style="color: #dc2626;">⚠️ Overwrite &amp; Replace</strong>
                            <p style="margin: 0; font-size: 0.85rem; color: var(--text-muted);">
                                Removes your current account records and replaces them with the contents of the backup file.
                            </p>
                        </div>
                    </label>
                </div>
            </div>

            <div style="text-align: right;">
                <button type="submit" class="btn btn-primary btn-lg" style="width: 100%;">
                    ⚡ Upload &amp; Restore Records
                </button>
            </div>
        </form>

        <div style="margin-top: 1.5rem; border-top: 1px solid var(--border-color); padding-top: 1rem;">
            <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">
                💡 <em>Looking to import an individual BeerXML file?</em> You can also use the single recipe importer directly on the <a href="recipes.php">Recipes page</a>.
            </p>
        </div>
    </div>

</div>

<script>
function confirmImport() {
    const file = document.getElementById('backupFile');
    const isReplace = document.getElementById('modeReplace').checked;

    if (!file.files || file.files.length === 0) {
        alert('Please choose a CraftBrew backup file (.json) to import.');
        return false;
    }

    if (isReplace) {
        return confirm('⚠️ WARNING: You selected "Overwrite & Replace". This will replace your current recipes, brew batches, and inventory with the imported file. Are you sure you want to proceed?');
    }

    return confirm('Are you sure you want to import this backup file into your account?');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
