<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// Temporary: catch fatal errors on Linux PHP 7 for debugging
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
        http_response_code(500);
        echo '<h1>PHP Fatal Error</h1><pre>';
        echo htmlspecialchars($err['message']) . "\n";
        echo htmlspecialchars($err['file']) . ':' . $err['line'];
        echo '</pre>';
    }
});

/**

 * Dashboard Petugas V2 - Sistem Absensi dengan Alur Baru

 * ======================================================

 * Alur: Login ? Pilih Bagian ? Pilih Shift ? Absen Masuk/Keluar

 */




require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/functions.php';

// Fallback jika functions.php belum punya dbHasColumn (kompatibilitas server lama)
if (!function_exists('dbHasColumn')) {
    function dbHasColumn($conn, $table, $column) {
        $result = $conn->query("SHOW COLUMNS FROM `" . $conn->real_escape_string($table) . "` LIKE '" . $conn->real_escape_string($column) . "'");
        return ($result && $result->num_rows > 0);
    }
}

// Cek login

if (!isset($_SESSION['petugas_id'])) {

    header('Location: ../auth/login-v2.php');

    exit;

}



$petugasId = $_SESSION['petugas_id'];

$petugas = getPetugasById($conn, $petugasId);



if (!$petugas) {

    session_destroy();

    header('Location: ../auth/login-v2.php');

    exit;

}



// Get data

$bagianIdSession = isset($_SESSION['bagian_id']) ? (int) $_SESSION['bagian_id'] : 0;

$bagianId = $_SESSION['bagian_id'];

$stmt = $conn->prepare("
    SELECT b.*,
           (
             SELECT COUNT(*) 
             FROM bagian_koordinat bk 
             WHERE bk.bagian_id = b.id 
               AND bk.is_active = 1
           ) AS jumlah_titik
    FROM bagian b
    WHERE b.id = ? 
      AND b.is_active = 1
");
$bagianList = [];
if ($stmt) {
    $stmt->bind_param("i", $bagianId);
    $stmt->execute();
    $bagianList = stmtFetchAllAssoc($stmt);
    $stmt->close();
}


$bagianIdSession = $_SESSION['bagian_id']; // bisa null
$shiftData = getShiftTersedia($conn, $petugas['bagian_id']);

// Get assigned titik lokasi and shift (locked)
$assignedTitikLokasi = null;
$assignedTitikLokasiList = [];
$assignedShift = null;

$today = date('Y-m-d');

// Fetch all jadwal for today (for JS time-based logic)
$allJadwalHariIni = array();
$stmtAllJadwal = $conn->prepare("SELECT jp.id, jp.shift_id, s.nama_shift, s.mulai_masuk, s.akhir_masuk, s.mulai_keluar, s.akhir_keluar
                               FROM jadwal_petugas jp
                               LEFT JOIN shift s ON jp.shift_id = s.id
                               WHERE jp.petugas_id = ? AND jp.tanggal = ?
                               ORDER BY s.mulai_masuk ASC");
if ($stmtAllJadwal) {
    $stmtAllJadwal->bind_param('is', $petugasId, $today);
    $stmtAllJadwal->execute();
    $allJadwalHariIni = stmtFetchAllAssoc($stmtAllJadwal);
    $stmtAllJadwal->close();
}

// Use first jadwal as assigned shift (original behavior)
$jadwalHariIni = !empty($allJadwalHariIni) ? $allJadwalHariIni[0] : null;
if ($jadwalHariIni) {
    $assignedShift = $jadwalHariIni;
    $jadwalIdHariIni = (int) ($jadwalHariIni['id'] ?? 0);
    if ($jadwalIdHariIni > 0) {
        $stmtLok = $conn->prepare("SELECT bk.*
                                  FROM jadwal_lokasi jl
                                  JOIN bagian_koordinat bk ON jl.bagian_koordinat_id = bk.id
                                  WHERE jl.jadwal_id = ? AND bk.is_active = 1
                                  ORDER BY bk.nama_titik ASC");
        if ($stmtLok) {
            $stmtLok->bind_param('i', $jadwalIdHariIni);
            $stmtLok->execute();
            $assignedTitikLokasiList = stmtFetchAllAssoc($stmtLok);
            $stmtLok->close();
            if (!empty($assignedTitikLokasiList)) {
                $assignedTitikLokasi = $assignedTitikLokasiList[0];
            }
        }
    }
}




// Get absensi hari ini untuk semua shift
$absensiHariIni = array();
$stmt = $conn->prepare("
    SELECT a.*, jp.shift_id AS shift_id, s.nama_shift,
           p.bagian_id, b.nama_bagian, b.kode_bagian,
           lh.id as laporan_id, lh.kegiatan_harian as isi_laporan
    FROM absensi a
    LEFT JOIN jadwal_petugas jp ON a.jadwal_id = jp.id
    LEFT JOIN shift s ON jp.shift_id = s.id
    LEFT JOIN petugas p ON a.petugas_id = p.id
    LEFT JOIN bagian b ON p.bagian_id = b.id
    LEFT JOIN laporan_harian lh ON a.id = lh.absensi_id
    WHERE a.petugas_id = ? AND a.tanggal = ?
    ORDER BY a.jam_masuk DESC
");
if ($stmt) {
    $stmt->bind_param("is", $petugasId, $today);
    $stmt->execute();
    $absensiHariIni = stmtFetchAllAssoc($stmt);
    $stmt->close();
}



// Sync status otomatis untuk absensi hari ini (hanya jika status masih '--')
if (function_exists('syncAbsensiStatusOtomatisPetugasTanggal')) {
    syncAbsensiStatusOtomatisPetugasTanggal($conn, $petugasId, $today);
}



// Get rekap bulan ini

$rekap = getRekapBulanan($conn, $petugasId, date('n'), date('Y'));

// Get lupa absen count untuk tampilan (reset per bulan)
$lupaAbsenDisplay = getLupaAbsenDisplayCount($conn, $petugasId, date('n'), date('Y'));



$flash = getFlashMessage();

?>

<!DOCTYPE html>

<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dashboard - Absensi Lapangan</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- PWA Manifest & Meta -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#4f46e5">
    <link rel="apple-touch-icon" href="../assets/img/Logo-Acces.png">

    <script>
    function logoutApp() {
        // Replace current history entry with logout script
        // This prevents "Back" button from returning to dashboard
        window.location.replace('../auth/logoutpetugas.php');
    }
    
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
          navigator.serviceWorker.register('./sw.js')
            .then(registration => {
              console.log('ServiceWorker registration successful with scope: ', registration.scope);
            })
            .catch(err => {
              console.log('ServiceWorker registration failed: ', err);
            });
        });
      }
    </script>

    <style>

        .card-hover:hover { transform: translateY(-2px); transition: all 0.2s; }

        .pulse { animation: pulse 2s infinite; }

        @keyframes pulse {

            0%, 100% { opacity: 1; }

            50% { opacity: 0.5; }

        }

    </style>

</head>

<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen">

    <!-- Header -->

    <header class="bg-white shadow-md">

        <div class="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">

            <div class="flex items-center gap-3">

                <div class="w-12 h-12 rounded-full bg-gradient-to-r from-blue-600 to-indigo-600 overflow-hidden flex-shrink-0 flex items-center justify-center">

                    <img src="../assets/img/Logo-Acces.png" alt="ACCES" class="w-full h-full object-cover">

                </div>

                <div>

                    <h1 class="text-xl font-bold text-gray-800">ACCES</h1>

                    <p class="text-xs text-gray-500"><?= formatTanggal($today) ?></p>

                </div>

            </div>

            <div class="flex items-center gap-4">

                <div class="text-right">

                    <p class="font-medium text-gray-800"><?= htmlspecialchars($petugas['nama']) ?></p>

                    <p class="text-xs text-gray-500">NIP: <?= htmlspecialchars($petugas['nip']) ?></p>

                </div>

            </div>
            <button onclick="logoutApp()" class="text-gray-500 hover:text-red-500 transition-colors">
                <i class="fas fa-sign-out-alt text-xl"></i>
            </button>
        </div>

    </header>



    <main class="max-w-7xl mx-auto px-4 py-6">

        <?php if ($flash): ?>

        <div class="mb-4 p-4 rounded-lg <?= $flash['type'] === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">

            <i class="fas fa-<?= $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle' ?> mr-2"></i>

            <?= $flash['message'] ?>

        </div>

        <?php endif; ?>



        <!-- Card Absensi Utama -->

        <div class="bg-white rounded-2xl shadow-xl p-6 mb-6">

            <div class="flex items-center justify-between mb-4">

                <h2 class="text-lg font-bold text-gray-800">

                    <i class="fas fa-fingerprint text-blue-600 mr-2"></i>Absensi Hari Ini

                </h2>

                <span class="text-sm text-gray-500" id="currentTime"></span>

            </div>



            <!-- Locked Location & Shift Info (if assigned) -->
            <?php if ($assignedShift): ?>
            
            <div class="mb-6 p-4 bg-gradient-to-r from-blue-50 to-purple-50 rounded-xl border-2 border-blue-200">
                <div class="flex items-center gap-2 mb-3">
                    <i class="fas fa-lock text-blue-600"></i>
                    <h3 class="font-bold text-gray-800">Lokasi & Shift Anda (Locked)</h3>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-white p-3 rounded-lg">
                        <div class="text-xs text-gray-500 mb-1">
                            <i class="fas fa-map-marker-alt text-red-500"></i> Titik Lokasi Absen
                        </div>
                        <?php if (!empty($assignedTitikLokasiList)): ?>
                            <ul class="mt-2 space-y-1 text-sm text-gray-700">
                                <?php foreach ($assignedTitikLokasiList as $lok): ?>
                                    <li><?= htmlspecialchars($lok['nama_titik']) ?> <span class="text-xs text-gray-500">(<?= (int)($lok['radius_meter'] ?? 0) ?>m)</span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="text-sm text-red-600 font-semibold">Lokasi belum di-set pada jadwal hari ini. Hubungi admin.</div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="bg-white p-3 rounded-lg">
                        <div class="text-xs text-gray-500 mb-1">
                            <i class="fas fa-clock text-blue-500"></i> Shift Kerja
                        </div>
                        <div class="font-bold text-gray-800" id="lockedShiftName"><?= htmlspecialchars($assignedShift['nama_shift']) ?></div>
                        <div class="text-xs text-gray-500 mt-1" id="lockedShiftTimes">
                            <div>Jam Masuk: <?= substr($assignedShift['mulai_masuk'], 0, 5) ?> - <?= substr($assignedShift['akhir_masuk'], 0, 5) ?></div>
                            <div>Jam Keluar: <?= substr($assignedShift['mulai_keluar'], 0, 5) ?> - <?= substr($assignedShift['akhir_keluar'], 0, 5) ?></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php else: ?>
            <div class="mb-6 p-4 bg-yellow-50 rounded-xl border-2 border-yellow-200">
                <div class="flex items-center gap-2">
                    <i class="fas fa-exclamation-triangle text-yellow-700"></i>
                    <div class="font-semibold text-yellow-800">Jadwal hari ini belum tersedia/ter-lock. Hubungi admin.</div>
                </div>
            </div>
            
            <!-- Step 1: Pilih Bagian (for non-locked petugas) -->

            <div class="hidden">

                <label class="block text-sm font-medium text-gray-700 mb-2">

                    <i class="fas fa-building mr-1"></i> 1. Pilih Bagian/Lokasi Kerja

                </label>

                <select id="selectBagian" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-lg">

                    <option value="">-- Pilih Bagian --</option>

                    <?php foreach ($bagianList as $b): ?>

                    <option value="<?= $b['id'] ?>" 

                            data-titik="<?= $b['jumlah_titik'] ?>"

                            <?= ($petugas['bagian_id'] == $b['id']) ? 'selected' : '' ?>>

                        <?= htmlspecialchars($b['nama_bagian']) ?> (<?= $b['jumlah_titik'] ?> titik)

                    </option>

                    <?php endforeach; ?>

                </select>

            </div>



            <!-- Step 2: Pilih Shift (for non-locked petugas) -->

            <div class="hidden">

                <label class="block text-sm font-medium text-gray-700 mb-2">

                    <i class="fas fa-clock mr-1"></i> 2. Pilih Shift Kerja

                </label>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3" id="shiftContainer">

                    <?php foreach ($shiftData['semua'] as $shift): ?>

                    <button type="button" 

                            class="shift-btn p-4 rounded-xl border-2 text-left transition-all

                                   <?= $shift['is_available'] 

                                       ? 'border-gray-200 hover:border-blue-500 hover:bg-blue-50 cursor-pointer' 

                                       : 'border-gray-100 bg-gray-50 cursor-not-allowed opacity-50' ?>"

                            data-shift-id="<?= $shift['id'] ?>"

                            data-available="<?= $shift['is_available'] ? '1' : '0' ?>"

                            <?= !$shift['is_available'] ? 'disabled' : '' ?>>

                        <div class="font-semibold <?= $shift['is_available'] ? 'text-gray-800' : 'text-gray-400' ?>">

                            <?= htmlspecialchars($shift['nama_shift']) ?>

                        </div>

                        <div class="text-xs <?= $shift['is_available'] ? 'text-gray-500' : 'text-gray-400' ?>">

                            Masuk: <?= substr($shift['mulai_masuk'], 0, 5) ?>-<?= substr($shift['akhir_masuk'], 0, 5) ?><br>

                            Keluar: <?= substr($shift['mulai_keluar'], 0, 5) ?>-<?= substr($shift['akhir_keluar'], 0, 5) ?>

                        </div>

                        <?php if ($shift['is_available']): ?>

                            <?php if ($shift['can_masuk'] && $shift['can_keluar']): ?>

                            <span class="inline-block mt-2 px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">

                                <i class="fas fa-check-circle mr-1"></i>Masuk & Keluar

                            </span>

                            <?php elseif ($shift['can_masuk']): ?>

                            <span class="inline-block mt-2 px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-700">

                                <i class="fas fa-sign-in-alt mr-1"></i>Jam Masuk

                            </span>

                            <?php elseif ($shift['can_keluar']): ?>

                            <span class="inline-block mt-2 px-2 py-1 text-xs rounded-full bg-orange-100 text-orange-700">

                                <i class="fas fa-sign-out-alt mr-1"></i>Jam Keluar

                            </span>

                            </div>

            <?php endif; ?>

                        <?php else: ?>

                        <span class="inline-block mt-2 px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-500">

                            <i class="fas fa-lock mr-1"></i>Di luar jam

                        </span>

                        <?php endif; ?>

                    </button>

                    <?php endforeach; ?>

                </div>
            
            <?php endif; ?>

            </div>



            <!-- Step 3: Tombol Absen -->

            <div id="absenContainer" class="hidden">

                <div class="bg-gray-50 rounded-xl p-4 mb-4" id="absenStatus">

                    <!-- Status akan diisi via JS -->

                </div>



                <div id="laporKegiatanLinkWrap" class="hidden mb-4">

                    <a href="laporan-harian.php" class="block w-full text-center bg-orange-600 hover:bg-orange-700 text-white py-3 rounded-xl font-semibold">

                        <i class="fas fa-clipboard-check mr-2"></i>LAPOR KEGIATAN HARIAN

                    </a>

                </div>



                <!-- Tombol Aksi -->

                <div class="flex gap-3">

                    <button id="btnAbsenMasuk" onclick="mulaiAbsenMasuk()"

                            class="flex-1 bg-green-600 hover:bg-green-700 text-white py-4 rounded-xl font-semibold text-lg hidden">

                        <i class="fas fa-sign-in-alt mr-2"></i>ABSEN MASUK

                    </button>

                    <button id="btnAbsenKeluar" onclick="mulaiAbsenKeluar()"

                            class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-4 rounded-xl font-semibold text-lg hidden">

                        <i class="fas fa-sign-out-alt mr-2"></i>ABSEN KELUAR

                    </button>

                    <button id="btnSudahLengkap" disabled

                            class="flex-1 bg-gray-400 text-white py-4 rounded-xl font-semibold text-lg cursor-not-allowed hidden">

                        <i class="fas fa-check-double mr-2"></i>SUDAH LENGKAP

                    </button>

                </div>

            </div>



            <!-- Message jika belum pilih -->

            <div id="selectPrompt" class="text-center py-8 text-gray-500">

                <i class="fas fa-hand-pointer text-4xl mb-3"></i>

                <p>Jadwal hari ini belum tersedia</p>

            </div>

        </div>



        <!-- Riwayat Absensi Hari Ini -->

        <?php if (!empty($absensiHariIni)): ?>

        <div class="bg-white rounded-2xl shadow-xl p-6 mb-6">

            <h2 class="text-lg font-bold text-gray-800 mb-4">

                <i class="fas fa-history text-purple-600 mr-2"></i>Riwayat Absensi Hari Ini

            </h2>

            <div class="space-y-3">

                <?php foreach ($absensiHariIni as $a): ?>

                <div class="flex items-center justify-between p-4 bg-gray-50 rounded-xl">

                    <div>

                        <span class="font-medium"><?= htmlspecialchars($a['nama_shift']) ?></span>

                        <span class="text-sm text-gray-500 ml-2">(<?= htmlspecialchars($a['kode_bagian']) ?>)</span>

                        <div class="text-sm text-gray-600">

                            <i class="fas fa-sign-in-alt text-green-600"></i> <?= formatWaktu($a['jam_masuk']) ?>

                            <?php if ($a['jam_keluar']): ?>

                            <span class="mx-2">?</span>

                            <i class="fas fa-sign-out-alt text-blue-600"></i> <?= formatWaktu($a['jam_keluar']) ?>

                            <?php endif; ?>

                        </div>

                    </div>

                    <div class="text-right">

                        <?php if ($a['jam_keluar']): ?>

                        <span class="px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-700">

                            <i class="fas fa-check-circle"></i> Lengkap

                        </span>

                        <?php else: ?>

                        <span class="px-3 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-700 pulse">

                            <i class="fas fa-clock"></i> Aktif

                        </span>

                        <?php endif; ?>

                        <?php if ($a['laporan_id']): ?>

                        <span class="block text-xs text-gray-500 mt-1">

                            <i class="fas fa-clipboard-check"></i> Laporan diisi

                        </span>

                        <?php endif; ?>

                    </div>

                </div>

                <?php endforeach; ?>

            </div>

        </div>

        <?php endif; ?>



        <!-- Statistik Bulan Ini -->

        <div class="mb-6">

            <div class="flex items-center justify-between mb-3">

                <h2 class="text-lg font-bold text-gray-800">

                    <i class="fas fa-chart-pie text-indigo-600 mr-2"></i>Rekap Bulan Ini

                </h2>

                <span class="text-xs text-gray-500"><?= date('F Y') ?></span>

            </div>

            <div class="grid grid-cols-2 md:grid-cols-6 gap-3">

                <div class="bg-white rounded-xl shadow p-4 card-hover">

                    <div class="flex items-center justify-between">

                        <div class="text-xs text-gray-500 font-semibold uppercase">Hadir</div>

                        <div class="w-8 h-8 rounded-lg bg-green-100 text-green-700 flex items-center justify-center">

                            <i class="fas fa-check"></i>

                        </div>

                    </div>

                    <div class="mt-2 text-2xl font-bold text-green-700"><?= $rekap['hadir'] ?? 0 ?></div>

                </div>

                <div class="bg-white rounded-xl shadow p-4 card-hover">

                    <div class="flex items-center justify-between">

                        <div class="text-xs text-gray-500 font-semibold uppercase">Izin</div>

                        <div class="w-8 h-8 rounded-lg bg-blue-100 text-blue-700 flex items-center justify-center">

                            <i class="fas fa-file-alt"></i>

                        </div>

                    </div>

                    <div class="mt-2 text-2xl font-bold text-blue-700"><?= $rekap['izin'] ?? 0 ?></div>

                </div>

                <div class="bg-white rounded-xl shadow p-4 card-hover">

                    <div class="flex items-center justify-between">

                        <div class="text-xs text-gray-500 font-semibold uppercase">Sakit</div>

                        <div class="w-8 h-8 rounded-lg bg-yellow-100 text-yellow-700 flex items-center justify-center">

                            <i class="fas fa-notes-medical"></i>

                        </div>

                    </div>

                    <div class="mt-2 text-2xl font-bold text-yellow-700"><?= $rekap['sakit'] ?? 0 ?></div>

                </div>

                <div class="bg-white rounded-xl shadow p-4 card-hover">

                    <div class="flex items-center justify-between">

                        <div class="text-xs text-gray-500 font-semibold uppercase">Alpha</div>

                        <div class="w-8 h-8 rounded-lg bg-red-100 text-red-700 flex items-center justify-center">

                            <i class="fas fa-times"></i>

                        </div>

                    </div>

                    <div class="mt-2 text-2xl font-bold text-red-700"><?= $rekap['alpha'] ?? 0 ?></div>

                </div>

                <div class="bg-white rounded-xl shadow p-4 card-hover">

                    <div class="flex items-center justify-between">

                        <div class="text-xs text-gray-500 font-semibold uppercase">Lupa Absen</div>

                        <div class="w-8 h-8 rounded-lg bg-orange-100 text-orange-700 flex items-center justify-center">

                            <i class="fas fa-question-circle"></i>

                        </div>

                    </div>

                    <div class="mt-2 text-2xl font-bold text-orange-700"><?= $lupaAbsenDisplay ?></div>

                </div>

            </div>

        </div>



        <!-- Menu Navigasi -->

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">

            <a href="kejadian.php" class="bg-white rounded-xl shadow p-4 text-center card-hover hover:bg-red-50">

                <i class="fas fa-exclamation-triangle text-2xl text-red-600 mb-2"></i>

                <div class="font-medium text-gray-800">Lapor Kejadian</div>

            </a>

            <a href="pengajuan.php" class="bg-white rounded-xl shadow p-4 text-center card-hover hover:bg-blue-50">

                <i class="fas fa-file-alt text-2xl text-blue-600 mb-2"></i>

                <div class="font-medium text-gray-800">Pengajuan</div>

            </a>

            <a href="lokasi.php" class="bg-white rounded-xl shadow p-4 text-center card-hover hover:bg-green-50">

                <i class="fas fa-map-marked-alt text-2xl text-green-600 mb-2"></i>

                <div class="font-medium text-gray-800">Lokasi Titik</div>

            </a>

            <a href="rekap.php" class="bg-white rounded-xl shadow p-4 text-center card-hover hover:bg-purple-50">

                <i class="fas fa-chart-bar text-2xl text-purple-600 mb-2"></i>

                <div class="font-medium text-gray-800">Rekap Absensi</div>

            </a>

        </div>



        <!-- Profile Menu - Full Width on Mobile MD -->

        <a href="profil.php" class="block bg-white rounded-xl shadow p-4 text-center card-hover hover:bg-indigo-50">

            <i class="fas fa-user-circle text-2xl text-indigo-600 mb-2"></i>

            <div class="font-medium text-gray-800">Profile</div>

        </a>

    </main>



    <!-- Modal Loading -->

    <div id="modalLoading" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">

        <div class="bg-white rounded-xl p-8 text-center">

            <div class="animate-spin rounded-full h-12 w-12 border-4 border-blue-600 border-t-transparent mx-auto mb-4"></div>

            <p class="text-gray-700" id="loadingText">Memproses...</p>

        </div>

    </div>



    <div id="modalKamera" class="fixed inset-0 bg-black bg-opacity-60 items-center justify-center z-50 hidden p-4">

        <div class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden">

            <div class="px-5 py-4 border-b flex items-center justify-between">

                <div id="kameraTitle" class="font-bold text-gray-800">Foto Absen Masuk</div>

                <button type="button" class="text-gray-500 hover:text-gray-700" onclick="tutupKamera()">

                    <i class="fas fa-times"></i>

                </button>

            </div>

            <div class="p-5 max-h-screen overflow-y-auto">

                <div id="kameraContainer" class="bg-black rounded-xl overflow-hidden mx-auto" style="width:100%; max-height:360px;">
                    <video id="kameraVideo" autoplay playsinline style="width:100%; height:auto; max-height:360px; display:block;"></video>
                </div>

                <canvas id="kameraCanvas" class="hidden"></canvas>

                <div id="kameraPreviewWrap" class="hidden mt-3">

                    <img id="kameraPreview" src="" class="w-full rounded-xl border object-cover" />

                </div>



                <div class="grid grid-cols-2 gap-2 mt-4">

                    <button type="button" id="btnSnap" onclick="ambilFoto()" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-xl">

                        <i class="fas fa-camera mr-2"></i>Ambil Foto

                    </button>

                    <button type="button" id="btnGunakan" onclick="kirimAbsenDenganFoto()" class="bg-green-600 hover:bg-green-700 text-white font-semibold py-3 rounded-xl" disabled>

                        <i class="fas fa-check mr-2"></i>Gunakan

                    </button>

                </div>

                <p class="text-xs text-gray-500 mt-3">

                    Pastikan wajah terlihat jelas. Jika kamera tidak muncul, cek izin kamera pada browser.

                </p>

            </div>

        </div>

    </div>



    <script>

    // Data dari PHP

    const absensiHariIni = <?= json_encode($absensiHariIni) ?: '[]' ?>;
    const allJadwalHariIni = <?= json_encode($allJadwalHariIni) ?: '[]' ?>;
    
    // Locked location and shift data
    const assignedTitikLokasiId = <?= $assignedTitikLokasi ? (int)$assignedTitikLokasi['id'] : 'null' ?>;
    const assignedShiftId = <?= $assignedShift ? (int)($assignedShift['shift_id'] ?? 0) : 'null' ?>;
    const assignedBagianId = <?= $petugas['bagian_id'] ? (int)$petugas['bagian_id'] : 'null' ?>;

    let selectedBagianId = assignedBagianId; // Auto-set if locked

    let selectedShiftId = assignedShiftId; // Auto-set if locked

    let currentAbsensi = null;

    let kameraStream = null;

    let kameraTipe = 'masuk';

    let fotoMasukDataUri = '';

    let fotoKeluarDataUri = '';



    // Server Time Counter (PHP to JS)
    // Inisialisasi waktu dari server
    let currentServerTime = new Date('<?= date('Y/m/d H:i:s') ?>');

    function updateTime() {
        // Increment 1 detik
        currentServerTime.setSeconds(currentServerTime.getSeconds() + 1);
        
        document.getElementById('currentTime').textContent = currentServerTime.toLocaleTimeString('id-ID', {
            hour12: false,
            hour: '2-digit', 
            minute: '2-digit', 
            second: '2-digit'
        }).replace(/\./g, ':');
    }

    setInterval(updateTime, 1000);
    // Jalankan sekali di awal (tanpa increment) agar langsung muncul
    document.getElementById('currentTime').textContent = currentServerTime.toLocaleTimeString('id-ID', {
            hour12: false,
            hour: '2-digit', 
            minute: '2-digit', 
            second: '2-digit'
    }).replace(/\./g, ':');



    // Handle pilih bagian (only for non-locked petugas)

    const selectBagianEl = document.getElementById('selectBagian');
    if (selectBagianEl) {
        selectBagianEl.addEventListener('change', function() {

            selectedBagianId = this.value;

            checkAndShowAbsen();

        });
    }



    // Handle pilih shift

    document.querySelectorAll('.shift-btn').forEach(btn => {

        btn.addEventListener('click', function() {

            if (this.dataset.available !== '1') return;

            

            // Remove selection from all

            document.querySelectorAll('.shift-btn').forEach(b => {

                b.classList.remove('border-blue-500', 'bg-blue-50');

            });

            

            // Add selection to clicked

            this.classList.add('border-blue-500', 'bg-blue-50');

            selectedShiftId = this.dataset.shiftId;

            checkAndShowAbsen();

        });

    });



    function ambilJam(val) {

        if (!val) return '-';

        if (typeof val !== 'string') return '-';

        if (val.indexOf(' ') !== -1) {

            const parts = val.split(' ');

            if (parts[1]) return parts[1].substring(0, 5);

        }

        return val.substring(0, 5);

    }



    function getNowTimeStr() {
        // Return current server time string HH:mm:ss
        const h = String(currentServerTime.getHours()).padStart(2, '0');
        const m = String(currentServerTime.getMinutes()).padStart(2, '0');
        const s = String(currentServerTime.getSeconds()).padStart(2, '0');
        return h + ':' + m + ':' + s;
    }

    function findActiveJadwal() {
        const nowTime = getNowTimeStr();
        for (const jd of allJadwalHariIni) {
            const absen = absensiHariIni.find(a => a.shift_id == jd.shift_id);
            if (absen && absen.jam_masuk && !absen.jam_keluar) {
                if (!jd.akhir_keluar || nowTime <= jd.akhir_keluar) {
                    return jd;
                }
            }
        }
        for (const jd of allJadwalHariIni) {
            if (!jd.akhir_keluar || nowTime <= jd.akhir_keluar) {
                return jd;
            }
        }
        return null;
    }

    function updateLockedShiftDisplay(jd) {
        const nameEl = document.getElementById('lockedShiftName');
        const timesEl = document.getElementById('lockedShiftTimes');
        if (nameEl && jd.nama_shift) {
            nameEl.textContent = jd.nama_shift;
        }
        if (timesEl && jd.mulai_masuk) {
            timesEl.innerHTML =
                '<div>Jam Masuk: ' + jd.mulai_masuk.substring(0,5) + ' - ' + (jd.akhir_masuk||'').substring(0,5) + '</div>' +
                '<div>Jam Keluar: ' + (jd.mulai_keluar||'').substring(0,5) + ' - ' + (jd.akhir_keluar||'').substring(0,5) + '</div>';
        }
    }

    // Check status dan tampilkan tombol yang sesuai

    function checkAndShowAbsen() {

        const container = document.getElementById('absenContainer');

        const prompt = document.getElementById('selectPrompt');

        const status = document.getElementById('absenStatus');

        const laporKegiatanLinkWrap = document.getElementById('laporKegiatanLinkWrap');

        const btnMasuk = document.getElementById('btnAbsenMasuk');

        const btnKeluar = document.getElementById('btnAbsenKeluar');

        const btnLengkap = document.getElementById('btnSudahLengkap');



        if (!selectedBagianId || !selectedShiftId) {

            container.classList.add('hidden');

            prompt.classList.remove('hidden');

            return;

        }



        container.classList.remove('hidden');

        prompt.classList.add('hidden');



        // Reset semua tombol

        btnMasuk.classList.add('hidden');

        btnKeluar.classList.add('hidden');

        btnLengkap.classList.add('hidden');

        if (laporKegiatanLinkWrap) laporKegiatanLinkWrap.classList.add('hidden');



        btnKeluar.disabled = false;

        btnKeluar.classList.remove('opacity-50', 'cursor-not-allowed');



        const nowTime = getNowTimeStr();
        const currentJadwal = allJadwalHariIni.find(j => j.shift_id == selectedShiftId);
        const akhirKeluar = currentJadwal ? currentJadwal.akhir_keluar : null;
        const shiftExpired = akhirKeluar && nowTime > akhirKeluar;

        // Cek absensi untuk shift ini

        currentAbsensi = absensiHariIni.find(a => a.shift_id == selectedShiftId);



        if (shiftExpired) {
            const nextJadwal = findActiveJadwal();
            if (nextJadwal && nextJadwal.shift_id != selectedShiftId) {
                selectedShiftId = nextJadwal.shift_id;
                updateLockedShiftDisplay(nextJadwal);
                checkAndShowAbsen();
                return;
            }
            const shiftAbsen = currentAbsensi;
            if (shiftAbsen && shiftAbsen.jam_masuk && shiftAbsen.jam_keluar) {
                status.innerHTML = `
                    <div class="flex items-center text-gray-700">
                        <i class="fas fa-check-circle text-green-600 text-xl mr-3"></i>
                        <div>
                            <div class="font-medium">Absensi Lengkap</div>
                            <div class="text-sm text-gray-500">
                                Masuk: ${ambilJam(shiftAbsen.jam_masuk)}
                                | Keluar: ${ambilJam(shiftAbsen.jam_keluar)}
                            </div>
                        </div>
                    </div>
                `;
                btnLengkap.classList.remove('hidden');
            } else {
                status.innerHTML = `
                    <div class="flex items-center text-gray-700">
                        <i class="fas fa-calendar-check text-green-600 text-xl mr-3"></i>
                        <div>
                            <div class="font-medium">Jadwal Hari Ini Selesai</div>
                            <div class="text-sm text-gray-500">Tidak ada shift berikutnya</div>
                        </div>
                    </div>
                `;
            }
            return;
        }

        if (!currentAbsensi) {

            // Belum absen masuk

            status.innerHTML = `

                <div class="flex items-center text-gray-700">

                    <i class="fas fa-info-circle text-blue-600 text-xl mr-3"></i>

                    <div>

                        <div class="font-medium">Belum Absen Masuk</div>

                        <div class="text-sm text-gray-500">Klik tombol di bawah untuk absen masuk</div>

                    </div>

                </div>

            `;

            btnMasuk.classList.remove('hidden');

        } else if (currentAbsensi.jam_masuk && !currentAbsensi.jam_keluar) {

            // Sudah masuk, belum keluar

            const hasLaporan = currentAbsensi.laporan_id != null;

            

            status.innerHTML = `

                <div class="flex items-center text-gray-700">

                    <i class="fas fa-clock text-yellow-600 text-xl mr-3 pulse"></i>

                    <div>

                        <div class="font-medium">Sedang Bekerja</div>

                        <div class="text-sm text-gray-500">

                            Masuk: ${ambilJam(currentAbsensi.jam_masuk)} 

                            | Bagian: ${currentAbsensi.kode_bagian}

                            ${hasLaporan ? '| <span class="text-green-600"><i class="fas fa-check"></i> Laporan diisi</span>' : ''}

                        </div>

                    </div>

                </div>

            `;

            

            if (laporKegiatanLinkWrap) {

                laporKegiatanLinkWrap.classList.remove('hidden');

            }



            if (!hasLaporan) {

                btnKeluar.disabled = true;

                btnKeluar.classList.add('opacity-50', 'cursor-not-allowed');

            }

            btnKeluar.classList.remove('hidden');

        } else {

            // Sudah lengkap

            status.innerHTML = `

                <div class="flex items-center text-gray-700">

                    <i class="fas fa-check-circle text-green-600 text-xl mr-3"></i>

                    <div>

                        <div class="font-medium">Absensi Lengkap</div>

                        <div class="text-sm text-gray-500">

                            Masuk: ${ambilJam(currentAbsensi.jam_masuk)} 

                            | Keluar: ${ambilJam(currentAbsensi.jam_keluar)}

                        </div>

                    </div>

                </div>

            `;

            btnLengkap.classList.remove('hidden');

        }

    }



    // Proses Absen

    async function prosesAbsen(tipe, foto) {

        // Validasi

        if (!selectedBagianId || !selectedShiftId) {

            alert('Pilih bagian dan shift terlebih dahulu');

            return;

        }



        // Validasi laporan untuk absen keluar

        if (tipe === 'keluar') {

            const hasLaporan = currentAbsensi && currentAbsensi.laporan_id != null;

            if (!hasLaporan) {

                alert('Isi laporan kegiatan terlebih dahulu sebelum absen keluar');

                window.location.href = 'laporan-harian.php';

                return;

            }

        }



        // Tampilkan loading

        document.getElementById('modalLoading').classList.remove('hidden');

        document.getElementById('loadingText').textContent = 'Mengambil lokasi...';



        try {

            // Ambil lokasi

            const position = await getLocation();

            

            document.getElementById('loadingText').textContent = 'Memproses absensi...';



            // Siapkan data

            const data = {

                tipe: tipe,

                bagian_id: selectedBagianId,

                shift_id: selectedShiftId,

                latitude: position.coords.latitude,

                longitude: position.coords.longitude

            };



            if (foto) {

                data.foto = foto;

            }



            // Laporan diisi melalui halaman laporan-harian.php



            // Kirim request

            const url = (tipe === 'keluar') ? '../api/absen-keluar-process.php' : '../api/absen-masuk-process.php';

            const response = await fetch(url, {

                method: 'POST',

                headers: {

                    'Content-Type': 'application/json',

                    'Accept': 'application/json',

                    'X-Requested-With': 'XMLHttpRequest'

                },

                body: JSON.stringify(data)

            });



            const result = await response.json();



            if (result.success) {

                alert(result.message);

                location.reload();

            } else {

                alert('Gagal: ' + result.message);

            }

        } catch (error) {

            alert('Error: ' + error.message);

        } finally {

            document.getElementById('modalLoading').classList.add('hidden');

        }

    }



    function mulaiAbsenMasuk() {

        if (!selectedBagianId || !selectedShiftId) {

            alert('Pilih bagian dan shift terlebih dahulu');

            return;

        }



        kameraTipe = 'masuk';

        const kameraTitle = document.getElementById('kameraTitle');

        if (kameraTitle) kameraTitle.textContent = 'Foto Absen Masuk';



        fotoMasukDataUri = '';

        fotoKeluarDataUri = '';

        const btnGunakan = document.getElementById('btnGunakan');

        const previewWrap = document.getElementById('kameraPreviewWrap');

        const previewImg = document.getElementById('kameraPreview');

        if (btnGunakan) btnGunakan.disabled = true;

        if (previewWrap) previewWrap.classList.add('hidden');

        if (previewImg) previewImg.src = '';



        bukaKamera();

    }



    function mulaiAbsenKeluar() {

        if (!selectedBagianId || !selectedShiftId) {

            alert('Pilih bagian dan shift terlebih dahulu');

            return;

        }



        const hasLaporan = currentAbsensi && currentAbsensi.laporan_id != null;

        if (!hasLaporan) {

            alert('Isi laporan kegiatan terlebih dahulu sebelum absen keluar');

            window.location.href = 'laporan-harian.php';

            return;

        }



        kameraTipe = 'keluar';

        const kameraTitle = document.getElementById('kameraTitle');

        if (kameraTitle) kameraTitle.textContent = 'Foto Absen Keluar';



        fotoKeluarDataUri = '';

        const btnGunakan = document.getElementById('btnGunakan');

        const previewWrap = document.getElementById('kameraPreviewWrap');

        const previewImg = document.getElementById('kameraPreview');

        if (btnGunakan) btnGunakan.disabled = true;

        if (previewWrap) previewWrap.classList.add('hidden');

        if (previewImg) previewImg.src = '';



        bukaKamera();

    }



    async function bukaKamera() {

        const modal = document.getElementById('modalKamera');

        const video = document.getElementById('kameraVideo');

        const container = document.getElementById('kameraContainer');

        const previewWrap = document.getElementById('kameraPreviewWrap');

        const btnGunakan = document.getElementById('btnGunakan');



        if (!modal || !video) return;



        // Reset state

        if (container) container.style.display = 'block';

        if (previewWrap) previewWrap.classList.add('hidden');

        if (btnGunakan) btnGunakan.disabled = true;



        modal.classList.remove('hidden');

        modal.classList.add('flex');



        try {

            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {

                throw new Error('Browser tidak mendukung kamera');

            }



            kameraStream = await navigator.mediaDevices.getUserMedia({

                video: { facingMode: 'user' },

                audio: false

            });



            video.srcObject = kameraStream;

        } catch (e) {

            tutupKamera();

            alert('Gagal membuka kamera: ' + (e && e.message ? e.message : ''));

        }

    }



    function tutupKamera() {

        const modal = document.getElementById('modalKamera');

        const video = document.getElementById('kameraVideo');



        if (kameraStream) {

            kameraStream.getTracks().forEach(t => t.stop());

            kameraStream = null;

        }

        if (video) video.srcObject = null;

        if (modal) {

            modal.classList.add('hidden');

            modal.classList.remove('flex');

        }

    }



    function ambilFoto() {

        const video = document.getElementById('kameraVideo');

        const canvas = document.getElementById('kameraCanvas');

        const previewWrap = document.getElementById('kameraPreviewWrap');

        const previewImg = document.getElementById('kameraPreview');

        const btnGunakan = document.getElementById('btnGunakan');

        const container = document.getElementById('kameraContainer');



        if (!video || !canvas) return;



        const w = video.videoWidth || 640;

        const h = video.videoHeight || 480;

        canvas.width = w;

        canvas.height = h;

        const ctx = canvas.getContext('2d');

        ctx.drawImage(video, 0, 0, w, h);



        const dataUri = canvas.toDataURL('image/jpeg', 0.85);

        if (kameraTipe === 'keluar') {

            fotoKeluarDataUri = dataUri;

        } else {

            fotoMasukDataUri = dataUri;

        }

        if (previewImg) previewImg.src = dataUri;

        if (previewWrap) previewWrap.classList.remove('hidden');

        if (btnGunakan) btnGunakan.disabled = false;

        // Stop kamera stream setelah ambil foto

        if (kameraStream) {

            kameraStream.getTracks().forEach(t => t.stop());

            kameraStream = null;

        }

        if (video) video.srcObject = null;

        if (container) container.style.display = 'none'; // Sembunyikan container kamera setelah ambil foto

    }



    async function kirimAbsenDenganFoto() {

        const foto = (kameraTipe === 'keluar') ? fotoKeluarDataUri : fotoMasukDataUri;

        if (!foto) {

            alert('Ambil foto dulu');

            return;

        }



        tutupKamera();

        await prosesAbsen(kameraTipe, foto);

    }



    // Get location dengan promise

    function getLocation() {

        return new Promise((resolve, reject) => {

            if (!navigator.geolocation) {

                reject(new Error('Geolocation tidak didukung browser ini'));

                return;

            }



            navigator.geolocation.getCurrentPosition(

                position => resolve(position),

                error => {

                    let message = 'Gagal mendapatkan lokasi';

                    switch(error.code) {

                        case error.PERMISSION_DENIED:

                            message = 'Akses lokasi ditolak. Izinkan akses lokasi di browser.';

                            break;

                        case error.POSITION_UNAVAILABLE:

                            message = 'Informasi lokasi tidak tersedia.';

                            break;

                        case error.TIMEOUT:

                            message = 'Timeout mendapatkan lokasi.';

                            break;

                    }

                    reject(new Error(message));

                },

                {

                    enableHighAccuracy: true,

                    timeout: 10000,

                    maximumAge: 0

                }

            );

        });

    }



    // Auto-select shift yang sudah ada absensi aktif

    document.addEventListener('DOMContentLoaded', function() {

        const bagianSelect = document.getElementById('selectBagian');

        if (bagianSelect && bagianSelect.value) {

            selectedBagianId = bagianSelect.value;

        }

        // For locked petugas, auto-trigger checkAndShowAbsen
        if (assignedBagianId && assignedShiftId) {
            // Find the currently active jadwal based on time
            const activeJd = findActiveJadwal();
            if (activeJd) {
                selectedShiftId = activeJd.shift_id;
                updateLockedShiftDisplay(activeJd);
            }
            // Auto-select the shift button visually
            const shiftBtn = document.querySelector(`.shift-btn[data-shift-id="${selectedShiftId}"]`);
            if (shiftBtn) {
                shiftBtn.classList.add('border-blue-500', 'bg-blue-50');
            }
            checkAndShowAbsen();
            // Auto-refresh every 30 seconds to detect shift expiry
            setInterval(function() { checkAndShowAbsen(); }, 30000);
            return;
        }

        const activeAbsen = absensiHariIni.find(a => a.jam_masuk && !a.jam_keluar);

        if (activeAbsen) {

            // Select bagian
            if (bagianSelect) {
                bagianSelect.value = activeAbsen.bagian_id;
            }

            selectedBagianId = activeAbsen.bagian_id;

            

            // Select shift

            const shiftBtn = document.querySelector(`.shift-btn[data-shift-id="${activeAbsen.shift_id}"]`);

            if (shiftBtn) {

                shiftBtn.click();

            }

        } else {

            checkAndShowAbsen();

        }

    });

    </script>

</body>

</html>