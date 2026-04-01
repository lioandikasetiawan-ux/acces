<?php

require_once '../config/database.php';

require_once '../config/session.php';

require_once '../includes/functions.php';



// Memastikan hanya petugas atau admin dengan bagian_id yang bisa mengakses
if ($_SESSION['role'] !== 'petugas' && !($_SESSION['role'] === 'admin' && isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] !== NULL)) {
    header("Location: ../auth/login-v2.php"); exit;
}



$id_petugas = $_SESSION['petugas_id'];

$shiftTableExists = false;

$shiftIdColumnExists = false;

$petugasShiftColumnExists = false;

$bagianTableExists = false;

$bagianIdColumnExists = false;

$petugasBagianColumnExists = false;



$checkShiftTable = $conn->query("SHOW TABLES LIKE 'shift'");

if ($checkShiftTable && $checkShiftTable->num_rows > 0) {

    $shiftTableExists = true;

}



$checkShiftIdColumn = $conn->query("SHOW COLUMNS FROM petugas LIKE 'shift_id'");

if ($checkShiftIdColumn && $checkShiftIdColumn->num_rows > 0) {

    $shiftIdColumnExists = true;

}



$checkPetugasShiftColumn = $conn->query("SHOW COLUMNS FROM petugas LIKE 'shift'");

if ($checkPetugasShiftColumn && $checkPetugasShiftColumn->num_rows > 0) {

    $petugasShiftColumnExists = true;

}



$checkBagianTable = $conn->query("SHOW TABLES LIKE 'bagian'");

if ($checkBagianTable && $checkBagianTable->num_rows > 0) {

    $bagianTableExists = true;

}



$checkBagianIdColumn = $conn->query("SHOW COLUMNS FROM petugas LIKE 'bagian_id'");

if ($checkBagianIdColumn && $checkBagianIdColumn->num_rows > 0) {

    $bagianIdColumnExists = true;

}



$checkPetugasBagianColumn = $conn->query("SHOW COLUMNS FROM petugas LIKE 'bagian'");

if ($checkPetugasBagianColumn && $checkPetugasBagianColumn->num_rows > 0) {

    $petugasBagianColumnExists = true;

}



$bagianJoin = '';

$bagianSelect = "'' AS bagian_display";

if ($bagianTableExists && $bagianIdColumnExists) {

    $bagianJoin = " LEFT JOIN bagian b ON p.bagian_id = b.id ";

    if ($petugasBagianColumnExists) {

        $bagianSelect = "COALESCE(b.nama_bagian, p.bagian) AS bagian_display";

    } else {

        $bagianSelect = "b.nama_bagian AS bagian_display";

    }

} else if ($petugasBagianColumnExists) {

    $bagianSelect = "p.bagian AS bagian_display";

}



$shiftJoin = '';

$shiftSelect = "'' AS shift_display";

if ($shiftTableExists) {

    if ($shiftIdColumnExists) {

        $shiftJoin = " LEFT JOIN shift s ON p.shift_id = s.id ";

        if ($petugasShiftColumnExists) {

            $shiftSelect = "COALESCE(s.nama_shift, p.shift) AS shift_display";

        } else {

            $shiftSelect = "s.nama_shift AS shift_display";

        }

    } else if ($petugasShiftColumnExists) {

        $shiftJoin = " LEFT JOIN shift s ON p.shift = s.nama_shift ";

        $shiftSelect = "COALESCE(s.nama_shift, p.shift) AS shift_display";

    }

} else if ($petugasShiftColumnExists) {

    $shiftSelect = "p.shift AS shift_display";

}



$query = "SELECT p.*, $shiftSelect, $bagianSelect FROM petugas p $shiftJoin $bagianJoin WHERE p.id = ?";

$stmt = $conn->prepare($query);

$stmt->bind_param("i", $id_petugas);

$stmt->execute();

$data = stmtFetchAssoc($stmt);
$stmt->close();

?>



<!DOCTYPE html>

<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Profil & Lokasi Kerja</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

</head>

<body class="bg-gray-50 pb-20">



    <div class="bg-purple-700 p-4 text-white shadow-md flex items-center gap-4 sticky top-0 z-10">

        <a href="dashboard-v2.php" class="text-white"><i class="fas fa-arrow-left"></i></a>

        <h1 class="font-bold text-lg">Profil & Akun</h1>

    </div>



    <div class="p-5">

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-5 text-center">

            <div class="w-20 h-20 bg-purple-100 text-purple-600 rounded-full flex items-center justify-center mx-auto mb-3 text-3xl">

                <i class="fas fa-user-tie"></i>

            </div>

            <h2 class="font-bold text-gray-800 text-lg"><?= $data['nama'] ?></h2>

            <p class="text-gray-500 text-sm"><?= $data['nip'] ?></p>

        </div>


        <form action="../api/profil-process.php" method="POST">

            

            <h3 class="text-gray-500 text-xs font-bold uppercase mb-3 ml-1">Informasi Kepegawaian</h3>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-6 space-y-3">
            <div>

             <div>
                        <label class="block text-xs text-gray-400 mb-1">Bagian</label>
                        <input type="text" value="<?= $data['bagian_display'] ?>" readonly class="w-full border-b border-gray-200 py-1 text-gray-700 font-medium bg-transparent">
                    </div>

  <div>
                <label class="block text-xs text-gray-400 mb-1">Jabatan</label>
                <input type="text" value="<?= $data['jabatan'] ?>" readonly class="w-full border-b border-gray-200 py-2 text-gray-700 font-medium bg-transparent">
            </div>

                <label class="block text-xs text-gray-400 mb-1">Kode Jabatan</label>
                <input type="text" value="<?= isset($data['kode_jabatan']) ? $data['kode_jabatan'] : '-' ?>" readonly class="w-full border-b border-gray-200 py-2 text-gray-700 font-medium bg-transparent">
            </div>
          
            <div>
                <label class="block text-xs text-gray-400 mb-1">Job Description</label>
                <textarea readonly class="w-full border border-gray-200 rounded-lg p-3 text-gray-700 bg-gray-50 text-sm" rows="3"><?= isset($data['job_desc']) ? $data['job_desc'] : '-' ?></textarea>
            </div>

<div>
                <label class="block text-xs text-gray-400 mb-1">Detail Alamat Lapangan</label>
                <textarea readonly class="w-full border border-gray-200 rounded-lg p-3 text-gray-700 bg-gray-50 text-sm" rows="3"><?= isset($data['job_desc']) ? $data['alamat'] : '-' ?></textarea>
            </div>

<h3 class="text-gray-500 text-xs font-bold uppercase mb-3 ml-1">Keamanan Akun</h3>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-6 space-y-4">

                <div>

                    <label class="block text-gray-700 text-sm font-bold mb-2">Ganti Password</label>

                    <div class="mb-3">

                        <input type="password" name="password_lama" id="passOldInput" placeholder="Password lama" 

                            class="w-full border rounded-lg p-3 focus:ring-2 focus:ring-purple-500 outline-none text-sm">

                    </div>

                    <div class="relative">

                        <input type="password" name="password_baru" id="passInput" placeholder="Kosongkan jika tidak diganti" 

                            class="w-full border rounded-lg p-3 pr-10 focus:ring-2 focus:ring-purple-500 outline-none text-sm">

                        <i class="fas fa-eye absolute right-3 top-3.5 text-gray-400 cursor-pointer" onclick="togglePass()"></i>

                    </div>

                </div>

            </div>



            <button type="submit" class="w-full bg-purple-700 hover:bg-purple-800 text-white font-bold py-3.5 rounded-xl shadow-lg transition active:scale-95">

                SIMPAN PERUBAHAN

            </button>




        </div>
                

        </div>
                

            
        </form>

    </div>



    <script>

        function togglePass() {

            var x = document.getElementById("passInput");

            x.type = (x.type === "password") ? "text" : "password";

        }

    </script>

</body>

</html>