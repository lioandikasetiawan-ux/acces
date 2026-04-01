
<?php
    require_once '../../config/database.php';
    require_once '../../includes/functions.php';
    require_once '../../config/session.php';

    // Proteksi Role
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'superadmin'], true)) {
        header("Location: ../../auth/login-v2.php");
        exit;
    }

$isLockedAdmin = ($_SESSION['role'] === 'admin' && !empty($_SESSION['bagian_id']));
$exportExcel = (isset($_GET['excel']) && $_GET['excel'] === '1' && !$isLockedAdmin);
$flash = getFlashMessage();

// 1. Cek keberadaan kolom secara dinamis (Pencegahan Error bk. = b.id)
$lokasiWilayahColumnExists = false;
    $checkLokasiWilayah = $conn->query("SHOW COLUMNS FROM bagian LIKE 'lokasi_wilayah'");
    if ($checkLokasiWilayah && $checkLokasiWilayah->num_rows > 0) {
        $lokasiWilayahColumnExists = true;
    }

// Pastikan variabel ini punya nilai default 'bagian_id' agar query tidak pecah
$koordinatBagianCol = 'bagian_id'; 
$checkCol = $conn->query("SHOW COLUMNS FROM bagian_koordinat LIKE 'bagian_id'");
if (!$checkCol || $checkCol->num_rows === 0) {
    $checkOld = $conn->query("SHOW COLUMNS FROM bagian_koordinat LIKE 'bagian'");
    if ($checkOld && $checkOld->num_rows > 0) {
        $koordinatBagianCol = 'bagian';
    }
}

// 2. Tentukan field display
$selectLokasiWilayah = $lokasiWilayahColumnExists ? "COALESCE(b.lokasi_wilayah, b.deskripsi)" : "b.deskripsi";

// 3. Bangun WHERE clausess
$whereClause = " WHERE 1=1";
if (isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] != '') {
    $whereClause .= " AND b.id = " . (int)$_SESSION['bagian_id'];
}

// 4. Hitung Total Data untuk Pagination (Query terpisah agar AMAN)
$countSql = "SELECT COUNT(*) as total FROM bagian b" . $whereClause;
$countResult = $conn->query($countSql);
$totalRows = ($countResult && $row_c = $countResult->fetch_assoc()) ? (int)$row_c['total'] : 0;

// 5. Pengaturan Pagination
$perPage = 20;
$totalPages = max(1, ceil($totalRows / $perPage));
$page = isset($_GET['page']) ? max(1, min($totalPages, (int)$_GET['page'])) : 1;
$offset = ($page - 1) * $perPage;

// 6. Query Utama (Gunakan variabel $koordinatBagianCol yang sudah divalidasi)
$query = "
    SELECT b.*, 
           $selectLokasiWilayah AS lokasi_wilayah_display,
           (SELECT COUNT(*) FROM bagian_koordinat bk WHERE bk.$koordinatBagianCol = b.id) as jumlah_titik
    FROM bagian b 
    $whereClause
    ORDER BY b.nama_bagian
";

if (!$exportExcel) {
    $query .= " LIMIT $perPage OFFSET $offset";
}

$result = $conn->query($query);
    $bagianList = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $bagianList[] = $row;
        }
    }

// 7. Logika Export Excel
    if ($exportExcel) {
        $filename = 'master_bagian_' . date('Ymd') . '.xls';
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Pragma: no-cache');
        header('Expires: 0');

        echo "\xEF\xBB\xBF"; 
        echo '<table border="1">
                <thead>
                    <tr style="background-color: #eee;">
                        <th>No</th><th>Kode</th><th>Nama Bagian</th><th>Lokasi/Wilayah</th><th>Jumlah Titik</th><th>Status</th>
                    </tr>
                </thead>
                <tbody>';

            foreach ($bagianList as $i => $bagian) {
                $statusText = !empty($bagian['is_active']) ? 'Aktif' : 'Nonaktif';
                echo '<tr>
                        <td>' . ($i + 1) . '</td>
                        <td>' . htmlspecialchars($bagian['kode_bagian']) . '</td>
                        <td>' . htmlspecialchars($bagian['nama_bagian']) . '</td>
                        <td>' . htmlspecialchars($bagian['lokasi_wilayah_display'] ?? '') . '</td>
                        <td>' . (int)$bagian['jumlah_titik'] . '</td>
                        <td>' . $statusText . '</td>
                    </tr>';
            }
            echo '</tbody></table>';
            exit;
    }

    require_once '../layout/header.php';
    require_once '../layout/sidebar.php';

?>

<div class="p-8">

    <div class="flex justify-between items-center mb-6">

        <div>

            <h1 class="text-2xl font-bold text-gray-800">Master Bagian/Lokasi</h1>

            <p class="text-gray-600">Kelola data bagian dan titik koordinat absensi</p>

        </div>

        <div class="flex items-center gap-2">

            <?php if (!$isLockedAdmin): ?>

            <a href="?excel=1" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center gap-2">

                <i class="fas fa-file-excel"></i> Export Excel

            </a>

            <?php endif; ?>

            <?php if (!$isLockedAdmin): ?>

            <a href="create.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center gap-2">

                <i class="fas fa-plus"></i> Tambah Bagian

            </a>

            <?php endif; ?>

        </div>

    </div>



    <?php if ($flash): ?>

    <div class="mb-4 p-4 rounded-lg <?= $flash['type'] === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">

        <?= $flash['message'] ?>

    </div>

    <?php endif; ?>



    <div class="bg-white rounded-xl shadow-md overflow-hidden">

        <table class="min-w-full divide-y divide-gray-200">

            <thead class="bg-gray-50">

                <tr>

                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">No</th>

                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kode</th>

                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama Bagian</th>

                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Jumlah Titik</th>

                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>

                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>

                </tr>

            </thead>

            <tbody class="bg-white divide-y divide-gray-200">

                <?php if (empty($bagianList)): ?>

                <tr>

                    <td colspan="6" class="px-6 py-4 text-center text-gray-500">

                        Belum ada data bagian.

                        <?php if (!$isLockedAdmin): ?>

                        <a href="create.php" class="text-blue-600 hover:underline">Tambah bagian baru</a>

                        <?php endif; ?>

                    </td>

                </tr>

                <?php else: ?>

                <?php foreach ($bagianList as $i => $bagian): ?>

                <tr class="hover:bg-gray-50">

                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= $i + 1 ?></td>

                    <td class="px-6 py-4 whitespace-nowrap">

                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">

                            <?= htmlspecialchars($bagian['kode_bagian']) ?>

                        </span>

                    </td>

                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">

                        <?= htmlspecialchars($bagian['nama_bagian']) ?>

                        <?php if (!empty($bagian['lokasi_wilayah_display'])): ?>

                        <p class="text-xs text-gray-500"><?= htmlspecialchars(substr($bagian['lokasi_wilayah_display'], 0, 50)) ?>...</p>

                        <?php endif; ?>

                    </td>

                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">

                        <a href="koordinat.php?bagian_id=<?= $bagian['id'] ?>" class="text-blue-600 hover:underline">

                            <?= (int)$bagian['jumlah_titik'] ?> titik <i class="fas fa-map-marker-alt ml-1"></i>

                        </a>

                    </td>

                    <td class="px-6 py-4 whitespace-nowrap">

                        <?php if (!empty($bagian['is_active'])): ?>

                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Aktif</span>

                        <?php else: ?>

                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">Nonaktif</span>

                        <?php endif; ?>

                    </td>

                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">

                        <a href="edit.php?id=<?= $bagian['id'] ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">

                            <i class="fas fa-edit"></i> Edit

                        </a>

                        <button onclick="confirmDelete(<?= $bagian['id'] ?>, '<?= htmlspecialchars($bagian['nama_bagian']) ?>')" class="text-red-600 hover:text-red-900">

                            <i class="fas fa-trash"></i> Hapus

                        </button>

                    </td>

                </tr>

                <?php endforeach; ?>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

<?php
require_once '../layout/pagination.php';
renderPagination($page, $totalPages, '?');
?>

</div>



<script>

function confirmDelete(id, nama) {

    if (confirm('Yakin ingin menghapus bagian "' + nama + '"?\nSemua titik koordinat terkait juga akan dihapus.')) {

        window.location.href = 'delete.php?id=' + id;

    }

}

</script>



<?php require_once '../layout/footer.php'; ?>

