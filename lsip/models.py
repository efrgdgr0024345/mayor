from dataclasses import dataclass
from typing import Dict, List


@dataclass
class Parcel:
    parcel_id: str
    location: str
    size_m2: float
    zoning: str
    ownership_type: str
    constraints: List[str]
    distance_to_public_transport_m: float
    distance_to_health_service_m: float
    distance_to_residential_edge_m: float
    policy_alignment_score: float
    service_readiness_score: float


@dataclass
class Scenario:
    scenario_id: str
    name: str
    minimum_size_m2: float
    preferred_zoning: List[str]
    excluded_constraints: List[str]
    max_constraint_count: int
    weights: Dict[str, float]
