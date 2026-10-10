<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
try {
    $staff =$pdo->query("SELECT * FROM staff")->fetchAll();
    $shifts =$pdo->query("SELECT * FROM shifts")->fetchAll();
    $history =$pdo->query("SELECT h.*, u.name as user_name FROM history h JOIN staff u ON h.user_id = u.id ORDER BY h.id DESC")->fetchAll();
    echo json_encode(['staff' => $staff, 'shifts' => $shifts, 'history' =>$history]);
} catch (Throwable $e) {
    http_response_code(500); echo json_encode(['error' => 'Server error']);
}
?>
