<?php
// index.php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/vendor/autoload.php';

use Legenda\NormalSurf\Templates\Report;

// View-models
$report = new Report();
$vmCC    = $report->currentConditionsView();              
$vmNow   = $report->whereToSurfNowView();                 
$vmLater = $report->whereToSurfLaterTodayView();         
$vmTom   = $report->whereToSurfTomorrowView();  
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <title>Hazard Surf (Beta)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    :root {
      color-scheme: light dark;
    }

    body {
      font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      margin: 1.25rem;
      line-height: 1.45;
    }

    h2,
    h3 {
      margin: 1.25rem 0 0.5rem;
    }

    table {
      border-collapse: collapse;
      width: 100%;
      margin: 0.75rem 0 1.25rem;
    }

    th,
    td {
      border: 1px solid #7774;
      padding: 8px 10px;
      vertical-align: top;
    }

    th {
      text-align: left;
      background: #eee4;
    }

    em.note {
      color: #666;
      font-style: italic;
    }

    details {
      margin: 0.75rem 0;
    }
  </style>
</head>

<body>

  <section aria-labelledby="ns-heading">
    <h2 id="ns-heading">Hazard Surf (Beta)</h2>

    <!-- ——————————————————————————————————
       Current Conditions
       —————————————————————————————————— -->
    <h3>Current Conditions (<?= htmlspecialchars($vmCC['now_local'] ?? '') ?>)</h3>
    <table>
      <thead>
        <tr>
          <th>Region</th>
          <th>Wave</th>
          <th>Current Tide At <?= htmlspecialchars($vmCC['current_hm_label'] ?? '') ?></th>
          <th>Next Tide In <?= htmlspecialchars($vmCC['next_tide_in_label'] ?? '') ?></th>
          <th>Wind</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($vmCC['rows'])): ?>
          <?php foreach ($vmCC['rows'] as $row): ?>
            <tr>
              <td><?= htmlspecialchars($row['label'] ?? '') ?></td>
              <td><?= $row['wave_cell'] ?? '&mdash;' ?></td>
              <td><?= htmlspecialchars($row['tide_label_current'] ?? '—') ?></td>
              <td><?= htmlspecialchars($row['tide_next_at'] ?? '—') ?></td>
              <td><?= htmlspecialchars($row['wind_label'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="5"><em>No current buoy data available.</em></td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>

    <!-- ——————————————————————————————————
       Where To Surf Now
       —————————————————————————————————— -->
    <h3>Where To Surf Now (<?= htmlspecialchars($vmNow['header_hm'] ?? '') ?>)</h3>
    <table>
      <thead>
        <tr>
          <th>Name</th>
          <th>Wave</th>
          <th>Tide</th>
          <th>Wind</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($vmNow['best'])): ?>
          <?php foreach ($vmNow['best'] as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['name']) ?></td>
              <td><?= $r['wave_cell'] ?></td>
              <td><?= htmlspecialchars($r['tide']) ?></td>
              <td><?= htmlspecialchars($r['wind']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="4"><em><?= htmlspecialchars($vmNow['message'] ?? 'Conditions are less than optimal at this time.') ?></em></td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>

    <?php if (!empty($vmNow['others'])): ?>
      <details>
        <summary><strong>Other Possible Locations</strong></summary>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Wave</th>
              <th>Tide</th>
              <th>Wind</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($vmNow['others'] as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['name']) ?></td>
                <td><?= $r['wave_cell'] ?></td>
                <td><?= htmlspecialchars($r['tide']) ?></td>
                <td><?= htmlspecialchars($r['wind']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </details>
    <?php endif; ?>

  </section>
  <section aria-labelledby="later-heading">
    <h2 id="later-heading">Where To Surf Later (<?= htmlspecialchars($vmLater['header_date']) ?>)</h2>
    <table class="table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Wave</th>
          <th>Tide</th>
          <th>Wind</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($vmLater['rows'])): foreach ($vmLater['rows'] as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['spot_name']) ?></td>
              <td><?= $r['wave_cell'] ?></td>
              <td><?= htmlspecialchars($r['tide']) ?></td>
              <td><?= htmlspecialchars($r['wind']) ?></td>
            </tr>
          <?php endforeach;
        else: ?>
          <tr>
            <td colspan="4"><em>Conditions are less than optimal at this time.</em></td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </section>

  <section aria-labelledby="tomorrow-heading">
    <h2 id="tomorrow-heading">Where To Surf Tomorrow (<?= htmlspecialchars($vmTom['header_date']) ?>)</h2>
    <table class="table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Wave</th>
          <th>Tide</th>
          <th>Wind</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($vmTom['rows'])): foreach ($vmTom['rows'] as $r): ?>
            <tr>
              <td><?= htmlspecialchars($r['spot_name']) ?></td>
              <td><?= $r['wave_cell'] ?></td>
              <td><?= htmlspecialchars($r['tide']) ?></td>
              <td><?= htmlspecialchars($r['wind']) ?></td>
            </tr>
          <?php endforeach;
        else: ?>
          <tr>
            <td colspan="4"><em>No preferred tide windows tomorrow.</em></td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </section>
</body>

</html>