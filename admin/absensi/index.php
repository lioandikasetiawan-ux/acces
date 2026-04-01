<?php

require_once '../../config/database.php';

require_once '../../config/session.php';

ini_set('display_errors', 0);

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {

    header("Location: ../../auth/login-v2.php");

    exit;

}



/**
 * PERFORMA REALTIME: Schema runtime checks (SHOW COLUMNS/SHOW TABLES) 
 * dinonaktifkan pada endpoint realtime untuk menjaga latency tetap rendah.
 * 
 * - Full page load: getSchemaCache() — cache ke session, TTL 10 menit
 * - Partial AJAX (?partial=1): getSchemaCacheReadOnly() — TIDAK PERNAH SHOW query
 * - update-status.php: sudah bersih, tidak ada SHOW query
 * - absensi-process-v2.php: sudah bersih, tidak ada SHOW query
 */
require_once '../../config/schema-bootstrap.php';

$isPartialRequest = (isset($_GET['partial']) && $_GET['partial'] === '1');

if ($isPartialRequest) {
    // REALTIME PATH: Tidak boleh ada SHOW COLUMNS / SHOW TABLES
    $schemaCache = getSchemaCacheReadOnly();
} else {
    // FULL PAGE LOAD: Jalankan schema check jika cache expired
    $schemaCache = getSchemaCache($conn);
}

// Ekstrak variabel dari cache
$shiftTableExists = $schemaCache['shiftTableExists'];
$absensiShiftIdExists = $schemaCache['absensiShiftIdExists'];
$bagianTableExists = $schemaCache['bagianTableExists'];
$petugasBagianColumnExists = $schemaCache['petugasBagianColumnExists'];
$petugasBagianIdColumnExists = $schemaCache['petugasBagianIdColumnExists'];
$hasLokasiMasukV2 = $schemaCache['hasLokasiMasukV2'];
$hasFotoMasuk = $schemaCache['hasFotoMasuk'];
$hasLatKeluar = $schemaCache['hasLatKeluar'];
$hasLongKeluar = $schemaCache['hasLongKeluar'];
$hasLatKeluarV2 = $schemaCache['hasLatKeluarV2'];
$hasLongKeluarV2 = $schemaCache['hasLongKeluarV2'];
$hasLokasiKeluar = $schemaCache['hasLokasiKeluar'];

// Shift list (1 query operasional, bukan schema check)
$shiftList = [];
if ($shiftTableExists) {
    if (isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] !== '' && $_SESSION['bagian_id'] !== null) {
        $shiftBagianId = (int)$_SESSION['bagian_id'];
        $resShift = $conn->query("SELECT nama_shift FROM shift WHERE bagian_id = $shiftBagianId ORDER BY id ASC");
    } else {
        $resShift = $conn->query("SELECT nama_shift FROM shift ORDER BY id ASC");
    }
    if ($resShift) {
        while ($s = $resShift->fetch_assoc()) {
            if (isset($s['nama_shift'])) $shiftList[] = $s['nama_shift'];
        }
    }
}



// --- LOGIKA FILTER PER BULAN ---

$bulan_filter = isset($_GET['bulan']) ? $_GET['bulan'] : date('Y-m');

$shift_filter = isset($_GET['shift']) ? $_GET['shift'] : 'semua';

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';

$exportExcel = isset($_GET['excel']) && $_GET['excel'] === '1';



// Hitung tanggal awal dan akhir bulan

$tgl_awal = $bulan_filter . '-01';

$tgl_akhir = date('Y-m-t', strtotime($tgl_awal));



// Query Dasar dengan Join ke Tabel Petugas

$joinShift = $shiftTableExists ? " LEFT JOIN jadwal_petugas jp ON a.jadwal_id = jp.id LEFT JOIN shift s ON jp.shift_id = s.id " : "";

$selectShift = $shiftTableExists ? "s.nama_shift" : "'-'";

$joinBagian = ($bagianTableExists && $petugasBagianIdColumnExists) ? " LEFT JOIN bagian b ON p.bagian_id = b.id " : "";

$selectBagian = $petugasBagianColumnExists

    ? "p.bagian AS bagian"

    : (($bagianTableExists && $petugasBagianIdColumnExists) ? "COALESCE(b.nama_bagian, '-') AS bagian" : "'-' AS bagian");

$selectFotoMasuk = $hasFotoMasuk ? "a.foto_masuk AS foto_absen" : "a.foto_absen AS foto_absen";

$selectLatMasuk = $hasLokasiMasukV2 ? "a.lat_masuk AS latitude" : "a.latitude AS latitude";

$selectLongMasuk = $hasLokasiMasukV2 ? "a.long_masuk AS longitude" : "a.longitude AS longitude";

$selectLatKeluar = ($hasLatKeluarV2 && $hasLongKeluarV2)

    ? 'a.lat_keluar AS latitude_keluar'

    : ($hasLatKeluar && $hasLongKeluar ? 'a.latitude_keluar AS latitude_keluar' : 'NULL AS latitude_keluar');

$selectLongKeluar = ($hasLatKeluarV2 && $hasLongKeluarV2)

    ? 'a.long_keluar AS longitude_keluar'

    : ($hasLatKeluar && $hasLongKeluar ? 'a.longitude_keluar AS longitude_keluar' : 'NULL AS longitude_keluar');



$sql = "SELECT 

            a.id, a.petugas_id, a.tanggal, 

            $selectShift AS shift,

            a.jam_masuk, a.jam_keluar,

            $selectFotoMasuk,

            a.foto_keluar,

            $selectLatMasuk,

            $selectLongMasuk,

            $selectLatKeluar,

            $selectLongKeluar,

            a.status,

            p.nama, p.nip, $selectBagian

        FROM absensi a 

        JOIN petugas p ON a.petugas_id = p.id 

        $joinShift

        $joinBagian

        WHERE a.tanggal BETWEEN '$tgl_awal' AND '$tgl_akhir'";


// Filter by bagian_id if set in session
if (isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] != '') {
    $sql .= " AND p.bagian_id = " . (int)$_SESSION['bagian_id'];}
// Tambahan Filter Shift

if ($shift_filter != 'semua') {

    if ($shiftTableExists) {

        $shift_filter_escaped = $conn->real_escape_string($shift_filter);
        $sql .= " AND s.nama_shift = '$shift_filter_escaped'";

    } else {

        $sql .= " AND a.shift = '$shift_filter'";

    }

}



// Filter Search

if ($search) {

    $sql .= " AND (p.nama LIKE '%$search%' OR p.nip LIKE '%$search%' OR a.status LIKE '%$search%')";

}



// Pagination
$perPage = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// Count total
$countSql = preg_replace('/SELECT.*?FROM /s', 'SELECT COUNT(*) as total FROM ', $sql, 1);
$countResult = $conn->query($countSql);
$totalRows = ($countResult && $row_c = $countResult->fetch_assoc()) ? (int)$row_c['total'] : 0;
$totalPages = max(1, ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql .= " ORDER BY a.tanggal DESC, a.jam_masuk DESC";

if (!$exportExcel) {
    $sql .= " LIMIT $perPage OFFSET $offset";
}

$result = $conn->query($sql);

if ($exportExcel) {
    $filename = 'absensi_' . $tgl_awal . '_sd_' . $tgl_akhir . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    echo '<table border="1">';
    echo '<thead><tr>';
    echo '<th>No</th>';
    echo '<th>Tanggal</th>';
    echo '<th>Nama</th>';
    echo '<th>NIP</th>';
    echo '<th>Bagian</th>';
    echo '<th>Shift</th>';
    echo '<th>Masuk</th>';
    echo '<th>Keluar</th>';
    echo '<th>Status</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    $no = 1;
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            echo '<tr>';
            echo '<td>' . $no++ . '</td>';
            echo '<td>' . (!empty($row['tanggal']) ? date('d/m/Y', strtotime($row['tanggal'])) : '-') . '</td>';
            echo '<td>' . htmlspecialchars($row['nama'] ?? '') . '</td>';
            echo '<td style="mso-number-format:\'\\@\'">' . htmlspecialchars($row['nip'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($row['bagian'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($row['shift'] ?? '') . '</td>';
            echo '<td>' . (!empty($row['jam_masuk']) ? date('H:i', strtotime($row['jam_masuk'])) : '-') . '</td>';
            echo '<td>' . (!empty($row['jam_keluar']) ? date('H:i', strtotime($row['jam_keluar'])) : '-') . '</td>';
            echo '<td>' . htmlspecialchars($row['status'] ?? '') . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="9">Tidak ada data.</td></tr>';
    }

    echo '</tbody></table>';
    exit;
}



if (isset($_GET['partial']) && $_GET['partial'] === '1') {

    if ($result && $result->num_rows > 0) {

        while ($row = $result->fetch_assoc()) {

            echo "<tr class=\"bg-white border-b hover:bg-gray-50\">";

            echo "<td class=\"px-4 py-3 whitespace-nowrap\">" . date('d M Y', strtotime($row['tanggal'])) . "</td>";

            echo "<td class=\"px-4 py-3\">";

            echo "<div class=\"font-bold text-gray-800\">" . $row['nama'] . "</div>";

            echo "<div class=\"text-xs text-gray-500\">" . $row['nip'] . "</div>";

            echo "</td>";

            echo "<td class=\"px-4 py-3\"><span class=\"uppercase text-xs font-bold text-gray-600\">" . $row['shift'] . "</span></td>";

            echo "<td class=\"px-4 py-3 text-green-700 font-bold\">" . ($row['jam_masuk'] ? date('H:i', strtotime($row['jam_masuk'])) : '-') . "</td>";

            echo "<td class=\"px-4 py-3 text-red-700 font-bold\">" . ($row['jam_keluar'] ? date('H:i', strtotime($row['jam_keluar'])) : '-') . "</td>";

            echo "<td class=\"px-4 py-3\">";

            if (!empty($row['foto_absen'])) {

                echo "<img src=\"" . $row['foto_absen'] . "\" class=\"w-10 h-10 rounded object-cover border border-gray-200 hover:scale-150 transition js-zoom-img\" data-img=\"" . htmlspecialchars($row['foto_absen']) . "\">";

            } else {

                echo "<span class=\"text-xs text-gray-400\">-</span>";

            }

            echo "</td>";

            echo "<td class=\"px-4 py-3\">";

            if (!empty($row['foto_keluar'])) {

                echo "<img src=\"" . $row['foto_keluar'] . "\" class=\"w-10 h-10 rounded object-cover border border-gray-200 hover:scale-150 transition js-zoom-img\" data-img=\"" . htmlspecialchars($row['foto_keluar']) . "\">";

            } else {

                echo "<span class=\"text-xs text-gray-400\">-</span>";

            }

            echo "</td>";

            echo "<td class=\"px-4 py-3\">";

            if (!empty($row['latitude'])) {

                echo "<a href=\"https://www.google.com/maps?q=" . $row['latitude'] . "," . $row['longitude'] . "\" target=\"_blank\" class=\"text-blue-600 hover:underline text-xs\"><i class=\"fas fa-map-marked-alt mr-1\"></i> Cek Maps</a>";

            }

            echo "</td>";

            echo "<td class=\"px-4 py-3\">";

            if ($hasLokasiKeluar && !empty($row['latitude_keluar'])) {

                echo "<a href=\"https://www.google.com/maps?q=" . $row['latitude_keluar'] . "," . $row['longitude_keluar'] . "\" target=\"_blank\" class=\"text-blue-600 hover:underline text-xs\"><i class=\"fas fa-map-marked-alt mr-1\"></i> Cek Maps</a>";

            } else {

                echo "<span class=\"text-xs text-gray-400\">-</span>";

            }

            echo "</td>";

		switch ($row['status']) {
    case 'hadir':
        $statusClass = 'bg-green-100 text-green-800';
        break;

    case 'absen masuk':
        $statusClass = 'bg-blue-100 text-blue-800';
        break;

    case 'izin':
        $statusClass = 'bg-yellow-100 text-yellow-800';
        break;

    case 'sakit':
        $statusClass = 'bg-purple-100 text-purple-800';
        break;

    case 'lupa absen':
        $statusClass = 'bg-orange-100 text-orange-800';
        break;

    case 'tidak hadir':
        $statusClass = 'bg-red-100 text-red-800';
        break;

    default:
        $statusClass = 'bg-gray-100 text-gray-800';
}

            // Dropdown ubah status manual
            echo "<td class=\"px-4 py-3\">";
            echo "<select onchange=\"ubahStatus(" . $row['id'] . ", this.value, this)\" class=\"text-xs font-bold uppercase px-2 py-1 rounded border-0 " . $statusClass . " cursor-pointer\" data-original-status=\"" . htmlspecialchars($row['status']) . "\">";
            $statusOptions = ['--', 'absen masuk', 'hadir', 'izin', 'sakit', 'tidak hadir', 'lupa absen'];
            foreach ($statusOptions as $opt) {
                $selected = ($opt === $row['status']) ? 'selected' : '';
                echo "<option value=\"" . htmlspecialchars($opt) . "\" $selected>" . htmlspecialchars($opt) . "</option>";
            }
            echo "</select>";
            echo "</td>";

            echo "<td class=\"px-4 py-3\"><a href=\"petugas.php?id=" . $row['petugas_id'] . "&bulan=" . $bulan_filter . "\" class=\"bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 rounded text-xs font-bold\"><i class=\"fas fa-eye mr-1\"></i> Detail</a></td>";

            echo "</tr>";

        }

    } else {

        echo "<tr><td colspan=\"11\" class=\"px-6 py-8 text-center text-gray-500\"><i class=\"fas fa-folder-open text-4xl mb-3 text-gray-300 block\"></i>Tidak ada data absensi pada bulan ini.</td></tr>";

    }

    exit;

}



require_once '../layout/header.php';

require_once '../layout/sidebar.php';

?>



<div class="flex flex-col sm:flex-row justify-between items-center mb-6 gap-4">

    <h1 class="text-2xl font-bold text-gray-800">Rekap Data Absensi</h1>



    <div class="flex items-center gap-2">

        <a href="?bulan=<?= $bulan_filter ?>&shift=<?= $shift_filter ?>&search=<?= urlencode($search) ?>&excel=1" 

           class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg shadow flex items-center gap-2">

            <i class="fas fa-file-excel"></i> Export Excel

        </a>

        <a href="cetak.php?bulan=<?= $bulan_filter ?>&shift=<?= $shift_filter ?>&search=<?= urlencode($search) ?>" target="_blank" 

           class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg shadow flex items-center gap-2">

            <i class="fas fa-print"></i> Cetak PDF

        </a>

    </div>

</div>



<div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 mb-6">

    <form action="" method="GET" class="flex flex-col md:flex-row gap-4 items-end">

        

        <div class="w-full md:w-auto">

            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Pilih Bulan</label>

            <input type="month" name="bulan" value="<?= $bulan_filter ?>" class="border rounded p-2 w-full text-sm">

        </div>



        <div class="w-full md:w-auto">

            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Filter Shift</label>

            <select name="shift" class="border rounded p-2 w-full text-sm bg-white">

                <option value="semua">Semua Shift</option>

                <?php if ($shiftTableExists && count($shiftList) > 0): ?>

                    <?php foreach ($shiftList as $s): ?>

                        <option value="<?= htmlspecialchars($s) ?>" <?= $shift_filter == $s ? 'selected' : '' ?>>

                            <?= htmlspecialchars($s) ?>

                        </option>

                    <?php endforeach; ?>

                <?php else: ?>

                    <option value="pagi" <?= $shift_filter == 'pagi' ? 'selected' : '' ?>>Pagi</option>

                    <option value="siang" <?= $shift_filter == 'siang' ? 'selected' : '' ?>>Siang</option>

                    <option value="malam" <?= $shift_filter == 'malam' ? 'selected' : '' ?>>Malam</option>

                <?php endif; ?>

            </select>

        </div>



        <div class="w-full md:w-auto">

            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Cari</label>

            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Nama, NIP, Status..." class="border rounded p-2 w-full text-sm">

        </div>



        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded shadow text-sm font-bold w-full md:w-auto">

            <i class="fas fa-filter mr-1"></i> Tampilkan

        </button>

    </form>

</div>



<div class="bg-white rounded-lg shadow overflow-hidden">

    <div class="overflow-x-auto">

        <table class="w-full text-sm text-left text-gray-500">

            <thead class="text-xs text-gray-700 uppercase bg-gray-100">

                <tr>

                    <th class="px-4 py-3">Tanggal</th>

                    <th class="px-4 py-3">Petugas</th>

                    <th class="px-4 py-3">Shift</th>

                    <th class="px-4 py-3">Jam Masuk</th>

                    <th class="px-4 py-3">Jam Keluar</th>

                    <th class="px-4 py-3">Foto Masuk</th>

                    <th class="px-4 py-3">Foto Keluar</th>

                    <th class="px-4 py-3">Lokasi Masuk</th>

                    <th class="px-4 py-3">Lokasi Keluar</th>

                    <th class="px-4 py-3">Status</th>

                    <th class="px-4 py-3">Aksi</th>

                </tr>

            </thead>

            <tbody id="table-body">

                <?php if ($result->num_rows > 0): ?>

                    <?php while ($row = $result->fetch_assoc()): ?>

                        <tr class="bg-white border-b hover:bg-gray-50">

                            <td class="px-4 py-3 whitespace-nowrap">

                                <?= date('d M Y', strtotime($row['tanggal'])) ?>

                            </td>

                            <td class="px-4 py-3">

                                <div class="font-bold text-gray-800"><?= $row['nama'] ?></div>

                                <div class="text-xs text-gray-500"><?= $row['nip'] ?></div>

                            </td>

                            <td class="px-4 py-3">

                                <span class="uppercase text-xs font-bold text-gray-600"><?= $row['shift'] ?></span>

                            </td>

                            <td class="px-4 py-3 text-green-700 font-bold">

                                <?= $row['jam_masuk'] ? date('H:i', strtotime($row['jam_masuk'])) : '-' ?>

                            </td>

                            <td class="px-4 py-3 text-red-700 font-bold">

                                <?= $row['jam_keluar'] ? date('H:i', strtotime($row['jam_keluar'])) : '-' ?>

                            </td>

                            <td class="px-4 py-3">

                                <?php if($row['foto_absen']): ?>

                                    <img src="<?= $row['foto_absen'] ?>" class="w-10 h-10 rounded object-cover border border-gray-200 cursor-pointer hover:scale-150 transition js-zoom-img" data-img="<?= htmlspecialchars($row['foto_absen']) ?>">

                                <?php else: ?>

                                    <span class="text-xs text-gray-400">-</span>

                                <?php endif; ?>

                            </td>

                            <td class="px-4 py-3">

                                <?php if(!empty($row['foto_keluar'])): ?>

                                    <img src="<?= $row['foto_keluar'] ?>" class="w-10 h-10 rounded object-cover border border-gray-200 cursor-pointer hover:scale-150 transition js-zoom-img" data-img="<?= htmlspecialchars($row['foto_keluar']) ?>">

                                <?php else: ?>

                                    <span class="text-xs text-gray-400">-</span>

                                <?php endif; ?>

                            </td>

                            <td class="px-4 py-3">

                                <?php if($row['latitude']): ?>

                                    <a href="https://www.google.com/maps?q=<?= $row['latitude'] ?>,<?= $row['longitude'] ?>" target="_blank" class="text-blue-600 hover:underline text-xs">

                                        <i class="fas fa-map-marked-alt mr-1"></i> Cek Maps

                                    </a>

                                <?php else: ?>

                                    <span class="text-xs text-gray-400">-</span>

                                <?php endif; ?>

                            </td>

                            <td class="px-4 py-3">

                                <?php if($hasLokasiKeluar && !empty($row['latitude_keluar'])): ?>

                                    <a href="https://www.google.com/maps?q=<?= $row['latitude_keluar'] ?>,<?= $row['longitude_keluar'] ?>" target="_blank" class="text-blue-600 hover:underline text-xs">

                                        <i class="fas fa-map-marked-alt mr-1"></i> Cek Maps

                                    </a>

                                <?php else: ?>

                                    <span class="text-xs text-gray-400">-</span>

                                <?php endif; ?>

                            </td>

                            <td class="px-4 py-3">

                                <?php 

                               switch ($row['status']) {
    case 'hadir':
        $statusClass = 'bg-green-100 text-green-800';
        break;
    case 'absen masuk':
        $statusClass = 'bg-blue-100 text-blue-800';
        break;
    case 'izin':
        $statusClass = 'bg-yellow-100 text-yellow-800';
        break;
    case 'sakit':
        $statusClass = 'bg-purple-100 text-purple-800';
        break;
    case 'lupa absen':
        $statusClass = 'bg-orange-100 text-orange-800';
        break;
    case 'tidak masuk':
        $statusClass = 'bg-red-100 text-red-800';
        break;
    default:
        $statusClass = 'bg-gray-100 text-gray-800';
};

                                $statusOptions = ['--', 'absen masuk', 'hadir', 'izin', 'sakit', 'tidak hadir', 'lupa absen'];
                                ?>
                                <select onchange="ubahStatus(<?= $row['id'] ?>, this.value, this)" class="text-xs font-bold uppercase px-2 py-1 rounded border-0 <?= $statusClass ?> cursor-pointer" data-original-status="<?= htmlspecialchars($row['status']) ?>">
                                    <?php foreach ($statusOptions as $opt): ?>
                                        <option value="<?= htmlspecialchars($opt) ?>" <?= $opt === $row['status'] ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>

                            </td>

                            <td class="px-4 py-3">

                                <a href="petugas.php?id=<?= $row['petugas_id'] ?>&bulan=<?= $bulan_filter ?>" 

                                   class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 rounded text-xs font-bold">

                                    <i class="fas fa-eye mr-1"></i> Detail

                                </a>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>

                        <td colspan="11" class="px-6 py-8 text-center text-gray-500">

                            <i class="fas fa-folder-open text-4xl mb-3 text-gray-300 block"></i>

                            Tidak ada data absensi pada rentang tanggal ini.

                        </td>

                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

<?php
require_once '../layout/pagination.php';
$paginationParams = array_filter([
    'bulan' => $bulan_filter,
    'shift' => $shift_filter,
    'search' => $search,
]);
$paginationUrl = '?' . http_build_query($paginationParams);
renderPagination($page, $totalPages, $paginationUrl);
?>

</div>



<script>
    (function() {
        var tbody = document.getElementById('table-body');
        if (!tbody) return;

        var lastHtml = tbody.innerHTML;
        var timer = null;

        // Cek apakah user sedang berinteraksi (fokus di select/input dalam tabel)
        function isUserInteracting() {
            var active = document.activeElement;
            if (!active) return false;
            // Jika user sedang fokus di select/input di dalam tbody, jangan refresh
            if (active.tagName === 'SELECT' || active.tagName === 'INPUT') {
                return tbody.contains(active);
            }
            return false;
        }

        async function refreshTable() {
            // Skip refresh jika user sedang berinteraksi dengan dropdown/input
            if (isUserInteracting()) return;

            try {
                var url = new URL(window.location.href);
                url.searchParams.set('partial', '1');
                var res = await fetch(url.toString(), { cache: 'no-store' });
                if (!res.ok) return;

                var html = await res.text();
                if (html !== lastHtml) {
                    // Smart update: hanya ganti baris yang berubah
                    var temp = document.createElement('tbody');
                    temp.innerHTML = html;
                    var newRows = temp.querySelectorAll('tr');
                    var oldRows = tbody.querySelectorAll('tr');

                    if (newRows.length !== oldRows.length) {
                        // Jumlah baris berubah, ganti semuanya
                        tbody.innerHTML = html;
                    } else {
                        // Update per baris yang berubah
                        for (var i = 0; i < newRows.length; i++) {
                            if (oldRows[i].innerHTML !== newRows[i].innerHTML) {
                                // Jangan update baris jika user sedang fokus di dalamnya
                                var activeInRow = oldRows[i].contains(document.activeElement) &&
                                    (document.activeElement.tagName === 'SELECT' || document.activeElement.tagName === 'INPUT');
                                if (!activeInRow) {
                                    oldRows[i].innerHTML = newRows[i].innerHTML;
                                }
                            }
                        }
                    }
                    lastHtml = html;
                }
            } catch (e) {
                return;
            }
        }

        function start() {
            if (timer) return;
            timer = setInterval(refreshTable, 20000); // 20 detik
        }

        function stop() {
            if (!timer) return;
            clearInterval(timer);
            timer = null;
        }

        document.addEventListener('visibilitychange', function() {
            if (document.hidden) stop(); else start();
        });

        start();
    })();
</script>
<div id="imgZoomModal" class="fixed inset-0 z-[2000] hidden items-center justify-center bg-black/70 p-4">
    <div class="relative max-w-4xl w-full">
        <button type="button" id="imgZoomClose" class="absolute -top-10 right-0 text-white text-2xl leading-none">&times;</button>
        <img id="imgZoomTarget" src="" alt="Preview" class="w-full max-h-[85vh] object-contain rounded bg-white" />
    </div>
</div>

<script>
    (function () {
        var modal = document.getElementById('imgZoomModal');
        var img = document.getElementById('imgZoomTarget');
        var closeBtn = document.getElementById('imgZoomClose');

        if (!modal || !img) return;

        function open(src) {
            if (!src) return;
            img.src = src;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function close() {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            img.src = '';
            // Trigger cleanup
            if (window.cleanupOrphanedBackdrops) {
                setTimeout(window.cleanupOrphanedBackdrops, 100);
            }
        }

        document.addEventListener('click', function (e) {
            var t = e.target;
            if (t && t.classList && t.classList.contains('js-zoom-img')) {
                e.preventDefault();
                open(t.getAttribute('data-img') || t.getAttribute('src'));
            }
        });

        modal.addEventListener('click', function (e) {
            if (e.target === modal) close();
        });

        if (closeBtn) closeBtn.addEventListener('click', close);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') close();
        });
    })();

    // Function untuk ubah status absensi
    function ubahStatus(absensiId, newStatus, selectElement) {
        if (!confirm('Yakin ingin mengubah status absensi ini menjadi "' + newStatus + '"?')) {
            selectElement.value = selectElement.dataset.originalStatus; // Kembalikan ke status semula
            return;
        }
        
        // Simpan status asli sebelum fetch
        selectElement.dataset.originalStatus = selectElement.value;

        fetch('update-status.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'absensi_id=' + absensiId + '&status=' + encodeURIComponent(newStatus)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // Perbarui kelas CSS berdasarkan status baru
                let statusClass = '';
                switch (newStatus) {
                    case 'hadir':
                        statusClass = 'bg-green-100 text-green-800';
                        break;
                    case 'absen masuk':
                        statusClass = 'bg-blue-100 text-blue-800';
                        break;
                    case 'izin':
                        statusClass = 'bg-yellow-100 text-yellow-800';
                        break;
                    case 'sakit':
                        statusClass = 'bg-purple-100 text-purple-800';
                        break;
                    case 'lupa absen':
                        statusClass = 'bg-orange-100 text-orange-800';
                        break;
                    case 'tidak hadir':
                        statusClass = 'bg-red-100 text-red-800';
                        break;
                    default:
                        statusClass = 'bg-gray-100 text-gray-800';
                }
                selectElement.className = "text-xs font-bold uppercase px-2 py-1 rounded border-0 " + statusClass + " cursor-pointer";
                alert('Status berhasil diubah!');
            } else {
                alert('Error: ' + (data.message || 'Gagal mengubah status'));
                selectElement.value = selectElement.dataset.originalStatus; // Kembalikan ke status semula jika gagal
            }
        })
        .catch(err => {
            console.error(err);
            alert('Terjadi kesalahan saat mengubah status');
            selectElement.value = selectElement.dataset.originalStatus; // Kembalikan ke status semula jika error
        });
    }
</script>



<?php require_once '../layout/footer.php'; ?>