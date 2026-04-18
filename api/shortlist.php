<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/JsonDataStore.php';
require_once __DIR__ . '/../app/SuitabilityEngine.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $scenarioId = $_GET['scenario_id'] ?? 'transitional_30';
    $topN = isset($_GET['top_n']) ? max(1, (int) $_GET['top_n']) : 10;

    $store = new JsonDataStore(__DIR__ . '/../data');
    $scenarios = $store->loadScenarios();

    if (!isset($scenarios[$scenarioId])) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown scenario_id: ' . $scenarioId], JSON_PRETTY_PRINT);
        exit;
    }

    $engine = new SuitabilityEngine($store->loadRiskRules());
    $result = $engine->runScenario($store->loadParcels(), $scenarios[$scenarioId], $topN);

    echo json_encode($result, JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to generate shortlist',
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}
