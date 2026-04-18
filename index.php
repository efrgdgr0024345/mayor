<?php

declare(strict_types=1);

require_once __DIR__ . '/app/JsonDataStore.php';
require_once __DIR__ . '/app/SuitabilityEngine.php';

$scenarioId = $_GET['scenario_id'] ?? 'transitional_30';
$topN = isset($_GET['top_n']) ? max(1, (int) $_GET['top_n']) : 10;
$error = null;
$result = null;
$scenarios = [];

$parcelMapLayout = [
    'FRE-001' => ['x' => 32, 'y' => 25, 'width' => 12, 'height' => 10],
    'FRE-002' => ['x' => 44, 'y' => 37, 'width' => 10, 'height' => 9],
    'FRE-003' => ['x' => 58, 'y' => 68, 'width' => 14, 'height' => 11],
    'FRE-004' => ['x' => 52, 'y' => 54, 'width' => 11, 'height' => 10],
];

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

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed> $scenario
 * @return array<int, string>
 */
function buildRuleNotes(array $row, array $scenario): array
{
    $notes = [];
    $parcel = $row['parcel'] ?? [];

    $notes[] = (($row['eligible'] ?? false) === true) ? 'Included: clears all scenario eligibility checks.' : 'Excluded: fails one or more mandatory eligibility checks.';

    $size = (float) ($parcel['size_m2'] ?? 0);
    $minimum = (float) ($scenario['minimum_size_m2'] ?? 0);
    if ($size >= $minimum) {
        $notes[] = sprintf('Included by lot size: %.0f m² meets minimum %.0f m².', $size, $minimum);
    } else {
        $notes[] = sprintf('Excluded by lot size: %.0f m² is below minimum %.0f m².', $size, $minimum);
    }

    $constraints = $parcel['constraints'] ?? [];
    $constraintCount = is_array($constraints) ? count($constraints) : 0;
    $maxConstraintCount = (int) ($scenario['max_constraint_count'] ?? PHP_INT_MAX);
    if ($constraintCount <= $maxConstraintCount) {
        $notes[] = sprintf('Included on constraints: %d/%d allowed constraints.', $constraintCount, $maxConstraintCount);
    } else {
        $notes[] = sprintf('Excluded on constraints: %d constraints exceed limit %d.', $constraintCount, $maxConstraintCount);
    }

    $serviceReadiness = (float) ($parcel['service_readiness_score'] ?? 0.0);
    $notes[] = $serviceReadiness >= 0.75
        ? 'Included by service readiness standard (>=0.75).'
        : 'Not in high-service-readiness standard (<0.75).';

    $policy = (float) ($parcel['policy_alignment_score'] ?? 0.0);
    $notes[] = $policy >= 0.75
        ? 'Included by policy-fit standard (>=0.75).'
        : 'Not in policy-fit standard (<0.75).';

    return $notes;
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>LSIP - Land Suitability Intelligence Platform</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 0; background: #f6f7fb; color: #1d2330; }
    .container { max-width: 1200px; margin: 24px auto; padding: 0 16px 24px; }
    .panel { background: #fff; border-radius: 12px; box-shadow: 0 8px 20px rgba(0,0,0,.06); padding: 16px; margin-bottom: 16px; }
    h1, h2, h3 { margin-top: 0; }
    form { display: flex; gap: 12px; flex-wrap: wrap; align-items: end; }
    label { display: flex; flex-direction: column; font-size: 14px; gap: 6px; }
    select, input, button { padding: 10px; border: 1px solid #cfd4e3; border-radius: 8px; font-size: 14px; }
    button { background: #2f6fed; color: #fff; cursor: pointer; }
    .grid { display: grid; gap: 16px; }
    .visual-grid { grid-template-columns: 2fr 1fr; align-items: start; }
    .map-shell {
      position: relative;
      min-height: 460px;
      border: 1px solid #1f2738;
      border-radius: 12px;
      overflow: hidden;
      background: radial-gradient(circle at 50% 20%, #182233, #0b1018 72%);
    }
    .map-background {
      position: absolute;
      inset: 0;
      background-position: center;
      background-size: cover;
      opacity: 0.42;
      filter: contrast(1.1) saturate(0.35) brightness(0.9);
    }
    .map-overlay {
      position: absolute;
      inset: 0;
      background: linear-gradient(180deg, rgba(5, 9, 16, 0.2), rgba(5, 9, 16, 0.5));
      pointer-events: none;
    }
    .parcel-block {
      position: absolute;
      border: 2px solid #8f97a9;
      background: rgba(130, 138, 158, 0.12);
      color: #fff;
      border-radius: 4px;
      cursor: pointer;
      transition: transform .15s ease, box-shadow .15s ease, opacity .15s ease;
      box-shadow: inset 0 0 0 1px rgba(0,0,0,.3);
    }
    .parcel-block:hover,
    .parcel-block.is-active {
      transform: translateY(-1px);
      box-shadow: 0 0 0 2px rgba(255,255,255,.22), 0 8px 20px rgba(0,0,0,.35);
    }
    .parcel-label {
      position: absolute;
      left: 6px;
      top: 4px;
      font-size: 12px;
      font-weight: 700;
      letter-spacing: .02em;
      text-shadow: 0 1px 2px rgba(0,0,0,.7);
    }
    .parcel-block.dimmed { opacity: .28; }
    .parcel-block.standard-eligible { border-color: #5ce18a; background: rgba(92, 225, 138, 0.24); }
    .parcel-block.standard-service { border-color: #3eb9ff; background: rgba(62, 185, 255, 0.24); }
    .parcel-block.standard-policy { border-color: #d58bff; background: rgba(213, 139, 255, 0.24); }
    .parcel-block.standard-shortlist { border-color: #ffc957; background: rgba(255, 201, 87, 0.24); }
    .map-caption {
      position: absolute;
      left: 12px;
      right: 12px;
      bottom: 12px;
      color: #cfd9ec;
      font-size: 12px;
      background: rgba(6, 10, 16, 0.65);
      border: 1px solid rgba(255,255,255,.1);
      border-radius: 8px;
      padding: 8px 10px;
      backdrop-filter: blur(2px);
    }
    .controls { display: grid; gap: 8px; }
    .control-chip {
      display: flex;
      align-items: center;
      gap: 10px;
      border: 1px solid #dce2ef;
      border-radius: 10px;
      padding: 8px 10px;
      background: #fbfcff;
    }
    .swatch { width: 14px; height: 14px; border-radius: 3px; border: 2px solid transparent; }
    .swatch-eligible { border-color: #5ce18a; background: rgba(92, 225, 138, 0.24); }
    .swatch-service { border-color: #3eb9ff; background: rgba(62, 185, 255, 0.24); }
    .swatch-policy { border-color: #d58bff; background: rgba(213, 139, 255, 0.24); }
    .swatch-shortlist { border-color: #ffc957; background: rgba(255, 201, 87, 0.24); }
    .characteristics { margin: 0; padding-left: 18px; display: grid; gap: 6px; }
    .characteristics li { line-height: 1.35; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 10px; border-bottom: 1px solid #ebedf4; text-align: left; vertical-align: top; }
    .score { font-weight: bold; }
    .tag { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; }
    .ok { background: #e9f9ef; color: #1c7d3a; }
    .bad { background: #ffecec; color: #ba2d2d; }
    .error { color: #ba2d2d; font-weight: 600; }

    @media (max-width: 960px) {
      .visual-grid { grid-template-columns: 1fr; }
      .map-shell { min-height: 360px; }
    }
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
    <?php
      $allRows = is_array($result['all_results'] ?? null) ? $result['all_results'] : [];
      $shortlistRows = is_array($result['shortlist'] ?? null) ? $result['shortlist'] : [];
      $shortlistIds = [];
      foreach ($shortlistRows as $shortlisted) {
          $shortlistIds[(string) (($shortlisted['parcel']['parcel_id'] ?? ''))] = true;
      }

      $mapRows = [];
      foreach ($allRows as $row) {
          $parcelId = (string) (($row['parcel']['parcel_id'] ?? ''));
          if ($parcelId === '' || !isset($parcelMapLayout[$parcelId])) {
              continue;
          }

          $parcel = $row['parcel'] ?? [];
          $constraints = $parcel['constraints'] ?? [];
          $constraintCount = is_array($constraints) ? count($constraints) : 0;
          $serviceReadiness = (float) ($parcel['service_readiness_score'] ?? 0.0);
          $policyAlignment = (float) ($parcel['policy_alignment_score'] ?? 0.0);

          $mapRows[] = [
              'parcel_id' => $parcelId,
              'location' => (string) ($parcel['location'] ?? '-'),
              'final_score' => (float) ($row['final_score'] ?? 0.0),
              'eligible' => (($row['eligible'] ?? false) === true),
              'service_standard' => $serviceReadiness >= 0.75,
              'policy_standard' => $policyAlignment >= 0.75,
              'shortlist_standard' => isset($shortlistIds[$parcelId]),
              'constraint_count' => $constraintCount,
              'rule_notes' => buildRuleNotes($row, $result['scenario'] ?? []),
          ] + $parcelMapLayout[$parcelId];
      }
    ?>

    <div class="panel grid visual-grid">
      <div>
        <h2>Cadastral suitability map</h2>
        <p>Attach a basemap image, then highlight parcel blocks by scenario standards. Click a parcel block for characteristics that rule it in/out.</p>
        <label>
          Basemap image URL (optional)
          <input id="basemap-url" type="url" placeholder="https://.../map-image.png" autocomplete="off">
        </label>
        <div class="map-shell" id="map-shell" aria-label="Parcel suitability map">
          <div class="map-background" id="map-background"></div>
          <?php foreach ($mapRows as $mapRow): ?>
            <?php
                $classNames = ['parcel-block'];
                if ($mapRow['eligible']) {
                    $classNames[] = 'standard-eligible';
                }
                if ($mapRow['service_standard']) {
                    $classNames[] = 'standard-service';
                }
                if ($mapRow['policy_standard']) {
                    $classNames[] = 'standard-policy';
                }
                if ($mapRow['shortlist_standard']) {
                    $classNames[] = 'standard-shortlist';
                }
            ?>
            <button
              type="button"
              class="<?= htmlspecialchars(implode(' ', $classNames), ENT_QUOTES, 'UTF-8') ?>"
              style="left: <?= (float) $mapRow['x'] ?>%; top: <?= (float) $mapRow['y'] ?>%; width: <?= (float) $mapRow['width'] ?>%; height: <?= (float) $mapRow['height'] ?>%;"
              data-parcel='<?= htmlspecialchars((string) json_encode($mapRow, JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8') ?>'
              aria-label="<?= htmlspecialchars((string) ($mapRow['parcel_id'] . ' in ' . $mapRow['location']), ENT_QUOTES, 'UTF-8') ?>"
            >
              <span class="parcel-label"><?= htmlspecialchars((string) $mapRow['parcel_id'], ENT_QUOTES, 'UTF-8') ?></span>
            </button>
          <?php endforeach; ?>
          <div class="map-overlay" aria-hidden="true"></div>
          <div class="map-caption">Blocks represent cadastral parcels in the scenario. Color edges indicate standards met; dimmed blocks are filtered out.</div>
        </div>
      </div>
      <div class="controls">
        <h3>Standards</h3>
        <label class="control-chip"><input type="checkbox" class="standard-filter" value="eligible" checked><span class="swatch swatch-eligible"></span>Eligible parcels</label>
        <label class="control-chip"><input type="checkbox" class="standard-filter" value="service" checked><span class="swatch swatch-service"></span>Service readiness ≥ 0.75</label>
        <label class="control-chip"><input type="checkbox" class="standard-filter" value="policy" checked><span class="swatch swatch-policy"></span>Policy fit ≥ 0.75</label>
        <label class="control-chip"><input type="checkbox" class="standard-filter" value="shortlist" checked><span class="swatch swatch-shortlist"></span>Scenario shortlist (Top N)</label>

        <h3>Selected parcel characteristics</h3>
        <p id="selected-summary">Click a parcel block to view what rules it in or out.</p>
        <ul id="selected-characteristics" class="characteristics"></ul>
      </div>
    </div>

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
          <?php foreach ($shortlistRows as $row): ?>
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

    <script>
      (() => {
        const mapBackground = document.getElementById('map-background');
        const basemapUrlInput = document.getElementById('basemap-url');
        const blocks = Array.from(document.querySelectorAll('.parcel-block'));
        const filters = Array.from(document.querySelectorAll('.standard-filter'));
        const selectedSummary = document.getElementById('selected-summary');
        const selectedCharacteristics = document.getElementById('selected-characteristics');

        const defaultMapImage = 'https://tile.openstreetmap.org/13/14417/10211.png';
        mapBackground.style.backgroundImage = `url(${defaultMapImage})`;

        basemapUrlInput.addEventListener('change', () => {
          const value = basemapUrlInput.value.trim();
          mapBackground.style.backgroundImage = value === '' ? `url(${defaultMapImage})` : `url(${value})`;
        });

        const parseParcelData = (block) => {
          const payload = block.getAttribute('data-parcel') || '{}';
          try {
            return JSON.parse(payload);
          } catch (error) {
            return {};
          }
        };

        const standardMatch = (parcel, standard) => {
          if (standard === 'eligible') return Boolean(parcel.eligible);
          if (standard === 'service') return Boolean(parcel.service_standard);
          if (standard === 'policy') return Boolean(parcel.policy_standard);
          if (standard === 'shortlist') return Boolean(parcel.shortlist_standard);
          return true;
        };

        const refreshFilterState = () => {
          const activeStandards = filters.filter((input) => input.checked).map((input) => input.value);
          blocks.forEach((block) => {
            const parcel = parseParcelData(block);
            const visible = activeStandards.some((standard) => standardMatch(parcel, standard));
            block.classList.toggle('dimmed', !visible);
          });
        };

        const renderParcelDetails = (parcel) => {
          selectedSummary.textContent = `${parcel.parcel_id} · ${parcel.location} · Final score ${parcel.final_score}`;
          selectedCharacteristics.innerHTML = '';
          (parcel.rule_notes || []).forEach((note) => {
            const item = document.createElement('li');
            item.textContent = note;
            selectedCharacteristics.appendChild(item);
          });
        };

        blocks.forEach((block) => {
          block.addEventListener('click', () => {
            blocks.forEach((candidate) => candidate.classList.remove('is-active'));
            block.classList.add('is-active');
            const parcel = parseParcelData(block);
            renderParcelDetails(parcel);
          });
        });

        filters.forEach((filterInput) => {
          filterInput.addEventListener('change', refreshFilterState);
        });

        refreshFilterState();
        if (blocks.length > 0) {
          blocks[0].click();
        }
      })();
    </script>
  <?php endif; ?>
</div>
</body>
</html>
