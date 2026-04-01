<?php

require_once '../../config/database.php';

require_once '../../config/session.php';





if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {

    http_response_code(403);

    exit;

}



$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$bulan_filter = isset($_GET['bulan']) ? $_GET['bulan'] : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $bulan_filter)) {
    $bulan_filter = date('Y-m');
}
$tgl_awal = $bulan_filter . '-01';
$tgl_akhir = date('Y-m-t', strtotime($tgl_awal));



$shiftTableExists = false;

$shiftIdColumnExists = false;

$petugasShiftColumnExists = false;

$pengajuanShiftColumnExists = false;



$checkPengajuanShift = $conn->query("SHOW COLUMNS FROM pengajuan LIKE 'shift'");

if ($checkPengajuanShift && $checkPengajuanShift->num_rows > 0) {

    $pengajuanShiftColumnExists = true;

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



$checkPengajuanShiftId = $conn->query("SHOW COLUMNS FROM pengajuan LIKE 'shift_id'");
if ($checkPengajuanShiftId && $checkPengajuanShiftId->num_rows > 0) {
    $pengajuanShiftIdColumnExists = true;
}

$joinShift = "";
$shiftDisplayExpr = "'-'";

if ($shiftTableExists && $pengajuanShiftIdColumnExists) {
    $joinShift = " LEFT JOIN shift s ON p.shift_id = s.id ";
    $shiftDisplayExpr = "COALESCE(s.nama_shift, '-')";
} else if ($pengajuanShiftColumnExists) {
    $shiftDisplayExpr = "COALESCE(p.shift, '-')";
}

if (isset($_GET['partial']) && $_GET['partial'] == '1') {

    $query = "SELECT p.*, pa.nama, pa.nip, {$shiftDisplayExpr} AS shift_display 

              FROM pengajuan p 

              JOIN petugas pa ON p.petugas_id = pa.id 

              {$joinShift}

              WHERE DATE(p.created_at) BETWEEN '$tgl_awal' AND '$tgl_akhir'";

// Filter by bagian_id if set in session
    if (isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] != '') {
        $query .= " AND pa.bagian_id = " . (int)$_SESSION['bagian_id'];
    }

    if ($search) {

        $query .= " AND (pa.nama LIKE '%$search%' OR pa.nip LIKE '%$search%' OR p.jenis LIKE '%$search%' OR p.keterangan LIKE '%$search%' OR p.status LIKE '%$search%')";

    }



    $query .= " ORDER BY FIELD(p.status, 'pending') DESC, p.created_at DESC";

    // Pagination for partial
    $perPage = 20;
    $partialPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $countSql = preg_replace('/SELECT.*?FROM /s', 'SELECT COUNT(*) as total FROM ', $query, 1);
    $countRes = $conn->query($countSql);
    $totalRows = ($countRes && $rc = $countRes->fetch_assoc()) ? (int)$rc['total'] : 0;
    $totalPages = max(1, ceil($totalRows / $perPage));
    if ($partialPage > $totalPages) $partialPage = $totalPages;
    $offset = ($partialPage - 1) * $perPage;
    $query .= " LIMIT $perPage OFFSET $offset";

    $result = $conn->query($query);



    if ($result && $result->num_rows > 0):

        while ($row = $result->fetch_assoc()):

?>

<tr class="bg-white border-b hover:bg-gray-50">

    <td class="px-6 py-4"><?= date('d/m/Y', strtotime($row['created_at'])) ?></td>

    <td class="px-6 py-4 font-bold text-gray-800">

        <?= $row['nama'] ?> <br> <span class="text-xs font-normal text-gray-500"><?= $row['nip'] ?></span>

    </td>

    <td class="px-6 py-4">

        <span class="bg-gray-100 text-gray-800 text-xs font-bold px-2 py-1 rounded uppercase"><?= $row['shift_display'] ?></span>

    </td>

    <td class="px-6 py-4 text-blue-600 font-bold"><?= date('d M Y', strtotime($row['tanggal'])) ?></td>

    <td class="px-6 py-4">

        <span class="uppercase font-bold text-xs"><?= $row['jenis'] ?></span>
        <?php if ($row['jenis'] == 'lupa absen' && !empty($row['jenis_lupa_absen'])): ?>
            <span class="block text-xs text-purple-600 mt-0.5">Lupa Absen <?= $row['jenis_lupa_absen'] == 'masuk' ? 'Masuk' : 'Keluar' ?></span>
        <?php endif; ?>

        <p class="text-xs text-gray-500 mt-1"><?= $row['keterangan'] ?></p>

    <td class="px-6 py-4 text-center">
    <?php if ($row['jenis'] == 'sakit' && !empty($row['bukti_sakit'])): ?>
        <?php
            // Support both old file-path and new base64 storage
            $buktiSrc = $row['bukti_sakit'];
            if (strpos($buktiSrc, 'data:image') !== 0) {
                $buktiSrc = '../../uploads/sakit/' . $buktiSrc;
            }
        ?>
        <img src="<?= $buktiSrc ?>"
             class="w-16 h-16 object-cover rounded-lg shadow border cursor-pointer hover:scale-110 transition js-bukti-img"
             alt="Bukti Sakit">

    <?php else: ?>
        <span class="text-gray-400 text-xs italic">-</span>
    <?php endif; ?>
</td>


    <td class="px-6 py-4">

        <?php

   switch ($row['status']) {
    case 'pending':
        $cl = 'bg-yellow-100 text-yellow-800';
        break;
    case 'disetujui':
        $cl = 'bg-green-100 text-green-800';
        break;
    case 'ditolak':
        $cl = 'bg-red-100 text-red-800';
        break;
    default:
        $cl = 'bg-gray-100 text-gray-800';
}

        ?>

        <span class="<?= $cl ?> px-2 py-1 rounded text-xs font-bold uppercase"><?= $row['status'] ?></span>

    </td>

    <td class="px-6 py-4 text-center">

        <?php if ($row['status'] == 'pending'): ?>

            <div class="flex justify-center gap-2">

                <a href="proses-pengajuan.php?id=<?= $row['id'] ?>&aksi=approve" onclick="return confirm('Setujui izin ini? Data absensi akan otomatis dibuat.')" class="bg-green-500 hover:bg-green-600 text-white p-2 rounded shadow">

                    <i class="fas fa-check"></i>

                </a>

                <a href="proses-pengajuan.php?id=<?= $row['id'] ?>&aksi=reject" onclick="return confirm('Tolak pengajuan ini?')" class="bg-red-500 hover:bg-red-600 text-white p-2 rounded shadow">

                    <i class="fas fa-times"></i>

                </a>

            </div>

        <?php else: ?>

            <span class="text-gray-400 text-xs italic">Selesai</span>

        <?php endif; ?>

    </td>

</tr>

<?php endwhile; else: ?>

<tr><td colspan="8" class="text-center py-4">Belum ada pengajuan.</td></tr>


<?php endif; ?>

<?php

    exit;

}



require_once '../layout/header.php';

require_once '../layout/sidebar.php';

?>



<div class="mb-6">

    <h1 class="text-2xl font-bold text-gray-800">Validasi Izin & Sakit</h1>

    <p class="text-sm text-gray-600">Persetujuan ketidakhadiran petugas.</p>

</div>



<div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 mb-6">

    <form action="" method="GET" class="flex flex-col sm:flex-row gap-4 items-end">

        <div>
            <label class="text-xs font-bold text-gray-500 uppercase">Bulan</label>
            <input type="month" name="bulan" value="<?= htmlspecialchars($bulan_filter) ?>" class="border rounded p-2 w-full text-sm">
        </div>

        <div class="flex-1">

            <label class="text-xs font-bold text-gray-500 uppercase">Cari Pengajuan</label>

            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Nama, NIP, jenis, keterangan..." class="border rounded p-2 w-full text-sm">

        </div>

        <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded text-sm font-bold">Filter</button>

    </form>

</div>



<div class="bg-white rounded-lg shadow overflow-hidden">

    <table class="w-full text-sm text-left text-gray-500">

        <thead class="text-xs text-gray-700 uppercase bg-gray-100">

            <tr>

                <th class="px-6 py-3">Tgl Pengajuan</th>

                <th class="px-6 py-3">Nama Petugas</th>

                <th class="px-6 py-3">Shift</th>

                <th class="px-6 py-3">Untuk Tanggal</th>

                <th class="px-6 py-3">Jenis & Alasan</th>
<th class="px-6 py-3">Bukti Sakit</th>

                <th class="px-6 py-3">Status</th>
		

                <th class="px-6 py-3 text-center">Aksi</th>

            </tr>

        </thead>

        <tbody id="table-body">

            <?php

            $query = "SELECT p.*, pa.nama, pa.nip, {$shiftDisplayExpr} AS shift_display 

                      FROM pengajuan p 

                      JOIN petugas pa ON p.petugas_id = pa.id 

                      {$joinShift}

                      WHERE DATE(p.created_at) BETWEEN '$tgl_awal' AND '$tgl_akhir'";

 	if (isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] != '') {
                $query .= " AND pa.bagian_id = " . (int)$_SESSION['bagian_id'];
            }

            if ($search) {

                $query .= " AND (pa.nama LIKE '%$search%' OR pa.nip LIKE '%$search%' OR p.jenis LIKE '%$search%' OR p.keterangan LIKE '%$search%' OR p.status LIKE '%$search%')";

            }



            $query .= " ORDER BY FIELD(p.status, 'pending') DESC, p.created_at DESC";

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



            if ($result->num_rows > 0):

                while ($row = $result->fetch_assoc()):

            ?>

            <tr class="bg-white border-b hover:bg-gray-50">

                <td class="px-6 py-4"><?= date('d/m/Y', strtotime($row['created_at'])) ?></td>

                <td class="px-6 py-4 font-bold text-gray-800">

                    <?= $row['nama'] ?> <br> <span class="text-xs font-normal text-gray-500"><?= $row['nip'] ?></span>

                </td>

                <td class="px-6 py-4">

                    <span class="bg-gray-100 text-gray-800 text-xs font-bold px-2 py-1 rounded uppercase"><?= $row['shift_display'] ?></span>

                </td>

                <td class="px-6 py-4 text-blue-600 font-bold"><?= date('d M Y', strtotime($row['tanggal'])) ?></td>

                <td class="px-6 py-4">

                    <span class="uppercase font-bold text-xs"><?= $row['jenis'] ?></span>
                    <?php if ($row['jenis'] == 'lupa absen' && !empty($row['jenis_lupa_absen'])): ?>
                        <span class="block text-xs text-purple-600 mt-0.5">Lupa Absen <?= $row['jenis_lupa_absen'] == 'masuk' ? 'Masuk' : 'Keluar' ?></span>
                    <?php endif; ?>

                    <p class="text-xs text-gray-500 mt-1"><?= $row['keterangan'] ?></p>

                </td>

                <td class="px-6 py-4">

                    <?php
              switch ($row['status']) {
    case 'pending':
        $cl = 'bg-yellow-100 text-yellow-800';
        break;
    case 'disetujui':
        $cl = 'bg-green-100 text-green-800';
        break;
    case 'ditolak':
        $cl = 'bg-red-100 text-red-800';
        break;
    default:
        $cl = 'bg-gray-100 text-gray-800';
}

                    ?>

                    <span class="<?= $cl ?> px-2 py-1 rounded text-xs font-bold uppercase"><?= $row['status'] ?></span>

                </td>

                <td class="px-6 py-4 text-center">

                    <?php if ($row['status'] == 'pending'): ?>

                        <div class="flex justify-center gap-2">

                            <a href="proses-pengajuan.php?id=<?= $row['id'] ?>&aksi=approve" onclick="return confirm('Setujui izin ini? Data absensi akan otomatis dibuat.')" class="bg-green-500 hover:bg-green-600 text-white p-2 rounded shadow">

                                <i class="fas fa-check"></i>

                            </a>

                            <a href="proses-pengajuan.php?id=<?= $row['id'] ?>&aksi=reject" onclick="return confirm('Tolak pengajuan ini?')" class="bg-red-500 hover:bg-red-600 text-white p-2 rounded shadow">

                                <i class="fas fa-times"></i>

                            </a>

                        </div>

                    <?php else: ?>

                        <span class="text-gray-400 text-xs italic">Selesai</span>

                    <?php endif; ?>

                </td>

            </tr>

            <?php endwhile; else: ?>

            <tr><td colspan="7" class="text-center py-4">Belum ada pengajuan.</td></tr>

            <?php endif; ?>

        </tbody>

    </table>

<?php
require_once '../layout/pagination.php';
$paginationParams = array_filter(['bulan' => $bulan_filter, 'search' => $search]);
$paginationUrl = '?' . http_build_query($paginationParams);
renderPagination($page, $totalPages, $paginationUrl);
?>

</div>



<!-- Image Zoom Modal for Bukti Sakit -->
<div id="buktiModal" class="fixed inset-0 z-[2000] hidden items-center justify-center bg-black/70 p-4">
    <div class="relative max-w-3xl w-full">
        <button type="button" id="buktiModalClose" class="absolute -top-10 right-0 text-white text-2xl leading-none">&times;</button>
        <img id="buktiModalImg" src="" alt="Preview" class="w-full max-h-[85vh] object-contain rounded bg-white" />
    </div>
</div>

<script>
(function () {
    // Bukti sakit image zoom
    var modal = document.getElementById('buktiModal');
    var modalImg = document.getElementById('buktiModalImg');
    var closeBtn = document.getElementById('buktiModalClose');

    document.addEventListener('click', function(e) {
        if (e.target && e.target.classList.contains('js-bukti-img')) {
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

(function () {

    const tbody = document.getElementById('table-body');
    if (!tbody) return;

    let lastHtml = tbody.innerHTML;
    let intervalId = null;

    async function fetchPartialAndUpdate() {
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
                tbody.innerHTML = html;
                lastHtml = html;
            }

        } catch (e) {
            console.log("Fetch error:", e);
        }
    }

    function start() {
        if (intervalId) return;
        intervalId = setInterval(fetchPartialAndUpdate, 4000);
    }

    // ? FIX UTAMA:
    // Fetch sekali langsung setelah halaman selesai load
    window.addEventListener("load", () => {
        fetchPartialAndUpdate(); // tampilkan gambar langsung
        start(); // baru lanjut polling
    });

})();
</script>



<?php require_once '../layout/footer.php'; ?>