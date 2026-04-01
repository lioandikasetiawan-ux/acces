<?php

require_once '../../config/database.php';

require_once '../../config/session.php';



if ($_SESSION['role'] !== 'admin') {

    header("Location: ../../auth/login-v2.php"); exit;

}



if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $id = $_POST['id'];

    $nama = trim($_POST['nama']);

    $nip = trim($_POST['nip']);

    $jabatan = $_POST['jabatan'];



    $job_desc = isset($_POST['job_desc']) ? trim($_POST['job_desc']) : null;



    $role = isset($_POST['role']) ? $_POST['role'] : 'petugas';

    $password_input = isset($_POST['password']) ? $_POST['password'] : '';



    $kode = $_POST['kode_jabatan'];

    $bagian_id = isset($_POST['bagian_id']) ? (int)$_POST['bagian_id'] : 0;

    $bagian = isset($_POST['bagian']) ? $_POST['bagian'] : '';

    $alamat = isset($_POST['alamat']) ? $_POST['alamat'] : '';

    $lat = (isset($_POST['latitude']) && $_POST['latitude'] !== '') ? $_POST['latitude'] : null;

    $long = (isset($_POST['longitude']) && $_POST['longitude'] !== '') ? $_POST['longitude'] : null;



    // PROTECTION: Cek status user sebelumnya
    $stmtCheck = $conn->prepare("SELECT role, bagian_id FROM petugas WHERE id = ?");
    $stmtCheck->bind_param("i", $id);
    $stmtCheck->execute();
    $resCheck = $stmtCheck->get_result();
    $oldData = $resCheck->fetch_assoc();
    
    // Jika user sebelumnya adalah Superadmin (Admin & Tanpa Bagian), 
    // Maka paksa bagian_id tetap 0 agar tidak 'turun pangkat' jadi admin biasa
    if ($oldData && $oldData['role'] === 'admin' && empty($oldData['bagian_id'])) {
        $bagian_id = 0;
        $bagian = '';
        // Pastikan role tetap admin jika POST mengirim 'petugas' (opsional, tergantung logic bisnis. User minta 'tetap jadi admin tanpa bagian_id')
        $role = 'admin'; 
    }

    if ($bagian_id > 0) {

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



    // shift/shift_id sengaja tidak diproses lagi (fitur shift dihapus dari manajemen petugas)



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
    
    // shift_id dan titik_lokasi_id sudah tidak dipakai (diganti jadwal_petugas)



    $conn->begin_transaction();



    try {

        $updateSets = [];

        $updateTypes = '';

        $updateParams = [];



        $updateSets[] = 'nama = ?';

        $updateTypes .= 's';

        $updateParams[] = $nama;


	$updateSets[] = 'nip = ?';
        $updateTypes .= 's';
        $updateParams[] = $nip;

        $updateSets[] = 'jabatan = ?';

        $updateTypes .= 's';

        $updateParams[] = $jabatan;



        $checkJobDescColumn = $conn->query("SHOW COLUMNS FROM petugas LIKE 'job_desc'");

        if ($checkJobDescColumn && $checkJobDescColumn->num_rows > 0) {

            $updateSets[] = 'job_desc = ?';

            $updateTypes .= 's';

            $updateParams[] = $job_desc;

        }



        $updateSets[] = 'kode_jabatan = ?';

        $updateTypes .= 's';

        $updateParams[] = $kode;



        if ($bagianIdColumnExists) {

            $updateSets[] = 'bagian_id = ?';

            $updateTypes .= 'i';

            // FIX: Jika bagian_id 0 (Superadmin/Unassigned), simpan sebagai NULL
            // karena ada Foreign Key ke tabel bagian. 
            $updateParams[] = ($bagian_id === 0) ? null : $bagian_id;

        } else if ($petugasBagianColumnExists) {

            $updateSets[] = 'bagian = ?';

            $updateTypes .= 's';

            $updateParams[] = $bagian;

        }



        if ($lokasiKerjaColumnExists) {

            $updateSets[] = 'lokasi_kerja = ?';

            $updateTypes .= 's';

            $updateParams[] = $lokasi;

        }



        if ($alamatColumnExists) {

            $updateSets[] = 'alamat = ?';

            $updateTypes .= 's';

            $updateParams[] = $alamat;

        }



        if ($latColumnExists && $lat !== null && is_numeric($lat)) {

            $updateSets[] = 'latitude = ?';

            $updateTypes .= 'd';

            $updateParams[] = (double)$lat;

        }



        if ($longColumnExists && $long !== null && is_numeric($long)) {

            $updateSets[] = 'longitude = ?';

            $updateTypes .= 'd';

            $updateParams[] = (double)$long;

        }
        
        // shift_id dan titik_lokasi_id sudah tidak dipakai (diganti jadwal_petugas)

        $sqlUpdate = "UPDATE petugas SET " . implode(', ', $updateSets) . " WHERE id = ?";

        $updateTypes .= 'i';

        $updateParams[] = (int)$id;



        $stmt = $conn->prepare($sqlUpdate);

        $bindParams = [];

        $bindParams[] = $updateTypes;

        foreach ($updateParams as $k => $v) {

            $updateParams[$k] = $v;

            $bindParams[] = &$updateParams[$k];

        }

        call_user_func_array([$stmt, 'bind_param'], $bindParams);



        if (!$stmt->execute()) {

            throw new Exception($stmt->error);

        }



        $validRoles = ['admin', 'petugas'];

        if (!in_array($role, $validRoles, true)) {

            $role = 'petugas';

        }



        $checkRoleColumn = $conn->query("SHOW COLUMNS FROM petugas LIKE 'role'");

        $roleColumnExists = ($checkRoleColumn && $checkRoleColumn->num_rows > 0);

        if ($roleColumnExists) {

            $stmtRole = $conn->prepare("UPDATE petugas SET role = ? WHERE id = ?");

            $stmtRole->bind_param("si", $role, $id);

            if (!$stmtRole->execute()) {

                throw new Exception($stmtRole->error);

            }

        }



        if (!empty($password_input)) {

            $checkPasswordColumn = $conn->query("SHOW COLUMNS FROM petugas LIKE 'password'");

            $passwordColumnExists = ($checkPasswordColumn && $checkPasswordColumn->num_rows > 0);

            if ($passwordColumnExists) {

                $password_hash = password_hash($password_input, PASSWORD_BCRYPT);

                $stmtPass = $conn->prepare("UPDATE petugas SET password = ? WHERE id = ?");

                $stmtPass->bind_param("si", $password_hash, $id);

                if (!$stmtPass->execute()) {

                    throw new Exception($stmtPass->error);

                }

            }

        }



        $conn->commit();
        echo "<script>alert('Data petugas berhasil diperbarui!'); window.location.href='index.php';</script>";
    } catch (Exception $e) {
        $conn->rollback();
        $errorMsg = $e->getMessage();
        // Check if it's a duplicate NIP error
        if (strpos($errorMsg, 'Duplicate entry') !== false && strpos($errorMsg, 'nip') !== false) {
            echo "<script>alert('Gagal update: NIP sudah digunakan petugas lain!'); window.history.back();</script>";
        } else {
            echo "<script>alert('Gagal update: " . addslashes($errorMsg) . "'); window.history.back();</script>";
        }
    

    }

}

?>