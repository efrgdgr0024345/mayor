# mayor

## Land Suitability Intelligence Platform (LSIP) — cPanel-ready PHP MVP

This project now runs as a plain **PHP + HTML** application that works on typical cPanel shared hosting.
All parcel, scenario, and rule definitions are stored in JSON files (no database required yet).

## Stack

- PHP 8+
- HTML/CSS (no frontend framework)
- JSON files as the storage layer

## Project layout

- `index.php` — main dashboard UI for running scenario rankings
- `api/shortlist.php` — JSON API endpoint for shortlist generation
- `app/JsonDataStore.php` — JSON loader for parcels/scenarios/risk rules
- `app/SuitabilityEngine.php` — eligibility, scoring, risk penalty, explanation logic
- `scripts/generate_shortlist.php` — CLI helper to generate `outputs/*.json`
- `data/parcels.json` — parcel-level dataset
- `data/scenarios.json` — scenario definitions and weights
- `data/risk_rules.json` — ownership, constraint, political sensitivity rules
- `outputs/` — generated shortlist files

## Run locally (PHP built-in server)

```bash
php -S 127.0.0.1:8000
```

Open:

- Dashboard: `http://127.0.0.1:8000/index.php`
- API: `http://127.0.0.1:8000/api/shortlist.php?scenario_id=transitional_30&top_n=10`

## Generate shortlist file via CLI

```bash
php scripts/generate_shortlist.php transitional_30 10
```

This writes:

- `outputs/shortlist_transitional_30.json`

## cPanel deployment notes

1. Upload repository contents into your domain document root (or subfolder).
2. Ensure `index.php` is in the served path.
3. Confirm PHP 8+ is selected in cPanel.
4. Ensure the web user can write to `outputs/` if you plan to generate files on-server.
5. No database setup is required for this MVP.

## Next step (when ready for DB)

- Keep JSON schema contracts stable.
- Replace `JsonDataStore` internals with DB-backed reads.
- Keep `SuitabilityEngine` unchanged so scoring remains consistent.
