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
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
  <style>
    body { font-family: Inter, Roboto, "Segoe UI", Arial, sans-serif; margin: 0; background: #080c12; color: #d6dce8; }
    .container { max-width: 1200px; margin: 24px auto; padding: 0 16px 24px; }
    .panel { background: #0d131d; border: 1px solid #1c2533; border-radius: 12px; box-shadow: 0 12px 30px rgba(0,0,0,.35); padding: 16px; margin-bottom: 16px; }
    h1, h2, h3 { margin-top: 0; }
    form { display: flex; gap: 12px; flex-wrap: wrap; align-items: end; }
    label { display: flex; flex-direction: column; font-size: 14px; gap: 6px; }
    select, input, button { padding: 10px; border: 1px solid #2a3547; border-radius: 8px; font-size: 14px; background: #111a27; color: #d6dce8; }
    button { background: #1f9ad2; color: #eaf8ff; cursor: pointer; }
    .grid { display: grid; gap: 16px; }
    .visual-grid { grid-template-columns: 2fr 1fr; align-items: start; }
    .map-shell {
      position: relative;
      min-height: 460px;
      border: 1px solid #2a374c;
      border-radius: 12px;
      overflow: hidden;
      background: #090e15;
    }
    #leaflet-map { width: 100%; min-height: 460px; }
    .leaflet-container { background: #090e15; }
    .parcel-marker {
      width: 16px;
      height: 16px;
      border-radius: 999px;
      border: 2px solid #5e6d83;
      background: rgba(122, 136, 160, 0.88);
      box-shadow: 0 0 0 1px rgba(0,0,0,.35);
      cursor: pointer;
      transition: transform .15s ease, box-shadow .15s ease, opacity .15s ease;
    }
    .parcel-marker.standard-eligible { border-color: #5ce18a; }
    .parcel-marker.standard-service { border-color: #3eb9ff; }
    .parcel-marker.standard-policy { border-color: #d58bff; }
    .parcel-marker.standard-shortlist { border-color: #ffc957; }
    .parcel-marker.is-active { transform: scale(1.2); box-shadow: 0 0 0 2px rgba(124, 214, 255, 0.75); }
    .parcel-marker.dimmed { opacity: 0.28; }
    .map-caption {
      position: absolute;
      left: 12px;
      right: 12px;
      bottom: 12px;
      color: #b8c3d8;
      font-size: 12px;
      background: rgba(6, 10, 16, 0.72);
      border: 1px solid rgba(94, 109, 131, 0.55);
      border-radius: 8px;
      padding: 8px 10px;
      backdrop-filter: blur(2px);
      pointer-events: none;
      z-index: 400;
    }
    .controls { display: grid; gap: 8px; }
    .control-chip {
      display: flex;
      align-items: center;
      gap: 10px;
      border: 1px solid #2a3547;
      border-radius: 10px;
      padding: 8px 10px;
      background: #111a27;
    }
    .swatch { width: 14px; height: 14px; border-radius: 3px; border: 2px solid transparent; }
    .swatch-eligible { border-color: #5ce18a; background: rgba(92, 225, 138, 0.24); }
    .swatch-service { border-color: #3eb9ff; background: rgba(62, 185, 255, 0.24); }
    .swatch-policy { border-color: #d58bff; background: rgba(213, 139, 255, 0.24); }
    .swatch-shortlist { border-color: #ffc957; background: rgba(255, 201, 87, 0.24); }
    .characteristics { margin: 0; padding-left: 18px; display: grid; gap: 6px; }
    .characteristics li { line-height: 1.35; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 10px; border-bottom: 1px solid #202b3a; text-align: left; vertical-align: top; }
    .score { font-weight: bold; }
    .tag { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; }
    .ok { background: #153224; color: #8af0b2; }
    .bad { background: #3a1a1a; color: #ff9898; }
    .error { color: #ff9898; font-weight: 600; }
    code { color: #9ed7ff; }

    @media (max-width: 960px) {
      .visual-grid { grid-template-columns: 1fr; }
      .map-shell,
      #leaflet-map { min-height: 360px; }
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
          if ($parcelId === '') {
              continue;
          }

          $parcel = $row['parcel'] ?? [];
          $latitude = (float) ($parcel['latitude'] ?? 0.0);
          $longitude = (float) ($parcel['longitude'] ?? 0.0);
          if ($latitude === 0.0 && $longitude === 0.0) {
              continue;
          }

          $constraints = $parcel['constraints'] ?? [];
          $constraintCount = is_array($constraints) ? count($constraints) : 0;
          $serviceReadiness = (float) ($parcel['service_readiness_score'] ?? 0.0);
          $policyAlignment = (float) ($parcel['policy_alignment_score'] ?? 0.0);

          $mapRows[] = [
              'parcel_id' => $parcelId,
              'location' => (string) ($parcel['location'] ?? '-'),
              'latitude' => $latitude,
              'longitude' => $longitude,
              'final_score' => (float) ($row['final_score'] ?? 0.0),
              'eligible' => (($row['eligible'] ?? false) === true),
              'service_standard' => $serviceReadiness >= 0.75,
              'policy_standard' => $policyAlignment >= 0.75,
              'shortlist_standard' => isset($shortlistIds[$parcelId]),
              'constraint_count' => $constraintCount,
              'rule_notes' => buildRuleNotes($row, $result['scenario'] ?? []),
          ];
      }
    ?>

    <div class="panel grid visual-grid">
      <div>
        <h2>Cadastral suitability map</h2>
        <p>Interactive basemap loads from OpenStreetMap tiles and plots dataset parcels as map markers. Click a marker to view rule details.</p>
        <div class="map-shell" aria-label="Parcel suitability map">
          <div id="leaflet-map"></div>
          <div class="map-caption">Markers represent parcels in the selected scenario. Marker outlines indicate standards met; dimmed markers are filtered out.</div>
        </div>
      </div>
      <div class="controls">
        <h3>Standards</h3>
        <label class="control-chip"><input type="checkbox" class="standard-filter" value="eligible" checked><span class="swatch swatch-eligible"></span>Eligible parcels</label>
        <label class="control-chip"><input type="checkbox" class="standard-filter" value="service" checked><span class="swatch swatch-service"></span>Service readiness ≥ 0.75</label>
        <label class="control-chip"><input type="checkbox" class="standard-filter" value="policy" checked><span class="swatch swatch-policy"></span>Policy fit ≥ 0.75</label>
        <label class="control-chip"><input type="checkbox" class="standard-filter" value="shortlist" checked><span class="swatch swatch-shortlist"></span>Scenario shortlist (Top N)</label>

        <h3>Selected parcel characteristics</h3>
        <p id="selected-summary">Click a marker to view what rules it in or out.</p>
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

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
      (() => {
        const parcelRows = <?= json_encode($mapRows, JSON_THROW_ON_ERROR) ?>;
        const filters = Array.from(document.querySelectorAll('.standard-filter'));
        const selectedSummary = document.getElementById('selected-summary');
        const selectedCharacteristics = document.getElementById('selected-characteristics');
        const markers = [];

        const map = L.map('leaflet-map', { zoomControl: true });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          maxZoom: 19,
          attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        const standardMatch = (parcel, standard) => {
          if (standard === 'eligible') return Boolean(parcel.eligible);
          if (standard === 'service') return Boolean(parcel.service_standard);
          if (standard === 'policy') return Boolean(parcel.policy_standard);
          if (standard === 'shortlist') return Boolean(parcel.shortlist_standard);
          return true;
        };

        const refreshFilterState = () => {
          const activeStandards = filters.filter((input) => input.checked).map((input) => input.value);
          markers.forEach(({ parcel, element }) => {
            const visible = activeStandards.some((standard) => standardMatch(parcel, standard));
            element.classList.toggle('dimmed', !visible);
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

        const classNamesForParcel = (parcel) => {
          const classes = ['parcel-marker'];
          if (parcel.eligible) classes.push('standard-eligible');
          if (parcel.service_standard) classes.push('standard-service');
          if (parcel.policy_standard) classes.push('standard-policy');
          if (parcel.shortlist_standard) classes.push('standard-shortlist');
          return classes.join(' ');
        };

        parcelRows.forEach((parcel) => {
          const icon = L.divIcon({
            className: '',
            html: `<button type="button" class="${classNamesForParcel(parcel)}" aria-label="${parcel.parcel_id} in ${parcel.location}"></button>`,
            iconSize: [16, 16],
            iconAnchor: [8, 8]
          });
          const marker = L.marker([parcel.latitude, parcel.longitude], { icon }).addTo(map);
          marker.bindTooltip(parcel.parcel_id, { direction: 'top', offset: [0, -8] });

          const markerElement = marker.getElement();
          if (!markerElement) {
            return;
          }

          const element = markerElement.querySelector('.parcel-marker');
          if (!element) {
            return;
          }

          element.addEventListener('click', () => {
            markers.forEach((candidate) => candidate.element.classList.remove('is-active'));
            element.classList.add('is-active');
            renderParcelDetails(parcel);
          });
          markers.push({ parcel, element });
        });

        const bounds = L.latLngBounds(parcelRows.map((parcel) => [parcel.latitude, parcel.longitude]));
        if (bounds.isValid()) {
          map.fitBounds(bounds.pad(0.25));
        } else {
          map.setView([-32.055, 115.768], 12);
        }

        filters.forEach((filterInput) => {
          filterInput.addEventListener('change', refreshFilterState);
        });

        refreshFilterState();
        if (markers.length > 0) {
          markers[0].element.click();
        }
      })();
    </script>
  <?php endif; ?>
</div>
</body>
</html>
