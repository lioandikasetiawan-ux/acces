<?php
session_start();

/*
  Sesuaikan dengan session login yang kamu pakai.
  Contoh umum:
  - $_SESSION['login']
  - $_SESSION['user']
  - $_SESSION['username']
*/

if (isset($_SESSION['user'])) {
    // Jika sudah login ? ke dashboard
    header("Location: dashboard.php");
    exit;
} else {
    // Jika belum login ? ke halaman login
    header("Location: ../../auth/login-v2.php");
    exit;
}
?>
