<?php
require_once '../../config/database.php';
require_once '../../config/session.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../auth/login-v2.php");
    exit;
}

$bulan_filter = $_GET['bulan'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $bulan_filter)) {
    $bulan_filter = date('Y-m');
}

// Backward-compatible start/end support
$tgl_awal  = $_GET['start'] ?? ($bulan_filter . '-01');
$tgl_akhir = $_GET['end'] ?? date('Y-m-t', strtotime($tgl_awal));
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';

// Ambil bagian_id dari sesi jika admin dan memiliki bagian_id
$admin_bagian_id = null;
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin' && isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] !== NULL) {
    $admin_bagian_id = (int)$_SESSION['bagian_id'];
}

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

$joinBagian = ($bagianTableExists && $petugasBagianIdColumnExists) ? " LEFT JOIN bagian b ON p.bagian_id = b.id " : "";
$selectBagian = $petugasBagianColumnExists
    ? "p.bagian AS bagian"
    : (($bagianTableExists && $petugasBagianIdColumnExists) ? "COALESCE(b.nama_bagian, '-') AS bagian" : "'-' AS bagian");

$sql = "SELECT l.*, a.tanggal, s.nama_shift AS shift, p.nama, $selectBagian
        FROM laporan_harian l
        JOIN absensi a ON l.absensi_id = a.id
        JOIN jadwal_petugas jp ON a.jadwal_id = jp.id
        JOIN shift s ON jp.shift_id = s.id
        JOIN petugas p ON a.petugas_id = p.id
        $joinBagian
        WHERE a.tanggal BETWEEN '$tgl_awal' AND '$tgl_akhir'";

// Tambahkan filter berdasarkan bagian_id jika admin memiliki bagian_id
if ($admin_bagian_id !== null) {
    $sql .= " AND p.bagian_id = $admin_bagian_id";
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

$sql .= " ORDER BY l.created_at DESC";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Kegiatan Lapangan</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            @page { size: A4 portrait; margin: 1cm; }
            body { -webkit-print-color-adjust: exact; }
            .no-print { display: none; }
        }
    </style>
</head>
<body class="bg-white p-8 font-sans text-gray-900" onload="window.print()">

    <div class="text-center mb-6 border-b-2 border-black pb-4">
        <h1 class="text-xl font-bold uppercase tracking-wide">Laporan Kegiatan Lapangan</h1>
        <?php if ($admin_bagian_id !== null && isset($_SESSION['nama_bagian'])): ?>
            <p class="text-sm text-gray-700">Bagian Unit: <?= htmlspecialchars($_SESSION['nama_bagian']) ?></p>
        <?php endif; ?>
        <p class="text-sm text-gray-600">Periode: <?= date('d F Y', strtotime($tgl_awal)) ?> s/d <?= date('d F Y', strtotime($tgl_akhir)) ?></p>
    </div>

    <?php
    // Pre-scan data to detect which columns have values
    $data = []; $cK=false; $cA=false; $cT=false; $cG=false; $cS=false; $cB=false;
    if ($result && $result->num_rows > 0) {
        while ($r = $result->fetch_assoc()) {
            $data[] = $r;
            if (!empty($r['kegiatan_harian_kategori'])) $cK=true;
            if (!empty($r['ketersediaan_air'])) $cA=true;
            if (isset($r['TMA']) && $r['TMA']!==null && $r['TMA']!=='') $cT=true;
            if (!empty($r['status_gulma'])) $cG=true;
            if (!empty($r['kondisi_saluran'])) $cS=true;
            if (!empty($r['bangunan_air'])) $cB=true;
        }
    }
    $hasPemantauan = $cA||$cT||$cG||$cS||$cB;
    ?>
    <table class="w-full border-collapse border border-black text-sm">
        <thead>
            <tr class="bg-gray-200">
                <th class="border border-black px-2 py-2 w-12">No</th>
                <th class="border border-black px-2 py-2 w-32">Tanggal / Shift</th>
                <th class="border border-black px-2 py-2 w-40">Petugas</th>
                <?php if ($cK): ?><th class="border border-black px-2 py-2 w-28">Kategori</th><?php endif; ?>
                <th class="border border-black px-2 py-2">Uraian Kegiatan</th>
                <?php if ($hasPemantauan): ?><th class="border border-black px-2 py-2 w-36">Data Pemantauan</th><?php endif; ?>
                <th class="border border-black px-2 py-2 w-36">Geolokasi</th>
                <th class="border border-black px-2 py-2 w-36">Foto</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($data)): $no=1; foreach ($data as $row): ?>
            <tr class="align-top">
                <td class="border border-black px-2 py-1 text-center"><?= $no++ ?></td>
                <td class="border border-black px-2 py-1">
                    <div class="font-bold"><?= date('d/m/Y', strtotime($row['tanggal'])) ?></div>
                    <div class="text-xs uppercase"><?= htmlspecialchars($row['shift']) ?></div>
                </td>
                <td class="border border-black px-2 py-1">
                    <div class="font-bold"><?= htmlspecialchars($row['nama']) ?></div>
                    <div class="text-xs"><?= htmlspecialchars($row['bagian']) ?></div>
                </td>
                <?php if ($cK): ?>
                <td class="border border-black px-2 py-1">
                    <div class="font-bold text-xs"><?= htmlspecialchars($row['kegiatan_harian_kategori'] ?? '-') ?></div>
                    <?php if (($row['kegiatan_harian_kategori'] ?? '')==='Kegiatan Lainnya' && !empty($row['kegiatan_harian_lainnya'])): ?>
                    <div class="text-xs"><?= htmlspecialchars($row['kegiatan_harian_lainnya']) ?></div>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <td class="border border-black px-2 py-1 whitespace-pre-line"><?= htmlspecialchars($row['kegiatan_harian']) ?></td>
                <?php if ($hasPemantauan): ?>
                <td class="border border-black px-2 py-1"><div class="text-xs">
                    <?php if ($cA && !empty($row['ketersediaan_air'])): ?><div>Status Air: <b><?= htmlspecialchars($row['ketersediaan_air']) ?></b></div><?php endif; ?>
                    <?php if ($cT && isset($row['TMA']) && $row['TMA']!==null && $row['TMA']!==''): ?><div>TMA: <b><?= number_format((float)$row['TMA'],2) ?> m</b></div><?php endif; ?>
                    <?php if ($cG && !empty($row['status_gulma'])): ?><div>Gulma: <b><?= htmlspecialchars($row['status_gulma']) ?></b></div><?php endif; ?>
                    <?php if ($cS && !empty($row['kondisi_saluran'])): ?><div>Saluran: <b><?= htmlspecialchars($row['kondisi_saluran']) ?></b></div><?php endif; ?>
                    <?php if ($cB && !empty($row['bangunan_air'])): ?><div>Bangunan Air: <b><?= htmlspecialchars($row['bangunan_air']) ?></b></div><?php endif; ?>
                </div></td>
                <?php endif; ?>
                <td class="border border-black px-2 py-1 text-center">
                    <?php if (!empty($row['latitude']) && !empty($row['longitude'])): ?>
                        <?= htmlspecialchars($row['latitude']) ?>, <?= htmlspecialchars($row['longitude']) ?>
                    <?php else: ?>-<?php endif; ?>
                </td>
                <td class="border border-black px-2 py-1 text-center">
                    <?php if (!empty($row['foto_pemantauan'])): ?>
                        <img src="<?= htmlspecialchars($row['foto_pemantauan']) ?>" alt="Foto" class="h-20 w-auto object-cover mx-auto">
                    <?php else: ?>-<?php endif; ?>
                </td>
            </tr>
            <?php endforeach; else: ?>
            <tr><td colspan="20" class="border border-black px-4 py-4 text-center italic">Tidak ada data.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="mt-12 flex justify-end">
        <div class="text-center w-64">
            <p>Admin Pengelola,</p>
            <br><br><br>
            <p class="font-bold underline"><?= $_SESSION['nama'] ?? 'Admin' ?></p>
        </div>
    </div>

</body>
</html>