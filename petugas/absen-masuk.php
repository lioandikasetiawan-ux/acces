<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['petugas_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'petugas') {
    header('Location: ../auth/login-v2.php');
    exit;
}

date_default_timezone_set('Asia/Jakarta');
$jam_sekarang = date('H:i:s');
$jam_masuk_start = $_SESSION['jam_masuk_start'] ?? '00:00:00';
$jam_masuk_end = $_SESSION['jam_masuk_end'] ?? '23:59:59';
$jam_keluar_start = $_SESSION['jam_keluar_start'] ?? '00:00:00';
$jam_keluar_end = $_SESSION['jam_keluar_end'] ?? '23:59:59';

if ($jam_masuk_end >= $jam_masuk_start) {
    $bisa_absen_masuk = ($jam_sekarang >= $jam_masuk_start && $jam_sekarang <= $jam_masuk_end);
} else {
    $bisa_absen_masuk = ($jam_sekarang >= $jam_masuk_start || $jam_sekarang <= $jam_masuk_end);
}
if (!$bisa_absen_masuk) {
    echo "<script>alert('Belum waktunya Absen Masuk!'); window.location.href='dashboard-v2.php';</script>";
    exit;
}

$petugas_id = $_SESSION['petugas_id'];

$stmtAktif = $conn->prepare("SELECT id, tanggal FROM absensi WHERE petugas_id = ? AND jam_masuk IS NOT NULL AND jam_masuk <> '' AND (jam_keluar IS NULL OR jam_keluar = '') ORDER BY id DESC LIMIT 1");
$stmtAktif->bind_param('i', $petugas_id);
$stmtAktif->execute();
$absenAktif = stmtFetchAssoc($stmtAktif);
$stmtAktif->close();

if (!empty($absenAktif)) {
    $tanggal_absen = $absenAktif['tanggal'];
    $deadline_tanggal = $tanggal_absen;
    if ($jam_keluar_end < $jam_keluar_start) {
        $deadline_tanggal = date('Y-m-d', strtotime($tanggal_absen . ' +1 day'));
    }
    $deadline = strtotime($deadline_tanggal . ' ' . $jam_keluar_end);
    if ($deadline !== false && time() > $deadline) {
        $deadline_db = date('Y-m-d H:i:s', $deadline);
        $upd = $conn->prepare("UPDATE absensi SET jam_keluar = ?, status = 'lupa absen' WHERE id = ? AND (jam_keluar IS NULL OR jam_keluar = '')");
        $upd->bind_param('si', $deadline_db, $absenAktif['id']);
        $upd->execute();

        $absenAktif = null;
    }
}

if (!empty($absenAktif)) {
    echo "<script>alert('Anda masih dalam status bekerja. Silakan Absen Keluar dulu!'); window.location.href='dashboard-v2.php';</script>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Absen Masuk</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/webcamjs/1.0.26/webcam.min.js"></script>
</head>
<body class="bg-gray-100 h-screen flex flex-col">

    <div class="bg-white p-4 shadow-sm flex items-center gap-4">
        <a href="dashboard-v2.php" class="text-gray-600"><i class="fas fa-arrow-left"></i> Kembali</a>
        <h1 class="font-bold text-gray-800">Form Absen Masuk</h1>
    </div>

    <div class="p-4 flex-1 overflow-y-auto">

        <div class="bg-white rounded-xl shadow p-4 mb-4">
            <p class="text-sm font-bold text-gray-700 mb-2">1. Ambil Foto Selfie</p>
            <div id="my_camera" class="w-full h-64 bg-gray-200 rounded-lg overflow-hidden mx-auto"></div>
            <div id="results" class="hidden mt-2"></div>
            <button type="button" onClick="take_snapshot()" class="mt-3 w-full bg-blue-600 text-white py-2 rounded-lg font-bold text-sm">
                <i class="fas fa-camera mr-1"></i> Ambil Foto
            </button>
        </div>

        <div class="bg-white rounded-xl shadow p-4 mb-4">
            <p class="text-sm font-bold text-gray-700 mb-2">2. Deteksi Lokasi</p>
            <div id="location-status" class="text-xs text-gray-500 mb-2">Sedang mencari koordinat...</div>
            <div class="text-xs bg-yellow-50 text-yellow-700 p-2 rounded border border-yellow-200">
                Jarak maksimal diizinkan: <b>1 KM (1000 Meter)</b> dari titik lokasi kerja.
            </div>
        </div>

        <form action="../api/absen-masuk-process.php" method="POST" id="form-absen">
            <input type="hidden" name="latitude" id="latitude">
            <input type="hidden" name="longitude" id="longitude">
            <input type="hidden" name="foto" id="foto">

            <button type="submit" id="btn-submit" disabled
                class="w-full bg-gray-400 text-white py-4 rounded-xl font-bold text-lg shadow-lg transition cursor-not-allowed">
                KIRIM ABSENSI
            </button>
        </form>

    </div>

    <script>
        Webcam.set({
            width: 320,
            height: 240,
            image_format: 'jpeg',
            jpeg_quality: 70,
            facingMode: "user"
        });
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
            navigator.geolocation.getCurrentPosition(showPosition, showError, {enableHighAccuracy: true});
        } else {
            document.getElementById("location-status").innerHTML = "Geolocation tidak didukung browser ini.";
        }

        function showPosition(position) {
            var lat = position.coords.latitude;
            var long = position.coords.longitude;

            document.getElementById('latitude').value = lat;
            document.getElementById('longitude').value = long;

            document.getElementById("location-status").innerHTML = "Lokasi ditemukan: " + lat + ", " + long;
            document.getElementById("location-status").classList.add('text-green-600');
            checkReady();
        }

        function showError(error) {
            alert("Wajib izinkan lokasi untuk absen!");
        }

        function checkReady() {
            var foto = document.getElementById('foto').value;
            var lat = document.getElementById('latitude').value;

            if (foto != '' && lat != '') {
                var btn = document.getElementById('btn-submit');
                btn.disabled = false;
                btn.classList.remove('bg-gray-400', 'cursor-not-allowed');
                btn.classList.add('bg-green-600', 'hover:bg-green-700');
            }
        }
    </script>
</body>
</html>
