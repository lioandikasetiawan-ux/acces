<?php
session_start();

// kalau SUDAH login, arahkan ke dashboard
if (isset($_SESSION['login'])) {
    header("Location: ../admin/dashboard.php");
    exit;
}

// kalau BELUM login, arahkan ke halaman login
header("Location: login-v2.php");
exit;
