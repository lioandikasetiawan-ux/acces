<?php
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

if (
    !isset($_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin','superadmin'], true)
) {
    header("Location: ../../auth/login-v2.php");
    exit;
}

$role = $_SESSION['role'];
$bagianId = $_SESSION['bagian_id'] ?? null;

// Get bulan dan tahun dari parameter atau default ke bulan ini
$bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('m');
$tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');

// Validasi bulan dan tahun
if ($bulan < 1 || $bulan > 12) $bulan = (int)date('m');
if ($tahun < 2020 || $tahun > 2050) $tahun = (int)date('Y');

// Get petugas_id dari parameter (untuk view jadwal 1 petugas)
$petugasId = isset($_GET['petugas_id']) ? (int)$_GET['petugas_id'] : null;

// Hitung jumlah hari dalam bulan
$jumlahHari = cal_days_in_month(CAL_GREGORIAN, $bulan, $tahun);
$namaBulan = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

// Get search query
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';

// Query petugas berdasarkan bagian admin
$wherePetugas = '';
$conditions = [];

if ($bagianId !== null) {
    $conditions[] = "bagian_id = " . (int)$bagianId;
}

if ($petugasId !== null) {
    // View jadwal 1 petugas
    $conditions[] = "id = $petugasId";
}

if (!empty($search)) {
    $conditions[] = "(nama LIKE '%$search%' OR nip LIKE '%$search%')";
}

if (count($conditions) > 0) {
    $wherePetugas = " WHERE " . implode(" AND ", $conditions);
}

$queryPetugas = "SELECT id, nip, nama, bagian_id FROM petugas" . $wherePetugas . " ORDER BY nama ASC";

// Pagination for petugas list
$perPage = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$countPetugasSql = "SELECT COUNT(*) as total FROM petugas" . $wherePetugas;
$countPetugasRes = $conn->query($countPetugasSql);
$totalPetugas = ($countPetugasRes && $rc = $countPetugasRes->fetch_assoc()) ? (int)$rc['total'] : 0;
$totalPages = max(1, ceil($totalPetugas / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;
$queryPetugas .= " LIMIT $perPage OFFSET $offset";

$resPetugas = $conn->query($queryPetugas);
$listPetugas = [];
if ($resPetugas) {
    while ($p = $resPetugas->fetch_assoc()) {
        $listPetugas[] = $p;
    }
}

// Ambil data jadwal untuk bulan ini
$tanggalAwal = "$tahun-" . str_pad($bulan, 2, '0', STR_PAD_LEFT) . "-01";
$tanggalAkhir = "$tahun-" . str_pad($bulan, 2, '0', STR_PAD_LEFT) . "-" . str_pad($jumlahHari, 2, '0', STR_PAD_LEFT);

$jadwalData = [];
$queryJadwal = "SELECT jp.*, s.nama_shift 
                FROM jadwal_petugas jp
                LEFT JOIN shift s ON jp.shift_id = s.id
                WHERE jp.tanggal BETWEEN '$tanggalAwal' AND '$tanggalAkhir'";

if ($petugasId !== null) {
    $queryJadwal .= " AND jp.petugas_id = $petugasId";
} elseif ($bagianId !== null) {
    // Filter jadwal hanya untuk petugas di bagian admin
    $petugasIds = array_column($listPetugas, 'id');
    if (count($petugasIds) > 0) {
        $queryJadwal .= " AND jp.petugas_id IN (" . implode(',', $petugasIds) . ")";
    }
}

$resJadwal = $conn->query($queryJadwal);
if ($resJadwal) {
    while ($j = $resJadwal->fetch_assoc()) {
        $key = $j['petugas_id'] . '_' . $j['tanggal'];
        $jadwalData[$key] = $j;
    }
}

// Ambil data lokasi untuk setiap jadwal
$lokasiData = [];
if (count($jadwalData) > 0) {
    $jadwalIds = array_column($jadwalData, 'id');
    $queryLokasi = "SELECT jl.jadwal_id, jl.bagian_koordinat_id, bk.nama_titik, jl.urutan
                    FROM jadwal_lokasi jl
                    JOIN bagian_koordinat bk ON jl.bagian_koordinat_id = bk.id
                    WHERE jl.jadwal_id IN (" . implode(',', $jadwalIds) . ")
                    ORDER BY jl.urutan ASC";
    $resLokasi = $conn->query($queryLokasi);
    if ($resLokasi) {
        while ($lok = $resLokasi->fetch_assoc()) {
            if (!isset($lokasiData[$lok['jadwal_id']])) {
                $lokasiData[$lok['jadwal_id']] = [];
            }
            $lokasiData[$lok['jadwal_id']][] = $lok;
        }
    }
}

// Ambil list shift untuk dropdown (filter per bagian petugas)
$listShift = [];
if ($petugasId !== null && count($listPetugas) > 0) {
    // Ambil bagian_id dari petugas yang dipilih
    $petugasBagianId = $listPetugas[0]['bagian_id'];
    $queryShift = "SELECT id, nama_shift FROM shift WHERE is_active = 1 AND bagian_id = " . (int)$petugasBagianId . " ORDER BY nama_shift";
    $resShift = $conn->query($queryShift);
    if ($resShift) {
        while ($s = $resShift->fetch_assoc()) {
            $listShift[] = $s;
        }
    }
}

// Ambil list lokasi untuk dropdown (berdasarkan bagian petugas)
$listLokasi = [];
if ($petugasId !== null && count($listPetugas) > 0) {
    // Ambil bagian_id dari petugas yang dipilih
    $petugasBagianId = $listPetugas[0]['bagian_id'];
    $queryLokasi = "SELECT id, nama_titik FROM bagian_koordinat WHERE bagian_id = " . (int)$petugasBagianId . " AND is_active = 1 ORDER BY nama_titik";
    $resLokasiList = $conn->query($queryLokasi);
    if ($resLokasiList) {
        while ($lok = $resLokasiList->fetch_assoc()) {
            $listLokasi[] = $lok;
        }
    }
}

require_once '../layout/header.php';
require_once '../layout/sidebar.php';
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">Jadwal Petugas</h1>
        <p class="text-gray-600 text-sm">Kelola jadwal shift dan lokasi per petugas</p>
    </div>
    <?php if ($petugasId !== null): ?>
    <a href="index.php?bulan=<?= $bulan ?>&tahun=<?= $tahun ?>" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition">
        <i class="fas fa-arrow-left mr-2"></i>Kembali ke Daftar
    </a>
    <?php endif; ?>
</div>

<!-- Filter Bulan/Tahun -->
<div class="bg-white rounded-xl shadow p-4 mb-6">
    <form method="GET" class="flex flex-wrap gap-4 items-end">
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2">Bulan</label>
            <select name="bulan" class="border rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m == $bulan ? 'selected' : '' ?>><?= $namaBulan[$m] ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2">Tahun</label>
            <select name="tahun" class="border rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                <?php for ($y = $tahun - 2; $y <= $tahun + 2; $y++): ?>
                <option value="<?= $y ?>" <?= $y == $tahun ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <?php if ($petugasId !== null): ?>
        <input type="hidden" name="petugas_id" value="<?= $petugasId ?>">
        <?php else: ?>
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm font-semibold text-gray-700 mb-2">Cari Petugas</label>
            <div class="relative">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari Nama atau NIP..." 
                       class="w-full border rounded-lg pl-10 pr-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
            </div>
        </div>
        <?php endif; ?>
        <div class="flex gap-2">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
                <i class="fas fa-filter mr-2"></i>Filter
            </button>
            <?php if (!empty($search)): ?>
            <a href="index.php?bulan=<?= $bulan ?>&tahun=<?= $tahun ?>" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg transition">
                <i class="fas fa-times mr-2"></i>Reset
            </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($petugasId === null): ?>
<!-- Daftar Petugas -->
<div class="bg-white rounded-xl shadow overflow-hidden">
    <div class="p-4 bg-gradient-to-r from-blue-600 to-indigo-600 text-white">
        <h2 class="text-lg font-bold">Daftar Petugas</h2>
        <p class="text-sm opacity-90">Klik nama petugas untuk kelola jadwal bulanan</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50 border-b">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-700 uppercase">NIP</th>
                    <th class="px-6 py-3 text-left text-xs font-bold text-gray-700 uppercase">Nama</th>
                    <th class="px-6 py-3 text-center text-xs font-bold text-gray-700 uppercase">Jadwal Bulan Ini</th>
                    <th class="px-6 py-3 text-center text-xs font-bold text-gray-700 uppercase">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($listPetugas) > 0): ?>
                    <?php foreach ($listPetugas as $petugas): ?>
                    <?php
                    // Hitung jumlah jadwal yang sudah di-set untuk petugas ini bulan ini
                    $countJadwal = 0;
                    $countLocked = 0;
                    foreach ($jadwalData as $key => $jdw) {
                        if ($jdw['petugas_id'] == $petugas['id']) {
                            $countJadwal++;
                            if ($jdw['is_locked'] == 1) $countLocked++;
                        }
                    }
                    ?>
                    <tr class="border-b hover:bg-gray-50">
                        <td class="px-6 py-3"><?= htmlspecialchars($petugas['nip']) ?></td>
                        <td class="px-6 py-3 font-semibold"><?= htmlspecialchars($petugas['nama']) ?></td>
                        <td class="px-6 py-3 text-center">
                            <span class="text-sm">
                                <?= $countJadwal ?> / <?= $jumlahHari ?> hari
                                <?php if ($countLocked > 0): ?>
                                <span class="text-xs text-green-600">(<?= $countLocked ?> locked)</span>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td class="px-6 py-3 text-center">
                            <a href="index.php?petugas_id=<?= $petugas['id'] ?>&bulan=<?= $bulan ?>&tahun=<?= $tahun ?>" 
                               class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm transition">
                                <i class="fas fa-calendar-alt mr-1"></i>Kelola Jadwal
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="px-6 py-8 text-center text-gray-500">
                            Tidak ada petugas di bagian ini
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<!-- Grid Kalender Jadwal untuk 1 Petugas -->
<?php
$petugasInfo = null;
foreach ($listPetugas as $p) {
    if ($p['id'] == $petugasId) {
        $petugasInfo = $p;
        break;
    }
}
?>

<?php if ($petugasInfo): ?>
<div class="bg-white rounded-xl shadow p-6 mb-6">
    <div class="mb-4 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold text-gray-800"><?= htmlspecialchars($petugasInfo['nama']) ?></h2>
            <p class="text-sm text-gray-600">NIP: <?= htmlspecialchars($petugasInfo['nip']) ?> | Jadwal: <?= $namaBulan[$bulan] ?> <?= $tahun ?></p>
            <p class="text-xs text-gray-500 mt-1"><i class="fas fa-info-circle mr-1"></i>Jadwal otomatis ter-lock setelah disimpan. Tanggal yang sudah lewat tidak bisa diubah.</p>
        </div>
        <button type="button" onclick="openGlobalModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition text-sm font-semibold whitespace-nowrap">
            <i class="fas fa-calendar-plus mr-2"></i>Input Jadwal Global
        </button>
    </div>

    <!-- Grid Kalender 30 Hari -->
    <div class="grid grid-cols-7 gap-2">
        <!-- Header Hari -->
        <?php 
        $namaHari = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
        foreach ($namaHari as $hari): 
        ?>
        <div class="text-center font-bold text-sm text-gray-700 py-2 bg-gray-100 rounded">
            <?= $hari ?>
        </div>
        <?php endforeach; ?>

        <!-- Hari-hari dalam bulan -->
        <?php
        // Hitung hari pertama bulan (0=Minggu, 6=Sabtu)
        $hariPertama = (int)date('w', strtotime("$tahun-$bulan-01"));
        
        // Padding untuk hari sebelum tanggal 1
        for ($i = 0; $i < $hariPertama; $i++) {
            echo '<div class="bg-gray-50 rounded h-24"></div>';
        }

        // Loop setiap tanggal dalam bulan
        for ($tgl = 1; $tgl <= $jumlahHari; $tgl++) {
            $tanggalFull = "$tahun-" . str_pad($bulan, 2, '0', STR_PAD_LEFT) . "-" . str_pad($tgl, 2, '0', STR_PAD_LEFT);
            $key = $petugasId . '_' . $tanggalFull;
            $jadwal = $jadwalData[$key] ?? null;
            
            $isToday = ($tanggalFull == date('Y-m-d'));
            $isPast = ($tanggalFull < date('Y-m-d'));
            $isLocked = $jadwal && $jadwal['is_locked'] == 1;
            
            $bgClass = 'bg-white';
            if ($isToday) $bgClass = 'bg-blue-50 border-2 border-blue-500';
            elseif ($isPast) $bgClass = 'bg-gray-50';
            
            $borderClass = $isLocked ? 'border-2 border-green-500' : 'border border-gray-200';
            
            // Semua tanggal bisa diklik untuk melihat detail (tanggal lalu akan read-only di modal)
            $cursorClass = 'cursor-pointer hover:shadow-lg';
            if ($isPast) $cursorClass .= ' opacity-75';
            $onclickAttr = "onclick=\"openEditModal('$tanggalFull', $petugasId, " . ($jadwal ? $jadwal['id'] : 'null') . ", " . ($isPast ? 'true' : 'false') . ")\"";
        ?>
        <div class="<?= $bgClass ?> <?= $borderClass ?> rounded p-2 h-24 relative transition <?= $cursorClass ?>"
             <?= $onclickAttr ?>>
            <!-- Tanggal -->
            <div class="text-xs font-bold text-gray-700 mb-1"><?= $tgl ?></div>
            
            <!-- Shift -->
            <?php if ($jadwal && $jadwal['shift_id']): ?>
            <div class="text-xs bg-blue-100 text-blue-800 px-1 py-0.5 rounded mb-1 truncate" title="<?= htmlspecialchars($jadwal['nama_shift']) ?>">
                <i class="fas fa-clock mr-1"></i><?= htmlspecialchars($jadwal['nama_shift']) ?>
            </div>
            <?php else: ?>
            <div class="text-xs text-gray-400 italic">Belum dijadwalkan</div>
            <?php endif; ?>
            
            <!-- Lokasi -->
            <?php if ($jadwal && isset($lokasiData[$jadwal['id']])): ?>
            <div class="text-xs text-gray-600 truncate" title="<?= count($lokasiData[$jadwal['id']]) ?> lokasi">
                <i class="fas fa-map-marker-alt mr-1"></i><?= count($lokasiData[$jadwal['id']]) ?> lokasi
            </div>
            <?php endif; ?>
            
            <!-- Lock Icon -->
            <?php if ($isLocked): ?>
            <div class="absolute top-1 right-1">
                <i class="fas fa-lock text-green-600 text-xs"></i>
            </div>
            <?php endif; ?>
        </div>
        <?php } ?>
    </div>

    <!-- Keterangan -->
    <div class="mt-4 flex gap-4 text-xs text-gray-600">
        <div><span class="inline-block w-3 h-3 bg-blue-50 border-2 border-blue-500 rounded mr-1"></span> Hari Ini</div>
        <div><span class="inline-block w-3 h-3 bg-white border-2 border-green-500 rounded mr-1"></span> Locked</div>
        <div><span class="inline-block w-3 h-3 bg-gray-50 border border-gray-200 rounded mr-1"></span> Hari Lalu</div>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- Modal Edit Jadwal -->
<div id="modalEditJadwal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b flex items-center justify-between sticky top-0 bg-white">
            <h3 class="text-xl font-bold text-gray-800">Edit Jadwal</h3>
            <button onclick="closeEditModal()" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        
        <form id="formEditJadwal" class="p-6">
            <input type="hidden" id="edit_jadwal_id" name="jadwal_id">
            <input type="hidden" id="edit_petugas_id" name="petugas_id">
            <input type="hidden" id="edit_tanggal" name="tanggal">
            
            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-2">Tanggal</label>
                <input type="text" id="edit_tanggal_display" readonly class="w-full border rounded-lg px-3 py-2 bg-gray-50">
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-2">Shift <span class="text-red-500">*</span></label>
                <select id="edit_shift_id" name="shift_id" required class="w-full border rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">-- Pilih Shift --</option>
                    <?php foreach ($listShift as $shift): ?>
                    <option value="<?= $shift['id'] ?>"><?= htmlspecialchars($shift['nama_shift']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-2">Lokasi Absen <span class="text-red-500">*</span></label>
                <div class="text-xs text-gray-500 mb-2">Pilih satu atau lebih lokasi (untuk UP3BK bisa pilih banyak)</div>
                
                <!-- Search Box Lokasi -->
                <div class="relative mb-2">
                    <input type="text" id="searchLokasi" placeholder="Cari lokasi..." 
                           class="w-full border rounded-lg pl-10 pr-3 py-1.5 text-sm outline-none focus:ring-2 focus:ring-blue-500">
                    <i class="fas fa-search absolute left-3 top-2.5 text-gray-400 text-xs"></i>
                </div>

                <div class="border rounded-lg p-3 max-h-48 overflow-y-auto" id="containerLokasi">
                    <?php foreach ($listLokasi as $lok): ?>
                    <label class="flex items-center py-2 hover:bg-gray-50 px-2 rounded cursor-pointer lokasi-item">
                        <input type="checkbox" name="lokasi_ids[]" value="<?= $lok['id'] ?>" class="mr-2 lokasi-checkbox">
                        <span class="text-sm lokasi-nama"><?= htmlspecialchars($lok['nama_titik']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="mb-4 bg-green-50 border border-green-200 rounded-lg p-3">
                <p class="text-xs text-green-800">
                    <i class="fas fa-info-circle mr-1"></i>
                    <strong>Info:</strong> Jadwal akan otomatis ter-lock setelah disimpan dan tidak bisa diubah oleh petugas.
                </p>
            </div>
            
            <div class="flex gap-3">
                <button type="submit" id="btnSimpanJadwal" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition font-semibold">
                    <i class="fas fa-save mr-2"></i>Simpan Jadwal
                </button>
                <button type="button" onclick="hapusJadwal()" id="btnHapusJadwal" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg transition">
                    <i class="fas fa-trash mr-2"></i>Hapus
                </button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/modal_global.php'; ?>

<script>
let currentJadwalId = null;
let currentPetugasId = <?= $petugasId ?? 'null' ?>;
let currentBulan = <?= $bulan ?>;
let currentTahun = <?= $tahun ?>;

function openEditModal(tanggal, petugasId, jadwalId, isPast = false) {
    currentJadwalId = jadwalId;
    
    document.getElementById('edit_jadwal_id').value = jadwalId || '';
    document.getElementById('edit_petugas_id').value = petugasId;
    document.getElementById('edit_tanggal').value = tanggal;
    document.getElementById('edit_tanggal_display').value = formatTanggal(tanggal);
    
    // Reset form
    document.getElementById('edit_shift_id').value = '';
    document.querySelectorAll('.lokasi-checkbox').forEach(cb => cb.checked = false);
    
    // Disable form jika tanggal sudah lewat (read-only mode)
    const isReadOnly = isPast;
    document.getElementById('edit_shift_id').disabled = isReadOnly;
    document.querySelectorAll('.lokasi-checkbox').forEach(cb => cb.disabled = isReadOnly);
    document.getElementById('btnSimpanJadwal').style.display = isReadOnly ? 'none' : 'block';
    document.getElementById('btnHapusJadwal').style.display = (jadwalId && !isReadOnly) ? 'block' : 'none';
    
    // Update modal title
    const modalTitle = document.querySelector('#modalEditJadwal h3');
    if (modalTitle) {
        modalTitle.textContent = isReadOnly ? 'Lihat Jadwal (Read-Only)' : 'Edit Jadwal';
    }
    
    // Jika edit jadwal existing, load data
    if (jadwalId) {
        loadJadwalData(jadwalId);
    }
    
    document.getElementById('modalEditJadwal').classList.remove('hidden');
}

// Fitur Filter Lokasi di dalam Modal
document.getElementById('searchLokasi')?.addEventListener('input', function() {
    const filter = this.value.toLowerCase();
    const items = document.querySelectorAll('.lokasi-item');
    
    items.forEach(item => {
        const text = item.querySelector('.lokasi-nama').textContent.toLowerCase();
        if (text.includes(filter)) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
});

function closeEditModal() {
    // Reset search lokasi saat modal ditutup
    const searchInput = document.getElementById('searchLokasi');
    if (searchInput) {
        searchInput.value = '';
        const items = document.querySelectorAll('.lokasi-item');
        items.forEach(item => item.style.display = 'flex');
    }
    // Use safe close method
    if (window.safeCloseModal) {
        window.safeCloseModal(document.getElementById('modalEditJadwal'));
    } else {
        document.getElementById('modalEditJadwal').classList.add('hidden');
    }
}

function formatTanggal(tanggal) {
    const [y, m, d] = tanggal.split('-');
    const bulanNama = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return `${parseInt(d)} ${bulanNama[parseInt(m)]} ${y}`;
}

function loadJadwalData(jadwalId) {
    fetch(`api_jadwal.php?action=get&id=${jadwalId}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById('edit_shift_id').value = data.jadwal.shift_id || '';
                
                // Check lokasi yang sudah dipilih
                if (data.lokasi && data.lokasi.length > 0) {
                    data.lokasi.forEach(lok => {
                        const checkbox = document.querySelector(`.lokasi-checkbox[value="${lok.bagian_koordinat_id}"]`);
                        if (checkbox) checkbox.checked = true;
                    });
                }
            }
        })
        .catch(err => console.error(err));
}

document.getElementById('formEditJadwal').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'save');
    
    // Validasi: minimal 1 lokasi harus dipilih
    const lokasiChecked = document.querySelectorAll('.lokasi-checkbox:checked');
    if (lokasiChecked.length === 0) {
        alert('Pilih minimal 1 lokasi absen!');
        return;
    }
    
    fetch('api_jadwal.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('Jadwal berhasil disimpan!');
            window.location.reload();
        } else {
            alert('Error: ' + (data.message || 'Gagal menyimpan jadwal'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Terjadi kesalahan saat menyimpan jadwal');
    });
});

function hapusJadwal() {
    if (!currentJadwalId) return;
    
    if (!confirm('Yakin ingin menghapus jadwal ini?')) return;
    
    fetch('api_jadwal.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=delete&jadwal_id=${currentJadwalId}`
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('Jadwal berhasil dihapus!');
            window.location.reload();
        } else {
            alert('Error: ' + (data.message || 'Gagal menghapus jadwal'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Terjadi kesalahan saat menghapus jadwal');
    });
}

function lockAllJadwal() {
    if (!confirm('Lock semua jadwal bulan ini? Jadwal yang sudah di-lock tidak bisa diubah petugas.')) return;
    
    fetch('api_jadwal.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=lock_all&petugas_id=${currentPetugasId}&bulan=${currentBulan}&tahun=${currentTahun}`
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(`Berhasil lock ${data.count} jadwal!`);
            window.location.reload();
        } else {
            alert('Error: ' + (data.message || 'Gagal lock jadwal'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Terjadi kesalahan');
    });
}

// === Jadwal Global ===
function openGlobalModal() {
    document.getElementById('modalJadwalGlobal').classList.remove('hidden');
}

// Fitur Filter Lokasi di dalam Modal Global
document.getElementById('searchLokasiGlobal')?.addEventListener('input', function() {
    const filter = this.value.toLowerCase();
    const items = document.querySelectorAll('.global-lokasi-item');
    
    items.forEach(item => {
        const text = item.querySelector('.global-lokasi-nama').textContent.toLowerCase();
        if (text.includes(filter)) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
});

function closeGlobalModal() {
    // Reset search lokasi saat modal ditutup
    const searchInput = document.getElementById('searchLokasiGlobal');
    if (searchInput) {
        searchInput.value = '';
        const items = document.querySelectorAll('.global-lokasi-item');
        items.forEach(item => item.style.display = 'flex');
    }
    
    if (window.safeCloseModal) {
        window.safeCloseModal(document.getElementById('modalJadwalGlobal'));
    } else {
        document.getElementById('modalJadwalGlobal').classList.add('hidden');
    }
}

document.getElementById('cbReplace')?.addEventListener('change', function() {
    document.getElementById('warnReplace').classList.toggle('hidden', !this.checked);
});

document.getElementById('formJadwalGlobal')?.addEventListener('submit', function(e) {
    e.preventDefault();
    var cbs = document.querySelectorAll('.global-lokasi-cb:checked');
    if (cbs.length === 0) { alert('Pilih minimal 1 lokasi!'); return; }

    var btn = document.getElementById('btnSimpanGlobal');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Memproses...';

    var fd = new FormData(this);
    fd.append('action', 'save_global');

    fetch('api_jadwal.php', { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(data){
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-magic mr-2"></i>Generate Jadwal 1 Bulan';
        if (data.success) {
            alert(data.message);
            window.location.reload();
        } else {
            alert('Error: ' + (data.message || 'Gagal'));
        }
    })
    .catch(function(err){
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-magic mr-2"></i>Generate Jadwal 1 Bulan';
        console.error(err);
        alert('Terjadi kesalahan');
    });
});
</script>

<?php
require_once '../layout/pagination.php';
$paginationParams = array_filter([
    'bulan' => $bulan,
    'tahun' => $tahun,
    'petugas_id' => $petugasId,
    'search' => $search,
]);
$paginationUrl = '?' . http_build_query($paginationParams);
renderPagination($page, $totalPages, $paginationUrl);

require_once '../layout/footer.php';
?>
