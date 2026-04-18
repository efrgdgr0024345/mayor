import json
from pathlib import Path
from typing import Any, Dict, List

from .models import Parcel, Scenario


class JsonDataStore:
    """Loads all platform data from JSON files (database-free MVP)."""

    def __init__(self, data_dir: Path):
        self.data_dir = data_dir

    def _load_json(self, filename: str) -> Any:
        path = self.data_dir / filename
        with path.open("r", encoding="utf-8") as handle:
            return json.load(handle)

    def load_parcels(self) -> List[Parcel]:
        raw = self._load_json("parcels.json")
        return [Parcel(**parcel) for parcel in raw]

    def load_scenarios(self) -> List[Scenario]:
        raw = self._load_json("scenarios.json")["scenarios"]
        return [Scenario(**scenario) for scenario in raw]

    def load_risk_rules(self) -> Dict[str, Any]:
        return self._load_json("risk_rules.json")
