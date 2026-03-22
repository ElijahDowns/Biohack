<?php
session_start();
require_once __DIR__ . '/db.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>FunGem — My Results</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:ital,wght@0,400;0,700;1,400&family=Syne:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="header">
    <div class="container header-inner">
        <div class="logo-block">
            <h1><span>Fun</span>Gem</h1>
            <p>Genome-Scale Metabolic Model Generator</p>
        </div>
        <!-- LOGO PLACEHOLDER -->
    </div>
</div>
<div class="menu">
    <div class="container">
        <a href="index.php">Home</a>
        <a href="history.php" class="active">My Results</a>
    </div>
</div>
<div class="container"><div class="content">

<h2>My Results</h2>
<p>All pipeline runs from this session, pulled from the database.</p>

<?php
$jobs = [];
try {
    $jobs = db_get_jobs_by_session(session_id());
} catch (Exception $e) {
    echo '<div class="error">Could not load history: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

if (empty($jobs)):
?>
<div class="infobox">
    No runs yet this session. <a href="index.php">Start a new run &rarr;</a>
</div>
<?php else: ?>

<div class="history-table">
    <div class="history-row history-row--header">
        <span>Genome</span>
        <span>Organism detected</span>
        <span>Submitted</span>
        <span>Status</span>
        <span>Action</span>
    </div>
    <?php foreach ($jobs as $job):
        $status = $job['status'];
        $submitted = date('d M Y H:i', strtotime($job['submitted_at']));
    ?>
    <div class="history-row">
        <span><code><?= htmlspecialchars($job['genome_filename']) ?></code></span>
        <span><?= htmlspecialchars($job['taxon_detected'] ?? $job['organism'] ?? '—') ?></span>
        <span><?= $submitted ?></span>
        <span>
            <span class="history-status history-status--<?= $status ?>">
                <?= htmlspecialchars($status) ?>
            </span>
        </span>
        <span>
            <?php if ($status === 'done'): ?>
                <a href="results.php?job_id=<?= urlencode($job['job_id']) ?>"
                   class="btn-history-view">View Results</a>
            <?php elseif (in_array($status, ['pending', 'running'])): ?>
                <a href="progress.php?job_id=<?= urlencode($job['job_id']) ?>"
                   class="btn-history-view btn-history-view--running">In Progress</a>
            <?php elseif ($status === 'fungi_error'): ?>
                <span class="history-status history-status--error">Non-fungal</span>
            <?php elseif ($status === 'error'): ?>
                <span class="history-status history-status--error">&#10007; Failed</span>
            <?php else: ?>
                <span class="history-status">—</span>
            <?php endif; ?>
        </span>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<hr>
<p style="font-size:0.82rem;color:var(--text-dim);">
    Results are stored in the database for the duration of your session.
</p>

</div></div>
<div class="footer">
    <div class="container">
        <p>FunGem &mdash; Pacifico Biolabs GmbH &times; BioHack Challenge 6</p>
    </div>
</div>
</body>
</html>
