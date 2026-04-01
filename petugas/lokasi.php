<?php

/**

 * Halaman Peta Lokasi Titik Absensi

 * ==================================

 */



require_once '../config/database.php';

require_once '../config/session.php';

require_once '../includes/functions.php';



if (!isset($_SESSION['petugas_id'])) {

    header('Location: ../auth/login-v2.php');

    exit;

}



$petugasId = $_SESSION['petugas_id'];
$petugas = getPetugasById($conn, $petugasId);
$bagianId = isset($petugas['bagian_id']) ? (int)$petugas['bagian_id'] : null;

// Ambil lokasi dari jadwal_petugas hari ini (bukan semua lokasi di bagian)
$today = date('Y-m-d');
$allKoordinat = [];

$stmtJadwal = $conn->prepare("SELECT jp.id FROM jadwal_petugas jp WHERE jp.petugas_id = ? AND jp.tanggal = ? LIMIT 1");
$stmtJadwal->bind_param("is", $petugasId, $today);
$stmtJadwal->execute();
$jadwalData = stmtFetchAssoc($stmtJadwal);
$stmtJadwal->close();

if ($jadwalData) {
    $jadwalId = (int)$jadwalData['id'];
    // Ambil semua lokasi yang di-assign ke jadwal ini
    $stmtLokasi = $conn->prepare("
        SELECT bk.id, bk.nama_titik, bk.latitude, bk.longitude, bk.radius_meter
        FROM jadwal_lokasi jl
        JOIN bagian_koordinat bk ON jl.bagian_koordinat_id = bk.id
        WHERE jl.jadwal_id = ?
        ORDER BY jl.urutan ASC
    ");
    $stmtLokasi->bind_param("i", $jadwalId);
    $stmtLokasi->execute();
    $allKoordinat = stmtFetchAllAssoc($stmtLokasi);
    $stmtLokasi->close();
}

?>

<!DOCTYPE html>

<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Lokasi Titik Absensi</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <style>

        #map { height: calc(100vh - 200px); min-height: 400px; }

    </style>

</head>

<body class="bg-gray-100">

    <!-- Header -->

    <header class="bg-white shadow-md">

        <div class="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">

            <div class="flex items-center gap-4">

                <a href="dashboard-v2.php" class="text-blue-600 hover:text-blue-800">

                    <i class="fas fa-arrow-left text-xl"></i>

                </a>

                <div>

                    <h1 class="text-xl font-bold text-gray-800">Lokasi Titik Absensi</h1>

                    <p class="text-sm text-gray-500">Peta semua titik yang valid untuk absensi</p>

                </div>

            </div>

            <button onclick="getMyLocation()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">

                <i class="fas fa-crosshairs mr-2"></i>Lokasi Saya

            </button>

        </div>

    </header>



    <main class="max-w-7xl mx-auto px-4 py-4">

        <!-- Filter -->

     


        <!-- Map -->

        <div class="bg-white rounded-xl shadow overflow-hidden">

            <div id="map"></div>

        </div>



        <!-- Legend -->

        <div class="bg-white rounded-xl shadow p-4 mt-4">

            <h3 class="font-semibold mb-2">Keterangan:</h3>

            <div class="flex flex-wrap gap-4 text-sm">

                <div class="flex items-center gap-2">

                    <div class="w-4 h-4 rounded-full bg-blue-500"></div>

                    <span>Titik Absensi</span>

                </div>

                <div class="flex items-center gap-2">

                    <div class="w-4 h-4 rounded-full bg-blue-300 opacity-50"></div>

                    <span>Radius Valid (lingkaran)</span>

                </div>

                <div class="flex items-center gap-2">

                    <div class="w-4 h-4 rounded-full bg-green-500"></div>

                    <span>Lokasi Anda</span>

                </div>

            </div>

        </div>



        <!-- List Titik -->

        <div class="bg-white rounded-xl shadow mt-4 overflow-hidden">

            <div class="px-4 py-3 border-b border-gray-200">

                <h3 class="font-semibold">Daftar Titik Absensi</h3>

            </div>

            <div class="divide-y divide-gray-200 max-h-64 overflow-y-auto">

                <?php foreach ($allKoordinat as $k): ?>

                <div class="px-4 py-3 hover:bg-gray-50 cursor-pointer titik-item" 
                     onclick="focusMarker(<?= $k['latitude'] ?>, <?= $k['longitude'] ?>)">

                    <div class="flex justify-between items-start">

                        <div>

                            <span class="font-medium"><?= htmlspecialchars($k['nama_titik']) ?></span>

                            <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded ml-2">

                                <?= htmlspecialchars($k['bagian_kode']) ?>

                            </span>

                            <div class="text-sm text-gray-500">

                                <?= htmlspecialchars($k['bagian_nama']) ?>

                            </div>

                        </div>

                        <div class="text-right text-sm text-gray-500">

                            <div>Radius: <?= number_format($k['radius_meter']) ?>m</div>

                        </div>

                    </div>

                </div>

                <?php endforeach; ?>

            </div>

        </div>

    </main>



 <script>
/* ===============================
   DATA DARI PHP
================================ */
const koordinatList = <?= json_encode($allKoordinat) ?>;

/* ===============================
   INIT VARIABLE
================================ */
let map;
let markers = [];
let circles = [];
let myLocationMarker = null;

/* ===============================
   TENTUKAN CENTER
================================ */
let center = [-6.2088, 106.8456]; // default Jakarta
if (koordinatList.length > 0) {
    center = [
        parseFloat(koordinatList[0].latitude),
        parseFloat(koordinatList[0].longitude)
    ];
}

/* ===============================
   INIT MAP
================================ */
map = L.map('map').setView(center, 12);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '� OpenStreetMap'
}).addTo(map);

/* ===============================
   TAMBAH MARKER & RADIUS
================================ */
koordinatList.forEach(k => {
    const lat = parseFloat(k.latitude);
    const lng = parseFloat(k.longitude);
    const radius = parseInt(k.radius_meter);

    // marker
    const marker = L.marker([lat, lng]).addTo(map)
        .bindPopup(`
            <strong>${k.nama_titik}</strong><br>
            ${k.bagian_nama}<br>
            Radius: ${radius} m
        `);

    markers.push(marker);

    // circle
    const circle = L.circle([lat, lng], {
        radius: radius,
        color: '#3b82f6',
        fillColor: '#3b82f6',
        fillOpacity: 0.15
    }).addTo(map);

    circles.push(circle);
});

/* ===============================
   AUTO ZOOM KE SEMUA TITIK
================================ */
if (markers.length > 0) {
    const group = L.featureGroup(markers);
    map.fitBounds(group.getBounds().pad(0.2));
}

/* ===============================
   LOKASI SAYA
================================ */
function getMyLocation() {
    if (!navigator.geolocation) {
        alert('Geolocation tidak didukung browser ini');
        return;
    }

    navigator.geolocation.getCurrentPosition(
        pos => {
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;

            if (myLocationMarker) {
                map.removeLayer(myLocationMarker);
            }

            const myIcon = L.divIcon({
                html: '<i class="fas fa-circle text-green-500 text-2xl"></i>',
                className: '',
                iconSize: [24, 24],
                iconAnchor: [12, 12]
            });

            myLocationMarker = L.marker([lat, lng], { icon: myIcon })
                .addTo(map)
                .bindPopup('Lokasi Anda')
                .openPopup();

            map.setView([lat, lng], 16);
        },
        err => alert('Gagal ambil lokasi: ' + err.message),
        { enableHighAccuracy: true }
    );
}

/* ===============================
   FOCUS MARKER DARI LIST
================================ */
function focusMarker(lat, lng) {
    map.setView([lat, lng], 16);
    markers.forEach(m => {
        const p = m.getLatLng();
        if (p.lat == lat && p.lng == lng) {
            m.openPopup();
        }
    });
}
</script>
</body>

</html>

