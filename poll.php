<?php
require_once 'login.php';

header('Content-Type: application/json');

$job_id = intval($_GET['job_id'] ?? 0);
if ($job_id <= 0) {
    echo json_encode(['error' => 'Invalid job ID']);
    exit();
}

try {
    $dsn  = "mysql:host=127.0.0.1;dbname=$database;charset=utf8mb4";
    $conn = new PDO($dsn, $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['error' => 'DB connection failed']);
    exit();
}

$stmt = $conn->prepare('
    SELECT analysis_type, status, output_file
    FROM analysis
    WHERE job_id = ?
    ORDER BY analysis_id ASC
');
$stmt->execute([$job_id]);
$steps = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['steps' => $steps]);
?>
