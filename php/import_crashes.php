<?php
// import_crashes.php - import only new collision_ids, 1k-page batches, batched INSERTs
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

set_time_limit(300); // adjust as needed [web:261]

require_once __DIR__ . '/conn/conn.php';

$db = new Database();
$pdo = $db->getConn();

if (!$pdo) {
    die("DB connection failed");
}

// ------------- CONFIG -------------
$pageLimit = 1000;   // rows per API call [web:184][web:187]
$currentOffset = 0;  // start at 0; we fetch until no more rows

$month = isset($_GET['month']) ? (int)$_GET['month'] : 0;
if ($month < 0 || $month > 12) {
    $month = 0;
}

// ------------- BUILD BASE QUERY -------------
$baseUrl = "https://data.cityofnewyork.us/resource/h9gi-nx95.json";

// Build WHERE for year 2025, optionally restricted to a month
if ($month >= 1 && $month <= 12) {
    $start = sprintf("2025-%02d-01T00:00:00.000", $month);
    if ($month === 12) {
        $end = "2026-01-01T00:00:00.000";
    } else {
        $end = sprintf("2025-%02d-01T00:00:00.000", $month + 1);
    }
    $where = urlencode("crash_date >= '$start' AND crash_date < '$end'");
    $monthLabel = date('F', mktime(0, 0, 0, $month, 1));
} else {
    $where = urlencode("crash_date between '2025-01-01T00:00:00.000' and '2025-12-31T23:59:59.999'");
    $monthLabel = "all 2025";
}

echo "<h2>NYC 2025 crash import – {$monthLabel}</h2>";
echo "<p>Importing all records for this period in pages of {$pageLimit}, inserting only new collision IDs.</p>";

$totalFetchedOverall   = 0;
$totalNewInsertedOverall = 0;

while (true) {
    $order = urlencode("crash_date DESC");
    $url   = "{$baseUrl}?\$where={$where}&\$limit={$pageLimit}&\$offset={$currentOffset}&\$order={$order}";

    echo "<p>Fetching page at offset {$currentOffset}: " . htmlspecialchars($url) . "</p>";

    $json = file_get_contents($url);
    if ($json === false) {
        echo "<p style='color:red;'>Failed to fetch data at offset {$currentOffset}. Stopping import.</p>";
        break;
    }

    $data = json_decode($json, true);
    if (!is_array($data) || count($data) === 0) {
        echo "<p>No more records returned at offset {$currentOffset}. Import for this period is complete.</p>";
        break;
    }

    $fetchedThisPage = count($data);
    echo "<p>Fetched {$fetchedThisPage} records for this page.</p>";

    $totalFetchedOverall += $fetchedThisPage;
    $currentOffset       += $fetchedThisPage;

    // 1) Collect collision_ids from this page
    $ids = [];
    foreach ($data as $row) {
        if (isset($row['collision_id'])) {
            $ids[] = (int)$row['collision_id'];
        }
    }
    $ids = array_values(array_unique($ids));

    if (empty($ids)) {
        // Nothing usable in this page
        if ($fetchedThisPage < $pageLimit) {
            echo "<p>No collision_ids in this page; last page reached.</p>";
            break;
        }
        continue;
    }

    // 2) Find which of these IDs already exist in DB (single SELECT) [web:270]
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sqlExisting  = "SELECT collision_id FROM nyc_crashes_2025 WHERE collision_id IN ($placeholders)";
    $stmtExisting = $pdo->prepare($sqlExisting);
    foreach ($ids as $i => $id) {
        $stmtExisting->bindValue($i + 1, $id, PDO::PARAM_INT);
    }
    $stmtExisting->execute();
    $existingIds = $stmtExisting->fetchAll(PDO::FETCH_COLUMN);

    $existingLookup = [];
    foreach ($existingIds as $eid) {
        $existingLookup[(int)$eid] = true;
    }

    // 3) Build rows to insert only for IDs not present
    $rowsToInsert = [];
    foreach ($data as $row) {
        if (!isset($row['collision_id'])) {
            continue;
        }
        $cid = (int)$row['collision_id'];
        if (isset($existingLookup[$cid])) {
            // already have this collision_id, skip
            continue;
        }

        $crash_date_raw    = $row['crash_date'] ?? null;
        $crash_time_raw    = $row['crash_time'] ?? null;
        if ($crash_date_raw === null || $crash_time_raw === null) {
            continue;
        }

        $borough           = $row['borough'] ?? null;
        $zip_code          = $row['zip_code'] ?? null;
        $latitude          = isset($row['latitude']) ? (float)$row['latitude'] : null;
        $longitude         = isset($row['longitude']) ? (float)$row['longitude'] : null;
        $street_on         = $row['on_street_name'] ?? null;
        $street_cross      = $row['cross_street_name'] ?? null;

        $vehicle_type_code1 = $row['vehicle_type_code1'] ?? null;
        $vehicle_type_code2 = $row['vehicle_type_code2'] ?? null;
        $vehicle_type_code3 = $row['vehicle_type_code3'] ?? null;
        $vehicle_type_code4 = $row['vehicle_type_code4'] ?? null;

        $persons_injured   = isset($row['number_of_persons_injured']) ? (int)$row['number_of_persons_injured'] : 0;
        $persons_killed    = isset($row['number_of_persons_killed']) ? (int)$row['number_of_persons_killed'] : 0;

        $crash_date = substr($crash_date_raw, 0, 10);
        $crash_time = substr($crash_time_raw, 11, 8);

        $rowsToInsert[] = [
            'collision_id'       => $cid,
            'crash_date'         => $crash_date,
            'crash_time'         => $crash_time,
            'borough'            => $borough,
            'zip_code'           => $zip_code,
            'latitude'           => $latitude,
            'longitude'          => $longitude,
            'street_on'          => $street_on,
            'street_cross'       => $street_cross,
            'vehicle_type_code1' => $vehicle_type_code1,
            'vehicle_type_code2' => $vehicle_type_code2,
            'vehicle_type_code3' => $vehicle_type_code3,
            'vehicle_type_code4' => $vehicle_type_code4,
            'persons_injured'    => $persons_injured,
            'persons_killed'     => $persons_killed
        ];
    }

    if (empty($rowsToInsert)) {
        echo "<p>All {$fetchedThisPage} records already existed; nothing new to insert from this page.</p>";
        if ($fetchedThisPage < $pageLimit) {
            echo "<p>Last page for this period reached.</p>";
            break;
        }
        continue;
    }

    // 4) Insert new rows in batches (e.g., 100 rows per multi-row INSERT)
    $batchSize = 100;
    $insertedFromPage = 0;

    for ($i = 0; $i < count($rowsToInsert); $i += $batchSize) {
        $batch = array_slice($rowsToInsert, $i, $batchSize);

        $values = [];
        $params = [];
        foreach ($batch as $idx => $r) {
            $values[] = "(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

            $params[] = $r['collision_id'];
            $params[] = $r['crash_date'];
            $params[] = $r['crash_time'];
            $params[] = $r['borough'];
            $params[] = $r['zip_code'];
            $params[] = $r['latitude'];
            $params[] = $r['longitude'];
            $params[] = $r['street_on'];
            $params[] = $r['street_cross'];
            $params[] = $r['vehicle_type_code1'];
            $params[] = $r['vehicle_type_code2'];
            $params[] = $r['vehicle_type_code3'];
            $params[] = $r['vehicle_type_code4'];
            $params[] = $r['persons_injured'];
            $params[] = $r['persons_killed'];
        }

        $sqlInsert = "INSERT IGNORE INTO nyc_crashes_2025
            (collision_id, crash_date, crash_time, borough, zip_code,
             latitude, longitude, street_on, street_cross,
             vehicle_type_code1, vehicle_type_code2, vehicle_type_code3, vehicle_type_code4,
             persons_injured, persons_killed)
            VALUES " . implode(',', $values);

        $stmtInsert = $pdo->prepare($sqlInsert);
        $stmtInsert->execute($params);

        // rowCount with INSERT IGNORE counts successful inserts (ignored duplicates not added) [web:267][web:272]
        $insertedFromPage += $stmtInsert->rowCount();
    }

    echo "<p>Inserted {$insertedFromPage} new rows from this page.</p>";

    $totalNewInsertedOverall += $insertedFromPage;

    if ($fetchedThisPage < $pageLimit) {
        echo "<p>Last page for this period reached.</p>";
        break;
    }
}

echo "<hr>";
echo "<p>Total records fetched for {$monthLabel}: {$totalFetchedOverall}.</p>";
echo "<p>Total new rows inserted: {$totalNewInsertedOverall}.</p>";

// ---- update nyc_import_status so dashboard knows how many rows exist ----
try {
    $countStmt = $pdo->query("SELECT COUNT(*) AS c FROM nyc_crashes_2025");
    $countRow  = $countStmt->fetch(PDO::FETCH_ASSOC);
    $totalNow  = (int)($countRow['c'] ?? 0);

    $statusCheck = $pdo->query("SHOW TABLES LIKE 'nyc_import_status'");
    if ($statusCheck && $statusCheck->rowCount() > 0) {
        $statusSql = "UPDATE nyc_import_status
                      SET imported_rows = :rows, last_import_at = NOW()
                      WHERE id = 1";
        $statusStmt = $pdo->prepare($statusSql);
        $statusStmt->execute([':rows' => $totalNow]);
    }
} catch (Exception $e) {
    echo "<p style='color:orange;'>Warning: failed to update import status: "
         . htmlspecialchars($e->getMessage()) . "</p>";
}