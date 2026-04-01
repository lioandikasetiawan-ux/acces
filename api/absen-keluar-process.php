<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

date_default_timezone_set('Asia/Jakarta');

/**
 * Cek apakah request mengharapkan JSON
 */
function isJsonRequest(): bool {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    return (stripos($accept, 'application/json') !== false) || (strtolower($xrw) === 'xmlhttprequest');
}

/**
 * Helper untuk response error (Alert/JSON)
 */
function respondError($message, $redirect = null, $httpCode = 400) {
    if (isJsonRequest()) {
        if (function_exists('jsonResponse')) {
            jsonResponse(false, $message, [], $httpCode);
        } else {
            http_response_code($httpCode);
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
    }
    $redir = $redirect ?: '../petugas/dashboard-v2.php';
    echo "<script>alert(" . json_encode($message) . "); window.location.href=" . json_encode($redir) . ";</script>";
    exit;
}

/**
 * Mencari kolom yang tersedia di tabel (untuk fleksibilitas struktur DB)
 */
function pickFirstExistingColumn($conn, $table, $candidates) {
    foreach ($candidates as $col) {
        $stmt = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        $stmt->bind_param('ss', $table, $col);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($exists) return $col;
    }
    return null;
}

// 1. Keamanan Dasar
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Method not allowed', '../petugas/dashboard-v2.php', 405);
}

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['petugas', 'admin'], true)) {
    respondError('Unauthorized. Silakan login ulang.', '../auth/login-v2.php', 401);
}

$petugasId = isset($_SESSION['petugas_id']) ? (int)$_SESSION['petugas_id'] : (int)($_SESSION['user_id'] ?? 0);

// 2. Ambil & Validasi Input
$input = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $input = $_POST;
}

$latitude  = isset($input['latitude']) ? (float)$input['latitude'] : 0;
$longitude = isset($input['longitude']) ? (float)$input['longitude'] : 0;
$fotoRaw   = isset($input['foto']) ? trim($input['foto']) : '';

if ($latitude == 0 || $longitude == 0) respondError('Koordinat tidak valid. Pastikan GPS aktif.');
if (empty($fotoRaw)) respondError('Foto absen keluar wajib diambil.');

// 3. Proses Foto (Base64 to File)
$namaFileKeluar = "";
if (preg_match('/^data:image\/(\w+);base64,/', $fotoRaw, $type)) {
    $dataFoto = base64_decode(substr($fotoRaw, strpos($fotoRaw, ',') + 1));
    if ($dataFoto === false) respondError("Format foto rusak.");

    $uploadDir = '../uploads/absensi/';
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

    $namaFileKeluar = 'keluar_' . $petugasId . '_' . time() . '.jpg';
    if (!file_put_contents($uploadDir . $namaFileKeluar, $dataFoto)) {
        respondError("Gagal menyimpan foto ke server.");
    }
} else {
    $namaFileKeluar = $fotoRaw; 
}

// 4. Cek Jadwal Hari Ini
$today = date('Y-m-d');
$stmtJadwal = $conn->prepare("
    SELECT jp.id as jadwal_id, s.mulai_keluar, s.akhir_keluar 
    FROM jadwal_petugas jp 
    JOIN shift s ON jp.shift_id = s.id 
    WHERE jp.petugas_id = ? AND jp.tanggal = ? LIMIT 1
");
$stmtJadwal->bind_param('is', $petugasId, $today);
$stmtJadwal->execute();
$jadwal = $stmtJadwal->get_result()->fetch_assoc();
$stmtJadwal->close();

if (!$jadwal) respondError('Anda tidak memiliki jadwal tugas untuk hari ini.');

// 5. Validasi Jam Keluar (Window Time)
$currentTime = date('H:i:s');
$mulai = $jadwal['mulai_keluar'];
$akhir = $jadwal['akhir_keluar'];
if ($mulai && $akhir) {
    $isInside = ($akhir >= $mulai) 
                ? ($currentTime >= $mulai && $currentTime <= $akhir)
                : ($currentTime >= $mulai || $currentTime <= $akhir); // Handle shift lewat tengah malam
    if (!$isInside) respondError("Absen keluar hanya diizinkan pada jam {$mulai} s/d {$akhir}.");
}

// 6. Cek Status Absensi (Harus sudah Masuk & Belum Keluar)
$stmtCek = $conn->prepare('SELECT id, jam_masuk, jam_keluar FROM absensi WHERE petugas_id = ? AND tanggal = ? LIMIT 1');
$stmtCek->bind_param('is', $petugasId, $today);
$stmtCek->execute();
$existing = $stmtCek->get_result()->fetch_assoc();
$stmtCek->close();

if (!$existing || empty($existing['jam_masuk'])) respondError('Anda belum melakukan absen masuk!');
if (!empty($existing['jam_keluar'])) respondError('Anda sudah melakukan absen keluar hari ini.');

$absensiId = (int)$existing['id'];

// 7. Wajib Laporan Harian (Business Logic)
$cekLaporan = $conn->prepare('SELECT id FROM laporan_harian WHERE absensi_id = ? LIMIT 1');
$cekLaporan->bind_param('i', $absensiId);
$cekLaporan->execute();
$laporan = $cekLaporan->get_result()->fetch_assoc();
$cekLaporan->close();

if (!$laporan) {
    respondError('Wajib mengisi Laporan Kegiatan sebelum Absen Keluar!', '../petugas/laporan-harian.php');
}

// 8. Validasi Radius Lokasi
$stmtLokasi = $conn->prepare("
    SELECT bk.id, bk.latitude, bk.longitude, bk.radius_meter 
    FROM jadwal_lokasi jl 
    JOIN bagian_koordinat bk ON jl.bagian_koordinat_id = bk.id 
    WHERE jl.jadwal_id = ?
");
$stmtLokasi->bind_param('i', $jadwal['jadwal_id']);
$stmtLokasi->execute();
$lokasiJadwal = $stmtLokasi->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtLokasi->close();

$titikId = null;
foreach ($lokasiJadwal as $lok) {
    $dist = haversineDistance($latitude, $longitude, $lok['latitude'], $lok['longitude']);
    if ($dist <= (float)$lok['radius_meter']) {
        $titikId = (int)$lok['id'];
        break;
    }
}
if (!$titikId) respondError('Posisi Anda di luar radius lokasi tugas.');

// 9. Update Database
$now = date('Y-m-d H:i:s');
$colLat  = pickFirstExistingColumn($conn, 'absensi', ['latitude_keluar', 'lat_keluar']) ?: 'latitude_keluar';
$colLong = pickFirstExistingColumn($conn, 'absensi', ['longitude_keluar', 'long_keluar']) ?: 'longitude_keluar';

$sql = "UPDATE absensi SET 
            jam_keluar = ?, 
            {$colLat} = ?, 
            {$colLong} = ?, 
            titik_keluar_id = ?, 
            foto_keluar = ?, 
            status = 'hadir' 
        WHERE id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param('sddisi', $now, $latitude, $longitude, $titikId, $namaFileKeluar, $absensiId);

if ($stmt->execute()) {
    $stmt->close();
    if (isJsonRequest()) {
        echo json_encode(['success' => true, 'message' => 'Absen keluar berhasil!']);
        exit;
    }
    echo "<script>alert('Absen keluar berhasil!'); window.location.href='../petugas/dashboard-v2.php';</script>";
} else {
    respondError('Gagal menyimpan data: ' . $conn->error);
}