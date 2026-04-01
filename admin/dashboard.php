<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';


$tgl = date('Y-m-d');

// Bagian filter untuk admin
$bagian_filter = '';
$bagian_filter_petugas = '';
if (isset($_SESSION['bagian_id']) && !empty($_SESSION['bagian_id'])) {
    $bagian_id = (int)$_SESSION['bagian_id'];
    $bagian_filter = " AND p.bagian_id = $bagian_id";
    $bagian_filter_petugas = " WHERE bagian_id = $bagian_id";
}


// Query Statistik

$total_petugas = (int)$conn->query("SELECT COUNT(*) as total FROM petugas" . $bagian_filter_petugas)->fetch_assoc()['total'];
$hadir = (int)$conn->query("SELECT COUNT(*) as total FROM absensi a JOIN petugas p ON a.petugas_id = p.id WHERE a.tanggal = '$tgl' AND a.status = 'hadir'" . $bagian_filter)->fetch_assoc()['total'];
$izin = (int)$conn->query("SELECT COUNT(*) as total FROM absensi a JOIN petugas p ON a.petugas_id = p.id WHERE a.tanggal = '$tgl' AND a.status IN ('izin', 'sakit', 'lupa absen')" . $bagian_filter)->fetch_assoc()['total'];

// Pending validations with bagian filter
if (isset($_SESSION['bagian_id']) && !empty($_SESSION['bagian_id'])) {
    $bagian_id = (int)$_SESSION['bagian_id'];
    $pending_kejadian = (int)$conn->query("SELECT COUNT(*) as total FROM kejadian k JOIN petugas p ON k.petugas_id = p.id WHERE k.status='pending' AND p.bagian_id = $bagian_id")->fetch_assoc()['total'];
    $pending_pengajuan = (int)$conn->query("SELECT COUNT(*) as total FROM pengajuan pg JOIN petugas p ON pg.petugas_id = p.id WHERE pg.status='pending' AND p.bagian_id = $bagian_id")->fetch_assoc()['total'];
    $pending = $pending_kejadian + $pending_pengajuan;
} else {
    $pending = (int)$conn->query("SELECT (SELECT COUNT(*) FROM kejadian WHERE status='pending') + (SELECT COUNT(*) FROM pengajuan WHERE status='pending') as total")->fetch_assoc()['total'];
}


$hasLatMasuk = false;

$hasLongMasuk = false;

$checkLatMasuk = $conn->query("SHOW COLUMNS FROM absensi LIKE 'lat_masuk'");

if ($checkLatMasuk && $checkLatMasuk->num_rows > 0) {

    $hasLatMasuk = true;

}

$checkLongMasuk = $conn->query("SHOW COLUMNS FROM absensi LIKE 'long_masuk'");

if ($checkLongMasuk && $checkLongMasuk->num_rows > 0) {

    $hasLongMasuk = true;

}

$hasLokasiMasukV2 = ($hasLatMasuk && $hasLongMasuk);



$hasLatKeluar = false;

$hasLongKeluar = false;

$checkLatKeluar = $conn->query("SHOW COLUMNS FROM absensi LIKE 'latitude_keluar'");

if ($checkLatKeluar && $checkLatKeluar->num_rows > 0) {

    $hasLatKeluar = true;

}

$checkLongKeluar = $conn->query("SHOW COLUMNS FROM absensi LIKE 'longitude_keluar'");

if ($checkLongKeluar && $checkLongKeluar->num_rows > 0) {

    $hasLongKeluar = true;

}

$hasLatKeluarV2 = false;

$hasLongKeluarV2 = false;

$checkLatKeluarV2 = $conn->query("SHOW COLUMNS FROM absensi LIKE 'lat_keluar'");

if ($checkLatKeluarV2 && $checkLatKeluarV2->num_rows > 0) {

    $hasLatKeluarV2 = true;

}

$checkLongKeluarV2 = $conn->query("SHOW COLUMNS FROM absensi LIKE 'long_keluar'");

if ($checkLongKeluarV2 && $checkLongKeluarV2->num_rows > 0) {

    $hasLongKeluarV2 = true;

}

$hasLokasiKeluar = ($hasLatKeluar && $hasLongKeluar) || ($hasLatKeluarV2 && $hasLongKeluarV2);



$selectLatMasuk = $hasLokasiMasukV2 ? 'a.lat_masuk AS latitude' : 'a.latitude AS latitude';

$selectLongMasuk = $hasLokasiMasukV2 ? 'a.long_masuk AS longitude' : 'a.longitude AS longitude';



$selectLatKeluar = ($hasLatKeluarV2 && $hasLongKeluarV2)

    ? 'a.lat_keluar AS latitude_keluar'

    : ($hasLokasiKeluar ? 'a.latitude_keluar AS latitude_keluar' : 'NULL AS latitude_keluar');

$selectLongKeluar = ($hasLatKeluarV2 && $hasLongKeluarV2)

    ? 'a.long_keluar AS longitude_keluar'

    : ($hasLokasiKeluar ? 'a.longitude_keluar AS longitude_keluar' : 'NULL AS longitude_keluar');

$where_clause = "a.tanggal = '$tgl'";
if (isset($_SESSION['bagian_id']) && !empty($_SESSION['bagian_id'])) {
    $bagian_id = (int)$_SESSION['bagian_id'];
    $where_clause .= " AND p.bagian_id = $bagian_id";
}



$q_absensi = "SELECT a.tanggal, a.jam_masuk, a.jam_keluar, $selectLatMasuk, $selectLongMasuk, $selectLatKeluar, $selectLongKeluar, p.nama

    FROM absensi a

    JOIN petugas p ON a.petugas_id = p.id

    WHERE $where_clause

    ORDER BY COALESCE(a.jam_keluar, a.jam_masuk) DESC

    LIMIT 8";

$res_absensi = $conn->query($q_absensi);

if (isset($_GET['partial']) && $_GET['partial'] === '1') {

    header('Content-Type: application/json; charset=utf-8');

    $rowsHtml = '';

    if ($res_absensi && $res_absensi->num_rows > 0) {

        while ($row = $res_absensi->fetch_assoc()) {

            $rowsHtml .= "<tr class=\"bg-white border-b hover:bg-gray-50\">";

            $rowsHtml .= "<td class=\"px-6 py-3\">" . $row['nama'] . "</td>";

            $rowsHtml .= "<td class=\"px-6 py-3\">" . date('d M Y', strtotime($row['tanggal'])) . "</td>";

            $rowsHtml .= "<td class=\"px-6 py-3 text-green-700 font-bold\">" . (!empty($row['jam_masuk']) ? date('H:i', strtotime($row['jam_masuk'])) : '-') . "</td>";

            $rowsHtml .= "<td class=\"px-6 py-3 text-red-700 font-bold\">" . (!empty($row['jam_keluar']) ? date('H:i', strtotime($row['jam_keluar'])) : '-') . "</td>";



            if (!empty($row['latitude'])) {

                $rowsHtml .= "<td class=\"px-6 py-3 text-center\"><a href=\"https://www.google.com/maps?q=" . $row['latitude'] . "," . $row['longitude'] . "\" target=\"_blank\" class=\"text-blue-600 hover:text-blue-800\" title=\"Buka Lokasi Masuk\"><i class=\"fas fa-map-marker-alt\"></i></a></td>";

            } else {

                $rowsHtml .= "<td class=\"px-6 py-3 text-xs text-gray-400\">-</td>";

            }



            if (!empty($row['latitude_keluar'])) {

                $rowsHtml .= "<td class=\"px-6 py-3 text-center\"><a href=\"https://www.google.com/maps?q=" . $row['latitude_keluar'] . "," . $row['longitude_keluar'] . "\" target=\"_blank\" class=\"text-blue-600 hover:text-blue-800\" title=\"Buka Lokasi Keluar\"><i class=\"fas fa-map-marker-alt\"></i></a></td>";

            } else {

                $rowsHtml .= "<td class=\"px-6 py-3 text-xs text-gray-400\">-</td>";

            }



            $rowsHtml .= "</tr>";

        }

    } else {

        $rowsHtml = '<tr><td colspan="6" class="text-center py-4">Belum ada data absensi.</td></tr>';

    }



    echo json_encode([

        'total_petugas' => $total_petugas,

        'hadir' => $hadir,

        'izin' => $izin,

        'pending' => $pending,

        'rows_html' => $rowsHtml,

    ]);

    exit;
}

// Include layout for normal page load
require_once 'layout/header.php';
require_once 'layout/sidebar.php';
?>

<div class="mb-6">

    <h1 class="text-2xl font-bold text-gray-800">Dashboard</h1>

    <p class="text-gray-600 text-sm">Ringkasan hari ini: <?= date('d F Y') ?></p>

</div>



<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">

    

    <div class="bg-white rounded-xl shadow p-5 border-l-4 border-blue-500 flex items-center justify-between">

        <div>

            <p class="text-gray-500 text-xs font-bold uppercase">Total Petugas</p>

            <h3 id="stat-total-petugas" class="text-2xl font-bold text-gray-800"><?= $total_petugas ?></h3>

        </div>

        <div class="bg-blue-100 p-3 rounded-full text-blue-600"><i class="fas fa-users"></i></div>

    </div>



    <div class="bg-white rounded-xl shadow p-5 border-l-4 border-green-500 flex items-center justify-between">

        <div>

            <p class="text-gray-500 text-xs font-bold uppercase">Hadir Hari Ini</p>

            <h3 id="stat-hadir" class="text-2xl font-bold text-gray-800"><?= $hadir ?></h3>

        </div>

        <div class="bg-green-100 p-3 rounded-full text-green-600"><i class="fas fa-check"></i></div>

    </div>



    <div class="bg-white rounded-xl shadow p-5 border-l-4 border-purple-500 flex items-center justify-between">

        <div>

            <p class="text-gray-500 text-xs font-bold uppercase">Izin / Sakit / Lupa</p>

            <h3 id="stat-izin" class="text-2xl font-bold text-gray-800"><?= $izin ?></h3>

        </div>

        <div class="bg-purple-100 p-3 rounded-full text-purple-600"><i class="fas fa-bed"></i></div>

    </div>



    <div class="bg-white rounded-xl shadow p-5 border-l-4 border-orange-500 flex items-center justify-between">

        <div>

            <p class="text-gray-500 text-xs font-bold uppercase">Menunggu Validasi</p>

            <h3 id="stat-pending" class="text-2xl font-bold text-orange-600"><?= $pending ?></h3>

        </div>

        <div class="bg-orange-100 p-3 rounded-full text-orange-600 animate-pulse"><i class="fas fa-bell"></i></div>

    </div>

</div>



<div class="block w-full bg-white rounded-xl shadow overflow-hidden">

    <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">

        <h2 class="font-bold text-gray-800">Ringkasan Absensi Terbaru (Realtime)</h2>

        <a href="<?= BASE_URL ?>/admin/absensi/index.php" class="text-sm text-blue-600 hover:underline">Lihat Semua</a>

    </div>



    <?php if (!$hasLokasiKeluar): ?>

        <div class="px-6 py-3 bg-yellow-50 border-b border-yellow-200 text-yellow-800 text-sm">

            Lokasi Keluar belum bisa ditampilkan karena kolom <b>latitude_keluar</b> dan <b>longitude_keluar</b> belum ada di tabel absensi. Jalankan migrasi SQL terlebih dahulu.

        </div>

    <?php endif; ?>

    

    <div class="overflow-x-auto">

        <table class="w-full text-sm text-left text-gray-500">

            <thead class="text-xs text-gray-700 uppercase bg-gray-100">

                <tr>

                    <th class="px-6 py-3">Nama</th>

                    <th class="px-6 py-3">Tanggal</th>

                    <th class="px-6 py-3">Jam Masuk</th>

                    <th class="px-6 py-3">Jam Keluar</th>

                    <th class="px-6 py-3 text-center">Lokasi Masuk</th>

                    <th class="px-6 py-3 text-center">Lokasi Keluar</th>

                </tr>

            </thead>

            <tbody id="tbody-events">

                <?php if ($res_absensi && $res_absensi->num_rows > 0): ?>

                    <?php while($row = $res_absensi->fetch_assoc()): ?>

                        <tr class="bg-white border-b hover:bg-gray-50">

                            <td class="px-6 py-3"><?= $row['nama'] ?></td>

                            <td class="px-6 py-3"><?= date('d M Y', strtotime($row['tanggal'])) ?></td>

                            <td class="px-6 py-3 text-green-700 font-bold"><?= !empty($row['jam_masuk']) ? date('H:i', strtotime($row['jam_masuk'])) : '-' ?></td>

                            <td class="px-6 py-3 text-red-700 font-bold"><?= !empty($row['jam_keluar']) ? date('H:i', strtotime($row['jam_keluar'])) : '-' ?></td>

                            <td class="px-6 py-3 text-center">

                                <?php if(!empty($row['latitude'])): ?>

                                    <a href="https://www.google.com/maps?q=<?= $row['latitude'] ?>,<?= $row['longitude'] ?>" target="_blank" class="text-blue-600 hover:text-blue-800" title="Buka Lokasi Masuk">

                                        <i class="fas fa-map-marker-alt"></i>

                                    </a>

                                <?php else: ?>

                                    <span class="text-xs text-gray-400">-</span>

                                <?php endif; ?>

                            </td>

                            <td class="px-6 py-3 text-center">

                                <?php if(!empty($row['latitude_keluar'])): ?>

                                    <a href="https://www.google.com/maps?q=<?= $row['latitude_keluar'] ?>,<?= $row['longitude_keluar'] ?>" target="_blank" class="text-blue-600 hover:text-blue-800" title="Buka Lokasi Keluar">

                                        <i class="fas fa-map-marker-alt"></i>

                                    </a>

                                <?php else: ?>

                                    <span class="text-xs text-gray-400">-</span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr><td colspan="6" class="text-center py-4">Belum ada data absensi.</td></tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>



<script>

    (function () {

        var tbody = document.getElementById('tbody-events');

        if (!tbody) return;



        var lastPayload = '';

        var timer = null;



        function setText(id, value) {

            var el = document.getElementById(id);

            if (el) el.textContent = value;

        }



        async function refreshDashboard() {

            try {

                var url = new URL(window.location.href);

                url.searchParams.set('partial', '1');



                var res = await fetch(url.toString(), { cache: 'no-store' });

                if (!res.ok) return;



                var data = await res.json();

                var payload = JSON.stringify(data);

                if (payload === lastPayload) return;



                setText('stat-total-petugas', data.total_petugas);

                setText('stat-hadir', data.hadir);

                setText('stat-izin', data.izin);

                setText('stat-pending', data.pending);

                tbody.innerHTML = data.rows_html;

                lastPayload = payload;

            } catch (e) {

                return;

            }

        }



        function start() {

            if (timer) return;

            timer = setInterval(refreshDashboard, 4000);

        }



        function stop() {

            if (!timer) return;

            clearInterval(timer);

            timer = null;

        }



        document.addEventListener('visibilitychange', function () {

            if (document.hidden) stop(); else start();

        });



        refreshDashboard();

        start();

    })();

</script>



<?php require_once 'layout/footer.php'; ?>