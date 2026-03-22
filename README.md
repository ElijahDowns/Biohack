FunGem
Biohackathon 6 — Pacifico Biolabs GmbH
FunGem is a web platform that predicts fungal fermentation performance directly from genome data. It combines genome-scale metabolic modelling, Flux Balance Analysis, and bioreactor-aware corrections to estimate key industrial metrics in seconds — without running a single experiment.

What it does
Given a protein FASTA file and bioreactor conditions, FunGem:

1. Identifies the organism via NCBI Taxonomy (hard-blocks non-fungal inputs)
2. Loads a genome-scale metabolic model (SBML format)
3. Runs Flux Balance Analysis entirely in the browser via a pure-JS LP solver
4. Applies five bioreactor corrections to bring the theoretical optimum to a realistic prediction
5. Outputs the three metrics that matter in industrial bioprocessing:
      T — Titer  final biomass concentration (g/L)
      R — Rate  volumetric productivity (g/L/h)
      Y — Yield  biomass per gram of carbon substrate (g/g)

How it works
Genome (FASTA) → NCBI taxon detection → fungi validation → SBML model load → FBA (browser, jsLPSolver) → five-factor bioreactor corrections → TRY output

The five corrections applied after FBA are where the engineering realism comes in:
1. Michaelis-Menten carbon uptake kinetics
2. kLa oxygen transfer ceiling (RPM-dependent)
3. C:N ratio penalty (optimal window 10–25:1 for fungi)
4. Temperature and pH bell curves around organism-specific optima
5. Maintenance energy coefficient subtraction

Highlights

FBA runs entirely client-side — no WASM, no server round-trip for computation
Supports industrial feedstocks: molasses, corn steep liquor, wheat bran, cheese whey, and more
Genome-driven predictions work for novel organisms and untested media combinations
Fungi-specific: validates lineage against NCBI Taxonomy before proceeding

Limitations

FunGem is a research prototype. When an organism-specific GEM is unavailable, it falls back to a reference fungal model covering core metabolism (glycolysis, oxidative phosphorylation, biomass synthesis) — all five corrections still apply. The full pipeline is built to integrate with BV-BRC for de novo GEM reconstruction; this is currently limited by server access rather than design.


Core idea


FunGem bridges genomics and bioprocessing — turning a genome sequence and a set of reactor conditions into an actionable TRY prediction, grounded in metabolic network optimisation rather than historical data interpolation.
