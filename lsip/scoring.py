from dataclasses import asdict
from typing import Dict, List

from .models import Parcel, Scenario


def clamp(value: float, minimum: float = 0.0, maximum: float = 1.0) -> float:
    return max(minimum, min(maximum, value))


def size_score(parcel: Parcel, scenario: Scenario) -> float:
    if parcel.size_m2 < scenario.minimum_size_m2:
        return 0.0
    bonus = min(parcel.size_m2 / (scenario.minimum_size_m2 * 2.0), 1.0)
    return clamp(0.5 + bonus * 0.5)


def planning_compatibility_score(parcel: Parcel, scenario: Scenario) -> float:
    zoning_score = 1.0 if parcel.zoning in scenario.preferred_zoning else 0.45
    return clamp((zoning_score + parcel.policy_alignment_score) / 2.0)


def location_logic_score(parcel: Parcel) -> float:
    transport = clamp(1 - parcel.distance_to_public_transport_m / 1000)
    health = clamp(1 - parcel.distance_to_health_service_m / 3000)
    return (transport + health + parcel.service_readiness_score) / 3.0


def constraint_burden_score(parcel: Parcel, rules: Dict) -> float:
    penalties = rules["constraint_penalties"]
    burden = sum(penalties.get(item, 0.12) for item in parcel.constraints)
    return clamp(1 - burden)


def ownership_likelihood_score(parcel: Parcel, rules: Dict) -> float:
    return rules["ownership_scores"].get(parcel.ownership_type, 0.4)


def political_sensitivity(parcel: Parcel, rules: Dict) -> float:
    edge = parcel.distance_to_residential_edge_m
    table = rules["political_sensitivity"]
    if edge < 30:
        return table["residential_edge_lt_30m"]
    if edge < 80:
        return table["residential_edge_30_to_80m"]
    return table["residential_edge_gte_80m"]


def validate_eligibility(parcel: Parcel, scenario: Scenario) -> List[str]:
    failures: List[str] = []
    if parcel.size_m2 < scenario.minimum_size_m2:
        failures.append("Parcel below minimum size")
    if len(parcel.constraints) > scenario.max_constraint_count:
        failures.append("Constraint count above scenario tolerance")

    blocked = set(parcel.constraints).intersection(scenario.excluded_constraints)
    if blocked:
        failures.append(f"Contains excluded constraints: {', '.join(sorted(blocked))}")

    return failures


def score_parcel(parcel: Parcel, scenario: Scenario, rules: Dict) -> Dict:
    failures = validate_eligibility(parcel, scenario)

    component_scores = {
        "planning_compatibility": planning_compatibility_score(parcel, scenario),
        "size_usability": size_score(parcel, scenario),
        "location_logic": location_logic_score(parcel),
        "constraint_burden": constraint_burden_score(parcel, rules),
        "ownership_likelihood": ownership_likelihood_score(parcel, rules),
    }

    weighted = sum(component_scores[key] * scenario.weights[key] for key in scenario.weights)
    risk_penalty = political_sensitivity(parcel, rules)
    final_score = clamp(weighted - risk_penalty)

    explanation = []
    if component_scores["planning_compatibility"] > 0.75:
        explanation.append("Strong planning compatibility")
    if component_scores["size_usability"] < 0.6:
        explanation.append("Site is near minimum size threshold")
    if risk_penalty > 0.2:
        explanation.append("Elevated political sensitivity near residential edge")
    if not explanation:
        explanation.append("Balanced profile across planning, size, and location factors")

    return {
        "scenario_id": scenario.scenario_id,
        "parcel": asdict(parcel),
        "eligible": len(failures) == 0,
        "eligibility_failures": failures,
        "component_scores": component_scores,
        "weighted_score": round(weighted * 100, 2),
        "risk_penalty": round(risk_penalty * 100, 2),
        "final_score": round((final_score if not failures else 0.0) * 100, 2),
        "explanation": explanation,
    }
