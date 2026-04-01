<?php
file_put_contents(__DIR__ . '/../config/kejadian_process_log.txt', "kejadian-process.php executed at " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);

require_once '../config/database.php';
require_once '../config/session.php';
require_once __DIR__ . '/image-helper.php';

if ($_SESSION['role'] !== 'petugas' && !($_SESSION['role'] === 'admin' && isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] !== NULL)) {
    header("Location: ../auth/login-v2.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $petugas_id = $_SESSION['petugas_id'];
    $deskripsi = htmlspecialchars($_POST['deskripsi']);
    $kode_sync = (int)($_POST['kode_sync'] ?? 0);
    $jenis_laporan = trim($_POST['jenis_laporan'] ?? '');
    $laporan_lainnya_text = trim($_POST['laporan_lainnya_text'] ?? '');
    $jenis_kerusakan_bangunan = trim($_POST['jenis_kerusakan_bangunan'] ?? '');
    $latitude  = $_POST['latitude'] ?? null;
    $longitude = $_POST['longitude'] ?? null;

    // Validasi lokasi wajib diisi
    if (empty($latitude) || empty($longitude)) {
        $_SESSION['error_message'] = 'Lokasi GPS wajib diaktifkan! Refresh halaman dan izinkan akses lokasi.';
        header('Location: ../petugas/kejadian.php');
        exit;
    }

    $fotoField = null;
    if (isset($_FILES['foto_kejadian']) && $_FILES['foto_kejadian']['error'] == 0) {
        $fotoField = 'foto_kejadian';
    } else if (isset($_FILES['foto_kejadian_camera']) && $_FILES['foto_kejadian_camera']['error'] == 0) {
        $fotoField = 'foto_kejadian_camera';
    }

    if ($fotoField === null) {
        $_SESSION['error_message'] = 'Wajib menyertakan bukti foto kejadian!';
        header('Location: ../petugas/kejadian.php');
        exit;
    }

    $file_tmp = $_FILES[$fotoField]['tmp_name'];
    $file_type = $_FILES[$fotoField]['type'];
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];

    if (!in_array($file_type, $allowed_types)) {
        $_SESSION['error_message'] = 'Format foto harus JPG atau PNG!';
        header('Location: ../petugas/kejadian.php');
        exit;
    }

    // --- PROSES KOMPRESI & RESIZE GAMBAR ---
    $image = null;
    if ($file_type == 'image/jpeg' || $file_type == 'image/jpg') {
        $image = @imagecreatefromjpeg($file_tmp);
    } elseif ($file_type == 'image/png') {
        $image = @imagecreatefrompng($file_tmp);
    }

    if ($image === false) {
        $_SESSION['error_message'] = 'Gagal memproses gambar!';
        header('Location: ../petugas/kejadian.php');
        exit;
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $max_size = 1000; // Ukuran sedikit lebih besar agar tetap tajam

    if ($width > $max_size || $height > $max_size) {
        $ratio = min($max_size / $width, $max_size / $height);
        $new_width = (int)($width * $ratio);
        $new_height = (int)($height * $ratio);
        $resized = imagecreatetruecolor($new_width, $new_height);
        
        // Handle transparansi PNG
        if ($file_type == 'image/png') {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        imagedestroy($image);
        $image = $resized;
    }

    // --- SIMPAN SEBAGAI FILE (SOLUSI DATA TOO LONG) ---
    $upload_dir = __DIR__ . '/../uploads/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $filename = 'IMG_' . time() . '_' . uniqid() . '.jpg';
    $target_file = $upload_dir . $filename;
    
    // Simpan ke folder uploads dengan kualitas 75%
    imagejpeg($image, $target_file, 75);
    imagedestroy($image);

    // Path yang akan disimpan ke database (relatif atau absolut URL)
    $path_untuk_db = '../uploads/' . $filename;

    // --- Validasi field baru ---
    $aJ = ['Laporan Kejadian Banjir','Laporan Lokasi Kritis Sungai','Laporan Kerusakan Bangunan','Laporan Pelanggaran SDA','Laporan Lainnya'];
    $aK = ['Bendung','Embung','Situ','Pintu Air Sungai'];
    if (in_array($kode_sync, [1,5,6])) {
        if (!in_array($jenis_laporan, $aJ)) {
            $_SESSION['error_message'] = 'Pilih Jenis Laporan!';
            header('Location: ../petugas/kejadian.php'); exit;
        }
        if ($jenis_laporan === 'Laporan Lainnya' && $laporan_lainnya_text === '') {
            $_SESSION['error_message'] = 'Keterangan Laporan Lainnya wajib diisi!';
            header('Location: ../petugas/kejadian.php'); exit;
        }
        if ($jenis_laporan === 'Laporan Kerusakan Bangunan' && !in_array($jenis_kerusakan_bangunan, $aK)) {
            $_SESSION['error_message'] = 'Pilih Jenis Kerusakan Bangunan!';
            header('Location: ../petugas/kejadian.php'); exit;
        }
    }

    // --- Cek/Auto-create Kolom ---
    function _hcK($c,$col){ $r=$c->query("SHOW COLUMNS FROM kejadian LIKE '".$c->real_escape_string($col)."'"); return($r&&$r->num_rows>0); }
    if (!_hcK($conn,'jenis_laporan')) $conn->query("ALTER TABLE kejadian ADD COLUMN jenis_laporan ENUM('Laporan Kejadian Banjir','Laporan Lokasi Kritis Sungai','Laporan Kerusakan Bangunan','Laporan Pelanggaran SDA','Laporan Lainnya') NULL AFTER petugas_id");
    if (!_hcK($conn,'laporan_lainnya_text')) $conn->query("ALTER TABLE kejadian ADD COLUMN laporan_lainnya_text TEXT NULL AFTER jenis_laporan");
    if (!_hcK($conn,'jenis_kerusakan_bangunan')) $conn->query("ALTER TABLE kejadian ADD COLUMN jenis_kerusakan_bangunan ENUM('Bendung','Embung','Situ','Pintu Air Sungai') NULL AFTER laporan_lainnya_text");

    $hJL = _hcK($conn,'jenis_laporan');
    $hLT = _hcK($conn,'laporan_lainnya_text');
    $hJK = _hcK($conn,'jenis_kerusakan_bangunan');

    // --- Build INSERT ---
    $cols=['petugas_id','deskripsi','foto','latitude','longitude','status'];
    $plc=['?','?','?','?','?',"'pending'"];
    $typ='issdd'; 
    $val=[$petugas_id, $deskripsi, $path_untuk_db, $latitude, $longitude];

    if (in_array($kode_sync,[1,5,6]) && $hJL && $jenis_laporan!=='') {
        $cols[]='jenis_laporan'; $plc[]='?'; $typ.='s'; $val[]=$jenis_laporan;
    }
    if (in_array($kode_sync,[1,5,6]) && $hLT && $jenis_laporan==='Laporan Lainnya' && $laporan_lainnya_text!=='') {
        $cols[]='laporan_lainnya_text'; $plc[]='?'; $typ.='s'; $val[]=$laporan_lainnya_text;
    }
    if (in_array($kode_sync,[1,5,6]) && $hJK && $jenis_laporan==='Laporan Kerusakan Bangunan' && $jenis_kerusakan_bangunan!=='') {
        $cols[]='jenis_kerusakan_bangunan'; $plc[]='?'; $typ.='s'; $val[]=$jenis_kerusakan_bangunan;
    }

    $sql = "INSERT INTO kejadian (".implode(',',$cols).") VALUES (".implode(',',$plc).")";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $_SESSION['error_message'] = 'Error DB: ' . $conn->error;
        header('Location: ../petugas/kejadian.php'); exit;
    }

    $stmt->bind_param($typ, ...$val);

    if ($stmt->execute()) {
        $_SESSION['success_message'] = 'Laporan kejadian berhasil dikirim!';
        header('Location: ../petugas/kejadian.php'); exit;
    } else {
        $_SESSION['error_message'] = 'Error Database: ' . $conn->error;
        header('Location: ../petugas/kejadian.php'); exit;
    }
}