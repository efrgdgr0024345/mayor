<?php

declare(strict_types=1);

require_once __DIR__ . '/app/JsonDataStore.php';
require_once __DIR__ . '/app/SuitabilityEngine.php';

$scenarioId = $_GET['scenario_id'] ?? 'transitional_30';
$topN = isset($_GET['top_n']) ? max(1, (int) $_GET['top_n']) : 10;
$error = null;
$result = null;
$scenarios = [];

try {
    $store = new JsonDataStore(__DIR__ . '/data');
    $scenarios = $store->loadScenarios();

    if (!isset($scenarios[$scenarioId])) {
        $scenarioId = array_key_first($scenarios) ?? '';
    }

    if ($scenarioId === '' || !isset($scenarios[$scenarioId])) {
        throw new RuntimeException('No scenarios are available in data/scenarios.json.');
    }

    $engine = new SuitabilityEngine($store->loadRiskRules());
    $result = $engine->runScenario($store->loadParcels(), $scenarios[$scenarioId], $topN);
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>LSIP - Land Suitability Intelligence Platform</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 0; background: #f6f7fb; color: #1d2330; }
    .container { max-width: 1080px; margin: 24px auto; padding: 0 16px; }
    .panel { background: #fff; border-radius: 12px; box-shadow: 0 8px 20px rgba(0,0,0,.06); padding: 16px; margin-bottom: 16px; }
    h1 { margin-top: 0; }
    form { display: flex; gap: 12px; flex-wrap: wrap; align-items: end; }
    label { display: flex; flex-direction: column; font-size: 14px; gap: 6px; }
    select, input, button { padding: 10px; border: 1px solid #cfd4e3; border-radius: 8px; font-size: 14px; }
    button { background: #2f6fed; color: #fff; cursor: pointer; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 10px; border-bottom: 1px solid #ebedf4; text-align: left; vertical-align: top; }
    .score { font-weight: bold; }
    .tag { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; }
    .ok { background: #e9f9ef; color: #1c7d3a; }
    .bad { background: #ffecec; color: #ba2d2d; }
    .error { color: #ba2d2d; font-weight: 600; }
  </style>
</head>
<body>
<div class="container">
  <div class="panel">
    <h1>LSIP Suitability Dashboard (cPanel-ready PHP)</h1>
    <p>JSON-backed parcel scoring for micro-housing and transitional village scenarios.</p>
    <form method="get">
      <label>
        Scenario
        <select name="scenario_id">
          <?php foreach ($scenarios as $id => $scenario): ?>
            <option value="<?= htmlspecialchars((string) $id, ENT_QUOTES, 'UTF-8') ?>" <?= $id === $scenarioId ? 'selected' : '' ?>>
              <?= htmlspecialchars((string) ($scenario['name'] ?? $id), ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        Top N
        <input type="number" name="top_n" min="1" max="50" value="<?= htmlspecialchars((string) $topN, ENT_QUOTES, 'UTF-8') ?>">
      </label>
      <button type="submit">Run Scenario</button>
    </form>
  </div>

  <?php if ($error !== null): ?>
    <div class="panel error">Error: <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php elseif ($result !== null): ?>
    <div class="panel">
      <h2><?= htmlspecialchars((string) ($result['scenario']['name'] ?? 'Scenario'), ENT_QUOTES, 'UTF-8') ?></h2>
      <p><strong>Minimum size:</strong> <?= htmlspecialchars((string) ($result['scenario']['minimum_size_m2'] ?? '-'), ENT_QUOTES, 'UTF-8') ?> m²</p>
      <p>API endpoint: <code>/api/shortlist.php?scenario_id=<?= urlencode((string) $scenarioId) ?>&amp;top_n=<?= urlencode((string) $topN) ?></code></p>
      <table>
        <thead>
          <tr>
            <th>Parcel</th>
            <th>Location</th>
            <th>Final Score</th>
            <th>Eligibility</th>
            <th>Explanation</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (($result['shortlist'] ?? []) as $row): ?>
            <tr>
              <td><?= htmlspecialchars((string) ($row['parcel']['parcel_id'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars((string) ($row['parcel']['location'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
              <td class="score"><?= htmlspecialchars((string) ($row['final_score'] ?? 0), ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <?php if (($row['eligible'] ?? false) === true): ?>
                  <span class="tag ok">Eligible</span>
                <?php else: ?>
                  <span class="tag bad">Not eligible</span>
                  <div><?= htmlspecialchars(implode('; ', $row['eligibility_failures'] ?? []), ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars(implode('; ', $row['explanation'] ?? []), ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
