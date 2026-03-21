<?php
session_start();
require_once __DIR__ . '/db.php';

$job_id  = preg_replace('/[^a-zA-Z0-9_\.]/', '', $_GET['job_id'] ?? '');
$job_dir = __DIR__ . '/jobs/' . $job_id;

// ── AJAX poll endpoint ────────────────────────────────────────────────────────
if (isset($_GET['poll'])) {
    header('Content-Type: application/json');

    // Read status.json written by pipeline.py
    $status_file = $job_dir . '/status.json';
    if (file_exists($status_file)) {
        $status = json_decode(file_get_contents($status_file), true);

        // On done/error: sync MySQL
        $overall = $status['overall'] ?? 'pending';
        if (in_array($overall, ['done', 'error', 'fungi_error'])) {
            try {
                $results_file = $job_dir . '/results.json';
                if ($overall === 'done' && file_exists($results_file)) {
                    $results = json_decode(file_get_contents($results_file), true);
                    db_save_results($job_id, $results);
                } else {
                    db_update_status($job_id, $overall, $status['message'] ?? null);
                }
            } catch (Exception $e) {
                error_log("[GEMgen] MySQL sync failed: " . $e->getMessage());
            }
        }

        echo json_encode($status);
    } else {
        echo json_encode(['overall' => 'pending', 'message' => 'Waiting for pipeline to start...']);
    }
    exit;
}

// ── Load params ───────────────────────────────────────────────────────────────
$params = [];
$params_file = $job_dir . '/params.json';
if (file_exists($params_file)) {
    $params = json_decode(file_get_contents($params_file), true);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>GEMgen — Pipeline Running</title>
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
            <h1><span>GEM</span>gen</h1>
            <p>Genome-Scale Metabolic Model Generator</p>
        </div>
        <!-- LOGO PLACEHOLDER -->
    </div>
</div>

<div class="menu">
    <div class="container">
        <a href="index.php">Home</a>
        <a href="example.php">Example Dataset</a>
        <a href="history.php">My Results</a>
        <a href="about.php">About</a>
        <a href="help.php">Help</a>
        <a href="feedback.php">Feedback</a>
    </div>
</div>

<div class="container">
<div class="content">

    <div class="results-header">
        <div>
            <h2>Pipeline Running</h2>
            <p class="results-subtitle">
                Job: <code><?= htmlspecialchars($job_id) ?></code>
                &nbsp;&mdash;&nbsp; <?= htmlspecialchars($params['genome_filename'] ?? 'genome.faa') ?>
            </p>
        </div>
        <a href="index.php" class="btn-back">&#8592; New Run</a>
    </div>

    <div class="progress-layout">

        <!-- Stage tracker -->
        <div class="stage-tracker">

            <?php
            $stages = [
                ['id' => 'taxon_detect',     'name' => 'Taxon Detection',       'desc' => 'Parse FASTA header → query BV-BRC taxonomy API'],
                ['id' => 'validate',         'name' => 'Fungi Validation',      'desc' => 'Confirm genome is fungal — hard block if not'],
                ['id' => 'upload',           'name' => 'BV-BRC Upload',         'desc' => 'Upload FASTA to BV-BRC workspace'],
                ['id' => 'gem_construction', 'name' => 'GEM Reconstruction',    'desc' => 'BV-BRC ModelReconstruction app'],
                ['id' => 'bv_brc_qc',        'name' => 'BV-BRC QC / Polling',  'desc' => 'Waiting for BV-BRC job to complete (~5–30 min)'],
                ['id' => 'fetch_model',      'name' => 'Fetch SBML Model',      'desc' => 'Retrieve reconstructed model from BV-BRC'],
            ];
            foreach ($stages as $i => $s):
            ?>
            <div class="stage-item" id="stage-<?= $s['id'] ?>">
                <?php if ($i > 0): ?><div class="stage-connector stage-connector--top"></div><?php endif; ?>
                <div class="stage-dot stage-dot--pending" id="dot-<?= $s['id'] ?>"></div>
                <?php if ($i < count($stages)-1): ?><div class="stage-connector stage-connector--bottom"></div><?php endif; ?>
                <div class="stage-content">
                    <div class="stage-name"><?= htmlspecialchars($s['name']) ?></div>
                    <div class="stage-desc"><?= htmlspecialchars($s['desc']) ?></div>
                    <div class="stage-msg" id="msg-<?= $s['id'] ?>"></div>
                </div>
                <div class="stage-badge stage-badge--pending" id="badge-<?= $s['id'] ?>">—</div>
            </div>
            <?php endforeach; ?>

        </div><!-- /.stage-tracker -->

        <!-- Sidebar -->
        <div class="progress-sidebar">

            <div class="progress-status-card">
                <div class="progress-status-dot progress-status-dot--pending" id="status-dot"></div>
                <div>
                    <div class="progress-status-title" id="status-title">Starting BV-BRC pipeline...</div>
                    <div class="progress-status-sub" id="status-sub">Job <?= htmlspecialchars($job_id) ?></div>
                </div>
            </div>

            <div class="infobox" style="font-size:0.8rem;">
                <strong>Note:</strong> BV-BRC GEM reconstruction typically takes
                <strong>5–30 minutes</strong> depending on genome size and server load.
                This page polls every 15 seconds and will redirect automatically when complete.
            </div>

            <div class="progress-params">
                <div class="progress-params-title">Run parameters</div>
                <div class="progress-param-row"><span>Carbon source</span><code><?= htmlspecialchars($params['carbon_source'] ?? '—') ?></code></div>
                <div class="progress-param-row"><span>Carbon conc.</span><code><?= htmlspecialchars(($params['carbon_conc'] ?? '—').' '.($params['carbon_unit'] ?? '')) ?></code></div>
                <div class="progress-param-row"><span>Nitrogen source</span><code><?= htmlspecialchars($params['nitrogen_source'] ?? '—') ?></code></div>
                <div class="progress-param-row"><span>pH</span><code><?= htmlspecialchars($params['ph'] ?? '—') ?></code></div>
                <div class="progress-param-row"><span>Temperature</span><code><?= htmlspecialchars($params['temperature'] ?? '—') ?></code></div>
                <div class="progress-param-row"><span>Impeller speed</span><code><?= htmlspecialchars($params['rpm'] ?? '—') ?></code></div>
            </div>

            <div class="progress-log-box">
                <div class="progress-log-title">Pipeline log</div>
                <div class="progress-log" id="progress-log">Waiting for pipeline output...</div>
            </div>

        </div><!-- /.progress-sidebar -->

    </div><!-- /.progress-layout -->

</div>
</div>

<div class="footer">
    <div class="container">
        <p>GEMgen &mdash; Pacifico Biolabs GmbH &times; BioHack Challenge 6</p>
    </div>
</div>

<script>
(function () {
    var jobId   = <?= json_encode($job_id) ?>;
    var pollUrl = 'progress.php?job_id=' + encodeURIComponent(jobId) + '&poll=1';
    var interval, logLines = [];

    var LABELS = { pending:'—', running:'Running...', done:'✓ Done', warning:'⚠ Warn', error:'✗ Error' };
    var TITLES = { pending:'Starting...', running:'Pipeline running', warning:'Running with warnings',
                   done:'Pipeline complete!', error:'Pipeline error', fungi_error:'Non-fungal genome detected' };

    function setDot(id, s) {
        var el = document.getElementById('dot-' + id);
        if (el) el.className = 'stage-dot stage-dot--' + s;
    }
    function setBadge(id, s) {
        var el = document.getElementById('badge-' + id);
        if (el) { el.className = 'stage-badge stage-badge--' + s; el.textContent = LABELS[s] || s; }
    }
    function setMsg(id, m) {
        var el = document.getElementById('msg-' + id);
        if (el && m) el.textContent = m;
    }
    function log(line) {
        logLines.push(line);
        if (logLines.length > 80) logLines = logLines.slice(-80);
        var el = document.getElementById('progress-log');
        if (el) { el.textContent = logLines.join('\n'); el.scrollTop = el.scrollHeight; }
    }

    function poll() {
        fetch(pollUrl)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var stages  = data.stages  || {};
                var overall = data.overall || 'pending';

                for (var name in stages) {
                    var s = stages[name];
                    setDot(name, s.status);
                    setBadge(name, s.status);
                    if (s.message) { setMsg(name, s.message); log('[' + name + '] ' + s.message); }
                }

                var dot   = document.getElementById('status-dot');
                var title = document.getElementById('status-title');
                var sub   = document.getElementById('status-sub');
                if (dot)   dot.className = 'progress-status-dot progress-status-dot--' + overall;
                if (title) title.textContent = TITLES[overall] || overall;
                if (sub && data.message) sub.textContent = data.message;

                if (overall === 'done') {
                    clearInterval(interval);
                    log('✓ Complete — loading results...');
                    setTimeout(function () {
                        window.location.href = 'results.php?job_id=' + encodeURIComponent(jobId);
                    }, 1200);
                }

                if (overall === 'fungi_error') {
                    clearInterval(interval);
                    log('✗ Non-fungal genome — redirecting...');
                    setTimeout(function () {
                        window.location.href = 'index.php?fungi_error=1&msg=' +
                            encodeURIComponent(data.message || 'Non-fungal genome detected');
                    }, 2000);
                }

                if (overall === 'error') {
                    clearInterval(interval);
                    log('✗ Pipeline failed. Check parameters and try again.');
                }
            })
            .catch(function(e) { log('[poll error] ' + e.message); });
    }

    poll();
    interval = setInterval(poll, 15000);  // 15 s — BV-BRC jobs are slow
})();
</script>
</body>
</html>
