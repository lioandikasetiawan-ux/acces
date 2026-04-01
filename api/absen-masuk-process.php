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
    // NOTE: avoid `SHOW COLUMNS ... LIKE ?` which may fail on some MySQL/MariaDB versions
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

// Enable error reporting untuk debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Method not allowed', '../petugas/dashboard-v2.php', 405);
}

// Allow petugas atau admin dengan bagian_id
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['petugas', 'admin'])) {
    respondError('Unauthorized. Silakan login ulang.', '../auth/login-v2.php', 401);
}

// Untuk admin, gunakan user_id sebagai petugas_id
$petugasId = isset($_SESSION['petugas_id']) ? (int)$_SESSION['petugas_id'] : (int)$_SESSION['user_id'];

// Support JSON request body dari dashboard-v2.php
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
    respondError('Foto absen masuk wajib diambil.');
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
$stmtJadwal = $conn->prepare("SELECT jp.id, jp.shift_id, s.nama_shift, s.mulai_masuk, s.akhir_masuk 
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

// Validasi jam absen sesuai shift
$currentTime = date('H:i:s');
$mulai = $jadwal['mulai_masuk'];
$akhir = $jadwal['akhir_masuk'];

if ($mulai && $akhir) {
    $canMasuk = false;
    if ($akhir >= $mulai) {
        $canMasuk = ($currentTime >= $mulai && $currentTime <= $akhir);
    } else {
        $canMasuk = ($currentTime >= $mulai || $currentTime <= $akhir);
    }
    
    if (!$canMasuk) {
        respondError("Absen masuk hanya bisa dilakukan antara jam {$mulai} - {$akhir}.");
    }
}

// Cek apakah sudah absen masuk hari ini
$stmtCek = $conn->prepare('SELECT id FROM absensi WHERE petugas_id = ? AND tanggal = ? LIMIT 1');
$stmtCek->bind_param('is', $petugasId, $today);
$stmtCek->execute();
$existing = stmtFetchAssoc($stmtCek);
$stmtCek->close();

if ($existing) {
    respondError('Anda sudah absen masuk hari ini.');
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

$colFotoMasuk = pickFirstExistingColumn($conn, 'absensi', ['foto_absen', 'foto_masuk']);
$colLatMasuk = pickFirstExistingColumn($conn, 'absensi', ['latitude', 'lat_masuk']);
$colLongMasuk = pickFirstExistingColumn($conn, 'absensi', ['longitude', 'long_masuk']);

if ($colFotoMasuk === null || $colLatMasuk === null || $colLongMasuk === null) {
    respondError('Konfigurasi database absensi tidak sesuai (kolom foto/latitude/longitude tidak ditemukan).', null, 500);
}

// Cek apakah kolom titik_masuk_id ada
$hasTitikMasukId = hasColumn($conn, 'absensi', 'titik_masuk_id');

// Build query dinamis
$columns = "petugas_id, bagian_id, tanggal, jadwal_id, jam_masuk, {$colLatMasuk}, {$colLongMasuk}";
$placeholders = "?, ?, ?, ?, ?, ?, ?";
$types = 'iisisdd';
$params = [$petugasId, $bagianId, $today, $jadwalId, $nowDateTime, $latitude, $longitude];

if ($hasTitikMasukId) {
    $columns .= ", titik_masuk_id";
    $placeholders .= ", ?";
    $types .= 'i';
    $params[] = $titikId;
}

$columns .= ", {$colFotoMasuk}, status";
$placeholders .= ", ?, 'absen masuk'";
$types .= 's';
$params[] = $foto;

$sql = "INSERT INTO absensi ({$columns}) VALUES ({$placeholders})";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    respondError('Gagal prepare statement: ' . $conn->error . ' | SQL: ' . $sql, null, 500);
}

$stmt->bind_param($types, ...$params);

if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    // Debug info
    $debugInfo = [
        'sql' => $sql,
        'types' => $types,
        'params_count' => count($params),
        'error' => $err
    ];
    respondError('Gagal menyimpan absensi: ' . $err . ' | Debug: ' . json_encode($debugInfo), null, 500);
}

$absensiId = $conn->insert_id;
$stmt->close();

if (isJsonRequest()) {
    jsonResponse(true, 'Absen masuk berhasil!', [
        'absensi_id' => $absensiId,
        'shift_id' => $shiftId,
        'bagian_id' => $bagianId
    ]);
}

echo "<script>alert('Absen masuk berhasil!'); window.location.href='../petugas/dashboard-v2.php';</script>";
exit;

} catch (Exception $e) {
    // Tangkap semua error dan tampilkan detail lengkap
    $errorDetail = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    
    respondError('ERROR DETAIL: ' . json_encode($errorDetail, JSON_PRETTY_PRINT), null, 500);
} catch (Error $e) {
    // Tangkap fatal error
    $errorDetail = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    
    respondError('FATAL ERROR: ' . json_encode($errorDetail, JSON_PRETTY_PRINT), null, 500);
}
