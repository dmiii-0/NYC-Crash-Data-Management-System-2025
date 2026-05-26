<?php
// get_streets.php - returns JSON list of streets for a given borough (optional date filters)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/conn/conn.php';

header('Content-Type: application/json');

$db  = new Database();
$pdo = $db->getConn();

$borough = isset($_GET['borough']) ? trim($_GET['borough']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to   = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : '';

if ($date_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $date_from = '';
}
if ($date_to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $date_to = '';
}

$where = [];
$params = [];

if ($borough !== '') {
    $where[] = "borough = :borough";
    $params[':borough'] = $borough;
}
if ($date_from !== '') {
    $where[] = "crash_date >= :date_from";
    $params[':date_from'] = $date_from;
}
if ($date_to !== '') {
    $where[] = "crash_date <= :date_to";
    $params[':date_to'] = $date_to;
}

$whereSql = '';
if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}

$sql = "SELECT DISTINCT street_on
        FROM nyc_crashes_2025
        {$whereSql}
        AND street_on IS NOT NULL
        AND street_on <> ''
        ORDER BY street_on ASC
        LIMIT 500";

if ($whereSql === '') {
    // No filters at all: need a WHERE for the extra conditions
    $sql = "SELECT DISTINCT street_on
            FROM nyc_crashes_2025
            WHERE street_on IS NOT NULL
            AND street_on <> ''
            ORDER BY street_on ASC
            LIMIT 500";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$streets = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo json_encode($streets);