/**
 * Optimized Bioprocess Simulator with Biological Guardrails
 * Incorporates: Michaelis-Menten Kinetics, kLa Limits, Maintenance Energy,
 * C:N ratio-driven nutrient constraints, organism-specific overrides,
 * bioreactor condition inputs, and optional advanced parameters.
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
 * Return a small environmental adjustment (for temperature and pH optima)
 * derived from the free-text organism name.
 *
 * Currently heuristic — recognises broad taxonomic keywords in the name.
 * Production use should query a database (e.g. BV-BRC, as in pipeline.py).
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
 * @param {string} modelXml     SBML XML string (from BV-BRC or local file)
 * @param {object} userInputs   Collected from the web UI (see schema below)
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
 */
async function runScientificallyHardenedOptimization(modelXml, userInputs) {
    const solver = await glpk();
    const model  = escherFba.loadModel(modelXml);

    // ── 1. PHYSICAL CONSTANTS ──────────────────────────────────────────────
    const MAX_KLA = 350;   // Max O₂ transfer rate (mmol/L/h), stirred tank

    // Organism-specific environmental optima (overridden by organism name if given)
    const orgDefaults = organismsDefaults(userInputs.organism);
    const CRITICAL_TEMP         = orgDefaults.criticalTemp;
    const PH_OPTIMA             = orgDefaults.phOptima;
    const MAINTENANCE_COEFFICIENT = orgDefaults.maintenanceCoeff;

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
    const KS_CARBON         = 0.5;
    const VMAX_CARBON_UPTAKE = 10.0;  // mmol/g/h
    const cFluxBound = (VMAX_CARBON_UPTAKE * cConc_mmolL) / (KS_CARBON + cConc_mmolL);

    // Apply nutrient bounds to the model (exchange reactions)
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
                // C:N factor is applied here — N-limited media reduce net growth
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
                    cnRatio:       cnRatio.toFixed(2),
                    cnFactor:      cnFactor.toFixed(3),
                    growthRate:    netGrowth.toFixed(4),
                    biomass_gL:    finalBiomass.toFixed(2),
                    o2_limited:    rawGrowth >= o2Availability * 0.95,
                    n_limited:     cnFactor < 0.8,
                    organism:      userInputs.organism || "unspecified",
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
    // Starting concentration scales with inoculum fraction
    // Assume the seed culture is at ≈ 10 g/L
    const SEED_CONCENTRATION = 10; // g/L
    const X0 = inoculumFraction * SEED_CONCENTRATION;  // g/L
    return (X0 * Math.exp(mu * time)) * volume;         // g total
}
