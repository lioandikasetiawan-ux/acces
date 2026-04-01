<?php
/**
 * API: Check Notification Status
 * Returns server time and attendance status for the logged-in petugas.
 * Uses server time (Asia/Jakarta) for security - never trust device time.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['petugas_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$petugasId = (int)$_SESSION['petugas_id'];
$today = date('Y-m-d');
$now = date('H:i:s');
$nowFull = date('Y-m-d H:i:s');

$notifications = [];

// Get all jadwal for today
$stmtJadwal = $conn->prepare("
    SELECT jp.id AS jadwal_id, jp.shift_id, 
           s.nama_shift, s.mulai_masuk, s.akhir_masuk, s.mulai_keluar, s.akhir_keluar
    FROM jadwal_petugas jp
    LEFT JOIN shift s ON jp.shift_id = s.id
    WHERE jp.petugas_id = ? AND jp.tanggal = ? AND s.is_active = 1
    ORDER BY s.mulai_masuk ASC
");
$stmtJadwal->bind_param("is", $petugasId, $today);
$stmtJadwal->execute();
$result = $stmtJadwal->get_result();
$jadwalList = [];
while ($row = $result->fetch_assoc()) {
    $jadwalList[] = $row;
}
$stmtJadwal->close();

if (empty($jadwalList)) {
    echo json_encode([
        'success' => true,
        'server_time' => $nowFull,
        'notifications' => [],
        'message' => 'Tidak ada jadwal hari ini'
    ]);
    exit;
}

// Get absensi for today
$stmtAbsensi = $conn->prepare("
    SELECT a.*, jp.shift_id,
           lh.id AS laporan_id
    FROM absensi a
    LEFT JOIN jadwal_petugas jp ON a.jadwal_id = jp.id
    LEFT JOIN laporan_harian lh ON a.id = lh.absensi_id
    WHERE a.petugas_id = ? AND a.tanggal = ?
");
$stmtAbsensi->bind_param("is", $petugasId, $today);
$stmtAbsensi->execute();
$resultAbsensi = $stmtAbsensi->get_result();
$absensiMap = [];
while ($row = $resultAbsensi->fetch_assoc()) {
    $absensiMap[$row['shift_id']] = $row;
}
$stmtAbsensi->close();

foreach ($jadwalList as $jadwal) {
    $shiftId = $jadwal['shift_id'];
    $namaShift = $jadwal['nama_shift'];
    $mulaiMasuk = $jadwal['mulai_masuk'];
    $akhirMasuk = $jadwal['akhir_masuk'];
    $mulaiKeluar = $jadwal['mulai_keluar'];
    $akhirKeluar = $jadwal['akhir_keluar'];
    $absensi = $absensiMap[$shiftId] ?? null;

    // 1. Reminder 10 menit sebelum jam masuk
    $reminderMasuk = date('H:i:s', strtotime($mulaiMasuk) - 600); // 10 min before
    if ($now >= $reminderMasuk && $now < $mulaiMasuk && !$absensi) {
        $menitLagi = ceil((strtotime($mulaiMasuk) - strtotime($now)) / 60);
        $notifications[] = [
            'type' => 'reminder_masuk',
            'shift' => $namaShift,
            'title' => 'Pengingat Absen Masuk',
            'body' => "Shift {$namaShift}: Jam absen masuk dimulai {$menitLagi} menit lagi (". substr($mulaiMasuk, 0, 5) .")",
            'priority' => 'warning'
        ];
    }

    // 2. Belum absen masuk (dalam rentang waktu masuk)
    if ($now >= $mulaiMasuk && $now <= $akhirMasuk && !$absensi) {
        $notifications[] = [
            'type' => 'belum_masuk',
            'shift' => $namaShift,
            'title' => 'Belum Absen Masuk!',
            'body' => "Shift {$namaShift}: Anda belum absen masuk. Batas waktu: " . substr($akhirMasuk, 0, 5),
            'priority' => 'urgent'
        ];
    }

    // 3. Reminder 10 menit sebelum jam keluar
    $reminderKeluar = date('H:i:s', strtotime($mulaiKeluar) - 600); // 10 min before
    if ($now >= $reminderKeluar && $now < $mulaiKeluar && $absensi && $absensi['jam_masuk'] && !$absensi['jam_keluar']) {
        $menitLagi = ceil((strtotime($mulaiKeluar) - strtotime($now)) / 60);
        $notifications[] = [
            'type' => 'reminder_keluar',
            'shift' => $namaShift,
            'title' => 'Pengingat Absen Keluar',
            'body' => "Shift {$namaShift}: Jam absen keluar dimulai {$menitLagi} menit lagi (" . substr($mulaiKeluar, 0, 5) . ")",
            'priority' => 'warning'
        ];
    }

    // 4. Belum absen keluar (dalam rentang waktu keluar)
    if ($now >= $mulaiKeluar && $now <= $akhirKeluar && $absensi && $absensi['jam_masuk'] && !$absensi['jam_keluar']) {
        $notifications[] = [
            'type' => 'belum_keluar',
            'shift' => $namaShift,
            'title' => 'Belum Absen Keluar!',
            'body' => "Shift {$namaShift}: Anda belum absen keluar. Batas waktu: " . substr($akhirKeluar, 0, 5),
            'priority' => 'urgent'
        ];
    }

    // 5. Belum isi laporan kegiatan harian (sudah masuk, belum keluar, belum ada laporan)
    if ($absensi && $absensi['jam_masuk'] && !$absensi['jam_keluar'] && !$absensi['laporan_id']) {
        // Only remind if approaching keluar time or already in keluar window
        if ($now >= $reminderKeluar) {
            $notifications[] = [
                'type' => 'belum_laporan',
                'shift' => $namaShift,
                'title' => 'Laporan Kegiatan Belum Diisi!',
                'body' => "Shift {$namaShift}: Isi laporan kegiatan harian sebelum absen keluar.",
                'priority' => 'urgent'
            ];
        }
    }
}

echo json_encode([
    'success' => true,
    'server_time' => $nowFull,
    'server_time_short' => date('H:i:s'),
    'notifications' => $notifications
]);
