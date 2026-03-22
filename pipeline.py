#!/usr/bin/env python3
import urllib.request
import urllib.parse
import urllib.error
import xml.etree.ElementTree as ET
import json
import os
import sys
import re
import argparse
import logging
import time

logging.basicConfig(level=logging.INFO, format="[%(levelname)s] %(message)s", stream=sys.stderr)
log = logging.getLogger(__name__)

NCBI_ESEARCH = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi"
NCBI_EFETCH  = "https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi"

# NCBI API key — read from environment variable (set in ~/.bashrc or server config)
# export NCBI_API_KEY=bc81dc27024bce567d64cb201a28e9ad8508
NCBI_API_KEY = os.environ.get("NCBI_API_KEY", "bc81dc27024bce567d64cb201a28e9ad8508")

MODELS_DIR   = os.path.join(os.path.dirname(__file__), "models")
DEFAULT_GEM  = os.path.join(MODELS_DIR, "default_fungi.xml")


def extract_organism(fasta_content: str) -> str:

    first_header = fasta_content.split('\n')[0]
    log.info(f"[extract_organism] Header: {first_header[:120]}")
    m = re.search(r'\[([A-Z][a-z]+\s+[a-z]+[^\]]*)\]', first_header)
    if m:
        name = m.group(1).strip()
        log.info(f"[extract_organism] Found in brackets: {name}")
        return name

    m = re.search(r'organism[=\s]+([A-Z][a-z]+[\s_][a-z]+)', first_header)
    if m:
        name = m.group(1).replace('_', ' ').strip()
        log.info(f"[extract_organism] Found organism= tag: {name}")
        return name

    m = re.search(r'>\S+\s+([A-Z][a-z]+\s+[a-z]+)', first_header)
    if m:
        name = m.group(1).strip()
        log.info(f"[extract_organism] Found genus species after ID: {name}")
        return name

    m = re.search(r'>([A-Z][a-z]+)_([a-z]+)', first_header)
    if m:
        name = f"{m.group(1)} {m.group(2)}"
        log.info(f"[extract_organism] Found in ID: {name}")
        return name

    log.warning("[extract_organism] Could not extract organism name from header")
    return "Unknown"


def ncbi_taxon_lookup(organism_name: str) -> tuple[int, list]:

    cache_key = organism_name.lower().replace(' ', '_')
    cache_file = f"/tmp/gemgen_ncbi_{cache_key}.json"
    if os.path.isfile(cache_file):
        try:
            with open(cache_file) as f:
                cached = json.load(f)
            log.info(f"[ncbi_taxon_lookup] Cache hit for '{organism_name}'")
            return cached['taxon_id'], cached['lineage']
        except Exception:
            pass

    log.info(f"[ncbi_taxon_lookup] Searching NCBI for: '{organism_name}'")

    def ncbi_get(url, retries=4):
        for attempt in range(retries):
            try:
                with urllib.request.urlopen(url, timeout=15) as r:
                    return r.read().decode()
            except urllib.error.HTTPError as e:
                if e.code == 429:
                    wait = 2 ** attempt  # 1, 2, 4, 8 seconds
                    log.warning(f"[ncbi_taxon_lookup] 429 rate limit — waiting {wait}s (attempt {attempt+1})")
                    time.sleep(wait)
                else:
                    raise
            except Exception as e:
                log.error(f"[ncbi_taxon_lookup] Request error: {e}")
                return None
        log.error(f"[ncbi_taxon_lookup] Failed after {retries} attempts")
        return None

    params = urllib.parse.urlencode({
        'db': 'taxonomy', 'term': organism_name,
        'retmode': 'json', 'api_key': NCBI_API_KEY
    })
    resp   = ncbi_get(f"{NCBI_ESEARCH}?{params}")
    if not resp:
        return 0, []

    try:
        data    = json.loads(resp)
        id_list = data.get('esearchresult', {}).get('idlist', [])
    except Exception as e:
        log.error(f"[ncbi_taxon_lookup] esearch parse error: {e}")
        return 0, []

    if not id_list:
        log.warning(f"[ncbi_taxon_lookup] No NCBI result for '{organism_name}'")
        return 0, []

    taxon_id = int(id_list[0])
    log.info(f"[ncbi_taxon_lookup] taxon_id = {taxon_id}")

    time.sleep(0.15)
    params   = urllib.parse.urlencode({
        'db': 'taxonomy', 'id': taxon_id,
        'retmode': 'xml', 'api_key': NCBI_API_KEY
    })
    xml_data = ncbi_get(f"{NCBI_EFETCH}?{params}")
    if not xml_data:
        return taxon_id, []

    try:
        root    = ET.fromstring(xml_data)
        lineage = root.findtext('.//Lineage') or ''
        log.info(f"[ncbi_taxon_lookup] Raw lineage text: '{lineage[:120]}'")
        lineage_list = [x.strip() for x in lineage.split(';') if x.strip()]

        with open(cache_file, 'w') as f:
            json.dump({'taxon_id': taxon_id, 'lineage': lineage_list}, f)

        return taxon_id, lineage_list
    except Exception as e:
        log.error(f"[ncbi_taxon_lookup] efetch parse error: {e}")
        return taxon_id, []


def validate_fungi(lineage: list, organism_name: str) -> None:
    if not lineage:
        log.warning(f"[validate_fungi] Empty lineage for '{organism_name}' — allowing through (API may have failed)")
        return

    fungi_keywords = ['Fungi', 'Ascomycota', 'Basidiomycota', 'Mucoromycota',
                      'Chytridiomycota', 'Microsporidia', 'Dikarya']
    log.info(f"[validate_fungi] Checking lineage: {lineage}")
    log.info(f"[validate_fungi] Looking for any of: {fungi_keywords}")
    if any(kw in lineage for kw in fungi_keywords):
        log.info(f"[validate_fungi] ✓ Fungal confirmed: {organism_name}")
        return
    raise ValueError(
        f"Non-fungal genome detected ('{organism_name}'). "
        f"Lineage: {' > '.join(lineage[-4:]) if lineage else 'unknown'}. "
        "GEMgen only supports filamentous fungi."
    )


def constraints(taxon_id: int, lineage: list) -> dict:
    is_basidio = any('Basidiomycota' in l for l in lineage)
    return {
        "organism_type":           "Fungal",
        "required_supplements":    ["Biotin", "Thiamine"],
        "maintenance_coefficient": 0.05,
        "tax_id":                  taxon_id,
        "subclass":                "Basidiomycota" if is_basidio else "Ascomycota",
    }

def load_gem(taxon_id: int) -> str:

    os.makedirs(MODELS_DIR, exist_ok=True)

    taxon_model = os.path.join(MODELS_DIR, f"{taxon_id}.xml")
    if os.path.isfile(taxon_model):
        log.info(f"[load_gem] Using taxon-specific model: {taxon_model}")
        with open(taxon_model) as f:
            return f.read()

    if os.path.isfile(DEFAULT_GEM):
        log.info(f"[load_gem] Using default fungal GEM: {DEFAULT_GEM}")
        with open(DEFAULT_GEM) as f:
            return f.read()

    log.warning("[load_gem] No GEM available — returning minimal stub SBML")
    return _minimal_sbml(taxon_id)


def _minimal_sbml(taxon_id: int) -> str:
    return f"""<?xml version="1.0" encoding="UTF-8"?>
<sbml xmlns="http://www.sbml.org/sbml/level3/version1/core"
      xmlns:fbc="http://www.sbml.org/sbml/level3/version1/fbc/version2"
      level="3" version="1" fbc:required="false">
  <model id="GEMgen_stub_{taxon_id}" name="GEMgen fungal stub (taxon {taxon_id})">
    <listOfCompartments>
      <compartment id="e" constant="true"/>
      <compartment id="c" constant="true"/>
    </listOfCompartments>
    <listOfSpecies>
      <species id="glc_e"     compartment="e" hasOnlySubstanceUnits="false" boundaryCondition="false" constant="false"/>
      <species id="glc_c"     compartment="c" hasOnlySubstanceUnits="false" boundaryCondition="false" constant="false"/>
      <species id="nh4_e"     compartment="e" hasOnlySubstanceUnits="false" boundaryCondition="false" constant="false"/>
      <species id="nh4_c"     compartment="c" hasOnlySubstanceUnits="false" boundaryCondition="false" constant="false"/>
      <species id="o2_e"      compartment="e" hasOnlySubstanceUnits="false" boundaryCondition="false" constant="false"/>
      <species id="o2_c"      compartment="c" hasOnlySubstanceUnits="false" boundaryCondition="false" constant="false"/>
      <species id="atp_c"     compartment="c" hasOnlySubstanceUnits="false" boundaryCondition="false" constant="false"/>
      <species id="nadh_c"    compartment="c" hasOnlySubstanceUnits="false" boundaryCondition="false" constant="false"/>
      <species id="co2_c"     compartment="c" hasOnlySubstanceUnits="false" boundaryCondition="false" constant="false"/>
      <species id="h2o_c"     compartment="c" hasOnlySubstanceUnits="false" boundaryCondition="false" constant="false"/>
      <species id="biomass_c" compartment="c" hasOnlySubstanceUnits="false" boundaryCondition="false" constant="false"/>
    </listOfSpecies>
    <listOfReactions>
      <reaction id="EX_glc__D_e" reversible="true" fbc:lowerFluxBound="-10" fbc:upperFluxBound="0">
        <listOfReactants><speciesReference species="glc_e" stoichiometry="1" constant="true"/></listOfReactants>
      </reaction>
      <reaction id="EX_nh4_e" reversible="true" fbc:lowerFluxBound="-10" fbc:upperFluxBound="0">
        <listOfReactants><speciesReference species="nh4_e" stoichiometry="1" constant="true"/></listOfReactants>
      </reaction>
      <reaction id="EX_o2_e" reversible="true" fbc:lowerFluxBound="-20" fbc:upperFluxBound="0">
        <listOfReactants><speciesReference species="o2_e" stoichiometry="1" constant="true"/></listOfReactants>
      </reaction>
      <reaction id="EX_co2_e" reversible="true" fbc:lowerFluxBound="0" fbc:upperFluxBound="1000">
        <listOfReactants><speciesReference species="co2_c" stoichiometry="1" constant="true"/></listOfReactants>
      </reaction>
      <reaction id="EX_biomass" reversible="false" fbc:lowerFluxBound="0" fbc:upperFluxBound="1000">
        <listOfReactants><speciesReference species="biomass_c" stoichiometry="1" constant="true"/></listOfReactants>
      </reaction>
      <reaction id="EX_h2o_e" reversible="true" fbc:lowerFluxBound="-1000" fbc:upperFluxBound="1000">
        <listOfReactants><speciesReference species="h2o_c" stoichiometry="1" constant="true"/></listOfReactants>
      </reaction>
      <reaction id="GLCt" reversible="false" fbc:lowerFluxBound="0" fbc:upperFluxBound="1000">
        <listOfReactants><speciesReference species="glc_e" stoichiometry="1" constant="true"/></listOfReactants>
        <listOfProducts><speciesReference species="glc_c" stoichiometry="1" constant="true"/></listOfProducts>
      </reaction>
      <reaction id="NH4t" reversible="false" fbc:lowerFluxBound="0" fbc:upperFluxBound="1000">
        <listOfReactants><speciesReference species="nh4_e" stoichiometry="1" constant="true"/></listOfReactants>
        <listOfProducts><speciesReference species="nh4_c" stoichiometry="1" constant="true"/></listOfProducts>
      </reaction>
      <reaction id="O2t" reversible="false" fbc:lowerFluxBound="0" fbc:upperFluxBound="1000">
        <listOfReactants><speciesReference species="o2_e" stoichiometry="1" constant="true"/></listOfReactants>
        <listOfProducts><speciesReference species="o2_c" stoichiometry="1" constant="true"/></listOfProducts>
      </reaction>
      <reaction id="GLYCOLYSIS" reversible="false" fbc:lowerFluxBound="0" fbc:upperFluxBound="1000">
        <listOfReactants><speciesReference species="glc_c" stoichiometry="1" constant="true"/></listOfReactants>
        <listOfProducts>
          <speciesReference species="atp_c"  stoichiometry="2"  constant="true"/>
          <speciesReference species="nadh_c" stoichiometry="2"  constant="true"/>
          <speciesReference species="co2_c"  stoichiometry="2"  constant="true"/>
        </listOfProducts>
      </reaction>
      <reaction id="OXPHOS" reversible="false" fbc:lowerFluxBound="0" fbc:upperFluxBound="1000">
        <listOfReactants>
          <speciesReference species="nadh_c" stoichiometry="1"   constant="true"/>
          <speciesReference species="o2_c"   stoichiometry="0.5" constant="true"/>
        </listOfReactants>
        <listOfProducts>
          <speciesReference species="atp_c"  stoichiometry="2.5" constant="true"/>
          <speciesReference species="h2o_c"  stoichiometry="1"   constant="true"/>
        </listOfProducts>
      </reaction>
      <reaction id="BIOMASS" reversible="false" fbc:lowerFluxBound="0" fbc:upperFluxBound="1000">
        <listOfReactants>
          <speciesReference species="atp_c"  stoichiometry="10" constant="true"/>
          <speciesReference species="nh4_c"  stoichiometry="1"  constant="true"/>
        </listOfReactants>
        <listOfProducts>
          <speciesReference species="biomass_c" stoichiometry="1" constant="true"/>
        </listOfProducts>
      </reaction>
    </listOfReactions>
    <fbc:listOfObjectives fbc:activeObjective="obj1">
      <fbc:objective fbc:id="obj1" fbc:type="maximize">
        <fbc:listOfFluxObjectives>
          <fbc:fluxObjective fbc:reaction="BIOMASS" fbc:coefficient="1"/>
        </fbc:listOfFluxObjectives>
      </fbc:objective>
    </fbc:listOfObjectives>
  </model>
</sbml>"""

def model_orchestra(fasta_content: str, job_id: str, output_dir: str, organism_name_hint: str = "") -> dict:
    os.makedirs(output_dir, exist_ok=True)

    def write_status(stage: str, overall: str, message: str = "", stages_override: dict = None):
        stages = stages_override or {
            "taxon_detect": {"status": "pending", "message": ""},
            "validate":     {"status": "pending", "message": ""},
            "load_gem":     {"status": "pending", "message": ""},
            "done":         {"status": "pending", "message": ""},
        }
        with open(os.path.join(output_dir, "status.json"), "w") as f:
            json.dump({
                "job_id": job_id, "overall": overall,
                "current_stage": stage, "message": message,
                "stages": stages,
            }, f, indent=2)

    try:
        write_status("taxon_detect", "running", "Identifying organism...")

        if organism_name_hint and organism_name_hint.strip():
            organism_name = organism_name_hint.strip()
            log.info(f"[model_orchestra] Using form-provided organism name: {organism_name}")
        else:
            organism_name = extract_organism(fasta_content)

        write_status("taxon_detect", "running", f"Detected: {organism_name}")

        taxon_id, lineage = ncbi_taxon_lookup(organism_name)

        write_status("validate", "running", f"Validating: {organism_name}")
        validate_fungi(lineage, organism_name)

        write_status("load_gem", "running", "Loading genome-scale model...")
        model_xml = load_gem(taxon_id)

        xml_path = os.path.join(output_dir, "model.xml")
        with open(xml_path, "w") as f:
            f.write(model_xml)

        bio_metadata = constraints(taxon_id, lineage)

        result = {
            "status":         "success",
            "job_id":         job_id,
            "model_xml":      model_xml,
            "model_xml_path": xml_path,
            "bio_metadata":   bio_metadata,
            "taxon_detected": organism_name,
            "taxon_id":       taxon_id,
            "lineage":        lineage[-5:],
        }

        with open(os.path.join(output_dir, "results.json"), "w") as f:
            json.dump(result, f, indent=2)

        with open(os.path.join(output_dir, "status.json"), "w") as f:
            json.dump({
                "job_id": job_id, "overall": "done",
                "current_stage": "done",
                "message": f"Complete — {organism_name} (taxon {taxon_id})",
                "stages": {
                    "taxon_detect": {"status": "done", "message": organism_name},
                    "validate":     {"status": "done", "message": "Fungal ✓"},
                    "load_gem":     {"status": "done", "message": f"Model loaded ({len(model_xml)} chars)"},
                    "done":         {"status": "done", "message": ""},
                }
            }, f, indent=2)

        log.info(f"[model_orchestra] ✓ Job {job_id} complete — {organism_name}")
        print(json.dumps(result))
        return result

    except ValueError as e:
        _write_error(output_dir, job_id, str(e), "fungi_error")
        print(json.dumps({"status": "fungi_error", "job_id": job_id, "error": str(e)}))
        sys.exit(2)

    except Exception as e:
        _write_error(output_dir, job_id, str(e), "error")
        print(json.dumps({"status": "error", "job_id": job_id, "error": str(e)}))
        sys.exit(1)


def _write_error(output_dir, job_id, message, status):
    with open(os.path.join(output_dir, "status.json"), "w") as f:
        json.dump({"job_id": job_id, "overall": status,
                   "current_stage": "error", "message": message}, f)
    with open(os.path.join(output_dir, "results.json"), "w") as f:
        json.dump({"status": status, "job_id": job_id, "error": message}, f)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--job_id",   required=True)
    parser.add_argument("--genome",   required=True)
    parser.add_argument("--out_dir",  required=True)
    parser.add_argument("--organism", default="", help="Organism name from form (optional but recommended)")
    args = parser.parse_args()

    with open(args.genome) as f:
        fasta_content = f.read()

    model_orchestra(fasta_content, args.job_id, args.out_dir, args.organism)
