<?php
/**
 * RESTful API Batches Endpoint:
 * - GET    /api/v1/index.php?route=batches            (List batches with filters)
 * - GET    /api/v1/index.php?route=batches&id={id}    (Batch detail with readings)
 * - POST   /api/v1/index.php?route=batches            (Create new batch)
 * - PUT    /api/v1/index.php?route=batches&id={id}    (Update batch)
 * - DELETE /api/v1/index.php?route=batches&id={id}    (Delete batch)
 */

$user = authenticate_api_request();
$db = get_db();
$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Read JSON input body for POST/PUT
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?: $_POST;

if ($method === 'GET') {
    if ($id > 0) {
        // Single batch detail
        $stmt = $db->prepare("
            SELECT b.*, c.name as category_name, r.name as recipe_name
            FROM batches b
            JOIN categories c ON b.category_id = c.id
            LEFT JOIN recipes r ON b.recipe_id = r.id
            WHERE b.id = ? AND b.user_id = ?
        ");
        $stmt->execute([$id, $user['id']]);
        $batch = $stmt->fetch();

        if (!$batch) {
            http_response_code(404);
            echo json_encode(['error' => 'Batch not found or access denied']);
            exit;
        }

        // Fetch fermentation readings
        $rStmt = $db->prepare("SELECT * FROM fermentation_readings WHERE batch_id = ? ORDER BY reading_date ASC, id ASC");
        $rStmt->execute([$id]);
        $batch['readings'] = $rStmt->fetchAll();

        // Calculate days in fermentation
        $startDate = !empty($batch['date_start']) ? strtotime($batch['date_start']) : null;
        $batch['days_active'] = $startDate ? (int)ceil((time() - $startDate) / 86400) : 0;

        echo json_encode([
            'status' => 'success',
            'batch'  => $batch
        ]);
    } else {
        // List batches with optional query filters
        $statusFilter   = sanitize_text($_GET['status'] ?? '', 30);
        $categoryFilter = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
        $searchQuery    = sanitize_text($_GET['search'] ?? '', 50);
        $limit          = min(max(1, (int)($_GET['limit'] ?? 50)), 200);
        $offset         = max(0, (int)($_GET['offset'] ?? 0));

        $sql = "
            SELECT b.*, c.name as category_name, r.name as recipe_name,
                   (SELECT COUNT(*) FROM fermentation_readings fr WHERE fr.batch_id = b.id) AS readings_count,
                   (SELECT gravity FROM fermentation_readings fr WHERE fr.batch_id = b.id ORDER BY reading_date DESC, id DESC LIMIT 1) AS latest_reading_gravity
            FROM batches b
            JOIN categories c ON b.category_id = c.id
            LEFT JOIN recipes r ON b.recipe_id = r.id
            WHERE b.user_id = ?
        ";
        $params = [$user['id']];

        if (!empty($statusFilter)) {
            $sql .= " AND b.status = ?";
            $params[] = $statusFilter;
        }
        if ($categoryFilter > 0) {
            $sql .= " AND b.category_id = ?";
            $params[] = $categoryFilter;
        }
        if (!empty($searchQuery)) {
            $sql .= " AND (b.batch_name LIKE ? OR b.batch_style LIKE ?)";
            $params[] = "%{$searchQuery}%";
            $params[] = "%{$searchQuery}%";
        }

        $sql .= " ORDER BY b.date_start DESC, b.id DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $batches = $stmt->fetchAll();

        echo json_encode([
            'status'  => 'success',
            'count'   => count($batches),
            'limit'   => $limit,
            'offset'  => $offset,
            'batches' => $batches
        ]);
    }

} elseif ($method === 'POST') {
    // Create new batch
    $batchName  = sanitize_text($input['batch_name'] ?? '', 100);
    $recipeId   = !empty($input['recipe_id']) ? (int)$input['recipe_id'] : null;
    $catId      = max(1, (int)($input['category_id'] ?? 1));
    $batchType  = sanitize_text($input['batch_type'] ?? '', 50);
    $batchStyle = sanitize_text($input['batch_style'] ?? '', 100);
    $batchSize  = validate_batch_size($input['batch_size_gal'] ?? 5.0);

    $dateStart  = validate_date($input['date_start'] ?? date('Y-m-d')) ?: date('Y-m-d');
    $dateRack   = validate_date($input['date_rack'] ?? null);
    $dateRack2  = validate_date($input['date_rack_2'] ?? null);
    $dateRack3  = validate_date($input['date_rack_3'] ?? null);
    $dateBottle = validate_date($input['date_bottle'] ?? null);

    $pitchTemp   = validate_temp($input['pitch_temp_f'] ?? '');
    $fermentTemp = validate_temp($input['ferment_temp_f'] ?? '');

    $preOg    = validate_gravity($input['gravity_pre_og'] ?? null);
    $og       = validate_gravity($input['gravity_og'] ?? null);
    $sg       = validate_gravity($input['gravity_sg'] ?? null);
    $tertiary = validate_gravity($input['gravity_tertiary'] ?? null);
    $fg       = validate_gravity($input['gravity_fg'] ?? null);

    $abvInput = sanitize_float($input['calculated_abv'] ?? null);
    $calcAbv  = ($abvInput !== null && $abvInput >= 0) ? round($abvInput, 2) : calculate_abv($og, $fg ?: $sg);

    $ingredients = sanitize_text($input['ingredients'] ?? '', 5000);
    $boilNotes   = sanitize_text($input['boil_notes'] ?? '', 5000);
    $reflections = sanitize_text($input['reflections'] ?? '', 5000);
    $rating      = validate_rating($input['rating'] ?? 0);

    $allowedStatuses = ['Planning', 'Must Prep', 'Primary', 'Secondary', 'Bottling/Aging', 'Completed'];
    $status = validate_enum($input['status'] ?? 'Primary', $allowedStatuses, 'Primary');

    if (empty($batchName)) {
        http_response_code(400);
        echo json_encode(['error' => 'batch_name is required']);
        exit;
    }

    $ins = $db->prepare("
        INSERT INTO batches (
            user_id, recipe_id, category_id, batch_name, batch_type, batch_style,
            batch_size_gal, date_start, date_rack, date_rack_2, date_rack_3, date_bottle,
            pitch_temp_f, ferment_temp_f, gravity_pre_og, gravity_og, gravity_sg,
            gravity_tertiary, gravity_fg, calculated_abv, ingredients, boil_notes,
            reflections, rating, status
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?
        )
    ");
    $ins->execute([
        $user['id'], $recipeId, $catId, $batchName, $batchType, $batchStyle,
        $batchSize, $dateStart, $dateRack, $dateRack2, $dateRack3, $dateBottle,
        $pitchTemp, $fermentTemp, $preOg, $og, $sg,
        $tertiary, $fg, $calcAbv, $ingredients, $boilNotes,
        $reflections, $rating, $status
    ]);
    $newId = (int)$db->lastInsertId();

    // Auto-deduct inventory if requested
    $deductedItems = 0;
    if (!empty($input['deduct_inventory']) && $recipeId > 0) {
        $deductedItems = deduct_inventory_for_batch($user['id'], $recipeId);
    }

    http_response_code(201);
    echo json_encode([
        'status'         => 'success',
        'message'        => 'Batch log created successfully',
        'batch_id'       => $newId,
        'calculated_abv' => $calcAbv,
        'inventory_deducted' => $deductedItems
    ]);

} elseif ($method === 'PUT' || $method === 'PATCH') {
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Valid batch id is required in query parameter (?id={id})']);
        exit;
    }

    // Check ownership
    $chk = $db->prepare("SELECT * FROM batches WHERE id = ? AND user_id = ?");
    $chk->execute([$id, $user['id']]);
    $existing = $chk->fetch();

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Batch not found or access denied']);
        exit;
    }

    $batchName  = isset($input['batch_name']) ? sanitize_text($input['batch_name'], 100) : $existing['batch_name'];
    $recipeId   = array_key_exists('recipe_id', $input) ? ($input['recipe_id'] ? (int)$input['recipe_id'] : null) : $existing['recipe_id'];
    $catId      = isset($input['category_id']) ? max(1, (int)$input['category_id']) : (int)$existing['category_id'];
    $batchType  = isset($input['batch_type']) ? sanitize_text($input['batch_type'], 50) : $existing['batch_type'];
    $batchStyle = isset($input['batch_style']) ? sanitize_text($input['batch_style'], 100) : $existing['batch_style'];
    $batchSize  = isset($input['batch_size_gal']) ? validate_batch_size($input['batch_size_gal']) : (float)$existing['batch_size_gal'];

    $dateStart  = isset($input['date_start']) ? validate_date($input['date_start']) : $existing['date_start'];
    $dateRack   = isset($input['date_rack']) ? validate_date($input['date_rack']) : $existing['date_rack'];
    $dateRack2  = isset($input['date_rack_2']) ? validate_date($input['date_rack_2']) : $existing['date_rack_2'];
    $dateRack3  = isset($input['date_rack_3']) ? validate_date($input['date_rack_3']) : $existing['date_rack_3'];
    $dateBottle = isset($input['date_bottle']) ? validate_date($input['date_bottle']) : $existing['date_bottle'];

    $pitchTemp   = isset($input['pitch_temp_f']) ? validate_temp($input['pitch_temp_f']) : $existing['pitch_temp_f'];
    $fermentTemp = isset($input['ferment_temp_f']) ? validate_temp($input['ferment_temp_f']) : $existing['ferment_temp_f'];

    $preOg    = array_key_exists('gravity_pre_og', $input) ? validate_gravity($input['gravity_pre_og']) : $existing['gravity_pre_og'];
    $og       = array_key_exists('gravity_og', $input) ? validate_gravity($input['gravity_og']) : $existing['gravity_og'];
    $sg       = array_key_exists('gravity_sg', $input) ? validate_gravity($input['gravity_sg']) : $existing['gravity_sg'];
    $tertiary = array_key_exists('gravity_tertiary', $input) ? validate_gravity($input['gravity_tertiary']) : $existing['gravity_tertiary'];
    $fg       = array_key_exists('gravity_fg', $input) ? validate_gravity($input['gravity_fg']) : $existing['gravity_fg'];

    $calcAbv = calculate_abv($og, $fg ?: $sg);
    if (isset($input['calculated_abv']) && sanitize_float($input['calculated_abv']) !== null) {
        $calcAbv = round(sanitize_float($input['calculated_abv']), 2);
    }

    $ingredients = isset($input['ingredients']) ? sanitize_text($input['ingredients'], 5000) : $existing['ingredients'];
    $boilNotes   = isset($input['boil_notes']) ? sanitize_text($input['boil_notes'], 5000) : $existing['boil_notes'];
    $reflections = isset($input['reflections']) ? sanitize_text($input['reflections'], 5000) : $existing['reflections'];
    $rating      = isset($input['rating']) ? validate_rating($input['rating']) : (int)$existing['rating'];

    $allowedStatuses = ['Planning', 'Must Prep', 'Primary', 'Secondary', 'Bottling/Aging', 'Completed'];
    $status = isset($input['status']) ? validate_enum($input['status'], $allowedStatuses, $existing['status']) : $existing['status'];

    $up = $db->prepare("
        UPDATE batches SET
            recipe_id = ?, category_id = ?, batch_name = ?, batch_type = ?, batch_style = ?,
            batch_size_gal = ?, date_start = ?, date_rack = ?, date_rack_2 = ?, date_rack_3 = ?, date_bottle = ?,
            pitch_temp_f = ?, ferment_temp_f = ?, gravity_pre_og = ?, gravity_og = ?, gravity_sg = ?,
            gravity_tertiary = ?, gravity_fg = ?, calculated_abv = ?, ingredients = ?, boil_notes = ?,
            reflections = ?, rating = ?, status = ?
        WHERE id = ? AND user_id = ?
    ");
    $up->execute([
        $recipeId, $catId, $batchName, $batchType, $batchStyle,
        $batchSize, $dateStart, $dateRack, $dateRack2, $dateRack3, $dateBottle,
        $pitchTemp, $fermentTemp, $preOg, $og, $sg,
        $tertiary, $fg, $calcAbv, $ingredients, $boilNotes,
        $reflections, $rating, $status,
        $id, $user['id']
    ]);

    echo json_encode([
        'status'         => 'success',
        'message'        => 'Batch log updated successfully',
        'batch_id'       => $id,
        'calculated_abv' => $calcAbv
    ]);

} elseif ($method === 'DELETE') {
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Valid batch id is required in query parameter (?id={id})']);
        exit;
    }

    $chk = $db->prepare("SELECT id FROM batches WHERE id = ? AND user_id = ?");
    $chk->execute([$id, $user['id']]);
    if (!$chk->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Batch not found or access denied']);
        exit;
    }

    $del = $db->prepare("DELETE FROM batches WHERE id = ? AND user_id = ?");
    $del->execute([$id, $user['id']]);

    echo json_encode([
        'status'  => 'success',
        'message' => 'Batch and associated readings deleted successfully',
        'deleted_batch_id' => $id
    ]);

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
}
