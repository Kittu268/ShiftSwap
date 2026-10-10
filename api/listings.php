<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

function respond($status, $body) { http_response_code($status); echo json_encode($body); exit; }
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("
        SELECT l.*, s.start_time, s.end_time, s.required_certification, p.name as poster_name, c.name as claimer_name
        FROM listings l
        JOIN shifts s ON l.shift_id = s.id
        JOIN staff p ON l.poster_id = p.id
        LEFT JOIN staff c ON l.claimer_id = c.id
        ORDER BY l.id DESC
    ");
    respond(200, ['listings' => $stmt->fetchAll()]);
} 
elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $shiftId = $input['shift_id'] ?? null;
    $posterId = $input['poster_id'] ?? null;
    if (!$shiftId || !$posterId) respond(400, ['error' => 'shift_id and poster_id required']);

    try {
        $pdo->beginTransaction();
        
        // Rule: Shift has no OPEN or CLAIMED listing
        $stmt = $pdo->prepare("SELECT id FROM listings WHERE shift_id = ? AND status IN ('OPEN', 'CLAIMED') FOR UPDATE");
        $stmt->execute([$shiftId]);
        if ($stmt->fetch()) {
            $pdo->rollBack();
            respond(422, ['error' => 'Shift already has an active listing']);
        }

        $stmt = $pdo->prepare("INSERT INTO listings (shift_id, poster_id, status) VALUES (?, ?, 'OPEN')");
        $stmt->execute([$shiftId, $posterId]);
        
        $stmt = $pdo->prepare("INSERT INTO history (user_id, action, shift_id, outcome) VALUES (?, 'POST', ?, 'Listing OPENED')");
        $stmt->execute([$posterId, $shiftId]);

        $pdo->commit();
        respond(201, ['success' => true, 'message' => 'Listing posted successfully']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(500, ['error' => 'Server error']);
    }
}
