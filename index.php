<?php
/**
 * MAIN ENTRY POINT (Gerbang Utama)
 * File ini berfungsi untuk mengarahkan user (Redirect)
 * Tidak perlu ada HTML di sini.
 */

// 1. Panggil konfigurasi session
// Pastikan path-nya benar (folder config ada di sebelah file index.php ini)
require_once 'config/session.php';

// 2. Cek apakah user sudah login?
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    
    // 3. Jika SUDAH login, cek role-nya apa
    switch ($_SESSION['role']) {
        case 'admin':
            // Jika Admin, lempar ke Dashboard Admin
            header("Location: admin/dashboard.php");
            break;
        
        case 'petugas':
            // Jika Petugas, lempar ke Dashboard Petugas
            header("Location: petugas/dashboard-v2.php");
            break;
            
        default:
            // Jika role tidak dikenal (security guard), logout paksa
            header("Location: auth/logout.php");
            break;
    }
    exit; // Penting: Hentikan script agar tidak lanjut ke bawah

} else {
    // 4. Jika BELUM login
    // Lempar ke halaman Login
//    header("Location: auth/login-v2.php");
    header("Location: auth/login-v2.php");
    exit;
}
?>