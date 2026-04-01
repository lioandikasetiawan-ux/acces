<?php

require_once '../config/database.php';

require_once '../config/session.php';

require_once __DIR__ . '/image-helper.php';



function hasColumn($conn, $table, $column) {

    $table  = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);

    $sql = "SHOW COLUMNS FROM `$table` LIKE '$column'";
    $res = $conn->query($sql);

    return ($res && $res->num_rows > 0);
}



function hasTable($conn, $table) {

    $table = $conn->real_escape_string($table);

    $sql = "SHOW TABLES LIKE '$table'";
    $res = $conn->query($sql);

    return ($res && $res->num_rows > 0);
}



function resolveAbsensiStatus($conn, $desired) {

    $stmt = $conn->prepare("SHOW COLUMNS FROM absensi LIKE 'status'");
    $stmt->execute();

    $field = null;
    $type = null;
    $null = null;
    $key = null;
    $default = null;
    $extra = null;

    $stmt->bind_result($field, $type, $null, $key, $default, $extra);
    $stmt->fetch();
    $stmt->close();

    if (empty($type)) {
        return $desired;
    }

    $type = strtolower($type);

    if (strpos($type, "enum(") === false) {
        return $desired;
    }

    $inside = substr($type, 5, -1);
    $parts = array_map('trim', explode(',', $inside));

    $values = [];
    foreach ($parts as $p) {
        $values[] = trim($p, "'\"");
    }

    if (in_array($desired, $values, true)) return $desired;

    $alt = str_replace(' ', '_', $desired);
    if (in_array($alt, $values, true)) return $alt;

    $alt2 = str_replace('_', ' ', $desired);
    if (in_array($alt2, $values, true)) return $alt2;

    return $desired;
}



if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $petugas_id = (int)($_SESSION['petugas_id'] ?? 0);

    if ($petugas_id <= 0) {

         $_SESSION['error_message'] = 'Silakan login ulang.';
        header('Location: ../auth/login-v2.php');

        exit;

    }



    if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'petugas' && !($_SESSION['role'] === 'admin' && isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] !== NULL))) {

       $_SESSION['error_message'] = 'Silakan login ulang.';
        header('Location: ../auth/login-v2.php');        exit;

    }



    $jenis      = $_POST['jenis'];

    $tanggal    = $_POST['tanggal'];

    $keterangan = trim($_POST['keterangan']);

    $jenis_lupa_absen = null;
    $pengajuanJenisLupaColumnExists = hasColumn($conn, 'pengajuan', 'jenis_lupa_absen');
    $alreadyComplete = false;
    if ($jenis === 'lupa absen') {
        $jenis_lupa_absen = isset($_POST['jenis_lupa_absen']) ? trim((string)$_POST['jenis_lupa_absen']) : '';
        if ($jenis_lupa_absen !== 'masuk' && $jenis_lupa_absen !== 'keluar') {
            $_SESSION['error_message'] = 'Jenis lupa absen wajib dipilih!';
            header('Location: ../petugas/pengajuan.php');
            exit;
        }
    }

	// Pastikan kolom bukti_sakit cukup besar untuk base64
    $colCheck = $conn->query("SHOW COLUMNS FROM pengajuan LIKE 'bukti_sakit'");
    if ($colCheck && $colRow = $colCheck->fetch_assoc()) {
        $colType = strtolower($colRow['Type']);
        if (strpos($colType, 'longtext') === false && strpos($colType, 'mediumtext') === false) {
            $conn->query("ALTER TABLE pengajuan MODIFY bukti_sakit LONGTEXT NULL");
        }
    }

	// ==============================
// ? STEP 4: UPLOAD FOTO BUKTI SAKIT (compressed base64)
// ==============================

$buktiSakit = null;

if ($jenis === 'sakit') {

    if (!empty($_FILES['foto_sakit']['name']) && $_FILES['foto_sakit']['error'] == 0) {

        $tmpFile  = $_FILES['foto_sakit']['tmp_name'];
        $fileType = $_FILES['foto_sakit']['type'];

        // Validasi tipe file
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png'];
        if (!in_array($fileType, $allowedTypes)) {
            $_SESSION['error_message'] = 'Format foto bukti sakit harus JPG atau PNG!';
            header('Location: ../petugas/pengajuan.php');
            exit;
        }

        // Compress & convert to base64 (max 800x800, quality 70%)
        $compressed = compressPhotoForMobile($tmpFile);
        if ($compressed !== false) {
            $buktiSakit = $compressed;
        } else {
            $_SESSION['error_message'] = 'Gagal memproses foto bukti sakit!';
            header('Location: ../petugas/pengajuan.php');
            exit;
        }
    }
}



    $pengajuanShiftColumnExists = false;

    $checkPengajuanShift = $conn->query("SHOW COLUMNS FROM pengajuan LIKE 'shift'");

    if ($checkPengajuanShift && $checkPengajuanShift->num_rows > 0) {

        $pengajuanShiftColumnExists = true;

    }



    $pengajuanShiftIdColumnExists = false;

    $checkPengajuanShiftId = $conn->query("SHOW COLUMNS FROM pengajuan LIKE 'shift_id'");

    if ($checkPengajuanShiftId && $checkPengajuanShiftId->num_rows > 0) {

        $pengajuanShiftIdColumnExists = true;

    }



    $allowedShifts = [];

    $shiftNameToId = [];

    if (hasTable($conn, 'shift')) {

        $resShift = $conn->query("SELECT id, nama_shift FROM shift ORDER BY id ASC");

        if ($resShift) {

            while ($s = $resShift->fetch_assoc()) {

                if (isset($s['nama_shift']) && $s['nama_shift'] !== '') {

                    $allowedShifts[] = $s['nama_shift'];

                    if (isset($s['id'])) {

                        $shiftNameToId[$s['nama_shift']] = (int)$s['id'];

                    }

                }

            }

        }

    }

    if (count($allowedShifts) === 0) {

        $allowedShifts = ['pagi', 'siang', 'malam'];

    }



    $shift_value = null;

    $shift_id_value = null;



    if ($jenis === 'izin' || $jenis === 'sakit' || $jenis === 'lupa absen') {

        $shift_value = isset($_POST['shift']) ? trim($_POST['shift']) : '';

        if ($shift_value === '') {

            $_SESSION['error_message'] = 'Shift wajib dipilih!';
            header('Location: ../petugas/pengajuan.php');
            exit;

        }



        if (!in_array($shift_value, $allowedShifts, true)) {

            $_SESSION['error_message'] = 'Shift tidak valid!';
            header('Location: ../petugas/pengajuan.php');
            exit;

        }



        $shift_id_value = $shiftNameToId[$shift_value] ?? null;

    }



    if ($jenis === 'lupa absen') {

        $absensiShiftIdColumnExists = hasColumn($conn, 'absensi', 'shift_id');

        if ($absensiShiftIdColumnExists && !$shift_id_value) {

             $_SESSION['error_message'] = 'Shift tidak valid!';
            header('Location: ../petugas/pengajuan.php');
            exit;

        }

    }



    // Validasi sederhana

    if (empty($keterangan) || empty($tanggal)) {

         $_SESSION['error_message'] = 'Data tidak lengkap!';
        header('Location: ../petugas/pengajuan.php');
        exit;
    }



    $resAbsen = null;

    if ($jenis === 'lupa absen') {

        $absensiShiftIdColumnExists = hasColumn($conn, 'absensi', 'shift_id');

        $absensiShiftColumnExists = hasColumn($conn, 'absensi', 'shift');

        if ($absensiShiftIdColumnExists && $shift_id_value) {

	$cekAbsen = $conn->prepare("
    SELECT id FROM absensi
    WHERE petugas_id = ?
      AND tanggal = ?
      AND shift_id = ?
      AND jam_masuk IS NOT NULL AND jam_masuk <> ''
      AND jam_keluar IS NOT NULL AND jam_keluar <> ''
    LIMIT 1
");

if ($cekAbsen === false) {
    die(
        "Prepare cekAbsen gagal<br>" .
        "Error: " . $conn->error . "<br>" .
        "SQL salah / kolom tidak ada"
    );
}

$cekAbsen->bind_param('isi', $petugas_id, $tanggal, $shift_id_value);
$cekAbsen->execute();
$cekAbsen->store_result();
$alreadyComplete = ($cekAbsen->num_rows > 0);
$cekAbsen->close();

        } else {

    $cekAbsen = $conn->prepare("
        SELECT id FROM absensi
        WHERE petugas_id = ?
          AND tanggal = ?
          AND jam_masuk IS NOT NULL
          AND jam_masuk <> ''
          AND jam_keluar IS NOT NULL
          AND jam_keluar <> ''
        LIMIT 1
    ");

    if ($cekAbsen === false) {
        die(
            "Prepare cekAbsen (tanpa shift) gagal<br>" .
            "MySQL Error: " . $conn->error
        );
    }

    $cekAbsen->bind_param('is', $petugas_id, $tanggal);
    $cekAbsen->execute();
    $cekAbsen->store_result();
    $alreadyComplete = ($cekAbsen->num_rows > 0);
    $cekAbsen->close();
}

    }

    if ($alreadyComplete && $jenis === 'lupa absen') {

          $_SESSION['error_message'] = 'Anda sudah Absen Masuk dan Absen Keluar pada tanggal tersebut. Pengajuan Lupa Absen tidak dapat dibuat.';
        header('Location: ../petugas/pengajuan.php');
        exit;
    }



    // Insert ke database

    if ($pengajuanShiftIdColumnExists && $shift_id_value) {

        if ($pengajuanJenisLupaColumnExists) {
            $sqlPengajuan = "INSERT INTO pengajuan (petugas_id, tanggal, shift_id, jenis, jenis_lupa_absen, keterangan, bukti_sakit, status)
                             VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')";
            $stmt = $conn->prepare($sqlPengajuan);
            if ($stmt === false) {
                die("Prepare pengajuan (shift_id) gagal: " . $conn->error);
            }
            $jenisLupaBind = ($jenis === 'lupa absen') ? $jenis_lupa_absen : null;
            $stmt->bind_param("isissss", $petugas_id, $tanggal, $shift_id_value, $jenis, $jenisLupaBind, $keterangan, $buktiSakit);
        } else {
            $sqlPengajuan = "INSERT INTO pengajuan (petugas_id, tanggal, shift_id, jenis, keterangan, bukti_sakit, status)
                             VALUES (?, ?, ?, ?, ?, ?, 'pending')";
            $stmt = $conn->prepare($sqlPengajuan);
            if ($stmt === false) {
                die("Prepare pengajuan (shift_id) gagal: " . $conn->error);
            }
            $stmt->bind_param("isisss", $petugas_id, $tanggal, $shift_id_value, $jenis, $keterangan, $buktiSakit);
        }

    } else if ($pengajuanShiftColumnExists && $shift_value) {

        if ($pengajuanJenisLupaColumnExists) {
		    $sqlPengajuan = "INSERT INTO pengajuan (petugas_id, tanggal, shift, jenis, jenis_lupa_absen, keterangan, bukti_sakit, status)
                             VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')";
            $stmt = $conn->prepare($sqlPengajuan);
            if ($stmt === false) {
                die("Prepare pengajuan (shift) gagal: " . $conn->error);
            }
            $jenisLupaBind = ($jenis === 'lupa absen') ? $jenis_lupa_absen : null;
            $stmt->bind_param("issssss", $petugas_id, $tanggal, $shift_value, $jenis, $jenisLupaBind, $keterangan, $buktiSakit);
        } else {
		    $sqlPengajuan = "INSERT INTO pengajuan (petugas_id, tanggal, shift, jenis, keterangan, bukti_sakit, status)
                             VALUES (?, ?, ?, ?, ?, ?, 'pending')";
            $stmt = $conn->prepare($sqlPengajuan);
            if ($stmt === false) {
                die("Prepare pengajuan (shift) gagal: " . $conn->error);
            }
            $stmt->bind_param("isssss", $petugas_id, $tanggal, $shift_value, $jenis, $keterangan, $buktiSakit);
        }

    } else {

        if ($pengajuanJenisLupaColumnExists) {
            $sqlPengajuan = "INSERT INTO pengajuan (petugas_id, tanggal, jenis, jenis_lupa_absen, keterangan, bukti_sakit, status)
                             VALUES (?, ?, ?, ?, ?, ?, 'pending')";
            $stmt = $conn->prepare($sqlPengajuan);
            if ($stmt === false) {
                error_log("SQL PENGAJUAN ERROR: " . $conn->error);
                error_log("SQL: " . $sqlPengajuan);
                die("Prepare pengajuan gagal, cek error_log.");
            }
            $jenisLupaBind = ($jenis === 'lupa absen') ? $jenis_lupa_absen : null;
            $stmt->bind_param("isssss", $petugas_id, $tanggal, $jenis, $jenisLupaBind, $keterangan, $buktiSakit);
        } else {
            $sqlPengajuan = "INSERT INTO pengajuan (petugas_id, tanggal, jenis, keterangan, bukti_sakit, status)
                             VALUES (?, ?, ?, ?, ?, 'pending')";
            $stmt = $conn->prepare($sqlPengajuan);
            if ($stmt === false) {
                error_log("SQL PENGAJUAN ERROR: " . $conn->error);
                error_log("SQL: " . $sqlPengajuan);
                die("Prepare pengajuan gagal, cek error_log.");
            }
            $stmt->bind_param("issss", $petugas_id, $tanggal, $jenis, $keterangan, $buktiSakit);
        }

    }



    if ($stmt->execute()) {
        $stmt->close();
         $_SESSION['success_message'] = 'Pengajuan berhasil dikirim! Menunggu persetujuan admin.';
        header('Location: ../petugas/pengajuan.php');
        exit;
    } else {
        $stmt->close();
        $_SESSION['error_message'] = 'Error Database: ' . $conn->error;
        header('Location: ../petugas/pengajuan.php');
        exit;
    }

}

?>