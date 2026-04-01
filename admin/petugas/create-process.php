<?php

require_once '../../config/database.php';
require_once '../../config/session.php';

if ($_SESSION['role'] !== 'admin') {
    header("Location: ../../auth/login-v2.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nama = trim($_POST['nama']);
    $nip = trim($_POST['nip']);
    $password_input = $_POST['password']; // Ambil password manual
    $jabatan = $_POST['jabatan'];

    $job_desc = isset($_POST['job_desc']) ? trim($_POST['job_desc']) : null;
    $kode_jabatan = $_POST['kode_jabatan'];
    $bagian_id = isset($_POST['bagian_id']) ? (int)$_POST['bagian_id'] : 0;
    $bagian = isset($_POST['bagian']) ? $_POST['bagian'] : '';
    $shift = '';
    $alamat = isset($_POST['alamat']) ? $_POST['alamat'] : '';
    $lat = (isset($_POST['latitude']) && $_POST['latitude'] !== '') ? $_POST['latitude'] : null;
    $long = (isset($_POST['longitude']) && $_POST['longitude'] !== '') ? $_POST['longitude'] : null;

    if ($bagian_id == NULL) {
        $stmtBagian = $conn->prepare("SELECT nama_bagian FROM bagian WHERE id = ? LIMIT 1");
        $stmtBagian->bind_param("i", $bagian_id);
        $stmtBagian->execute();
        $b = $stmtBagian->get_result()->fetch_assoc();
        if ($b && isset($b['nama_bagian'])) {
            $bagian = $b['nama_bagian'];
        }
        $stmtBagian->close();
    } 


    $lokasiKerjaColumnExists = false;
    $checkLokasiKerjaColumn = $conn->query("SHOW COLUMNS FROM petugas LIKE 'lokasi_kerja'");
    if ($checkLokasiKerjaColumn && $checkLokasiKerjaColumn->num_rows > 0) {
        $lokasiKerjaColumnExists = true;
    }

    $lokasi = !empty($alamat) ? $alamat : '-';

    // shift_id sudah tidak dipakai lagi (diganti dengan jadwal_petugas)

    $petugasShiftColumnExists = false;
    $checkShiftColumn = $conn->query("SHOW COLUMNS FROM petugas LIKE 'shift'");
    if ($checkShiftColumn && $checkShiftColumn->num_rows > 0) {
        $petugasShiftColumnExists = true;
    }

    $bagianIdColumnExists = false;
    $checkBagianIdColumn = $conn->query("SHOW COLUMNS FROM petugas LIKE 'bagian_id'");
    if ($checkBagianIdColumn && $checkBagianIdColumn->num_rows > 0) {
        $bagianIdColumnExists = true;
    }

    $petugasBagianColumnExists = false;
    $checkBagianColumn = $conn->query("SHOW COLUMNS FROM petugas LIKE 'bagian'");
    if ($checkBagianColumn && $checkBagianColumn->num_rows > 0) {
        $petugasBagianColumnExists = true;
    }

    $alamatColumnExists = false;
    $checkAlamatColumn = $conn->query("SHOW COLUMNS FROM petugas LIKE 'alamat'");
    if ($checkAlamatColumn && $checkAlamatColumn->num_rows > 0) {
        $alamatColumnExists = true;
    }

    $latColumnExists = false;
    $checkLatColumn = $conn->query("SHOW COLUMNS FROM petugas LIKE 'latitude'");
    if ($checkLatColumn && $checkLatColumn->num_rows > 0) {
        $latColumnExists = true;
    }

    $longColumnExists = false;
    $checkLongColumn = $conn->query("SHOW COLUMNS FROM petugas LIKE 'longitude'");
    if ($checkLongColumn && $checkLongColumn->num_rows > 0) {
        $longColumnExists = true;
    }

    // Shift sekarang diatur via jadwal_petugas, bukan di master petugas
    if ($petugasShiftColumnExists) {
        $shift = 'pagi'; // Default untuk backward compatibility kolom shift lama
    }

    if (empty($nip)) {
        echo "<script>alert('Gagal! NIP/Username wajib diisi.'); window.history.back();</script>";
        exit;
    }

    $check = $conn->prepare("SELECT id FROM petugas WHERE nip = ? LIMIT 1");
    $check->bind_param("s", $nip);
    $check->execute();
    $resCheck = $check->get_result();
    if ($resCheck && $resCheck->num_rows > 0) {
        echo "<script>alert('Gagal! NIP $nip sudah terdaftar.'); window.history.back();</script>";
        exit;
    }

    $conn->begin_transaction();

    try {
        $validRoles = ['admin', 'petugas'];
        $role = isset($_POST['role']) ? $_POST['role'] : 'petugas';
        if (!in_array($role, $validRoles, true)) {
            $role = 'petugas';
        }

        // 1. Insert Petugas
        $password_hash = password_hash($password_input, PASSWORD_BCRYPT);
        $insertCols = [];
        $insertPlaceholders = [];
        $insertTypes = '';
        $insertParams = [];

        $insertCols[] = 'nama';
        $insertPlaceholders[] = '?';
        $insertTypes .= 's';
        $insertParams[] = $nama;

        $insertCols[] = 'nip';
        $insertPlaceholders[] = '?';
        $insertTypes .= 's';
        $insertParams[] = $nip;

        $insertCols[] = 'jabatan';
        $insertPlaceholders[] = '?';
        $insertTypes .= 's';
        $insertParams[] = $jabatan;

        $checkJobDescColumn = $conn->query("SHOW COLUMNS FROM petugas LIKE 'job_desc'");
        if ($checkJobDescColumn && $checkJobDescColumn->num_rows > 0) {
            $insertCols[] = 'job_desc';
            $insertPlaceholders[] = '?';
            $insertTypes .= 's';
            $insertParams[] = $job_desc;
        }

        $insertCols[] = 'kode_jabatan';
        $insertPlaceholders[] = '?';
        $insertTypes .= 's';
        $insertParams[] = $kode_jabatan;

        if ($bagianIdColumnExists && $bagian_id > 0) {
            $insertCols[] = 'bagian_id';
            $insertPlaceholders[] = '?';
            $insertTypes .= 'i';
            $insertParams[] = $bagian_id;
        } else if ($petugasBagianColumnExists) {
            $insertCols[] = 'bagian';
            $insertPlaceholders[] = '?';
            $insertTypes .= 's';
            $insertParams[] = $bagian;
        }

        // shift_id dan titik_lokasi_id sudah tidak dipakai (diganti jadwal_petugas)
        // Hanya simpan kolom shift jika masih ada (backward compatibility)
        if ($petugasShiftColumnExists) {
            $insertCols[] = 'shift';
            $insertPlaceholders[] = '?';
            $insertTypes .= 's';
            $insertParams[] = $shift;
        }

        if ($lokasiKerjaColumnExists) {
            $insertCols[] = 'lokasi_kerja';
            $insertPlaceholders[] = '?';
            $insertTypes .= 's';
            $insertParams[] = $lokasi;
        }

        if ($alamatColumnExists) {
            $insertCols[] = 'alamat';
            $insertPlaceholders[] = '?';
            $insertTypes .= 's';
            $insertParams[] = $alamat;
        }

        if ($latColumnExists && $lat !== null && is_numeric($lat)) {
            $insertCols[] = 'latitude';
            $insertPlaceholders[] = '?';
            $insertTypes .= 'd';
            $insertParams[] = (double)$lat;
        }

        if ($longColumnExists && $long !== null && is_numeric($long)) {
            $insertCols[] = 'longitude';
            $insertPlaceholders[] = '?';
            $insertTypes .= 'd';
            $insertParams[] = (double)$long;
        }

        $insertCols[] = 'password';
        $insertPlaceholders[] = '?';
        $insertTypes .= 's';
        $insertParams[] = $password_hash;

        $checkRoleColumn = $conn->query("SHOW COLUMNS FROM petugas LIKE 'role'");
        if ($checkRoleColumn && $checkRoleColumn->num_rows > 0) {
            $insertCols[] = 'role';
            $insertPlaceholders[] = '?';
            $insertTypes .= 's';
            $insertParams[] = $role;
        }
	
	$checkIsActiveColumn = $conn->query("SHOW COLUMNS FROM petugas LIKE 'is_active'");
        if ($checkIsActiveColumn && $checkIsActiveColumn->num_rows > 0) {
            $insertCols[] = 'is_active';
            $insertPlaceholders[] = '?';
            $insertTypes .= 'i';
            $insertParams[] = 1;
        }	

        $sqlInsert = "INSERT INTO petugas (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertPlaceholders) . ")";
        $stmt1 = $conn->prepare($sqlInsert);
        $bindParams = [];
        $bindParams[] = $insertTypes;
        foreach ($insertParams as $k => $v) {
            $insertParams[$k] = $v;
            $bindParams[] = &$insertParams[$k];
        }
        call_user_func_array([$stmt1, 'bind_param'], $bindParams);

        if (!$stmt1->execute()) {
            throw new Exception("Error Insert Petugas: " . $stmt1->error);
        }

        $insertedId = (int)$conn->insert_id;
        if ($bagianIdColumnExists && $bagian_id > 0) {
            $stmtUpdBagian = $conn->prepare("UPDATE petugas SET bagian_id = ? WHERE id = ?");
            $stmtUpdBagian->bind_param("ii", $bagian_id, $insertedId);
            if (!$stmtUpdBagian->execute()) {
                throw new Exception("Error Update Bagian: " . $stmtUpdBagian->error);
            }
        }

        $conn->commit();
        echo "<script>alert('Sukses! Petugas berhasil ditambahkan.'); window.location.href='index.php';</script>";

    } catch (Exception $e) {
        $conn->rollback();
        // Tampilkan pesan error spesifik untuk debugging
        echo "<script>alert('GAGAL: " . addslashes($e->getMessage()) . "'); window.history.back();</script>";
    }
}
?>