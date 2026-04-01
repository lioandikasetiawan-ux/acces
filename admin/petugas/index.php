<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'superadmin'], true)) {
    header("Location: ../../auth/login-v2.php");
    exit;
}

$shiftTableExists = false;
$shiftIdColumnExists = false;
$petugasShiftColumnExists = false;
$bagianTableExists = false;
$petugasBagianIdColumnExists = false;
$petugasBagianColumnExists = false;
$lokasiWilayahColumnExists = false;
$isActiveColumnExists = false;

$checkIsActiveCol = $conn->query("SHOW COLUMNS FROM petugas LIKE 'is_active'");
if ($checkIsActiveCol && $checkIsActiveCol->num_rows > 0) {
    $isActiveColumnExists = true;
}

$checkShiftTable = $conn->query("SHOW TABLES LIKE 'shift'");
if ($checkShiftTable && $checkShiftTable->num_rows > 0) {
    $shiftTableExists = true;
}

$checkShiftIdColumn = $conn->query("SHOW COLUMNS FROM petugas LIKE 'shift_id'");
if ($checkShiftIdColumn && $checkShiftIdColumn->num_rows > 0) {
    $shiftIdColumnExists = true;
}

$checkPetugasShift = $conn->query("SHOW COLUMNS FROM petugas LIKE 'shift'");
if ($checkPetugasShift && $checkPetugasShift->num_rows > 0) {
    $petugasShiftColumnExists = true;
}

$checkBagianTable = $conn->query("SHOW TABLES LIKE 'bagian'");
if ($checkBagianTable && $checkBagianTable->num_rows > 0) {
    $bagianTableExists = true;
}

$checkLokasiWilayah = $conn->query("SHOW COLUMNS FROM bagian LIKE 'lokasi_wilayah'");
if ($checkLokasiWilayah && $checkLokasiWilayah->num_rows > 0) {
    $lokasiWilayahColumnExists = true;
}

$checkPetugasBagianId = $conn->query("SHOW COLUMNS FROM petugas LIKE 'bagian_id'");
if ($checkPetugasBagianId && $checkPetugasBagianId->num_rows > 0) {
    $petugasBagianIdColumnExists = true;
}

$checkPetugasBagian = $conn->query("SHOW COLUMNS FROM petugas LIKE 'bagian'");
if ($checkPetugasBagian && $checkPetugasBagian->num_rows > 0) {
    $petugasBagianColumnExists = true;
}

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';

if (isset($_GET['partial']) && $_GET['partial'] === '1') {
    $currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['petugas_id'] ?? 0);
    $selectBagianDisplay = "'-' AS bagian_display";
    $selectLokasiWilayahDisplay = "'' AS lokasi_wilayah_display";
    $joinBagian = "";
    if ($bagianTableExists && $petugasBagianIdColumnExists) {
        $joinBagian = " LEFT JOIN bagian b ON p.bagian_id = b.id ";

        if ($petugasBagianColumnExists) {
            $selectBagianDisplay = "COALESCE(b.nama_bagian, p.bagian) AS bagian_display";
        } else {
            $selectBagianDisplay = "COALESCE(b.nama_bagian, '-') AS bagian_display";
        }
        $selectLokasiWilayahDisplay = "COALESCE(" . ($lokasiWilayahColumnExists ? "b.lokasi_wilayah" : "NULL") . ", b.deskripsi, '') AS lokasi_wilayah_display";
    } else if ($petugasBagianColumnExists) {
        $selectBagianDisplay = "p.bagian AS bagian_display";
    }
	
if ($_SESSION['bagian_id'] === NULL) {
    $query = "
        SELECT p.*, {$selectBagianDisplay}, {$selectLokasiWilayahDisplay}
        FROM petugas p 
        {$joinBagian}
        WHERE 1=1
    ";
} else {
    $bagian_id = (int) $_SESSION['bagian_id'];
    $query = "
        SELECT p.*, {$selectBagianDisplay}, {$selectLokasiWilayahDisplay}
        FROM petugas p 
        {$joinBagian}
        WHERE p.bagian_id = $bagian_id
    ";
}
 	

    if ($search) {
        $searchConds = [];
        $searchConds[] = "p.nama LIKE '%$search%'";
        $searchConds[] = "p.nip LIKE '%$search%'";
        $searchConds[] = "p.jabatan LIKE '%$search%'";
        $searchConds[] = "p.kode_jabatan LIKE '%$search%'";
        $searchConds[] = "p.alamat LIKE '%$search%'";
        $searchConds[] = "p.job_desc LIKE '%$search%'";
        if ($petugasBagianColumnExists) {
            $searchConds[] = "p.bagian LIKE '%$search%'";
        }
        if ($bagianTableExists && $petugasBagianIdColumnExists) {
            $searchConds[] = "b.nama_bagian LIKE '%$search%'";
        }
        //$query .= " AND (". bagian_id==".$_SESSION['bagian_id'].");
        $query .= " AND (" . implode(' OR ', $searchConds) . ")";
    }
    $query .= " ORDER BY p.nama ASC";

    // Pagination
    $perPage = 20;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $countSql = preg_replace('/SELECT.*?FROM /s', 'SELECT COUNT(*) as total FROM ', $query, 1);
    $countResult = $conn->query($countSql);
    $totalRows = ($countResult && $row_c = $countResult->fetch_assoc()) ? (int)$row_c['total'] : 0;
    $totalPages = max(1, ceil($totalRows / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;
    $query .= " LIMIT $perPage OFFSET $offset";

    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $alamat = $row['alamat'] ?? '-';
            $lokasiText = $row['lokasi_wilayah_display'] ?? '';
            if ($lokasiText === '') $lokasiText = $alamat;
            $jobDesc = $row['job_desc'] ?? '-';
            $kodeJabatan = $row['kode_jabatan'] ?? '-';
            $jabatan = $row['jabatan'] ?? '-';
            echo "<tr class=\"bg-white border-b hover:bg-gray-50\">";
            echo "<td class=\"px-6 py-4\">";
            echo "<div class=\"font-bold text-gray-900\">" . $row['nama'] . "</div>";
            echo "<div class=\"text-xs text-gray-500\">NIP: " . $row['nip'] . "</div>";
            echo "</td>";
            echo "<td class=\"px-6 py-4\">";
            echo "<div class=\"text-gray-900\">" . $jabatan . "</div>";
            echo "<div class=\"text-xs text-gray-500\">Kode: " . $kodeJabatan . "</div>";
            echo "</td>";
            echo "<td class=\"px-6 py-4\">";
            echo "<span class=\"bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-0.5 rounded\">" . ($row['bagian_display'] ?? '-') . "</span>";
            echo "<div class=\"text-xs text-gray-500 truncate max-w-xs\">" . $lokasiText . "</div>";
            echo "</td>";

            echo "<td class=\"px-6 py-4\">";
            echo "<div class=\"text-gray-700 text-sm\">" . $jobDesc . "</div>";
            echo "</td>";
            echo "<td class=\"px-6 py-4 text-center\">";
            echo "<div class=\"flex item-center justify-center gap-2\">";
            echo "<a href=\"edit.php?id=" . $row['id'] . "\" class=\"text-blue-600 hover:text-blue-900\" title=\"Edit Data\"><i class=\"fas fa-edit\"></i></a>";
            if ($isActiveColumnExists && (int)$row['id'] !== $currentUserId) {
                $isActive = isset($row['is_active']) ? (int)$row['is_active'] : 1;
                $toggleClass = $isActive ? 'text-green-600 hover:text-green-800' : 'text-gray-400 hover:text-gray-600';
                $toggleIcon = $isActive ? 'fa-toggle-on' : 'fa-toggle-off';
                $toggleTitle = $isActive ? 'Nonaktifkan Petugas' : 'Aktifkan Petugas';
                echo "<button onclick=\"togglePetugas(" . $row['id'] . ", this)\" class=\"$toggleClass\" title=\"$toggleTitle\"><i class=\"fas $toggleIcon text-lg\"></i></button>";
            }
            if ((int)$row['id'] !== $currentUserId) {
                echo "<a href=\"delete-process.php?id=" . $row['id'] . "\" class=\"text-red-600 hover:text-red-900\" title=\"Hapus\" onclick=\"return confirm('Yakin hapus petugas ini? Semua data absensi terkait akan ikut terhapus!');\"><i class=\"fas fa-trash\"></i></a>";
            }
            echo "</div>";
            echo "</td>";
            echo "</tr>";
        }
    } else {
            echo "<tr><td colspan=\"5\" class=\"px-6 py-4 text-center\">Belum ada data petugas. Silakan tambah baru.</td></tr>";
    }
    exit;
}

// Server-side rendering for main page
$currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['petugas_id'] ?? 0);
$selectBagianDisplay = "'-' AS bagian_display";
$selectLokasiWilayahDisplay = "'' AS lokasi_wilayah_display";
$joinBagian = "";
if ($bagianTableExists && $petugasBagianIdColumnExists) {
    $joinBagian = " LEFT JOIN bagian b ON p.bagian_id = b.id ";
    if ($petugasBagianColumnExists) {
        $selectBagianDisplay = "COALESCE(b.nama_bagian, p.bagian) AS bagian_display";
    } else {
        $selectBagianDisplay = "COALESCE(b.nama_bagian, '-') AS bagian_display";
    }
    $selectLokasiWilayahDisplay = "COALESCE(" . ($lokasiWilayahColumnExists ? "b.lokasi_wilayah" : "NULL") . ", b.deskripsi, '') AS lokasi_wilayah_display";
} else if ($petugasBagianColumnExists) {
    $selectBagianDisplay = "p.bagian AS bagian_display";
}

if ($_SESSION['bagian_id'] === NULL) {
    $mainQuery = "SELECT p.*, {$selectBagianDisplay}, {$selectLokasiWilayahDisplay} FROM petugas p {$joinBagian} WHERE 1=1";
} else {
    $bagian_id = (int) $_SESSION['bagian_id'];
    $mainQuery = "SELECT p.*, {$selectBagianDisplay}, {$selectLokasiWilayahDisplay} FROM petugas p {$joinBagian} WHERE p.bagian_id = $bagian_id";
}

if ($search) {
    $searchConds = [];
    $searchConds[] = "p.nama LIKE '%$search%'";
    $searchConds[] = "p.nip LIKE '%$search%'";
    $searchConds[] = "p.jabatan LIKE '%$search%'";
    $searchConds[] = "p.kode_jabatan LIKE '%$search%'";
    $searchConds[] = "p.alamat LIKE '%$search%'";
    $searchConds[] = "p.job_desc LIKE '%$search%'";
    if ($petugasBagianColumnExists) $searchConds[] = "p.bagian LIKE '%$search%'";
    if ($bagianTableExists && $petugasBagianIdColumnExists) $searchConds[] = "b.nama_bagian LIKE '%$search%'";
    $mainQuery .= " AND (" . implode(' OR ', $searchConds) . ")";
}

$mainQuery .= " ORDER BY p.nama ASC";

// Pagination
$perPage = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$countSql = preg_replace('/SELECT.*?FROM /s', 'SELECT COUNT(*) as total FROM ', $mainQuery, 1);
$countResult = $conn->query($countSql);
$totalRows = ($countResult && $row_c = $countResult->fetch_assoc()) ? (int)$row_c['total'] : 0;
$totalPages = max(1, ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;
$mainQuery .= " LIMIT $perPage OFFSET $offset";
$mainResult = $conn->query($mainQuery);

require_once '../layout/header.php';
require_once '../layout/sidebar.php';
?>

<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
    <h1 class="text-2xl font-bold text-gray-800">Manajemen Petugas</h1>
    <a href="create.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg shadow transition">
        <i class="fas fa-plus mr-2"></i> Tambah Petugas
    </a>
</div>

<div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 mb-6">
    <form method="GET" class="flex flex-col sm:flex-row gap-4 items-end">
        <div class="flex-1 w-full">
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Cari Petugas</label>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari nama, NIP, bagian, jabatan..." 
                   class="border rounded p-2 w-full text-sm focus:ring-2 focus:ring-blue-500 outline-none">
        </div>
        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded shadow text-sm font-bold">
            <i class="fas fa-search mr-1"></i> Cari
        </button>
    </form>
</div>

<div class="bg-white rounded-lg shadow overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left text-gray-500">
            <thead class="text-xs text-gray-700 uppercase bg-gray-100">
                <tr>
                    <th class="px-6 py-3">Nama / NIP</th>
                    <th class="px-6 py-3">Jabatan</th>
                    <th class="px-6 py-3">Bagian / Lokasi</th>
                    <th class="px-6 py-3">Job Desc</th>
                    <th class="px-6 py-3 text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($mainResult && $mainResult->num_rows > 0): ?>
                    <?php while ($row = $mainResult->fetch_assoc()):
                        $alamat = $row['alamat'] ?? '-';
                        $lokasiText = $row['lokasi_wilayah_display'] ?? '';
                        if ($lokasiText === '') $lokasiText = $alamat;
                        $jobDesc = $row['job_desc'] ?? '-';
                        $kodeJabatan = $row['kode_jabatan'] ?? '-';
                        $jabatan = $row['jabatan'] ?? '-';
                    ?>
                    <tr class="bg-white border-b hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <div class="font-bold text-gray-900"><?= htmlspecialchars($row['nama']) ?></div>
                            <div class="text-xs text-gray-500">NIP: <?= htmlspecialchars($row['nip']) ?></div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-gray-900"><?= htmlspecialchars($jabatan) ?></div>
                            <div class="text-xs text-gray-500">Kode: <?= htmlspecialchars($kodeJabatan) ?></div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-0.5 rounded"><?= htmlspecialchars($row['bagian_display'] ?? '-') ?></span>
                            <div class="text-xs text-gray-500 truncate max-w-xs"><?= htmlspecialchars($lokasiText) ?></div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-gray-700 text-sm"><?= htmlspecialchars($jobDesc) ?></div>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <div class="flex item-center justify-center gap-2">
                                <a href="edit.php?id=<?= $row['id'] ?>" class="text-blue-600 hover:text-blue-900" title="Edit Data"><i class="fas fa-edit"></i></a>
                                <?php if ($isActiveColumnExists && (int)$row['id'] !== $currentUserId): 
                                    $isActive = isset($row['is_active']) ? (int)$row['is_active'] : 1;
                                    $toggleClass = $isActive ? 'text-green-600 hover:text-green-800' : 'text-gray-400 hover:text-gray-600';
                                    $toggleIcon = $isActive ? 'fa-toggle-on' : 'fa-toggle-off';
                                    $toggleTitle = $isActive ? 'Nonaktifkan Petugas' : 'Aktifkan Petugas';
                                ?>
                                <button onclick="togglePetugas(<?= $row['id'] ?>, this)" class="<?= $toggleClass ?>" title="<?= $toggleTitle ?>">
                                    <i class="fas <?= $toggleIcon ?> text-lg"></i>
                                </button>
                                <?php endif; ?>
                                <?php if ((int)$row['id'] !== $currentUserId): ?>
                                <a href="delete-process.php?id=<?= $row['id'] ?>" class="text-red-600 hover:text-red-900" title="Hapus" onclick="return confirm('Yakin hapus petugas ini? Semua data absensi terkait akan ikut terhapus!');"><i class="fas fa-trash"></i></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="px-6 py-4 text-center">Belum ada data petugas.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php
require_once '../layout/pagination.php';
$paginationParams = array_filter(['search' => $search]);
$paginationUrl = '?' . http_build_query($paginationParams);
renderPagination($page, $totalPages, $paginationUrl);
?>
</div>

<script>
function togglePetugas(id, btn) {
    if (!confirm('Yakin ingin mengubah status aktif petugas ini?')) return;
    fetch('toggle-status.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id=' + id
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const icon = btn.querySelector('i');
            if (data.is_active) {
                btn.className = 'text-green-600 hover:text-green-800';
                btn.title = 'Nonaktifkan Petugas';
                icon.className = 'fas fa-toggle-on text-lg';
            } else {
                btn.className = 'text-gray-400 hover:text-gray-600';
                btn.title = 'Aktifkan Petugas';
                icon.className = 'fas fa-toggle-off text-lg';
            }
        } else {
            alert('Error: ' + (data.message || 'Gagal mengubah status'));
        }
    })
    .catch(() => alert('Terjadi kesalahan'));
}
</script>

<?php require_once '../layout/footer.php'; ?>
