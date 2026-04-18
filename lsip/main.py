import argparse
import json
from pathlib import Path
from typing import List

from .data_store import JsonDataStore
from .scoring import score_parcel


def run_scenario(data_dir: Path, output_dir: Path, scenario_id: str, top_n: int) -> Path:
    store = JsonDataStore(data_dir)
    parcels = store.load_parcels()
    scenarios = {scenario.scenario_id: scenario for scenario in store.load_scenarios()}
    rules = store.load_risk_rules()

    if scenario_id not in scenarios:
        raise ValueError(f"Scenario '{scenario_id}' not found in data/scenarios.json")

    selected = scenarios[scenario_id]
    results = [score_parcel(parcel, selected, rules) for parcel in parcels]
    ranked = sorted(results, key=lambda row: row["final_score"], reverse=True)

    payload = {
        "scenario": {
            "scenario_id": selected.scenario_id,
            "name": selected.name,
            "minimum_size_m2": selected.minimum_size_m2,
            "weights": selected.weights,
        },
        "shortlist": ranked[:top_n],
        "all_results": ranked,
    }

    output_dir.mkdir(parents=True, exist_ok=True)
    output_path = output_dir / f"shortlist_{scenario_id}.json"
    with output_path.open("w", encoding="utf-8") as handle:
        json.dump(payload, handle, indent=2)

    return output_path


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run LSIP scenario scoring with JSON data files.")
    parser.add_argument("--data-dir", default="data", help="Directory containing JSON data inputs")
    parser.add_argument("--output-dir", default="outputs", help="Directory for generated shortlist outputs")
    parser.add_argument("--scenario-id", default="transitional_30", help="Scenario identifier")
    parser.add_argument("--top-n", type=int, default=10, help="Number of top sites to include in shortlist")
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    output_path = run_scenario(Path(args.data_dir), Path(args.output_dir), args.scenario_id, args.top_n)
    print(f"Generated shortlist: {output_path}")


if __name__ == "__main__":
    main()
