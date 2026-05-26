<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/conn/conn.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$db  = new Database();
$pdo = $db->getConn();

if (!$pdo) {
    // If DB is not available, just go back to dashboard with no hard error page
    header("Location: dashboard.php");
    exit;
}

try {
    // No explicit transaction – TRUNCATE is auto-committing in MySQL [web:231][web:234]

    // Remove all imported crash data
    $pdo->exec("TRUNCATE TABLE nyc_crashes_2025");

    // Reset import status if table exists
    $statusCheck = $pdo->query("SHOW TABLES LIKE 'nyc_import_status'");
    if ($statusCheck && $statusCheck->rowCount() > 0) {
        $pdo->exec(
            "UPDATE nyc_import_status
             SET imported_rows = 0,
                 last_import_at = NULL
             WHERE id = 1"
        );
    }

} catch (Exception $e) {
    // Optional: log the error somewhere, but do not stop user with a fatal page
    // error_log('clear_import failed: ' . $e->getMessage());
}

// Always return to dashboard, whether clear succeeded or not
header("Location: dashboard.php");
exit;