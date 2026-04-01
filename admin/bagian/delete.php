<?php
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../auth/login-v2.php');
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    redirectWithMessage('index.php', 'ID tidak valid', 'error');
}

// Cek apakah bagian digunakan di absensi
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM absensi WHERE bagian_id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

if ($result['count'] > 0) {
    redirectWithMessage('index.php', 'Bagian tidak bisa dihapus karena sudah digunakan di data absensi. Nonaktifkan saja.', 'error');
}

// Hapus bagian (koordinat akan terhapus otomatis karena ON DELETE CASCADE)
$stmt = $conn->prepare("DELETE FROM bagian WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    redirectWithMessage('index.php', 'Bagian berhasil dihapus', 'success');
} else {
    redirectWithMessage('index.php', 'Gagal menghapus bagian: ' . $conn->error, 'error');
}
?>
