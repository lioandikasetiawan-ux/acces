<?php
/**
 * API: Get Shift Tersedia
 * =======================
 * Mengembalikan daftar shift yang tersedia berdasarkan waktu sekarang
 */

require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Cek login
if (!isset($_SESSION['petugas_id'])) {
    jsonResponse(false, 'Unauthorized', [], 401);
}

$currentTime = $_GET['time'] ?? date('H:i:s');

try {
    $shiftData = getShiftTersedia($conn, $currentTime);
    
    jsonResponse(true, 'Data shift berhasil diambil', [
        'current_time' => $currentTime,
        'tersedia' => $shiftData['tersedia'],
        'tidak_tersedia' => $shiftData['tidak_tersedia']
    ]);
} catch (Exception $e) {
    jsonResponse(false, 'Error: ' . $e->getMessage(), [], 500);
}
?>
