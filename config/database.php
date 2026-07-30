<?php
session_start();

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '***REMOVED***');
define('DB_NAME', 'mpahira');

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

date_default_timezone_set('Africa/Kigali');

require_once __DIR__ . '/functions.php';
