<?php
require_once '../../config/database.php';
require_once '../../config/session.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../auth/login-v2.php");
    exit;
}

// Ambil Filter dari URL (sama seperti index.php)
$bulan_filter = isset($_GET['bulan']) ? $_GET['bulan'] : date('Y-m');
$shift_filter = isset($_GET['shift']) ? $_GET['shift'] : 'semua';
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';

$tgl_awal = $bulan_filter . '-01';
$tgl_akhir = date('Y-m-t', strtotime($tgl_awal));
$nama_bulan = date('F Y', strtotime($tgl_awal));

// Detect columns
$shiftTableExists = false;
$checkShiftTable = $conn->query("SHOW TABLES LIKE 'shift'");
if ($checkShiftTable && $checkShiftTable->num_rows > 0) $shiftTableExists = true;

$absensiShiftIdExists = false;
$checkAbsensiShiftId = $conn->query("SHOW COLUMNS FROM absensi LIKE 'shift_id'");
if ($checkAbsensiShiftId && $checkAbsensiShiftId->num_rows > 0) $absensiShiftIdExists = true;

$bagianTableExists = false;
$checkBagianTable = $conn->query("SHOW TABLES LIKE 'bagian'");
if ($checkBagianTable && $checkBagianTable->num_rows > 0) $bagianTableExists = true;

$petugasBagianColumnExists = false;
$checkPetugasBagian = $conn->query("SHOW COLUMNS FROM petugas LIKE 'bagian'");
if ($checkPetugasBagian && $checkPetugasBagian->num_rows > 0) $petugasBagianColumnExists = true;

$petugasBagianIdColumnExists = false;
$checkPetugasBagianId = $conn->query("SHOW COLUMNS FROM petugas LIKE 'bagian_id'");
if ($checkPetugasBagianId && $checkPetugasBagianId->num_rows > 0) $petugasBagianIdColumnExists = true;

$hasFotoMasuk = false;
$checkFotoMasuk = $conn->query("SHOW COLUMNS FROM absensi LIKE 'foto_masuk'");
if ($checkFotoMasuk && $checkFotoMasuk->num_rows > 0) $hasFotoMasuk = true;

// Build query (same as index.php but WITHOUT pagination)
$joinShift = $shiftTableExists ? " LEFT JOIN jadwal_petugas jp ON a.jadwal_id = jp.id LEFT JOIN shift s ON jp.shift_id = s.id " : "";
$selectShift = $shiftTableExists ? "s.nama_shift" : "'-'";
$joinBagian = ($bagianTableExists && $petugasBagianIdColumnExists) ? " LEFT JOIN bagian b ON p.bagian_id = b.id " : "";
$selectBagian = $petugasBagianColumnExists
    ? "p.bagian AS bagian"
    : (($bagianTableExists && $petugasBagianIdColumnExists) ? "COALESCE(b.nama_bagian, '-') AS bagian" : "'-' AS bagian");
$selectFotoMasuk = $hasFotoMasuk ? "a.foto_masuk AS foto_absen" : "a.foto_absen AS foto_absen";

$sql = "SELECT 
            a.id, a.petugas_id, a.tanggal, 
            $selectShift AS shift,
            a.jam_masuk, a.jam_keluar,
            $selectFotoMasuk,
            a.foto_keluar,
            a.status,
            p.nama, p.nip, $selectBagian
        FROM absensi a 
        JOIN petugas p ON a.petugas_id = p.id 
        $joinShift
        $joinBagian
        WHERE a.tanggal BETWEEN '$tgl_awal' AND '$tgl_akhir'";

// Filter by bagian_id if set in session
if (isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] != '') {
    $sql .= " AND p.bagian_id = " . (int)$_SESSION['bagian_id'];
}

// Filter Shift
if ($shift_filter != 'semua') {
    if ($shiftTableExists && $absensiShiftIdExists) {
        $sql .= " AND COALESCE(s.nama_shift, a.shift) = '$shift_filter'";
    } else {
        $sql .= " AND a.shift = '$shift_filter'";
    }
}

// Filter Search
if ($search) {
    $sql .= " AND (p.nama LIKE '%$search%' OR p.nip LIKE '%$search%' OR a.status LIKE '%$search%')";
}

$sql .= " ORDER BY a.tanggal DESC, a.jam_masuk DESC";

// NO LIMIT — cetak semua data sesuai filter
$result = $conn->query($sql);

// Get bagian name for header
$namaBagian = '';
if (isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] != '') {
    $resBag = $conn->query("SELECT nama_bagian FROM bagian WHERE id = " . (int)$_SESSION['bagian_id']);
    if ($resBag && $b = $resBag->fetch_assoc()) $namaBagian = $b['nama_bagian'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Rekap Absensi — <?= htmlspecialchars($nama_bulan) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            @page { size: A4 landscape; margin: 0.8cm; }
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none; }
        }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #333; padding: 3px 5px; font-size: 10px; }
        thead th { background: #e5e7eb !important; font-size: 9px; text-transform: uppercase; }
        .foto-cell { vertical-align: middle; }
        .foto-cell img { width: 35px; height: 35px; object-fit: cover; border-radius: 3px; display: block; margin: 0 auto; }
        .status-hadir { background-color: #dcfce7 !important; color: #166534 !important; }
        .status-absen-masuk { background-color: #dbeafe !important; color: #1e40af !important; }
        .status-izin { background-color: #fef9c3 !important; color: #854d0e !important; }
        .status-sakit { background-color: #f3e8ff !important; color: #6b21a8 !important; }
        .status-tidak-hadir { background-color: #fee2e2 !important; color: #991b1b !important; }
        .status-lupa { background-color: #ffedd5 !important; color: #9a3412 !important; }
    </style>
</head>
<body class="bg-white p-4 font-sans text-gray-900" onload="window.print()">

    <div class="no-print mb-4 flex gap-2">
        <button onclick="window.print()" class="bg-blue-600 text-white px-4 py-2 rounded text-sm font-bold">
            <i class="fas fa-print mr-1"></i> Print
        </button>
        <button onclick="window.close()" class="bg-gray-500 text-white px-4 py-2 rounded text-sm font-bold">Tutup</button>
    </div>

    <div class="text-center mb-4 border-b-2 border-black pb-3">
        <h1 class="text-lg font-bold uppercase tracking-wide">Rekap Data Absensi</h1>
        <p class="text-sm text-gray-600">Periode: <?= htmlspecialchars($nama_bulan) ?><?= $namaBagian ? ' — ' . htmlspecialchars($namaBagian) : '' ?></p>
        <?php if ($shift_filter != 'semua'): ?>
        <p class="text-xs text-gray-500">Shift: <?= htmlspecialchars($shift_filter) ?></p>
        <?php endif; ?>
    </div>

    <table>
        <thead>
            <tr>
                <th class="text-center" style="width:30px">No</th>
                <th>Tanggal</th>
                <th>Nama / NIP</th>
                <th>Bagian</th>
                <th>Shift</th>
                <th class="text-center">Masuk</th>
                <th class="text-center">Keluar</th>
                <th class="text-center">Foto Masuk</th>
                <th class="text-center">Foto Keluar</th>
                <th class="text-center">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            if ($result && $result->num_rows > 0):
                while ($row = $result->fetch_assoc()):
                    $statusClass = '';
                    switch ($row['status']) {
                        case 'hadir': $statusClass = 'status-hadir'; break;
                        case 'absen masuk': $statusClass = 'status-absen-masuk'; break;
                        case 'izin': $statusClass = 'status-izin'; break;
                        case 'sakit': $statusClass = 'status-sakit'; break;
                        case 'tidak hadir': $statusClass = 'status-tidak-hadir'; break;
                        case 'lupa absen': $statusClass = 'status-lupa'; break;
                    }
            ?>
            <tr>
                <td class="text-center"><?= $no++ ?></td>
                <td class="whitespace-nowrap"><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                <td>
                    <div class="font-bold"><?= htmlspecialchars($row['nama']) ?></div>
                    <div style="font-size:8px; color:#666;"><?= htmlspecialchars($row['nip']) ?></div>
                </td>
                <td><?= htmlspecialchars($row['bagian'] ?? '-') ?></td>
                <td class="text-center uppercase"><?= htmlspecialchars($row['shift'] ?? '-') ?></td>
                <td class="text-center font-bold" style="color:#166534"><?= $row['jam_masuk'] ? date('H:i', strtotime($row['jam_masuk'])) : '-' ?></td>
                <td class="text-center font-bold" style="color:#991b1b"><?= $row['jam_keluar'] ? date('H:i', strtotime($row['jam_keluar'])) : '-' ?></td>
                <td class="text-center foto-cell">
                    <?php if (!empty($row['foto_absen'])): ?>
                        <img src="<?= $row['foto_absen'] ?>" alt="">
                    <?php else: ?>
                        <span style="color:#999">-</span>
                    <?php endif; ?>
                </td>
                <td class="text-center foto-cell">
                    <?php if (!empty($row['foto_keluar'])): ?>
                        <img src="<?= $row['foto_keluar'] ?>" alt="">
                    <?php else: ?>
                        <span style="color:#999">-</span>
                    <?php endif; ?>
                </td>
                <td class="text-center font-bold uppercase <?= $statusClass ?>"><?= htmlspecialchars($row['status']) ?></td>
            </tr>
            <?php endwhile; else: ?>
            <tr><td colspan="10" class="text-center py-4">Tidak ada data absensi.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="mt-6 flex justify-end">
        <div class="text-center w-48" style="font-size:10px">
            <p>Admin Pengelola,</p>
            <br><br><br>
            <p class="font-bold underline"><?= $_SESSION['nama'] ?? 'Admin' ?></p>
        </div>
    </div>

</body>
</html>