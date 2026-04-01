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

// Get bagian list for filter
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
    $effectiveBagian = $adminBagianId; // admin bagian can only see their own
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

// Excel export
if (isset($_GET['excel']) && $_GET['excel'] === '1') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="Full_Absensi_' . $bulan_filter . '.xls"');
    echo '<html><head><meta charset="UTF-8"></head><body>';
    echo '<table border="1">';
    echo '<thead><tr>';
    echo '<th>No</th><th>NIP</th><th>Nama</th>';
    foreach ($dates as $date) {
        echo '<th>' . date('d', strtotime($date)) . '</th>';
    }
    echo '<th>Total Hadir</th>';
    echo '</tr></thead>';
    echo '<tbody>';
    $no = 1;
    foreach ($petugas_list as $p) {
        $total_hadir = 0;
        echo '<tr>';
        echo '<td>' . $no++ . '</td>';
        echo '<td style="mso-number-format:\'\\@\'">' . htmlspecialchars($p['nip']) . '</td>';
        echo '<td>' . htmlspecialchars($p['nama']) . '</td>';
        foreach ($dates as $date) {
            $data = isset($absensi_matrix[$p['id']][$date]) ? $absensi_matrix[$p['id']][$date] : null;
            $val = '-';
            $bgColor = '';
            if ($data) {
                switch ($data['status']) {
                    case 'hadir': 
                        $val = 'H'; $total_hadir++; $bgColor = '#dcfce7'; break;
                    case 'absen masuk': 
                        $val = 'AM'; $bgColor = '#dbeafe'; break;
                    case 'izin': 
                        $val = 'I'; $bgColor = '#fef9c3'; break;
                    case 'sakit': 
                        $val = 'S'; $bgColor = '#f3e8ff'; break;
                    case 'tidak hadir': 
                        $val = 'A'; $bgColor = '#fee2e2'; break;
                    case 'lupa absen': 
                        $val = 'L'; $bgColor = '#ffedd5'; break;
                    default: 
                        $val = strtoupper(substr($data['status'], 0, 1)); break;
                }
            }
            echo '<td style="background-color: ' . $bgColor . '; text-align: center;">' . $val . '</td>';
        }
        echo '<td>' . $total_hadir . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<br><table>';
    echo '<tr><td colspan="3"><b>Keterangan:</b></td></tr>';
    echo '<tr><td style="background-color: #dbeafe; text-align: center;"><b>AM</b></td><td>:</td><td>Absen Masuk</td></tr>';
    echo '<tr><td style="background-color: #dcfce7; text-align: center;"><b>H</b></td><td>:</td><td>Hadir</td></tr>';
    echo '<tr><td style="background-color: #fef9c3; text-align: center;"><b>I</b></td><td>:</td><td>Izin</td></tr>';
    echo '<tr><td style="background-color: #f3e8ff; text-align: center;"><b>S</b></td><td>:</td><td>Sakit</td></tr>';
    echo '<tr><td style="background-color: #fee2e2; text-align: center;"><b>A</b></td><td>:</td><td>Tidak Hadir</td></tr>';
    echo '<tr><td style="background-color: #ffedd5; text-align: center;"><b>L</b></td><td>:</td><td>Lupa Absen</td></tr>';
    echo '</table>';
    echo '</body></html>';
    exit;
}

// Get selected bagian name for display
$namaBagianFilter = 'Semua Bagian';
if ($effectiveBagian > 0) {
    foreach ($bagian_list as $b) {
        if ((int)$b['id'] === $effectiveBagian) {
            $namaBagianFilter = $b['nama_bagian'];
            break;
        }
    }
}

require_once '../layout/header.php';
require_once '../layout/sidebar.php';
?>

<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">Full Absensi</h1>
        <p class="text-sm text-gray-500">Rekap absensi seluruh petugas — <?= htmlspecialchars($nama_bulan) ?> — <?= htmlspecialchars($namaBagianFilter) ?></p>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
        <a href="?bulan=<?= $bulan_filter ?>&bagian=<?= $effectiveBagian ?>&search=<?= urlencode($search) ?>&excel=1" 
           class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg shadow flex items-center gap-2 text-sm font-bold">
            <i class="fas fa-file-excel"></i> Export Excel
        </a>
        <a href="cetak-full-absensi.php?bulan=<?= $bulan_filter ?>&bagian=<?= $effectiveBagian ?>&search=<?= urlencode($search) ?>" target="_blank" 
           class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg shadow flex items-center gap-2 text-sm font-bold">
            <i class="fas fa-print"></i> Cetak PDF
        </a>
    </div>
</div>

<!-- Filters -->
<div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 mb-6">
    <form action="" method="GET" class="flex flex-col md:flex-row gap-4 items-end flex-wrap">
        <div class="w-full md:w-auto">
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Bulan</label>
            <input type="month" name="bulan" value="<?= $bulan_filter ?>" class="border rounded p-2 w-full text-sm">
        </div>

        <?php if ($adminBagianId <= 0): ?>
        <div class="w-full md:w-auto">
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Bagian</label>
            <select name="bagian" class="border rounded p-2 w-full text-sm">
                <option value="0">Semua Bagian</option>
                <?php foreach ($bagian_list as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $bagian_filter == $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['nama_bagian']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="w-full md:w-auto">
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Cari</label>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Nama / NIP..." class="border rounded p-2 w-full text-sm">
        </div>

        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded shadow text-sm font-bold w-full md:w-auto">
            <i class="fas fa-filter mr-1"></i> Tampilkan
        </button>
    </form>
</div>

<!-- Matrix Table -->
<div class="bg-white rounded-lg shadow overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-xs text-left text-gray-600 border-collapse">
            <thead class="text-[10px] text-gray-700 uppercase bg-gray-100 sticky top-0">
                <tr>
                    <th class="px-2 py-2 border text-center sticky left-0 bg-gray-100 z-10" style="min-width:40px">No</th>
                    <th class="px-2 py-2 border sticky bg-gray-100 z-10" style="min-width:100px; left:40px">NIP</th>
                    <th class="px-2 py-2 border sticky bg-gray-100 z-10" style="min-width:150px; left:140px">Nama</th>
                    <?php foreach ($dates as $date): ?>
                        <th class="px-1 py-2 border text-center <?= (date('N', strtotime($date)) >= 6) ? 'bg-red-50' : '' ?>" style="min-width:28px">
                            <?= date('d', strtotime($date)) ?>
                        </th>
                    <?php endforeach; ?>
                    <th class="px-2 py-2 border text-center bg-green-50" style="min-width:50px">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($petugas_list)): ?>
                    <tr>
                        <td colspan="<?= 4 + $jumlah_hari ?>" class="px-4 py-8 text-center text-gray-500">
                            <i class="fas fa-folder-open text-3xl mb-2 text-gray-300 block"></i>
                            Tidak ada data petugas.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $no = 1; foreach ($petugas_list as $p): 
                        $total_hadir = 0;
                    ?>
                    <tr class="hover:bg-gray-50 border-b">
                        <td class="px-2 py-1 border text-center sticky left-0 bg-white z-10"><?= $no++ ?></td>
                        <td class="px-2 py-1 border sticky bg-white z-10 font-mono text-[10px]" style="left:40px"><?= htmlspecialchars($p['nip']) ?></td>
                        <td class="px-2 py-1 border sticky bg-white z-10 font-semibold whitespace-nowrap" style="left:140px"><?= htmlspecialchars($p['nama']) ?></td>
                        <?php foreach ($dates as $date):
                            $data = isset($absensi_matrix[$p['id']][$date]) ? $absensi_matrix[$p['id']][$date] : null;
                            $cell = '';
                            $cellClass = '';
                            $isWeekend = (date('N', strtotime($date)) >= 6);

                            if ($data) {
                                switch ($data['status']) {
                                    case 'hadir':
                                        $cell = 'H';
                                        $cellClass = 'bg-green-600 text-white font-bold';
                                        $total_hadir++;
                                        break;
                                    case 'absen masuk':
                                        $cell = 'AM';
                                        $cellClass = 'bg-blue-500 text-white font-bold';
                                        break;
                                    case 'izin':
                                        $cell = 'I';
                                        $cellClass = 'bg-yellow-400 text-black font-bold';
                                        break;
                                    case 'sakit':
                                        $cell = 'S';
                                        $cellClass = 'bg-purple-600 text-white font-bold';
                                        break;
                                    case 'tidak hadir':
                                        $cell = 'A';
                                        $cellClass = 'bg-red-600 text-white font-bold';
                                        break;
                                    case 'lupa absen':
                                        $cell = 'L';
                                        $cellClass = 'bg-orange-500 text-white font-bold';
                                        break;
                                    default:
                                        $cell = '-';
                                        $cellClass = 'text-gray-400';
                                        break;
                                }
                            } else {
                                $cell = '';
                                $cellClass = $isWeekend ? 'bg-red-50' : '';
                            }
                        ?>
                            <td class="px-0 py-1 border text-center text-[10px] <?= $cellClass ?>"><?= $cell ?></td>
                        <?php endforeach; ?>
                        <td class="px-2 py-1 border text-center font-bold bg-green-50 text-green-800"><?= $total_hadir ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Legend -->
<div class="mt-4 flex flex-wrap gap-3 text-xs text-gray-600">
    <span class="font-bold">Keterangan:</span>
    <span class="flex items-center gap-1"><span class="inline-block w-4 h-4 bg-blue-500 rounded"></span> AM = Absen Masuk</span>
    <span class="flex items-center gap-1"><span class="inline-block w-4 h-4 bg-green-600 rounded"></span> H = Hadir</span>
    <span class="flex items-center gap-1"><span class="inline-block w-4 h-4 bg-yellow-400 rounded border"></span> I = Izin</span>
    <span class="flex items-center gap-1"><span class="inline-block w-4 h-4 bg-purple-600 rounded"></span> S = Sakit</span>
    <span class="flex items-center gap-1"><span class="inline-block w-4 h-4 bg-red-600 rounded"></span> A = Tidak Hadir</span>
    <span class="flex items-center gap-1"><span class="inline-block w-4 h-4 bg-orange-500 rounded"></span> L = Lupa Absen</span>
</div>

<?php require_once '../layout/footer.php'; ?>
