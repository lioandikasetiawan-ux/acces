<?php
require_once '../config/database.php';
require_once '../config/session.php';

// Cek ID Absensi hari ini (Laporan harus nempel ke Absensi)

$petugas_id = isset($_SESSION['petugas_id'])
    ? (int)$_SESSION['petugas_id']
    : (int)($_SESSION['user_id'] ?? 0);
if ($petugas_id <= 0) {
    header('Location: ../auth/login-v2.php');
    exit;
}
//CEK ID kode_bagian
$qb = $conn->query("SELECT kode_sync FROM bagian WHERE id = ".$_SESSION['bagian_id']." ORDER BY id DESC LIMIT 1");
$kode_sync = $qb->fetch_assoc();
$sync=$kode_sync['kode_sync'];

$tanggal = date('Y-m-d');
$q = $conn->query("SELECT id, jam_masuk, jam_keluar FROM absensi WHERE petugas_id = '$petugas_id' AND tanggal = '$tanggal' AND jam_masuk IS NOT NULL AND jam_masuk <> '' AND (jam_keluar IS NULL OR jam_keluar = '') ORDER BY id DESC LIMIT 1");

$absen = $q->fetch_assoc();

if (!$absen || empty($absen['jam_masuk'])) {
    echo "<script>alert('Anda harus Absen Masuk dulu sebelum mengisi laporan!'); window.location='dashboard-v2.php';</script>";
    exit;
}
$absensi_id = $absen['id'];
?>

<!DOCTYPE html>

<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Laporan Kegiatan</title>

    <script src="https://cdn.tailwindcss.com"></script>

</head>

<body class="bg-gray-50 pb-20">



    <div class="bg-blue-600 p-4 text-white shadow-md mb-4">

        <h1 class="font-bold text-lg"><i class="fas fa-clipboard-list mr-2"></i>Laporan Kegiatan</h1>

        <p class="text-xs opacity-80"><?= date('d F Y'); ?></p>

    </div>



    <form action="../api/laporan-process.php" method="POST" enctype="multipart/form-data" class="px-4">

        <input type="hidden" name="absensi_id" value="<?= $absensi_id ?>">
        <input type="hidden" name="latitude" id="latitudeInput">
        <input type="hidden" name="longitude" id="longitudeInput">
<?php 
// 1. UP3BK
// 2. UPI
// 3. UPB
// 4. SISDA
?>

        <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 mb-4">
            <h3 class="font-bold text-gray-700 mb-3 border-b pb-2">Data Pemantauan</h3> 
            <div class="mb-3">
   	    <?php if ($sync==1) {?>	
                <label class="block text-sm text-gray-600 mb-1">Ketersediaan Air</label>
                <select name="ketersediaan_air" id="ketersediaan_air" class="w-full border rounded-lg p-2 bg-white">
                    <option value="menggenang">Menggenang</option>
                    <option value="mengalir">Mengalir</option>
                    <option value="kering">Kering</option>
                </select>
	   <?php } else if ($sync==2){?>	
            	<label class="block text-sm text-gray-600 mb-1">Kegiatan Harian</label>
                <select name="ketersediaan_air" id="ketersediaan_air" class="w-full border rounded-lg p-2 bg-white">
                    <option value="Koordinasi">Koordinasi</option>
                    <option value="Monitoring">Monitoring</option>
                    <option value="Survey">Survey</option>
                    <option value="Kegiatan Lainya">Kegiatan Lainya</option>
                </select>
		<?php } ?>		
		</div>

            <div class="grid grid-cols-2 gap-4">

   	    <?php if ($sync==2) {?>	
            <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 mb-4">
            <label class="block text-gray-700 font-bold mb-2">Informasi Detail Kegiatan</label>
            <textarea name="detail_kegiatan" rows="4" required class="w-full border rounded-lg p-3 focus:ring-2 focus:ring-blue-500 outline-none" placeholder="Contoh: Pembersihan sampah di pintu air..."></textarea>
        </div>

		<?php } ?>

            </div>

        </div>

        <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 mb-4">
            <label class="block text-gray-700 font-bold mb-2">Uraian Kegiatan</label>
            <textarea name="kegiatan" rows="4" required class="w-full border rounded-lg p-3 focus:ring-2 focus:ring-blue-500 outline-none" placeholder="Contoh: Pembersihan sampah di pintu air..."></textarea>
        </div>



        <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 mb-6">

            <label class="block text-gray-700 font-bold mb-2">Foto Dokumentasi</label>

            <div class="flex gap-2 mb-3">

                <button type="button" onclick="openCamera()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 rounded-lg text-sm">Buka Kamera</button>

                <button type="button" onclick="openGallery()" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-2 rounded-lg text-sm">Pilih dari Galeri</button>

            </div>

            <div id="fotoPreviewWrap" class="hidden mb-3">

                <img id="fotoPreview" src="" class="w-full rounded-lg border object-cover" />

            </div>

            <input type="file" name="foto_laporan" id="fotoLaporanInput" accept="image/*" required class="w-0 h-0 opacity-0 absolute" />

            <input type="file" name="foto_laporan_camera" id="fotoLaporanCameraInput" accept="image/*" capture="environment" class="w-0 h-0 opacity-0 absolute" />

            <p class="text-xs text-gray-400 mt-1">*Ambil foto kondisi lapangan terkini</p>

        </div>



        <button type="submit" class="w-full bg-blue-600 text-white font-bold py-3 rounded-xl shadow-lg hover:bg-blue-700 transition">

            KIRIM LAPORAN

        </button>

        <a href="index.php" class="block text-center mt-4 text-gray-500 text-sm">Batal</a>



    </form>



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

                    <button type="button" onclick="takeSnapshot()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 rounded-lg text-sm">Ambil Foto</button>

                </div>

                <p class="text-xs text-gray-500 mt-2">Jika kamera tidak muncul, browser akan memakai pemilih file (kamera/galeri).</p>

            </div>

        </div>

    </div>



    <script>

        var cameraStream = null;



        function openCamera() {

            if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {

                navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false })

                    .then(function (stream) {

                        cameraStream = stream;

                        var modal = document.getElementById('cameraModal');

                        var video = document.getElementById('cameraVideo');

                        video.srcObject = stream;

                        modal.classList.remove('hidden');

                        modal.classList.add('flex');

                    })

                    .catch(function () {

                        var cam = document.getElementById('fotoLaporanCameraInput');

                        cam.value = '';

                        cam.click();

                    });

            } else {

                var cam = document.getElementById('fotoLaporanCameraInput');

                cam.value = '';

                cam.click();

            }

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

            var input = document.getElementById('fotoLaporanInput');

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

                var file = new File([blob], 'laporan.jpg', { type: 'image/jpeg' });



                try {

                    var dt = new DataTransfer();

                    dt.items.add(file);

                    input.files = dt.files;

                } catch (e) {

                }



                closeCameraModal();

                var wrap = document.getElementById('fotoPreviewWrap');

                var img = document.getElementById('fotoPreview');

                if (wrap && img) {

                    var url = URL.createObjectURL(file);

                    img.src = url;

                    wrap.classList.remove('hidden');

                }

            }, 'image/jpeg', 0.9);

        }



        function openGallery() {

            var input = document.getElementById('fotoLaporanInput');

            input.value = '';

            input.click();

        }



        (function () {

            var input = document.getElementById('fotoLaporanInput');

            var cam = document.getElementById('fotoLaporanCameraInput');

            var wrap = document.getElementById('fotoPreviewWrap');

            var img = document.getElementById('fotoPreview');

            if (!input || !cam || !wrap || !img) return;



            function handleFile(file) {

                if (!file) {

                    wrap.classList.add('hidden');

                    img.src = '';

                    return;

                }



                var url = URL.createObjectURL(file);

                img.src = url;

                wrap.classList.remove('hidden');



                try {

                    var dt = new DataTransfer();

                    dt.items.add(file);

                    input.files = dt.files;

                } catch (e) {

                }

            }



            input.addEventListener('change', function () {

                handleFile(input.files && input.files[0] ? input.files[0] : null);

            });



            cam.addEventListener('change', function () {

                handleFile(cam.files && cam.files[0] ? cam.files[0] : null);

            });

        })();

        // Geolokasi
        const latitudeInput = document.getElementById('latitudeInput');
        const longitudeInput = document.getElementById('longitudeInput');
        const form = document.querySelector('form');

        function getLocation() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(showPosition, showError, {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                });
            } else {
                alert("Geolocation tidak didukung oleh browser ini.");
            }
        }

        function showPosition(position) {
            latitudeInput.value = position.coords.latitude;
            longitudeInput.value = position.coords.longitude;
            console.log("Lokasi berhasil didapatkan: " + position.coords.latitude + ", " + position.coords.longitude);
        }

        function showError(error) {
            switch(error.code) {
                case error.PERMISSION_DENIED:
                    alert("Pengguna menolak permintaan Geolocation. Laporan tidak dapat dikirim tanpa lokasi.");
                    break;
                case error.POSITION_UNAVAILABLE:
                    alert("Informasi lokasi tidak tersedia. Laporan tidak dapat dikirim tanpa lokasi.");
                    break;
                case error.TIMEOUT:
                    alert("Waktu permintaan untuk mendapatkan lokasi habis. Laporan tidak dapat dikirim tanpa lokasi.");
                    break;
                case error.UNKNOWN_ERROR:
                    alert("Terjadi kesalahan yang tidak diketahui. Laporan tidak dapat dikirim tanpa lokasi.");
                    break;
            }
            console.error("Error getting location:", error);
        }

        // Panggil getLocation saat halaman dimuat
        window.addEventListener('load', getLocation);

        // Validasi form saat submit
        form.addEventListener('submit', function(event) {
            if (!latitudeInput.value || !longitudeInput.value) {
                event.preventDefault(); // Mencegah form terkirim
                alert("Lokasi (lintang dan bujur) harus diambil sebelum mengirim laporan.");
                getLocation(); // Coba ambil lokasi lagi
            }
        });
    </script>



</body>

</html>