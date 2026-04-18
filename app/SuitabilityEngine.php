<?php

declare(strict_types=1);

final class SuitabilityEngine
{
    /** @var array<string, mixed> */
    private array $riskRules;

    /** @param array<string, mixed> $riskRules */
    public function __construct(array $riskRules)
    {
        $this->riskRules = $riskRules;
    }

    /**
     * @param array<string, mixed> $parcel
     * @param array<string, mixed> $scenario
     * @return array<string, mixed>
     */
    public function scoreParcel(array $parcel, array $scenario): array
    {
        $failures = $this->validateEligibility($parcel, $scenario);

        $componentScores = [
            'planning_compatibility' => $this->planningCompatibilityScore($parcel, $scenario),
            'size_usability' => $this->sizeScore($parcel, $scenario),
            'location_logic' => $this->locationLogicScore($parcel),
            'constraint_burden' => $this->constraintBurdenScore($parcel),
            'ownership_likelihood' => $this->ownershipLikelihoodScore($parcel),
        ];

        $weighted = 0.0;
        foreach (($scenario['weights'] ?? []) as $key => $weight) {
            $weighted += ($componentScores[$key] ?? 0.0) * (float) $weight;
        }

        $riskPenalty = $this->politicalSensitivity($parcel);
        $finalScore = $this->clamp($weighted - $riskPenalty);

        return [
            'scenario_id' => $scenario['scenario_id'] ?? null,
            'parcel' => $parcel,
            'eligible' => count($failures) === 0,
            'eligibility_failures' => $failures,
            'component_scores' => $componentScores,
            'weighted_score' => round($weighted * 100, 2),
            'risk_penalty' => round($riskPenalty * 100, 2),
            'final_score' => round((count($failures) === 0 ? $finalScore : 0.0) * 100, 2),
            'explanation' => $this->explanation($componentScores, $riskPenalty),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $parcels
     * @param array<string, mixed> $scenario
     * @param int $topN
     * @return array<string, mixed>
     */
    public function runScenario(array $parcels, array $scenario, int $topN = 10): array
    {
        $results = [];
        foreach ($parcels as $parcel) {
            $results[] = $this->scoreParcel($parcel, $scenario);
        }

        usort(
            $results,
            static fn (array $a, array $b): int => (float) $b['final_score'] <=> (float) $a['final_score']
        );

        return [
            'scenario' => [
                'scenario_id' => $scenario['scenario_id'] ?? null,
                'name' => $scenario['name'] ?? null,
                'minimum_size_m2' => $scenario['minimum_size_m2'] ?? null,
                'weights' => $scenario['weights'] ?? [],
            ],
            'shortlist' => array_slice($results, 0, max($topN, 1)),
            'all_results' => $results,
        ];
    }

    /** @param array<string, mixed> $parcel @param array<string, mixed> $scenario @return array<int, string> */
    private function validateEligibility(array $parcel, array $scenario): array
    {
        $failures = [];

        $size = (float) ($parcel['size_m2'] ?? 0);
        $minSize = (float) ($scenario['minimum_size_m2'] ?? 0);
        if ($size < $minSize) {
            $failures[] = 'Parcel below minimum size';
        }

        $constraints = $parcel['constraints'] ?? [];
        $maxConstraintCount = (int) ($scenario['max_constraint_count'] ?? PHP_INT_MAX);
        if (count($constraints) > $maxConstraintCount) {
            $failures[] = 'Constraint count above scenario tolerance';
        }

        $blocked = array_values(array_intersect($constraints, $scenario['excluded_constraints'] ?? []));
        if ($blocked !== []) {
            $failures[] = 'Contains excluded constraints: ' . implode(', ', $blocked);
        }

        return $failures;
    }

    /** @param array<string, mixed> $parcel @param array<string, mixed> $scenario */
    private function planningCompatibilityScore(array $parcel, array $scenario): float
    {
        $zoning = (string) ($parcel['zoning'] ?? '');
        $preferredZoning = $scenario['preferred_zoning'] ?? [];
        $zoningScore = in_array($zoning, $preferredZoning, true) ? 1.0 : 0.45;
        $policyAlignment = (float) ($parcel['policy_alignment_score'] ?? 0.0);

        return $this->clamp(($zoningScore + $policyAlignment) / 2.0);
    }

    /** @param array<string, mixed> $parcel @param array<string, mixed> $scenario */
    private function sizeScore(array $parcel, array $scenario): float
    {
        $size = (float) ($parcel['size_m2'] ?? 0);
        $minSize = (float) ($scenario['minimum_size_m2'] ?? 0);
        if ($size < $minSize || $minSize <= 0) {
            return 0.0;
        }

        $bonus = min($size / ($minSize * 2.0), 1.0);
        return $this->clamp(0.5 + $bonus * 0.5);
    }

    /** @param array<string, mixed> $parcel */
    private function locationLogicScore(array $parcel): float
    {
        $transport = $this->clamp(1 - ((float) ($parcel['distance_to_public_transport_m'] ?? 0) / 1000));
        $health = $this->clamp(1 - ((float) ($parcel['distance_to_health_service_m'] ?? 0) / 3000));
        $serviceReadiness = (float) ($parcel['service_readiness_score'] ?? 0.0);

        return $this->clamp(($transport + $health + $serviceReadiness) / 3.0);
    }

    /** @param array<string, mixed> $parcel */
    private function constraintBurdenScore(array $parcel): float
    {
        $penalties = $this->riskRules['constraint_penalties'] ?? [];
        $constraints = $parcel['constraints'] ?? [];

        $burden = 0.0;
        foreach ($constraints as $constraint) {
            $burden += (float) ($penalties[$constraint] ?? 0.12);
        }

        return $this->clamp(1 - $burden);
    }

    /** @param array<string, mixed> $parcel */
    private function ownershipLikelihoodScore(array $parcel): float
    {
        $ownershipScores = $this->riskRules['ownership_scores'] ?? [];
        $ownershipType = (string) ($parcel['ownership_type'] ?? 'unknown');
        return (float) ($ownershipScores[$ownershipType] ?? 0.4);
    }

    /** @param array<string, mixed> $parcel */
    private function politicalSensitivity(array $parcel): float
    {
        $table = $this->riskRules['political_sensitivity'] ?? [];
        $edge = (float) ($parcel['distance_to_residential_edge_m'] ?? 0.0);

        if ($edge < 30) {
            return (float) ($table['residential_edge_lt_30m'] ?? 0.28);
        }
        if ($edge < 80) {
            return (float) ($table['residential_edge_30_to_80m'] ?? 0.18);
        }
        return (float) ($table['residential_edge_gte_80m'] ?? 0.08);
    }

    /** @param array<string, float> $componentScores @return array<int, string> */
    private function explanation(array $componentScores, float $riskPenalty): array
    {
        $notes = [];

        if (($componentScores['planning_compatibility'] ?? 0) > 0.75) {
            $notes[] = 'Strong planning compatibility';
        }
        if (($componentScores['size_usability'] ?? 1) < 0.6) {
            $notes[] = 'Site is near minimum size threshold';
        }
        if ($riskPenalty > 0.2) {
            $notes[] = 'Elevated political sensitivity near residential edge';
        }
        if ($notes === []) {
            $notes[] = 'Balanced profile across planning, size, and location factors';
        }

        return $notes;
    }

    private function clamp(float $value, float $minimum = 0.0, float $maximum = 1.0): float
    {
        return max($minimum, min($maximum, $value));
    }
}
