<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

function respond($status, $body) { http_response_code($status); echo json_encode($body); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['error' => 'Use POST']);

$input = json_decode(file_get_contents('php://input'), true);
$listingId = $input['listing_id'] ?? null;
$claimerId = $input['claimer_id'] ?? null;

if (!$listingId || !$claimerId) respond(400, ['error' => 'listing_id and claimer_id required']);

try {
    $pdo->beginTransaction();

    // Lock listing and get shift info
    $stmt = $pdo->prepare("
        SELECT l.*, s.start_time, s.end_time, s.required_certification, s.assigned_to 
        FROM listings l
        JOIN shifts s ON l.shift_id = s.id
        WHERE l.id = ? FOR UPDATE
    ");
    $stmt->execute([$listingId]);
    $listing = $stmt->fetch();

    if (!$listing) {
        $pdo->rollBack();
        respond(404, ['error' => 'Listing not found']);
    }

    // REQUIREMENT: If not OPEN, refuse immediately and check nothing else
    if ($listing['status'] !== 'OPEN') {
        $pdo->rollBack();
        respond(422, ['error' => 'no longer available']);
    }

    $posterId = $listing['poster_id'];
    $sStartTs = strtotime($listing['start_time']);
    $sEndTs = strtotime($listing['end_time']);
    $sHours = ($sEndTs - $sStartTs) / 3600;
    
    // ISO week year and week number (Monday to Monday week calculation)
    $sWeek = date('oW', $sStartTs); 

    // Get claimer data
    $stmt = $pdo->prepare("SELECT * FROM staff WHERE id = ?");
    $stmt->execute([$claimerId]);
    $claimer = $stmt->fetch();
    if (!$claimer) { $pdo->rollBack(); respond(404, ['error' => 'Claimer not found']); }
    
    $cId = $claimer['id'];
    $cCerts = json_decode($claimer['certifications'], true) ?: [];

    $violations = [];

    // Rule 1: C is not P
    if ($cId === $posterId) {
        $pdo->rollBack();
        // REQUIREMENT: If C is P, report ONLY rule 1
        respond(422, ['success' => false, 'violations' => ['Rule 1: You cannot claim your own shift.']]);
    }

    // Rule 2: Certification
    if ($listing['required_certification'] && !in_array($listing['required_certification'], $cCerts)) {
        $violations[] = "Rule 2: Missing required certification ({$listing['required_certification']}).";
    }

    // Get all shifts currently assigned to Claimer
    // "counts as assigned until swap is approved"
    $stmt = $pdo->prepare("SELECT * FROM shifts WHERE assigned_to = ?");
    $stmt->execute([$cId]);
    $cShifts = $stmt->fetchAll();

    $weeklyHours = $sHours; 

    foreach ($cShifts as $x) {
        $xStartTs = strtotime($x['start_time']);
        $xEndTs = strtotime($x['end_time']);
        
        // Accumulate hours if it falls in the same week (Monday-Monday)
        if (date('oW', $xStartTs) === $sWeek) {
            $weeklyHours += (($xEndTs - $xStartTs) / 3600);
        }

        // Rule 3: Overlap calculation (A.start < B.end and B.start < A.end)
        $overlaps = ($sStartTs < $xEndTs) && ($xStartTs < $sEndTs);
        
        if ($overlaps) {
            $violations[] = "Rule 3: Shift overlaps with assigned shift {$x['id']}.";
        } else {
            // Rule 4: 11 hours separation for non-overlapping shifts
            // Gap is either X ends before S starts, or S ends before X starts
            if ($xEndTs <= $sStartTs) {
                $gap = ($sStartTs - $xEndTs) / 3600;
            } else {
                $gap = ($xStartTs - $sEndTs) / 3600;
            }
            
            if ($gap < 11) {
                $violations[] = "Rule 4: Less than 11 hours separation from shift {$x['id']}.";
            }
        }
    }

    // Rule 5: Weekly hours max 40
    if ($weeklyHours > 40) {
        $violations[] = "Rule 5: Claiming this shift exceeds the 40-hour weekly limit (Total would be $weeklyHours hrs).";
    }

    if (!empty($violations)) {
        $pdo->rollBack();
        respond(422, ['success' => false, 'violations' => $violations]);
    }

    // Success: Update listing and add history
    $stmt = $pdo->prepare("UPDATE listings SET status = 'CLAIMED', claimer_id = ? WHERE id = ?");
    $stmt->execute([$cId, $listingId]);

    $stmt = $pdo->prepare("INSERT INTO history (user_id, action, shift_id, outcome) VALUES (?, 'CLAIM', ?, 'Listing CLAIMED successfully')");
    $stmt->execute([$cId, $listing['shift_id']]);

    $pdo->commit();
    respond(200, ['success' => true, 'message' => 'Shift claimed successfully. Pending manager approval.']);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Claim Error: " . $e->getMessage());
    respond(500, ['error' => 'Server error']);
}
