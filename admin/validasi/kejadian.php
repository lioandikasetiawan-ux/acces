<?php

require_once '../../config/database.php';
require_once '../../config/session.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    exit;
}

$exportExcel = isset($_GET['excel']) && $_GET['excel'] === '1';

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$bulan_filter = isset($_GET['bulan']) ? $_GET['bulan'] : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $bulan_filter)) {
    $bulan_filter = date('Y-m');
}

$tgl_awal = $bulan_filter . '-01';
$tgl_akhir = date('Y-m-t', strtotime($tgl_awal));

/**
 * PERFORMA REALTIME: Schema checks di-cache ke session.
 * Partial AJAX: getSchemaCacheReadOnly() — TIDAK PERNAH SHOW query.
 * Full page: getSchemaCache() — cache TTL 10 menit.
 */
require_once '../../config/schema-bootstrap.php';

$isPartialRequest = (isset($_GET['partial']) && $_GET['partial'] == '1');

if ($isPartialRequest) {
    $schemaCache = getSchemaCacheReadOnly();
} else {
    $schemaCache = getSchemaCache($conn);
}

$bagianTableExists = $schemaCache['bagianTableExists'];
$petugasBagianColumnExists = $schemaCache['petugasBagianColumnExists'];
$petugasBagianIdColumnExists = $schemaCache['petugasBagianIdColumnExists'];

$joinBagian = ($bagianTableExists && $petugasBagianIdColumnExists) ? " LEFT JOIN bagian b ON p.bagian_id = b.id " : "";
$selectBagian = $petugasBagianColumnExists
    ? "p.bagian AS bagian"
    : (($bagianTableExists && $petugasBagianIdColumnExists) ? "COALESCE(b.nama_bagian, '-') AS bagian" : "'-' AS bagian");

if ($exportExcel) {
    $tgl_akhir_next_e = date('Y-m-d', strtotime($tgl_akhir . ' +1 day'));
    $query = "SELECT k.*, p.nama, p.nip, $selectBagian, p.alamat, k.latitude, k.longitude
              FROM kejadian k 
              JOIN petugas p ON k.petugas_id = p.id 
              $joinBagian
              WHERE k.status = 'disetujui' AND k.created_at >= '$tgl_awal 00:00:00' AND k.created_at < '$tgl_akhir_next_e 00:00:00'";

    if ($petugasBagianIdColumnExists && isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] !== '' && $_SESSION['bagian_id'] !== null) {
        $query .= " AND p.bagian_id = " . (int) $_SESSION['bagian_id'];
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
        $searchConds[] = "k.deskripsi LIKE '%$search%'";
        $query .= " AND (" . implode(' OR ', $searchConds) . ")";
    }
    $query .= " ORDER BY FIELD(k.status, 'pending') DESC, k.created_at DESC";
    $result = $conn->query($query);

    $filename = 'kejadian_' . $bulan_filter . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Pragma: no-cache');
    header('Expires: 0');

    $data=[]; $hasJL=false;
    if ($result && $result->num_rows>0) {
        while ($r=$result->fetch_assoc()) { $data[]=$r; if (!empty($r['jenis_laporan'])) $hasJL=true; }
    }
    echo "\xEF\xBB\xBF";
    echo '<table border="1"><thead><tr>';
    echo '<th>No</th><th>Waktu</th><th>Nama</th><th>NIP</th><th>Bagian</th>';
    if ($hasJL) echo '<th>Jenis Laporan</th>';
    echo '<th>Deskripsi</th><th>Geotagging</th>';
    echo '</tr></thead><tbody>';

    $no = 1;
    if (!empty($data)) {
        foreach ($data as $row) {
            echo '<tr>';
            echo '<td>' . $no++ . '</td>';
            echo '<td>' . (!empty($row['created_at']) ? date('d/m/Y H:i', strtotime($row['created_at'])) : '-') . '</td>';
            echo '<td>' . htmlspecialchars($row['nama']) . '</td>';
            echo '<td style="mso-number-format:\'\\@\'">' . htmlspecialchars($row['nip']) . '</td>';
            echo '<td>' . htmlspecialchars($row['bagian']) . '</td>';
            if ($hasJL) {
                $jl = !empty($row['jenis_laporan']) ? htmlspecialchars($row['jenis_laporan']) : '-';
                if (($row['jenis_laporan'] ?? '')==='Laporan Lainnya' && !empty($row['laporan_lainnya_text'])) $jl .= ' ('.htmlspecialchars($row['laporan_lainnya_text']).')';
                if (($row['jenis_laporan'] ?? '')==='Laporan Kerusakan Bangunan' && !empty($row['jenis_kerusakan_bangunan'])) $jl .= ' - '.htmlspecialchars($row['jenis_kerusakan_bangunan']);
                echo '<td>' . $jl . '</td>';
            }
            echo '<td>' . htmlspecialchars($row['deskripsi']) . '</td>';
            $geo = (!empty($row['latitude']) && !empty($row['longitude'])) ? htmlspecialchars($row['latitude']).', '.htmlspecialchars($row['longitude']) : '-';
            echo '<td>' . $geo . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="20">Tidak ada data kejadian.</td></tr>';
    }

    echo '</tbody></table>';
    exit;
}

if (isset($_GET['partial']) && $_GET['partial'] == '1') {
    // Optimized: range comparison (bukan DATE()) agar index bisa digunakan
    $tgl_akhir_next_p = date('Y-m-d', strtotime($tgl_akhir . ' +1 day'));
    $query = "SELECT k.id, k.petugas_id, k.deskripsi, k.foto, k.status, k.latitude, k.longitude, k.created_at,
              k.jenis_laporan, k.laporan_lainnya_text, k.jenis_kerusakan_bangunan,
              p.nama, p.nip, $selectBagian, p.alamat
              FROM kejadian k 
              JOIN petugas p ON k.petugas_id = p.id 
              $joinBagian
              WHERE k.created_at >= '$tgl_awal 00:00:00' AND k.created_at < '$tgl_akhir_next_p 00:00:00'";

    if ($petugasBagianIdColumnExists && isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] !== '' && $_SESSION['bagian_id'] !== null) {
        $query .= " AND p.bagian_id = " . (int) $_SESSION['bagian_id'];
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
        $searchConds[] = "k.deskripsi LIKE '%$search%'";
        $searchConds[] = "k.status LIKE '%$search%'";
        $query .= " AND (" . implode(' OR ', $searchConds) . ")";
    }
    $query .= " ORDER BY FIELD(k.status, 'pending') DESC, k.created_at DESC";

    // Pagination for partial
    $perPage = 20;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $countSql = preg_replace('/SELECT.*?FROM /s', 'SELECT COUNT(*) as total FROM ', $query, 1);
    $countRes = $conn->query($countSql);
    $totalRows = ($countRes && $rc = $countRes->fetch_assoc()) ? (int)$rc['total'] : 0;
    $totalPages = max(1, ceil($totalRows / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;
    $query .= " LIMIT $perPage OFFSET $offset";

    $result = $conn->query($query);

    if ($result && $result->num_rows > 0):
        while ($row = $result->fetch_assoc()):
?>
<tr class="bg-white border-b hover:bg-gray-50">
    <td class="px-6 py-4">
        <div class="text-xs text-gray-500 mb-1"><?= date('d M Y H:i', strtotime($row['created_at'])) ?></div>
        <div class="font-bold text-gray-800"><?= $row['nama'] ?></div>
        <div class="text-xs text-gray-500"><?= $row['nip'] ?></div>
    </td>
    <td class="px-6 py-4">
        <span class="text-xs bg-gray-200 px-2 rounded"><?= $row['bagian'] ?></span>
    </td>
    <td class="px-6 py-4">
        <?php
        $jl = $row['jenis_laporan'] ?? '';
        if ($jl !== ''):
        ?>
        <span class="bg-orange-100 text-orange-800 text-xs font-semibold px-2 py-1 rounded"><?= htmlspecialchars($jl) ?></span>
        <?php if ($jl==='Laporan Lainnya' && !empty($row['laporan_lainnya_text'])): ?>
        <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($row['laporan_lainnya_text']) ?></div>
        <?php endif; ?>
        <?php if ($jl==='Laporan Kerusakan Bangunan' && !empty($row['jenis_kerusakan_bangunan'])): ?>
        <div class="text-xs text-gray-500 mt-1">Jenis: <?= htmlspecialchars($row['jenis_kerusakan_bangunan']) ?></div>
        <?php endif; ?>
        <?php else: ?>
        <span class="text-gray-400 text-xs">-</span>
        <?php endif; ?>
    </td>
    <td class="px-6 py-4">
        <p class="w-64 truncate" title="<?= $row['deskripsi'] ?>"><?= $row['deskripsi'] ?></p>
    </td>
    <td class="px-6 py-4">
        <?php if(!empty($row['foto'])): ?>
            <img src="<?= $row['foto'] ?>" class="w-12 h-12 rounded object-cover border cursor-pointer hover:scale-110 transition mb-2 js-foto-kejadian" alt="Bukti Foto">
            <a href="<?= $row['foto'] ?>" download="kejadian_<?= $row['id'] ?>.jpg" class="text-blue-600 hover:underline text-xs block">
                <i class="fas fa-download"></i> Download
            </a>
        <?php endif; ?>
    </td>
    <td class="px-6 py-4 text-center">
        <?php if(!empty($row['latitude']) && !empty($row['longitude'])): ?>
            <a href="https://www.google.com/maps?q=<?= $row['latitude'] ?>,<?= $row['longitude'] ?>" target="_blank" class="text-blue-600 hover:underline inline-block">
                <i class="fas fa-map-marker-alt"></i> Lihat Peta
            </a>
            <div class="text-xs text-gray-500 mt-1">
                <span class="block"><?= htmlspecialchars($row['latitude']) ?>,</span>
                <span class="block"><?= htmlspecialchars($row['longitude']) ?></span>
            </div>
        <?php else: ?>
            <span class="text-gray-400 text-xs">-</span>
        <?php endif; ?>
    </td>
    <td class="px-6 py-4">
        <?php
        switch ($row['status']) {
            case 'pending':
                $statusColor = 'bg-yellow-100 text-yellow-800';
                break;
            case 'disetujui':
                $statusColor = 'bg-green-100 text-green-800';
                break;
            case 'ditolak':
                $statusColor = 'bg-red-100 text-red-800';
                break;
            default:
                $statusColor = 'bg-gray-100 text-gray-800';
        }
        ?>
        <span class="<?= $statusColor ?> px-2 py-1 rounded text-xs font-bold uppercase"><?= $row['status'] ?></span>
    </td>
    <td class="px-6 py-4 text-center">
        <?php if ($row['status'] == 'pending'): ?>
            <div class="flex justify-center gap-2">
                <a href="proses-kejadian.php?id=<?= $row['id'] ?>&aksi=approve" onclick="return confirm('Setujui laporan ini?')" class="bg-green-500 hover:bg-green-600 text-white p-2 rounded shadow" title="Setujui">
                    <i class="fas fa-check"></i>
                </a>
                <a href="proses-kejadian.php?id=<?= $row['id'] ?>&aksi=reject" onclick="return confirm('Tolak laporan ini?')" class="bg-red-500 hover:bg-red-600 text-white p-2 rounded shadow" title="Tolak">
                    <i class="fas fa-times"></i>
                </a>
            </div>
        <?php else: ?>
            <a href="hapus-kejadian.php?id=<?= $row['id'] ?>" onclick="return confirm('Yakin hapus laporan ini?')" class="bg-gray-500 hover:bg-gray-600 text-white p-2 rounded shadow" title="Hapus">
                <i class="fas fa-trash"></i>
            </a>
        <?php endif; ?>
    </td>
</tr>
<?php endwhile; else: ?>
<tr><td colspan="8" class="text-center py-4">Tidak ada data kejadian.</td></tr>
<?php endif; ?>
<?php
    exit;
}

require_once '../layout/header.php';
require_once '../layout/sidebar.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-800">Validasi Laporan Kejadian</h1>
    <p class="text-sm text-gray-600">Verifikasi laporan insiden dari petugas lapangan.</p>
</div>

<div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 mb-6">
    <form action="" method="GET" class="flex flex-col sm:flex-row gap-4 items-end">
        <div>
            <label class="text-xs font-bold text-gray-500 uppercase">Bulan</label>
            <input type="month" name="bulan" value="<?= htmlspecialchars($bulan_filter) ?>" class="border rounded p-2 w-full text-sm">
        </div>
        <div class="flex-1">
            <label class="text-xs font-bold text-gray-500 uppercase">Cari Kejadian</label>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Nama, bagian, deskripsi, status..." class="border rounded p-2 w-full text-sm">
        </div>
        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded text-sm font-bold">Filter</button>
        <a href="?bulan=<?= htmlspecialchars($bulan_filter) ?>&search=<?= urlencode($search) ?>&excel=1" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded text-sm font-bold">
            <i class="fas fa-file-excel mr-1"></i> Export Excel
        </a>
        <a href="cetak-kejadian.php?bulan=<?= htmlspecialchars($bulan_filter) ?>&search=<?= urlencode($search) ?>" target="_blank" class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded text-sm font-bold">
            <i class="fas fa-print mr-1"></i> Cetak PDF
        </a>
    </form>
</div>

<div class="bg-white rounded-lg shadow overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left text-gray-500">
            <thead class="text-xs text-gray-700 uppercase bg-gray-100">
                <tr>
                    <th class="px-6 py-3">Waktu & Pelapor</th>
                    <th class="px-6 py-3">Lokasi</th>
                    <th class="px-6 py-3">Jenis Laporan</th>
                    <th class="px-6 py-3">Deskripsi Kejadian</th>
                    <th class="px-6 py-3">Bukti Foto</th>
                    <th class="px-6 py-3 text-center">Geotagging</th>
                    <th class="px-6 py-3">Status</th>
                    <th class="px-6 py-3 text-center">Aksi</th>
                </tr>
            </thead>
            <tbody id="table-body">
<?php
// --- SERVER-SIDE INITIAL DATA RENDERING ---
// Data langsung di-render di server agar halaman tidak perlu 2x round-trip
// Menggunakan range comparison (bukan DATE()) agar index bisa digunakan
$tgl_akhir_next = date('Y-m-d', strtotime($tgl_akhir . ' +1 day'));

$initQuery = "SELECT k.id, k.petugas_id, k.deskripsi, k.foto, k.status, k.latitude, k.longitude, k.created_at,
              k.jenis_laporan, k.laporan_lainnya_text, k.jenis_kerusakan_bangunan,
              p.nama, p.nip, $selectBagian, p.alamat
              FROM kejadian k 
              JOIN petugas p ON k.petugas_id = p.id 
              $joinBagian
              WHERE k.created_at >= '$tgl_awal 00:00:00' AND k.created_at < '$tgl_akhir_next 00:00:00'";

if ($petugasBagianIdColumnExists && isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] !== '' && $_SESSION['bagian_id'] !== null) {
    $initQuery .= " AND p.bagian_id = " . (int) $_SESSION['bagian_id'];
}
if ($search) {
    $searchConds = [];
    $searchConds[] = "p.nama LIKE '%$search%'";
    if ($petugasBagianColumnExists) $searchConds[] = "p.bagian LIKE '%$search%'";
    if ($bagianTableExists && $petugasBagianIdColumnExists) $searchConds[] = "b.nama_bagian LIKE '%$search%'";
    $searchConds[] = "k.deskripsi LIKE '%$search%'";
    $searchConds[] = "k.status LIKE '%$search%'";
    $initQuery .= " AND (" . implode(' OR ', $searchConds) . ")";
}

// Count untuk pagination
$countSql = preg_replace('/SELECT.*?FROM /s', 'SELECT COUNT(*) as total FROM ', $initQuery, 1);
$countRes = $conn->query($countSql);
$mainTotalRows = ($countRes && $mc = $countRes->fetch_assoc()) ? (int)$mc['total'] : 0;
$mainPerPage = 20;
$mainPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$mainTotalPages = max(1, ceil($mainTotalRows / $mainPerPage));
if ($mainPage > $mainTotalPages) $mainPage = $mainTotalPages;
$offset = ($mainPage - 1) * $mainPerPage;

$initQuery .= " ORDER BY FIELD(k.status, 'pending') DESC, k.created_at DESC";
$initQuery .= " LIMIT $mainPerPage OFFSET $offset";

$result = $conn->query($initQuery);

if ($result && $result->num_rows > 0):
    while ($row = $result->fetch_assoc()):
?>
<tr class="bg-white border-b hover:bg-gray-50">
    <td class="px-6 py-4">
        <div class="text-xs text-gray-500 mb-1"><?= date('d M Y H:i', strtotime($row['created_at'])) ?></div>
        <div class="font-bold text-gray-800"><?= $row['nama'] ?></div>
        <div class="text-xs text-gray-500"><?= $row['nip'] ?></div>
    </td>
    <td class="px-6 py-4">
        <span class="text-xs bg-gray-200 px-2 rounded"><?= $row['bagian'] ?></span>
    </td>
    <td class="px-6 py-4">
        <?php
        $jl = $row['jenis_laporan'] ?? '';
        if ($jl !== ''):
        ?>
        <span class="bg-orange-100 text-orange-800 text-xs font-semibold px-2 py-1 rounded"><?= htmlspecialchars($jl) ?></span>
        <?php if ($jl==='Laporan Lainnya' && !empty($row['laporan_lainnya_text'])): ?>
        <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($row['laporan_lainnya_text']) ?></div>
        <?php endif; ?>
        <?php if ($jl==='Laporan Kerusakan Bangunan' && !empty($row['jenis_kerusakan_bangunan'])): ?>
        <div class="text-xs text-gray-500 mt-1">Jenis: <?= htmlspecialchars($row['jenis_kerusakan_bangunan']) ?></div>
        <?php endif; ?>
        <?php else: ?>
        <span class="text-gray-400 text-xs">-</span>
        <?php endif; ?>
    </td>
    <td class="px-6 py-4">
        <p class="w-64 truncate" title="<?= $row['deskripsi'] ?>"><?= $row['deskripsi'] ?></p>
    </td>
    <td class="px-6 py-4">
        <?php if(!empty($row['foto'])): ?>
            <img src="<?= $row['foto'] ?>" class="w-12 h-12 rounded object-cover border cursor-pointer hover:scale-110 transition mb-2 js-foto-kejadian" alt="Bukti Foto">
            <a href="<?= $row['foto'] ?>" download="kejadian_<?= $row['id'] ?>.jpg" class="text-blue-600 hover:underline text-xs block">
                <i class="fas fa-download"></i> Download
            </a>
        <?php endif; ?>
    </td>
    <td class="px-6 py-4 text-center">
        <?php if(!empty($row['latitude']) && !empty($row['longitude'])): ?>
            <a href="https://www.google.com/maps?q=<?= $row['latitude'] ?>,<?= $row['longitude'] ?>" target="_blank" class="text-blue-600 hover:underline inline-block">
                <i class="fas fa-map-marker-alt"></i> Lihat Peta
            </a>
            <div class="text-xs text-gray-500 mt-1">
                <span class="block"><?= htmlspecialchars($row['latitude']) ?>,</span>
                <span class="block"><?= htmlspecialchars($row['longitude']) ?></span>
            </div>
        <?php else: ?>
            <span class="text-gray-400 text-xs">-</span>
        <?php endif; ?>
    </td>
    <td class="px-6 py-4">
        <?php
        switch ($row['status']) {
            case 'pending':
                $statusColor = 'bg-yellow-100 text-yellow-800';
                break;
            case 'disetujui':
                $statusColor = 'bg-green-100 text-green-800';
                break;
            case 'ditolak':
                $statusColor = 'bg-red-100 text-red-800';
                break;
            default:
                $statusColor = 'bg-gray-100 text-gray-800';
        }
        ?>
        <span class="<?= $statusColor ?> px-2 py-1 rounded text-xs font-bold uppercase"><?= $row['status'] ?></span>
    </td>
    <td class="px-6 py-4 text-center">
        <?php if ($row['status'] == 'pending'): ?>
            <div class="flex justify-center gap-2">
                <a href="proses-kejadian.php?id=<?= $row['id'] ?>&aksi=approve" onclick="return confirm('Setujui laporan ini?')" class="bg-green-500 hover:bg-green-600 text-white p-2 rounded shadow" title="Setujui">
                    <i class="fas fa-check"></i>
                </a>
                <a href="proses-kejadian.php?id=<?= $row['id'] ?>&aksi=reject" onclick="return confirm('Tolak laporan ini?')" class="bg-red-500 hover:bg-red-600 text-white p-2 rounded shadow" title="Tolak">
                    <i class="fas fa-times"></i>
                </a>
            </div>
        <?php else: ?>
            <a href="hapus-kejadian.php?id=<?= $row['id'] ?>" onclick="return confirm('Yakin hapus laporan ini?')" class="bg-gray-500 hover:bg-gray-600 text-white p-2 rounded shadow" title="Hapus">
                <i class="fas fa-trash"></i>
            </a>
        <?php endif; ?>
    </td>
</tr>
<?php endwhile; else: ?>
<tr><td colspan="8" class="text-center py-4">Tidak ada data kejadian.</td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="pagination-container"></div>

<?php
require_once '../layout/pagination.php';
$paginationParams = array_filter(['bulan' => $bulan_filter, 'search' => $search]);
$paginationUrl = '?' . http_build_query($paginationParams);
renderPagination($mainPage, $mainTotalPages, $paginationUrl);
?>
</div>

<script>
    (function () {
        const tbody = document.getElementById('table-body');
        if (!tbody) return;

        let lastHtml = tbody.innerHTML;
        let intervalId = null;

        function isUserInteracting() {
            var active = document.activeElement;
            if (!active) return false;
            if (active.tagName === 'SELECT' || active.tagName === 'INPUT') {
                return tbody.contains(active);
            }
            return false;
        }

        async function fetchPartialAndUpdate() {
            if (isUserInteracting()) return;

            try {
                const url = new URL(window.location.href);
                url.searchParams.set('partial', '1');

                const res = await fetch(url.toString(), {
                    headers: { 'X-Requested-With': 'fetch' },
                    cache: 'no-store'
                });
                if (!res.ok) return;

                const html = await res.text();
                if (html !== lastHtml) {
                    // Smart row-level diffing
                    var temp = document.createElement('tbody');
                    temp.innerHTML = html;
                    var newRows = temp.querySelectorAll('tr');
                    var oldRows = tbody.querySelectorAll('tr');

                    if (newRows.length !== oldRows.length) {
                        tbody.innerHTML = html;
                    } else {
                        for (var i = 0; i < newRows.length; i++) {
                            if (oldRows[i].innerHTML !== newRows[i].innerHTML) {
                                oldRows[i].innerHTML = newRows[i].innerHTML;
                            }
                        }
                    }
                    lastHtml = html;
                }
            } catch (e) {
            }
        }

        function start() {
            if (intervalId) return;
            intervalId = setInterval(fetchPartialAndUpdate, 20000); // 20 detik
        }

        function stop() {
            if (!intervalId) return;
            clearInterval(intervalId);
            intervalId = null;
        }

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                fetchPartialAndUpdate();
                start();
            } else {
                stop();
            }
        });

        // Initial load: fetch data segera
        fetchPartialAndUpdate();
        start();
    })();
</script>

<!-- Image Zoom Modal for Bukti Foto -->
<div id="fotoModal" class="fixed inset-0 z-[2000] hidden items-center justify-center bg-black/70 p-4">
    <div class="relative max-w-3xl w-full">
        <button type="button" id="fotoModalClose" class="absolute -top-10 right-0 text-white text-2xl leading-none">&times;</button>
        <img id="fotoModalImg" src="" alt="Preview" class="w-full max-h-[85vh] object-contain rounded bg-white" />
    </div>
</div>

<script>
(function () {
    // Bukti foto image zoom
    var modal = document.getElementById('fotoModal');
    var modalImg = document.getElementById('fotoModalImg');
    var closeBtn = document.getElementById('fotoModalClose');

    document.addEventListener('click', function(e) {
        if (e.target && e.target.classList.contains('js-foto-kejadian')) {
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
