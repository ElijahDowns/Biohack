<?php
session_start();
?>
<html>
<head>
    <title>GEMgen — Genome-Scale Metabolic Model Generator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:ital,wght@0,400;0,700;1,400&family=Syne:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<!-- HEADER -->
<div class="header">
    <div class="container header-inner">
        <div class="logo-block">
            <h1><span>GEM</span>gen</h1>
            <p>Genome-Scale Metabolic Model Generator &mdash; submerged fermentation optimisation for filamentous fungi</p>
        </div>
        <!-- LOGO PLACEHOLDER: replace with <img src="images/logo.png" alt="GEMgen logo" class="logo-icon"> -->
    </div>
</div>

<!-- NAV -->
<div class="menu">
    <div class="container">
        <a href="index.php" class="active">Home</a>
        <a href="example.php">Example Dataset</a>
        <a href="history.php">My Results</a>
        <a href="about.php">About</a>
        <a href="help.php">Help</a>
        <a href="feedback.php">Feedback</a>
    </div>
</div>

<!-- MAIN -->
<div class="container">
<div class="content">

<?php
if (!empty($_SESSION['errors'])) {
    foreach ($_SESSION['errors'] as $error) {
        echo '<div class="error">' . htmlspecialchars($error) . '</div>';
    }
    unset($_SESSION['errors']);
}
if (isset($_GET['fungi_error'])) {
    $msg = htmlspecialchars($_GET['msg'] ?? 'Non-fungal genome detected.');
    echo '<div class="error"><strong>&#9888; Non-fungal genome detected:</strong> ' . $msg .
         ' GEMgen only supports filamentous fungi. Please upload a fungal protein FASTA.</div>';
}
?>

<h2>Welcome to <span class="accent">GEM</span>gen</h2>
<p>
    GEMgen predicts TRY metrics — Titer, Rate, and Yield — for filamentous fungi in submerged fermentation,
    directly from your genome and bioreactor conditions. Upload a fungal genome, define your substrate
    and reactor parameters, and get an instant in-silico prediction to focus your wet lab effort.
    Any <a href="feedback.php">feedback</a> is greatly appreciated.
</p>

<!-- pipeline strip -->
<div class="pipeline-strip">
    <div class="pipeline-strip-step">
        <span class="pipeline-strip-num">1</span>
        <span>Upload Genome</span>
    </div>
    <div class="pipeline-strip-arrow">&#8250;</div>
    <div class="pipeline-strip-step">
        <span class="pipeline-strip-num">2</span>
        <span>Define Media &amp; Reactor</span>
    </div>
    <div class="pipeline-strip-arrow">&#8250;</div>
    <div class="pipeline-strip-step">
        <span class="pipeline-strip-num">3</span>
        <span>GEM Reconstruction</span>
    </div>
    <div class="pipeline-strip-arrow">&#8250;</div>
    <div class="pipeline-strip-step">
        <span class="pipeline-strip-num">4</span>
        <span>FBA Optimisation</span>
    </div>
    <div class="pipeline-strip-arrow">&#8250;</div>
    <div class="pipeline-strip-step active">
        <span class="pipeline-strip-num">5</span>
        <span>TRY Prediction</span>
    </div>
</div>

<h3>Configure Your Run</h3>

<form action="run.php" method="post" enctype="multipart/form-data" novalidate>
<div class="form-panel">

    <!-- ── GENOME ── -->
    <div class="section-label">Genome</div>

    <div class="field-group">
        <span class="field-label">Genome file <span class="field-fmt">(.faa / .fasta)</span></span>
        <input type="file" name="genome_file" accept=".faa,.fasta,.fa"/>
    </div>

    <div class="field-group">
        <span class="field-label">Organism name <span class="field-fmt">(optional)</span></span>
        <input type="text" name="organism" placeholder="e.g. Fusarium venenatum, proprietary strain A"/>
    </div>

    <!-- ── SUBSTRATE / MEDIA ── -->
    <div class="section-label">Substrate Composition</div>

    <div class="two-col-fields">

        <div class="subcol">
            <div class="subcol-heading">Carbon Source</div>
            <div class="field-group field-group--tight">
                <span class="field-label">Carbon source</span>
                <select name="carbon_source">
                    <optgroup label="Agri-food side streams">
                        <option value="corn_steep">Corn steep liquor</option>
                        <option value="wheat_bran">Wheat bran hydrolysate</option>
                        <option value="molasses">Cane molasses</option>
                        <option value="stillage">Distillery stillage</option>
                        <option value="whey">Cheese whey (lactose)</option>
                        <option value="straw_hydrolysate">Wheat straw hydrolysate</option>
                        <option value="potato_waste">Potato processing waste</option>
                    </optgroup>
                    <optgroup label="Defined / pure substrates">
                        <option value="glucose" selected>Glucose</option>
                        <option value="sucrose">Sucrose</option>
                        <option value="glycerol">Glycerol</option>
                        <option value="xylose">Xylose</option>
                        <option value="fructose">Fructose</option>
                    </optgroup>
                </select>
            </div>
            <div class="field-group field-group--tight">
                <span class="field-label">Concentration</span>
                <div class="input-unit-row">
                    <input type="number" name="carbon_conc" value="20" min="0.1" max="200" step="0.5"/>
                    <select name="carbon_unit">
                        <option value="g/L" selected>g / L</option>
                        <option value="mmol/L">mmol / L</option>
                        <option value="% w/v">% w/v</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="subcol">
            <div class="subcol-heading">Nitrogen Source</div>
            <div class="field-group field-group--tight">
                <span class="field-label">Nitrogen source</span>
                <select name="nitrogen_source">
                    <optgroup label="Agri-food side streams">
                        <option value="corn_steep_n">Corn steep liquor</option>
                        <option value="soy_hydrolysate">Soy protein hydrolysate</option>
                        <option value="distillers_grains">Distillers dried grains (DDGS)</option>
                        <option value="rapeseed_meal">Rapeseed meal extract</option>
                    </optgroup>
                    <optgroup label="Defined / pure substrates">
                        <option value="ammonium_sulfate" selected>Ammonium sulfate</option>
                        <option value="ammonium_chloride">Ammonium chloride</option>
                        <option value="urea">Urea</option>
                        <option value="yeast_extract">Yeast extract</option>
                        <option value="peptone">Peptone</option>
                    </optgroup>
                </select>
            </div>
            <div class="field-group field-group--tight">
                <span class="field-label">Concentration</span>
                <div class="input-unit-row">
                    <input type="number" name="nitrogen_conc" value="2" min="0.1" max="50" step="0.1"/>
                    <select name="nitrogen_unit">
                        <option value="g/L" selected>g / L</option>
                        <option value="mmol/L">mmol / L</option>
                        <option value="% w/v">% w/v</option>
                    </select>
                </div>
            </div>
        </div>

    </div><!-- /.two-col-fields -->

    <div class="cn-ratio-display">
        C:N ratio &asymp; <strong id="cn-ratio">10 : 1</strong>
        <span class="cn-note">&mdash; typical range for filamentous fungi: 10:1 to 30:1</span>
    </div>

    <!-- ── BIOREACTOR ── -->
    <div class="section-label">Bioreactor Conditions</div>

    <div class="field-row-4">

        <div class="field-col">
            <label class="field-col-label">pH</label>
            <select name="ph">
                <option>4.0</option>
                <option>4.5</option>
                <option>5.0</option>
                <option>5.5</option>
                <option>6.0</option>
                <option selected>6.5</option>
                <option>7.0</option>
                <option>7.5</option>
                <option>8.0</option>
                <option>8.5</option>
                <option>9.0</option>
            </select>
        </div>

        <div class="field-col">
            <label class="field-col-label">Temperature</label>
            <select name="temperature">
                <option>20 °C</option>
                <option>22 °C</option>
                <option>25 °C</option>
                <option selected>28 °C</option>
                <option>30 °C</option>
                <option>32 °C</option>
                <option>35 °C</option>
                <option>37 °C</option>
                <option>40 °C</option>
                <option>42 °C</option>
            </select>
        </div>

        <div class="field-col">
            <label class="field-col-label">Impeller speed</label>
            <select name="rpm">
                <option>50 RPM</option>
                <option>100 RPM</option>
                <option>150 RPM</option>
                <option selected>200 RPM</option>
                <option>250 RPM</option>
                <option>300 RPM</option>
                <option>400 RPM</option>
                <option>500 RPM</option>
                <option>600 RPM</option>
                <option>800 RPM</option>
                <option>1000 RPM</option>
            </select>
        </div>

        <div class="field-col">
            <label class="field-col-label">Working volume</label>
            <select name="volume">
                <option>100 mL</option>
                <option>250 mL</option>
                <option selected>1 L</option>
                <option>5 L</option>
                <option>10 L</option>
                <option>50 L</option>
                <option>100 L</option>
                <option>500 L</option>
                <option>1,000 L</option>
                <option>10,000 L</option>
            </select>
        </div>

    </div><!-- /.field-row-4 -->

    <!-- ── ADVANCED ── -->
    <details class="adv-section">
        <summary>Advanced options</summary>
        <div class="adv-inner">

            <div class="field-group">
                <span class="field-label">Inoculum size <span class="field-fmt">(% v/v)</span></span>
                <input type="number" name="inoculum" value="5" min="0.1" max="20" step="0.5"
                       style="max-width:120px;"/>
            </div>

            <div class="field-group">
                <span class="field-label">Fermentation duration <span class="field-fmt">(h)</span></span>
                <input type="number" name="duration" value="72" min="6" max="240" step="6"
                       style="max-width:120px;"/>
            </div>

            <div class="field-group">
                <span class="field-label">Phosphate source <span class="field-fmt">(optional)</span></span>
                <select name="phosphate">
                    <option value="kh2po4" selected>KH₂PO₄ (potassium dihydrogen phosphate)</option>
                    <option value="k2hpo4">K₂HPO₄ (dipotassium hydrogen phosphate)</option>
                    <option value="na2hpo4">Na₂HPO₄</option>
                    <option value="none">None / not defined</option>
                </select>
            </div>

            <div class="field-group">
                <span class="field-label">Phosphate concentration</span>
                <div class="input-unit-row">
                    <input type="number" name="phosphate_conc" value="1" min="0" max="20" step="0.1"
                           style="max-width:120px;"/>
                    <select name="phosphate_unit" style="max-width:100px;">
                        <option value="g/L" selected>g / L</option>
                        <option value="mmol/L">mmol / L</option>
                    </select>
                </div>
            </div>

        </div>
    </details>

    <input type="submit" class="btn-submit" value="&#9654;&nbsp; Predict TRY"/>

</div><!-- /.form-panel -->
</form>

<hr>

<h3>What GEMgen predicts</h3>
<div class="pipeline">
    <div class="step">
        <div class="step-num">1</div>
        <div class="step-text"><strong>Taxon detection</strong> &mdash; reads your FASTA header, queries the BV-BRC taxonomy API to confirm the organism is fungal, and selects the appropriate reconstruction template</div>
    </div>
    <div class="step">
        <div class="step-num">2</div>
        <div class="step-text"><strong>GEM reconstruction</strong> &mdash; uploads your genome to BV-BRC and triggers the ModelReconstruction app, which builds a genome-scale metabolic model (SBML) automatically</div>
    </div>
    <div class="step">
        <div class="step-num">3</div>
        <div class="step-text"><strong>Model retrieval</strong> &mdash; fetches the completed SBML model from BV-BRC, stores it in the database, and passes it to the FBA layer along with organism-specific biological constants</div>
    </div>
    <div class="step">
        <div class="step-num">4</div>
        <div class="step-text"><strong>Flux Balance Analysis</strong> &mdash; runs escher-FBA client-side on the reconstructed model, applying Michaelis-Menten kinetics, kLa O₂ constraints, C:N ratio, temperature &amp; pH corrections, and maintenance energy</div>
    </div>
    <div class="step">
        <div class="step-num">5</div>
        <div class="step-text"><strong>TRY output</strong> &mdash; returns Titer (g/L), Rate (g/L/h), and Yield (g biomass / g substrate) calibrated to your exact bioreactor conditions and inoculum</div>
    </div>
</div>

<div class="infobox">
    <strong>Assumptions:</strong> submerged liquid fermentation; no oxygen limitation (fully aerated);
    no solid-state or moisture-dependent processes. GEM reconstruction is performed by BV-BRC
    ModelReconstruction and typically takes 5–30 minutes. Predictions are model-based estimates —
    experimental validation is always recommended.
</div>

<hr>

<h3 style="margin-bottom:6px;">Not sure where to start?</h3>
<p>
    Try the <a href="example.php">pre-loaded example dataset</a>:
    a proprietary filamentous fungi strain grown on corn steep liquor + glucose at 28&nbsp;°C, 200&nbsp;RPM.
    All TRY outputs are pre-computed so you can see exactly what to expect.
    <span class="tag">Example ready</span>
</p>

<?php
if (isset($_SESSION['jobs']) && !empty($_SESSION['jobs'])) {
    echo '<div class="success">';
    echo '&#9679; You have previous runs this session. ';
    echo '<a href="history.php">View your results &rarr;</a>';
    echo '</div>';
}
?>

</div><!-- /.content -->
</div><!-- /.container -->

<!-- FOOTER -->
<div class="footer">
    <div class="container">
        <p>GEMgen &mdash; Genome-Scale Metabolic Model Generator &mdash; Pacifico Biolabs GmbH &times; BioHack Challenge 6</p>
        <p style="margin-top:4px;">
            <a href="credits.php">Statement of Credits</a> &nbsp;|&nbsp;
            <a href="about.php">About</a> &nbsp;|&nbsp;
            <a href="feedback.php">Feedback</a>
        </p>
    </div>
</div>

<script>
// Client-side validation (replaces browser validation disabled by novalidate)
document.querySelector('form').addEventListener('submit', function(e) {
    var fileInput = document.querySelector('[name="genome_file"]');
    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
        e.preventDefault();
        alert('Please select a protein FASTA file (.faa or .fasta) before submitting.');
        fileInput.focus();
        return;
    }
    var fname = fileInput.files[0].name.toLowerCase();
    if (!fname.endsWith('.faa') && !fname.endsWith('.fasta') && !fname.endsWith('.fa')) {
        e.preventDefault();
        alert('Invalid file type. Please upload a .faa or .fasta protein FASTA file.');
        fileInput.focus();
        return;
    }
});

// Live C:N ratio display (rough molar approximation)
(function() {
    var cConc = document.querySelector('[name="carbon_conc"]');
    var nConc = document.querySelector('[name="nitrogen_conc"]');
    var display = document.getElementById('cn-ratio');
    if (!cConc || !nConc || !display) return;
    function update() {
        var c = parseFloat(cConc.value) || 0;
        var n = parseFloat(nConc.value) || 0.001;
        var ratio = (c / n).toFixed(1);
        display.textContent = ratio + ' : 1';
    }
    cConc.addEventListener('input', update);
    nConc.addEventListener('input', update);
    update();
})();
</script>

</body>
</html>
