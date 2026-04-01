<?php

require_once '../../config/database.php';

require_once '../../config/session.php';

require_once '../../includes/functions.php';



if (
    !isset($_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin','superadmin'], true)
) {
    header("Location: ../../auth/login-v2.php");
    exit;
}

$role = $_SESSION['role'];
$bagianId = $_SESSION['bagian_id'] ?? null;

// Tentukan apakah user adalah admin global (superadmin atau admin dengan bagian_id null)
$isAdminGlobal = ($role === 'superadmin' || ($role === 'admin' && $bagianId === null));

// Ambil list bagian untuk dropdown (admin global)
$bagianList = [];
if ($isAdminGlobal) {
    $resBagian = $conn->query("SELECT id, kode_bagian, nama_bagian FROM bagian WHERE is_active = 1 ORDER BY nama_bagian");
    if ($resBagian) {
        while ($b = $resBagian->fetch_assoc()) {
            $bagianList[] = $b;
        }
    }
}



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nama_shift = trim($_POST['nama_shift']);

    $mulai_masuk = trim($_POST['mulai_masuk']);

    $akhir_masuk = trim($_POST['akhir_masuk']);

    $mulai_keluar = trim($_POST['mulai_keluar']);

    $akhir_keluar = trim($_POST['akhir_keluar']);

    // Tentukan bagian_id berdasarkan role
    $shift_bagian_id = null;
    if ($isAdminGlobal) {
        // Admin global: ambil dari dropdown (bisa kosong = global)
        $shift_bagian_id = !empty($_POST['bagian_id']) ? (int)$_POST['bagian_id'] : null;
    } elseif ($bagianId !== null) {
        // Admin bagian: pakai bagian_id session
        $shift_bagian_id = (int)$bagianId;
    }
    // Jika admin global (bagianId === null), shift_bagian_id tetap null melalui logika isAdminGlobal di atas



    if ($nama_shift === '' || $mulai_masuk === '' || $akhir_masuk === '' || $mulai_keluar === '' || $akhir_keluar === '') {

        echo "<script>alert('Semua field wajib diisi!'); window.history.back();</script>";

        exit;

    }



    // Insert shift dengan bagian_id yang sudah ditentukan
    if ($shift_bagian_id === null) {
        $stmt = $conn->prepare(
            "INSERT INTO shift (nama_shift, mulai_masuk, akhir_masuk, mulai_keluar, akhir_keluar, bagian_id)
             VALUES (?, ?, ?, ?, ?, NULL)"
        );
        $stmt->bind_param(
            "sssss",
            $nama_shift,
            $mulai_masuk,
            $akhir_masuk,
            $mulai_keluar,
            $akhir_keluar
        );
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO shift (nama_shift, mulai_masuk, akhir_masuk, mulai_keluar, akhir_keluar, bagian_id)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "sssssi",
            $nama_shift,
            $mulai_masuk,
            $akhir_masuk,
            $mulai_keluar,
            $akhir_keluar,
            $shift_bagian_id
        );
    }


    if ($stmt->execute()) {

        echo "<script>alert('Shift berhasil ditambahkan!'); window.location.href='index.php';</script>";

        exit;

    }



    echo "<script>alert('Gagal menambahkan shift: " . addslashes($stmt->error) . "'); window.history.back();</script>";

    exit;

}

?>

<?php
require_once '../layout/header.php';
require_once '../layout/sidebar.php';
?>

<div class="flex items-center gap-4 mb-6">

    <a href="index.php" class="bg-gray-200 hover:bg-gray-300 text-gray-700 p-2 rounded-lg transition"><i class="fas fa-arrow-left"></i></a>

    <h1 class="text-2xl font-bold text-gray-800">Tambah Shift</h1>

</div>



<div class="bg-white rounded-xl shadow-lg border border-gray-100 overflow-hidden max-w-3xl">

    <form method="POST" class="p-6">



        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

            <div class="md:col-span-2">

                <label class="block text-gray-700 text-sm font-bold mb-2">Nama Shift</label>

                <input type="text" name="nama_shift" required class="w-full border rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500" placeholder="Contoh: pagi / siang / malam">

            </div>

            <?php if ($isAdminGlobal): ?>
            <div class="md:col-span-2">
                <label class="block text-gray-700 text-sm font-bold mb-2">Bagian/Lokasi <span class="text-xs text-gray-500">(Opsional, kosongkan untuk shift global)</span></label>
                <select name="bagian_id" class="w-full border rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">-- Global (Semua Bagian) --</option>
                    <?php foreach ($bagianList as $b): ?>
                    <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['kode_bagian']) ?> - <?= htmlspecialchars($b['nama_bagian']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>



            <div>

                <label class="block text-gray-700 text-sm font-bold mb-2">Mulai Masuk</label>

                <input type="time" name="mulai_masuk" required class="w-full border rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">

            </div>



            <div>

                <label class="block text-gray-700 text-sm font-bold mb-2">Akhir Masuk</label>

                <input type="time" name="akhir_masuk" required class="w-full border rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">

            </div>



            <div>

                <label class="block text-gray-700 text-sm font-bold mb-2">Mulai Keluar</label>

                <input type="time" name="mulai_keluar" required class="w-full border rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">

            </div>



            <div>

                <label class="block text-gray-700 text-sm font-bold mb-2">Akhir Keluar</label>

                <input type="time" name="akhir_keluar" required class="w-full border rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">

            </div>

        </div>



        <div class="mt-8 pt-6 border-t border-gray-100 flex justify-end gap-4">

            <a href="index.php" class="px-6 py-2 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50">Batal</a>

            <button type="submit" class="px-6 py-2 rounded-lg bg-blue-600 text-white font-bold hover:bg-blue-700 shadow">

                <i class="fas fa-save mr-2"></i> Simpan

            </button>

        </div>

    </form>

</div>



<?php require_once '../layout/footer.php'; ?>

