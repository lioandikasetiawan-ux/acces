<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

// Cek apakah user sudah login & role petugas (bisa di-include di header nanti)

if (!isset($_SESSION['petugas_id']) || !isset($_SESSION['role']) || ($_SESSION['role'] !== 'petugas' && !($_SESSION['role'] === 'admin' && isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] !== NULL))) {
    header("Location: ../auth/login-v2.php");
    exit;
}

date_default_timezone_set('Asia/Jakarta');
$jam_sekarang = date('H:i:s');
$jam_keluar_start = $_SESSION['jam_keluar_start'] ?? '00:00:00';
$jam_keluar_end = $_SESSION['jam_keluar_end'] ?? '23:59:59';

$petugas_id = $_SESSION['petugas_id'];
$cek = $conn->prepare("SELECT id, tanggal, jam_masuk, jam_keluar FROM absensi WHERE petugas_id = ? AND jam_masuk IS NOT NULL AND jam_masuk <> '' AND (jam_keluar IS NULL OR jam_keluar = '') ORDER BY id DESC LIMIT 1");
$cek->bind_param("i", $petugas_id);
$cek->execute();

$data_absen = stmtFetchAssoc($cek);
$cek->close();

if (!$data_absen) {
    echo "<script>alert('Tidak ada absen aktif. Silakan Absen Masuk dulu!'); window.location.href='dashboard-v2.php';</script>";
    exit;
}

$tanggal_absen = $data_absen['tanggal'];
$deadline_tanggal = $tanggal_absen;
if ($jam_keluar_end < $jam_keluar_start) {
    $deadline_tanggal = date('Y-m-d', strtotime($tanggal_absen . ' +1 day'));
}
$deadline = strtotime($deadline_tanggal . ' ' . $jam_keluar_end);
if ($deadline !== false && time() > $deadline) {
    $deadline_db = date('Y-m-d H:i:s', $deadline);
    $upd = $conn->prepare("UPDATE absensi SET jam_keluar = ?, status = 'lupa absen' WHERE id = ? AND (jam_keluar IS NULL OR jam_keluar = '')");
    $upd->bind_param('si', $deadline_db, $data_absen['id']);
    $upd->execute();

    echo "<script>alert('Sudah lewat batas waktu pulang. Absensi otomatis direset.'); window.location.href='dashboard-v2.php';</script>";
    exit;
}

if ($jam_keluar_end >= $jam_keluar_start) {
    $bisa_absen_keluar = ($jam_sekarang >= $jam_keluar_start && $jam_sekarang <= $jam_keluar_end);
} else {
    $bisa_absen_keluar = ($jam_sekarang >= $jam_keluar_start || $jam_sekarang <= $jam_keluar_end);
}

if (!$bisa_absen_keluar) {
    echo "<script>alert('Belum waktunya Absen Keluar!'); window.location.href='dashboard-v2.php';</script>";
    exit;
}

$absensi_id = (int)$data_absen['id'];
$cekLaporan = $conn->prepare("SELECT id FROM laporan_harian WHERE absensi_id = ? LIMIT 1");
$cekLaporan->bind_param("i", $absensi_id);
$cekLaporan->execute();

$laporan = stmtFetchAssoc($cekLaporan);
$cekLaporan->close();
if (!$laporan) {
    echo "<script>alert('Anda wajib mengisi Laporan Kegiatan sebelum Absen Keluar!'); window.location.href='laporan-harian.php';</script>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Absen Keluar</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/webcamjs/1.0.26/webcam.min.js"></script>
</head>
<body class="bg-gray-100 h-screen flex flex-col">

    <div class="bg-red-600 p-4 shadow-sm flex items-center gap-4 text-white">
        <a href="dashboard-v2.php" class="text-white"><i class="fas fa-arrow-left"></i> Kembali</a>
        <h1 class="font-bold">Form Absen Keluar</h1>
    </div>

    <div class="p-4 flex-1 overflow-y-auto">
        <div class="bg-white rounded-xl shadow p-4 mb-4">
            <p class="text-sm font-bold text-gray-700 mb-2">1. Foto Selfie Pulang</p>
            <div id="my_camera" class="w-full h-64 bg-gray-200 rounded-lg overflow-hidden mx-auto"></div>
            <div id="results" class="hidden mt-2"></div>
            <button type="button" onClick="take_snapshot()" class="mt-3 w-full bg-red-600 text-white py-2 rounded-lg font-bold text-sm">
                Jepret Foto
            </button>
        </div>

        <div class="bg-white rounded-xl shadow p-4 mb-4">
            <p class="text-sm font-bold text-gray-700 mb-2">2. Validasi Lokasi</p>
            <div id="location-status" class="text-xs text-gray-500">Mencari koordinat...</div>
        </div>

        <form action="../api/absen-keluar-process.php" method="POST">
            <input type="hidden" name="latitude" id="latitude">
            <input type="hidden" name="longitude" id="longitude">
            <input type="hidden" name="foto" id="foto">
            
            <button type="submit" id="btn-submit" disabled 
                class="w-full bg-gray-400 text-white py-4 rounded-xl font-bold text-lg shadow-lg transition cursor-not-allowed">
                SELESAI KERJA (PULANG)
            </button>
        </form>
    </div>

    <script>
        Webcam.set({ width: 320, height: 240, image_format: 'jpeg', jpeg_quality: 70, facingMode: "user" });
        Webcam.attach('#my_camera');

        function take_snapshot() {
            Webcam.snap(function(data_uri) {
                document.getElementById('results').innerHTML = '<img src="'+data_uri+'" class="w-full rounded-lg"/>';
                document.getElementById('my_camera').style.display = 'none';
                document.getElementById('results').classList.remove('hidden');
                document.getElementById('foto').value = data_uri;
                checkReady();
            });
        }

        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(position) {
                document.getElementById('latitude').value = position.coords.latitude;
                document.getElementById('longitude').value = position.coords.longitude;
                document.getElementById("location-status").innerHTML = "Lokasi terkunci.";
                checkReady();
            });
        }

        function checkReady() {
            if(document.getElementById('foto').value != '' && document.getElementById('latitude').value != '') {
                var btn = document.getElementById('btn-submit');
                btn.disabled = false;
                btn.classList.replace('bg-gray-400', 'bg-red-600');
                btn.classList.remove('cursor-not-allowed');
            }
        }
    </script>
</body>
</html>