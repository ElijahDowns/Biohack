<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
    && isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > 0) {
    session_start();
    $_SESSION['errors'][] = 'Upload failed: file too large (max 64 MB). '
        . 'Make sure you are uploading a protein FASTA (.faa) not a nucleotide genome (.fna) — '
        . 'protein FASTAs are typically 5–20 MB.';
    header('Location: index.php');
    exit;
}

session_start();
require_once __DIR__ . '/db.php';

define('PIPELINE_DIR', __DIR__); 
define('JOBS_DIR',     __DIR__ . '/jobs');
define('PYTHON_BIN',   getenv('GEMGEN_PYTHON') ?: 'python3');

if (empty($_FILES['genome_file']['tmp_name'])) {
    $_SESSION['errors'][] = 'Please upload a protein FASTA (.faa) genome file.';
    header('Location: index.php');
    exit;
}

$original_name = $_FILES['genome_file']['name'];
$ext           = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

if (!in_array($ext, ['faa', 'fasta', 'fa'])) {
    $_SESSION['errors'][] = 'Invalid file type. Please upload a .faa or .fasta protein FASTA file.';
    header('Location: index.php');
    exit;
}

$faa_tmp = $_FILES['genome_file']['tmp_name'];

$job_id  = uniqid('gemgen_', true);
$job_dir = JOBS_DIR . '/' . $job_id;
mkdir($job_dir, 0755, true);

$genome_path = $job_dir . '/genome.faa';
move_uploaded_file($faa_tmp, $genome_path);

$params = [
    'genome_filename' => $original_name,
    'organism'        => trim($_POST['organism']          ?? ''),
    'carbon_source'   => $_POST['carbon_source']          ?? 'glucose',
    'carbon_conc'     => floatval($_POST['carbon_conc']   ?? 20),
    'carbon_unit'     => $_POST['carbon_unit']            ?? 'g/L',
    'nitrogen_source' => $_POST['nitrogen_source']        ?? 'ammonium_sulfate',
    'nitrogen_conc'   => floatval($_POST['nitrogen_conc'] ?? 2),
    'nitrogen_unit'   => $_POST['nitrogen_unit']          ?? 'g/L',
    'ph'              => floatval($_POST['ph']             ?? 6.5),
    'temperature'     => $_POST['temperature']            ?? '28 °C',
    'rpm'             => $_POST['rpm']                    ?? '200 RPM',
    'volume'          => $_POST['volume']                 ?? '1 L',
    'inoculum'        => floatval($_POST['inoculum']      ?? 5),
    'duration'        => floatval($_POST['duration']      ?? 72),
    'phosphate'       => $_POST['phosphate']              ?? 'kh2po4',
    'phosphate_conc'  => floatval($_POST['phosphate_conc'] ?? 1),
    'phosphate_unit'  => $_POST['phosphate_unit']         ?? 'g/L',
    'submitted_at'    => date('c'),
];

file_put_contents($job_dir . '/params.json', json_encode($params, JSON_PRETTY_PRINT));

file_put_contents($job_dir . '/status.json', json_encode([
    'job_id'        => $job_id,
    'overall'       => 'pending',
    'current_stage' => 'taxon_detect',
    'message'       => 'Job queued — starting pipeline...',
    'stages' => [
        'taxon_detect' => ['status' => 'pending', 'message' => ''],
        'validate'     => ['status' => 'pending', 'message' => ''],
        'load_gem'     => ['status' => 'pending', 'message' => ''],
        'done'         => ['status' => 'pending', 'message' => ''],
    ],
], JSON_PRETTY_PRINT));

try {
    db_create_job($job_id, session_id(), $params);
} catch (Exception $e) {
    error_log("[GEMgen] MySQL insert failed for $job_id: " . $e->getMessage());
}

$log_path = $job_dir . '/pipeline.log';
$cmd = sprintf(
    'cd %s && nohup %s pipeline/pipeline.py --job_id %s --genome %s --out_dir %s --organism %s > %s 2>&1 &',
    escapeshellarg(PIPELINE_DIR),
    escapeshellarg(PYTHON_BIN),
    escapeshellarg($job_id),
    escapeshellarg($genome_path),
    escapeshellarg($job_dir),
    escapeshellarg($params['organism'] ?? ''),
    escapeshellarg($log_path)
);
exec($cmd);

if (!isset($_SESSION['jobs'])) $_SESSION['jobs'] = [];
array_unshift($_SESSION['jobs'], $job_id);
$_SESSION['jobs'] = array_slice($_SESSION['jobs'], 0, 30);

header('Location: progress.php?job_id=' . urlencode($job_id));
exit;
