<?php
$host = '127.0.0.1';
$db   = 'shiftswap';
$user = 'root';
$pass = '';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$json = file_get_contents(__DIR__ . '/ShiftSwap_seed.json');
if ($json === false) die("Error: Could not find ShiftSwap_seed.json\n");

$data = json_decode($json, true);
if ($data === null) die("JSON Error: " . json_last_error_msg() . "\n");

// Clear tables just in case
$conn->query("TRUNCATE TABLE staff");
$conn->query("TRUNCATE TABLE shifts");

foreach ($data['staff'] as $s) {
    $certs = json_encode($s['certifications']);
    $conn->query("INSERT INTO staff (id, name, role, certifications) VALUES ('{$s['id']}', '{$s['name']}', '{$s['role']}', '$certs')");
}

foreach ($data['shifts'] as $sh) {
    $req = $sh['required_certification'] ? "'{$sh['required_certification']}'" : "NULL";
    $conn->query("INSERT INTO shifts (id, start_time, end_time, required_certification, assigned_to) VALUES ('{$sh['id']}', '{$sh['start']}', '{$sh['end']}', $req, '{$sh['assigned_to']}')");
}

echo "ShiftSwap database seeded perfectly!\n";
$conn->close();
?>
