<?php

require_once '../../config/database.php';

require_once '../../config/session.php';

require_once '../../includes/functions.php';



if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'superadmin'], true)) {

    header('Location: ../../auth/login-v2.php');

    exit;

}

$isLockedAdmin = ($_SESSION['role'] === 'admin' && !empty($_SESSION['bagian_id']));

if ($isLockedAdmin) {
    redirectWithMessage('index.php', 'Anda tidak diizinkan menambahkan bagian.', 'error');
}



$error = '';

$success = '';



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $kode = sanitize($_POST['kode_bagian'] ?? '');

    $nama = sanitize($_POST['nama_bagian'] ?? '');

    $lokasiWilayah = sanitize($_POST['lokasi_wilayah'] ?? '');

    $isActive = 1;



    if (empty($kode) || empty($nama)) {

        $error = 'Kode dan nama bagian wajib diisi';

    } else {

        // Cek duplikat kode

        $stmt = $conn->prepare("SELECT id FROM bagian WHERE kode_bagian = ?");

        $stmt->bind_param("s", $kode);

        $stmt->execute();

        $dup = stmtFetchAssoc($stmt);

        if ($dup) {

            $error = 'Kode bagian sudah digunakan';

        } else {

            $lokasiWilayahColumnExists = false;

            $checkLokasiWilayah = $conn->query("SHOW COLUMNS FROM bagian LIKE 'lokasi_wilayah'");

            if ($checkLokasiWilayah && $checkLokasiWilayah->num_rows > 0) {

                $lokasiWilayahColumnExists = true;

            }



            if ($lokasiWilayahColumnExists) {

                $stmt = $conn->prepare("INSERT INTO bagian (kode_bagian, nama_bagian, lokasi_wilayah, is_active, kode_sync) VALUES (?, ?, ?, ?, 0)");

                $stmt->bind_param("sssi", $kode, $nama, $lokasiWilayah, $isActive);

            } else {

                $stmt = $conn->prepare("INSERT INTO bagian (kode_bagian, nama_bagian, deskripsi, is_active, kode_sync) VALUES (?, ?, ?, ?, 0)");

                $stmt->bind_param("sssi", $kode, $nama, $lokasiWilayah, $isActive);

            }

            

            if ($stmt->execute()) {

                redirectWithMessage('index.php', 'Bagian berhasil ditambahkan', 'success');

            } else {

                $error = 'Gagal menyimpan data: ' . $conn->error;

            }

        }

        $stmt->close();

    }

}

?>

<?php

require_once '../layout/header.php';

require_once '../layout/sidebar.php';

?>

<div class="p-8">

        <div class="mb-6">

            <a href="index.php" class="text-blue-600 hover:underline"><i class="fas fa-arrow-left mr-2"></i>Kembali</a>

            <h1 class="text-2xl font-bold text-gray-800 mt-2">Tambah Bagian Baru</h1>

        </div>



        <?php if ($error): ?>

        <div class="mb-4 p-4 rounded-lg bg-red-100 text-red-700"><?= $error ?></div>

        <?php endif; ?>



        <div class="bg-white rounded-xl shadow-md p-6 max-w-2xl">

            <form method="POST" class="space-y-4">

                <div>

                    <label class="block text-sm font-medium text-gray-700 mb-1">Kode Bagian *</label>

                    <input type="text" name="kode_bagian" required maxlength="20"

                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"

                           placeholder="Contoh: UPB, UPI, UP3BK" value="<?= htmlspecialchars($_POST['kode_bagian'] ?? '') ?>">

                    <p class="text-xs text-gray-500 mt-1">Kode singkat unik untuk bagian ini</p>

                </div>



                <div>

                    <label class="block text-sm font-medium text-gray-700 mb-1">Nama Bagian *</label>

                    <input type="text" name="nama_bagian" required maxlength="100"

                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"

                           placeholder="Contoh: Unit Pelaksana Bendungan" value="<?= htmlspecialchars($_POST['nama_bagian'] ?? '') ?>">

                </div>



                <div>

                    <label class="block text-sm font-medium text-gray-700 mb-1">Lokasi Wilayah</label>

                    <textarea name="lokasi_wilayah" rows="3"

                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"

                              placeholder="Contoh: Desa/Kecamatan/Kabupaten (wilayah kerja)"><?= htmlspecialchars($_POST['lokasi_wilayah'] ?? '') ?></textarea>

                </div>



                <div class="flex items-center">

                    <input type="checkbox" name="is_active" id="is_active" checked

                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">

                    <label for="is_active" class="ml-2 block text-sm text-gray-700">Aktif</label>

                </div>



                <div class="pt-4 flex gap-3">

                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">

                        <i class="fas fa-save mr-2"></i>Simpan

                    </button>

                    <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-lg">Batal</a>

                </div>

            </form>

        </div>

    </div>



<?php require_once '../layout/footer.php'; ?>