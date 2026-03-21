/**
 * Optimized Bioprocess Simulator with Biological Guardrails
 * Incorporates: Michaelis-Menten Kinetics, kLa Limits, Maintenance Energy,
 * C:N ratio-driven nutrient constraints, organism-specific overrides,
 * bioreactor condition inputs, and optional advanced parameters.
 *
 * Updated: accepts structured bioMetadata from pipeline.py so that
 * organism-specific constants (maintenance_coefficient, organism_type,
 * required_supplements) come from the BV-BRC pipeline rather than
 * being re-derived from a heuristic regex on a free-text name.
 */

// ─── Unit conversion helpers ─────────────────────────────────────────────────

/**
 * Convert a substrate concentration into mmol/L (the internal working unit).
 * @param {number} value       Raw numeric value entered by the user
 * @param {string} unit        One of: "g/L", "mmol/L", "% w/v"
 * @param {number} molarMass   Molar mass of the substrate (g/mol)
 * @returns {number}           Concentration in mmol/L
 */
function toMmolPerL(value, unit, molarMass) {
    switch (unit) {
        case "g/L":
            return (value / molarMass) * 1000;
        case "% w/v":
            // % w/v = g per 100 mL = 10 g/L
            return ((value * 10) / molarMass) * 1000;
        case "mmol/L":
        default:
            return value;
    }
}

/**
 * Molar masses (g/mol) for supported substrates and nitrogen sources.
 * For complex biological hydrolysates the value is an approximate average
 * carbon-equivalent molar mass — used only for unit conversion.
 */
const MOLAR_MASSES = {
    // Carbon sources
    "corn steep liquor":        180,   // predominantly glucose/lactate (approx)
    "wheat bran hydrolysate":   162,   // xylose/arabinose (approx)
    "cane molasses":            342,   // sucrose equivalent
    "distillery stillage":      180,   // mixed sugars (approx)
    "cheese whey (lactose)":    342,   // lactose
    "wheat straw hydrolysate":  150,   // mixed C5/C6 (approx)
    "potato waste":             162,   // starch-derived glucose (approx)
    "glucose":                  180.16,
    "sucrose":                  342.30,
    "glycerol":                  92.09,
    "xylose":                   150.13,
    "fructose":                 180.16,
    // Nitrogen sources
    "soy protein hydrolysate":  119,   // average amino acid MW
    "distillers dried grains (DDGS)": 119,
    "rapeseed meal extract":    119,
    "ammonium sulphate":        132.14,
    "ammonium chloride":         53.49,
    "urea":                      60.06,
    "yeast extract":            119,   // average amino acid MW
    "peptone":                  119,
    // corn steep liquor can also serve as N source (same MW entry as C source)
};

// Carbon content (g C per g substrate) — used for C:N ratio calculation
const CARBON_FRACTION = {
    "corn steep liquor":         0.40,
    "wheat bran hydrolysate":    0.44,
    "cane molasses":             0.42,
    "distillery stillage":       0.40,
    "cheese whey (lactose)":     0.42,
    "wheat straw hydrolysate":   0.44,
    "potato waste":              0.44,
    "glucose":                   0.40,
    "sucrose":                   0.42,
    "glycerol":                  0.39,
    "xylose":                    0.40,
    "fructose":                  0.40,
};

// Nitrogen content (g N per g source)
const NITROGEN_FRACTION = {
    "corn steep liquor":         0.08,
    "soy protein hydrolysate":   0.16,
    "distillers dried grains (DDGS)": 0.13,
    "rapeseed meal extract":     0.12,
    "ammonium sulphate":         0.212,
    "ammonium chloride":         0.262,
    "urea":                      0.467,
    "yeast extract":             0.10,
    "peptone":                   0.14,
};

// Phosphate molar masses (g/mol)
const PHOSPHATE_MM = {
    "potassium dihydrogen phosphate":  136.09,  // KH2PO4
    "dipotassium hydrogen phosphate":  174.18,  // K2HPO4
    "na2hpo4":                         141.96,  // Na2HPO4
    "none":                            null,
};

// ─── C:N ratio → FBA bound scaling ───────────────────────────────────────────

/**
 * Convert C:N mass ratio into a fractional penalty (0–1) applied to the
 * nitrogen uptake exchange bound in the FBA model.
 *
 * Optimal C:N for most fungi is ~10–25:1 (mass basis).
 * Very low C:N (N excess) or very high C:N (N limitation) impairs growth.
 *
 * @param {number} cnRatio   Calculated C:N mass ratio
 * @returns {number}         Fraction between 0 and 1
 */
function cnRatioFactor(cnRatio) {
    const optimalLow  = 10;
    const optimalHigh = 25;
    if (cnRatio >= optimalLow && cnRatio <= optimalHigh) {
        return 1.0;
    }
    if (cnRatio < optimalLow) {
        // Excess nitrogen; mild penalty — microbe can always assimilate extra N
        return Math.max(0.5, cnRatio / optimalLow);
    }
    // Nitrogen limitation above the optimum window
    return Math.max(0, 1 - ((cnRatio - optimalHigh) / 100));
}

/**
 * Scale the maximum nitrogen exchange flux in the model based on the
 * supplied N-source concentration and the C:N ratio factor.
 * Returns mmol/L/h (an approximate upper bound for FBA).
 *
 * @param {number} nConc_mmolL   Nitrogen source concentration (mmol/L)
 * @param {number} cnFactor      Output of cnRatioFactor()
 * @returns {number}
 */
function nitrogenFluxBound(nConc_mmolL, cnFactor) {
    // Assume a maximum specific uptake of 5 mmol/g/h, attenuated by C:N
    const BASE_N_UPTAKE = 5.0;
    return nConc_mmolL * BASE_N_UPTAKE * cnFactor / 1000;
}

// ─── Phosphate helper ─────────────────────────────────────────────────────────

/**
 * Return a phosphate exchange bound (mmol/L/h) from the optional phosphate input.
 * Returns Infinity (unconstrained) if no phosphate source was selected.
 *
 * @param {object} phosphateInput  { source, concentration, unit } or null
 * @returns {number}
 */
function phosphateFluxBound(phosphateInput) {
    if (!phosphateInput || phosphateInput.source === "none" ||
        phosphateInput.concentration == null || isNaN(phosphateInput.concentration)) {
        return Infinity;   // leave exchange unconstrained
    }
    const mm = PHOSPHATE_MM[phosphateInput.source];
    if (!mm) return Infinity;
    const conc_mmolL = toMmolPerL(
        phosphateInput.concentration,
        phosphateInput.unit,
        mm
    );
    // Phosphate uptake kinetics: simple proportional bound (Vmax-like scaling)
    const BASE_P_UPTAKE = 3.0;
    return conc_mmolL * BASE_P_UPTAKE / 1000;
}

// ─── Organism-specific override ───────────────────────────────────────────────

/**
 * Return environmental adjustment defaults (temperature and pH optima,
 * fallback maintenance coefficient) derived from the free-text organism name.
 *
 * This is a heuristic fallback used ONLY when pipeline.py's bioMetadata
 * does not supply a maintenance_coefficient. Temperature and pH optima are
 * still resolved here because pipeline.py does not currently return them.
 *
 * @param {string} organismName   Free-text name (may be empty)
 * @returns {{ criticalTemp: number, phOptima: number, maintenanceCoeff: number }}
 */
function organismsDefaults(organismName) {
    const name = (organismName || "").toLowerCase();

    if (/aspergill|penicill|trichoderm|fusari/.test(name)) {
        // Filamentous fungi: warm and mildly acidic
        return { criticalTemp: 30, phOptima: 6.0, maintenanceCoeff: 0.05 };
    }
    if (/yeast|saccharomyces|kluyveromyces|pichia|yarrowia/.test(name)) {
        // Yeasts: slightly higher optimum temperature
        return { criticalTemp: 32, phOptima: 5.5, maintenanceCoeff: 0.04 };
    }
    if (/bacill|corynebact|escherichia|e\.? ?coli/.test(name)) {
        // Common bacteria
        return { criticalTemp: 37, phOptima: 7.0, maintenanceCoeff: 0.03 };
    }
    if (/streptomyces|actinomycet/.test(name)) {
        return { criticalTemp: 28, phOptima: 7.2, maintenanceCoeff: 0.05 };
    }
    // Default (generic fungal, matches the original hard-coded values)
    return { criticalTemp: 30, phOptima: 6.2, maintenanceCoeff: 0.05 };
}

// ─── Helper from original code ────────────────────────────────────────────────

function range(start, stop, step) {
    const arr = [];
    for (let v = start; v <= stop; v += step) arr.push(parseFloat(v.toFixed(6)));
    return arr;
}

// ─── Main optimisation entry point ───────────────────────────────────────────

/**
 * runScientificallyHardenedOptimization
 *
 * @param {string} modelXml      SBML XML string — the GEM produced by pipeline.py
 * @param {object} userInputs    Collected from the web UI (see schema below)
 * @param {object} [bioMetadata] Structured organism metadata from pipeline.py
 *                               (the bio_metadata field of pipeline output JSON).
 *                               When supplied, its values take precedence over the
 *                               heuristic organismsDefaults() fallback.
 *
 * userInputs schema
 * ─────────────────
 * {
 *   // Bioreactor sweep ranges (dropdown-selected)
 *   temp:    { min: number, max: number },   // °C
 *   ph:      { min: number, max: number },
 *   rpm:     { min: number, max: number },
 *   volume:  number,                          // L  (working volume)
 *   hours:   number,                          // fermentation duration (h)
 *
 *   // Nutrient composition (text + dropdown)
 *   carbonSource: {
 *     name:          string,   // e.g. "glucose"
 *     concentration: number,
 *     unit:          string,   // "g/L" | "mmol/L" | "% w/v"
 *   },
 *   nitrogenSource: {
 *     name:          string,   // e.g. "ammonium sulphate"
 *     concentration: number,
 *     unit:          string,
 *   },
 *
 *   // Optional
 *   organism:    string,   // free text, may be ""
 *   inoculumPct: number,   // % v/v (may be NaN / undefined)
 *   phosphate: {           // may be null or { source: "none" }
 *     source:        string,
 *     concentration: number,
 *     unit:          string,   // "g/L" | "mmol/L"
 *   },
 * }
 *
 * bioMetadata schema (output of pipeline.py's constraints())
 * ──────────────────────────────────────────────────────────
 * {
 *   organism_type:          string,    // "Fungal" or "Bacterial"
 *   required_supplements:   string[],  // e.g. ["Biotin", "Thiamine"] for fungi
 *   maintenance_coefficient: number,   // e.g. 1.5 (fungal) or 1.1 (bacterial)
 *   tax_id:                 number,    // NCBI taxon ID
 * }
 */
async function runScientificallyHardenedOptimization(modelXml, userInputs, bioMetadata = {}) {
    const solver = await glpk();
    const model  = escherFba.loadModel(modelXml);

    // ── 1. PHYSICAL CONSTANTS ──────────────────────────────────────────────
    const MAX_KLA = 350;   // Max O₂ transfer rate (mmol/L/h), stirred tank

    // Resolve the organism label: prefer bioMetadata.organism_type (authoritative,
    // from BV-BRC taxonomy) over the free-text userInputs.organism field.
    const resolvedOrganismName = userInputs.organism
        || (bioMetadata.organism_type
                ? bioMetadata.organism_type.toLowerCase()  // "fungal" / "bacterial"
                : "");

    // Heuristic defaults for temperature/pH optima (pipeline.py does not supply these).
    // maintenance_coefficient from here is only used as a fallback (see below).
    const orgDefaults = organismsDefaults(resolvedOrganismName);

    const CRITICAL_TEMP = orgDefaults.criticalTemp;
    const PH_OPTIMA     = orgDefaults.phOptima;

    // MAINTENANCE_COEFFICIENT priority:
    //   1. bioMetadata.maintenance_coefficient  — exact value from BV-BRC pipeline
    //   2. orgDefaults.maintenanceCoeff         — heuristic regex fallback
    //
    // Note: pipeline.py's constraints() returns 1.5 (fungal) or 1.1 (bacterial),
    // which are on a different scale than the original 0.03–0.05 heuristic values.
    // If you want to use the pipeline values directly (recommended), leave as-is.
    // If your model's objective is in h⁻¹ and the heuristic scale feels more
    // appropriate, you can normalise here, e.g.: bioMetadata.maintenance_coefficient / 30
    const MAINTENANCE_COEFFICIENT =
        (bioMetadata.maintenance_coefficient != null)
            ? bioMetadata.maintenance_coefficient
            : orgDefaults.maintenanceCoeff;

    // Log which source was used (helpful during integration debugging)
    const maintenanceSource = (bioMetadata.maintenance_coefficient != null)
        ? "pipeline.py (BV-BRC)"
        : "heuristic fallback";
    console.log(
        `[FBA] organism="${resolvedOrganismName}" | ` +
        `maintenance_coefficient=${MAINTENANCE_COEFFICIENT} (source: ${maintenanceSource})`
    );

    // Warn if pipeline.py flagged required supplements but none were supplied in userInputs.
    // This is advisory only — the model proceeds regardless.
    if (Array.isArray(bioMetadata.required_supplements) && bioMetadata.required_supplements.length > 0) {
        console.warn(
            `[FBA] BV-BRC pipeline flagged required supplements for this organism: ` +
            `${bioMetadata.required_supplements.join(", ")}. ` +
            `Ensure these are present in your media formulation.`
        );
    }

    // ── 2. NUTRIENT PRE-PROCESSING ────────────────────────────────────────

    // Carbon source
    const cSource  = userInputs.carbonSource;
    const cMM      = MOLAR_MASSES[cSource.name] || 180;
    const cConc_mmolL = toMmolPerL(cSource.concentration, cSource.unit, cMM);

    // Nitrogen source
    const nSource  = userInputs.nitrogenSource;
    const nMM      = MOLAR_MASSES[nSource.name] || 119;
    const nConc_mmolL = toMmolPerL(nSource.concentration, nSource.unit, nMM);

    // C:N mass ratio (g C / g N)
    const cFraction = CARBON_FRACTION[cSource.name] || 0.40;
    const nFraction = NITROGEN_FRACTION[nSource.name] || 0.14;
    // Convert mmol/L back to g/L for the ratio calculation
    const cMass_gL  = (cConc_mmolL * cMM) / 1000;
    const nMass_gL  = (nConc_mmolL * nMM) / 1000;
    const carbonMass   = cMass_gL * cFraction;
    const nitrogenMass = nMass_gL * nFraction;
    const cnRatio = nitrogenMass > 0 ? carbonMass / nitrogenMass : Infinity;

    const cnFactor         = cnRatioFactor(cnRatio);
    const nFluxBound       = nitrogenFluxBound(nConc_mmolL, cnFactor);
    const phosphateBound   = phosphateFluxBound(userInputs.phosphate);

    // Carbon uptake bound — Michaelis-Menten with Ks ≈ 0.5 mmol/L
    const KS_CARBON          = 0.5;
    const VMAX_CARBON_UPTAKE = 10.0;  // mmol/g/h
    const cFluxBound = (VMAX_CARBON_UPTAKE * cConc_mmolL) / (KS_CARBON + cConc_mmolL);

    // Apply nutrient bounds to the model (exchange reactions).
    // Exchange IDs follow BiGG convention; adapt as needed for the actual model.
    model.setUpperBound('EX_glc__D_e',  cFluxBound);      // generic carbon source
    model.setUpperBound('EX_nh4_e',     nFluxBound);      // generic nitrogen source
    if (isFinite(phosphateBound)) {
        model.setUpperBound('EX_pi_e',  phosphateBound);  // inorganic phosphate
    }

    // ── 3. INOCULUM DILUTION ──────────────────────────────────────────────
    // A larger inoculum means a higher starting biomass, which shortens
    // the effective lag phase but does not change the specific growth rate.
    // We represent it as a scaling factor on the initial biomass (X0).
    const inoculumFraction = (userInputs.inoculumPct > 0 && !isNaN(userInputs.inoculumPct))
        ? userInputs.inoculumPct / 100
        : 0.05;   // default 5 % v/v

    // Fermentation duration
    const fermentationHours = (userInputs.hours > 0 && !isNaN(userInputs.hours))
        ? userInputs.hours
        : 72;   // default 72 h

    // ── 4. MULTI-DIMENSIONAL SWEEP ───────────────────────────────────────
    let results = [];

    for (let temp of range(userInputs.temp.min, userInputs.temp.max, 2)) {
        for (let ph of range(userInputs.ph.min, userInputs.ph.max, 0.2)) {
            for (let rpm of range(userInputs.rpm.min, userInputs.rpm.max, 50)) {

                // A. Environmental stress (bell curves around organism optima)
                const tempFactor = Math.exp(-0.5 * Math.pow((temp - CRITICAL_TEMP) / 5, 2));
                const phFactor   = Math.exp(-0.5 * Math.pow((ph   - PH_OPTIMA)     / 1, 2));

                // B. Oxygen ceiling (mass-transfer limitation, Michaelis-Menten in rpm)
                const o2Availability = (MAX_KLA * rpm) / (200 + rpm);
                model.setUpperBound('EX_o2_e', o2Availability);

                // C. Solve
                const fbaResult = escherFba.solve(model, solver);
                let rawGrowth   = fbaResult.objectiveValue;

                // D. Maintenance & stress tax
                // C:N factor is applied here — N-limited media reduce net growth.
                // MAINTENANCE_COEFFICIENT now comes from pipeline.py when available.
                let netGrowth = (rawGrowth * tempFactor * phFactor * cnFactor)
                                - MAINTENANCE_COEFFICIENT;
                netGrowth = Math.max(0, netGrowth);

                // E. Industrial yield (uses inoculum-adjusted X0 and actual duration)
                const finalBiomass = calculateBiomassYield(
                    netGrowth,
                    userInputs.volume,
                    fermentationHours,
                    inoculumFraction
                );

                results.push({
                    temp,
                    ph,
                    rpm,
                    cnRatio:              cnRatio.toFixed(2),
                    cnFactor:             cnFactor.toFixed(3),
                    growthRate:           netGrowth.toFixed(4),
                    biomass_gL:           finalBiomass.toFixed(2),
                    o2_limited:           rawGrowth >= o2Availability * 0.95,
                    n_limited:            cnFactor < 0.8,
                    organism:             resolvedOrganismName || "unspecified",
                    // Carry pipeline provenance into each result row for traceability
                    tax_id:               bioMetadata.tax_id    ?? null,
                    pipeline_maintenance: bioMetadata.maintenance_coefficient ?? null,
                });
            }
        }
    }

    const optimum = results.reduce((prev, curr) =>
        parseFloat(curr.growthRate) > parseFloat(prev.growthRate) ? curr : prev
    );

    renderFullReport(results, optimum);
}

// ─── Biomass yield (updated to accept inoculum fraction) ─────────────────────

/**
 * @param {number} mu               Net specific growth rate (h⁻¹)
 * @param {number} volume           Working volume (L)
 * @param {number} time             Fermentation duration (h)
 * @param {number} inoculumFraction Inoculum as a fraction of working volume (0–1)
 * @returns {number}                Final total biomass (g)
 */
function calculateBiomassYield(mu, volume, time, inoculumFraction = 0.05) {
    // Starting concentration scales with inoculum fraction.
    // Assume the seed culture is at ≈ 10 g/L.
    const SEED_CONCENTRATION = 10; // g/L
    const X0 = inoculumFraction * SEED_CONCENTRATION;  // g/L
    return (X0 * Math.exp(mu * time)) * volume;         // g total
}
