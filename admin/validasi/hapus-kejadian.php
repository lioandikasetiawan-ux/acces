<?php
require_once '../../config/database.php';
require_once '../../config/session.php';

if ($_SESSION['role'] !== 'admin') { 
    header("Location: ../../auth/login-v2.php"); 
    exit; 
}

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    // Only allow deletion of approved or rejected records
    $check = $conn->prepare("SELECT status FROM kejadian WHERE id = ?");
    $check->bind_param("i", $id);
    $check->execute();
    $result = $check->get_result();
    $row = $result->fetch_assoc();
    
    if ($row && ($row['status'] === 'disetujui' || $row['status'] === 'ditolak')) {
        $stmt = $conn->prepare("DELETE FROM kejadian WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            echo "<script>alert('Laporan berhasil dihapus!'); window.location.href='kejadian.php';</script>";
        } else {
            echo "<script>alert('Error: Gagal menghapus laporan.'); window.location.href='kejadian.php';</script>";
        }
    } else {
        echo "<script>alert('Hanya laporan yang sudah disetujui atau ditolak yang dapat dihapus.'); window.location.href='kejadian.php';</script>";
    }
} else {
    header("Location: kejadian.php");
    exit;
}
?>
