# mayor

## Land Suitability Intelligence Platform (LSIP) — JSON-first MVP

This repository now contains a database-free starter implementation of the LSIP vision.
All data-model details are stored in JSON files so the scoring engine can run without setting up Postgres, PostGIS, or any external database.

## Project layout

- `data/parcels.json` — parcel-level dataset (mock Fremantle pilot input)
- `data/scenarios.json` — scenario definitions and suitability weights
- `data/risk_rules.json` — ownership, constraint, and political sensitivity rules
- `lsip/models.py` — parcel/scenario data models
- `lsip/data_store.py` — JSON data access layer
- `lsip/scoring.py` — eligibility checks + scoring + explanation logic
- `lsip/main.py` — CLI entry point to generate scenario shortlists
- `outputs/` — generated shortlist JSON files

## Run

```bash
python -m lsip.main --scenario-id transitional_30 --top-n 10
```

Optional flags:

- `--data-dir` (default: `data`)
- `--output-dir` (default: `outputs`)
- `--scenario-id` (default: `transitional_30`)
- `--top-n` (default: `10`)

## Example output

Running the CLI writes a file like:

- `outputs/shortlist_transitional_30.json`

Each result includes:

- eligibility status + failure reasons
- component scores (planning, size, location, constraints, ownership)
- risk penalty
- final score
- explanation bullets ("Why this site?")

## Next build step

When ready to move past JSON files:

1. Keep JSON schemas stable.
2. Replace `JsonDataStore` with a repository abstraction backed by a real datastore.
3. Keep the scoring engine unchanged so validation remains consistent across storage backends.
