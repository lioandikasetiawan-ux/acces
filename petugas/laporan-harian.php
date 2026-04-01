<?php
require_once '../config/database.php';
require_once '../config/session.php';

// Cek login
$petugas_id = isset($_SESSION['petugas_id'])
    ? (int)$_SESSION['petugas_id']
    : (int)($_SESSION['user_id'] ?? 0);
if ($petugas_id <= 0) {
    header('Location: ../auth/login-v2.php');
    exit;
}

// Ambil kode_sync langsung dari DB
$kode_sync = 0;
$bagian_id_session = isset($_SESSION['bagian_id']) ? (int)$_SESSION['bagian_id'] : 0;
if ($bagian_id_session > 0) {
    $qSync = $conn->prepare("SELECT kode_sync FROM bagian WHERE id = ? LIMIT 1");
    if ($qSync) {
        $qSync->bind_param("i", $bagian_id_session);
        $qSync->execute();
        $qSync->bind_result($ks);
        if ($qSync->fetch()) {
            $kode_sync = (int)$ks;
        }
        $qSync->close();
    }
}

$tanggal = date('Y-m-d');
// $q = $conn->query("SELECT id, jam_masuk, jam_keluar FROM absensi WHERE petugas_id = '$petugas_id' AND tanggal = '$tanggal' AND jam_masuk IS NOT NULL AND jam_masuk <> '' AND (jam_keluar IS NULL OR jam_keluar = '') ORDER BY id DESC LIMIT 1");

// Gunakan IS NOT NULL untuk mengecek apakah jam sudah terisi
$q = $conn->query("SELECT id, jam_masuk, jam_keluar 
                   FROM absensi 
                   WHERE petugas_id = '$petugas_id' 
                   AND tanggal = '$tanggal' 
                   AND jam_masuk IS NOT NULL 
                   AND jam_keluar IS NULL 
                   ORDER BY id DESC LIMIT 1");

$absen = $q ? $q->fetch_assoc() : null;

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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-50 pb-20">

    <div class="bg-blue-600 p-4 text-white shadow-md mb-4">
        <h1 class="font-bold text-lg"><i class="fas fa-clipboard-list mr-2"></i>Laporan Kegiatan</h1>
        <p class="text-xs opacity-80"><?= date('d F Y'); ?></p>
    </div>

    <form action="../api/laporan-process.php" method="POST" enctype="multipart/form-data" class="px-4">
        <input type="hidden" name="absensi_id" value="<?= $absensi_id ?>">
        <input type="hidden" name="kode_sync" value="<?= $kode_sync ?>">
        <input type="hidden" name="latitude" id="latitudeInput">
        <input type="hidden" name="longitude" id="longitudeInput">
        <?php if (in_array($kode_sync, [1, 5, 6])): ?>
        <input type="hidden" name="kegiatan_harian_kategori" id="kegiatanKategoriInput" value="">
        <?php endif; ?>

        <?php if (in_array($kode_sync, [1, 5, 6])): ?>
        <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 mb-4">
            <label class="block text-gray-700 font-bold mb-2">Kegiatan Harian <span class="text-red-500">*</span></label>
            <div class="grid grid-cols-3 gap-2 mb-2" id="kegiatanBtnGroup">
                <button type="button" class="kegiatan-btn border-2 border-gray-300 rounded-lg py-2.5 px-2 text-sm font-semibold text-gray-700 bg-white" data-value="Pemantauan">Pemantauan</button>
                <button type="button" class="kegiatan-btn border-2 border-gray-300 rounded-lg py-2.5 px-2 text-sm font-semibold text-gray-700 bg-white" data-value="Pelaporan">Pelaporan</button>
                <button type="button" class="kegiatan-btn border-2 border-gray-300 rounded-lg py-2.5 px-2 text-sm font-semibold text-gray-700 bg-white" data-value="Koordinasi">Koordinasi</button>
            </div>
            <div class="mb-2">
                <button type="button" class="kegiatan-btn w-full border-2 border-gray-300 rounded-lg py-2.5 px-2 text-sm font-semibold text-gray-700 bg-white" data-value="Kegiatan Lainnya">Kegiatan Lainnya</button>
            </div>
            <div id="kegiatanLainnyaWrap" class="hidden mt-2">
                <label class="block text-sm text-gray-600 mb-1">Keterangan Kegiatan Lainnya <span class="text-red-500">*</span></label>
                <textarea name="kegiatan_harian_lainnya" id="kegiatanLainnyaText" rows="2" class="w-full border rounded-lg p-3 outline-none focus:ring-2 focus:ring-blue-500" placeholder="Jelaskan kegiatan lainnya..."></textarea>
            </div>
        </div>
        <?php endif; ?>

        <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 mb-4">
            <label class="block text-gray-700 font-bold mb-2">Uraian Pelaksanaan Tugas <span class="text-red-500">*</span></label>
            <textarea name="kegiatan" rows="4" required class="w-full border rounded-lg p-3 outline-none focus:ring-2 focus:ring-blue-500" placeholder="Contoh: Pembersihan sampah di pintu air..."></textarea>
        </div>

        <?php if ($kode_sync == 1): ?>
        <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 mb-4">
            <h3 class="font-bold text-gray-700 mb-3 border-b pb-2">Data Pemantauan</h3>
            <div class="mb-3">
                <label class="block text-sm text-gray-600 mb-2">Status Air <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-3 gap-2" id="statusAirBtnGroup">
                    <button type="button" class="status-air-btn border-2 border-gray-300 rounded-lg py-2.5 px-2 text-sm font-semibold text-gray-700 bg-white" data-value="kering">Kering</button>
                    <button type="button" class="status-air-btn border-2 border-gray-300 rounded-lg py-2.5 px-2 text-sm font-semibold text-gray-700 bg-white" data-value="menggenang">Menggenang</button>
                    <button type="button" class="status-air-btn border-2 border-gray-300 rounded-lg py-2.5 px-2 text-sm font-semibold text-gray-700 bg-white" data-value="mengalir">Mengalir</button>
                </div>
                <input type="hidden" name="ketersediaan_air" id="statusAirInput" value="">
            </div>
        </div>
        <?php endif; ?>

        <?php if ($kode_sync == 6): ?>
        <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 mb-4">
            <h3 class="font-bold text-gray-700 mb-3 border-b pb-2">Data Pemantauan</h3>
            <div class="mb-4">
                <label class="block text-sm text-gray-600 mb-2">Status Air <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-2 gap-2" id="statusAirBtnGroup6">
                    <button type="button" class="status-air6-btn border-2 border-gray-300 rounded-lg py-2.5 px-2 text-sm font-semibold text-gray-700 bg-white" data-value="kering">Kering</button>
                    <button type="button" class="status-air6-btn border-2 border-gray-300 rounded-lg py-2.5 px-2 text-sm font-semibold text-gray-700 bg-white" data-value="menggenang">Menggenang</button>
                </div>
                <input type="hidden" name="ketersediaan_air" id="statusAirInput6" value="">
            </div>
            <div class="mb-4">
                <label class="block text-sm text-gray-600 mb-1">Tinggi Muka Air / TMA (m)</label>
                <input type="number" step="0.01" name="tma" class="w-full border rounded-lg p-2 focus:ring-2 focus:ring-blue-500 outline-none" placeholder="0.00">
            </div>
            <div class="mb-3">
                <label class="block text-sm text-gray-600 mb-2">Status Gulma <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-2 gap-2" id="statusGulmaBtnGroup">
                    <button type="button" class="status-gulma-btn border-2 border-gray-300 rounded-lg py-2.5 px-2 text-sm font-semibold text-gray-700 bg-white" data-value="Ada">Ada</button>
                    <button type="button" class="status-gulma-btn border-2 border-gray-300 rounded-lg py-2.5 px-2 text-sm font-semibold text-gray-700 bg-white" data-value="Tidak Ada">Tidak Ada</button>
                </div>
                <input type="hidden" name="status_gulma" id="statusGulmaInput" value="">
            </div>
        </div>
        <?php endif; ?>

        <?php if ($kode_sync == 2): ?>
        <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 mb-4">
            <h3 class="font-bold text-gray-700 mb-3 border-b pb-2">Data Pemantauan</h3>
            <div class="mb-4">
                <label class="block text-sm text-gray-600 mb-1">Tinggi Muka Air / TMA (m)</label>
                <input type="number" step="0.01" name="tma" class="w-full border rounded-lg p-2 focus:ring-2 focus:ring-blue-500 outline-none" placeholder="0.00">
            </div>
            <div class="mb-4">
                <label class="block text-sm text-gray-600 mb-1">Kondisi Saluran</label>
                <textarea name="kondisi_saluran" rows="2" class="w-full border rounded-lg p-3 focus:ring-2 focus:ring-blue-500 outline-none" placeholder="Jelaskan kondisi saluran..."></textarea>
            </div>
            <div class="mb-3">
                <label class="block text-sm text-gray-600 mb-1">Bangunan Air</label>
                <textarea name="bangunan_air" rows="2" class="w-full border rounded-lg p-3 focus:ring-2 focus:ring-blue-500 outline-none" placeholder="Jelaskan kondisi bangunan air..."></textarea>
            </div>
        </div>
        <?php endif; ?>
        

        <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 mb-6">
            <label class="block text-gray-700 font-bold mb-2">Foto Dokumentasi</label>
            <div class="flex gap-2 mb-3">
                <button type="button" onclick="openCamera()" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 rounded-lg text-sm">Buka Kamera</button>
            </div>
            <div id="fotoPreviewWrap" class="hidden mb-3">
                <img id="fotoPreview" src="" class="w-full rounded-lg border object-cover" />
                <p id="sizeInfo" class="text-[10px] text-gray-500 mt-1 text-right"></p>
            </div>
            <input type="file" name="foto_laporan" id="fotoLaporanInput" accept="image/*" required class="hidden" />
            <input type="file" id="fotoLaporanCameraInput" accept="image/*" capture="environment" class="hidden" />
            <p class="text-xs text-gray-400 mt-1">*Kamera diwajibkan untuk dokumentasi kegiatan</p>
        </div>

        <button type="submit" class="w-full bg-blue-600 text-white font-bold py-3 rounded-xl shadow-lg hover:bg-blue-700 transition">
            KIRIM LAPORAN
        </button>
        <a href="dashboard-v2.php" class="block text-center mt-4 text-gray-500 text-sm">Batal</a>
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
            </div>
        </div>
    </div>

    <script>
        // Logika Button Groups
        function setupBtnGroup(btnClass, hiddenInputId, onSelect) {
            var btns = document.querySelectorAll('.' + btnClass);
            btns.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    btns.forEach(b => b.classList.replace('bg-blue-600', 'bg-white'), b => b.classList.replace('text-white', 'text-gray-700'));
                    btns.forEach(b => b.classList.remove('border-blue-600'));
                    
                    btn.classList.replace('bg-white', 'bg-blue-600');
                    btn.classList.replace('text-gray-700', 'text-white');
                    btn.classList.add('border-blue-600');

                    var input = document.getElementById(hiddenInputId);
                    if (input) input.value = btn.getAttribute('data-value');
                    if (onSelect) onSelect(btn.getAttribute('data-value'));
                });
            });
        }

        <?php if (in_array($kode_sync, [1, 5, 6])): ?>
        setupBtnGroup('kegiatan-btn', 'kegiatanKategoriInput', function(val) {
            var wrap = document.getElementById('kegiatanLainnyaWrap');
            var txt = document.getElementById('kegiatanLainnyaText');
            if (val === 'Kegiatan Lainnya') {
                wrap.classList.remove('hidden');
                txt.required = true;
            } else {
                wrap.classList.add('hidden');
                txt.required = false; txt.value = '';
            }
        });
        <?php endif; ?>

        setupBtnGroup('status-air-btn', 'statusAirInput');
        setupBtnGroup('status-air6-btn', 'statusAirInput6');
        setupBtnGroup('status-gulma-btn', 'statusGulmaInput');

        // Logika Kamera & Kompresi
        var cameraStream = null;

        function openCamera() {
            if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false })
                    .then(function(stream) {
                        cameraStream = stream;
                        var modal = document.getElementById('cameraModal');
                        var video = document.getElementById('cameraVideo');
                        video.srcObject = stream;
                        modal.classList.replace('hidden', 'flex');
                    })
                    .catch(() => document.getElementById('fotoLaporanCameraInput').click());
            } else {
                document.getElementById('fotoLaporanCameraInput').click();
            }
        }

        function closeCameraModal() {
            if (cameraStream) cameraStream.getTracks().forEach(t => t.stop());
            document.getElementById('cameraModal').classList.replace('flex', 'hidden');
        }

        function takeSnapshot() {
            var video = document.getElementById('cameraVideo');
            var canvas = document.getElementById('cameraCanvas');
            
            // OPTIMASI: Batasi resolusi maksimal 1024px agar file tidak bengkak
            var maxWidth = 1024;
            var scale = maxWidth / video.videoWidth;
            canvas.width = maxWidth;
            canvas.height = video.videoHeight * scale;

            var ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

            // OPTIMASI: Gunakan kualitas 0.6 (60%) - Sangat hemat size
            canvas.toBlob(function(blob) {
                processFile(blob);
                closeCameraModal();
            }, 'image/jpeg', 0.6);
        }

        function processFile(blob) {
            if (!blob) return;
            var file = new File([blob], "laporan_" + Date.now() + ".jpg", { type: "image/jpeg" });
            
            // Masukkan ke input file utama
            var dt = new DataTransfer();
            dt.items.add(file);
            document.getElementById('fotoLaporanInput').files = dt.files;

            // Update Preview
            var img = document.getElementById('fotoPreview');
            img.src = URL.createObjectURL(file);
            document.getElementById('fotoPreviewWrap').classList.remove('hidden');
            document.getElementById('sizeInfo').innerText = "Ukuran terkompresi: " + (blob.size / 1024).toFixed(1) + " KB";
        }

        // Handle input file cadangan
        document.getElementById('fotoLaporanCameraInput').addEventListener('change', function(e) {
            if (this.files && this.files[0]) processFile(this.files[0]);
        });

        // Geolokasi
        function getLocation() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    pos => {
                        document.getElementById('latitudeInput').value = pos.coords.latitude;
                        document.getElementById('longitudeInput').value = pos.coords.longitude;
                    },
                    err => console.error(err),
                    { enableHighAccuracy: true }
                );
            }
        }
        window.addEventListener('load', getLocation);

        // Validasi Akhir
        document.querySelector('form').addEventListener('submit', function(e) {
            if (!document.getElementById('latitudeInput').value) {
                e.preventDefault();
                alert("Tunggu hingga lokasi didapatkan atau aktifkan GPS Anda!");
                getLocation();
            }
        });
    </script>
</body>
</html>