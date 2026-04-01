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



    // Cek file foto kejadian

    if ($fotoField === null) {

         $_SESSION['error_message'] = 'Wajib menyertakan bukti foto kejadian!';
        header('Location: ../petugas/kejadian.php');
        exit;

    }



    // Konversi file upload ke base64 untuk disimpan ke database

    $file_tmp = $_FILES[$fotoField]['tmp_name'];

    $file_type = $_FILES[$fotoField]['type'];

    

    // Validasi tipe file

    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];

    if (!in_array($file_type, $allowed_types)) {

        $_SESSION['error_message'] = 'Format foto harus JPG atau PNG!';
        header('Location: ../petugas/kejadian.php');
        exit;

    }

       // Kompresi gambar untuk mengurangi ukuran file
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

    // Resize jika terlalu besar (max 800x800)
    $width = imagesx($image);
    $height = imagesy($image);
    $max_size = 800;

    if ($width > $max_size || $height > $max_size) {
        $ratio = min($max_size / $width, $max_size / $height);
        $new_width = (int)($width * $ratio);
        $new_height = (int)($height * $ratio);
        
        $resized = imagecreatetruecolor($new_width, $new_height);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        imagedestroy($image);
        $image = $resized;
    }

    // Konversi ke JPEG dengan quality 70% untuk ukuran lebih kecil
    ob_start();
    imagejpeg($image, null, 70);
    $image_data = ob_get_clean();
    imagedestroy($image);

    $nama_foto = 'data:image/jpeg;base64,' . base64_encode($image_data);


    // --- Validasi field baru kode_sync 1/5/6 ---
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

    // --- Auto-create kolom baru ---
    function _hcK($c,$col){ $r=$c->query("SHOW COLUMNS FROM kejadian LIKE '".$c->real_escape_string($col)."'"); return($r&&$r->num_rows>0); }
    if (!_hcK($conn,'jenis_laporan')) $conn->query("ALTER TABLE kejadian ADD COLUMN jenis_laporan ENUM('Laporan Kejadian Banjir','Laporan Lokasi Kritis Sungai','Laporan Kerusakan Bangunan','Laporan Pelanggaran SDA','Laporan Lainnya') NULL AFTER petugas_id");
    if (!_hcK($conn,'laporan_lainnya_text')) $conn->query("ALTER TABLE kejadian ADD COLUMN laporan_lainnya_text TEXT NULL AFTER jenis_laporan");
    if (!_hcK($conn,'jenis_kerusakan_bangunan')) $conn->query("ALTER TABLE kejadian ADD COLUMN jenis_kerusakan_bangunan ENUM('Bendung','Embung','Situ','Pintu Air Sungai') NULL AFTER laporan_lainnya_text");

    $hJL = _hcK($conn,'jenis_laporan');
    $hLT = _hcK($conn,'laporan_lainnya_text');
    $hJK = _hcK($conn,'jenis_kerusakan_bangunan');

    // --- Build INSERT dinamis ---
    $cols=['petugas_id','deskripsi','foto','latitude','longitude','status'];
    $plc=['?','?','?','?','?',"'pending'"];
    $typ='issdd'; $val=[$petugas_id,$deskripsi,$nama_foto,$latitude,$longitude];

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

?>