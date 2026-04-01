<?php
require_once '../../config/database.php';
require_once '../../config/session.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'superadmin'], true)) {
    header('Location: ../../auth/login-v2.php');
    exit;
}

require_once '../layout/header.php';
require_once '../layout/sidebar.php';

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

    if($_SESSION['bagian_id']==""){
	$sqlbagian="SELECT id, kode_bagian, nama_bagian, $selectLokasiWilayah AS lokasi_wilayah_display FROM bagian WHERE is_active = 1 ORDER BY nama_bagian";		
    } else { 
	$sqlbagian="SELECT id, kode_bagian, nama_bagian, $selectLokasiWilayah AS lokasi_wilayah_display FROM bagian WHERE is_active = 1 AND id=".$_SESSION['bagian_id']." ORDER BY nama_bagian";
    }

    $resBagian = $conn->query($sqlbagian);
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

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style> 
    #map { height: 400px; width: 100%; z-index: 0; } 
    /* Spinner Loading */
    .loader { border: 2px solid #f3f3f3; border-top: 2px solid #3498db; border-radius: 50%; width: 16px; height: 16px; animation: spin 1s linear infinite; display: none; }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
</style>

<div class="max-w-5xl mx-auto">
    <div class="flex items-center gap-4 mb-6">
        <a href="index.php" class="bg-gray-200 hover:bg-gray-300 text-gray-700 p-2 rounded-lg transition"><i class="fas fa-arrow-left"></i></a>
        <h1 class="text-2xl font-bold text-gray-800">Tambah Data Petugas</h1>
    </div>

    <div class="bg-white rounded-xl shadow-lg border border-gray-100 overflow-hidden">
        <form action="create-process.php" method="POST" class="p-6">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                
                <div class="space-y-4">
                    <h3 class="text-gray-500 font-bold uppercase text-xs border-b pb-2">Identitas Personal</h3>
                    
                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2">Nama Lengkap</label>
                        <input type="text" name="nama" required class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>

                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2">NIP / NRP (Username)</label>
                         <input type="text" name="nip" id="nip" required class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none">
                        <span id="nip-error" class="text-red-600 text-xs font-bold hidden"></span>                   </div>

                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2">Password Login</label>
                        <input type="password" name="password" required class="w-full border rounded-lg px-3 py-2 bg-yellow-50">
                    </div>

                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2">Hak Akses (Role)</label>
                        <select name="role" class="w-full border rounded-lg px-3 py-2 bg-blue-50">
			<?php if($_SESSION['bagian_id']==''){?>		
                            <option value="petugas">Petugas Lapangan</option>
                            <option value="admin">Administrator</option>
                        <?php }else {?>
                            <option value="petugas">Petugas Lapangan</option>
			<?php } ?>
			</select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2">Jabatan</label>
                            <input type="text" name="jabatan" required class="w-full border rounded-lg px-3 py-2 outline-none">
                        </div>
                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2">Kode Jabatan</label>
			<input type="text" name="kode_jabatan" class="w-full border rounded-lg px-3 py-2 outline-none">			
                        </div>
                    </div>

                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2">Job Desc</label>
                        <textarea name="job_desc" rows="3" class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 outline-none" placeholder="Tulis deskripsi pekerjaan..."></textarea>
                    </div>
                </div>

                <div class="space-y-4">
                   <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2">Bagian / Unit</label>
				<?php //echo $_SESSION['bagian_id'];?>
                            <select name="bagian_id" id="bagianSelect" class="w-full border rounded-lg px-3 py-2 bg-white" <?= ($_SESSION['bagian_id'] != '') ? 'disabled' : '' ?>>
                                <?php foreach ($bagianList as $b): ?>
                                    <option value="<?= (int)$b['id'] ?>" data-lokasi="<?= htmlspecialchars($b['lokasi_wilayah_display'] ?? '') ?>">
                                        <?= htmlspecialchars($b['nama_bagian']) ?> (<?= htmlspecialchars($b['kode_bagian']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($_SESSION['bagian_id'] != ''): ?>
                                <input type="hidden" name="bagian_id" value="<?= $_SESSION['bagian_id'] ?>">
                            <?php endif; ?>
                    </div>


                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2">Alamat Lengkap </label>
                        <textarea name="alamat" id="alamat" rows="3" required class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500 bg-green-50" placeholder="isi Alamat Lengkap"></textarea>
                    </div>
                </div>
            </div>

            <div class="mt-8 pt-6 border-t border-gray-100 flex justify-end gap-4">
                <a href="index.php" class="px-6 py-2 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50">Batal</a>
                <button type="submit" class="px-6 py-2 rounded-lg bg-blue-600 text-white font-bold hover:bg-blue-700 shadow transform hover:scale-105 transition">
                    <i class="fas fa-save mr-2"></i> Simpan Data
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Data koordinat per bagian
const koordinatByBagian = <?= json_encode($koordinatByBagian) ?>;

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
            const response = await fetch('check-duplicate.php?field=nip&value=' + encodeURIComponent(nip));
            const data = await response.json();
            
            if (data.exists) {
                nipError.textContent = 'NIP sudah terdaftar !!';
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
        alert('Tidak dapat menyimpan! Ada data yang duplikat. Silakan perbaiki terlebih dahulu.');
        return false;
    }
    
});
</script>



<?php require_once '../layout/footer.php'; ?>