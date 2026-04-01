<?php
require_once '../../config/database.php';
require_once '../../config/session.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../auth/login-v2.php");
    exit;
}

// Filters
$bulan_filter = isset($_GET['bulan']) ? $_GET['bulan'] : date('Y-m');
$bagian_filter = isset($_GET['bagian']) ? (int)$_GET['bagian'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Generate dates for the month
$tgl_awal = $bulan_filter . '-01';
$tgl_akhir = date('Y-m-t', strtotime($tgl_awal));
$nama_bulan = date('F Y', strtotime($tgl_awal));
$jumlah_hari = (int)date('t', strtotime($tgl_awal));

$dates = [];
for ($d = 1; $d <= $jumlah_hari; $d++) {
    $dates[] = $bulan_filter . '-' . str_pad($d, 2, '0', STR_PAD_LEFT);
}

// Check if admin has bagian restriction
$adminBagianId = (isset($_SESSION['bagian_id']) && !empty($_SESSION['bagian_id'])) ? (int)$_SESSION['bagian_id'] : 0;

// Get bagian list
$bagian_list = [];
$qBagian = "SELECT id, nama_bagian FROM bagian ORDER BY nama_bagian ASC";
$resBagian = $conn->query($qBagian);
if ($resBagian) {
    while ($b = $resBagian->fetch_assoc()) {
        $bagian_list[] = $b;
    }
}

// Determine effective bagian filter
$effectiveBagian = $bagian_filter;
if ($adminBagianId > 0) {
    $effectiveBagian = $adminBagianId;
}

// Build petugas query
$query_petugas = "SELECT p.id, p.nama, p.nip FROM petugas p";
$where_petugas = [];

if ($effectiveBagian > 0) {
    $where_petugas[] = "p.bagian_id = " . $effectiveBagian;
}

if ($search !== '') {
    $searchEsc = $conn->real_escape_string($search);
    $where_petugas[] = "(p.nama LIKE '%{$searchEsc}%' OR p.nip LIKE '%{$searchEsc}%')";
}

if (!empty($where_petugas)) {
    $query_petugas .= " WHERE " . implode(" AND ", $where_petugas);
}
$query_petugas .= " ORDER BY p.nama ASC";

$resPetugas = $conn->query($query_petugas);
$petugas_list = [];
while ($p = $resPetugas->fetch_assoc()) {
    $petugas_list[] = $p;
}

// Build absensi matrix
$absensi_matrix = [];
if (!empty($petugas_list)) {
    $petugasIds = array_column($petugas_list, 'id');
    $idsStr = implode(',', $petugasIds);

    $query_absen = "SELECT petugas_id, tanggal, status FROM absensi 
                    WHERE tanggal BETWEEN '{$conn->real_escape_string($tgl_awal)}' AND '{$conn->real_escape_string($tgl_akhir)}'
                    AND petugas_id IN ({$idsStr})";
    $resAbsen = $conn->query($query_absen);
    if ($resAbsen) {
        while ($a = $resAbsen->fetch_assoc()) {
            $absensi_matrix[$a['petugas_id']][$a['tanggal']] = $a;
        }
    }
}

// Get selected bagian name
$namaBagianFilter = 'Semua Bagian';
if ($effectiveBagian > 0) {
    foreach ($bagian_list as $b) {
        if ((int)$b['id'] === $effectiveBagian) {
            $namaBagianFilter = $b['nama_bagian'];
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Full Absensi   <?= htmlspecialchars($nama_bulan) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            @page { size: A3 landscape; margin: 0.3cm; }
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none; }
        }
        body { margin: 0; padding: 2px; }
        table { border-collapse: collapse; width: 100%; table-layout: fixed; }
        th, td { border: 1px solid #333; padding: 0px 1px; font-size: 6.5px; text-align: center; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; }
        thead th { background: #e5e7eb !important; font-size: 6px; padding: 1px; }
        .col-nip { text-align: left; padding-left: 2px; padding-right: 0; white-space: nowrap; font-size: 6px; font-family: monospace; width: 55px; }
        .col-nama { text-align: left; padding-left: 2px; padding-right: 0; white-space: nowrap; font-weight: bold; font-size: 6px; width: 95px; }
        .status-hadir { background-color: #16a34a !important; color: white !important; }
        .status-absen-masuk { background-color: #2563eb !important; color: white !important; }
        .status-izin { background-color: #facc15 !important; color: black !important; }
        .status-sakit { background-color: #9333ea !important; color: white !important; }
        .status-tidak-hadir { background-color: #dc2626 !important; color: white !important; }
        .status-lupa { background-color: #ea580c !important; color: white !important; }
    </style>
</head>
<body class="bg-white p-1 font-sans text-gray-900" onload="window.print()">

    <div class="no-print mb-4 flex gap-2">
        <button onclick="window.print()" class="bg-blue-600 text-white px-4 py-2 rounded text-sm font-bold">
            <i class="fas fa-print mr-1"></i> Print
        </button>
        <button onclick="window.close()" class="bg-gray-500 text-white px-4 py-2 rounded text-sm font-bold">Tutup</button>
    </div>

    <div class="text-center mb-1 border-b border-black pb-1">
        <h1 class="text-sm font-bold uppercase">Rekap Full Absensi Petugas</h1>
        <p style="font-size:8px" class="text-gray-600">Periode: <?= htmlspecialchars($nama_bulan) ?>   <?= htmlspecialchars($namaBagianFilter) ?></p>
    </div>

    <table>
        <thead>
            <tr class="bg-gray-200">
                <th style="width:15px" class="whitespace-nowrap">No</th>
                <th style="width:55px" class="whitespace-nowrap" style="text-align:left; padding-left:2px">NIP</th>
                <th style="width:95px" class="whitespace-nowrap" style="text-align:left; padding-left:2px">Nama Petugas</th>
                <?php foreach ($dates as $date): ?>
                    <th style="width:16px; padding:0" class="<?= (date('N', strtotime($date)) >= 6) ? 'bg-red-100' : '' ?>">
                        <?= date('d', strtotime($date)) ?>
                    </th>
                <?php endforeach; ?>
                <th style="width:22px" class="bg-green-100">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php $no = 1; foreach ($petugas_list as $p): 
                $total_hadir = 0;
            ?>
            <tr>
                <td class="whitespace-nowrap"><?= $no++ ?></td>
                <td class="col-nip"><?= htmlspecialchars($p['nip']) ?></td>
                <td class="col-nama"><?= htmlspecialchars($p['nama']) ?></td>
                <?php foreach ($dates as $date):
                    $data = isset($absensi_matrix[$p['id']][$date]) ? $absensi_matrix[$p['id']][$date] : null;
                    $cell = '';
                    $bgClass = '';

                    if ($data) {
                        switch ($data['status']) {
                            case 'hadir':
                                $cell = 'H'; $bgClass = 'status-hadir'; $total_hadir++;
                                break;
                            case 'absen masuk':
                                $cell = 'AM'; $bgClass = 'status-absen-masuk';
                                break;
                            case 'izin':
                                $cell = 'I'; $bgClass = 'status-izin';
                                break;
                            case 'sakit':
                                $cell = 'S'; $bgClass = 'status-sakit';
                                break;
                            case 'tidak hadir':
                                $cell = 'A'; $bgClass = 'status-tidak-hadir';
                                break;
                            case 'lupa absen':
                                $cell = 'L'; $bgClass = 'status-lupa';
                                break;
                            default:
                                $cell = '-';
                                break;
                        }
                    }
                ?>
                    <td class="<?= $bgClass ?>"><?= $cell ?></td>
                <?php endforeach; ?>
                <td class="font-bold bg-green-100"><?= $total_hadir ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="mt-1 text-[7px] flex justify-between items-start">
        <div class="flex gap-3">
            <p><strong>Keterangan:</strong></p>
            <div class="flex items-center gap-1"><span class="inline-block w-3 h-3 status-absen-masuk"></span> AM: Absen Masuk</div>
            <div class="flex items-center gap-1"><span class="inline-block w-3 h-3 status-hadir"></span> H: Hadir</div>
            <div class="flex items-center gap-1"><span class="inline-block w-3 h-3 status-izin"></span> I: Izin</div>
            <div class="flex items-center gap-1"><span class="inline-block w-3 h-3 status-sakit"></span> S: Sakit</div>
            <div class="flex items-center gap-1"><span class="inline-block w-3 h-3 status-tidak-hadir"></span> A: Tidak Hadir</div>
            <div class="flex items-center gap-1"><span class="inline-block w-3 h-3 status-lupa"></span> L: Lupa Absen</div>
        </div>
        <div class="text-center w-48">
            <p>Admin Pengelola,</p>
            <br><br><br>
            <p class="font-bold underline"><?= $_SESSION['nama'] ?? 'Admin' ?></p>
        </div>
    </div>

</body>
</html>
