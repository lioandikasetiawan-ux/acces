<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

date_default_timezone_set('Asia/Jakarta');

function isJsonRequest(): bool {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    return (stripos($accept, 'application/json') !== false) || (strtolower($xrw) === 'xmlhttprequest');
}

function respondError($message, $redirect = null, $httpCode = 400) {
    if (isJsonRequest()) {
        jsonResponse(false, $message, [], $httpCode);
    }

    $redir = $redirect ?: '../petugas/dashboard-v2.php';
    echo "<script>alert(" . json_encode($message) . "); window.location.href=" . json_encode($redir) . ";</script>";
    exit;
}

function hasColumn($conn, $table, $column): bool {
    $stmt = $conn->prepare(
        "SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->store_result();
    $ok = $stmt->num_rows > 0;
    $stmt->close();
    return $ok;
}

function pickFirstExistingColumn($conn, $table, $candidates) {
    foreach ($candidates as $col) {
        if (hasColumn($conn, $table, $col)) {
            return $col;
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Method not allowed', '../petugas/dashboard-v2.php', 405);
}

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['petugas', 'admin'], true)) {
    respondError('Unauthorized. Silakan login ulang.', '../auth/login-v2.php', 401);
}

$petugasId = isset($_SESSION['petugas_id']) ? (int)$_SESSION['petugas_id'] : (int)($_SESSION['user_id'] ?? 0);

$input = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?? [];
} else {
    $input = $_POST;
}

$latitude = isset($input['latitude']) ? (float)$input['latitude'] : 0;
$longitude = isset($input['longitude']) ? (float)$input['longitude'] : 0;
$foto = isset($input['foto']) ? trim($input['foto']) : '';

if ($latitude == 0 || $longitude == 0) {
    respondError('Koordinat lokasi tidak valid. Aktifkan GPS.');
}

if ($foto === '') {
    respondError('Foto absen keluar wajib diambil.');
}

$bagianId = isset($_SESSION['bagian_id']) ? (int)$_SESSION['bagian_id'] : 0;
if ($bagianId <= 0) {
    $stmt = $conn->prepare('SELECT bagian_id FROM petugas WHERE id = ?');
    $stmt->bind_param('i', $petugasId);
    $stmt->execute();
    $row = stmtFetchAssoc($stmt);
    $stmt->close();
    $bagianId = isset($row['bagian_id']) ? (int)$row['bagian_id'] : 0;
}

if ($bagianId <= 0) {
    respondError('Bagian petugas tidak ditemukan.');
}

$today = date('Y-m-d');

// Cek jadwal petugas hari ini
$stmtJadwal = $conn->prepare("SELECT jp.id, jp.shift_id, s.nama_shift, s.mulai_keluar, s.akhir_keluar 
                               FROM jadwal_petugas jp 
                               LEFT JOIN shift s ON jp.shift_id = s.id 
                               WHERE jp.petugas_id = ? AND jp.tanggal = ? LIMIT 1");
$stmtJadwal->bind_param('is', $petugasId, $today);
$stmtJadwal->execute();
$jadwal = stmtFetchAssoc($stmtJadwal);
$stmtJadwal->close();

if (!$jadwal) {
    respondError('Anda tidak memiliki jadwal untuk hari ini. Hubungi admin.');
}

$jadwalId = (int)$jadwal['id'];
$shiftId = (int)$jadwal['shift_id'];

if ($shiftId <= 0) {
    respondError('Jadwal Anda belum memiliki shift. Hubungi admin.');
}

// Validasi jam absen keluar sesuai shift
$currentTime = date('H:i:s');
$mulai = $jadwal['mulai_keluar'];
$akhir = $jadwal['akhir_keluar'];

if ($mulai && $akhir) {
    $canKeluar = false;
    if ($akhir >= $mulai) {
        $canKeluar = ($currentTime >= $mulai && $currentTime <= $akhir);
    } else {
        $canKeluar = ($currentTime >= $mulai || $currentTime <= $akhir);
    }
    
    if (!$canKeluar) {
        respondError("Absen keluar hanya bisa dilakukan antara jam {$mulai} - {$akhir}.");
    }
}

// Cek apakah sudah absen masuk hari ini
$stmtCek = $conn->prepare('SELECT id, jam_masuk, jam_keluar, foto_keluar FROM absensi WHERE petugas_id = ? AND tanggal = ? LIMIT 1');
$stmtCek->bind_param('is', $petugasId, $today);
$stmtCek->execute();
$existing = stmtFetchAssoc($stmtCek);
$stmtCek->close();

// BLOCKING: Tidak bisa absen keluar jika belum absen masuk
if (!$existing || empty($existing['jam_masuk'])) {
    respondError('Anda belum absen masuk hari ini. Silakan absen masuk terlebih dahulu!');
}

if (!empty($existing['jam_keluar'])) {
    respondError('Anda sudah absen keluar hari ini.');
}

if (!empty($existing['foto_keluar'])) {
    respondError('Foto absen keluar sudah tersimpan. Tidak bisa absen keluar ulang.');
}

$absensiId = (int)$existing['id'];

$cekLaporan = $conn->prepare('SELECT id FROM laporan_harian WHERE absensi_id = ? LIMIT 1');
$cekLaporan->bind_param('i', $absensiId);
$cekLaporan->execute();
$laporan = stmtFetchAssoc($cekLaporan);
$cekLaporan->close();

if (!$laporan) {
    respondError('Anda wajib mengisi Laporan Kegiatan sebelum Absen Keluar!', '../petugas/laporan-harian.php');
}

// Validasi lokasi sesuai jadwal_lokasi (multi-lokasi support)
$stmtLokasi = $conn->prepare("SELECT bk.id, bk.nama_titik, bk.latitude, bk.longitude, bk.radius_meter 
                               FROM jadwal_lokasi jl 
                               JOIN bagian_koordinat bk ON jl.bagian_koordinat_id = bk.id 
                               WHERE jl.jadwal_id = ?");
$stmtLokasi->bind_param('i', $jadwalId);
$stmtLokasi->execute();
$lokasiJadwal = stmtFetchAllAssoc($stmtLokasi);
$stmtLokasi->close();

if (empty($lokasiJadwal)) {
    respondError('Jadwal Anda belum memiliki lokasi yang ditentukan. Hubungi admin.');
}

// Cek apakah koordinat petugas berada di salah satu lokasi yang diizinkan
$titikId = null;
$lokasiValid = false;
foreach ($lokasiJadwal as $lok) {
    $distance = haversineDistance($latitude, $longitude, $lok['latitude'], $lok['longitude']);
    if ($distance <= $lok['radius_meter']) {
        $titikId = (int)$lok['id'];
        $lokasiValid = true;
        break;
    }
}

if (!$lokasiValid) {
    $namaLokasi = array_map(fn($l) => $l['nama_titik'], $lokasiJadwal);
    respondError('Anda berada di luar radius lokasi yang diizinkan: ' . implode(', ', $namaLokasi));
}

$nowDateTime = date('Y-m-d H:i:s');

$colLatKeluar = pickFirstExistingColumn($conn, 'absensi', ['latitude_keluar', 'lat_keluar']);
$colLongKeluar = pickFirstExistingColumn($conn, 'absensi', ['longitude_keluar', 'long_keluar']);

if ($colLatKeluar === null || $colLongKeluar === null) {
    respondError('Konfigurasi database absensi tidak sesuai (kolom latitude_keluar/longitude_keluar tidak ditemukan).', null, 500);
}

$sql = "UPDATE absensi SET jam_keluar = ?, {$colLatKeluar} = ?, {$colLongKeluar} = ?, titik_keluar_id = ?, foto_keluar = ?, status = 'hadir' WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('sddisi', $nowDateTime, $latitude, $longitude, $titikId, $foto, $absensiId);

if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    respondError('Gagal menyimpan absen keluar: ' . $err, null, 500);
}
$stmt->close();

if (isJsonRequest()) {
    jsonResponse(true, 'Absen keluar berhasil!', [
        'absensi_id' => $absensiId,
        'shift_id' => $shiftId,
        'bagian_id' => $bagianId
    ]);
}

echo "<script>alert('Absen keluar berhasil!'); window.location.href='../petugas/dashboard-v2.php';</script>";
exit;