<?php
require_once '../../config/database.php';
require_once '../../config/session.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'superadmin'], true)) {
    header("Location: ../../auth/login-v2.php"); exit;
}

if (isset($_GET['id'])) {
    $id = $_GET['id'];

    $currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['petugas_id'] ?? 0);
    if ((int)$id === $currentUserId) {
        echo "<script>alert('Tidak bisa menghapus akun sendiri!'); window.location.href='index.php';</script>";
        exit;
    }
    
    // Hapus data (Otomatis hapus user login & absensi karena Foreign Key Cascade)
    $stmt = $conn->prepare("DELETE FROM petugas WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        echo "<script>alert('Petugas berhasil dihapus!'); window.location.href='index.php';</script>";
    } else {
        echo "<script>alert('Gagal menghapus: " . $conn->error . "'); window.location.href='index.php';</script>";
    }
}
?>