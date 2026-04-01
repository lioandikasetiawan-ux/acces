<?php
require_once '../../config/database.php';
require_once '../../config/session.php';

ini_set('display_errors', 0);

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../auth/login-v2.php");
    exit;
}

require_once '../../config/schema-bootstrap.php';

$isPartialRequest = (isset($_GET['partial']) && $_GET['partial'] === '1');

if ($isPartialRequest) {
    $schemaCache = getSchemaCacheReadOnly();
} else {
    $schemaCache = getSchemaCache($conn);
}

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

$bulan_filter = isset($_GET['bulan']) ? $_GET['bulan'] : date('Y-m');
$shift_filter = isset($_GET['shift']) ? $_GET['shift'] : 'semua';
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$exportExcel = isset($_GET['excel']) && $_GET['excel'] === '1';

$tgl_awal = $bulan_filter . '-01';
$tgl_akhir = date('Y-m-t', strtotime($tgl_awal));

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

if (isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] != '') {
    $sql .= " AND p.bagian_id = " . (int)$_SESSION['bagian_id'];
}

if ($shift_filter != 'semua') {
    if ($shiftTableExists) {
        $shift_filter_escaped = $conn->real_escape_string($shift_filter);
        $sql .= " AND s.nama_shift = '$shift_filter_escaped'";
    } else {
        $sql .= " AND a.shift = '$shift_filter'";
    }
}

if ($search) {
    $sql .= " AND (p.nama LIKE '%$search%' OR p.nip LIKE '%$search%' OR a.status LIKE '%$search%')";
}

$perPage = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
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

// --- LOGIKA PARTIAL AJAX (REALTIME UPDATE) ---
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
            
            // FOTO MASUK (PARTIAL)
            echo "<td class=\"px-4 py-3\">";
            if (!empty($row['foto_absen'])) {
                $pathFotoMasuk = "../../uploads/absensi/" . $row['foto_absen']; 
                echo "<img src=\"" . $pathFotoMasuk . "\" class=\"w-10 h-10 rounded object-cover border border-gray-200 hover:scale-150 transition js-zoom-img cursor-pointer\" data-img=\"" . htmlspecialchars($pathFotoMasuk) . "\" onerror=\"this.onerror=null;this.src='../../assets/img/no-image.png';\">";
            } else {
                echo "<span class=\"text-xs text-gray-400\">-</span>";
            }
            echo "</td>";

            // FOTO KELUAR (PARTIAL)
            echo "<td class=\"px-4 py-3\">";
            if (!empty($row['foto_keluar'])) {
                $pathFotoKeluar = "../../uploads/absensi/" . $row['foto_keluar']; 
                echo "<img src=\"" . $pathFotoKeluar . "\" class=\"w-10 h-10 rounded object-cover border border-gray-200 hover:scale-150 transition js-zoom-img cursor-pointer\" data-img=\"" . htmlspecialchars($pathFotoKeluar) . "\" onerror=\"this.onerror=null;this.src='../../assets/img/no-image.png';\">";
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

            // Status Logic
            $statusClass = 'bg-gray-100 text-gray-800';
            switch ($row['status']) {
                case 'hadir': $statusClass = 'bg-green-100 text-green-800'; break;
                case 'absen masuk': $statusClass = 'bg-blue-100 text-blue-800'; break;
                case 'izin': $statusClass = 'bg-yellow-100 text-yellow-800'; break;
                case 'sakit': $statusClass = 'bg-purple-100 text-purple-800'; break;
                case 'lupa absen': $statusClass = 'bg-orange-100 text-orange-800'; break;
                case 'tidak hadir': $statusClass = 'bg-red-100 text-red-800'; break;
            }

            echo "<td class=\"px-4 py-3\">";
            echo "<select onchange=\"ubahStatus(" . $row['id'] . ", this.value, this)\" class=\"text-xs font-bold uppercase px-2 py-1 rounded border-0 " . $statusClass . " cursor-pointer\" data-original-status=\"" . htmlspecialchars($row['status']) . "\">";
            $statusOptions = ['--', 'absen masuk', 'hadir', 'izin', 'sakit', 'tidak hadir', 'lupa absen'];
            foreach ($statusOptions as $opt) {
                $selected = ($opt === $row['status']) ? 'selected' : '';
                echo "<option value=\"" . htmlspecialchars($opt) . "\" $selected>" . htmlspecialchars($opt) . "</option>";
            }
            echo "</select></td>";
            echo "<td class=\"px-4 py-3\"><a href=\"petugas.php?id=" . $row['petugas_id'] . "&bulan=" . $bulan_filter . "\" class=\"bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 rounded text-xs font-bold\"><i class=\"fas fa-eye mr-1\"></i> Detail</a></td>";
            echo "</tr>";
        }
    }
    exit;
}

require_once '../layout/header.php';
require_once '../layout/sidebar.php';
?>

<div class="flex flex-col sm:flex-row justify-between items-center mb-6 gap-4">
    <h1 class="text-2xl font-bold text-gray-800">Rekap Data Absensi</h1>
    <div class="flex items-center gap-2">
        <a href="?bulan=<?= $bulan_filter ?>&shift=<?= $shift_filter ?>&search=<?= urlencode($search) ?>&excel=1" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg shadow flex items-center gap-2">
            <i class="fas fa-file-excel"></i> Export Excel
        </a>
    </div>
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
                            <td class="px-4 py-3 whitespace-nowrap"><?= date('d M Y', strtotime($row['tanggal'])) ?></td>
                            <td class="px-4 py-3">
                                <div class="font-bold text-gray-800"><?= $row['nama'] ?></div>
                                <div class="text-xs text-gray-500"><?= $row['nip'] ?></div>
                            </td>
                            <td class="px-4 py-3"><span class="uppercase text-xs font-bold text-gray-600"><?= $row['shift'] ?></span></td>
                            <td class="px-4 py-3 text-green-700 font-bold"><?= $row['jam_masuk'] ? date('H:i', strtotime($row['jam_masuk'])) : '-' ?></td>
                            <td class="px-4 py-3 text-red-700 font-bold"><?= $row['jam_keluar'] ? date('H:i', strtotime($row['jam_keluar'])) : '-' ?></td>
                            
                            <td class="px-4 py-3">
                                <?php if($row['foto_absen']): 
                                    $pathFotoMasuk = "../../uploads/absensi/" . $row['foto_absen']; ?>
                                    <img src="<?= $pathFotoMasuk ?>" class="w-10 h-10 rounded object-cover border border-gray-200 cursor-pointer hover:scale-150 transition js-zoom-img" data-img="<?= htmlspecialchars($pathFotoMasuk) ?>" onerror="this.onerror=null;this.src='../../assets/img/no-image.png';">
                                <?php else: ?>
                                    <span class="text-xs text-gray-400">-</span>
                                <?php endif; ?>
                            </td>

                            <td class="px-4 py-3">
                                <?php if(!empty($row['foto_keluar'])): 
                                    $pathFotoKeluar = "../../uploads/absensi/" . $row['foto_keluar']; ?>
                                    <img src="<?= $pathFotoKeluar ?>" class="w-10 h-10 rounded object-cover border border-gray-200 cursor-pointer hover:scale-150 transition js-zoom-img" data-img="<?= htmlspecialchars($pathFotoKeluar) ?>" onerror="this.onerror=null;this.src='../../assets/img/no-image.png';">
                                <?php else: ?>
                                    <span class="text-xs text-gray-400">-</span>
                                <?php endif; ?>
                            </td>

                            <td class="px-4 py-3">
                                <?php if($row['latitude']): ?>
                                    <a href="https://www.google.com/maps?q=<?= $row['latitude'] ?>,<?= $row['longitude'] ?>" target="_blank" class="text-blue-600 hover:underline text-xs"><i class="fas fa-map-marked-alt mr-1"></i> Cek Maps</a>
                                <?php else: ?>
                                    <span class="text-xs text-gray-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php if($hasLokasiKeluar && !empty($row['latitude_keluar'])): ?>
                                    <a href="https://www.google.com/maps?q=<?= $row['latitude_keluar'] ?>,<?= $row['longitude_keluar'] ?>" target="_blank" class="text-blue-600 hover:underline text-xs"><i class="fas fa-map-marked-alt mr-1"></i> Cek Maps</a>
                                <?php else: ?>
                                    <span class="text-xs text-gray-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php 
                                $statusClass = 'bg-gray-100 text-gray-800';
                                switch ($row['status']) {
                                    case 'hadir': $statusClass = 'bg-green-100 text-green-800'; break;
                                    case 'absen masuk': $statusClass = 'bg-blue-100 text-blue-800'; break;
                                    case 'izin': $statusClass = 'bg-yellow-100 text-yellow-800'; break;
                                    case 'sakit': $statusClass = 'bg-purple-100 text-purple-800'; break;
                                    case 'lupa absen': $statusClass = 'bg-orange-100 text-orange-800'; break;
                                    case 'tidak hadir': $statusClass = 'bg-red-100 text-red-800'; break;
                                }
                                ?>
                                <select onchange="ubahStatus(<?= $row['id'] ?>, this.value, this)" class="text-xs font-bold uppercase px-2 py-1 rounded border-0 <?= $statusClass ?> cursor-pointer" data-original-status="<?= htmlspecialchars($row['status']) ?>">
                                    <?php $statusOptions = ['--', 'absen masuk', 'hadir', 'izin', 'sakit', 'tidak hadir', 'lupa absen'];
                                    foreach ($statusOptions as $opt): ?>
                                        <option value="<?= htmlspecialchars($opt) ?>" <?= $opt === $row['status'] ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="px-4 py-3">
                                <a href="petugas.php?id=<?= $row['petugas_id'] ?>&bulan=<?= $bulan_filter ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 rounded text-xs font-bold"><i class="fas fa-eye mr-1"></i> Detail</a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="11" class="px-6 py-8 text-center text-gray-500">Tidak ada data.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../layout/footer.php'; ?>