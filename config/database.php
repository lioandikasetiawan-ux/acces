<?php
// config/database.php
$host = "127.0.0.1";
$user = "root";
$pass = "";
$db   = "db_acces";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

// Set timezone agar waktu absen akurat (Penting untuk projek absensi)
date_default_timezone_set('Asia/Jakarta');
?>