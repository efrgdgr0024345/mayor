<?php

declare(strict_types=1);

final class JsonDataStore
{
    private string $dataDir;

    public function __construct(string $dataDir)
    {
        $this->dataDir = rtrim($dataDir, DIRECTORY_SEPARATOR);
    }

    /** @return array<int, array<string, mixed>> */
    public function loadParcels(): array
    {
        $raw = $this->loadJson('parcels.json');
        return is_array($raw) ? $raw : [];
    }

    /** @return array<string, array<string, mixed>> */
    public function loadScenarios(): array
    {
        $payload = $this->loadJson('scenarios.json');
        $rows = $payload['scenarios'] ?? [];
        $map = [];

        foreach ($rows as $scenario) {
            if (!isset($scenario['scenario_id'])) {
                continue;
            }
            $map[(string) $scenario['scenario_id']] = $scenario;
        }

        return $map;
    }

    /** @return array<string, mixed> */
    public function loadRiskRules(): array
    {
        $raw = $this->loadJson('risk_rules.json');
        return is_array($raw) ? $raw : [];
    }

    /** @return array<string, mixed> */
    private function loadJson(string $filename): array
    {
        $path = $this->dataDir . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            throw new RuntimeException('Missing data file: ' . $path);
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Unable to read file: ' . $path);
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON content in: ' . $path);
        }

        return $decoded;
    }
}
