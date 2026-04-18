<?php

declare(strict_types=1);

require_once __DIR__ . '/app/JsonDataStore.php';
require_once __DIR__ . '/app/SuitabilityEngine.php';

$scenarioId = $_GET['scenario_id'] ?? 'transitional_30';
$topN = isset($_GET['top_n']) ? max(1, (int) $_GET['top_n']) : 10;
$error = null;
$result = null;
$scenarios = [];

$parcelGeometry = [
    'FRE-001' => [
        [-32.0317, 115.7442],
        [-32.0317, 115.7479],
        [-32.0292, 115.7479],
        [-32.0292, 115.7442],
    ],
    'FRE-002' => [
        [-32.0582, 115.7556],
        [-32.0582, 115.7585],
        [-32.0557, 115.7585],
        [-32.0557, 115.7556],
    ],
    'FRE-003' => [
        [-32.0828, 115.7841],
        [-32.0828, 115.7882],
        [-32.0799, 115.7882],
        [-32.0799, 115.7841],
    ],
    'FRE-004' => [
        [-32.0702, 115.7712],
        [-32.0702, 115.7752],
        [-32.0674, 115.7752],
        [-32.0674, 115.7712],
    ],
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

    $notes[] = (($row['eligible'] ?? false) === true)
        ? 'Included: passes mandatory scenario eligibility checks.'
        : 'Excluded: fails one or more mandatory eligibility checks.';

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
        $notes[] = sprintf('Included by constraints: %d/%d allowed.', $constraintCount, $maxConstraintCount);
    } else {
        $notes[] = sprintf('Excluded by constraints: %d exceeds maximum %d.', $constraintCount, $maxConstraintCount);
    }

    $serviceReadiness = (float) ($parcel['service_readiness_score'] ?? 0.0);
    $notes[] = $serviceReadiness >= 0.75
        ? 'Included in Service Readiness layer (>= 0.75).'
        : 'Excluded from Service Readiness layer (< 0.75).';

    $policy = (float) ($parcel['policy_alignment_score'] ?? 0.0);
    $notes[] = $policy >= 0.75
        ? 'Included in Policy Fit layer (>= 0.75).'
        : 'Excluded from Policy Fit layer (< 0.75).';

    return $notes;
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>LSIP - Land Suitability Intelligence Platform</title>
  <link
    rel="stylesheet"
    href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
    crossorigin=""
  >
  <style>
    body { font-family: Inter, Arial, sans-serif; margin: 0; background: #080b10; color: #d8dde7; }
    .container { max-width: 1250px; margin: 20px auto; padding: 0 16px 24px; }
    .panel { background: #0f141d; border: 1px solid #1e2734; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,.35); padding: 16px; margin-bottom: 16px; }
    h1, h2, h3 { margin-top: 0; color: #f2f5fb; }
    p { color: #9ca7b6; }
    code { color: #8ac8ff; }

    form { display: flex; gap: 12px; flex-wrap: wrap; align-items: end; }
    label { display: flex; flex-direction: column; font-size: 13px; gap: 6px; color: #b6c1d2; }
    select, input, button {
      padding: 10px;
      border: 1px solid #2a3647;
      border-radius: 8px;
      font-size: 14px;
      background: #0a1119;
      color: #e8edf6;
    }
    button { background: #133e67; border-color: #19507f; cursor: pointer; }

    .map-layout { display: grid; grid-template-columns: 280px 1fr; gap: 14px; }
    .left-controls {
      background: #0a1018;
      border: 1px solid #1d2734;
      border-radius: 10px;
      padding: 12px;
      display: grid;
      gap: 12px;
      align-content: start;
    }
    .layer-option {
      display: flex;
      align-items: flex-start;
      gap: 8px;
      padding: 8px;
      border: 1px solid #1e2a3a;
      border-radius: 8px;
      background: #0d1621;
    }
    .layer-option small { color: #8393a8; display: block; margin-top: 2px; }

    #map {
      min-height: 560px;
      border: 1px solid #1b2430;
      border-radius: 10px;
      overflow: hidden;
      background: #090d13;
    }

    .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .details-card {
      border: 1px solid #1c2735;
      border-radius: 10px;
      background: #0a1018;
      padding: 12px;
    }
    .characteristics { margin: 0; padding-left: 18px; display: grid; gap: 6px; color: #b5c0d1; }

    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 10px; border-bottom: 1px solid #1c2634; text-align: left; vertical-align: top; }
    .score { font-weight: bold; color: #8fd5ff; }
    .tag { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; }
    .ok { background: rgba(87, 222, 143, .2); color: #66e6a1; border: 1px solid rgba(102,230,161,.35); }
    .bad { background: rgba(255, 116, 116, .2); color: #ff8f8f; border: 1px solid rgba(255,143,143,.35); }
    .error { color: #ff8f8f; font-weight: 600; }

    .leaflet-popup-content-wrapper,
    .leaflet-popup-tip {
      background: #0d1520;
      color: #dce3f2;
      border: 1px solid #243143;
    }

    @media (max-width: 980px) {
      .map-layout { grid-template-columns: 1fr; }
      .meta-grid { grid-template-columns: 1fr; }
      #map { min-height: 460px; }
    }
  </style>
</head>
<body>
<div class="container">
  <div class="panel">
    <h1>LSIP Suitability Dashboard</h1>
    <p>Live OpenStreetMap-based GIS view with dark tactical styling, scenario filters, and layer toggles for parcel standards.</p>
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
          $parcel = is_array($row['parcel'] ?? null) ? $row['parcel'] : [];
          $parcelId = (string) ($parcel['parcel_id'] ?? '');
          if ($parcelId === '' || !isset($parcelGeometry[$parcelId])) {
              continue;
          }

          $constraints = is_array($parcel['constraints'] ?? null) ? $parcel['constraints'] : [];
          $mapRows[] = [
              'parcel_id' => $parcelId,
              'location' => (string) ($parcel['location'] ?? '-'),
              'coordinates' => $parcelGeometry[$parcelId],
              'eligible' => (($row['eligible'] ?? false) === true),
              'shortlist_standard' => isset($shortlistIds[$parcelId]),
              'service_standard' => ((float) ($parcel['service_readiness_score'] ?? 0.0)) >= 0.75,
              'policy_standard' => ((float) ($parcel['policy_alignment_score'] ?? 0.0)) >= 0.75,
              'constraints_present' => count($constraints) > 0,
              'constraints' => $constraints,
              'distance_to_residential_edge_m' => (float) ($parcel['distance_to_residential_edge_m'] ?? 0),
              'final_score' => (float) ($row['final_score'] ?? 0),
              'rule_notes' => buildRuleNotes($row, $result['scenario'] ?? []),
          ];
      }
    ?>

    <div class="panel">
      <h2>Interactive cadastral map</h2>
      <p>Use the left layer toggles to show or hide scenario overlays. Click any parcel polygon on the map to inspect rule-in/rule-out characteristics.</p>
      <div class="map-layout">
        <div class="left-controls">
          <h3>Layers</h3>
          <label class="layer-option">
            <input type="checkbox" class="layer-toggle" data-layer="eligible" checked>
            <span>Eligible Parcels<small>Bright cyan polygons for parcels passing eligibility checks.</small></span>
          </label>
          <label class="layer-option">
            <input type="checkbox" class="layer-toggle" data-layer="shortlist" checked>
            <span>Top-N Shortlist<small>Gold outlines highlight parcels included in current shortlist.</small></span>
          </label>
          <label class="layer-option">
            <input type="checkbox" class="layer-toggle" data-layer="service" checked>
            <span>Service Readiness<small>Blue markers for parcels with service readiness ≥ 0.75.</small></span>
          </label>
          <label class="layer-option">
            <input type="checkbox" class="layer-toggle" data-layer="policy" checked>
            <span>Policy Fit<small>Purple markers for parcels with policy alignment ≥ 0.75.</small></span>
          </label>
          <label class="layer-option">
            <input type="checkbox" class="layer-toggle" data-layer="constraints" checked>
            <span>Constraint Flags<small>Red markers for parcels with one or more constraints.</small></span>
          </label>
          <label class="layer-option">
            <input type="checkbox" class="layer-toggle" data-layer="edge" checked>
            <span>Residential Edge Sensitivity<small>Orange rings indicate political sensitivity radius bands.</small></span>
          </label>
        </div>
        <div id="map" aria-label="Land parcel suitability map"></div>
      </div>

      <div class="meta-grid" style="margin-top: 14px;">
        <div class="details-card">
          <h3>Selected parcel characteristics</h3>
          <p id="selected-summary">Click a parcel on the map to inspect details.</p>
          <ul id="selected-characteristics" class="characteristics"></ul>
        </div>
        <div class="details-card">
          <h3>Scenario Criteria (Rule-in / Rule-out)</h3>
          <ul class="characteristics">
            <li>Parcel must meet scenario minimum size and constraint limits.</li>
            <li>Eligible layer shows only parcels that pass mandatory checks.</li>
            <li>Shortlist layer highlights top ranked parcels after scoring and risk penalty.</li>
            <li>Service Readiness and Policy Fit layers are optional standards overlays.</li>
            <li>Constraint and Residential Edge layers show caution factors for planning.</li>
          </ul>
        </div>
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

    <script id="map-data" type="application/json"><?= htmlspecialchars((string) json_encode($mapRows, JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8') ?></script>
    <script
      src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
      integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
      crossorigin=""
    ></script>
    <script>
      (() => {
        const dataElement = document.getElementById('map-data');
        const rows = dataElement ? JSON.parse(dataElement.textContent || '[]') : [];
        const map = L.map('map', { zoomControl: true }).setView([-32.061, 115.755], 13);

        L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
          attribution: '&copy; OpenStreetMap &copy; CARTO',
          subdomains: 'abcd',
          maxZoom: 20,
        }).addTo(map);

        const selectedSummary = document.getElementById('selected-summary');
        const selectedCharacteristics = document.getElementById('selected-characteristics');
        const toggles = Array.from(document.querySelectorAll('.layer-toggle'));

        const layers = {
          parcels: L.layerGroup().addTo(map),
          eligible: L.layerGroup().addTo(map),
          shortlist: L.layerGroup().addTo(map),
          service: L.layerGroup().addTo(map),
          policy: L.layerGroup().addTo(map),
          constraints: L.layerGroup().addTo(map),
          edge: L.layerGroup().addTo(map),
        };

        const centroid = (coordinates) => {
          const total = coordinates.reduce((acc, pair) => {
            acc.lat += pair[0];
            acc.lng += pair[1];
            return acc;
          }, { lat: 0, lng: 0 });

          return [total.lat / coordinates.length, total.lng / coordinates.length];
        };

        const edgeRadius = (distanceToEdge) => {
          if (distanceToEdge < 30) return 230;
          if (distanceToEdge < 80) return 170;
          return 110;
        };

        const renderDetails = (parcel) => {
          selectedSummary.textContent = `${parcel.parcel_id} · ${parcel.location} · Final score ${parcel.final_score}`;
          selectedCharacteristics.innerHTML = '';
          (parcel.rule_notes || []).forEach((note) => {
            const item = document.createElement('li');
            item.textContent = note;
            selectedCharacteristics.appendChild(item);
          });
        };

        rows.forEach((parcel) => {
          const polygon = L.polygon(parcel.coordinates, {
            color: '#3a4658',
            weight: 1,
            fillColor: '#121a26',
            fillOpacity: 0.35,
          })
            .bindTooltip(parcel.parcel_id, { direction: 'center', permanent: false, opacity: 0.7 })
            .on('click', () => renderDetails(parcel));
          polygon.addTo(layers.parcels);

          if (parcel.eligible) {
            L.polygon(parcel.coordinates, {
              color: '#4fc3f7',
              weight: 2,
              fillColor: '#1b3f58',
              fillOpacity: 0.28,
            }).addTo(layers.eligible);
          }

          if (parcel.shortlist_standard) {
            L.polygon(parcel.coordinates, {
              color: '#ffd166',
              weight: 3,
              fillOpacity: 0,
              dashArray: '6 4',
            }).addTo(layers.shortlist);
          }

          const center = centroid(parcel.coordinates);
          if (parcel.service_standard) {
            L.circleMarker(center, {
              radius: 8,
              color: '#4fc3f7',
              fillColor: '#4fc3f7',
              fillOpacity: 0.6,
              weight: 1.5,
            }).bindTooltip(`${parcel.parcel_id}: service-ready`).addTo(layers.service);
          }

          if (parcel.policy_standard) {
            L.circleMarker(center, {
              radius: 6,
              color: '#bb86fc',
              fillColor: '#bb86fc',
              fillOpacity: 0.65,
              weight: 1.5,
            }).bindTooltip(`${parcel.parcel_id}: policy-fit`).addTo(layers.policy);
          }

          if (parcel.constraints_present) {
            L.circleMarker(center, {
              radius: 5,
              color: '#ff6b6b',
              fillColor: '#ff6b6b',
              fillOpacity: 0.75,
              weight: 1,
            }).bindTooltip(`${parcel.parcel_id}: constraints ${parcel.constraints.join(', ')}`).addTo(layers.constraints);
          }

          L.circle(center, {
            radius: edgeRadius(parcel.distance_to_residential_edge_m),
            color: '#ff9f43',
            fillOpacity: 0,
            weight: 1,
            dashArray: '3 6',
          }).bindTooltip(`${parcel.parcel_id}: edge ${parcel.distance_to_residential_edge_m}m`).addTo(layers.edge);
        });

        const toggleMap = {
          eligible: layers.eligible,
          shortlist: layers.shortlist,
          service: layers.service,
          policy: layers.policy,
          constraints: layers.constraints,
          edge: layers.edge,
        };

        toggles.forEach((toggle) => {
          toggle.addEventListener('change', () => {
            const layerName = toggle.dataset.layer;
            const layer = toggleMap[layerName];
            if (!layer) return;
            if (toggle.checked) {
              map.addLayer(layer);
            } else {
              map.removeLayer(layer);
            }
          });
        });

        if (rows.length > 0) {
          renderDetails(rows[0]);
          map.fitBounds(L.featureGroup([layers.parcels]).getBounds().pad(0.25));
        }
      })();
    </script>
  <?php endif; ?>
</div>
</body>
</html>
