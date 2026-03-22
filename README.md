FunGem - our biohackathon solution

FunGem is a lightweight web platform for predicting microbial growth and fermentation performance
directly from genome data.

It combines genome-scale metabolic models (GEMs), Flux Balance Analysis (FBA), and bioprocess
constraints to estimate key industrial metrics in seconds.

What it does
FunGem takes a genome (FASTA input) and:
• Identifies the organism (NCBI taxonomy)
• Loads a metabolic model (SBML)
• Runs FBA optimisation in the browser
• Applies biological corrections (temperature, pH, C\:N, O₂, kinetics)

Outputs TRY metrics:
• Titer (final biomass)
• Rate (productivity)
• Yield (efficiency)


How it works
Genome → GEM (SBML) → Flux Balance Analysis (FBA) → Constraints → Corrections → TRY

Core idea
FunGem bridges the gap between genomics and bioprocessing by turning metabolic networks into
actionable fermentation predictions.

Highlights
• Runs entirely in the browser (no heavy dependencies)
• Uses real metabolic models (SBML)
• Integrates kinetics + environmental effects
• Designed for rapid screening of fungal strains


Note
FunGem is a research prototype and uses simplified models when organism-specific GEMs are
unavailable. The actual system would be able to query BFV-BRC as an API to retrieve more complex
GEMs (which would also impact FBA).

Summary
FunGem predicts microbial performance by combining metabolic modelling,
optimisation, and bioprocess constraints into a fast, accessible pipeline.
