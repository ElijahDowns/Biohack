<?php
session_start();
require_once 'login.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

$protein  = trim($_POST['protein']   ?? '');
$taxon    = trim($_POST['taxon']     ?? '');
$max_seqs = intval($_POST['max_seqs'] ?? 50);

$do_align = isset($_POST['do_align']) ? 1 : 0;
$do_motif = isset($_POST['do_motif']) ? 1 : 0;
$do_blast = isset($_POST['do_blast']) ? 1 : 0;
$do_pymol = isset($_POST['do_pymol']) ? 1 : 0;
$do_tree  = isset($_POST['do_tree'])  ? 1 : 0;
$do_hist  = 1;

$errors = [];
if ($protein === '')  $errors[] = 'Please enter a protein family name.';
if ($taxon === '')    $errors[] = 'Please enter a taxonomic group.';
if ($max_seqs < 2)   $errors[] = 'Please request at least 2 sequences.';
if ($max_seqs > 200) $errors[] = 'Maximum sequences is capped at 200.';
if (!$do_align && !$do_motif && !$do_blast && !$do_pymol)
    $errors[] = 'Please select at least one analysis to run.';
if (preg_match('/[;<>&|`$]/', $protein) || preg_match('/[;<>&|`$]/', $taxon))
    $errors[] = 'Invalid characters detected in input.';

if (!empty($errors)) {
    $_SESSION['errors'] = $errors;
    $_SESSION['old']    = ['protein' => $protein, 'taxon' => $taxon, 'max_seqs' => $max_seqs];
    header('Location: index.php');
    exit();
}

try {
    $dsn  = "mysql:host=127.0.0.1;dbname=$database;charset=utf8mb4";
    $conn = new PDO($dsn, $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

$check = shell_exec("python3 -c \"
from Bio import Entrez
Entrez.email = 's2837201@ed.ac.uk'
Entrez.api_key = 'bc81dc27024bce567d64cb201a28e9ad8508'
handle = Entrez.esearch(db='protein', term='" . addslashes($protein) . "[protein] AND " . addslashes($taxon) . "[organism] NOT partial[title]', retmax=0)
record = Entrez.read(handle)
print(record['Count'])
\"");

$count = intval(trim($check));
if ($count < 2) {
    $_SESSION['errors'] = ["No sequences found for \"" . ucfirst($protein) . "\" in \"" . ucfirst($taxon) . "\" on NCBI: found $count. Check your spelling or try a broader term (e.g. Rodentia instead of rodents)."];
    $_SESSION['old'] = ['protein' => $protein, 'taxon' => $taxon, 'max_seqs' => $max_seqs];
    header('Location: index.php');
    exit();
}

$session_id = session_id();
$stmt = $conn->prepare('
    INSERT INTO jobs (session_id, protein, taxon, max_seqs, do_align, do_motif, do_blast, do_pymol, do_tree, do_hist)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
');
$stmt->execute([$session_id, $protein, $taxon, $max_seqs, $do_align, $do_motif, $do_blast, $do_pymol, $do_tree, $do_hist]);
$job_id = $conn->lastInsertId();

$base_dir = '/localdisk/home/s2837201/public_html/ICA/data/job_' . $job_id;
if (!mkdir($base_dir, 0777, true))
    die('Failed to create output directory.');
$analyses = ['fetch', 'histogram'];
if ($do_align) { $analyses[] = 'alignment'; $analyses[] = 'conservation'; }
if ($do_motif) $analyses[] = 'motif';
if ($do_blast) $analyses[] = 'blast';
if ($do_pymol) $analyses[] = 'pymol';

$stmt = $conn->prepare("INSERT INTO analysis (job_id, analysis_type, status) VALUES (?, ?, 'Pending')");
foreach ($analyses as $type) $stmt->execute([$job_id, $type]);

require_once 'write_parsers.php';
write_parsers($job_id, $base_dir, $username, $password, $database);

require_once 'generate_script.php';
$script = generate_script($job_id, $base_dir, $protein, $taxon, $max_seqs,
                           $do_align, $do_motif, $do_blast, $do_pymol,
                           $username, $password, $database);

$script_path = $base_dir . '/run_analysis.sh';
file_put_contents($script_path, $script);
chmod($script_path, 0755);

$log_path = $base_dir . '/log.txt';
exec("nohup bash " . escapeshellarg($script_path) . " > " . escapeshellarg($log_path) . " 2>&1 &");

header("Location: progress.php?job_id={$job_id}");
exit();
?>
