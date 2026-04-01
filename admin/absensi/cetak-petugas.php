<?php
require_once '../../config/database.php';
require_once '../../config/session.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../auth/login-v2.php");
    exit;
}

$petugas_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$bulan_filter = isset($_GET['bulan']) ? $_GET['bulan'] : date('Y-m');
$exportExcel = isset($_GET['excel']) && $_GET['excel'] === '1';

// Hitung tanggal awal dan akhir bulan
$tgl_awal = $bulan_filter . '-01';
$tgl_akhir = date('Y-m-t', strtotime($tgl_awal));
$nama_bulan = date('F Y', strtotime($tgl_awal));
$jumlah_hari = date('t', strtotime($tgl_awal));

$is_matrix = ($petugas_id <= 0);

if ($is_matrix) {
    // Mode Rekap Semua Petugas (Matrix)
    $query_petugas = "SELECT p.id, p.nama, p.nip FROM petugas p";
    if (isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] != '' && $_SESSION['bagian_id'] != null) {
        $query_petugas .= " WHERE p.bagian_id = " . (int)$_SESSION['bagian_id'];
    }
    $resPetugas = $conn->query($query_petugas);
    $petugas_list = [];
    while($p = $resPetugas->fetch_assoc()) {
        $petugas_list[] = $p;
    }

    $query_absen = "SELECT a.petugas_id, DAY(a.tanggal) as tgl, a.jam_masuk, a.jam_keluar, a.status 
                    FROM absensi a 
                    WHERE a.tanggal BETWEEN '$tgl_awal' AND '$tgl_akhir'";
    $resAbsen = $conn->query($query_absen);
    $absensi_matrix = [];
    while($a = $resAbsen->fetch_assoc()) {
        $absensi_matrix[$a['petugas_id']][$a['tgl']] = $a;
    }
} else {
    // Mode Rekap Per Petugas (Existing)
    $stmtPetugas = $conn->prepare("SELECT p.* FROM petugas p WHERE p.id = ?");
    $stmtPetugas->bind_param("i", $petugas_id);
    $stmtPetugas->execute();
    $petugas = $stmtPetugas->get_result()->fetch_assoc();

    if (!$petugas) {
        header("Location: index.php");
        exit;
    }

    $stmtAbsen = $conn->prepare("SELECT * FROM absensi WHERE petugas_id = ? AND tanggal BETWEEN ? AND ? ORDER BY tanggal ASC");
    $stmtAbsen->bind_param("iss", $petugas_id, $tgl_awal, $tgl_akhir);
    $stmtAbsen->execute();
    $resAbsen = $stmtAbsen->get_result();

    $total_hadir = 0;
    $total_izin = 0;
    $total_sakit = 0;
    $total_lupa = 0;
    $total_tidak_masuk = 0;
    $absensi_list = [];

    while ($row = $resAbsen->fetch_assoc()) {
        $absensi_list[] = $row;
        switch ($row['status']) {
            case 'hadir': $total_hadir++; break;
            case 'izin': $total_izin++; break;
            case 'sakit': $total_sakit++; break;
            case 'lupa absen': $total_lupa++; break;
            case 'tidak masuk': $total_tidak_masuk++; break;
        }
    }
}

// Logic Excel Export
if ($exportExcel) {
    $filename = ($is_matrix ? 'Rekap_Absensi_Bulanan_' : 'Laporan_Absensi_Petugas_') . $bulan_filter . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    ?>
    <table border="1">
        <thead>
            <?php if ($is_matrix): ?>
                <tr>
                    <th colspan="<?= $jumlah_hari + 3 ?>" style="font-size: 16px; font-weight: bold;">REKAP ABSENSI BULANAN - <?= strtoupper($nama_bulan) ?></th>
                </tr>
                <tr>
                    <th>No</th>
                    <th>NIP</th>
                    <th>Nama Petugas</th>
                    <?php for($d=1; $d<=$jumlah_hari; $d++): ?>
                        <th><?= $d ?></th>
                    <?php endfor; ?>
                </tr>
            <?php else: ?>
                <tr>
                    <th colspan="6" style="font-size: 16px; font-weight: bold;">LAPORAN ABSENSI PETUGAS - <?= strtoupper($nama_bulan) ?></th>
                </tr>
                <tr>
                    <th colspan="6">Nama: <?= htmlspecialchars($petugas['nama']) ?> | NIP: <?= htmlspecialchars($petugas['nip']) ?></th>
                </tr>
                <tr>
                    <th>No</th>
                    <th>Tanggal</th>
                    <th>Shift</th>
                    <th>Masuk</th>
                    <th>Keluar</th>
                    <th>Status</th>
                </tr>
            <?php endif; ?>
        </thead>
        <tbody>
            <?php if ($is_matrix): ?>
                <?php $no = 1; foreach($petugas_list as $p): ?>
                    <tr>
                        <td><?= $no++ ?></td>
                        <td style="mso-number-format:'\@'"><?= htmlspecialchars($p['nip']) ?></td>
                        <td><?= htmlspecialchars($p['nama']) ?></td>
                        <?php for($d=1; $d<=$jumlah_hari; $d++): 
                            $data = isset($absensi_matrix[$p['id']][$d]) ? $absensi_matrix[$p['id']][$d] : null;
                            $val = '-';
                            if ($data) {
                                if ($data['status'] == 'hadir') {
                                    $val = ($data['jam_masuk'] ? date('H:i', strtotime($data['jam_masuk'])) : '') . '-' . ($data['jam_keluar'] ? date('H:i', strtotime($data['jam_keluar'])) : '');
                                } else {
                                    $val = strtoupper($data['status']);
                                }
                            }
                        ?>
                            <td><?= $val ?></td>
                        <?php endfor; ?>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <?php $no = 1; foreach($absensi_list as $row): ?>
                    <tr>
                        <td><?= $no++ ?></td>
                        <td><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                        <td><?= htmlspecialchars($row['shift'] ?? '-') ?></td>
                        <td><?= $row['jam_masuk'] ? date('H:i', strtotime($row['jam_masuk'])) : '-' ?></td>
                        <td><?= $row['jam_keluar'] ? date('H:i', strtotime($row['jam_keluar'])) : '-' ?></td>
                        <td><?= strtoupper($row['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Absensi - <?= $is_matrix ? 'Rekap Bulanan' : htmlspecialchars($petugas['nama']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            @page { size: <?= $is_matrix ? 'A3 landscape' : 'A4 portrait' ?>; margin: 1cm; }
            body { -webkit-print-color-adjust: exact; }
            .no-print { display: none; }
        }
        table { border-collapse: collapse; width: 100%; table-layout: fixed; }
        th, td { border: 1px solid black; padding: 4px; font-size: <?= $is_matrix ? '10px' : '12px' ?>; overflow: hidden; text-overflow: ellipsis; }
        .bg-gray-200 { background-color: #e5e7eb !important; }
    </style>
</head>
<body class="bg-white p-4 font-sans text-gray-900" onload="window.print()">

    <div class="text-center mb-6 border-b-2 border-black pb-4">
        <h1 class="text-xl font-bold uppercase tracking-wide">
            <?= $is_matrix ? 'Rekap Absensi Bulanan Petugas' : 'Laporan Absensi Petugas' ?>
        </h1>
        <p class="text-sm text-gray-600">Periode: <?= $nama_bulan ?></p>
    </div>

    <?php if ($is_matrix): ?>
        <!-- Mode Matrix -->
        <table class="w-full" style="table-layout: fixed;">
            <thead>
                <tr class="bg-gray-200">
                    <th style="width:20px" class="whitespace-nowrap">No</th>
                    <th style="width:65px" class="whitespace-nowrap">NIP</th>
                    <th style="width:110px" class="whitespace-nowrap">Nama Petugas</th>
                    <?php for($d=1; $d<=$jumlah_hari; $d++): ?>
                        <th class="w-10" style="width: 25px;"><?= $d ?></th>
                    <?php endfor; ?>
                </tr>
            </thead>
            <tbody>
                <?php $no = 1; foreach($petugas_list as $p): ?>
                    <tr>
                        <td class="text-center whitespace-nowrap"><?= $no++ ?></td>
                        <td class="whitespace-nowrap"><?= htmlspecialchars($p['nip']) ?></td>
                        <td class="font-bold whitespace-nowrap"><?= htmlspecialchars($p['nama']) ?></td>
                        <?php for($d=1; $d<=$jumlah_hari; $d++): 
                            $data = isset($absensi_matrix[$p['id']][$d]) ? $absensi_matrix[$p['id']][$d] : null;
                            $cell_content = '-';
                            $bg_class = '';
                            if ($data) {
                                if ($data['status'] == 'hadir') {
                                    $cell_content = ($data['jam_masuk'] ? date('H:i', strtotime($data['jam_masuk'])) : '') . '<br>' . ($data['jam_keluar'] ? date('H:i', strtotime($data['jam_keluar'])) : '');
                                    $bg_class = 'bg-green-50';
                                } else {
                                    $cell_content = strtoupper(substr($data['status'], 0, 1));
                                    switch($data['status']) {
                                        case 'izin': $bg_class = 'bg-yellow-50'; break;
                                        case 'sakit': $bg_class = 'bg-purple-50'; break;
                                        case 'tidak masuk': $bg_class = 'bg-red-50'; break;
                                    }
                                }
                            }
                        ?>
                            <td class="text-center <?= $bg_class ?>" style="font-size: 8px; line-height: 1;">
                                <?= $cell_content ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="mt-4 text-[10px]">
            <p><strong>Keterangan:</strong> H: Hadir, I: Izin, S: Sakit, T: Tidak Masuk, L: Lupa Absen</p>
        </div>

    <?php else: ?>
        <!-- Mode Single Petugas (Existing) -->
        <div class="mb-6 text-sm">
            <table class="w-full !border-0">
                <tr class="!border-0">
                    <td class="w-24 font-bold !border-0">Nama</td>
                    <td class="w-4 !border-0">:</td>
                    <td class="!border-0"><?= htmlspecialchars($petugas['nama']) ?></td>
                </tr>
                <tr class="!border-0">
                    <td class="font-bold !border-0">NIP</td>
                    <td class="!border-0">:</td>
                    <td class="!border-0"><?= htmlspecialchars($petugas['nip']) ?></td>
                </tr>
                <tr class="!border-0">
                    <td class="font-bold !border-0">Jabatan</td>
                    <td class="!border-0">:</td>
                    <td class="!border-0"><?= htmlspecialchars($petugas['jabatan'] ?? '-') ?></td>
                </tr>
                <tr class="!border-0">
                    <td class="font-bold !border-0">Bagian</td>
                    <td class="!border-0">:</td>
                    <td class="!border-0"><?= htmlspecialchars($petugas['bagian'] ?? '-') ?></td>
                </tr>
            </table>
        </div>

        <div class="mb-6">
            <p class="font-bold text-sm mb-2">Rekap Kehadiran:</p>
            <table class="text-sm w-auto">
                <tr>
                    <td class="px-3 py-1 bg-green-100 font-bold">Hadir</td>
                    <td class="px-3 py-1 text-center"><?= $total_hadir ?></td>
                    <td class="px-3 py-1 bg-yellow-100 font-bold">Izin</td>
                    <td class="px-3 py-1 text-center"><?= $total_izin ?></td>
                    <td class="px-3 py-1 bg-purple-100 font-bold">Sakit</td>
                    <td class="px-3 py-1 text-center"><?= $total_sakit ?></td>
                    <td class="px-3 py-1 bg-orange-100 font-bold">Lupa Absen</td>
                    <td class="px-3 py-1 text-center"><?= $total_lupa ?></td>
                    <td class="px-3 py-1 bg-red-100 font-bold">Tidak Masuk</td>
                    <td class="px-3 py-1 text-center"><?= $total_tidak_masuk ?></td>
                </tr>
            </table>
        </div>

        <table class="w-full border-collapse border border-black text-sm">
            <thead>
                <tr class="bg-gray-200">
                    <th class="px-2 py-2">No</th>
                    <th class="px-2 py-2">Tanggal</th>
                    <th class="px-2 py-2">Hari</th>
                    <th class="px-2 py-2">Shift</th>
                    <th class="px-2 py-2">Masuk</th>
                    <th class="px-2 py-2">Keluar</th>
                    <th class="px-2 py-2">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $no = 1;
                if (count($absensi_list) > 0):
                    foreach ($absensi_list as $row): 
                ?>
                <tr>
                    <td class="text-center"><?= $no++ ?></td>
                    <td><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                    <td><?= date('l', strtotime($row['tanggal'])) ?></td>
                    <td class="capitalize text-center"><?= $row['shift'] ?? '-' ?></td>
                    <td class="text-center">
                        <?= $row['jam_masuk'] ? date('H:i', strtotime($row['jam_masuk'])) : '-' ?>
                    </td>
                    <td class="text-center">
                        <?= $row['jam_keluar'] ? date('H:i', strtotime($row['jam_keluar'])) : '-' ?>
                    </td>
                    <td class="text-center uppercase text-xs font-bold">
                        <?= $row['status'] ?>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr>
                    <td colspan="7" class="px-4 py-4 text-center italic">Tidak ada data.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="mt-12 flex justify-between">
        <div class="text-center w-64">
            <p><?= $is_matrix ? '' : 'Petugas,' ?></p>
            <br><br><br>
            <p class="font-bold underline"><?= $is_matrix ? '' : htmlspecialchars($petugas['nama']) ?></p>
            <p class="text-xs"><?= $is_matrix ? '' : 'NIP. ' . htmlspecialchars($petugas['nip']) ?></p>
        </div>
        <div class="text-center w-64">
            <p>Admin Pengelola,</p>
            <br><br><br>
            <p class="font-bold underline"><?= $_SESSION['nama'] ?? 'Admin' ?></p>
        </div>
    </div>

</body>
</html>
