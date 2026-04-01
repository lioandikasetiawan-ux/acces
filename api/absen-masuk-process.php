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
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
    );
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->store_result();
    $ok = $stmt->num_rows > 0;
    $stmt->close();
    return $ok;
}

function pickFirstExistingColumn($conn, $table, $candidates) {
    foreach ($candidates as $col) {
        if (hasColumn($conn, $table, $col)) return $col;
    }
    return null;
}

// Debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respondError('Method not allowed', '../petugas/dashboard-v2.php', 405);
    }

    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['petugas', 'admin'])) {
        respondError('Unauthorized. Silakan login ulang.', '../auth/login-v2.php', 401);
    }

    $petugasId = isset($_SESSION['petugas_id']) ? (int)$_SESSION['petugas_id'] : (int)$_SESSION['user_id'];

    // Ambil Input
    $input = [];
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $input = $_POST;
    }

    $latitude = isset($input['latitude']) ? (float)$input['latitude'] : 0;
    $longitude = isset($input['longitude']) ? (float)$input['longitude'] : 0;
    $fotoRaw = isset($input['foto']) ? trim($input['foto']) : '';

    if ($latitude == 0 || $longitude == 0) respondError('Koordinat tidak valid. Aktifkan GPS.');
    if (empty($fotoRaw)) respondError('Foto absen masuk wajib diambil.');

    // --- LOGIKA BARU: PROSES FOTO (Agar DB tidak bengkak) ---
    $namaFileFinal = "";
    if (preg_match('/^data:image\/(\w+);base64,/', $fotoRaw, $type)) {
        $dataFoto = substr($fotoRaw, strpos($fotoRaw, ',') + 1);
        $dataFoto = base64_decode($dataFoto);
        
        if ($dataFoto === false) respondError("Format foto rusak.");

        // Tentukan folder & nama file unik
        $uploadDir = '../uploads/absensi/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

        $namaFileFinal = 'masuk_' . $petugasId . '_' . time() . '.jpg';
        $pathFile = $uploadDir . $namaFileFinal;

        // Simpan file fisik ke storage server
        if (!file_put_contents($pathFile, $dataFoto)) {
            respondError("Gagal menyimpan file foto ke server.");
        }
    } else {
        // Jika input bukan base64 (mungkin sudah nama file atau format salah)
        $namaFileFinal = $fotoRaw; 
    }
    // -------------------------------------------------------

    $bagianId = isset($_SESSION['bagian_id']) ? (int)$_SESSION['bagian_id'] : 0;
    if ($bagianId <= 0) {
        $stmt = $conn->prepare('SELECT bagian_id FROM petugas WHERE id = ?');
        $stmt->bind_param('i', $petugasId);
        $stmt->execute();
        $row = stmtFetchAssoc($stmt);
        $stmt->close();
        $bagianId = isset($row['bagian_id']) ? (int)$row['bagian_id'] : 0;
    }
    if ($bagianId <= 0) respondError('Bagian petugas tidak ditemukan.');

    $today = date('Y-m-d');

    // Cek Jadwal & Shift
    $stmtJadwal = $conn->prepare("SELECT jp.id, jp.shift_id, s.nama_shift, s.mulai_masuk, s.akhir_masuk 
                                   FROM jadwal_petugas jp 
                                   LEFT JOIN shift s ON jp.shift_id = s.id 
                                   WHERE jp.petugas_id = ? AND jp.tanggal = ? LIMIT 1");
    $stmtJadwal->bind_param('is', $petugasId, $today);
    $stmtJadwal->execute();
    $jadwal = stmtFetchAssoc($stmtJadwal);
    $stmtJadwal->close();

    if (!$jadwal) respondError('Anda tidak memiliki jadwal hari ini.');

    $jadwalId = (int)$jadwal['id'];
    $shiftId = (int)$jadwal['shift_id'];

    // Validasi Jam Absen
    $currentTime = date('H:i:s');
    $mulai = $jadwal['mulai_masuk'];
    $akhir = $jadwal['akhir_masuk'];
    if ($mulai && $akhir) {
        $canMasuk = ($akhir >= $mulai) 
                    ? ($currentTime >= $mulai && $currentTime <= $akhir)
                    : ($currentTime >= $mulai || $currentTime <= $akhir);
        if (!$canMasuk) respondError("Absen masuk hanya jam {$mulai} - {$akhir}.");
    }

    // Cek Double Absen
    $stmtCek = $conn->prepare('SELECT id FROM absensi WHERE petugas_id = ? AND tanggal = ? LIMIT 1');
    $stmtCek->bind_param('is', $petugasId, $today);
    $stmtCek->execute();
    $existing = stmtFetchAssoc($stmtCek);
    $stmtCek->close();
    if ($existing) respondError('Anda sudah absen masuk hari ini.');

    // Validasi Radius Lokasi
    $stmtLokasi = $conn->prepare("SELECT bk.id, bk.nama_titik, bk.latitude, bk.longitude, bk.radius_meter 
                                   FROM jadwal_lokasi jl 
                                   JOIN bagian_koordinat bk ON jl.bagian_koordinat_id = bk.id 
                                   WHERE jl.jadwal_id = ?");
    $stmtLokasi->bind_param('i', $jadwalId);
    $stmtLokasi->execute();
    $lokasiJadwal = stmtFetchAllAssoc($stmtLokasi);
    $stmtLokasi->close();

    $titikId = null;
    $lokasiValid = false;
    foreach ($lokasiJadwal as $lok) {
        if (haversineDistance($latitude, $longitude, $lok['latitude'], $lok['longitude']) <= $lok['radius_meter']) {
            $titikId = (int)$lok['id'];
            $lokasiValid = true;
            break;
        }
    }
    if (!$lokasiValid) respondError('Anda berada di luar radius lokasi.');

    // Persiapan Simpan ke DB
    $nowDateTime = date('Y-m-d H:i:s');
    $colFotoMasuk = pickFirstExistingColumn($conn, 'absensi', ['foto_absen', 'foto_masuk']);
    $colLatMasuk = pickFirstExistingColumn($conn, 'absensi', ['latitude', 'lat_masuk']);
    $colLongMasuk = pickFirstExistingColumn($conn, 'absensi', ['longitude', 'long_masuk']);
    $hasTitikId = hasColumn($conn, 'absensi', 'titik_masuk_id');

    $columns = "petugas_id, bagian_id, tanggal, jadwal_id, jam_masuk, {$colLatMasuk}, {$colLongMasuk}";
    $placeholders = "?, ?, ?, ?, ?, ?, ?";
    $types = 'iisisdd';
    $params = [$petugasId, $bagianId, $today, $jadwalId, $nowDateTime, $latitude, $longitude];

    if ($hasTitikId) {
        $columns .= ", titik_masuk_id";
        $placeholders .= ", ?";
        $types .= 'i';
        $params[] = $titikId;
    }

    // Masukkan NAMA FILE (bukan base64) ke kolom foto
    $columns .= ", {$colFotoMasuk}, status";
    $placeholders .= ", ?, 'absen masuk'";
    $types .= 's';
    $params[] = $namaFileFinal;

    $sql = "INSERT INTO absensi ({$columns}) VALUES ({$placeholders})";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);

    if (!$stmt->execute()) {
        respondError('Gagal menyimpan: ' . $stmt->error);
    }

    $absensiId = $conn->insert_id;
    $stmt->close();

    if (isJsonRequest()) {
        jsonResponse(true, 'Absen masuk berhasil!', ['absensi_id' => $absensiId]);
    }
    echo "<script>alert('Absen masuk berhasil!'); window.location.href='../petugas/dashboard-v2.php';</script>";
    exit;

} catch (Exception $e) {
    respondError($e->getMessage());
}