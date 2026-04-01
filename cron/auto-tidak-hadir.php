<?php
/**
 * Cron Job: Auto update status 'absen masuk' -> 'tidak hadir'
 * 
 * Logika: Jika petugas sudah absen masuk tapi TIDAK absen keluar,
 * dan waktu akhir_keluar shift sudah terlewat, maka status diubah menjadi 'tidak hadir'.
 *
 * Jalankan via cron setiap 5-10 menit:
 *   php /path/to/cron/auto-tidak-hadir.php
 * 
 * Atau panggil via URL (dilindungi secret key):
 *   http://localhost/testing1/cron/auto-tidak-hadir.php?key=YOUR_SECRET_KEY
 */

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Asia/Jakarta');

// Proteksi: jika diakses via web, harus pakai secret key
if (php_sapi_name() !== 'cli') {
    $secretKey = 'absensi_cron_secret_2024'; // Ganti dengan key yang aman
    if (!isset($_GET['key']) || $_GET['key'] !== $secretKey) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

$today = date('Y-m-d');
$now = date('H:i:s');

// Cari semua absensi hari ini yang status = 'absen masuk' dan belum absen keluar
$sql = "SELECT a.id, a.petugas_id, a.tanggal, a.jadwal_id,
               s.akhir_keluar, s.nama_shift
        FROM absensi a
        LEFT JOIN jadwal_petugas jp ON a.jadwal_id = jp.id
        LEFT JOIN shift s ON jp.shift_id = s.id
        WHERE a.status = 'absen masuk'
          AND a.jam_keluar IS NULL
          AND a.tanggal <= ?
        ORDER BY a.tanggal ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $today);
$stmt->execute();
$result = $stmt->get_result();

$updated = 0;
$skipped = 0;

while ($row = $result->fetch_assoc()) {
    $akhirKeluar = $row['akhir_keluar'];
    $tanggal = $row['tanggal'];

    // Jika tanggal absensi sudah lewat hari (kemarin atau sebelumnya), langsung update
    if ($tanggal < $today) {
        $stmtUpdate = $conn->prepare("UPDATE absensi SET status = 'tidak hadir' WHERE id = ?");
        $stmtUpdate->bind_param('i', $row['id']);
        $stmtUpdate->execute();
        $stmtUpdate->close();
        $updated++;
        continue;
    }

    // Untuk absensi hari ini, cek apakah akhir_keluar sudah terlewat
    if (empty($akhirKeluar)) {
        // Tidak ada data shift/akhir_keluar, skip (atau bisa set default 23:59)
        $skipped++;
        continue;
    }

    // Handle shift yang melewati tengah malam (misal akhir_keluar = 02:00)
    // Jika akhir_keluar < '06:00', anggap itu shift malam yang berakhir besok
    // Jadi untuk hari ini, belum saatnya diubah
    if ($akhirKeluar < '06:00:00') {
        // Shift malam: akhir keluar di hari berikutnya, skip untuk hari ini
        $skipped++;
        continue;
    }

    // Cek apakah waktu sekarang sudah melewati akhir_keluar
    if ($now > $akhirKeluar) {
        $stmtUpdate = $conn->prepare("UPDATE absensi SET status = 'tidak hadir' WHERE id = ?");
        $stmtUpdate->bind_param('i', $row['id']);
        $stmtUpdate->execute();
        $stmtUpdate->close();
        $updated++;
    } else {
        $skipped++;
    }
}

$stmt->close();

$message = date('Y-m-d H:i:s') . " | Auto tidak hadir: $updated updated, $skipped skipped";

// Log hasil
$logFile = __DIR__ . '/cron.log';
file_put_contents($logFile, $message . PHP_EOL, FILE_APPEND);

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => $message]);
} else {
    echo $message . PHP_EOL;
}
