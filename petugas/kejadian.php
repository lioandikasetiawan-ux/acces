<?php

require_once '../config/database.php';

require_once '../config/session.php';



if (!isset($_SESSION['petugas_id'])) {

    header("Location: ../auth/login-v2.php");

    exit;

}



// Memastikan hanya petugas atau admin dengan bagian_id yang bisa mengakses
if (isset($_SESSION['role']) && ($_SESSION['role'] !== 'petugas' && !($_SESSION['role'] === 'admin' && isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] !== NULL))) {
    header("Location: ../auth/login-v2.php");
    exit;
}



$id_petugas = $_SESSION['petugas_id'];

// Ambil kode_sync dari session
$kode_sync = isset($_SESSION['kode_sync']) ? (int)$_SESSION['kode_sync'] : 0;

// Ambil Riwayat Kejadian (Semua riwayat user ini)

$q_riwayat = "SELECT * FROM kejadian WHERE petugas_id = '$id_petugas' ORDER BY created_at DESC";

$res_riwayat = $conn->query($q_riwayat);

?>



<!DOCTYPE html>

<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Lapor Kejadian</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

</head>

<body class="bg-gray-50 pb-20">



    <div class="bg-orange-600 p-4 text-white shadow-md flex items-center gap-4 sticky top-0 z-10">

        <a href="dashboard-v2.php" class="text-white"><i class="fas fa-arrow-left"></i></a>

        <h1 class="font-bold text-lg">Lapor Kejadian</h1>

    </div>



    <div class="p-5 max-w-lg mx-auto">

        <?php if(isset($_SESSION['error_message'])): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-4 flex items-center gap-2">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= $_SESSION['error_message'] ?></span>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <?php if(isset($_SESSION['success_message'])): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-4 flex items-center gap-2">
                <i class="fas fa-check-circle"></i>
                <span><?= $_SESSION['success_message'] ?></span>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <div class="bg-white rounded-xl shadow-md border border-gray-100 overflow-hidden mb-8">

            <div class="bg-orange-50 p-3 border-b border-orange-100 flex items-center gap-2">

                <i class="fas fa-pen text-orange-600"></i>

                <h2 class="text-sm font-bold text-gray-700">Form Laporan Baru</h2>

            </div>

            

            <form action="../api/kejadian-process.php" method="POST" enctype="multipart/form-data" class="p-5">
	
	<input type="hidden" name="latitude" id="latitude">

                <input type="hidden" name="longitude" id="longitude">

                <input type="hidden" name="kode_sync" value="<?= $kode_sync ?>">

                <input type="hidden" name="jenis_laporan" id="jenisLaporanInput" value="">

                

                <div class="mb-4">

                    <label class="block text-gray-600 text-xs font-bold mb-2 uppercase">Bukti Foto</label>

                    <div class="flex gap-2 mb-3">
                        <button type="button" onclick="openGallery()" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-2 rounded-lg text-sm">Pilih dari Galeri</button>
                    </div>
                    <div class="relative w-full h-40 bg-gray-100 border-2 border-dashed border-gray-300 rounded-lg flex flex-col items-center justify-center cursor-pointer hover:bg-gray-50 transition overflow-hidden" onclick="openGallery()">
                        <img id="preview" class="absolute inset-0 w-full h-full object-cover hidden">
                        <div id="placeholder" class="text-center p-4">
                            <i class="fas fa-camera text-3xl text-gray-400 mb-2"></i>
                            <p class="text-xs text-gray-500">Klik untuk ambil/pilih foto</p>
                        </div>
                    </div>
                    <input type="file" name="foto_kejadian" id="fotoInput" accept="image/*" required class="hidden">
                    <input type="file" name="foto_kejadian_camera" id="fotoCameraInput" accept="image/*" capture="environment" class="hidden">
                </div>


                <?php if (in_array($kode_sync, [1, 5, 6])): ?>
                <div class="mb-4">
                    <label class="block text-gray-600 text-xs font-bold mb-2 uppercase">Jenis Laporan <span class="text-red-500">*</span></label>
                    <div class="grid grid-cols-1 gap-2" id="jenisLaporanGroup">
                        <button type="button" class="jenis-btn border-2 border-gray-300 rounded-lg py-2.5 px-3 text-sm font-semibold text-gray-700 bg-white hover:bg-gray-50 transition text-left" data-value="Laporan Kejadian Banjir"><i class="fas fa-water mr-2 text-blue-500"></i>Laporan Kejadian Banjir</button>
                        <button type="button" class="jenis-btn border-2 border-gray-300 rounded-lg py-2.5 px-3 text-sm font-semibold text-gray-700 bg-white hover:bg-gray-50 transition text-left" data-value="Laporan Lokasi Kritis Sungai"><i class="fas fa-exclamation-triangle mr-2 text-yellow-500"></i>Laporan Lokasi Kritis Sungai</button>
                        <button type="button" class="jenis-btn border-2 border-gray-300 rounded-lg py-2.5 px-3 text-sm font-semibold text-gray-700 bg-white hover:bg-gray-50 transition text-left" data-value="Laporan Kerusakan Bangunan"><i class="fas fa-building mr-2 text-red-500"></i>Laporan Kerusakan Bangunan</button>
                        <button type="button" class="jenis-btn border-2 border-gray-300 rounded-lg py-2.5 px-3 text-sm font-semibold text-gray-700 bg-white hover:bg-gray-50 transition text-left" data-value="Laporan Pelanggaran SDA"><i class="fas fa-gavel mr-2 text-purple-500"></i>Laporan Pelanggaran SDA</button>
                        <button type="button" class="jenis-btn border-2 border-gray-300 rounded-lg py-2.5 px-3 text-sm font-semibold text-gray-700 bg-white hover:bg-gray-50 transition text-left" data-value="Laporan Lainnya"><i class="fas fa-ellipsis-h mr-2 text-gray-500"></i>Laporan Lainnya</button>
                    </div>
                </div>

                <div id="laporanLainnyaWrap" class="mb-4 hidden">
                    <label class="block text-gray-600 text-xs font-bold mb-2 uppercase">Keterangan Laporan Lainnya <span class="text-red-500">*</span></label>
                    <textarea name="laporan_lainnya_text" id="laporanLainnyaText" rows="2" class="w-full border rounded-lg p-3 focus:ring-2 focus:ring-orange-500 outline-none text-sm" placeholder="Jelaskan jenis laporan lainnya..."></textarea>
                </div>

                <div id="kerusakanBangunanWrap" class="mb-4 hidden">
                    <label class="block text-gray-600 text-xs font-bold mb-2 uppercase">Jenis Kerusakan Bangunan <span class="text-red-500">*</span></label>
                    <select name="jenis_kerusakan_bangunan" id="jenisKerusakanSelect" class="w-full border rounded-lg p-3 text-sm focus:ring-2 focus:ring-orange-500 outline-none bg-white">
                        <option value="">-- Pilih Jenis Bangunan --</option>
                        <option value="Bendung">Bendung</option>
                        <option value="Embung">Embung</option>
                        <option value="Situ">Situ</option>
                        <option value="Pintu Air Sungai">Pintu Air Sungai</option>
                    </select>
                </div>
                <?php endif; ?>

                <div class="mb-4">

                    <label class="block text-gray-600 text-xs font-bold mb-2 uppercase">Deskripsi Kejadian <span class="text-red-500">*</span></label>

                    <textarea name="deskripsi" rows="4" required class="w-full border rounded-lg p-3 focus:ring-2 focus:ring-orange-500 outline-none text-sm" placeholder="Jelaskan detail kejadian, lokasi, dan waktu..."></textarea>

                </div>



                <button
    id="btnSubmit"
    type="submit"
    disabled
    class="w-full bg-orange-400 text-white font-bold py-3 rounded-xl shadow-lg">
    Mengambil lokasi...
</button>

            </form>

        </div>



        <div class="flex items-center gap-2 mb-4">

            <i class="fas fa-history text-gray-400"></i>

            <h2 class="text-gray-500 text-sm font-bold uppercase tracking-wider">Riwayat Laporan Anda</h2>

        </div>



        <div class="space-y-3">

            <?php if($res_riwayat->num_rows > 0): ?>

                <?php while($row = $res_riwayat->fetch_assoc()): ?>

                    <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 flex gap-4 items-start">

                    <div class="flex-shrink-0">
                        <?php if(!empty($row['foto'])): ?>
                            <img src="<?= $row['foto'] ?>" class="w-16 h-16 rounded-lg object-cover border bg-gray-100 shadow-sm" onerror="this.src='../assets/img/no-image.png';">
                        <?php else: ?>
                            <div class="w-16 h-16 rounded-lg bg-gray-100 flex items-center justify-center border">
                                <i class="fas fa-image text-gray-400"></i>
                            </div>
                        <?php endif; ?>
                    </div>

                        

                        <div class="flex-1 min-w-0">

                            <div class="flex justify-between items-start mb-1">

                                <span class="text-[10px] text-gray-400 font-mono bg-gray-50 px-1 rounded border">

                                    <?= date('d/m/y H:i', strtotime($row['created_at'])) ?>

                                </span>



                                <?php

switch ($row['status']) {
    case 'pending':
        $statusClass = 'bg-yellow-100 text-yellow-700 border-yellow-200';
        break;

    case 'disetujui':
        $statusClass = 'bg-green-100 text-green-700 border-green-200';
        break;

    case 'ditolak':
        $statusClass = 'bg-red-100 text-red-700 border-red-200';
        break;

    default:
        $statusClass = 'bg-gray-100 text-gray-700 border-gray-200';
        break;
}

                                ?>

                             <span class="<?php echo $statusClass; ?> text-[10px] px-2 py-0.5 rounded-full font-bold uppercase border">
    <?php echo $row['status']; ?>
</span>

                                </span>

                            </div>

                            

                            <p class="text-sm text-gray-800 line-clamp-2 leading-snug">

                            <?php echo $row['deskripsi']; ?>


                            </p>

                        </div>

                    </div>

                <?php endwhile; ?>

            <?php else: ?>

                <div class="text-center py-8 bg-white rounded-xl border border-dashed border-gray-300">

                    <div class="bg-gray-50 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-3">

                        <i class="far fa-folder-open text-gray-400 text-2xl"></i>

                    </div>

                    <p class="text-gray-500 text-sm">Belum ada riwayat laporan.</p>

                </div>

            <?php endif; ?>

        </div>



    </div>



    <div id="cameraModal" class="fixed inset-0 bg-black/70 hidden items-center justify-center p-4 z-50">

        <div class="bg-white w-full max-w-md rounded-xl overflow-hidden">

            <div class="px-4 py-3 border-b flex items-center justify-between">

                <div class="font-bold text-gray-700 text-sm">Kamera</div>

                <button type="button" class="text-gray-500" onclick="closeCameraModal()">Tutup</button>

            </div>

            <div class="p-4">

                <video id="cameraVideo" autoplay playsinline class="w-full rounded-lg bg-black"></video>

                <canvas id="cameraCanvas" class="hidden"></canvas>

                <div class="flex gap-2 mt-3">

                    <button type="button" onclick="takeSnapshot()" class="flex-1 bg-orange-600 hover:bg-orange-700 text-white font-bold py-2 rounded-lg text-sm">Ambil Foto</button>

                </div>

                <p class="text-xs text-gray-500 mt-2">Jika kamera tidak muncul, browser akan memakai pemilih file (kamera/galeri).</p>

            </div>

        </div>

    </div>



    <script>

        var cameraStream = null;



        function handleFile(file) {

            if (!file) return;



            var reader = new FileReader();

            reader.onload = function (e) {

                document.getElementById('preview').src = e.target.result;

                document.getElementById('preview').classList.remove('hidden');

                document.getElementById('placeholder').classList.add('hidden');

            };

            reader.readAsDataURL(file);



            try {

                var input = document.getElementById('fotoInput');

                var dt = new DataTransfer();

                dt.items.add(file);

                input.files = dt.files;

            } catch (e) {

            }

        }



        function openGallery() {

            var input = document.getElementById('fotoInput');

            input.value = '';

            input.click();

        }



      



        function closeCameraModal() {

            var modal = document.getElementById('cameraModal');

            var video = document.getElementById('cameraVideo');

            if (cameraStream) {

                cameraStream.getTracks().forEach(function (t) { t.stop(); });

                cameraStream = null;

            }

            video.srcObject = null;

            modal.classList.add('hidden');

            modal.classList.remove('flex');

        }



        function takeSnapshot() {

            var video = document.getElementById('cameraVideo');

            var canvas = document.getElementById('cameraCanvas');

            var w = video.videoWidth || 1280;

            var h = video.videoHeight || 720;

            canvas.width = w;

            canvas.height = h;

            var ctx = canvas.getContext('2d');

            ctx.drawImage(video, 0, 0, w, h);

            canvas.toBlob(function (blob) {

                if (!blob) return;

                var file = new File([blob], 'kejadian.jpg', { type: 'image/jpeg' });

                handleFile(file);

                closeCameraModal();

            }, 'image/jpeg', 0.9);

        }



        (function () {

            var input = document.getElementById('fotoInput');

            var cam = document.getElementById('fotoCameraInput');

            if (input) {

                input.addEventListener('change', function () {

                    handleFile(input.files && input.files[0] ? input.files[0] : null);

                });

            }

            if (cam) {

                cam.addEventListener('change', function () {

                    handleFile(cam.files && cam.files[0] ? cam.files[0] : null);

                });

            }

        })();

        // === Jenis Laporan Button Group ===
        (function() {
            var btns = document.querySelectorAll('.jenis-btn');
            var hiddenInput = document.getElementById('jenisLaporanInput');
            var lainnyaWrap = document.getElementById('laporanLainnyaWrap');
            var lainnyaText = document.getElementById('laporanLainnyaText');
            var kerusakanWrap = document.getElementById('kerusakanBangunanWrap');
            var kerusakanSelect = document.getElementById('jenisKerusakanSelect');

            btns.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    btns.forEach(function(b) {
                        b.classList.remove('bg-orange-600', 'text-white', 'border-orange-600');
                        b.classList.add('bg-white', 'text-gray-700', 'border-gray-300');
                    });
                    btn.classList.remove('bg-white', 'text-gray-700', 'border-gray-300');
                    btn.classList.add('bg-orange-600', 'text-white', 'border-orange-600');
                    if (hiddenInput) hiddenInput.value = btn.getAttribute('data-value');

                    var val = btn.getAttribute('data-value');

                    if (lainnyaWrap) {
                        if (val === 'Laporan Lainnya') {
                            lainnyaWrap.classList.remove('hidden');
                            if (lainnyaText) lainnyaText.required = true;
                        } else {
                            lainnyaWrap.classList.add('hidden');
                            if (lainnyaText) { lainnyaText.required = false; lainnyaText.value = ''; }
                        }
                    }

                    if (kerusakanWrap) {
                        if (val === 'Laporan Kerusakan Bangunan') {
                            kerusakanWrap.classList.remove('hidden');
                            if (kerusakanSelect) kerusakanSelect.required = true;
                        } else {
                            kerusakanWrap.classList.add('hidden');
                            if (kerusakanSelect) { kerusakanSelect.required = false; kerusakanSelect.value = ''; }
                        }
                    }
                });
            });

            // Form validation
            var form = document.querySelector('form');
            if (form && hiddenInput) {
                form.addEventListener('submit', function(e) {
                    if (btns.length > 0 && !hiddenInput.value) {
                        e.preventDefault();
                        alert('Pilih salah satu Jenis Laporan!');
                        return;
                    }
                });
            }
        })();

    </script>

<script>
if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
        function (position) {
            document.getElementById('latitude').value  = position.coords.latitude;
            document.getElementById('longitude').value = position.coords.longitude;

            const btn = document.getElementById('btnSubmit');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> KIRIM LAPORAN';
                btn.classList.remove('bg-orange-400');
                btn.classList.add('bg-orange-600');
            }

            console.log("GPS OK:", position.coords.latitude, position.coords.longitude);
        },
        function (error) {
            alert("Lokasi gagal diambil. Aktifkan GPS & refresh halaman.");
            console.error(error);
        },
        {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: 0
        }
    );
} else {
    alert("Browser tidak mendukung GPS");
}
</script>



</body>

</html>