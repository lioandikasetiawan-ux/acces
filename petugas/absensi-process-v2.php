<?php
/**
 * API Proses Absensi V2 - Lokal
 * Lokasi File: petugas/absensi-process-v2.php
 */

ob_start();
ini_set('display_errors', '1'); // Set ke 1 untuk debugging di lokal
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('ABSENSI API FATAL: ' . $err['message']);
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan fatal di server lokal.']);
    }
});

// Sesuaikan path include karena file ini ada di dalam folder /petugas/
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['petugas_id'])) {
    jsonResponse(false, 'Unauthorized. Silakan login ulang.', [], 401);
}

$petugasId = $_SESSION['petugas_id'];

/**
 * Fungsi Simpan Gambar ke Folder Lokal
 */
function saveBase64ToImage($base64String, $petugasId, $prefix) {
    if (empty($base64String)) return null;

    $parts = explode(";base64,", $base64String);
    if (count($parts) < 2) return null;

    $imageData = base64_decode($parts[1]);
    $fileName = $prefix . '_' . $petugasId . '_' . date('Ymd_His') . '.jpg';
    
    // Path: Mundur satu folder ke root, lalu masuk ke assets/img/absensi/
    $targetPath = "../assets/img/absensi/" . $fileName;

    if (file_put_contents($targetPath, $imageData)) {
        return $fileName;
    }
    return null;
}

// Ambil data petugas
$stmtPetugas = $conn->prepare("SELECT bagian_id, titik_lokasi_id, shift_id FROM petugas WHERE id = ?");
$stmtPetugas->bind_param("i", $petugasId);
$stmtPetugas->execute();
$petugasData = stmtFetchAssoc($stmtPetugas);
$stmtPetugas->close();

if (!$petugasData) {
    jsonResponse(false, 'Data petugas tidak ditemukan', [], 404);
}

// Parse Input
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$tipe = $input['tipe'] ?? ''; 
$bagianId = (int)($input['bagian_id'] ?? $petugasData['bagian_id']);
$shiftId = (int)($input['shift_id'] ?? $petugasData['shift_id']);
$latitude = (float)($input['latitude'] ?? 0);
$longitude = (float)($input['longitude'] ?? 0);
$laporan = sanitize($input['laporan'] ?? '');

if (empty($tipe) || !in_array($tipe, ['masuk', 'keluar'])) {
    jsonResponse(false, 'Tipe absensi tidak valid', [], 400);
}

$today = date('Y-m-d');
$nowDateTime = date('Y-m-d H:i:s');

try {
    // 1. Validasi Lokasi (Contoh sederhana untuk lokal)
    // Jika kamu ingin bypass validasi lokasi di lokal, bisa beri komentar pada blok validasiLokasi
    $lokasiCheck = validasiLokasi($conn, $latitude, $longitude, $bagianId);
    if (!$lokasiCheck['valid']) {
        jsonResponse(false, $lokasiCheck['message'], [], 400);
    }
    $titikId = $lokasiCheck['titik_id'];

    // 2. Cari Jadwal
    $stmtJadwal = $conn->prepare("SELECT id FROM jadwal_petugas WHERE petugas_id = ? AND tanggal = ? LIMIT 1");
    $stmtJadwal->bind_param("is", $petugasId, $today);
    $stmtJadwal->execute();
    $jadwalRow = stmtFetchAssoc($stmtJadwal);
    $stmtJadwal->close();

    if (!$jadwalRow) jsonResponse(false, 'Jadwal tidak ditemukan.', [], 400);
    $jadwalId = $jadwalRow['id'];

    // 3. Cek Absensi Existing
    $stmt = $conn->prepare("SELECT id, jam_masuk, jam_keluar FROM absensi WHERE petugas_id = ? AND tanggal = ? AND jadwal_id = ?");
    $stmt->bind_param("isi", $petugasId, $today, $jadwalId);
    $stmt->execute();
    $existing = stmtFetchAssoc($stmt);
    $stmt->close();

    // --- PROSES MASUK ---
    if ($tipe === 'masuk') {
        if ($existing) jsonResponse(false, 'Sudah absen masuk.', [], 400);
//lio-ubah ini untuk konversi data foto dari base64 ke file gambar di server lokal        
        $namaFile = saveBase64ToImage($input['foto'] ?? '', $petugasId, 'MASUK');
        if (!$namaFile) jsonResponse(false, 'Gagal menyimpan foto masuk.', [], 400);

        $stmt = $conn->prepare("INSERT INTO absensi (petugas_id, bagian_id, jadwal_id, tanggal, jam_masuk, latitude, longitude, titik_masuk_id, foto_absen, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'absen masuk')");
        $stmt->bind_param("iiissddis", $petugasId, $bagianId, $jadwalId, $today, $nowDateTime, $latitude, $longitude, $titikId, $namaFile);
        $stmt->execute();

        jsonResponse(true, 'Berhasil Absen Masuk!', ['jam' => $nowDateTime]);
    }

    // --- PROSES KELUAR ---
    if ($tipe === 'keluar') {
        if (!$existing) jsonResponse(false, 'Belum absen masuk.', [], 400);
        if ($existing['jam_keluar']) jsonResponse(false, 'Sudah absen keluar.', [], 400);

        if (empty($laporan)) jsonResponse(false, 'Isi laporan kegiatan dahulu.', [], 400);

        $namaFileKeluar = saveBase64ToImage($input['foto'] ?? '', $petugasId, 'KELUAR');
        if (!$namaFileKeluar) jsonResponse(false, 'Gagal menyimpan foto keluar.', [], 400);

        // Update Absensi & Simpan Laporan
        $conn->begin_transaction();
        
        $stmtU = $conn->prepare("UPDATE absensi SET jam_keluar = ?, foto_keluar = ?, status = 'hadir' WHERE id = ?");
        $stmtU->bind_param("ssi", $nowDateTime, $namaFileKeluar, $existing['id']);
        $stmtU->execute();

        $stmtL = $conn->prepare("INSERT INTO laporan_harian (absensi_id, kegiatan_harian) VALUES (?, ?)");
        $stmtL->bind_param("is", $existing['id'], $laporan);
        $stmtL->execute();

        $conn->commit();
        jsonResponse(true, 'Berhasil Absen Keluar!', ['jam' => $nowDateTime]);
    }

} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    jsonResponse(false, 'Error: ' . $e->getMessage(), [], 500);
}