<?php
require_once '../../config/database.php';
require_once '../../config/session.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$absensiId = isset($_POST['absensi_id']) ? (int)$_POST['absensi_id'] : 0;
$newStatus = isset($_POST['status']) ? trim($_POST['status']) : '';

if ($absensiId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID absensi tidak valid']);
    exit;
}

// Validasi status
$allowedStatus = ['--', 'absen masuk', 'hadir', 'izin', 'sakit', 'tidak hadir', 'lupa absen'];
if (!in_array($newStatus, $allowedStatus)) {
    echo json_encode(['success' => false, 'message' => 'Status tidak valid']);
    exit;
}

// Validasi akses: admin hanya bisa ubah status absensi di bagiannya
$bagianId = isset($_SESSION['bagian_id']) ? (int)$_SESSION['bagian_id'] : null;

if ($bagianId !== null) {
    // Admin bagian: cek apakah absensi ini milik petugas di bagiannya
    $stmtCheck = $conn->prepare("SELECT a.id FROM absensi a 
                                  JOIN petugas p ON a.petugas_id = p.id 
                                  WHERE a.id = ? AND p.bagian_id = ?");
    $stmtCheck->bind_param("ii", $absensiId, $bagianId);
    $stmtCheck->execute();
    $stmtCheck->store_result();
    
    if ($stmtCheck->num_rows === 0) {
        $stmtCheck->close();
        echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
        exit;
    }
    $stmtCheck->close();
}

// Update status
$stmt = $conn->prepare("UPDATE absensi SET status = ? WHERE id = ?");
$stmt->bind_param("si", $newStatus, $absensiId);

if ($stmt->execute()) {
    $stmt->close();
    echo json_encode(['success' => true, 'message' => 'Status berhasil diubah']);
} else {
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Gagal mengubah status: ' . $conn->error]);
}
?>