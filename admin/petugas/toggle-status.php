<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'superadmin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'ID tidak valid']);
    exit;
}

$currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['petugas_id'] ?? 0);
if ($id === $currentUserId) {
    echo json_encode(['success' => false, 'message' => 'Tidak dapat menonaktifkan akun sendiri']);
    exit;
}

$checkCol = $conn->query("SHOW COLUMNS FROM petugas LIKE 'is_active'");
if (!$checkCol || $checkCol->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Kolom is_active tidak ditemukan']);
    exit;
}

$stmt = $conn->prepare("UPDATE petugas SET is_active = NOT is_active WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    $res = $conn->query("SELECT is_active FROM petugas WHERE id = $id");
    $row = $res->fetch_assoc();
    $newStatus = (int)$row['is_active'];
    echo json_encode(['success' => true, 'is_active' => $newStatus, 'message' => $newStatus ? 'Petugas diaktifkan' : 'Petugas dinonaktifkan']);
} else {
    echo json_encode(['success' => false, 'message' => 'Gagal mengubah status']);
}

$stmt->close();
