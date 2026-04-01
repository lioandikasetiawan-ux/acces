<?php
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../auth/login-v2.php");
    exit;
}

$petugas_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$bulan_filter = isset($_GET['bulan']) ? $_GET['bulan'] : date('Y-m');

$exportExcel = isset($_GET['excel']) && $_GET['excel'] === '1';

if ($petugas_id <= 0) {
    header("Location: index.php");
    exit;
}

// Ambil data petugas (data diri murni dari tabel petugas)
$stmtPetugas = $conn->prepare("SELECT p.* FROM petugas p WHERE p.id = ?");
$stmtPetugas->bind_param("i", $petugas_id);
$stmtPetugas->execute();
$petugas = stmtFetchAssoc($stmtPetugas);
$stmtPetugas->close();

if (!$petugas) {
    header("Location: index.php");
    exit;
}

// Hitung tanggal awal dan akhir bulan
$tgl_awal = $bulan_filter . '-01';
$tgl_akhir = date('Y-m-t', strtotime($tgl_awal));
$nama_bulan = date('F Y', strtotime($tgl_awal));

// Query absensi petugas di bulan tersebut
$stmtAbsen = $conn->prepare("
    SELECT a.*, s.nama_shift AS shift
    FROM absensi a
    JOIN jadwal_petugas jp ON a.jadwal_id = jp.id
    JOIN shift s ON jp.shift_id = s.id
    WHERE a.petugas_id = ? AND a.tanggal BETWEEN ? AND ?
    ORDER BY a.tanggal ASC, a.jam_masuk ASC
");
$stmtAbsen->bind_param("iss", $petugas_id, $tgl_awal, $tgl_akhir);
$stmtAbsen->execute();
$absensi_list = stmtFetchAllAssoc($stmtAbsen);
$stmtAbsen->close();

if ($exportExcel) {
    $filename = 'absensi_petugas_' . ($petugas['nip'] ?? $petugas_id) . '_' . $tgl_awal . '_sd_' . $tgl_akhir . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    echo '<table border="1">';
    echo '<thead><tr>';
    echo '<th>No</th>';
    echo '<th>Tanggal</th>';
    echo '<th>Shift</th>';
    echo '<th>Masuk</th>';
    echo '<th>Keluar</th>';
    echo '<th>Status</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    if (!empty($absensi_list)) {
        $no = 1;
        foreach ($absensi_list as $row) {
            echo '<tr>';
            echo '<td>' . $no++ . '</td>';
            echo '<td>' . (!empty($row['tanggal']) ? date('d/m/Y', strtotime($row['tanggal'])) : '-') . '</td>';
            echo '<td>' . htmlspecialchars($row['shift'] ?? '-') . '</td>';
            echo '<td>' . (!empty($row['jam_masuk']) ? date('H:i', strtotime($row['jam_masuk'])) : '-') . '</td>';
            echo '<td>' . (!empty($row['jam_keluar']) ? date('H:i', strtotime($row['jam_keluar'])) : '-') . '</td>';
            echo '<td>' . htmlspecialchars($row['status'] ?? '-') . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="6">Tidak ada data.</td></tr>';
    }

    echo '</tbody></table>';
    exit;
}

$hasLatKeluar = false;
$hasLongKeluar = false;
$checkLatKeluar = $conn->query("SHOW COLUMNS FROM absensi LIKE 'latitude_keluar'");
if ($checkLatKeluar && $checkLatKeluar->num_rows > 0) {
    $hasLatKeluar = true;
}
$checkLongKeluar = $conn->query("SHOW COLUMNS FROM absensi LIKE 'longitude_keluar'");
if ($checkLongKeluar && $checkLongKeluar->num_rows > 0) {
    $hasLongKeluar = true;
}
$hasLokasiKeluar = ($hasLatKeluar && $hasLongKeluar);

// Hitung statistik
$total_absen_masuk = 0;
$total_hadir = 0;
$total_izin = 0;
$total_sakit = 0;
$total_lupa = 0;
$total_tidak_masuk = 0;
foreach ($absensi_list as $row) {
    switch ($row['status']) {
        case 'absen masuk': $total_absen_masuk++; break;
        case 'hadir': $total_hadir++; break;
        case 'izin': $total_izin++; break;
        case 'sakit': $total_sakit++; break;
        case 'lupa absen': $total_lupa++; break;
        case 'tidak masuk': $total_tidak_masuk++; break;
    }
}

require_once '../layout/header.php';
require_once '../layout/sidebar.php';
?>

<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
    <div>
        <a href="index.php?bulan=<?= $bulan_filter ?>" class="text-blue-600 hover:underline text-sm mb-2 inline-block">
            <i class="fas fa-arrow-left mr-1"></i> Kembali ke Rekap
        </a>
        <h1 class="text-2xl font-bold text-gray-800">Detail Absensi Petugas</h1>
    </div>

    <div class="flex items-center gap-2">
        <a href="?id=<?= $petugas_id ?>&bulan=<?= $bulan_filter ?>&excel=1" 
           class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg shadow flex items-center gap-2">
            <i class="fas fa-file-excel"></i> Export Excel
        </a>
        <a href="cetak-petugas.php?id=<?= $petugas_id ?>&bulan=<?= $bulan_filter ?>" target="_blank" 
           class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg shadow flex items-center gap-2">
            <i class="fas fa-print"></i> Cetak PDF
        </a>
    </div>
</div>

<!-- Info Petugas -->
<div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 mb-6">
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div>
            <p class="text-xs text-gray-500 uppercase font-bold">Nama</p>
            <p class="text-lg font-bold text-gray-800"><?= htmlspecialchars($petugas['nama']) ?></p>
        </div>
        <div>
            <p class="text-xs text-gray-500 uppercase font-bold">NIP</p>
            <p class="text-lg font-bold text-gray-800"><?= htmlspecialchars($petugas['nip']) ?></p>
        </div>
        <div>
            <p class="text-xs text-gray-500 uppercase font-bold">Jabatan</p>
            <p class="text-lg font-bold text-gray-800"><?= htmlspecialchars($petugas['jabatan'] ?? '-') ?></p>
        </div>
        <div>
            <p class="text-xs text-gray-500 uppercase font-bold">Bagian</p>
            <p class="text-lg font-bold text-gray-800"><?= htmlspecialchars($petugas['bagian'] ?? '-') ?></p>
        </div>
    </div>
</div>

<!-- Filter Bulan -->
<div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 mb-6">
    <form action="" method="GET" class="flex flex-col sm:flex-row gap-4 items-end">
        <input type="hidden" name="id" value="<?= $petugas_id ?>">
        <div class="w-full sm:w-auto">
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Pilih Bulan</label>
            <input type="month" name="bulan" value="<?= $bulan_filter ?>" class="border rounded p-2 w-full text-sm">
        </div>
        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded shadow text-sm font-bold w-full sm:w-auto">
            <i class="fas fa-filter mr-1"></i> Tampilkan
        </button>
    </form>
</div>

<!-- Statistik -->
<div class="grid grid-cols-2 md:grid-cols-6 gap-4 mb-6">
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 text-center">
        <p class="text-3xl font-bold text-blue-700"><?= $total_absen_masuk ?></p>
        <p class="text-xs text-blue-600 uppercase font-bold">Absen Masuk</p>
    </div>
    <div class="bg-green-50 border border-green-200 rounded-xl p-4 text-center">
        <p class="text-3xl font-bold text-green-700"><?= $total_hadir ?></p>
        <p class="text-xs text-green-600 uppercase font-bold">Hadir</p>
    </div>
    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 text-center">
        <p class="text-3xl font-bold text-yellow-700"><?= $total_izin ?></p>
        <p class="text-xs text-yellow-600 uppercase font-bold">Izin</p>
    </div>
    <div class="bg-purple-50 border border-purple-200 rounded-xl p-4 text-center">
        <p class="text-3xl font-bold text-purple-700"><?= $total_sakit ?></p>
        <p class="text-xs text-purple-600 uppercase font-bold">Sakit</p>
    </div>
    <div class="bg-orange-50 border border-orange-200 rounded-xl p-4 text-center">
        <p class="text-3xl font-bold text-orange-700"><?= $total_lupa ?></p>
        <p class="text-xs text-orange-600 uppercase font-bold">Lupa Absen</p>
    </div>
    <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-center">
        <p class="text-3xl font-bold text-red-700"><?= $total_tidak_masuk ?></p>
        <p class="text-xs text-red-600 uppercase font-bold">Tidak Masuk</p>
    </div>
</div>

<!-- Tabel Absensi -->
<div class="bg-white rounded-lg shadow overflow-hidden">
    <div class="px-4 py-3 bg-gray-50 border-b">
        <h2 class="font-bold text-gray-700">Rekap Absensi Bulan <?= $nama_bulan ?></h2>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left text-gray-500">
            <thead class="text-xs text-gray-700 uppercase bg-gray-100">
                <tr>
                    <th class="px-4 py-3">No</th>
                    <th class="px-4 py-3">Tanggal</th>
                    <th class="px-4 py-3">Shift</th>
                    <th class="px-4 py-3">Jam Masuk</th>
                    <th class="px-4 py-3">Jam Keluar</th>
                    <th class="px-4 py-3">Foto Masuk</th>
                    <th class="px-4 py-3">Foto Keluar</th>
                    <th class="px-4 py-3">Lokasi Masuk</th>
                    <th class="px-4 py-3">Lokasi Keluar</th>
                    <th class="px-4 py-3">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($absensi_list) > 0): ?>
                    <?php $no = 1; foreach ($absensi_list as $row): ?>
                        <tr class="bg-white border-b hover:bg-gray-50">
                            <td class="px-4 py-3"><?= $no++ ?></td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <?= date('d M Y', strtotime($row['tanggal'])) ?>
                                <div class="text-xs text-gray-400"><?= date('l', strtotime($row['tanggal'])) ?></div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="uppercase text-xs font-bold text-gray-600"><?= $row['shift'] ?? '-' ?></span>
                            </td>
                            <td class="px-4 py-3 text-green-700 font-bold">
                                <?= $row['jam_masuk'] ? date('H:i', strtotime($row['jam_masuk'])) : '-' ?>
                            </td>
                            <td class="px-4 py-3 text-red-700 font-bold">
                                <?= $row['jam_keluar'] ? date('H:i', strtotime($row['jam_keluar'])) : '-' ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php $fotoMasuk = $row['foto_masuk'] ?? ($row['foto_absen'] ?? null); ?>
                                <?php if(!empty($fotoMasuk)): ?>
                                    <img src="<?= $fotoMasuk ?>" class="w-10 h-10 rounded object-cover border border-gray-200 cursor-pointer hover:scale-150 transition js-zoom-img" data-img="<?= htmlspecialchars($fotoMasuk) ?>">
                                <?php else: ?>
                                    <span class="text-xs text-gray-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php if(!empty($row['foto_keluar'])): ?>
                                    <img src="<?= $row['foto_keluar'] ?>" class="w-10 h-10 rounded object-cover border border-gray-200 cursor-pointer hover:scale-150 transition js-zoom-img" data-img="<?= htmlspecialchars($row['foto_keluar']) ?>">
                                <?php else: ?>
                                    <span class="text-xs text-gray-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php $latMasuk = $row['lat_masuk'] ?? ($row['latitude'] ?? null); ?>
                                <?php $longMasuk = $row['long_masuk'] ?? ($row['longitude'] ?? null); ?>
                                <?php if(!empty($latMasuk) && !empty($longMasuk)): ?>
                                    <a href="https://www.google.com/maps?q=<?= $latMasuk ?>,<?= $longMasuk ?>" target="_blank" class="text-blue-600 hover:underline text-xs">
                                        <i class="fas fa-map-marked-alt mr-1"></i> Cek Maps
                                    </a>
                                <?php else: ?>
                                    <span class="text-xs text-gray-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php $latKeluar = $row['lat_keluar'] ?? ($row['latitude_keluar'] ?? null); ?>
                                <?php $longKeluar = $row['long_keluar'] ?? ($row['longitude_keluar'] ?? null); ?>
                                <?php if(!empty($latKeluar) && !empty($longKeluar)): ?>
                                    <a href="https://www.google.com/maps?q=<?= $latKeluar ?>,<?= $longKeluar ?>" target="_blank" class="text-blue-600 hover:underline text-xs">
                                        <i class="fas fa-map-marked-alt mr-1"></i> Cek Maps
                                    </a>
                                <?php else: ?>
                                    <span class="text-xs text-gray-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php 
                                switch ($row['status']) {
                                    case 'hadir':
                                        $statusClass = 'bg-green-100 text-green-800';
                                        break;
                                    case 'absen masuk':
                                        $statusClass = 'bg-blue-100 text-blue-800';
                                        break;
                                    case 'izin':
                                        $statusClass = 'bg-yellow-100 text-yellow-800';
                                        break;
                                    case 'sakit':
                                        $statusClass = 'bg-purple-100 text-purple-800';
                                        break;
                                    case 'lupa absen':
                                        $statusClass = 'bg-orange-100 text-orange-800';
                                        break;
                                    case 'tidak masuk':
                                        $statusClass = 'bg-red-100 text-red-800';
                                        break;
                                    default:
                                        $statusClass = 'bg-gray-100 text-gray-800';
                                }
                                ?>
                                <span class="<?= $statusClass ?> px-2 py-1 rounded text-xs font-bold uppercase">
                                    <?= $row['status'] ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" class="px-6 py-8 text-center text-gray-500">
                            <i class="fas fa-folder-open text-4xl mb-3 text-gray-300 block"></i>
                            Tidak ada data absensi pada bulan ini.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="imgZoomModal" class="fixed inset-0 z-[2000] hidden items-center justify-center bg-black/70 p-4">
    <div class="relative max-w-4xl w-full">
        <button type="button" id="imgZoomClose" class="absolute -top-10 right-0 text-white text-2xl leading-none">&times;</button>
        <img id="imgZoomTarget" src="" alt="Preview" class="w-full max-h-[85vh] object-contain rounded bg-white" />
    </div>
</div>

<script>
    (function () {
        var modal = document.getElementById('imgZoomModal');
        var img = document.getElementById('imgZoomTarget');
        var closeBtn = document.getElementById('imgZoomClose');

        if (!modal || !img) return;

        function open(src) {
            if (!src) return;
            img.src = src;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function close() {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            img.src = '';
            // Trigger cleanup
            if (window.cleanupOrphanedBackdrops) {
                setTimeout(window.cleanupOrphanedBackdrops, 100);
            }
        }

        document.addEventListener('click', function (e) {
            var t = e.target;
            if (t && t.classList && t.classList.contains('js-zoom-img')) {
                e.preventDefault();
                open(t.getAttribute('data-img') || t.getAttribute('src'));
            }
        });

        modal.addEventListener('click', function (e) {
            if (e.target === modal) close();
        });

        if (closeBtn) closeBtn.addEventListener('click', close);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') close();
        });
    })();
</script>

<?php require_once '../layout/footer.php'; ?>
