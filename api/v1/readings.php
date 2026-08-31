<?php
/**
 * RESTful API Fermentation Gravity Readings Endpoint:
 * - GET    /api/v1/index.php?route=readings&batch_id={batch_id}    (List readings for batch)
 * - POST   /api/v1/index.php?route=readings                       (Record new hydrometer reading / IoT telemetry)
 * - DELETE /api/v1/index.php?route=readings&id={id}               (Delete reading)
 */

$user = authenticate_api_request();
$db = get_db();
$method = $_SERVER['REQUEST_METHOD'];

// Read JSON input body
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?: $_POST;

if ($method === 'GET') {
    $batchId = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
    if ($batchId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Valid batch_id query parameter is required (?batch_id={id})']);
        exit;
    }

    // Verify ownership
    $chk = $db->prepare("SELECT id, batch_name, gravity_og, gravity_fg, calculated_abv, status FROM batches WHERE id = ? AND user_id = ?");
    $chk->execute([$batchId, $user['id']]);
    $batch = $chk->fetch();

    if (!$batch) {
        http_response_code(404);
        echo json_encode(['error' => 'Batch not found or access denied']);
        exit;
    }

    $stmt = $db->prepare("SELECT * FROM fermentation_readings WHERE batch_id = ? ORDER BY reading_date ASC, id ASC");
    $stmt->execute([$batchId]);
    $readings = $stmt->fetchAll();

    echo json_encode([
        'status'   => 'success',
        'batch_id' => $batchId,
        'count'    => count($readings),
        'batch'    => $batch,
        'readings' => $readings
    ]);

} elseif ($method === 'POST') {
    $batchId     = sanitize_int($input['batch_id'] ?? 0);
    $gravity     = sanitize_float($input['gravity'] ?? ($input['SG'] ?? ($input['specific_gravity'] ?? 0)));
    $tempF       = sanitize_text($input['temp_f'] ?? ($input['temperature'] ?? ($input['temp'] ?? '')), 10);
    $notes       = sanitize_text($input['notes'] ?? ($input['device_name'] ?? ''), 1000);
    $readingDate = validate_date($input['reading_date'] ?? null) ? $input['reading_date'] : date('Y-m-d H:i:s');

    if ($batchId <= 0 || $gravity <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'batch_id and valid numeric gravity (e.g. 1.050) are required']);
        exit;
    }

    // Verify batch ownership
    $chk = $db->prepare("SELECT id, gravity_og, gravity_fg, calculated_abv FROM batches WHERE id = ? AND user_id = ?");
    $chk->execute([$batchId, $user['id']]);
    $batch = $chk->fetch();

    if (!$batch) {
        http_response_code(404);
        echo json_encode(['error' => 'Batch log not found or access denied']);
        exit;
    }

    // Insert reading
    $ins = $db->prepare("INSERT INTO fermentation_readings (batch_id, reading_date, gravity, temp_f, notes) VALUES (?, ?, ?, ?, ?)");
    $ins->execute([$batchId, $readingDate, $gravity, $tempF, $notes]);
    $readingId = (int)$db->lastInsertId();

    // Recalculate current ABV and update batch SG/ABV
    $currentAbv = null;
    if (!empty($batch['gravity_og'])) {
        $fgVal = $batch['gravity_fg'] ?: $gravity;
        $currentAbv = calculate_abv((float)$batch['gravity_og'], (float)$fgVal);
        $up = $db->prepare("UPDATE batches SET gravity_sg = ?, calculated_abv = ? WHERE id = ?");
        $up->execute([$gravity, $currentAbv, $batchId]);
    } else {
        $up = $db->prepare("UPDATE batches SET gravity_sg = ? WHERE id = ?");
        $up->execute([$gravity, $batchId]);
    }

    http_response_code(201);
    echo json_encode([
        'status'                => 'success',
        'message'               => 'Fermentation gravity reading recorded successfully',
        'reading_id'            => $readingId,
        'batch_id'              => $batchId,
        'recorded_gravity'      => $gravity,
        'current_estimated_abv' => $currentAbv
    ]);

} elseif ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Valid reading id is required in query parameter (?id={id})']);
        exit;
    }

    // Verify ownership via parent batch
    $chk = $db->prepare("
        SELECT fr.id, fr.batch_id
        FROM fermentation_readings fr
        JOIN batches b ON fr.batch_id = b.id
        WHERE fr.id = ? AND b.user_id = ?
    ");
    $chk->execute([$id, $user['id']]);
    $reading = $chk->fetch();

    if (!$reading) {
        http_response_code(404);
        echo json_encode(['error' => 'Reading not found or access denied']);
        exit;
    }

    $del = $db->prepare("DELETE FROM fermentation_readings WHERE id = ?");
    $del->execute([$id]);

    echo json_encode([
        'status'             => 'success',
        'message'            => 'Fermentation reading deleted successfully',
        'deleted_reading_id' => $id,
        'batch_id'           => (int)$reading['batch_id']
    ]);

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
}
