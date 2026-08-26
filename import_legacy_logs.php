<?php
/**
 * Legacy Log & Reference File Importer
 * Parses homebrew_log.txt & homebrew_cider.txt and populates structured recipe components
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth_check.php';

$sourceDir = __DIR__ . '/legacy_import';
$logFilePath = $sourceDir . '/homebrew_log.txt';

echo "<h2>Starting Import of Legacy Brew Logs, Structured Recipes & Reference Files...</h2>\n";

try {
    $db = get_db();
    init_schema();

    // Ensure default user exists
    $stmt = $db->prepare("SELECT id FROM users WHERE username = 'brewer'");
    $stmt->execute();
    $user = $stmt->fetch();
    if (!$user) {
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $token = generate_api_token();
        $ins = $db->prepare("INSERT INTO users (username, email, password_hash, role, api_token) VALUES ('brewer', 'brewer@example.com', ?, 'brewer', ?)");
        $ins->execute([$hash, $token]);
        $userId = $db->lastInsertId();
        echo "<p style='color:green;'>Created default user 'brewer' (ID: $userId, default password: password123)</p>\n";
    } else {
        $userId = $user['id'];
        echo "<p>Using user 'brewer' (ID: $userId)</p>\n";
    }

    // Fetch categories lookup table
    $catStmt = $db->query("SELECT id, name FROM categories");
    $categories = [];
    while ($row = $catStmt->fetch()) {
        $categories[strtolower($row['name'])] = $row['id'];
    }

    // 1. Seed Default Structured Recipes (Cider, Stout, IPA)
    $ciderCatId = $categories['cider'] ?? 3;
    $chkR = $db->prepare("SELECT id FROM recipes WHERE name = ?");
    $chkR->execute(['Simple Hard Cider Guide']);
    if (!$chkR->fetch()) {
        $insR = $db->prepare("INSERT INTO recipes (user_id, category_id, name, style, batch_size_gal, target_og, target_fg, target_abv, ingredients, instructions, is_public) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insR->execute([
            $userId, $ciderCatId, 'Simple Hard Cider Guide', 'Hard Cider', 5.0, 1.064, 1.005, 9.00,
            "- 5 gal Fresh Orchard Apple Juice (cloudier, unfiltered)\n- 4 lbs Table Sugar (boiled 10-15 min)\n- 1 pkt Nottingham or Muntons Dry Yeast",
            "1. Boil sugar in filtered water for 10-15 mins.\n2. Mix boiled sugar syrup into apple juice in fermenter.\n3. Cool to 70-74F and pitch yeast.\n4. Ferment 2-3 months until clear.",
            1
        ]);
        $ciderRecipeId = $db->lastInsertId();

        save_recipe_details($ciderRecipeId, [
            ['name' => 'Fresh Orchard Apple Cider Juice', 'ingredient_type' => 'Fermentable', 'amount' => 5.0, 'unit' => 'Gal', 'stage_addition' => 'Primary', 'notes' => 'Unfiltered, cloudier orchard juice preferred'],
            ['name' => 'Table Sugar (Sucrose)', 'ingredient_type' => 'Fermentable', 'amount' => 4.0, 'unit' => 'lbs', 'stage_addition' => 'Primary', 'notes' => 'Boil 10-15 minutes in water before adding'],
            ['name' => 'Nottingham Dry Yeast', 'ingredient_type' => 'Yeast', 'amount' => 1.0, 'unit' => 'pkt', 'stage_addition' => 'Primary', 'notes' => 'Pitch at 70-74°F']
        ], [
            ['item_name' => '5 Gallon Glass Carboy / Fermenter', 'category' => 'Equipment', 'quantity' => '1 unit', 'is_required' => 1],
            ['item_name' => 'Air Lock & Rubber Stopper', 'category' => 'Equipment', 'quantity' => '1 unit', 'is_required' => 1],
            ['item_name' => 'StarSan Sanitizer', 'category' => 'Sanitation', 'quantity' => '1 bottle', 'is_required' => 1],
            ['item_name' => 'Hydrometer & Test Jar', 'category' => 'Measuring', 'quantity' => '1 set', 'is_required' => 1],
            ['item_name' => 'Auto-Siphon & Tubing', 'category' => 'Packaging', 'quantity' => '1 unit', 'is_required' => 1]
        ], [
            ['phase' => 'Sanitation', 'title' => 'Sanitize Fermenter & Air Lock', 'duration' => '15 mins', 'target_temp' => 'Room Temp', 'instructions' => 'Thoroughly sanitize 5-gallon carboy, air lock, funnel, and stopper using StarSan.'],
            ['phase' => 'Boil', 'title' => 'Boil Sugar Solution', 'duration' => '15 mins', 'target_temp' => '212°F', 'instructions' => 'Boil 4 lbs table sugar in 8 cups filtered water for 10-15 minutes to sterilize.'],
            ['phase' => 'Pitching', 'title' => 'Combine Juice & Pitch Yeast', 'duration' => '30 mins', 'target_temp' => '72°F', 'instructions' => 'Add apple juice and cooled sugar syrup to fermenter. Aerate well and pitch yeast.'],
            ['phase' => 'Primary', 'title' => 'Primary Fermentation', 'duration' => '2-3 months', 'target_temp' => '68°F', 'instructions' => 'Keep in dark cool fermenting location. Allow to clarify completely. Target FG ~1.005.'],
            ['phase' => 'Bottling', 'title' => 'Rack & Bottle', 'duration' => '1 day', 'target_temp' => 'Room Temp', 'instructions' => 'Siphon clear hard cider into bottles. Age for additional smoothness.']
        ]);
        echo "<p style='color:green;'>Created default structured recipe 'Simple Hard Cider Guide' with ingredients, equipment, and step schedule!</p>\n";
    }

    // 2. Import homebrew_log.txt if present
    if (file_exists($logFilePath)) {
        $rawContent = file_get_contents($logFilePath);
        $blocks = explode('-----------------------------------------', $rawContent);
        $importedCount = 0;

        foreach ($blocks as $block) {
            $block = trim($block);
            if (empty($block)) continue;

            preg_match('/Date:\s*(.+)/i', $block, $mDate);
            preg_match('/Kit:\s*(.+)/i', $block, $mKit);
            preg_match('/Type:\s*(.+)/i', $block, $mType);
            preg_match('/Size:\s*(.+)/i', $block, $mSize);

            preg_match('/Ingredents:\s*(.*?)(?=Origial Gravity|Original Gravity|Pitch Temp|$)/is', $block, $mIng);
            preg_match('/(?:Origial Gravity|Original Gravity):\s*(.+)/i', $block, $mOg);
            preg_match('/ABV:\s*(.+)/i', $block, $mAbv);
            preg_match('/Pitch Temp:\s*(.+)/i', $block, $mPitch);
            preg_match('/Ferment Temp|Fermet Temp:\s*(.+)/i', $block, $mFerm);
            preg_match('/Rack Date:\s*(.+)/i', $block, $mRack);
            preg_match('/Final Gravity:\s*(.+)/i', $block, $mFg);
            preg_match('/Comments:\s*(.*?)(?=Rating:|$)/is', $block, $mComm);
            preg_match('/Rating:\s*(.+) of 10/i', $block, $mRating);

            $dateRaw = trim($mDate[1] ?? '');
            $kit = trim($mKit[1] ?? '');
            $type = trim($mType[1] ?? 'Unknown Brew');
            $sizeRaw = trim($mSize[1] ?? '5.0 Gal');
            $ingredientsRaw = trim($mIng[1] ?? '');
            $ogRaw = trim($mOg[1] ?? '');
            $abvRaw = trim($mAbv[1] ?? '');
            $pitchTemp = trim($mPitch[1] ?? '');
            $fermTemp = trim($mFerm[1] ?? '');
            $rackDateRaw = trim($mRack[1] ?? '');
            $fgRaw = trim($mFg[1] ?? '');
            $comments = trim($mComm[1] ?? '');
            $ratingRaw = trim($mRating[1] ?? '0');

            $dateStart = null;
            if ($dateRaw) {
                $dt = DateTime::createFromFormat('m/d/y', $dateRaw);
                if ($dt) $dateStart = $dt->format('Y-m-d');
            }

            $dateRack = null;
            if ($rackDateRaw) {
                $dtRack = DateTime::createFromFormat('m/d/y', $rackDateRaw);
                if ($dtRack) $dateRack = $dtRack->format('Y-m-d');
            }

            $batchSize = 5.0;
            if (preg_match('/([\d\.]+)/', $sizeRaw, $mSizeNum)) {
                $batchSize = (float)$mSizeNum[1];
            }

            $og = is_numeric($ogRaw) ? (float)$ogRaw : null;
            $fg = is_numeric($fgRaw) ? (float)$fgRaw : null;

            $abv = null;
            if ($og && $fg) {
                $abv = calculate_abv($og, $fg);
            } elseif (preg_match('/([\d\.]+)/', $abvRaw, $mAbvNum)) {
                $abv = (float)$mAbvNum[1];
            }

            $typeLower = strtolower($type);
            $catId = $categories['beer'];
            if (strpos($typeLower, 'cider') !== false) {
                $catId = $categories['cider'];
            } elseif (strpos($typeLower, 'wine') !== false) {
                $catId = strpos($typeLower, 'fruit') !== false ? ($categories['fruit wine'] ?? $categories['wine']) : $categories['wine'];
            }

            $rating = is_numeric($ratingRaw) ? (float)$ratingRaw : 0;
            $batchName = ($kit && $kit !== 'None') ? "$kit $type" : $type;

            $chkStmt = $db->prepare("SELECT id FROM batches WHERE user_id = ? AND batch_name = ? AND date_start = ?");
            $chkStmt->execute([$userId, $batchName, $dateStart]);
            if (!$chkStmt->fetch()) {
                $insBatch = $db->prepare("
                    INSERT INTO batches (
                        user_id, category_id, batch_name, batch_type, batch_style,
                        batch_size_gal, date_start, date_rack, pitch_temp_f, ferment_temp_f,
                        gravity_og, gravity_fg, calculated_abv, ingredients, reflections,
                        rating, status
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, 'Completed'
                    )
                ");
                $insBatch->execute([
                    $userId, $catId, $batchName, $type, $type,
                    $batchSize, $dateStart, $dateRack, $pitchTemp, $fermTemp,
                    $og, $fg, $abv, $ingredientsRaw, $comments,
                    $rating
                ]);
                $importedCount++;
            }
        }
        echo "<p style='color:green;'>Imported historical batch logs from homebrew_log.txt!</p>\n";
    }

    // 3. Import Reference Documents
    if (!is_dir(DOC_UPLOAD_DIR)) {
        mkdir(DOC_UPLOAD_DIR, 0755, true);
    }

    $docFiles = glob($sourceDir . '/*.*');
    $importedDocs = 0;

    foreach ($docFiles as $file) {
        $basename = basename($file);
        if ($basename === 'homebrew_log.txt') continue;

        $targetPath = DOC_UPLOAD_DIR . $basename;
        copy($file, $targetPath);

        $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
        $fileType = $ext === 'pdf' ? 'PDF Document' : ($ext === 'txt' ? 'Text Note' : 'Image');

        $title = str_replace(['_', '-'], ' ', pathinfo($basename, PATHINFO_FILENAME));
        $title = ucwords($title);

        $chkDoc = $db->prepare("SELECT id FROM documents WHERE filename = ?");
        $chkDoc->execute([$basename]);
        if (!$chkDoc->fetch()) {
            $insDoc = $db->prepare("INSERT INTO documents (user_id, title, category, filename, file_type, description) VALUES (?, ?, 'Reference', ?, ?, ?)");
            $desc = "Imported reference file: " . $basename;
            if ($basename === 'homebrew_cider.txt') {
                $desc = "Simple Hard Cider Recipe Guide & Tips";
            }
            $insDoc->execute([$userId, $title, $basename, $fileType, $desc]);
            $importedDocs++;
        }
    }
    echo "<p style='color:green;'>Imported reference files into Document Library!</p>\n";

    echo "<p><strong>Import completed successfully!</strong> <a href='index.php'>Go to Dashboard &raquo;</a></p>\n";

} catch (Exception $e) {
    echo "<p style='color:red;'>Import Error: " . htmlspecialchars($e->getMessage()) . "</p>\n";
}
