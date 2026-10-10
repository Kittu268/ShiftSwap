<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

function respond($status, $body) { http_response_code($status); echo json_encode($body); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['error' => 'Use POST']);

$input = json_decode(file_get_contents('php://input'), true);
$listingId = $input['listing_id'] ?? null;
$managerId = $input['manager_id'] ?? null;
$action = $input['action'] ?? null; // 'APPROVE' or 'REJECT'

if (!$listingId || !$managerId || !in_array($action, ['APPROVE', 'REJECT'])) respond(400, ['error' => 'Invalid input']);

try {
    $pdo->beginTransaction();
    
    // Verify Manager
    $stmt = $pdo->prepare("SELECT role FROM staff WHERE id = ?");
    $stmt->execute([$managerId]);
    if ($stmt->fetchColumn() !== 'MANAGER') { $pdo->rollBack(); respond(403, ['error' => 'Only managers can perform this action']); }

    $stmt = $pdo->prepare("SELECT l.*, s.start_time, s.end_time, s.required_certification FROM listings l JOIN shifts s ON l.shift_id = s.id WHERE l.id = ? FOR UPDATE");
    $stmt->execute([$listingId]);
    $listing = $stmt->fetch();
    
    if (!$listing || $listing['status'] !== 'CLAIMED') { $pdo->rollBack(); respond(422, ['error' => 'Listing must be CLAIMED']); }

    if ($action === 'REJECT') {
        $pdo->prepare("UPDATE listings SET status = 'OPEN', claimer_id = NULL WHERE id = ?")->execute([$listingId]);
        $pdo->prepare("INSERT INTO history (user_id, action, shift_id, outcome) VALUES (?, 'REJECT', ?, 'Manager REJECTED claim, now OPEN')")->execute([$managerId, $listing['shift_id']]);
        $pdo->commit();
        respond(200, ['success' => true, 'message' => 'Claim rejected']);
    }

    // --- RE-VALIDATION FOR APPROVAL (Rules 2-5) ---
    $cId = $listing['claimer_id'];
    $stmt = $pdo->prepare("SELECT certifications FROM staff WHERE id = ?");
    $stmt->execute([$cId]);
    $cCerts = json_decode($stmt->fetchColumn(), true) ?: [];
    
    $sStartTs = strtotime($listing['start_time']);
    $sEndTs = strtotime($listing['end_time']);
    $sHours = ($sEndTs - $sStartTs) / 3600;
    $sWeek = date('oW', $sStartTs);
    
    $violations = [];
    if ($listing['required_certification'] && !in_array($listing['required_certification'], $cCerts)) $violations[] = "Rule 2 fail";
    
    $stmt = $pdo->prepare("SELECT * FROM shifts WHERE assigned_to = ?");
    $stmt->execute([$cId]);
    $cShifts = $stmt->fetchAll();
    
    $weeklyHours = $sHours;
    foreach ($cShifts as $x) {
        $xStartTs = strtotime($x['start_time']);
        $xEndTs = strtotime($x['end_time']);
        if (date('oW', $xStartTs) === $sWeek) $weeklyHours += (($xEndTs - $xStartTs) / 3600);
        
        if (($sStartTs < $xEndTs) && ($xStartTs < $sEndTs)) {
            $violations[] = "Rule 3 fail (overlap)";
        } else {
            $gap = ($xEndTs <= $sStartTs) ? ($sStartTs - $xEndTs)/3600 : ($xStartTs - $sEndTs)/3600;
            if ($gap < 11) $violations[] = "Rule 4 fail (gap < 11h)";
        }
    }
    if ($weeklyHours > 40) $violations[] = "Rule 5 fail (hours > 40)";

    if (!empty($violations)) {
        // If fail, refuse approval and leave listing CLAIMED
        $pdo->prepare("INSERT INTO history (user_id, action, shift_id, outcome) VALUES (?, 'APPROVE_FAIL', ?, 'Approval failed rules check')")->execute([$managerId, $listing['shift_id']]);
        $pdo->commit();
        respond(422, ['success' => false, 'error' => 'Claim rules failed upon approval check', 'violations' => $violations]);
    }

    // Success
    $pdo->prepare("UPDATE listings SET status = 'APPROVED' WHERE id = ?")->execute([$listingId]);
    $pdo->prepare("UPDATE shifts SET assigned_to = ? WHERE id = ?")->execute([$cId, $listing['shift_id']]);
    $pdo->prepare("INSERT INTO history (user_id, action, shift_id, outcome) VALUES (?, 'APPROVE', ?, 'Manager APPROVED claim, shift reassigned')")->execute([$managerId, $listing['shift_id']]);
    
    $pdo->commit();
    respond(200, ['success' => true, 'message' => 'Swap approved and shift reassigned']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(500, ['error' => 'Server error']);
}
