<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/JsonDataStore.php';
require_once __DIR__ . '/../app/SuitabilityEngine.php';

$scenarioId = $argv[1] ?? 'transitional_30';
$topN = isset($argv[2]) ? max(1, (int) $argv[2]) : 10;

$store = new JsonDataStore(__DIR__ . '/../data');
$scenarios = $store->loadScenarios();

if (!isset($scenarios[$scenarioId])) {
    fwrite(STDERR, "Unknown scenario_id: {$scenarioId}\n");
    exit(1);
}

$engine = new SuitabilityEngine($store->loadRiskRules());
$result = $engine->runScenario($store->loadParcels(), $scenarios[$scenarioId], $topN);

$outDir = __DIR__ . '/../outputs';
if (!is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}

$outFile = $outDir . '/shortlist_' . $scenarioId . '.json';
file_put_contents($outFile, json_encode($result, JSON_PRETTY_PRINT));

fwrite(STDOUT, "Generated shortlist: {$outFile}\n");
