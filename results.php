<?php
session_start();
require_once __DIR__ . '/db.php';

$job_id  = preg_replace('/[^a-zA-Z0-9_\.]/', '', $_GET['job_id'] ?? '');
$job_dir = __DIR__ . '/jobs/' . $job_id;

// ── Load results — try DB first, fallback to file ─────────────────────────────
$results = null;
try {
    $row = db_get_job($job_id);
    if ($row && $row['status'] === 'done' && $row['model_xml']) {
        $results = [
            'status'         => 'success',
            'job_id'         => $job_id,
            'model_xml'      => $row['model_xml'],
            'bio_metadata'   => is_array($row['bio_metadata'])
                                    ? $row['bio_metadata']
                                    : (json_decode($row['bio_metadata'], true) ?? []),
            'taxon_detected' => $row['taxon_detected'],
            'params'         => json_decode(
                file_get_contents($job_dir . '/params.json') ?: '{}', true
            ),
        ];
    }
} catch (Exception $e) {
    error_log("[GEMgen] DB read failed: " . $e->getMessage());
}

// File fallback
if (!$results) {
    $results_file = $job_dir . '/results.json';
    if (file_exists($results_file)) {
        $results = json_decode(file_get_contents($results_file), true);
        // Attach params separately if not embedded
        if (empty($results['params']) && file_exists($job_dir . '/params.json')) {
            $results['params'] = json_decode(file_get_contents($job_dir . '/params.json'), true);
        }
    }
}

if (empty($results) || ($results['status'] ?? '') !== 'success') {
    $errMsg = $results['error'] ?? 'No results found.';
    $_SESSION['errors'][] = $errMsg;
    header('Location: index.php');
    exit;
}

$params       = $results['params']       ?? [];
$bio_metadata = $results['bio_metadata'] ?? [];
$taxon        = $results['taxon_detected'] ?? ($params['organism'] ?? '—');

// Label maps
$carbon_labels = [
    'glucose'=>'Glucose','sucrose'=>'Sucrose','glycerol'=>'Glycerol','xylose'=>'Xylose','fructose'=>'Fructose',
    'corn_steep'=>'Corn steep liquor','wheat_bran'=>'Wheat bran hydrolysate','molasses'=>'Cane molasses',
    'stillage'=>'Distillery stillage','whey'=>'Cheese whey (lactose)',
    'straw_hydrolysate'=>'Wheat straw hydrolysate','potato_waste'=>'Potato processing waste',
];
$nitrogen_labels = [
    'ammonium_sulfate'=>'Ammonium sulfate','ammonium_chloride'=>'Ammonium chloride',
    'urea'=>'Urea','yeast_extract'=>'Yeast extract','peptone'=>'Peptone',
    'corn_steep_n'=>'Corn steep liquor','soy_hydrolysate'=>'Soy protein hydrolysate',
    'distillers_grains'=>'Distillers dried grains (DDGS)','rapeseed_meal'=>'Rapeseed meal',
];
$carbon_label   = $carbon_labels[$params['carbon_source']   ?? ''] ?? ($params['carbon_source']   ?? '—');
$nitrogen_label = $nitrogen_labels[$params['nitrogen_source'] ?? ''] ?? ($params['nitrogen_source'] ?? '—');

// Pass data to JS — model_xml can be large, encode safely
// We write it into a JS variable rather than inlining in HTML
$bio_metadata_json = json_encode($bio_metadata, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$params_json       = json_encode($params,       JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);

// model_xml is written to a separate endpoint to avoid bloating the page
// We serve it via model.php?job_id=... so JS can fetch it
?>
<!DOCTYPE html>
<html>
<head>
    <title>GEMgen — TRY Results</title>
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
            <h2>TRY Prediction Results</h2>
            <p class="results-subtitle">
                <code><?= htmlspecialchars($params['genome_filename'] ?? 'genome.faa') ?></code>
                &nbsp;&mdash;&nbsp; <em><?= htmlspecialchars($taxon) ?></em>
                &nbsp;&mdash;&nbsp; <?= htmlspecialchars($bio_metadata['organism_type'] ?? '') ?>
                &nbsp;&mdash;&nbsp; <?= htmlspecialchars($carbon_label) ?> + <?= htmlspecialchars($nitrogen_label) ?>
            </p>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <?php if (!empty($bio_metadata['organism_type'])): ?>
            <div class="memote-badge memote-badge--good">
                &#10003; <?= htmlspecialchars($bio_metadata['organism_type']) ?> confirmed
            </div>
            <?php endif; ?>
            <?php if (!empty($bio_metadata['required_supplements'])): ?>
            <div class="memote-badge memote-badge--ok">
                &#9888; Supplements: <?= htmlspecialchars(implode(', ', $bio_metadata['required_supplements'])) ?>
            </div>
            <?php endif; ?>
            <a href="index.php" class="btn-back">&#8592; New Run</a>
        </div>
    </div>

    <!-- FBA status / warnings injected by JS -->
    <div id="fba-status-banner"></div>

    <!-- Loading indicator -->
    <div id="fba-loading" class="infobox" style="text-align:center;">
        <strong>Running FBA...</strong> Loading SBML model and computing TRY metrics.
        <div style="margin-top:8px;font-size:0.8rem;color:var(--text-dim);">
            This runs client-side via escher-FBA + glpk.js
        </div>
    </div>

    <!-- Correction factors strip (hidden until FBA runs) -->
    <div class="correction-strip" id="correction-strip" style="display:none;">
        <div class="correction-item"><span class="cf-label">Temp factor</span><span class="cf-val" id="cf-temp">—</span></div>
        <div class="correction-item"><span class="cf-label">pH factor</span><span class="cf-val" id="cf-ph">—</span></div>
        <div class="correction-item"><span class="cf-label">C:N factor</span><span class="cf-val" id="cf-cn">—</span></div>
        <div class="correction-item"><span class="cf-label">MM kinetics</span><span class="cf-val" id="cf-mm">—</span></div>
        <div class="correction-item"><span class="cf-label">kLa / O₂</span><span class="cf-val" id="cf-o2">—</span></div>
        <div class="correction-item correction-item--total"><span class="cf-label">Maintenance coeff.</span><span class="cf-val" id="cf-maintenance">—</span></div>
    </div>

    <!-- TRY output (hidden until FBA runs) -->
    <div id="fba-results" style="display:none;">

        <h3>Predicted TRY Metrics</h3>
        <p>Computed by escher-FBA on the BV-BRC reconstructed model, corrected for
           Michaelis-Menten kinetics, kLa O₂ transfer, C:N ratio, temperature, pH,
           and maintenance energy. Organism constants from BV-BRC taxonomy
           (maintenance_coefficient = <strong id="maint-display">—</strong>).</p>

        <div class="output-cards output-cards--three">
            <div class="output-card output-card--primary">
                <div class="output-card-tag">T &mdash; Titer</div>
                <div class="output-card-label">Biomass Concentration</div>
                <div class="output-card-value" id="out-titer">—</div>
                <div class="output-card-range" id="out-titer-range"></div>
                <div class="output-card-desc">Final biomass at end of fermentation</div>
            </div>
            <div class="output-card output-card--secondary">
                <div class="output-card-tag">R &mdash; Rate</div>
                <div class="output-card-label">Specific Growth Rate (μ)</div>
                <div class="output-card-value" id="out-rate">—</div>
                <div class="output-card-range" id="out-rate-range"></div>
                <div class="output-card-desc">Volumetric productivity</div>
            </div>
            <div class="output-card output-card--tertiary">
                <div class="output-card-tag">Y &mdash; Yield</div>
                <div class="output-card-label">Biomass Yield</div>
                <div class="output-card-value" id="out-yield">—</div>
                <div class="output-card-range" id="out-yield-range"></div>
                <div class="output-card-desc">g biomass per g carbon substrate</div>
            </div>
        </div>

        <div class="solver-status-row">
            <div class="solver-status-item">
                <span class="solver-label">Net growth rate μ</span>
                <span class="solver-val" id="out-mu">—</span>
            </div>
            <div class="solver-status-item">
                <span class="solver-label">Raw FBA objective</span>
                <span class="solver-val" id="out-raw">—</span>
            </div>
            <div class="solver-status-item">
                <span class="solver-label">C:N ratio</span>
                <span class="solver-val" id="out-cn">—</span>
            </div>
            <div class="solver-status-item">
                <span class="solver-label">O₂ availability (kLa)</span>
                <span class="solver-val" id="out-o2">—</span>
            </div>
        </div>

        <!-- Supplement warning if required_supplements present -->
        <?php if (!empty($bio_metadata['required_supplements'])): ?>
        <div class="infobox" style="margin-top:16px;">
            <strong>&#9888; Supplement requirement:</strong>
            BV-BRC identified this organism requires
            <strong><?= htmlspecialchars(implode(' and ', $bio_metadata['required_supplements'])) ?></strong>
            as essential growth factors. Ensure these are present in your media formulation.
            Absence may result in lower actual yield than predicted.
        </div>
        <?php endif; ?>

    </div><!-- /#fba-results -->

    <!-- Run parameters -->
    <h3>Run parameters</h3>
    <div class="summary-grid">
        <div class="summary-item"><span class="summary-key">Genome</span><span class="summary-val"><code><?= htmlspecialchars($params['genome_filename'] ?? '—') ?></code></span></div>
        <div class="summary-item"><span class="summary-key">Taxon detected</span><span class="summary-val"><em><?= htmlspecialchars($taxon) ?></em></span></div>
        <div class="summary-item"><span class="summary-key">Organism type</span><span class="summary-val"><?= htmlspecialchars($bio_metadata['organism_type'] ?? '—') ?></span></div>
        <div class="summary-item"><span class="summary-key">Tax ID</span><span class="summary-val"><?= htmlspecialchars($bio_metadata['tax_id'] ?? '—') ?></span></div>
        <div class="summary-item"><span class="summary-key">Carbon source</span><span class="summary-val"><?= htmlspecialchars($carbon_label) ?></span></div>
        <div class="summary-item"><span class="summary-key">Carbon conc.</span><span class="summary-val"><code><?= htmlspecialchars(($params['carbon_conc'] ?? '—').' '.($params['carbon_unit'] ?? '')) ?></code></span></div>
        <div class="summary-item"><span class="summary-key">Nitrogen source</span><span class="summary-val"><?= htmlspecialchars($nitrogen_label) ?></span></div>
        <div class="summary-item"><span class="summary-key">Nitrogen conc.</span><span class="summary-val"><code><?= htmlspecialchars(($params['nitrogen_conc'] ?? '—').' '.($params['nitrogen_unit'] ?? '')) ?></code></span></div>
        <div class="summary-item"><span class="summary-key">pH</span><span class="summary-val"><?= htmlspecialchars($params['ph'] ?? '—') ?></span></div>
        <div class="summary-item"><span class="summary-key">Temperature</span><span class="summary-val"><?= htmlspecialchars($params['temperature'] ?? '—') ?></span></div>
        <div class="summary-item"><span class="summary-key">Impeller speed</span><span class="summary-val"><?= htmlspecialchars($params['rpm'] ?? '—') ?></span></div>
        <div class="summary-item"><span class="summary-key">Volume</span><span class="summary-val"><?= htmlspecialchars($params['volume'] ?? '—') ?></span></div>
        <div class="summary-item"><span class="summary-key">Duration</span><span class="summary-val"><?= htmlspecialchars($params['duration'] ?? '—') ?> h</span></div>
        <div class="summary-item"><span class="summary-key">Inoculum</span><span class="summary-val"><?= htmlspecialchars($params['inoculum'] ?? '5') ?> % v/v</span></div>
    </div>

    <div class="results-actions">
        <a href="index.php" class="btn-submit" style="text-decoration:none;display:inline-block;margin:0;">&#8592; Run Again</a>
        <button class="btn-outline" onclick="window.print()">&#8659; Print / Save Report</button>
        <a href="model.php?job_id=<?= urlencode($job_id) ?>" class="btn-outline" download="model.xml">&#8659; Download SBML Model</a>
    </div>

</div>
</div>

<div class="footer">
    <div class="container">
        <p>GEMgen &mdash; Pacifico Biolabs GmbH &times; BioHack Challenge 6</p>
        <p style="margin-top:4px;"><a href="credits.php">Credits</a> &nbsp;|&nbsp; <a href="feedback.php">Feedback</a></p>
    </div>
</div>

<!-- ── FBA Libraries ── -->
<!-- javascript-lp-solver: pure JS LP solver, no WASM needed -->
<script src="js/lpsolver.js"></script>
<!-- escher-fba SBML parser + FBA wrapper (uses lpsolver, exposes glpk shim) -->
<script src="js/escher-fba.min.js"></script>
<!-- Define renderFullReport BEFORE loading escher-fba.js -->
<script>
// escher-fba.js calls renderFullReport(results, optimum) at the end
// We intercept it here and route to our renderResults function
function renderFullReport(results, optimum) {
    console.log('[GEMgen] renderFullReport called — optimum:', optimum);
    var best = Array.isArray(optimum) && optimum.length > 0
        ? optimum.reduce(function(a, b) {
            return (parseFloat(b.growthRate)||0) > (parseFloat(a.growthRate)||0) ? b : a;
          }, optimum[0])
        : (optimum || {});
    // renderResults may not be defined yet — defer if needed
    if (typeof window.renderResults === 'function') {
        window.renderResults(best, window._bioMetadata || {});
    } else {
        setTimeout(function() {
            if (typeof window.renderResults === 'function') {
                window.renderResults(best, window._bioMetadata || {});
            }
        }, 100);
    }
}
</script>
<!-- Biological corrections layer -->
<script src="escher-fba.js"></script>
<script>
// All scripts loaded — signal ready
window.glpk().then(function(solver) {
    window._glpkSolver = solver;
    console.log('[GEMgen] FBA libraries ready — solver:', solver.version);
    document.dispatchEvent(new CustomEvent('glpk-ready'));
});
</script>

<script>
(function () {

    var jobId       = <?= json_encode($job_id) ?>;
    var bioMetadata = <?= $bio_metadata_json ?>;
    window._bioMetadata = bioMetadata; // expose for renderFullReport
    var params      = <?= $params_json ?>;

    // Build userInputs in the format expected by runScientificallyHardenedOptimization()
    function parseNum(s, def) { return parseFloat((s||'').replace(/[^\d.]/g,'')) || def; }

    var userInputs = {
        organism:    params.organism || '',
        temp:        { min: parseNum(params.temperature, 28), max: parseNum(params.temperature, 28) },
        ph:          { min: parseFloat(params.ph) || 6.5,     max: parseFloat(params.ph) || 6.5 },
        rpm:         { min: parseNum(params.rpm, 200),        max: parseNum(params.rpm, 200) },
        volume:      (function() {
                         var s = params.volume || '1 L';
                         var v = parseFloat(s.replace(/[^\d.]/g,'')) || 1;
                         return /ml/i.test(s) ? v/1000 : v;
                     })(),
        hours:       parseFloat(params.duration) || 72,
        inoculumPct: parseFloat(params.inoculum) || 5,
        carbonSource: {
            name:          params.carbon_source  || 'glucose',
            concentration: parseFloat(params.carbon_conc) || 20,
            unit:          params.carbon_unit  || 'g/L',
        },
        nitrogenSource: {
            name:          params.nitrogen_source || 'ammonium sulphate',
            concentration: parseFloat(params.nitrogen_conc) || 2,
            unit:          params.nitrogen_unit || 'g/L',
        },
        phosphate: params.phosphate && params.phosphate !== 'none' ? {
            source:        params.phosphate,
            concentration: parseFloat(params.phosphate_conc) || 1,
            unit:          params.phosphate_unit || 'g/L',
        } : null,
    };

    // ── Wait for glpk ES module to be ready, then fetch model and run FBA ──────
    function runFBA() {
        fetch('model.php?job_id=' + encodeURIComponent(jobId))
            .then(function(r) {
                if (!r.ok) throw new Error('Model fetch failed: ' + r.status);
                return r.text();
            })
            .then(function(modelXml) {
                return runScientificallyHardenedOptimization(modelXml, userInputs, bioMetadata);
            })
            .then(function(optimum) {
                // escher-fba.js calls renderFullReport() internally which calls renderResults()
                // We don't need to call renderResults() here — it's already done
                console.log('[GEMgen] runFBA promise resolved — renderFullReport already called');
            })
            .catch(function(err) {
                console.error('[GEMgen FBA error]', err);
                showError(err && err.message ? err.message : String(err));
            });
    }

    // Wait for glpk ES module to initialise before running FBA
    if (window._glpkSolver) {
        runFBA();
    } else {
        document.addEventListener('glpk-ready', function() {
            runFBA();
        });
    }

    // ── Render results into DOM ──────────────────────────────────────────────
    window.renderResults = function(optimum, bio) {
        optimum = optimum || {};
        bio     = bio     || {};
        document.getElementById('fba-loading').style.display  = 'none';
        document.getElementById('fba-results').style.display  = '';
        document.getElementById('correction-strip').style.display = '';

        // Maintenance coefficient source
        var maint = bio.maintenance_coefficient != null
            ? bio.maintenance_coefficient + ' (BV-BRC)'
            : 'heuristic fallback';
        document.getElementById('maint-display').textContent = maint;
        document.getElementById('cf-maintenance').textContent = bio.maintenance_coefficient != null
            ? bio.maintenance_coefficient : '~0.05';

        // TRY cards
        var mu   = parseFloat(optimum.growthRate)   || 0;
        var vol  = userInputs.volume;
        var hrs  = userInputs.hours;
        var X0   = (userInputs.inoculumPct / 100) * 10;  // 10 g/L seed
        var titer = X0 * Math.exp(mu * hrs);
        var rate  = titer / hrs;

        set('out-titer',        titer.toFixed(2) + ' g/L');
        set('out-titer-range',  (titer*0.85).toFixed(2) + ' – ' + (titer*1.15).toFixed(2) + ' g/L');
        set('out-rate',         rate.toFixed(4)  + ' g/L/h');
        set('out-rate-range',   (rate*0.85).toFixed(4)  + ' – ' + (rate*1.15).toFixed(4)  + ' g/L/h');
        set('out-yield',        optimum.growthRate || '—');
        set('out-yield-range',  '');

        // Status row
        set('out-mu',   (mu).toFixed(4) + ' h⁻¹');
        set('out-raw',  optimum.growthRate || '—');
        set('out-cn',   (optimum.cnRatio  || '—') + ' : 1');
        set('out-o2',   '—');  // available in full results array if needed

        // Correction factors (from optimum row)
        // The original JS stores these per result row
        if (optimum.cnFactor)   set('cf-cn',  parseFloat(optimum.cnFactor).toFixed(3));
        if (optimum.growthRate) set('cf-temp', '—');  // temp/pH factors in individual rows
    }

    window.set = function(id, val) {
        var el = document.getElementById(id);
        if (el) el.textContent = val;
    }

    window.showError = function(msg) {
        document.getElementById('fba-loading').style.display = 'none';
        var banner = document.getElementById('fba-status-banner');
        banner.innerHTML = '<div class="error"><strong>&#10007; FBA error:</strong> ' + msg +
            '<br><small>Check browser console for details. ' +
            'Ensure escher-fba.js and glpk.js are loaded correctly.</small></div>';
    }

})();
</script>

</body>
</html>
