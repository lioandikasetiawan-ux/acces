<?php

require_once '../config/database.php';

require_once '../config/session.php';

require_once '../includes/functions.php';



// Memastikan hanya petugas atau admin dengan bagian_id yang bisa mengakses
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'petugas' && !($_SESSION['role'] === 'admin' && isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] !== NULL))) {
    header("Location: ../auth/login-v2.php");
    exit;
}



// Ambil Koordinat Target (Lokasi Kerja) dari Database

$id = $_SESSION['petugas_id'];
$bagianId = null;

// Source of truth: ambil bagian_id dari tabel petugas
$stmtPetugas = $conn->prepare("SELECT bagian_id FROM petugas WHERE id = ?");
$stmtPetugas->bind_param("i", $id);
$stmtPetugas->execute();
$petugasData = stmtFetchAssoc($stmtPetugas);
$stmtPetugas->close();

if ($petugasData) {
    $bagianId = isset($petugasData['bagian_id']) ? (int)$petugasData['bagian_id'] : null;
}

// Ambil lokasi dari jadwal_petugas hari ini (bukan dari titik_lokasi_id di tabel petugas)
$today = date('Y-m-d');
$jadwalLokasiIds = [];
$stmtJadwal = $conn->prepare("SELECT jp.id FROM jadwal_petugas jp WHERE jp.petugas_id = ? AND jp.tanggal = ? LIMIT 1");
$stmtJadwal->bind_param("is", $id, $today);
$stmtJadwal->execute();
$jadwalData = stmtFetchAssoc($stmtJadwal);
$stmtJadwal->close();

if ($jadwalData) {
    $jadwalId = (int)$jadwalData['id'];
    // Ambil semua lokasi yang di-assign ke jadwal ini
    $stmtLokasi = $conn->prepare("SELECT bagian_koordinat_id FROM jadwal_lokasi WHERE jadwal_id = ?");
    $stmtLokasi->bind_param("i", $jadwalId);
    $stmtLokasi->execute();
    $lokasiList = stmtFetchAllAssoc($stmtLokasi);
    $stmtLokasi->close();
    
    foreach ($lokasiList as $lok) {
        $jadwalLokasiIds[] = (int)$lok['bagian_koordinat_id'];
    }
}



$bagianTableExists = false;

$bagianIdColumnExists = false;

$petugasBagianColumnExists = false;



$checkBagianTable = $conn->query("SHOW TABLES LIKE 'bagian'");

if ($checkBagianTable && $checkBagianTable->num_rows > 0) {

    $bagianTableExists = true;

}



$checkBagianIdColumn = $conn->query("SHOW COLUMNS FROM petugas LIKE 'bagian_id'");

if ($checkBagianIdColumn && $checkBagianIdColumn->num_rows > 0) {

    $bagianIdColumnExists = true;

}



$checkPetugasBagianColumn = $conn->query("SHOW COLUMNS FROM petugas LIKE 'bagian'");

if ($checkPetugasBagianColumn && $checkPetugasBagianColumn->num_rows > 0) {

    $petugasBagianColumnExists = true;

}



$bagianJoin = '';

$bagianSelect = "'' AS bagian_display";

if ($bagianTableExists && $bagianIdColumnExists) {

    $bagianJoin = " LEFT JOIN bagian b ON p.bagian_id = b.id ";

    if ($petugasBagianColumnExists) {

        $bagianSelect = "COALESCE(b.nama_bagian, p.bagian) AS bagian_display";

    } else {

        $bagianSelect = "b.nama_bagian AS bagian_display";

    }

} else if ($petugasBagianColumnExists) {

    $bagianSelect = "p.bagian AS bagian_display";

}



$stmt = $conn->prepare("SELECT p.nama, $bagianSelect, p.alamat, p.latitude, p.longitude FROM petugas p $bagianJoin WHERE p.id = ?");

$stmt->bind_param("i", $id);

$stmt->execute();

$d = stmtFetchAssoc($stmt);

$stmt->close();

// Ambil lokasi berdasarkan jadwal hari ini
$titikLokasi = [];
if (!empty($jadwalLokasiIds)) {
    // Tampilkan hanya lokasi yang ada di jadwal hari ini
    $placeholders = implode(',', array_fill(0, count($jadwalLokasiIds), '?'));
    $sql = "SELECT id, nama_titik as nama_lokasi, latitude, longitude, radius_meter
            FROM bagian_koordinat
            WHERE id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    $types = str_repeat('i', count($jadwalLokasiIds));
    $stmt->bind_param($types, ...$jadwalLokasiIds);
    $stmt->execute();
    $titikLokasi = stmtFetchAllAssoc($stmt);
    $stmt->close();
}

if (!$d) {
    die('Data petugas tidak ditemukan');
}

// Debug and validation for location data
if (empty($titikLokasi)) {
    $debugMsg = "Tidak ada data lokasi untuk hari ini. ";
    if (empty($jadwalLokasiIds)) {
        $debugMsg .= "Anda belum memiliki jadwal atau jadwal Anda belum memiliki lokasi yang ditentukan untuk hari ini. Silakan hubungi admin.";
    } else {
        $debugMsg .= "Lokasi di jadwal tidak ditemukan di database. Silakan hubungi admin.";
    }
    
    // Show error page instead of continuing
    echo "<!DOCTYPE html><html><head><title>Error - Peta Lokasi</title>";
    echo "<script src='https://cdn.tailwindcss.com'></script></head>";
    echo "<body class='bg-gray-100 flex items-center justify-center min-h-screen'>";
    echo "<div class='bg-white p-8 rounded-xl shadow-lg max-w-md'>";
    echo "<div class='text-red-600 text-6xl mb-4 text-center'>⚠️</div>";
    echo "<h1 class='text-2xl font-bold text-gray-800 mb-4 text-center'>Lokasi Tidak Tersedia</h1>";
    echo "<p class='text-gray-600 mb-6 text-center'>$debugMsg</p>";
    echo "<a href='dashboard-v2.php' class='block w-full text-center bg-blue-600 text-white py-3 rounded-lg font-semibold hover:bg-blue-700'>Kembali ke Dashboard</a>";
    echo "</div></body></html>";
    exit;
}


?>



<!DOCTYPE html>

<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Peta Lokasi Kerja</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    

    <style>

        #map { height: 100vh; width: 100%; z-index: 1; }

        .info-box { z-index: 1000; position: fixed; bottom: 20px; left: 20px; right: 20px; }

    </style>

</head>

<body class="bg-gray-100 overflow-hidden">



    <a href="dashboard-v2.php" class="fixed top-4 left-4 z-[1001] bg-white text-gray-800 p-3 rounded-full shadow-lg">

        <i class="fas fa-arrow-left"></i>

    </a>



    <div id="map"></div>



    <div class="info-box bg-white p-4 rounded-2xl shadow-2xl border border-gray-200">

        <div class="flex items-center justify-between mb-2">

            <div>

                <p class="text-xs text-gray-500 uppercase font-bold">Target Lokasi</p>

                <h3 class="font-bold text-gray-800"><?= $d['bagian_display'] ?></h3>

                <p class="text-xs text-gray-500 mt-0.5"><?= $d['alamat'] ?></p>

            </div>

            <div class="text-right">

                <p class="text-xs text-gray-500 uppercase font-bold">Jarak Saat Ini</p>

                <h2 id="distance-val" class="text-xl font-bold text-blue-600">... Meter</h2>

            </div>

        </div>

        

        <div id="status-msg" class="text-xs bg-gray-100 p-2 rounded text-center text-gray-500">

            Sedang mencari sinyal GPS...

        </div>

    </div>

<script>
const titikLokasi = <?= json_encode($titikLokasi) ?>;
</script>

  

         <script>
/* ===============================
   1. VALIDASI DATA DARI PHP
================================ */
if (!titikLokasi || titikLokasi.length === 0) {
    alert('Lokasi kerja untuk bagian ini belum diatur');
    document.getElementById('status-msg').innerHTML = 
        '<span class="text-red-600 font-bold">⚠️ Lokasi kerja belum diatur oleh admin</span>';
    throw new Error('No location data');
}

/* ===============================
   2. INIT MAP (PAKAI TITIK PERTAMA)
================================ */
const firstPoint = titikLokasi[0];

const map = L.map('map').setView(
    [firstPoint.latitude, firstPoint.longitude],
    15
);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap'
}).addTo(map);

/* ===============================
   3. ICON
================================ */
const redIcon = new L.Icon({
    iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/markers/marker-icon-2x-red.png',
    shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png',
    iconSize: [25, 41],
    iconAnchor: [12, 41],
    popupAnchor: [1, -34],
    shadowSize: [41, 41]
});

/* ===============================
   4. MARKER LOKASI KERJA (SEMUA TITIK BAGIAN)
================================ */
const targetMarkers = [];

titikLokasi.forEach(t => {
    const marker = L.marker([t.latitude, t.longitude], { icon: redIcon })
        .addTo(map)
        .bindPopup(
            `<b>${t.nama_lokasi}</b><br>
             ${t.alamat}<br>
             Radius: ${t.radius_meter} m`
        );

    targetMarkers.push(marker);
});

/* ===============================
   5. MARKER USER
================================ */
let userMarker = null;

/* ===============================
   6. HITUNG JARAK (HAVERSINE)
================================ */
function getDistance(lat1, lon1, lat2, lon2) {
    const R = 6371000;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;

    const a =
        Math.sin(dLat / 2) * Math.sin(dLat / 2) +
        Math.cos(lat1 * Math.PI / 180) *
        Math.cos(lat2 * Math.PI / 180) *
        Math.sin(dLon / 2) * Math.sin(dLon / 2);

    return Math.round(R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)));
}

/* ===============================
   7. GPS TRACKING
================================ */
if (navigator.geolocation) {
    navigator.geolocation.watchPosition(
        position => {
            const userLat = position.coords.latitude;
            const userLng = position.coords.longitude;

            // Update marker user
            if (!userMarker) {
                userMarker = L.marker([userLat, userLng])
                    .addTo(map)
                    .bindPopup("Posisi Anda");
            } else {
                userMarker.setLatLng([userLat, userLng]);
            }

            /* ===============================
               8. CEK KE SEMUA TITIK BAGIAN
            ================================ */
            let dalamRadius = false;
            let jarakTerdekat = null;

            titikLokasi.forEach(t => {
                const jarak = getDistance(
                    userLat,
                    userLng,
                    t.latitude,
                    t.longitude
                );

                if (jarakTerdekat === null || jarak < jarakTerdekat) {
                    jarakTerdekat = jarak;
                }

                if (jarak <= t.radius_meter) {
                    dalamRadius = true;
                }
            });

            document.getElementById('distance-val').innerText =
                jarakTerdekat + " Meter";

            const status = document.getElementById('status-msg');

            if (dalamRadius) {
                status.innerHTML =
                    "<span class='text-green-600 font-bold'>? Anda berada dalam radius lokasi kerja</span>";
                status.className =
                    "text-xs bg-green-50 p-2 rounded text-center border border-green-200";
            } else {
                status.innerHTML =
                    "<span class='text-red-600 font-bold'>? Anda di luar radius lokasi kerja</span>";
                status.className =
                    "text-xs bg-red-50 p-2 rounded text-center border border-red-200";
            }
        },
        error => {
            document.getElementById('status-msg').innerText =
                "Gagal mengambil GPS: " + error.message;
        },
        { enableHighAccuracy: true }
    );
} else {
    alert("Browser tidak mendukung GPS");
}
</script>

  
</body>

</html>