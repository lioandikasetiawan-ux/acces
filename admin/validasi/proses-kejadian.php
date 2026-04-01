<?php
require_once '../../config/database.php';
require_once '../../config/session.php';

if ($_SESSION['role'] !== 'admin') { header("Location: ../../auth/login-v2.php"); exit; }

if (isset($_GET['id']) && isset($_GET['aksi'])) {
    $id = $_GET['id'];
    $status = ($_GET['aksi'] == 'approve') ? 'disetujui' : 'ditolak';

    $stmt = $conn->prepare("UPDATE kejadian SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $id);

    if ($stmt->execute()) {
        echo "<script>alert('Status kejadian berhasil diperbarui!'); window.location.href='kejadian.php';</script>";
    } else {
        echo "Error: " . $conn->error;
    }
}
?>