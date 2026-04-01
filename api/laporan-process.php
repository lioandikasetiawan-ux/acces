<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/image-helper.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../petugas/dashboard-v2.php');
    exit;
}

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], array('petugas', 'admin'), true)) {
    header('Location: ../auth/login-v2.php');
    exit;
}

$petugas_id = isset($_SESSION['petugas_id'])
    ? (int)$_SESSION['petugas_id']
    : (int)($_SESSION['user_id'] ?? 0);

if ($petugas_id <= 0) {
    header('Location: ../auth/login-v2.php');
    exit;
}

// --- Input ---
$absensi_id = (int)($_POST['absensi_id'] ?? 0);
$kegiatan   = trim($_POST['kegiatan'] ?? '');
$kode_sync  = (int)($_POST['kode_sync'] ?? ($_SESSION['kode_sync'] ?? 0));
$latitude   = $_POST['latitude'] ?? null;
$longitude  = $_POST['longitude'] ?? null;

// Validasi geolokasi
if (empty($latitude) || empty($longitude)) {
    $_SESSION['error_message'] = 'Lokasi (lintang dan bujur) wajib diambil sebelum mengirim laporan!';
    header('Location: ../petugas/laporan-harian.php');
    exit;
}

// Field kegiatan harian kategori (hanya kode_sync 1/5/6)
$kegiatan_kategori = trim($_POST['kegiatan_harian_kategori'] ?? '');
$kegiatan_lainnya = trim($_POST['kegiatan_harian_lainnya'] ?? '');
$allowedKategori = ['Pemantauan', 'Pelaporan', 'Koordinasi', 'Kegiatan Lainnya'];

if (in_array($kode_sync, [1, 5, 6])) {
    if ($kegiatan_kategori === '' || !in_array($kegiatan_kategori, $allowedKategori)) {
        $_SESSION['error_message'] = 'Pilih salah satu Kegiatan Harian!';
        header('Location: ../petugas/laporan-harian.php');
        exit;
    }
    if ($kegiatan_kategori === 'Kegiatan Lainnya' && $kegiatan_lainnya === '') {
        $_SESSION['error_message'] = 'Keterangan Kegiatan Lainnya wajib diisi!';
        header('Location: ../petugas/laporan-harian.php');
        exit;
    }
}

// Field khusus per kode_sync
$air = null;
$tma = null;
$kondisi_saluran = null;
$bangunan_air = null;
$status_gulma = null;

if ($kode_sync == 1) {
    $air = trim($_POST['ketersediaan_air'] ?? '');
    if ($air === '') {
        $_SESSION['error_message'] = 'Status Air wajib dipilih!';
        header('Location: ../petugas/laporan-harian.php');
        exit;
    }
}

if ($kode_sync == 6) {
    $air = trim($_POST['ketersediaan_air'] ?? '');
    if ($air === '') {
        $_SESSION['error_message'] = 'Status Air wajib dipilih!';
        header('Location: ../petugas/laporan-harian.php');
        exit;
    }

    $tmaInput = isset($_POST['tma']) ? trim($_POST['tma']) : '';
    if ($tmaInput !== '') {
        $tmaInput = str_replace(',', '.', $tmaInput);
        if (!is_numeric($tmaInput)) {
            $_SESSION['error_message'] = 'TMA tidak valid. Gunakan angka (contoh: 1.25)';
            header('Location: ../petugas/laporan-harian.php');
            exit;
        }
        $tma = (float) $tmaInput;
        if ($tma > 999.99) {
            $_SESSION['error_message'] = 'Nilai TMA terlalu besar (maks 999.99 m).';
            header('Location: ../petugas/laporan-harian.php');
            exit;
        }
    }

    $status_gulma = trim($_POST['status_gulma'] ?? '');
    if ($status_gulma === '' || !in_array($status_gulma, ['Ada', 'Tidak Ada'])) {
        $_SESSION['error_message'] = 'Status Gulma wajib dipilih!';
        header('Location: ../petugas/laporan-harian.php');
        exit;
    }
}

if ($kode_sync == 2) {
    $tmaInput = isset($_POST['tma']) ? trim($_POST['tma']) : '';
    if ($tmaInput !== '') {
        $tmaInput = str_replace(',', '.', $tmaInput);
        if (!is_numeric($tmaInput)) {
            $_SESSION['error_message'] = 'TMA tidak valid.';
            header('Location: ../petugas/laporan-harian.php'); exit;
        }
        $tma = (float) $tmaInput;
    }
    $kondisi_saluran = trim($_POST['kondisi_saluran'] ?? '');
    $bangunan_air = trim($_POST['bangunan_air'] ?? '');
}

// --- Validasi absensi ---
$cek = $conn->prepare("SELECT id, jam_masuk, jam_keluar FROM absensi WHERE id = ? AND petugas_id = ?");
$cek->bind_param("ii", $absensi_id, $petugas_id);
$cek->execute();
$absen = stmtFetchAssoc($cek);
$cek->close();

if (!$absen || empty($absen['jam_masuk']) || !empty($absen['jam_keluar'])) {
    $_SESSION['error_message'] = 'Laporan hanya bisa diisi setelah Absen Masuk dan sebelum Absen Keluar!';
    header('Location: ../petugas/dashboard-v2.php');
    exit;
}

// --- Cek duplikat ---
$cekDupe = $conn->prepare("SELECT id FROM laporan_harian WHERE absensi_id = ? LIMIT 1");
$cekDupe->bind_param("i", $absensi_id);
$cekDupe->execute();
$dupe = stmtFetchAssoc($cekDupe);
$cekDupe->close();

if ($dupe) {
    $_SESSION['error_message'] = 'Laporan kegiatan untuk absensi ini sudah dikirim!';
    header('Location: ../petugas/dashboard-v2.php');
    exit;
}

// --- Foto ---
$fotoField = null;
if (isset($_FILES['foto_laporan']) && $_FILES['foto_laporan']['error'] == 0) {
    $fotoField = 'foto_laporan';
} elseif (isset($_FILES['foto_laporan_camera']) && $_FILES['foto_laporan_camera']['error'] == 0) {
    $fotoField = 'foto_laporan_camera';
}

if ($fotoField === null) {
    $_SESSION['error_message'] = 'Wajib melampirkan foto dokumentasi!';
    header('Location: ../petugas/laporan-harian.php');
    exit;
}

$file_tmp  = $_FILES[$fotoField]['tmp_name'];
$file_type = $_FILES[$fotoField]['type'];
$allowed_types = array('image/jpeg', 'image/jpg', 'image/png');
if (!in_array($file_type, $allowed_types)) {
    $_SESSION['error_message'] = 'Format foto harus JPG atau PNG!';
    header('Location: ../petugas/laporan-harian.php');
    exit;
}

$foto_nama = compressPhotoForMobile($file_tmp);
if ($foto_nama === false) {
    $_SESSION['error_message'] = 'Gagal memproses foto!';
    header('Location: ../petugas/laporan-harian.php');
    exit;
}

// --- Cek kolom opsional di laporan_harian ---
function _hasCol($conn, $col) {
    $r = $conn->query("SHOW COLUMNS FROM laporan_harian LIKE '" . $conn->real_escape_string($col) . "'");
    return ($r && $r->num_rows > 0);
}

$hasLatLong             = _hasCol($conn, 'latitude') && _hasCol($conn, 'longitude');
$hasKetersediaanAir     = _hasCol($conn, 'ketersediaan_air');
$hasTMA                 = _hasCol($conn, 'TMA');
$hasKondisiSaluran      = _hasCol($conn, 'kondisi_saluran');
$hasBangunanAir         = _hasCol($conn, 'bangunan_air');
$hasKegiatanKategori    = _hasCol($conn, 'kegiatan_harian_kategori');
$hasKegiatanLainnya     = _hasCol($conn, 'kegiatan_harian_lainnya');
$hasStatusGulma         = _hasCol($conn, 'status_gulma');

// Auto-create latitude/longitude jika belum ada
if (!$hasLatLong) {
    $conn->query("ALTER TABLE laporan_harian ADD COLUMN latitude DECIMAL(10,8) NULL, ADD COLUMN longitude DECIMAL(11,8) NULL");
    $hasLatLong = _hasCol($conn, 'latitude') && _hasCol($conn, 'longitude');
}

// Auto-create kondisi_saluran & bangunan_air jika belum ada
if (!$hasKondisiSaluran) {
    $conn->query("ALTER TABLE laporan_harian ADD COLUMN kondisi_saluran TEXT NULL");
    $hasKondisiSaluran = _hasCol($conn, 'kondisi_saluran');
}
if (!$hasBangunanAir) {
    $conn->query("ALTER TABLE laporan_harian ADD COLUMN bangunan_air TEXT NULL");
    $hasBangunanAir = _hasCol($conn, 'bangunan_air');
}

// Auto-create kegiatan_harian_kategori jika belum ada
if (!$hasKegiatanKategori) {
    $conn->query("ALTER TABLE laporan_harian ADD COLUMN kegiatan_harian_kategori ENUM('Pemantauan','Pelaporan','Koordinasi','Kegiatan Lainnya') NULL AFTER kegiatan_harian");
    $hasKegiatanKategori = _hasCol($conn, 'kegiatan_harian_kategori');
}

// Auto-create kegiatan_harian_lainnya jika belum ada
if (!$hasKegiatanLainnya) {
    $conn->query("ALTER TABLE laporan_harian ADD COLUMN kegiatan_harian_lainnya TEXT NULL AFTER kegiatan_harian_kategori");
    $hasKegiatanLainnya = _hasCol($conn, 'kegiatan_harian_lainnya');
}

// Auto-create status_gulma jika belum ada
if (!$hasStatusGulma) {
    $conn->query("ALTER TABLE laporan_harian ADD COLUMN status_gulma ENUM('Ada','Tidak Ada') NULL AFTER TMA");
    $hasStatusGulma = _hasCol($conn, 'status_gulma');
}

// --- Build INSERT secara dinamis ---
$cols   = array('absensi_id', 'kegiatan_harian', 'foto_pemantauan');
$places = array('?', '?', '?');
$types  = 'iss';
$vals   = array($absensi_id, $kegiatan, $foto_nama);

// Kegiatan harian kategori (hanya kode_sync 1/5/6)
if (in_array($kode_sync, [1,5,6]) && $hasKegiatanKategori && $kegiatan_kategori !== '') {
    $cols[]   = 'kegiatan_harian_kategori';
    $places[] = '?';
    $types   .= 's';
    $vals[]   = $kegiatan_kategori;
}

// Kegiatan harian lainnya
if (in_array($kode_sync, [1,5,6]) && $hasKegiatanLainnya && $kegiatan_kategori === 'Kegiatan Lainnya') {
    $cols[]   = 'kegiatan_harian_lainnya';
    $places[] = '?';
    $types   .= 's';
    $vals[]   = $kegiatan_lainnya;
}

// Ketersediaan air (kode_sync 1 dan 6)
if (($kode_sync == 1 || $kode_sync == 6) && $hasKetersediaanAir) {
    $cols[]   = 'ketersediaan_air';
    $places[] = '?';
    $types   .= 's';
    $vals[]   = $air;
}

// Field khusus kode_sync 6 (Bendung/Embung)
if ($kode_sync == 6) {
    if ($hasTMA) {
        $cols[]   = 'TMA';
        $places[] = '?';
        $types   .= 'd';
        $vals[]   = $tma;
    }
    if ($hasStatusGulma) {
        $cols[]   = 'status_gulma';
        $places[] = '?';
        $types   .= 's';
        $vals[]   = $status_gulma;
    }
}

// Field khusus kode_sync 2: TMA, kondisi_saluran, bangunan_air
if ($kode_sync == 2) {
    if ($hasTMA && $tma !== null) {
        $cols[]   = 'TMA';
        $places[] = '?';
        $types   .= 'd';
        $vals[]   = $tma;
    }
    if ($hasKondisiSaluran && $kondisi_saluran !== null && $kondisi_saluran !== '') {
        $cols[]   = 'kondisi_saluran';
        $places[] = '?';
        $types   .= 's';
        $vals[]   = $kondisi_saluran;
    }
    if ($hasBangunanAir && $bangunan_air !== null && $bangunan_air !== '') {
        $cols[]   = 'bangunan_air';
        $places[] = '?';
        $types   .= 's';
        $vals[]   = $bangunan_air;
    }
}

if ($hasLatLong) {
    $cols[]   = 'latitude';
    $places[] = '?';
    $types   .= 's';
    $vals[]   = $latitude;

    $cols[]   = 'longitude';
    $places[] = '?';
    $types   .= 's';
    $vals[]   = $longitude;
}

$sql  = "INSERT INTO laporan_harian (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $places) . ")";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    $_SESSION['error_message'] = 'Error Database: ' . $conn->error;
    header('Location: ../petugas/laporan-harian.php');
    exit;
}
$stmt->bind_param($types, ...$vals);

if ($stmt->execute()) {
    $_SESSION['success_message'] = 'Laporan Berhasil Dikirim!';
    header('Location: ../petugas/dashboard-v2.php');
    exit;
} else {
    $_SESSION['error_message'] = 'Error Database: ' . $conn->error;
    header('Location: ../petugas/laporan-harian.php');
    exit;
}