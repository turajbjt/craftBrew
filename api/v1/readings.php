<?php
/**
 * API Gravity Readings Endpoint: POST /api/v1/index.php?route=readings
 */

$user = authenticate_api_request();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?: $_POST;

$batchId     = sanitize_int($input['batch_id'] ?? 0);
$gravity     = sanitize_float($input['gravity'] ?? 0);
$tempF       = sanitize_text($input['temp_f'] ?? '', 10);
$notes       = sanitize_text($input['notes'] ?? '', 1000);
$readingDate = sanitize_text($input['reading_date'] ?? date('Y-m-d H:i:s'), 20);

if ($batchId <= 0 || $gravity <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'batch_id and valid gravity (numeric) are required']);
    exit;
}

// Verify batch ownership
$chk = $db->prepare("SELECT id, gravity_og, gravity_fg FROM batches WHERE id = ? AND user_id = ?");
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

// Calculate current ABV if OG is set and update batch SG/ABV
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

echo json_encode([
    'status' => 'success',
    'message' => 'Gravity reading recorded successfully',
    'batch_id' => $batchId,
    'recorded_gravity' => $gravity,
    'current_estimated_abv' => $currentAbv
]);
