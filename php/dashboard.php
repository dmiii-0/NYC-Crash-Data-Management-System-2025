<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/conn/conn.php';

$user_name  = $_SESSION["user_name"] ?? "Developer";
$user_email = $_SESSION["user_email"] ?? "you@devmail.com";

$db  = new Database();
$pdo = $db->getConn();

// Optional: show total imported rows for info in the card
$import_rows = 0;
if ($pdo) {
    try {
        $statusCheck = $pdo->query("SHOW TABLES LIKE 'nyc_import_status'");
        if ($statusCheck && $statusCheck->rowCount() > 0) {
            $st = $pdo->query("SELECT imported_rows FROM nyc_import_status WHERE id = 1");
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $import_rows = (int)$row['imported_rows'];
            }
        } else {
            $cnt = $pdo->query("SELECT COUNT(*) AS c FROM nyc_crashes_2025");
            $cRow = $cnt->fetch(PDO::FETCH_ASSOC);
            $import_rows = (int)($cRow['c'] ?? 0);
        }
    } catch (Exception $e) {
        $import_rows = 0;
    }
}

// ----------- READ FILTER INPUTS -----------
$filter_date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$filter_date_to   = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : '';
$filter_borough   = isset($_GET['borough'])   ? trim($_GET['borough'])   : '';
$filter_street    = isset($_GET['street_on']) ? trim($_GET['street_on']) : '';

$filter_v1 = isset($_GET['vehicle_type_code1']) ? trim($_GET['vehicle_type_code1']) : '';
$filter_v2 = isset($_GET['vehicle_type_code2']) ? trim($_GET['vehicle_type_code2']) : '';
$filter_v3 = isset($_GET['vehicle_type_code3']) ? trim($_GET['vehicle_type_code3']) : '';
$filter_v4 = isset($_GET['vehicle_type_code4']) ? trim($_GET['vehicle_type_code4']) : '';

// Pagination
$per_page = 100;
$page     = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $per_page;

if ($filter_date_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_from)) {
    $filter_date_from = '';
}
if ($filter_date_to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date_to)) {
    $filter_date_to = '';
}

// ----------- BUILD WHERE CLAUSE -----------
$where  = [];
$params = [];

if ($filter_date_from !== '') {
    $where[] = "crash_date >= :date_from";
    $params[':date_from'] = $filter_date_from;
}
if ($filter_date_to !== '') {
    $where[] = "crash_date <= :date_to";
    $params[':date_to'] = $filter_date_to;
}
if ($filter_borough !== '') {
    $where[] = "borough = :borough";
    $params[':borough'] = $filter_borough;
}
if ($filter_street !== '') {
    $where[] = "street_on = :street_on";
    $params[':street_on'] = $filter_street;
}

// Each vehicle filter narrows by matching column if set
if ($filter_v1 !== '') {
    $where[] = "vehicle_type_code1 = :v1";
    $params[':v1'] = $filter_v1;
}
if ($filter_v2 !== '') {
    $where[] = "vehicle_type_code2 = :v2";
    $params[':v2'] = $filter_v2;
}
if ($filter_v3 !== '') {
    $where[] = "vehicle_type_code3 = :v3";
    $params[':v3'] = $filter_v3;
}
if ($filter_v4 !== '') {
    $where[] = "vehicle_type_code4 = :v4";
    $params[':v4'] = $filter_v4;
}

$whereSql = '';
if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}

// ----------- SUMMARY STATS & COUNT -----------
$total_crashes = 0;
$total_injured = 0;
$total_killed  = 0;
$total_rows    = 0;
$latest_rows   = [];
$all_boroughs  = [];
$all_streets   = [];
$all_v1        = [];
$all_v2        = [];
$all_v3        = [];
$all_v4        = [];

// Extra stats
$stats_borough = [];
$stats_daily   = [];
$stats_vtype1  = [];

if ($pdo) {
    // Summary
    $sql_summary = "SELECT
        COUNT(*) AS total_crashes,
        SUM(persons_injured) AS total_injured,
        SUM(persons_killed)  AS total_killed
      FROM nyc_crashes_2025
      {$whereSql}";
    $summary_stmt = $pdo->prepare($sql_summary);
    $summary_stmt->execute($params);
    $summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

    if ($summary) {
        $total_crashes = (int)($summary['total_crashes'] ?? 0);
        $total_injured = (int)($summary['total_injured'] ?? 0);
        $total_killed  = (int)($summary['total_killed'] ?? 0);
    }

    // Count for pagination
    $sql_count = "SELECT COUNT(*) AS total_rows FROM nyc_crashes_2025 {$whereSql}";
    $count_stmt = $pdo->prepare($sql_count);
    $count_stmt->execute($params);
    $count_res = $count_stmt->fetch(PDO::FETCH_ASSOC);
    if ($count_res) {
        $total_rows = (int)$count_res['total_rows'];
    }
    $total_pages = ($total_rows > 0) ? (int)ceil($total_rows / $per_page) : 1;
    if ($page > $total_pages) {
        $page = $total_pages;
        $offset = ($page - 1) * $per_page;
    }

    // Page rows – include latitude/longitude for map
    $sql_latest = "SELECT
        crash_date, crash_time, borough, street_on, street_cross,
        persons_injured, persons_killed,
        vehicle_type_code1, vehicle_type_code2, vehicle_type_code3, vehicle_type_code4,
        latitude, longitude
      FROM nyc_crashes_2025
      {$whereSql}
      ORDER BY crash_date DESC, crash_time DESC
      LIMIT :limit OFFSET :offset";
    $latest_stmt = $pdo->prepare($sql_latest);
    foreach ($params as $k => $v) {
        $latest_stmt->bindValue($k, $v);
    }
    $latest_stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $latest_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $latest_stmt->execute();
    $latest_rows = $latest_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Boroughs list
    $sql_boroughs = "SELECT DISTINCT borough
                     FROM nyc_crashes_2025
                     WHERE borough IS NOT NULL AND borough <> ''
                     ORDER BY borough ASC";
    $all_boroughs = $pdo->query($sql_boroughs)->fetchAll(PDO::FETCH_COLUMN);

    // Vehicle type lists
    $sql_v1 = "SELECT DISTINCT vehicle_type_code1
               FROM nyc_crashes_2025
               WHERE vehicle_type_code1 IS NOT NULL AND vehicle_type_code1 <> ''
               ORDER BY vehicle_type_code1 ASC
               LIMIT 200";
    $all_v1 = $pdo->query($sql_v1)->fetchAll(PDO::FETCH_COLUMN);

    $sql_v2 = "SELECT DISTINCT vehicle_type_code2
               FROM nyc_crashes_2025
               WHERE vehicle_type_code2 IS NOT NULL AND vehicle_type_code2 <> ''
               ORDER BY vehicle_type_code2 ASC
               LIMIT 200";
    $all_v2 = $pdo->query($sql_v2)->fetchAll(PDO::FETCH_COLUMN);

    $sql_v3 = "SELECT DISTINCT vehicle_type_code3
               FROM nyc_crashes_2025
               WHERE vehicle_type_code3 IS NOT NULL AND vehicle_type_code3 <> ''
               ORDER BY vehicle_type_code3 ASC
               LIMIT 200";
    $all_v3 = $pdo->query($sql_v3)->fetchAll(PDO::FETCH_COLUMN);

    $sql_v4 = "SELECT DISTINCT vehicle_type_code4
               FROM nyc_crashes_2025
               WHERE vehicle_type_code4 IS NOT NULL AND vehicle_type_code4 <> ''
               ORDER BY vehicle_type_code4 ASC
               LIMIT 200";
    $all_v4 = $pdo->query($sql_v4)->fetchAll(PDO::FETCH_COLUMN);

    // Streets: dependent on borough
    if ($filter_borough !== '') {
        $sql_streets = "SELECT DISTINCT street_on
                        FROM nyc_crashes_2025
                        WHERE borough = :b_street
                        AND street_on IS NOT NULL
                        AND street_on <> ''
                        ORDER BY street_on ASC
                        LIMIT 500";
        $st_stmt = $pdo->prepare($sql_streets);
        $st_stmt->execute([':b_street' => $filter_borough]);
        $all_streets = $st_stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $sql_streets = "SELECT DISTINCT street_on
                        FROM nyc_crashes_2025
                        WHERE street_on IS NOT NULL
                        AND street_on <> ''
                        ORDER BY street_on ASC
                        LIMIT 500";
        $all_streets = $pdo->query($sql_streets)->fetchAll(PDO::FETCH_COLUMN);
    }

    // --- Extra stats based on current filters ---
    $sql_stats_borough = "SELECT borough,
                                 COUNT(*) AS crash_count,
                                 SUM(persons_injured) AS injured,
                                 SUM(persons_killed)  AS killed
                          FROM nyc_crashes_2025
                          {$whereSql}
                          GROUP BY borough
                          ORDER BY crash_count DESC";
    $stmt_sb = $pdo->prepare($sql_stats_borough);
    $stmt_sb->execute($params);
    $stats_borough = $stmt_sb->fetchAll(PDO::FETCH_ASSOC);

    $sql_stats_daily = "SELECT crash_date,
                               COUNT(*) AS crash_count,
                               SUM(persons_injured) AS injured,
                               SUM(persons_killed)  AS killed
                        FROM nyc_crashes_2025
                        {$whereSql}
                        GROUP BY crash_date
                        ORDER BY crash_date ASC";
    $stmt_sd = $pdo->prepare($sql_stats_daily);
    $stmt_sd->execute($params);
    $stats_daily = $stmt_sd->fetchAll(PDO::FETCH_ASSOC);

    if ($whereSql === '') {
        $sql_stats_vtype1 = "SELECT vehicle_type_code1 AS vtype,
                                    COUNT(*) AS crash_count
                             FROM nyc_crashes_2025
                             WHERE vehicle_type_code1 IS NOT NULL
                               AND vehicle_type_code1 <> ''
                             GROUP BY vehicle_type_code1
                             ORDER BY crash_count DESC
                             LIMIT 5";
        $params_vtype = [];
    } else {
        $sql_stats_vtype1 = "SELECT vehicle_type_code1 AS vtype,
                                    COUNT(*) AS crash_count
                             FROM nyc_crashes_2025
                             {$whereSql}
                               AND vehicle_type_code1 IS NOT NULL
                               AND vehicle_type_code1 <> ''
                             GROUP BY vehicle_type_code1
                             ORDER BY crash_count DESC
                             LIMIT 5";
        $params_vtype = $params;
    }

    $stmt_sv = $pdo->prepare($sql_stats_vtype1);
    $stmt_sv->execute($params_vtype);
    $stats_vtype1 = $stmt_sv->fetchAll(PDO::FETCH_ASSOC);

} else {
    $total_pages = 1;
}

// Prepare arrays for charts
$chart_borough_labels = [];
$chart_borough_values = [];
foreach ($stats_borough as $sb) {
    $chart_borough_labels[] = $sb['borough'] ?: 'Unknown';
    $chart_borough_values[] = (int)$sb['crash_count'];
}

$chart_daily_labels = [];
$chart_daily_values = [];
foreach ($stats_daily as $sd) {
    $chart_daily_labels[] = $sd['crash_date'];
    $chart_daily_values[] = (int)$sd['crash_count'];
}

$chart_vtype_labels = [];
$chart_vtype_values = [];
foreach ($stats_vtype1 as $sv) {
    $chart_vtype_labels[] = $sv['vtype'] ?: 'Unknown';
    $chart_vtype_values[] = (int)$sv['crash_count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>NYC Crash Data - Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="style.css" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script> <!-- [web:287] -->
  <style>
    .map-modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.6);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 1000;
    }
    .map-modal {
      background: #020617;
      border-radius: 12px;
      width: 90%;
      max-width: 960px;
      height: 70vh;
      box-shadow: 0 20px 40px rgba(0,0,0,0.7);
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }
    .map-modal-header {
      padding: 0.5rem 0.75rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 1px solid rgba(148,163,184,0.35);
      color: #e5e7eb;
      font-size: 0.9rem;
    }
    .map-modal-body {
      flex: 1;
    }
    .map-modal-body iframe {
      border: 0;
      width: 100%;
      height: 100%;
    }
    .map-modal-close {
      background: none;
      border: none;
      color: #9ca3af;
      cursor: pointer;
      font-size: 1rem;
    }
    .map-modal-close:hover {
      color: #e5e7eb;
    }
  </style>
</head>
<body class="dark dashboard-body">
  <aside class="sidebar">
    <div class="sidebar-header">
      <div class="logo">NYC Crash Data 2025</div>
    </div>
    <nav class="sidebar-nav">
      <a href="#" class="sidebar-link active">Overview</a>
    </nav>
    <div class="sidebar-footer">
      <a href="logout.php" class="sidebar-link muted">Sign out</a>
    </div>
  </aside>

  <div class="dashboard-main">
    <header class="dashboard-topbar">
      <h1>Dashboard</h1>
      <div class="topbar-right">
        <div class="chip"><?php echo htmlspecialchars($user_email); ?></div>
        <div class="avatar">
          <?php echo strtoupper(substr($user_name, 0, 1)); ?>
        </div>
      </div>
    </header>

    <main class="dashboard-content">
      <!-- Statistics at top -->
      <section class="card" style="margin-bottom:1.5rem;">
        <h2>Collision statistics (filtered view)</h2>
        <p style="color:var(--text-soft); font-size:0.85rem; margin-bottom:0.75rem;">
          Visual summaries computed from the same filtered dataset shown below. Adjust the filters to update these charts.
        </p>

        <div style="display:flex; flex-wrap:wrap; gap:1rem;">
          <div style="flex:1 1 260px; min-width:260px;">
            <h3 style="font-size:0.95rem; margin-bottom:0.5rem;">Crashes by borough</h3>
            <canvas id="chartBorough"></canvas>
          </div>

          <div style="flex:2 1 360px; min-width:320px;">
            <h3 style="font-size:0.95rem; margin-bottom:0.5rem;">Daily crash trend</h3>
            <canvas id="chartDaily"></canvas>
          </div>

          <div style="flex:1 1 260px; min-width:260px;">
            <h3 style="font-size:0.95rem; margin-bottom:0.5rem;">Top vehicle types (slot 1)</h3>
            <canvas id="chartVTypes"></canvas>
          </div>
        </div>
      </section>

      <!-- NYC Crash Data section -->
      <section class="card" style="margin-top:1.5rem; min-height:260px;">
        <h2>NYC Motor Vehicle Collisions – 2025</h2>
        <p style="color:var(--text-soft); font-size:0.9rem; margin-bottom:0.4rem;">
          Data from NYC Open Data “Motor Vehicle Collisions Filter by date range, borough, street, and vehicle types.
        </p>

        <!-- Import / Clear toolbar -->
        <div style="display:flex; flex-wrap:wrap; gap:0.75rem; align-items:flex-end; margin-bottom:0.8rem;">
          <form action="import_start.php" method="post" style="display:flex; flex-wrap:wrap; gap:0.5rem; align-items:flex-end;">
            <div class="field" style="min-width:180px;">
              <span style="display:block; font-size:0.8rem; margin-bottom:0.25rem;">Month to import</span>
              <select name="month" style="width:100%;">
                <option value="0">All 2025</option>
                <option value="1">January 2025</option>
                <option value="2">February 2025</option>
                <option value="3">March 2025</option>
                <option value="4">April 2025</option>
                <option value="5">May 2025</option>
                <option value="6">June 2025</option>
                <option value="7">July 2025</option>
                <option value="8">August 2025</option>
                <option value="9">September 2025</option>
                <option value="10">October 2025</option>
                <option value="11">November 2025</option>
                <option value="12">December 2025</option>
              </select>
            </div>
            <div>
              <button type="submit" class="btn btn-primary">Import data</button>
            </div>
          </form>

          <form action="clear_import.php" method="post"
                onsubmit="return confirm('This will delete all imported crash data. Continue?');">
            <button type="submit" class="btn btn-ghost">Clear imported data</button>
          </form>

          <div style="font-size:0.8rem; color:var(--text-soft); margin-left:auto;">
            Total rows imported: <span class="pill"><?php echo number_format($import_rows); ?></span>
          </div>
        </div>

        <!-- Filter controls -->
        <form id="filterForm" method="get" style="display:flex; flex-wrap:wrap; gap:0.75rem; margin-bottom:1rem; align-items:flex-end;">
          <div class="field" style="min-width:160px;">
            <span style="display:block; font-size:0.8rem; margin-bottom:0.25rem;">Date from</span>
            <input type="date" name="date_from"
                   value="<?php echo htmlspecialchars($filter_date_from); ?>"
                   style="width:100%;">
          </div>

          <div class="field" style="min-width:160px;">
            <span style="display:block; font-size:0.8rem; margin-bottom:0.25rem;">Date to</span>
            <input type="date" name="date_to"
                   value="<?php echo htmlspecialchars($filter_date_to); ?>"
                   style="width:100%;">
          </div>

          <div class="field" style="min-width:160px;">
            <span style="display:block; font-size:0.8rem; margin-bottom:0.25rem;">Borough</span>
            <select name="borough" id="boroughSelect" style="width:100%;">
              <option value="">All boroughs</option>
              <?php foreach ($all_boroughs as $b): ?>
                <option value="<?php echo htmlspecialchars($b); ?>"
                  <?php if ($filter_borough === $b) echo 'selected'; ?>>
                  <?php echo htmlspecialchars($b); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field" style="min-width:200px;">
            <span style="display:block; font-size:0.8rem; margin-bottom:0.25rem;">Street (on)</span>
            <select name="street_on" id="streetSelect" style="width:100%;">
              <option value="">All streets</option>
              <?php foreach ($all_streets as $s): ?>
                <option value="<?php echo htmlspecialchars($s); ?>"
                  <?php if ($filter_street === $s) echo 'selected'; ?>>
                  <?php echo htmlspecialchars($s); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Vehicle filters -->
          <div class="field" style="min-width:180px;">
            <span style="display:block; font-size:0.8rem; margin-bottom:0.25rem;">Vehicle type 1</span>
            <select name="vehicle_type_code1" style="width:100%;">
              <option value="">Any</option>
              <?php foreach ($all_v1 as $v): ?>
                <option value="<?php echo htmlspecialchars($v); ?>"
                  <?php if ($filter_v1 === $v) echo 'selected'; ?>>
                  <?php echo htmlspecialchars($v); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field" style="min-width:180px;">
            <span style="display:block; font-size:0.8rem; margin-bottom:0.25rem;">Vehicle type 2</span>
            <select name="vehicle_type_code2" style="width:100%;">
              <option value="">Any</option>
              <?php foreach ($all_v2 as $v): ?>
                <option value="<?php echo htmlspecialchars($v); ?>"
                  <?php if ($filter_v2 === $v) echo 'selected'; ?>>
                  <?php echo htmlspecialchars($v); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field" style="min-width:180px;">
            <span style="display:block; font-size:0.8rem; margin-bottom:0.25rem;">Vehicle type 3</span>
            <select name="vehicle_type_code3" style="width:100%;">
              <option value="">Any</option>
              <?php foreach ($all_v3 as $v): ?>
                <option value="<?php echo htmlspecialchars($v); ?>"
                  <?php if ($filter_v3 === $v) echo 'selected'; ?>>
                  <?php echo htmlspecialchars($v); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field" style="min-width:180px;">
            <span style="display:block; font-size:0.8rem; margin-bottom:0.25rem;">Vehicle type 4</span>
            <select name="vehicle_type_code4" style="width:100%;">
              <option value="">Any</option>
              <?php foreach ($all_v4 as $v): ?>
                <option value="<?php echo htmlspecialchars($v); ?>"
                  <?php if ($filter_v4 === $v) echo 'selected'; ?>>
                  <?php echo htmlspecialchars($v); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <input type="hidden" name="page" value="<?php echo (int)$page; ?>" id="pageInput">

          <div>
            <button type="submit" class="btn btn-primary">Apply filters</button>
          </div>
          <div>
            <a href="dashboard.php" class="btn btn-ghost">Reset</a>
          </div>
        </form>

        <div style="display:flex; flex-wrap:wrap; gap:1rem; margin-bottom:1rem;">
          <div style="flex:1 1 220px;">
            <ul class="meta-list">
              <li>Total crashes (filtered): <span class="pill"><?php echo number_format($total_crashes); ?></span></li>
              <li>Total persons injured: <span class="pill"><?php echo number_format($total_injured); ?></span></li>
              <li>Total persons killed: <span class="pill"><?php echo number_format($total_killed); ?></span></li>
              <li>Rows in table (filtered): <span class="pill"><?php echo number_format($total_rows); ?></span></li>
            </ul>
          </div>
        </div>

        <div class="code-block" style="min-height:140px; max-height:420px; overflow:auto;">
          <table style="width:100%; border-collapse:collapse; font-size:0.8rem;">
            <thead>
              <tr>
                <th style="text-align:left; padding:4px;">Date</th>
                <th style="text-align:left; padding:4px;">Time</th>
                <th style="text-align:left; padding:4px;">Borough</th>
                <th style="text-align:left; padding:4px;">Street</th>
                <th style="text-align:left; padding:4px;">Cross street</th>
                <th style="text-align:left; padding:4px;">Vehicle 1</th>
                <th style="text-align:left; padding:4px;">Vehicle 2</th>
                <th style="text-align:left; padding:4px;">Vehicle 3</th>
                <th style="text-align:left; padding:4px;">Vehicle 4</th>
                <th style="text-align:right; padding:4px;">Injured</th>
                <th style="text-align:right; padding:4px;">Killed</th>
                <th style="text-align:center; padding:4px;">Location</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($latest_rows)): ?>
                <?php foreach ($latest_rows as $r): ?>
                  <?php
                    $lat = isset($r['latitude']) ? (float)$r['latitude'] : null;
                    $lng = isset($r['longitude']) ? (float)$r['longitude'] : null;
                    $hasCoords = $lat !== null && $lng !== null;
                  ?>
                  <tr>
                    <td style="padding:4px;"><?php echo htmlspecialchars($r['crash_date']); ?></td>
                    <td style="padding:4px;"><?php echo htmlspecialchars($r['crash_time']); ?></td>
                    <td style="padding:4px;"><?php echo htmlspecialchars($r['borough'] ?? '-'); ?></td>
                    <td style="padding:4px;"><?php echo htmlspecialchars($r['street_on'] ?? '-'); ?></td>
                    <td style="padding:4px;"><?php echo htmlspecialchars($r['street_cross'] ?? '-'); ?></td>
                    <td style="padding:4px;"><?php echo htmlspecialchars($r['vehicle_type_code1'] ?? '-'); ?></td>
                    <td style="padding:4px;"><?php echo htmlspecialchars($r['vehicle_type_code2'] ?? '-'); ?></td>
                    <td style="padding:4px;"><?php echo htmlspecialchars($r['vehicle_type_code3'] ?? '-'); ?></td>
                    <td style="padding:4px;"><?php echo htmlspecialchars($r['vehicle_type_code4'] ?? '-'); ?></td>
                    <td style="padding:4px; text-align:right;"><?php echo (int)$r['persons_injured']; ?></td>
                    <td style="padding:4px; text-align:right;"><?php echo (int)$r['persons_killed']; ?></td>
                    <td style="padding:4px; text-align:center;">
                      <?php if ($hasCoords): ?>
                        <button type="button"
                                class="btn btn-ghost"
                                style="padding:2px 6px; font-size:0.7rem;"
                                onclick="openMap(<?php echo $lat; ?>, <?php echo $lng; ?>)">
                          View map
                        </button>
                      <?php else: ?>
                        <span style="font-size:0.7rem; color:var(--text-soft);">N/A</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="12" style="padding:4px;">No crash data matches the current filters.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination controls -->
        <?php if ($total_rows > $per_page): ?>
          <div style="margin-top:0.75rem; display:flex; gap:0.5rem; align-items:center;">
            <span style="font-size:0.8rem; color:var(--text-soft);">
              Page <?php echo $page; ?> of <?php echo $total_pages; ?>
            </span>
            <div style="display:flex; gap:0.5rem;">
              <?php
                function buildPageUrl($targetPage) {
                    $qs = $_GET;
                    $qs['page'] = $targetPage;
                    return 'dashboard.php?' . http_build_query($qs);
                }
              ?>
              <?php if ($page > 1): ?>
                <a class="btn btn-ghost" href="<?php echo buildPageUrl($page - 1); ?>">Previous</a>
              <?php endif; ?>
              <?php if ($page < $total_pages): ?>
                <a class="btn btn-ghost" href="<?php echo buildPageUrl($page + 1); ?>">Next</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </section>
    </main>
  </div>

  <!-- Map modal -->
  <div class="map-modal-backdrop" id="mapModalBackdrop">
    <div class="map-modal">
      <div class="map-modal-header">
        <span id="mapModalTitle">Crash location</span>
        <button class="map-modal-close" type="button" onclick="closeMap()">&times;</button>
      </div>
      <div class="map-modal-body">
        <iframe id="mapIframe"
                src=""
                loading="lazy"
                referrerpolicy="no-referrer-when-downgrade"></iframe>
      </div>
    </div>
  </div>

  <script>
    // Dynamic streets based on borough and dates
    document.addEventListener('DOMContentLoaded', function () {
      var boroughSelect = document.getElementById('boroughSelect');
      var streetSelect  = document.getElementById('streetSelect');
      var dateFromInput = document.querySelector('input[name="date_from"]');
      var dateToInput   = document.querySelector('input[name="date_to"]');
      var pageInput     = document.getElementById('pageInput');

      if (!boroughSelect || !streetSelect) return;

      function fetchStreets() {
        var borough = boroughSelect.value;
        var dateFrom = dateFromInput ? dateFromInput.value : '';
        var dateTo = dateToInput ? dateToInput.value : '';

        if (pageInput) pageInput.value = '1';

        var url = 'get_streets.php?borough=' + encodeURIComponent(borough) +
                  '&date_from=' + encodeURIComponent(dateFrom) +
                  '&date_to=' + encodeURIComponent(dateTo);

        fetch(url)
          .then(function (res) { return res.json(); })
          .then(function (streets) {
            streetSelect.innerHTML = '';
            var opt = document.createElement('option');
            opt.value = '';
            opt.textContent = 'All streets';
            streetSelect.appendChild(opt);

            streets.forEach(function (s) {
              var o = document.createElement('option');
              o.value = s;
              o.textContent = s;
              streetSelect.appendChild(o);
            });
          })
          .catch(function (err) {
            console.error('Failed to load streets', err);
          });
      }

      boroughSelect.addEventListener('change', fetchStreets);
      if (dateFromInput) dateFromInput.addEventListener('change', fetchStreets);
      if (dateToInput)   dateToInput.addEventListener('change', fetchStreets);
    });

    // Charts
    document.addEventListener('DOMContentLoaded', function () {
      var boroughLabels = <?php echo json_encode($chart_borough_labels); ?>;
      var boroughData   = <?php echo json_encode($chart_borough_values); ?>;
      var dailyLabels   = <?php echo json_encode($chart_daily_labels); ?>;
      var dailyData     = <?php echo json_encode($chart_daily_values); ?>;
      var vtypeLabels   = <?php echo json_encode($chart_vtype_labels); ?>;
      var vtypeData     = <?php echo json_encode($chart_vtype_values); ?>;

      if (document.getElementById('chartBorough') && boroughLabels.length > 0) {
        new Chart(document.getElementById('chartBorough').getContext('2d'), {
          type: 'pie',
          data: {
            labels: boroughLabels,
            datasets: [{
              data: boroughData,
              backgroundColor: [
                '#4f46e5','#ec4899','#22c55e','#eab308','#f97316',
                '#06b6d4','#a855f7','#f97373'
              ],
            }]
          },
          options: {
            plugins: {
              legend: { labels: { color: '#e5e7eb' } }
            }
          }
        });
      }

      if (document.getElementById('chartDaily') && dailyLabels.length > 0) {
        new Chart(document.getElementById('chartDaily').getContext('2d'), {
          type: 'line',
          data: {
            labels: dailyLabels,
            datasets: [{
              label: 'Crashes per day',
              data: dailyData,
              borderColor: '#22c55e',
              backgroundColor: 'rgba(34,197,94,0.2)',
              tension: 0.2,
              pointRadius: 0
            }]
          },
          options: {
            scales: {
              x: {
                ticks: { color: '#9ca3af', maxRotation: 0, autoSkip: true },
                grid: { color: 'rgba(55,65,81,0.3)' }
              },
              y: {
                ticks: { color: '#9ca3af' },
                grid: { color: 'rgba(55,65,81,0.3)' }
              }
            },
            plugins: {
              legend: { labels: { color: '#e5e7eb' } }
            }
          }
        });
      }

      if (document.getElementById('chartVTypes') && vtypeLabels.length > 0) {
        new Chart(document.getElementById('chartVTypes').getContext('2d'), {
          type: 'bar',
          data: {
            labels: vtypeLabels,
            datasets: [{
              label: 'Crashes',
              data: vtypeData,
              backgroundColor: '#3b82f6'
            }]
          },
          options: {
            indexAxis: 'y',
            scales: {
              x: {
                ticks: { color: '#9ca3af' },
                grid: { color: 'rgba(55,65,81,0.3)' }
              },
              y: {
                ticks: { color: '#9ca3af' },
                grid: { display: false }
              }
            },
            plugins: {
              legend: { labels: { color: '#e5e7eb' } }
            }
          }
        });
      }
    });

    // Map modal logic: uses a Google Maps iframe with q=lat,lng&output=embed 
    function openMap(lat, lng) {
      var backdrop = document.getElementById('mapModalBackdrop');
      var iframe   = document.getElementById('mapIframe');
      var titleEl  = document.getElementById('mapModalTitle');

      if (!backdrop || !iframe) return;

      var url = 'https://maps.google.com/maps?q=' + lat + ',' + lng + '&z=16&output=embed';
      iframe.src = url;
      if (titleEl) {
        titleEl.textContent = 'Crash location (' + lat.toFixed(5) + ', ' + lng.toFixed(5) + ')';
      }
      backdrop.style.display = 'flex';
    }

    function closeMap() {
      var backdrop = document.getElementById('mapModalBackdrop');
      var iframe   = document.getElementById('mapIframe');
      if (backdrop) backdrop.style.display = 'none';
      if (iframe) iframe.src = '';
    }

    // Close modal on ESC
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeMap();
      }
    });
  </script>
</body>
</html>