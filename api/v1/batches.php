<?php
/**
 * API Batches Endpoint: GET/POST /api/v1/index.php?route=batches
 */

$user = authenticate_api_request();
$db = get_db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id > 0) {
        // Single batch detail
        $stmt = $db->prepare("
            SELECT b.*, c.name as category_name
            FROM batches b
            JOIN categories c ON b.category_id = c.id
            WHERE b.id = ? AND b.user_id = ?
        ");
        $stmt->execute([$id, $user['id']]);
        $batch = $stmt->fetch();

        if (!$batch) {
            http_response_code(404);
            echo json_encode(['error' => 'Batch not found']);
            exit;
        }

        // Fetch readings
        $rStmt = $db->prepare("SELECT * FROM fermentation_readings WHERE batch_id = ? ORDER BY reading_date ASC");
        $rStmt->execute([$id]);
        $batch['readings'] = $rStmt->fetchAll();

        echo json_encode(['status' => 'success', 'batch' => $batch]);
    } else {
        // List batches
        $stmt = $db->prepare("
            SELECT b.*, c.name as category_name
            FROM batches b
            JOIN categories c ON b.category_id = c.id
            WHERE b.user_id = ?
            ORDER BY b.date_start DESC
        ");
        $stmt->execute([$user['id']]);
        $batches = $stmt->fetchAll();

        echo json_encode(['status' => 'success', 'count' => count($batches), 'batches' => $batches]);
    }
} elseif ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: $_POST;

    $catId      = (int)($input['category_id'] ?? 1);
    $batchName  = trim($input['batch_name'] ?? '');
    $batchType  = trim($input['batch_type'] ?? '');
    $batchStyle = trim($input['batch_style'] ?? '');
    $batchSize  = (float)($input['batch_size_gal'] ?? 5.0);
    $og         = !empty($input['gravity_og']) ? (float)$input['gravity_og'] : null;
    $fg         = !empty($input['gravity_fg']) ? (float)$input['gravity_fg'] : null;
    $ingredients= trim($input['ingredients'] ?? '');
    $status     = trim($input['status'] ?? 'Primary');

    $dateStart  = !empty($input['date_start']) ? trim($input['date_start']) : date('Y-m-d');
    $dateRack   = !empty($input['date_rack']) ? trim($input['date_rack']) : null;
    $dateRack2  = !empty($input['date_rack_2']) ? trim($input['date_rack_2']) : null;
    $dateRack3  = !empty($input['date_rack_3']) ? trim($input['date_rack_3']) : null;
    $dateBottle = !empty($input['date_bottle']) ? trim($input['date_bottle']) : null;

    if (empty($batchName)) {
        http_response_code(400);
        echo json_encode(['error' => 'batch_name is required']);
        exit;
    }

    $abv = calculate_abv($og, $fg);
    $ins = $db->prepare("
        INSERT INTO batches (
            user_id, category_id, batch_name, batch_type, batch_style,
            batch_size_gal, date_start, date_rack, date_rack_2, date_rack_3, date_bottle,
            gravity_og, gravity_fg, calculated_abv, ingredients, status
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?
        )
    ");
    $ins->execute([
        $user['id'], $catId, $batchName, $batchType, $batchStyle,
        $batchSize, $dateStart, $dateRack, $dateRack2, $dateRack3, $dateBottle,
        $og, $fg, $abv, $ingredients, $status
    ]);
    $newId = $db->lastInsertId();

    http_response_code(201);
    echo json_encode([
        'status' => 'success',
        'message' => 'Batch log created successfully',
        'batch_id' => (int)$newId
    ]);
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
}
