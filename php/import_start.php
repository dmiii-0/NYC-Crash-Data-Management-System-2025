<?php
// import_start.php - runs one import_crashes chunk and returns to dashboard
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/conn/conn.php';

// Read options from POST
$month  = isset($_POST['month'])  ? (int)$_POST['month']  : 0;
$offset = 0; // always start a new run at 0 for now

// Build CLI-style query string and include the importer
$_GET['month']  = $month;
$_GET['offset'] = $offset;

ob_start();
include __DIR__ . '/import_crashes.php';
$output = ob_get_clean();

// You could log $output if you want debugging info.
// For now we ignore it and just return to the dashboard.
header("Location: dashboard.php");
exit;