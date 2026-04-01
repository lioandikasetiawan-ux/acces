<?php

require_once '../../config/database.php';

require_once '../../config/session.php';



if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'superadmin'], true)) {

    header('Location: ../../auth/login-v2.php');

    exit;

}



$isAdminSuper = ($_SESSION['role'] === 'admin' && empty($_SESSION['bagian_id']));

require_once '../layout/header.php';

require_once '../layout/sidebar.php';

$id = $_GET['id'];

$stmtData = $conn->prepare("SELECT p.*, p.role FROM petugas p WHERE p.id = ?");

$stmtData->bind_param("i", $id);

$stmtData->execute();

$data = $stmtData->get_result()->fetch_assoc();



if(!$data) {

    echo "<script>alert('Data tidak ditemukan!'); window.location='index.php';</script>";

    exit;

}



$bagianTableExists = false;

$lokasiWilayahColumnExists = false;

$bagianList = [];

$koordinatByBagian = [];



$checkBagianTable = $conn->query("SHOW TABLES LIKE 'bagian'");

if ($checkBagianTable && $checkBagianTable->num_rows > 0) {

    $bagianTableExists = true;



    $checkLokasiWilayah = $conn->query("SHOW COLUMNS FROM bagian LIKE 'lokasi_wilayah'");

    if ($checkLokasiWilayah && $checkLokasiWilayah->num_rows > 0) {

        $lokasiWilayahColumnExists = true;

    }



    $selectLokasiWilayah = $lokasiWilayahColumnExists ? "COALESCE(lokasi_wilayah, deskripsi)" : "deskripsi";

    $resBagian = $conn->query("SELECT id, kode_bagian, nama_bagian, $selectLokasiWilayah AS lokasi_wilayah_display FROM bagian WHERE is_active = 1 ORDER BY nama_bagian");

    if ($resBagian) {

        while ($b = $resBagian->fetch_assoc()) {

            $bagianList[] = $b;

        }

    }



    $checkKoordinatTable = $conn->query("SHOW TABLES LIKE 'bagian_koordinat'");

    if ($checkKoordinatTable && $checkKoordinatTable->num_rows > 0) {

        $koordinatBagianCol = 'bagian_id';

        $checkKoordinatBagianId = $conn->query("SHOW COLUMNS FROM bagian_koordinat LIKE 'bagian_id'");

        if (!$checkKoordinatBagianId || $checkKoordinatBagianId->num_rows === 0) {

            $koordinatBagianCol = 'bagian';

        }

        $resKoor = $conn->query("SELECT id, {$koordinatBagianCol} AS bagian_id, nama_titik, latitude, longitude, radius_meter FROM bagian_koordinat WHERE is_active = 1 ORDER BY nama_titik");

        if ($resKoor) {

            while ($k = $resKoor->fetch_assoc()) {

                $bid = (int)$k['bagian_id'];

                if (!isset($koordinatByBagian[$bid])) {

                    $koordinatByBagian[$bid] = [];

                }

                $koordinatByBagian[$bid][] = $k;

            }

        }

    }

}

?>



<div class="max-w-4xl mx-auto">

    <div class="flex items-center gap-4 mb-6">

        <a href="index.php" class="bg-gray-200 hover:bg-gray-300 text-gray-700 p-2 rounded-lg transition">

            <i class="fas fa-arrow-left"></i>

        </a>

        <h1 class="text-2xl font-bold text-gray-800">Edit Petugas</h1>

    </div>



    <div class="bg-white rounded-xl shadow-lg border border-gray-100 overflow-hidden">

        <form action="edit-process.php" method="POST" class="p-6">

            <input type="hidden" name="id" value="<?= $data['id'] ?>">



            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">



                <div class="space-y-4">

                    <h3 class="text-gray-500 font-bold uppercase text-xs mb-4 border-b pb-2">Identitas Personal</h3>



                    <div class="mb-4">

                        <label class="block text-gray-700 text-sm font-bold mb-2">Nama Lengkap</label>

                        <input type="text" name="nama" value="<?= $data['nama'] ?>" required class="w-full border rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">

                    </div>



                    <div class="mb-4">

                        <label class="block text-gray-700 text-sm font-bold mb-2">NIP / NRP</label>
                    
		<input type="text" name="nip" id="nip" value="<?= $data['nip'] ?>" required class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none">
                        <span id="nip-error" class="text-red-600 text-xs font-bold hidden"></span>
		</div>



                    <div class="mb-4">

                        <label class="block text-gray-700 text-sm font-bold mb-2">Password Login</label>

                        <input type="text" name="password" class="w-full border rounded-lg px-3 py-2 bg-yellow-50" placeholder="Kosongkan jika tidak diubah">

                    </div>



                    <div class="mb-4">

                        <label class="block text-gray-700 text-sm font-bold mb-2">Hak Akses (Role)</label>

                        <select name="role" class="w-full border rounded-lg px-3 py-2 bg-blue-50">

                            <option value="petugas" <?= ($data['role'] == 'petugas') ? 'selected' : '' ?>>Petugas Lapangan</option>

                            <option value="admin" <?= ($data['role'] == 'admin') ? 'selected' : '' ?>>Administrator</option>

                        </select>

                    </div>



                    <div class="grid grid-cols-2 gap-4">

                        <div class="mb-4">

                            <label class="block text-gray-700 text-sm font-bold mb-2">Jabatan</label>

                            <input type="text" name="jabatan" value="<?= $data['jabatan'] ?>" required class="w-full border rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">

                        </div>

                        <div class="mb-4">

                            <label class="block text-gray-700 text-sm font-bold mb-2">Kode Jabatan</label>
 			<input type="text" name="kode_jabatan" value="<?= $data['kode_jabatan'] ?>" class="w-full border rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">

                        </div>

                    </div>



                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2">Bagian / Unit</label>
                        <?php 
                        // Cek apakah user yang DIEDIT adalah Superadmin (Admin tanpa bagian)
                        $isTargetSuperadmin = ($data['role'] === 'admin' && empty($data['bagian_id']));
                        
                        if ($isTargetSuperadmin): 
                        ?>
                            <div class="w-full border rounded-lg px-3 py-2 bg-purple-50 text-purple-800 font-semibold flex items-center">
                                <i class="fas fa-user-shield mr-2"></i> Superadmin (Akses Seluruh Bagian)
                            </div>
                            <input type="hidden" name="bagian_id" value="0">
                            <p class="text-xs text-gray-500 mt-1">Superadmin tidak terikat pada satu bagian.</p>
                        <?php else: ?>
                            <?php if ($bagianTableExists && !empty($bagianList)): ?>
                                <?php if (!$isAdminSuper): // If logged in user is NOT super admin, keep it disabled ?>
                                    <input type="hidden" name="bagian_id" value="<?= isset($data['bagian_id']) ? (int)$data['bagian_id'] : 0 ?>">
                                <?php endif; ?>
                                <select name="bagian_id" class="w-full border rounded-lg px-3 py-2 <?= $isAdminSuper ? '' : 'bg-gray-100 outline-none cursor-not-allowed' ?>" <?= $isAdminSuper ? '' : 'disabled' ?>>
                                    <option value="0">Current: <?= htmlspecialchars($data['bagian'] ?? '-') ?></option>
                                    <?php foreach ($bagianList as $b): ?>
                                        <?php
                                            $selected = '';
                                            if (isset($data['bagian_id']) && (int)$data['bagian_id'] === (int)$b['id']) {
                                                $selected = 'selected';
                                            } elseif (!empty($data['bagian']) && $data['bagian'] === $b['nama_bagian']) {
                                                $selected = 'selected';
                                            }
                                        ?>
                                        <option value="<?= (int)$b['id'] ?>" <?= $selected ?>>
                                            <?= htmlspecialchars($b['nama_bagian']) ?> (<?= htmlspecialchars($b['kode_bagian']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="hidden" name="bagian" value="<?= htmlspecialchars($data['bagian'] ?? '') ?>">
                                <input type="text" class="w-full border rounded-lg px-3 py-2 bg-gray-100 outline-none cursor-not-allowed" value="<?= htmlspecialchars($data['bagian'] ?? '') ?>" disabled>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>



                    <div class="mb-4">

                        <label class="block text-gray-700 text-sm font-bold mb-2">Job Desc</label>

                        <textarea name="job_desc" rows="3" class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none" placeholder="Tulis deskripsi pekerjaan..."><?= htmlspecialchars($data['job_desc'] ?? '') ?></textarea>

                    </div>

                </div>



                <div class="space-y-4">

                    <div>

                        <label class="block text-gray-700 text-sm font-bold mb-2">Alamat Lengkap (Auto-Fill)</label>

                        <textarea name="alamat" id="alamat" rows="3" required class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 bg-green-50" placeholder="Alamat akan muncul otomatis saat peta diklik..."><?= $data['alamat'] ?></textarea>

                    </div>

                </div>

            </div>



            <div class="mt-8 pt-6 border-t border-gray-100 flex justify-end gap-4">

                <a href="index.php" class="px-6 py-2 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50">Batal</a>

                <button type="submit" class="px-6 py-2 rounded-lg bg-blue-600 text-white font-bold hover:bg-blue-700 shadow">

                    <i class="fas fa-save mr-2"></i> Update Data

                </button>

            </div>

        </form>

    </div>

</div>
<script>
const currentId = <?= (int)$data['id'] ?>;

// Real-time validation for NIP
const nipInput = document.getElementById('nip');
const nipError = document.getElementById('nip-error');
let nipTimeout = null;

nipInput.addEventListener('input', function() {
    clearTimeout(nipTimeout);
    const nip = this.value.trim();
    
    if (nip.length < 3) {
        nipError.classList.add('hidden');
        return;
    }
    
    nipTimeout = setTimeout(async () => {
        try {
            const response = await fetch('check-duplicate.php?field=nip&value=' + encodeURIComponent(nip) + '&exclude_id=' + currentId);
            const data = await response.json();
            
            if (data.exists) {
                nipError.textContent = '?? NIP sudah digunakan petugas lain!';
                nipError.classList.remove('hidden');
                nipInput.classList.add('border-red-500');
            } else {
                nipError.classList.add('hidden');
                nipInput.classList.remove('border-red-500');
            }
        } catch (e) {
            console.error('Error checking NIP:', e);
        }
    }, 500);
});
// Prevent form submission if duplicates exist
const form = document.querySelector('form');
form.addEventListener('submit', function(e) {
    if (!nipError.classList.contains('hidden')) {
        e.preventDefault();
        alert('Tidak dapat menyimpan! NIP sudah digunakan.');
        return false;
    }
});</script>

<?php require_once '../layout/footer.php'; ?>
