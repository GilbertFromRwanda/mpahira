<?php
session_start();

$localConfig = __DIR__ . '/db.local.php';
if (!file_exists($localConfig)) {
    die('Missing config/db.local.php. Copy config/db.example.php to config/db.local.php and fill in your database credentials.');
}
require_once $localConfig;

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

date_default_timezone_set('Africa/Kigali');

require_once __DIR__ . '/functions.php';
