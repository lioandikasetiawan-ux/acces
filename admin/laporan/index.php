<?php

require_once '../../config/database.php';

require_once '../../config/env.php';

require_once '../../config/session.php';



if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {

    header("Location: ../../auth/login-v2.php");

    exit;

}



$exportExcel = isset($_GET['excel']) && $_GET['excel'] === '1';



// Filter Tanggal

$tgl_awal  = $_GET['start'] ?? date('Y-m-d');

$tgl_akhir = $_GET['end'] ?? date('Y-m-d');

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';



$bagianTableExists = false;

$checkBagianTable = $conn->query("SHOW TABLES LIKE 'bagian'");

if ($checkBagianTable && $checkBagianTable->num_rows > 0) {

    $bagianTableExists = true;

}



$petugasBagianColumnExists = false;

$checkPetugasBagian = $conn->query("SHOW COLUMNS FROM petugas LIKE 'bagian'");

if ($checkPetugasBagian && $checkPetugasBagian->num_rows > 0) {

    $petugasBagianColumnExists = true;

}



$petugasBagianIdColumnExists = false;

$checkPetugasBagianId = $conn->query("SHOW COLUMNS FROM petugas LIKE 'bagian_id'");

if ($checkPetugasBagianId && $checkPetugasBagianId->num_rows > 0) {

    $petugasBagianIdColumnExists = true;

}



// Query Laporan Harian

$joinBagian = ($bagianTableExists && $petugasBagianIdColumnExists) ? " LEFT JOIN bagian b ON p.bagian_id = b.id " : "";

$selectBagian = $petugasBagianColumnExists

    ? "p.bagian AS bagian"

    : (($bagianTableExists && $petugasBagianIdColumnExists) ? "COALESCE(b.nama_bagian, '-') AS bagian" : "'-' AS bagian");

$selectKodeSync = ($bagianTableExists && $petugasBagianIdColumnExists) ? ", b.kode_sync" : ", 0 AS kode_sync";



$sql = "SELECT l.*, a.tanggal, s.nama_shift AS shift, p.nama, p.nip, $selectBagian $selectKodeSync
        FROM laporan_harian l
        JOIN absensi a ON l.absensi_id = a.id
        JOIN jadwal_petugas jp ON a.jadwal_id = jp.id
        JOIN shift s ON jp.shift_id = s.id
        JOIN petugas p ON a.petugas_id = p.id
        $joinBagian
        WHERE a.tanggal BETWEEN '$tgl_awal' AND '$tgl_akhir'";

// Filter by bagian_id if set in session
if (isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] != '') {
    $sql .= " AND p.bagian_id = " . (int)$_SESSION['bagian_id'];
}

if ($search) {

    $searchConds = [];

    $searchConds[] = "p.nama LIKE '%$search%'";

    if ($petugasBagianColumnExists) {

        $searchConds[] = "p.bagian LIKE '%$search%'";

    }

    if ($bagianTableExists && $petugasBagianIdColumnExists) {

        $searchConds[] = "b.nama_bagian LIKE '%$search%'";

    }

    $searchConds[] = "l.kegiatan_harian LIKE '%$search%'";

    $sql .= " AND (" . implode(' OR ', $searchConds) . ")";

}



// Pagination
$perPage = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$countSql = preg_replace('/SELECT.*?FROM /s', 'SELECT COUNT(*) as total FROM ', $sql, 1);
$countResult = $conn->query($countSql);
$totalRows = ($countResult && $row_c = $countResult->fetch_assoc()) ? (int)$row_c['total'] : 0;
$totalPages = max(1, ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql .= " ORDER BY l.created_at DESC";

if (!$exportExcel) {
    $sql .= " LIMIT $perPage OFFSET $offset";
}

$result = $conn->query($sql);



if ($exportExcel) {
    $filename = 'laporan_kegiatan_' . $tgl_awal . '_sd_' . $tgl_akhir . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Pragma: no-cache');
    header('Expires: 0');

    // Scan data untuk menentukan kolom yang terisi
    $data = [];
    $colKategori = false; $colStatusAir = false; $colTMA = false;
    $colGulma = false; $colSaluran = false; $colBangunan = false;
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
            if (!empty($row['kegiatan_harian_kategori'])) $colKategori = true;
            if (!empty($row['ketersediaan_air'])) $colStatusAir = true;
            if (isset($row['TMA']) && $row['TMA'] !== null && $row['TMA'] !== '') $colTMA = true;
            if (!empty($row['status_gulma'])) $colGulma = true;
            if (!empty($row['kondisi_saluran'])) $colSaluran = true;
            if (!empty($row['bangunan_air'])) $colBangunan = true;
        }
    }

    echo "\xEF\xBB\xBF";
    echo '<table border="1"><thead><tr>';
    echo '<th>No</th><th>Tanggal</th><th>Shift</th><th>Nama</th><th>NIP</th><th>Bagian</th>';
    if ($colKategori) echo '<th>Kategori Kegiatan</th>';
    echo '<th>Uraian Kegiatan</th>';
    if ($colStatusAir) echo '<th>Status Air</th>';
    if ($colTMA) echo '<th>TMA (m)</th>';
    if ($colGulma) echo '<th>Status Gulma</th>';
    if ($colSaluran) echo '<th>Kondisi Saluran</th>';
    if ($colBangunan) echo '<th>Bangunan Air</th>';
    echo '<th>Geolokasi</th><th>Waktu Input</th>';
    echo '</tr></thead><tbody>';

    $no = 1;
    if (!empty($data)) {
        foreach ($data as $row) {
            echo '<tr>';
            echo '<td>' . $no++ . '</td>';
            echo '<td>' . date('d/m/Y', strtotime($row['tanggal'])) . '</td>';
            echo '<td>' . htmlspecialchars($row['shift']) . '</td>';
            echo '<td>' . htmlspecialchars($row['nama']) . '</td>';
            echo '<td style="mso-number-format:\'@\'">' . htmlspecialchars($row['nip'] ?? '-') . '</td>';
            echo '<td>' . htmlspecialchars($row['bagian']) . '</td>';
            if ($colKategori) {
                $kat = !empty($row['kegiatan_harian_kategori']) ? $row['kegiatan_harian_kategori'] : '-';
                if ($kat === 'Kegiatan Lainnya' && !empty($row['kegiatan_harian_lainnya'])) $kat .= ' (' . $row['kegiatan_harian_lainnya'] . ')';
                echo '<td>' . htmlspecialchars($kat) . '</td>';
            }
            echo '<td>' . htmlspecialchars($row['kegiatan_harian']) . '</td>';
            if ($colStatusAir) echo '<td>' . (!empty($row['ketersediaan_air']) ? htmlspecialchars($row['ketersediaan_air']) : '-') . '</td>';
            if ($colTMA) echo '<td>' . (isset($row['TMA']) && $row['TMA'] !== null && $row['TMA'] !== '' ? number_format((float)$row['TMA'], 2) : '-') . '</td>';
            if ($colGulma) echo '<td>' . (!empty($row['status_gulma']) ? htmlspecialchars($row['status_gulma']) : '-') . '</td>';
            if ($colSaluran) echo '<td>' . (!empty($row['kondisi_saluran']) ? htmlspecialchars($row['kondisi_saluran']) : '-') . '</td>';
            if ($colBangunan) echo '<td>' . (!empty($row['bangunan_air']) ? htmlspecialchars($row['bangunan_air']) : '-') . '</td>';
            echo '<td>' . (!empty($row['latitude']) && !empty($row['longitude']) ? htmlspecialchars($row['latitude']) . ',' . htmlspecialchars($row['longitude']) : '-') . '</td>';
            echo '<td>' . (!empty($row['created_at']) ? date('d/m/Y H:i', strtotime($row['created_at'])) : '-') . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="20">Belum ada laporan pada periode ini.</td></tr>';
    }

    echo '</tbody></table>';
    exit;
}



if (isset($_GET['partial']) && $_GET['partial'] === '1') {

    if ($result && $result->num_rows > 0) {

        while ($row = $result->fetch_assoc()) {

            echo "<tr class=\"bg-white border-b hover:bg-gray-50 align-top\">";

            echo "<td class=\"px-6 py-4\">" . date('d M Y', strtotime($row['tanggal'])) . "</td>";
            echo "<td class=\"px-6 py-4\">" . htmlspecialchars($row['nama']) . "</td>";
            echo "<td class=\"px-6 py-4\">" . htmlspecialchars($row['nip'] ?? '-') . "</td>";
            echo "<td class=\"px-6 py-4\">" . htmlspecialchars($row['shift']) . "</td>";
            echo "<td class=\"px-6 py-4\">" . htmlspecialchars($row['bagian']) . "</td>";

            $pKategori = !empty($row['kegiatan_harian_kategori']) ? htmlspecialchars($row['kegiatan_harian_kategori']) : '-';
            if (($row['kegiatan_harian_kategori'] ?? '') === 'Kegiatan Lainnya' && !empty($row['kegiatan_harian_lainnya'])) {
                $pKategori .= '<br><span class="text-gray-500 text-xs">' . htmlspecialchars($row['kegiatan_harian_lainnya']) . '</span>';
            }
            echo "<td class=\"px-6 py-4\"><span class=\"inline-block bg-blue-100 text-blue-800 text-xs font-semibold px-2 py-1 rounded\">" . $pKategori . "</span></td>";
            echo "<td class=\"px-6 py-4\"><p class=\"whitespace-pre-line text-gray-700\">" . htmlspecialchars($row['kegiatan_harian']) . "</p></td>";

            echo "<td class=\"px-6 py-4 w-48\">";
            echo "<div class=\"text-xs space-y-1\">";
            if (!empty($row['ketersediaan_air'])) echo "<div class=\"flex justify-between border-b pb-1\"><span>Status Air:</span> <b>" . htmlspecialchars($row['ketersediaan_air']) . "</b></div>";
            if (isset($row['TMA']) && $row['TMA'] !== null) echo "<div class=\"flex justify-between border-b pb-1\"><span>TMA:</span> <b>" . number_format((float)$row['TMA'], 2) . " m</b></div>";
            if (!empty($row['status_gulma'])) echo "<div class=\"flex justify-between border-b pb-1\"><span>Gulma:</span> <b>" . htmlspecialchars($row['status_gulma']) . "</b></div>";
            if (!empty($row['kondisi_saluran'])) echo "<div class=\"border-b pb-1\"><span>Saluran:</span> <b>" . htmlspecialchars($row['kondisi_saluran']) . "</b></div>";
            if (!empty($row['bangunan_air'])) echo "<div><span>Bangunan Air:</span> <b>" . htmlspecialchars($row['bangunan_air']) . "</b></div>";
            if (empty($row['ketersediaan_air']) && (!isset($row['TMA']) || $row['TMA'] === null) && empty($row['status_gulma']) && empty($row['kondisi_saluran']) && empty($row['bangunan_air'])) echo "<span class=\"text-gray-400\">-</span>";
            echo "</div>";
            echo "</td>";
            echo "<td class=\"px-6 py-4\">";
            if (!empty($row['latitude']) && !empty($row['longitude'])) {
                echo "<a href=\"https://www.google.com/maps?q=" . htmlspecialchars($row['latitude']) . "," . htmlspecialchars($row['longitude']) . "\" target=\"_blank\" class=\"text-blue-600 hover:text-blue-800\" title=\"Buka Lokasi\">";
                echo "<i class=\"fas fa-map-marker-alt\"></i>";
                echo "<span class=\"block text-xs\">" . htmlspecialchars($row['latitude']) . ",</span>";
                echo "<span class=\"block text-xs\">" . htmlspecialchars($row['longitude']) . "</span>";
                echo "</a>";
            } else {
                echo "-";
            }
            echo "</td>";

            echo "<td class=\"px-6 py-4\">";

            if (!empty($row['foto_pemantauan'])) {
                // UBAH MENJADI:
                $pathFoto = "../../uploads/laporan/" . $row['foto_pemantauan']; // Sesuaikan jumlah ../ dengan struktur folder Anda
                echo "<img src=\"" . $pathFoto . "\" class=\"w-16 h-16 rounded object-cover border cursor-pointer hover:scale-110 transition js-foto-laporan\" alt=\"Foto Pemantauan\">";
                echo "<a href=\"" . $pathFoto . "\" download=\"laporan_" . $row['id'] . ".jpg\" class=\"text-blue-600 hover:underline text-xs block mt-1\"><i class=\"fas fa-download\"></i> Download</a>";
            }

            echo "</td>";

            echo "</tr>";

        }

    } else {
        echo "<tr><td colspan=\"11\" class=\"text-center py-6 text-gray-400\">Belum ada laporan pada periode ini.</td></tr>";
    }

    exit;

}



require_once '../layout/header.php';

require_once '../layout/sidebar.php';

?>



<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">

    <h1 class="text-2xl font-bold text-gray-800">Laporan Kegiatan Lapangan</h1>

    <div class="flex items-center gap-2">

        <a href="?start=<?= $tgl_awal ?>&end=<?= $tgl_akhir ?>&search=<?= urlencode($search) ?>&excel=1" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg shadow flex items-center gap-2">

            <i class="fas fa-file-excel"></i> Export Excel

        </a>

        <a href="cetak.php?start=<?= $tgl_awal ?>&end=<?= $tgl_akhir ?>&search=<?= urlencode($search) ?>" target="_blank" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg shadow flex items-center gap-2">

            <i class="fas fa-print"></i> Cetak PDF

        </a>

    </div>

</div>



<div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 mb-6">

    <form action="" method="GET" class="flex flex-col sm:flex-row gap-4 items-end">

        <div>

            <label class="text-xs font-bold text-gray-500 uppercase">Dari Tanggal</label>

            <input type="date" name="start" value="<?= $tgl_awal ?>" class="border rounded p-2 w-full text-sm">

        </div>

        <div>

            <label class="text-xs font-bold text-gray-500 uppercase">Sampai Tanggal</label>

            <input type="date" name="end" value="<?= $tgl_akhir ?>" class="border rounded p-2 w-full text-sm">

        </div>

        <div class="flex-1">

            <label class="text-xs font-bold text-gray-500 uppercase">Cari</label>

            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Nama, bagian, kegiatan..." class="border rounded p-2 w-full text-sm">

        </div>

        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded text-sm font-bold">Filter</button>

    </form>

</div>



<div class="bg-white rounded-lg shadow overflow-hidden">

    <div class="overflow-x-auto">

        <table class="w-full text-sm text-left text-gray-500">

            <thead class="text-xs text-gray-700 uppercase bg-gray-100">

                <tr>

                    <th class="px-6 py-3">Tanggal</th>
                    <th class="px-6 py-3">Nama</th>
                    <th class="px-6 py-3">NIP</th>
                    <th class="px-6 py-3">Shift</th>
                    <th class="px-6 py-3">Bagian</th>

                    <th class="px-6 py-3">Kategori</th>
                    <th class="px-6 py-3">Uraian Kegiatan</th>

                    <th class="px-6 py-3">Data Pemantauan</th>
                    <th class="px-6 py-3">Geolokasi</th>
                    <th class="px-6 py-3">Foto</th>

                </tr>

            </thead>

            <tbody id="table-body">

                <?php if ($result->num_rows > 0): while ($row = $result->fetch_assoc()): ?>

                <tr class="bg-white border-b hover:bg-gray-50 align-top">
                    <td class="px-6 py-4"><?= date('d M Y', strtotime($row['tanggal'])) ?></td>
                    <td class="px-6 py-4"><?= htmlspecialchars($row['nama']) ?></td>
                    <td class="px-6 py-4"><?= htmlspecialchars($row['nip'] ?? '-') ?></td>
                    <td class="px-6 py-4"><?= htmlspecialchars($row['shift']) ?></td>
                    <td class="px-6 py-4"><?= htmlspecialchars($row['bagian']) ?></td>

                    <td class="px-6 py-4">
                        <span class="inline-block bg-blue-100 text-blue-800 text-xs font-semibold px-2 py-1 rounded">
                            <?= htmlspecialchars($row['kegiatan_harian_kategori'] ?? '-') ?>
                        </span>
                        <?php if (($row['kegiatan_harian_kategori'] ?? '') === 'Kegiatan Lainnya' && !empty($row['kegiatan_harian_lainnya'])): ?>
                        <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($row['kegiatan_harian_lainnya']) ?></div>
                        <?php endif; ?>
                    </td>

                    <td class="px-6 py-4">

                        <p class="whitespace-pre-line text-gray-700"><?= htmlspecialchars($row['kegiatan_harian']) ?></p>

                    </td>

                    <td class="px-6 py-4 w-48">
                        <div class="text-xs space-y-1">
                            <?php if (!empty($row['ketersediaan_air'])): ?>
                            <div class="flex justify-between border-b pb-1"><span>Status Air:</span> <b><?= htmlspecialchars($row['ketersediaan_air']) ?></b></div>
                            <?php endif; ?>
                            <?php if (isset($row['TMA']) && $row['TMA'] !== null): ?>
                            <div class="flex justify-between border-b pb-1"><span>TMA:</span> <b><?= number_format((float)$row['TMA'], 2) ?> m</b></div>
                            <?php endif; ?>
                            <?php if (!empty($row['status_gulma'])): ?>
                            <div class="flex justify-between border-b pb-1"><span>Gulma:</span> <b><?= htmlspecialchars($row['status_gulma']) ?></b></div>
                            <?php endif; ?>
                            <?php if (!empty($row['kondisi_saluran'])): ?>
                            <div class="border-b pb-1"><span class="text-gray-500">Saluran:</span><br><b><?= htmlspecialchars($row['kondisi_saluran']) ?></b></div>
                            <?php endif; ?>
                            <?php if (!empty($row['bangunan_air'])): ?>
                            <div><span class="text-gray-500">Bangunan Air:</span><br><b><?= htmlspecialchars($row['bangunan_air']) ?></b></div>
                            <?php endif; ?>
                            <?php if (empty($row['ketersediaan_air']) && (!isset($row['TMA']) || $row['TMA'] === null) && empty($row['status_gulma']) && empty($row['kondisi_saluran']) && empty($row['bangunan_air'])): ?>
                            <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        <?php if (!empty($row['latitude']) && !empty($row['longitude'])): ?>
                            <a href="https://www.google.com/maps?q=<?= htmlspecialchars($row['latitude']) ?>,<?= htmlspecialchars($row['longitude']) ?>" target="_blank" class="text-blue-600 hover:text-blue-800" title="Buka Lokasi">
                                <i class="fas fa-map-marker-alt"></i>
                                <span class="block text-xs"><?= htmlspecialchars($row['latitude']) ?>,</span>
                                <span class="block text-xs"><?= htmlspecialchars($row['longitude']) ?></span>
                            </a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>

                    <td class="px-6 py-4">
                        <?php if(!empty($row['foto_pemantauan'])): 
                           
                            $urlFoto = "../../uploads/laporan/" . $row['foto_pemantauan']; 
                        ?>
                            <img src="<?= $urlFoto ?>" 
                                class="w-16 h-16 rounded object-cover border cursor-pointer hover:scale-110 transition js-foto-laporan" 
                                alt="Foto Pemantauan"
                                onerror="this.src='../../assets/img/no-image.png';"> <a href="<?= $urlFoto ?>" download="laporan_<?= $row['id'] ?>.jpg" class="text-blue-600 hover:underline text-xs block mt-1">
                                <i class="fas fa-download"></i> Download
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>

                <?php endwhile; else: ?>

                <tr><td colspan="11" class="text-center py-6 text-gray-400">Belum ada laporan pada periode ini.</td></tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

    <?php
    require_once '../layout/pagination.php';
    $paginationParams = array_filter([
        'start' => $tgl_awal,
        'end' => $tgl_akhir,
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



        async function refreshTable() {

            try {

                var url = new URL(window.location.href);

                url.searchParams.set('partial', '1');



                var res = await fetch(url.toString(), { cache: 'no-store' });

                if (!res.ok) return;



                var html = await res.text();

                if (html !== lastHtml) {

                    tbody.innerHTML = html;

                    lastHtml = html;

                }

            } catch (e) {

                return;

            }

        }



        function start() {

            if (timer) return;

            timer = setInterval(refreshTable, 4000);

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



<!-- Image Zoom Modal for Foto Pemantauan -->
<div id="fotoModal" class="fixed inset-0 z-[2000] hidden items-center justify-center bg-black/70 p-4">
    <div class="relative max-w-3xl w-full">
        <button type="button" id="fotoModalClose" class="absolute -top-10 right-0 text-white text-2xl leading-none">&times;</button>
        <img id="fotoModalImg" src="" alt="Preview" class="w-full max-h-[85vh] object-contain rounded bg-white" />
    </div>
</div>

<script>
(function () {
    // Foto pemantauan image zoom
    var modal = document.getElementById('fotoModal');
    var modalImg = document.getElementById('fotoModalImg');
    var closeBtn = document.getElementById('fotoModalClose');

    document.addEventListener('click', function(e) {
        if (e.target && e.target.classList.contains('js-foto-laporan')) {
            e.preventDefault();
            modalImg.src = e.target.src;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }
    });

    if (closeBtn) closeBtn.addEventListener('click', function() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        modalImg.src = '';
    });

    if (modal) modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            modalImg.src = '';
        }
    });
})();
</script>

<?php require_once '../layout/footer.php'; ?>