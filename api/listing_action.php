<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

function respond($status, $body) { http_response_code($status); echo json_encode($body); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['error' => 'Use POST']);

$input = json_decode(file_get_contents('php://input'), true);
$listingId = $input['listing_id'] ?? null;
$userId = $input['user_id'] ?? null;
$action = $input['action'] ?? null; // 'WITHDRAW' or 'UNCLAIM'

if (!$listingId || !$userId || !in_array($action, ['WITHDRAW', 'UNCLAIM'])) respond(400, ['error' => 'Invalid input']);

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT * FROM listings WHERE id = ? FOR UPDATE");
    $stmt->execute([$listingId]);
    $listing = $stmt->fetch();
    if (!$listing) { $pdo->rollBack(); respond(404, ['error' => 'Listing not found']); }

    if ($action === 'WITHDRAW') {
        if ($listing['poster_id'] !== $userId) { $pdo->rollBack(); respond(403, ['error' => 'Only poster can withdraw']); }
        if ($listing['status'] !== 'OPEN') { $pdo->rollBack(); respond(422, ['error' => 'Listing must be OPEN to withdraw']); }
        
        $pdo->prepare("UPDATE listings SET status = 'WITHDRAWN' WHERE id = ?")->execute([$listingId]);
        $pdo->prepare("INSERT INTO history (user_id, action, shift_id, outcome) VALUES (?, 'WITHDRAW', ?, 'Listing WITHDRAWN')")->execute([$userId, $listing['shift_id']]);
    } 
    elseif ($action === 'UNCLAIM') {
        if ($listing['claimer_id'] !== $userId) { $pdo->rollBack(); respond(403, ['error' => 'Only claimer can unclaim']); }
        if ($listing['status'] !== 'CLAIMED') { $pdo->rollBack(); respond(422, ['error' => 'Listing must be CLAIMED to unclaim']); }
        
        $pdo->prepare("UPDATE listings SET status = 'OPEN', claimer_id = NULL WHERE id = ?")->execute([$listingId]);
        $pdo->prepare("INSERT INTO history (user_id, action, shift_id, outcome) VALUES (?, 'UNCLAIM', ?, 'Listing UNCLAIMED, now OPEN')")->execute([$userId, $listing['shift_id']]);
    }

    $pdo->commit();
    respond(200, ['success' => true, 'message' => "Action $action successful"]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(500, ['error' => 'Server error']);
}
