<?php

require_once '../../config/database.php';

require_once '../../config/session.php';

require_once '../../includes/functions.php';



if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'superadmin'], true)) {

    header('Location: ../../auth/login-v2.php');

    exit;

}

$isLockedAdmin = ($_SESSION['role'] === 'admin' && !empty($_SESSION['bagian_id']));



$id = (int)($_GET['id'] ?? 0);

if (!$id) {

    redirectWithMessage('index.php', 'ID tidak valid', 'error');

}



// Get bagian data

$stmt = $conn->prepare("SELECT * FROM bagian WHERE id = ?");

$stmt->bind_param("i", $id);

$stmt->execute();

$bagian = stmtFetchAssoc($stmt);

$stmt->close();



if (!$bagian) {

    redirectWithMessage('index.php', 'Bagian tidak ditemukan', 'error');

}



$error = '';



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $kode = sanitize($_POST['kode_bagian'] ?? '');

    $nama = sanitize($_POST['nama_bagian'] ?? '');

    $lokasiWilayah = sanitize($_POST['lokasi_wilayah'] ?? '');

    $isActive = isset($_POST['is_active']) ? 1 : 0;



    if (empty($kode) || empty($nama)) {

        $error = 'Kode dan nama bagian wajib diisi';

    } else {

        // Cek duplikat kode (exclude current)

        $stmt = $conn->prepare("SELECT id FROM bagian WHERE kode_bagian = ? AND id != ?");

        $stmt->bind_param("si", $kode, $id);

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

                $stmt = $conn->prepare("UPDATE bagian SET kode_bagian = ?, nama_bagian = ?, lokasi_wilayah = ?, is_active = ? WHERE id = ?");

                $stmt->bind_param("sssii", $kode, $nama, $lokasiWilayah, $isActive, $id);

            } else {

                $stmt = $conn->prepare("UPDATE bagian SET kode_bagian = ?, nama_bagian = ?, deskripsi = ?, is_active = ? WHERE id = ?");

                $stmt->bind_param("sssii", $kode, $nama, $lokasiWilayah, $isActive, $id);

            }

            

            if ($stmt->execute()) {

                redirectWithMessage('index.php', 'Bagian berhasil diupdate', 'success');

            } else {

                $error = 'Gagal menyimpan data: ' . $conn->error;

            }

        }

        $stmt->close();

    }

}

?>

<?php

if ($isLockedAdmin && (int)$_SESSION['bagian_id'] !== (int)$id) {
    redirectWithMessage('index.php', 'Anda tidak diizinkan mengubah bagian lain.', 'error');
}

require_once '../layout/header.php';

require_once '../layout/sidebar.php';

?>

<div class="p-8">

        <div class="mb-6">

            <a href="index.php" class="text-blue-600 hover:underline"><i class="fas fa-arrow-left mr-2"></i>Kembali</a>

            <h1 class="text-2xl font-bold text-gray-800 mt-2">Edit Bagian: <?= htmlspecialchars($bagian['nama_bagian']) ?></h1>

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

                           value="<?= htmlspecialchars($_POST['kode_bagian'] ?? $bagian['kode_bagian']) ?>">

                </div>



                <div>

                    <label class="block text-sm font-medium text-gray-700 mb-1">Nama Bagian *</label>

                    <input type="text" name="nama_bagian" required maxlength="100"

                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"

                           value="<?= htmlspecialchars($_POST['nama_bagian'] ?? $bagian['nama_bagian']) ?>">

                </div>



                <div>

                    <label class="block text-sm font-medium text-gray-700 mb-1">Lokasi Wilayah</label>

                    <textarea name="lokasi_wilayah" rows="3"

                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"><?= htmlspecialchars($_POST['lokasi_wilayah'] ?? ($bagian['lokasi_wilayah'] ?? $bagian['deskripsi'])) ?></textarea>

                </div>



                <div class="flex items-center">

                    <input type="checkbox" name="is_active" id="is_active" 

                           <?= ($bagian['is_active'] ?? true) ? 'checked' : '' ?>

                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">

                    <label for="is_active" class="ml-2 block text-sm text-gray-700">Aktif</label>

                </div>



                <div class="pt-4 flex gap-3">

                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">

                        <i class="fas fa-save mr-2"></i>Update

                    </button>

                    <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-lg">Batal</a>

                </div>

            </form>

        </div>

    </div>



<?php require_once '../layout/footer.php'; ?>

