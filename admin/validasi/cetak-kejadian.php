<?php
require_once '../../config/database.php';
require_once '../../config/session.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../auth/login-v2.php");
    exit;
}

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$bulan_filter = isset($_GET['bulan']) ? $_GET['bulan'] : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $bulan_filter)) {
    $bulan_filter = date('Y-m');
}

$tgl_awal = $bulan_filter . '-01';
$tgl_akhir = date('Y-m-t', strtotime($tgl_awal));
$nama_bulan = date('F Y', strtotime($tgl_awal));

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

$query = "SELECT k.*, p.nama, p.nip, $selectBagian, p.alamat, k.foto, k.latitude, k.longitude 
          FROM kejadian k 
          JOIN petugas p ON k.petugas_id = p.id 
          $joinBagian
          WHERE k.status = 'disetujui' AND DATE(k.created_at) BETWEEN '$tgl_awal' AND '$tgl_akhir'";

// Filter berdasarkan bagian_id admin jika bukan super admin (bagian_id != null)
if ($petugasBagianIdColumnExists && isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] !== '' && $_SESSION['bagian_id'] !== null) {
    $admin_bagian_id = (int)$_SESSION['bagian_id'];
    $query .= " AND p.bagian_id = $admin_bagian_id";
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

$query .= " ORDER BY k.created_at DESC";
$result = $conn->query($query);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Kejadian</title>
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
        <h1 class="text-xl font-bold uppercase tracking-wide">Laporan Kejadian</h1>
        <p class="text-sm text-gray-600">Periode: <?= htmlspecialchars($nama_bulan) ?></p>
    </div>

    <?php
    $data=[]; $hasJL=false;
    if ($result && $result->num_rows>0) { while($r=$result->fetch_assoc()){ $data[]=$r; if(!empty($r['jenis_laporan'])) $hasJL=true; } }
    ?>
    <table class="w-full border-collapse border border-black text-sm">
        <thead>
            <tr class="bg-gray-200">
                <th class="border border-black px-2 py-2 w-12">No</th>
                <th class="border border-black px-2 py-2 w-32">Waktu</th>
                <th class="border border-black px-2 py-2 w-40">Pelapor</th>
                <th class="border border-black px-2 py-2 w-48">Bagian</th>
                <?php if($hasJL): ?><th class="border border-black px-2 py-2">Jenis Laporan</th><?php endif; ?>
                <th class="border border-black px-2 py-2">Deskripsi</th>
                <th class="border border-black px-2 py-2 w-32">Foto Kejadian</th>
                <th class="border border-black px-2 py-2 w-32">Geotagging Lokasi</th>
            </tr>
        </thead>
        <tbody>
            <?php $no=1; if(!empty($data)): foreach($data as $row): ?>
            <tr class="align-top">
                <td class="border border-black px-2 py-1 text-center"><?= $no++ ?></td>
                <td class="border border-black px-2 py-1 text-center">
                    <div class="text-xs"><?= date('d/m/Y', strtotime($row['created_at'])) ?></div>
                    <div class="text-xs font-bold"><?= date('H:i', strtotime($row['created_at'])) ?></div>
                </td>
                <td class="border border-black px-2 py-1">
                    <div class="font-bold"><?= htmlspecialchars($row['nama']) ?></div>
                    <div class="text-xs"><?= htmlspecialchars($row['nip']) ?></div>
                </td>
                <td class="border border-black px-2 py-1">
                    <div class="font-bold"><?= htmlspecialchars($row['bagian']) ?></div>
                </td>
                <?php if($hasJL): ?>
                <td class="border border-black px-2 py-1">
                    <?php $jl=$row['jenis_laporan']??''; if($jl!==''): ?>
                    <div class="font-bold text-xs"><?= htmlspecialchars($jl) ?></div>
                    <?php if($jl==='Laporan Lainnya'&&!empty($row['laporan_lainnya_text'])): ?><div class="text-xs"><?= htmlspecialchars($row['laporan_lainnya_text']) ?></div><?php endif; ?>
                    <?php if($jl==='Laporan Kerusakan Bangunan'&&!empty($row['jenis_kerusakan_bangunan'])): ?><div class="text-xs"><?= htmlspecialchars($row['jenis_kerusakan_bangunan']) ?></div><?php endif; ?>
                    <?php else: ?>-<?php endif; ?>
                </td>
                <?php endif; ?>
                <td class="border border-black px-2 py-1 whitespace-pre-line"><?= htmlspecialchars($row['deskripsi']) ?></td>
                <td class="border border-black px-2 py-1 text-center">
                    <?php if(!empty($row['foto'])): ?><img src="<?= htmlspecialchars($row['foto']) ?>" alt="Foto" class="w-24 h-auto mx-auto"><?php else: ?>-<?php endif; ?>
                </td>
                <td class="border border-black px-2 py-1 text-center">
                    <?php if(!empty($row['latitude'])&&!empty($row['longitude'])): ?><?= htmlspecialchars($row['latitude']) ?>, <?= htmlspecialchars($row['longitude']) ?><?php else: ?>-<?php endif; ?>
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
