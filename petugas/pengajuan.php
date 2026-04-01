<?php



require_once '../config/database.php';



require_once '../config/session.php';







// Memastikan hanya petugas atau admin dengan bagian_id yang bisa mengakses

if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'petugas' && !($_SESSION['role'] === 'admin' && isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] !== NULL))) {

    header("Location: ../auth/login-v2.php");

    exit;

}







$petugas_id = $_SESSION['petugas_id'];

require_once '../includes/functions.php';



$petugas = getPetugasById($conn, $petugas_id);

if (!$petugas) {

    die('Petugas tidak ditemukan');

}



$bagian_id = $petugas['bagian_id'];





$tanggal_hari_ini = date('Y-m-d');







$shiftList = [];



$stmtShift = $conn->prepare("

    SELECT nama_shift

    FROM shift

    WHERE bagian_id = ?

      AND is_active = 1

    ORDER BY mulai_masuk

");

$stmtShift->bind_param("i", $bagian_id);

$stmtShift->execute();



$shiftRows = stmtFetchAllAssoc($stmtShift);

foreach ($shiftRows as $row) {

    $shiftList[] = $row['nama_shift'];

}



$stmtShift->close();

$shift_default = $_SESSION['shift_nama'] ?? ($shiftList[0] ?? '');











$cekComplete = $conn->prepare("SELECT id FROM absensi WHERE petugas_id = ? AND tanggal = ? AND jam_masuk IS NOT NULL AND jam_masuk <> '' AND jam_keluar IS NOT NULL AND jam_keluar <> '' LIMIT 1");



$cekComplete->bind_param('is', $petugas_id, $tanggal_hari_ini);



$cekComplete->execute();



$resComplete = stmtFetchAssoc($cekComplete);

$cekComplete->close();















$qOpen = $conn->prepare("SELECT id, tanggal, jam_masuk, jam_keluar FROM absensi WHERE petugas_id = ? AND tanggal = ? AND jam_masuk IS NOT NULL AND jam_masuk <> '' AND (jam_keluar IS NULL OR jam_keluar = '') ORDER BY id DESC LIMIT 1");
if ($qOpen) {
    $qOpen->bind_param('is', $petugas_id, $tanggal_hari_ini);
    $qOpen->execute();
    $openAbsen = stmtFetchAssoc($qOpen);
    $qOpen->close();
} else {
    $openAbsen = null;
}










?>



<!DOCTYPE html>



<html lang="id">



<head>



    <meta charset="UTF-8">



    <meta name="viewport" content="width=device-width, initial-scale=1.0">



    <title>Pengajuan Izin/Sakit</title>



    <script src="https://cdn.tailwindcss.com"></script>



    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">



</head>



<body class="bg-gray-50 pb-20">







    <div class="bg-purple-700 p-4 text-white shadow-md flex items-center gap-4">



        <a href="dashboard-v2.php" class="text-white"><i class="fas fa-arrow-left"></i></a>



        <h1 class="font-bold text-lg">Form Pengajuan</h1>



    </div>







    <div class="p-5">

<?php
$flash_success = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : '';
$flash_error = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : '';
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>
<?php if ($flash_success): ?>
<div class="mb-4 p-4 rounded-lg bg-green-100 text-green-700">
    <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($flash_success) ?>
</div>
<?php endif; ?>
<?php if ($flash_error): ?>
<div class="mb-4 p-4 rounded-lg bg-red-100 text-red-700">
    <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($flash_error) ?>
</div>
<?php endif; ?>

  <form action="../api/pengajuan-process.php"

      method="POST"

      enctype="multipart/form-data">







            <div class="mb-4">



                <label class="block text-gray-700 font-bold mb-2 text-sm">Jenis Pengajuan</label>



                <select name="jenis" class="w-full border border-gray-300 rounded-xl p-3 bg-white focus:ring-2 focus:ring-purple-500 outline-none">



                    <option value="izin">Izin (Keperluan Pribadi)</option>



                    <option value="sakit">Sakit</option>



                    <option value="lupa absen">Lupa Absen</option>



                </select>



            </div>



		<!-- Upload Bukti Sakit -->

<div class="mb-4 hidden" id="uploadSakitWrap">

    <label class="block text-gray-700 font-bold mb-2 text-sm">

        Bukti Sakit (Surat Dokter)

    </label>



    <!-- Icon Kamera -->

    <label for="fotoSakit"

        class="flex items-center gap-3 border border-gray-300 rounded-xl p-3 cursor-pointer hover:bg-gray-50">



        <i class="fas fa-camera text-purple-700 text-2xl"></i>



        <span class="text-gray-600 text-sm">

            Klik untuk upload foto bukti sakit

        </span>

    </label>



<input type="file"

    name="foto_sakit"

    id="fotoSakit"

    accept="image/*"
    class="hidden"

    onchange="previewFotoSakit(event)">

 <img id="preview_sakit"

        src=""

        alt="Preview Bukti Sakit"

        class="mt-3 rounded-xl shadow-lg"

        style="display:none; max-width:250px;">



    <p class="text-xs text-gray-400 mt-2">

        *Upload hanya muncul jika memilih sakit.

    </p>

</div>







            <div class="mb-4">



                <label class="block text-gray-700 font-bold mb-2 text-sm">Untuk Tanggal</label>



                <input type="date" name="tanggal" required value="<?= date('Y-m-d'); ?>"



                    class="w-full border border-gray-300 rounded-xl p-3 focus:ring-2 focus:ring-purple-500 outline-none">



            </div>







            <div class="mb-4 hidden" id="shiftFieldWrap">



                <label class="block text-gray-700 font-bold mb-2 text-sm">Shift</label>



                <select name="shift" id="shiftInput" class="w-full border border-gray-300 rounded-xl p-3 bg-white focus:ring-2 focus:ring-purple-500 outline-none">



                    <?php foreach ($shiftList as $s): ?>



                        <option value="<?= htmlspecialchars($s) ?>" <?= ($shift_default === $s) ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>



                    <?php endforeach; ?>



                </select>



                <p class="text-xs text-gray-400 mt-2">*Wajib dipilih untuk Izin/Sakit/Lupa Absen.</p>



            </div>



            <div class="mb-4 hidden" id="lupaAbsenWrap">



                <label class="block text-gray-700 font-bold mb-2 text-sm">Jenis Lupa Absen</label>



                <select name="jenis_lupa_absen" id="jenisLupaAbsenInput" class="w-full border border-gray-300 rounded-xl p-3 bg-white focus:ring-2 focus:ring-purple-500 outline-none">



                    <option value="masuk">Lupa Absen Masuk</option>



                    <option value="keluar">Lupa Absen Keluar</option>



                </select>



            </div>







            <div class="mb-6">



                <label class="block text-gray-700 font-bold mb-2 text-sm">Alasan / Keterangan</label>



                <textarea name="keterangan" rows="4" required 



                    class="w-full border border-gray-300 rounded-xl p-3 focus:ring-2 focus:ring-purple-500 outline-none" 



                    placeholder="Berikan alasan yang jelas..."></textarea>



                <p class="text-xs text-gray-400 mt-2">*Untuk sakit, surat dokter bisa diserahkan menyusul ke admin.</p>



            </div>







            <button type="submit" class="w-full bg-purple-700 hover:bg-purple-800 text-white font-bold py-3 rounded-xl shadow-lg transition">



                AJUKAN PERMOHONAN



            </button>



        </form>







        <div class="mt-8">



            <h3 class="text-gray-500 font-bold text-sm uppercase tracking-wide mb-3">Riwayat Pengajuan</h3>



            <?php



            require_once '../config/database.php';



            $id = $_SESSION['petugas_id'];



            // Check database schema for shift columns

            $pengajuanShiftColumnExists = false;

            $pengajuanShiftIdColumnExists = false;

            $shiftTableExists = false;



            $checkPengajuanShift = $conn->query("SHOW COLUMNS FROM pengajuan LIKE 'shift'");

            if ($checkPengajuanShift && $checkPengajuanShift->num_rows > 0) {

                $pengajuanShiftColumnExists = true;

            }



            $checkPengajuanShiftId = $conn->query("SHOW COLUMNS FROM pengajuan LIKE 'shift_id'");

            if ($checkPengajuanShiftId && $checkPengajuanShiftId->num_rows > 0) {

                $pengajuanShiftIdColumnExists = true;

            }



            $checkShiftTable = $conn->query("SHOW TABLES LIKE 'shift'");

            if ($checkShiftTable && $checkShiftTable->num_rows > 0) {

                $shiftTableExists = true;

            }



            // Build query with proper shift display

            $joinShift = "";

            $shiftDisplayExpr = "'-'";



            if ($shiftTableExists && $pengajuanShiftIdColumnExists) {

                $joinShift = " LEFT JOIN shift s ON p.shift_id = s.id ";

                $shiftDisplayExpr = "COALESCE(s.nama_shift, '-')";

            } else if ($pengajuanShiftColumnExists) {

                $shiftDisplayExpr = "COALESCE(p.shift, '-')";

            }



            $query = "SELECT p.*, {$shiftDisplayExpr} AS shift_display FROM pengajuan p {$joinShift} WHERE p.petugas_id = '$id' ORDER BY p.created_at DESC LIMIT 5";

            $hist = $conn->query($query);



            



            if ($hist->num_rows > 0):



                while($row = $hist->fetch_assoc()):



                    // Warna badge status



                switch ($row['status']) {

    case 'pending':

        $statusColor = 'bg-yellow-100 text-yellow-800';

        break;



    case 'disetujui':

        $statusColor = 'bg-green-100 text-green-800';

        break;



    case 'ditolak':

        $statusColor = 'bg-red-100 text-red-800';

        break;



    default:

        $statusColor = 'bg-gray-100 text-gray-800';

        break;

}



            ?>



                <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 mb-3 flex justify-between items-center">



                    <div>



                        <p class="font-bold text-gray-800 capitalize"><?= $row['jenis']; ?></p>



                        <p class="text-xs text-gray-500"><?= date('d M Y', strtotime($row['tanggal'])); ?></p>



                        <p class="text-xs text-gray-500">Shift: <?= $row['shift_display'] ?></p>



                    </div>



                    <span class="text-xs font-bold px-2 py-1 rounded <?= $statusColor ?>">



                        <?= ucfirst($row['status']); ?>



                    </span>



                </div>



            <?php endwhile; else: ?>



                <p class="text-center text-gray-400 text-sm">Belum ada pengajuan.</p>



            <?php endif; ?>



        </div>



    </div>







    <script>

(function () {



    var jenisEl = document.querySelector('select[name="jenis"]');

    var wrapShift = document.getElementById('shiftFieldWrap');

    var shiftInput = document.getElementById('shiftInput');



    var lupaWrap = document.getElementById('lupaAbsenWrap');

    var lupaInput = document.getElementById('jenisLupaAbsenInput');



    var uploadWrap = document.getElementById("uploadSakitWrap");

    var fileInput = document.getElementById("fotoSakit");



    function sync() {



        var v = (jenisEl.value || '').toLowerCase();



        // Shift muncul untuk semua jenis

        var showShift = (v === 'izin' || v === 'sakit' || v === 'lupa absen');

        wrapShift.classList.toggle('hidden', !showShift);

        shiftInput.required = showShift;



        var showLupa = (v === 'lupa absen');

        lupaWrap.classList.toggle('hidden', !showLupa);

        lupaInput.required = showLupa;



        // Upload hanya untuk sakit

        var showUpload = (v === "sakit");

        uploadWrap.classList.toggle("hidden", !showUpload);

    }



    jenisEl.addEventListener('change', sync);

    sync();



})();





// ? Preview Foto Bukti Sakit

function previewFotoSakit(event) {



    const preview = document.getElementById("preview_sakit");



    if (event.target.files && event.target.files[0]) {



        const reader = new FileReader();



        reader.onload = function(e) {

            preview.src = e.target.result;

            preview.style.display = "block";

        };



        reader.readAsDataURL(event.target.files[0]);

    }

}

</script>





</body>



</html>