<?php

require_once '../../config/database.php';

require_once '../../config/session.php';



if (
    !isset($_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin','superadmin'], true)
) {
    header("Location: ../../auth/login-v2.php");
    exit;
}


$role = $_SESSION['role'];
$bagianId = $_SESSION['bagian_id'] ?? null;

// Tentukan apakah user adalah admin global (superadmin atau admin dengan bagian_id NULL)
$isAdminGlobal = ($role === 'superadmin' || ($role === 'admin' && $bagianId === null));

// Helper untuk warna badge bagian
function getBagianBadgeColor($nama) {
    if (!$nama) return 'bg-gray-100 text-gray-600 border-gray-200';
    
    $colors = [
        ['bg-blue-50', 'text-blue-700', 'border-blue-200'],
        ['bg-green-50', 'text-green-700', 'border-green-200'],
        ['bg-purple-50', 'text-purple-700', 'border-purple-200'],
        ['bg-amber-50', 'text-amber-700', 'border-amber-200'],
        ['bg-rose-50', 'text-rose-700', 'border-rose-200'],
        ['bg-indigo-50', 'text-indigo-700', 'border-indigo-200'],
        ['bg-emerald-50', 'text-emerald-700', 'border-emerald-200'],
        ['bg-cyan-50', 'text-cyan-700', 'border-cyan-200'],
        ['bg-violet-50', 'text-violet-700', 'border-violet-200'],
    ];
    
    $index = abs(crc32($nama)) % count($colors);
    $c = $colors[$index];
    return "$c[0] $c[1] $c[2]";
}

$shiftTableExists = false;

$checkShiftTable = $conn->query("SHOW TABLES LIKE 'shift'");

if ($checkShiftTable && $checkShiftTable->num_rows > 0) {

    $shiftTableExists = true;

}



$result = null;

if ($shiftTableExists) {

    if ($isAdminGlobal) {
        // Admin global (superadmin atau admin bagian_id=NULL) ? lihat semua shift + nama bagian
        $sql = "
            SELECT s.*, b.nama_bagian 
            FROM shift s
            LEFT JOIN bagian b ON s.bagian_id = b.id
            ORDER BY b.nama_bagian ASC, s.nama_shift ASC
        ";

    } else {
        // Admin bagian ? hanya shift bagian sendiri
        $bagian_id = (int) $bagianId;
        $sql = "
            SELECT s.*, b.nama_bagian
            FROM shift s
            LEFT JOIN bagian b ON s.bagian_id = b.id
            WHERE s.bagian_id = $bagian_id
            ORDER BY s.nama_shift ASC
        ";
    }

    // Pagination
    $perPage = 20;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $countSql = preg_replace('/SELECT.*?FROM /s', 'SELECT COUNT(*) as total FROM ', $sql, 1);
    $countRes = $conn->query($countSql);
    $totalRows = ($countRes && $rc = $countRes->fetch_assoc()) ? (int)$rc['total'] : 0;
    $totalPages = max(1, ceil($totalRows / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;
    $sql .= " LIMIT $perPage OFFSET $offset";

    $result = $conn->query($sql);
}



if (isset($_GET['partial']) && $_GET['partial'] === '1') {
    $colCount = $isAdminGlobal ? 5 : 4;

    if (!$shiftTableExists) {
        echo "<tr><td colspan=\"$colCount\" class=\"px-6 py-6 text-center text-red-600 font-semibold\">Tabel <b>shift</b> belum tersedia di database.</td></tr>";
        exit;
    }

    if (!$result || $result->num_rows === 0) {
        echo "<tr><td colspan=\"$colCount\" class=\"px-6 py-6 text-center\">Belum ada data shift.</td></tr>";
        exit;
    }

    while ($row = $result->fetch_assoc()) {
        echo "<tr class=\"bg-white border-b hover:bg-gray-50\">";
        echo "<td class=\"px-6 py-4\">";
        echo "<div class=\"font-bold text-gray-900\">" . htmlspecialchars($row['nama_shift']) . "</div>";
        echo "<div class=\"text-xs text-gray-500\">ID: " . (int)$row['id'] . "</div>";
        echo "</td>";

        if ($isAdminGlobal) {
            $badgeColor = getBagianBadgeColor($row['nama_bagian']);
            echo "<td class=\"px-6 py-4\">";
            echo "<span class=\"px-2.5 py-1 rounded-full text-xs font-bold border $badgeColor\">";
            echo htmlspecialchars($row['nama_bagian'] ?? 'GLOBAL');
            echo "</span>";
            echo "</td>";
        }

        echo "<td class=\"px-6 py-4\">";
        echo "<span class=\"font-semibold text-gray-800\">" . substr($row['mulai_masuk'], 0, 5) . "</span>";
        echo "<span class=\"text-gray-400\">-</span>";
        echo "<span class=\"font-semibold text-gray-800\">" . substr($row['akhir_masuk'], 0, 5) . "</span>";
        echo "</td>";

        echo "<td class=\"px-6 py-4\">";
        echo "<span class=\"font-semibold text-gray-800\">" . substr($row['mulai_keluar'], 0, 5) . "</span>";
        echo "<span class=\"text-gray-400\">-</span>";
        echo "<span class=\"font-semibold text-gray-800\">" . substr($row['akhir_keluar'], 0, 5) . "</span>";
        echo "</td>";

        echo "<td class=\"px-6 py-4 text-center\">";
        echo "<div class=\"flex item-center justify-center gap-3\">";
        echo "<a href=\"edit.php?id=" . (int)$row['id'] . "\" class=\"text-blue-600 hover:text-blue-900\" title=\"Edit\"><i class=\"fas fa-edit\"></i></a>";
        echo "<a href=\"hapus.php?id=" . (int)$row['id'] . "\" class=\"text-red-600 hover:text-red-900\" title=\"Hapus\" onclick=\"return confirm('Yakin hapus shift ini?');\"><i class=\"fas fa-trash\"></i></a>";
        echo "</div>";
        echo "</td>";
        echo "</tr>";
    }
    exit;
}



require_once '../layout/header.php';

require_once '../layout/sidebar.php';



?>



<div class="flex justify-between items-center mb-6">

    <h1 class="text-2xl font-bold text-gray-800">Master Shift</h1>

    <a href="tambah.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg shadow transition">

        <i class="fas fa-plus mr-2"></i> Tambah Shift

    </a>

</div>



<div class="bg-white rounded-lg shadow overflow-hidden">

    <div class="overflow-x-auto">

        <table class="w-full text-sm text-left text-gray-500">

            <thead class="text-xs text-gray-700 uppercase bg-gray-100">

                <tr>

                    <th class="px-6 py-3">Nama Shift</th>

                    <?php if ($isAdminGlobal): ?>
                    <th class="px-6 py-3">Bagian/Unit</th>
                    <?php endif; ?>

                    <th class="px-6 py-3">Masuk</th>

                    <th class="px-6 py-3">Keluar</th>

                    <th class="px-6 py-3 text-center">Aksi</th>

                </tr>

            </thead>

            <tbody id="table-body">
                <?php 
                $colCount = $isAdminGlobal ? 5 : 4;
                if (!$shiftTableExists): 
                ?>
                    <tr>
                        <td colspan="<?= $colCount ?>" class="px-6 py-6 text-center text-red-600 font-semibold">
                            Tabel <b>shift</b> belum tersedia di database.
                        </td>
                    </tr>
                <?php elseif (!$result || $result->num_rows === 0): ?>
                    <tr>
                        <td colspan="<?= $colCount ?>" class="px-6 py-6 text-center">Belum ada data shift.</td>
                    </tr>
                <?php else: ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr class="bg-white border-b hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <div class="font-bold text-gray-900"><?= htmlspecialchars($row['nama_shift']) ?></div>
                                <div class="text-xs text-gray-500">ID: <?= (int)$row['id'] ?></div>
                            </td>

                            <?php if ($isAdminGlobal): ?>
                            <td class="px-6 py-4">
                                <?php $badgeColor = getBagianBadgeColor($row['nama_bagian']); ?>
                                <span class="px-2.5 py-1 rounded-full text-xs font-bold border <?= $badgeColor ?>">
                                    <?= htmlspecialchars($row['nama_bagian'] ?? 'GLOBAL') ?>
                                </span>
                            </td>
                            <?php endif; ?>

                            <td class="px-6 py-4">
                                <span class="font-semibold text-gray-800"><?= substr($row['mulai_masuk'], 0, 5) ?></span>
                                <span class="text-gray-400">-</span>
                                <span class="font-semibold text-gray-800"><?= substr($row['akhir_masuk'], 0, 5) ?></span>
                            </td>

                            <td class="px-6 py-4">
                                <span class="font-semibold text-gray-800"><?= substr($row['mulai_keluar'], 0, 5) ?></span>
                                <span class="text-gray-400">-</span>
                                <span class="font-semibold text-gray-800"><?= substr($row['akhir_keluar'], 0, 5) ?></span>
                            </td>

                            <td class="px-6 py-4 text-center">
                                <div class="flex item-center justify-center gap-3">
                                    <a href="edit.php?id=<?= (int)$row['id'] ?>" class="text-blue-600 hover:text-blue-900" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="hapus.php?id=<?= (int)$row['id'] ?>" class="text-red-600 hover:text-red-900" title="Hapus" onclick="return confirm('Yakin hapus shift ini?');">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>

        </table>

    </div>

<?php
if ($shiftTableExists) {
    require_once '../layout/pagination.php';
    renderPagination($page, $totalPages, '?');
}
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



<?php require_once '../layout/footer.php'; ?>

