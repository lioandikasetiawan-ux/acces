<?php

/**

 * Helper Functions untuk Sistem Absensi Lapangan V2

 * ==================================================

 */



/**

 * Hitung jarak antara 2 koordinat menggunakan Haversine Formula

 * @param float $lat1 Latitude titik 1

 * @param float $lon1 Longitude titik 1

 * @param float $lat2 Latitude titik 2

 * @param float $lon2 Longitude titik 2

 * @return float Jarak dalam meter

 */

function hitungJarak($lat1, $lon1, $lat2, $lon2) {

    $earthRadius = 6371000; // Radius bumi dalam meter

    

    $lat1Rad = deg2rad($lat1);

    $lat2Rad = deg2rad($lat2);

    $deltaLat = deg2rad($lat2 - $lat1);

    $deltaLon = deg2rad($lon2 - $lon1);

    

    $a = sin($deltaLat / 2) * sin($deltaLat / 2) +

         cos($lat1Rad) * cos($lat2Rad) *

         sin($deltaLon / 2) * sin($deltaLon / 2);

    

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    

    return $earthRadius * $c;

}

function haversineDistance($lat1, $lon1, $lat2, $lon2) {

    return hitungJarak($lat1, $lon1, $lat2, $lon2);

}

function stmtFetchAssoc($stmt) {

    if (method_exists($stmt, 'get_result')) {
        $res = $stmt->get_result();
        if (!$res) {
            return null;
        }
        $row = $res->fetch_assoc();
        return $row ?: null;
    }

    $meta = $stmt->result_metadata();
    if (!$meta) {
        return null;
    }

    $fields = $meta->fetch_fields();
    $row = [];
    $bind = [];
    foreach ($fields as $field) {
        $row[$field->name] = null;
        $bind[] = &$row[$field->name];
    }
    call_user_func_array([$stmt, 'bind_result'], $bind);

    if (!$stmt->fetch()) {
        return null;
    }

    $out = [];
    foreach ($row as $k => $v) {
        $out[$k] = $v;
    }
    return $out;

}

function stmtFetchAllAssoc($stmt) {

    if (method_exists($stmt, 'get_result')) {
        $res = $stmt->get_result();
        if (!$res) {
            return [];
        }
        return $res->fetch_all(MYSQLI_ASSOC);
    }

    $meta = $stmt->result_metadata();
    if (!$meta) {
        return [];
    }

    $fields = $meta->fetch_fields();
    $row = [];
    $bind = [];
    foreach ($fields as $field) {
        $row[$field->name] = null;
        $bind[] = &$row[$field->name];
    }
    call_user_func_array([$stmt, 'bind_result'], $bind);

    $rows = [];
    while ($stmt->fetch()) {
        $copy = [];
        foreach ($row as $k => $v) {
            $copy[$k] = $v;
        }
        $rows[] = $copy;
    }
    return $rows;

}



/**

 * Validasi lokasi user terhadap koordinat bagian

 * @param mysqli $conn Database connection

 * @param float $userLat User latitude

 * @param float $userLong User longitude

 * @param int $bagianId ID bagian

 * @return array ['valid' => bool, 'titik_id' => int|null, 'jarak' => float|null, 'message' => string]

 */

function validasiLokasi($conn, $userLat, $userLong, $bagianId) {

    $bagianCol = 'bagian_id';

    $checkBagianId = $conn->query("SHOW COLUMNS FROM bagian_koordinat LIKE 'bagian_id'");

    if (!$checkBagianId || $checkBagianId->num_rows === 0) {

        $bagianCol = 'bagian';

    }



    $stmt = $conn->prepare("

        SELECT id, nama_titik, latitude, longitude, radius_meter 

        FROM bagian_koordinat 

        WHERE {$bagianCol} = ? AND is_active = 1

    ");

    $stmt->bind_param("i", $bagianId);

    $stmt->execute();

    $rows = stmtFetchAllAssoc($stmt);
    $stmt->close();

    if (count($rows) === 0) {

        return [

            'valid' => false,

            'titik_id' => null,

            'jarak' => null,

            'message' => 'Tidak ada titik koordinat yang terdaftar untuk bagian ini'

        ];

    }

    

    $titikTerdekat = null;

    $jarakTerdekat = PHP_FLOAT_MAX;

    

    while ($row = array_shift($rows)) {

        $jarak = hitungJarak($userLat, $userLong, $row['latitude'], $row['longitude']);

        

        // Cek apakah dalam radius

        if ($jarak <= $row['radius_meter']) {

            return [

                'valid' => true,

                'titik_id' => $row['id'],

                'nama_titik' => $row['nama_titik'],

                'jarak' => round($jarak, 2),

                'message' => 'Lokasi valid (Titik: ' . $row['nama_titik'] . ', Jarak: ' . round($jarak, 2) . 'm)'

            ];

        }

        

        // Track titik terdekat untuk error message

        if ($jarak < $jarakTerdekat) {

            $jarakTerdekat = $jarak;

            $titikTerdekat = $row['nama_titik'];

        }

    }

    return [

        'valid' => false,

        'titik_id' => null,

        'jarak' => round($jarakTerdekat, 2),

        'message' => 'Anda berada di luar area kerja. Jarak ke titik terdekat (' . $titikTerdekat . '): ' . round($jarakTerdekat / 1000, 2) . ' km'

    ];

}



/**

 * Cek apakah shift tersedia berdasarkan waktu sekarang

 * Shift tersedia jika waktu sekarang dalam rentang masuk ATAU rentang keluar

 * @param mysqli $conn Database connection

 * @param int $shiftId ID shift

 * @param string $currentTime Waktu sekarang (format H:i:s)

 * @return array ['available' => bool, 'can_masuk' => bool, 'can_keluar' => bool, 'message' => string, 'shift' => array|null]

 */

function cekShiftTersedia($conn, $shiftId, $currentTime = null) {

    if ($currentTime === null) {

        $currentTime = date('H:i:s');

    }

    

    $stmt = $conn->prepare("

        SELECT id, nama_shift, mulai_masuk, akhir_masuk, mulai_keluar, akhir_keluar

        FROM shift 

        WHERE id = ? AND is_active = 1

    ");

    if (!$stmt) {

        return [

            'available' => false,

            'can_masuk' => false,

            'can_keluar' => false,

            'message' => 'Shift tidak ditemukan atau tidak aktif',

            'shift' => null

        ];

    }

    $stmt->bind_param("i", $shiftId);

    $stmt->execute();

    $shift = stmtFetchAssoc($stmt);
    $stmt->close();

    if (!$shift) {

        return [

            'available' => false,

            'can_masuk' => false,

            'can_keluar' => false,

            'message' => 'Shift tidak ditemukan atau tidak aktif',

            'shift' => null

        ];

    }

    

    $currentTimestamp = strtotime($currentTime);

    

    // Cek rentang waktu masuk

    $mulaiMasuk = strtotime($shift['mulai_masuk']);

    $akhirMasuk = strtotime($shift['akhir_masuk']);

    $canMasuk = ($currentTimestamp >= $mulaiMasuk && $currentTimestamp <= $akhirMasuk);

    

    // Cek rentang waktu keluar

    $mulaiKeluar = strtotime($shift['mulai_keluar']);

    $akhirKeluar = strtotime($shift['akhir_keluar']);

    $canKeluar = ($currentTimestamp >= $mulaiKeluar && $currentTimestamp <= $akhirKeluar);

    

    // Shift tersedia jika bisa masuk ATAU bisa keluar

    $available = $canMasuk || $canKeluar;

    

    if ($available) {

        $status = [];

        if ($canMasuk) $status[] = 'Absen Masuk';

        if ($canKeluar) $status[] = 'Absen Keluar';

        

        return [

            'available' => true,

            'can_masuk' => $canMasuk,

            'can_keluar' => $canKeluar,

            'message' => 'Shift tersedia untuk: ' . implode(', ', $status),

            'shift' => $shift

        ];

    }

    

    return [

        'available' => false,

        'can_masuk' => false,

        'can_keluar' => false,

        'message' => 'Shift ' . $shift['nama_shift'] . ' tidak tersedia pada jam ini. Masuk: ' . 

                     substr($shift['mulai_masuk'], 0, 5) . '-' . substr($shift['akhir_masuk'], 0, 5) . 

                     ', Keluar: ' . substr($shift['mulai_keluar'], 0, 5) . '-' . substr($shift['akhir_keluar'], 0, 5),

        'shift' => $shift

    ];

}



/**

 * Get semua shift yang tersedia berdasarkan waktu sekarang

 * @param mysqli $conn Database connection

 * @param string $currentTime Waktu sekarang (format H:i:s)

 * @return array List shift yang tersedia

 */


function getShiftTersedia($conn, $bagianId, $currentTime = null) {
    if ($currentTime === null) {
        $currentTime = date('H:i:s');
    }

    $stmt = $conn->prepare("
        SELECT id, nama_shift,
               mulai_masuk, akhir_masuk,
               mulai_keluar, akhir_keluar
        FROM shift
        WHERE bagian_id = ?
          AND is_active = 1
        ORDER BY mulai_masuk
    ");

    if (!$stmt) {
        return ['tersedia' => [], 'tidak_tersedia' => [], 'semua' => []];
    }

    $stmt->bind_param("i", $bagianId);
    $stmt->execute();
    $rows = stmtFetchAllAssoc($stmt);
    $stmt->close();

    $shiftTersedia = [];
    $shiftTidakTersedia = [];

    foreach ($rows as $shift) {
        $cek = cekShiftTersedia($conn, $shift['id'], $currentTime);
        $shift['is_available'] = $cek['available'];
        $shift['can_masuk'] = $cek['can_masuk'];
        $shift['can_keluar'] = $cek['can_keluar'];
        $shift['status_message'] = $cek['message'];

        if ($cek['available']) {
            $shiftTersedia[] = $shift;
        } else {
            $shiftTidakTersedia[] = $shift;
        }
    }

    return [
        'tersedia' => $shiftTersedia,
        'tidak_tersedia' => $shiftTidakTersedia,
        'semua' => array_merge($shiftTersedia, $shiftTidakTersedia)
    ];
}


/**

 * Cek status absensi hari ini

 * @param mysqli $conn Database connection

 * @param int $petugasId ID petugas

 * @param int $shiftId ID shift (optional)

 * @param string $tanggal Tanggal (default: hari ini)

 * @return array ['status' => string, 'absensi' => array|null, 'has_laporan' => bool]

 */

function cekAbsensiHariIni($conn, $petugasId, $shiftId = null, $tanggal = null) {

    if ($tanggal === null) {

        $tanggal = date('Y-m-d');

    }

    

    $sql = "

        SELECT a.*, lh.id as laporan_id, lh.kegiatan_harian as isi_laporan

        FROM absensi a

        LEFT JOIN laporan_harian lh ON a.id = lh.absensi_id

        WHERE a.petugas_id = ? AND a.tanggal = ?

    ";

    

    if ($shiftId !== null) {

        $sql .= " AND a.jadwal_id IN (SELECT jp.id FROM jadwal_petugas jp WHERE jp.shift_id = ? AND jp.petugas_id = ? AND jp.tanggal = ?)";

        $stmt = $conn->prepare($sql);

        $stmt->bind_param("isiis", $petugasId, $tanggal, $shiftId, $petugasId, $tanggal);

    } else {

        $stmt = $conn->prepare($sql);

        $stmt->bind_param("is", $petugasId, $tanggal);

    }

    

    $stmt->execute();

    $absensi = stmtFetchAssoc($stmt);
    $stmt->close();

    if (!$absensi) {

        return [

            'status' => 'belum_absen',

            'absensi' => null,

            'has_laporan' => false,

            'message' => 'Belum absen masuk'

        ];

    }

    

    $hasLaporan = !empty($absensi['laporan_id']);

    

    if ($absensi['jam_masuk'] !== null && $absensi['jam_keluar'] === null) {

        return [

            'status' => 'sudah_masuk',

            'absensi' => $absensi,

            'has_laporan' => $hasLaporan,

            'message' => $hasLaporan ? 'Sudah absen masuk, laporan sudah diisi' : 'Sudah absen masuk, isi laporan untuk absen keluar'

        ];

    }

    

    if ($absensi['jam_masuk'] !== null && $absensi['jam_keluar'] !== null) {

        return [

            'status' => 'lengkap',

            'absensi' => $absensi,

            'has_laporan' => $hasLaporan,

            'message' => 'Absensi sudah lengkap untuk shift ini'

        ];

    }

    

    return [

        'status' => 'unknown',

        'absensi' => $absensi,

        'has_laporan' => $hasLaporan,

        'message' => 'Status absensi tidak diketahui'

    ];

}



/**

 * Cek apakah bisa absen keluar (harus ada laporan kegiatan)

 * @param mysqli $conn Database connection

 * @param int $absensiId ID absensi

 * @return array ['allowed' => bool, 'message' => string]

 */

function cekBisaAbsenKeluar($conn, $absensiId) {

    $stmt = $conn->prepare("

        SELECT lh.id 

        FROM laporan_harian lh 

        WHERE lh.absensi_id = ?

    ");

    $stmt->bind_param("i", $absensiId);

    $stmt->execute();

    $row = stmtFetchAssoc($stmt);
    $stmt->close();

    if (!$row) {

        return [

            'allowed' => false,

            'message' => 'Isi laporan kegiatan terlebih dahulu sebelum absen keluar'

        ];

    }

    

    return [

        'allowed' => true,

        'message' => 'Dapat melakukan absen keluar'

    ];

}



/**

 * Get semua bagian aktif

 * @param mysqli $conn Database connection

 * @return array List bagian

 */

function getBagianByAdmin($conn) {

    $koordinatBagianCol = 'bagian_id';
    $checkKoordinatBagianId = $conn->query(
        "SHOW COLUMNS FROM bagian_koordinat LIKE 'bagian_id'"
    );

    if (!$checkKoordinatBagianId || $checkKoordinatBagianId->num_rows === 0) {
        $koordinatBagianCol = 'bagian';
    }

    // ADMIN GLOBAL ? lihat semua bagian
    if (empty($_SESSION['bagian_id'])) {

        $stmt = $conn->prepare("
            SELECT b.*, 
                   (SELECT COUNT(*) 
                    FROM bagian_koordinat bk 
                    WHERE bk.$koordinatBagianCol = b.id 
                      AND bk.is_active = 1) AS jumlah_titik
            FROM bagian b
            WHERE b.is_active = 1
            ORDER BY b.nama_bagian
        ");

    } 

    // ADMIN BAGIAN ? hanya bagian miliknya
    else {

        $bagian_id = (int) $_SESSION['bagian_id'];

        $stmt = $conn->prepare("
            SELECT b.*, 
                   (SELECT COUNT(*) 
                    FROM bagian_koordinat bk 
                    WHERE bk.$koordinatBagianCol = b.id 
                      AND bk.is_active = 1) AS jumlah_titik
            FROM bagian b
            WHERE b.is_active = 1
              AND b.id = ?
        ");

        $stmt->bind_param("i", $bagian_id);
    }

    $stmt->execute();
    $bagian = stmtFetchAllAssoc($stmt);
    $stmt->close();
    return $bagian;

}


/**
 * Get bagian untuk dropdown PETUGAS
 * hanya berdasarkan bagian_id petugas
 */

/**

 * Get koordinat bagian

 * @param mysqli $conn Database connection

 * @param int $bagianId ID bagian

 * @return array List koordinat

 */

function getKoordinatBagian($conn, $bagianId) {

    $koordinatBagianCol = 'bagian_id';

    $checkKoordinatBagianId = $conn->query("SHOW COLUMNS FROM bagian_koordinat LIKE 'bagian_id'");

    if (!$checkKoordinatBagianId || $checkKoordinatBagianId->num_rows === 0) {

        $koordinatBagianCol = 'bagian';

    }



    $stmt = $conn->prepare("

        SELECT * FROM bagian_koordinat 

        WHERE {$koordinatBagianCol} = ? AND is_active = 1

    ");

    $stmt->bind_param("i", $bagianId);

    $stmt->execute();

    $koordinat = stmtFetchAllAssoc($stmt);
    $stmt->close();
    return $koordinat;

}

function getAllKoordinatAktif($conn) {

    $bagianCol = 'bagian_id';
    $cek = $conn->query("SHOW COLUMNS FROM bagian_koordinat LIKE 'bagian_id'");
    if (!$cek || $cek->num_rows === 0) {
        $bagianCol = 'bagian';
    }

    $sql = "
        SELECT 
            bk.*,
            b.nama_bagian,
            b.kode_bagian
        FROM bagian_koordinat bk
        LEFT JOIN bagian b ON bk.$bagianCol = b.id
        WHERE bk.is_active = 1
    ";

    $result = $conn->query($sql);

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    return $data;
}function getKoordinatByBagianPetugas($conn, $bagianId) {

    // cek nama kolom bagian
    $bagianCol = 'bagian_id';
    $cek = $conn->query("SHOW COLUMNS FROM bagian_koordinat LIKE 'bagian_id'");
    if ($cek->num_rows == 0) {
        $bagianCol = 'bagian';
    }

    $stmt = $conn->prepare("
        SELECT bk.*, 
               b.nama_bagian,
               b.kode_bagian
        FROM bagian_koordinat bk
        JOIN bagian b ON b.id = bk.$bagianCol
        WHERE bk.is_active = 1
          AND bk.$bagianCol = ?
    ");

    $stmt->bind_param("i", $bagianId);
    $stmt->execute();
    $rows = stmtFetchAllAssoc($stmt);
    $stmt->close();
    return $rows;
}

/**

 * Get petugas by ID

 * @param mysqli $conn Database connection

 * @param int $petugasId ID petugas

 * @return array|null Petugas data

 */

function getPetugasById($conn, $petugasId) {

    $stmt = $conn->prepare("

        SELECT p.*, b.kode_bagian, b.nama_bagian

        FROM petugas p

        LEFT JOIN bagian b ON p.bagian_id = b.id

        WHERE p.id = ?

    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("i", $petugasId);

    $stmt->execute();

    $petugas = stmtFetchAssoc($stmt);
    $stmt->close();
    return $petugas ?: null;

}



/**

 * Get petugas by NIP

 * @param mysqli $conn Database connection

 * @param string $nip NIP petugas

 * @return array|null Petugas data

 */

function getPetugasByNip($conn, $nip) {

    $stmt = $conn->prepare("

        SELECT p.*, b.kode_bagian, b.nama_bagian

        FROM petugas p

        LEFT JOIN bagian b ON p.bagian_id = b.id

        WHERE p.nip = ? AND p.is_active = 1

    ");

    $stmt->bind_param("s", $nip);

    $stmt->execute();

    $petugas = stmtFetchAssoc($stmt);
    $stmt->close();
    return $petugas ?: null;

}



/**

 * Format waktu untuk display

 * @param string $time Time string

 * @return string Formatted time

 */

function formatWaktu($time) {

    if (empty($time)) return '-';

    return date('H:i', strtotime($time));

}



/**

 * Format tanggal untuk display

 * @param string $date Date string

 * @return string Formatted date

 */

function formatTanggal($date) {

    if (empty($date)) return '-';

    $bulan = [

        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',

        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'

    ];

    $d = date('j', strtotime($date));

    $m = (int)date('n', strtotime($date));

    $y = date('Y', strtotime($date));

    return $d . ' ' . $bulan[$m] . ' ' . $y;

}



/**

 * Get rekap absensi bulanan

 * @param mysqli $conn Database connection

 * @param int $petugasId ID petugas

 * @param int $bulan Bulan (1-12)

 * @param int $tahun Tahun

 * @return array Rekap data

 */

function getRekapBulanan($conn, $petugasId, $bulan, $tahun) {

    $stmt = $conn->prepare("

        SELECT 

            COUNT(*) as total,

            SUM(CASE WHEN status = 'absen masuk' THEN 1 ELSE 0 END) as absen_masuk,

            SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) as hadir,

            SUM(CASE WHEN status = 'izin' THEN 1 ELSE 0 END) as izin,

            SUM(CASE WHEN status = 'sakit' THEN 1 ELSE 0 END) as sakit,

            SUM(CASE WHEN status = 'tidak hadir' THEN 1 ELSE 0 END) as alpha,

            SUM(CASE WHEN status = 'lupa absen' THEN 1 ELSE 0 END) as lupa_absen

        FROM absensi 

        WHERE petugas_id = ? 

        AND MONTH(tanggal) = ? 

        AND YEAR(tanggal) = ?

    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("iii", $petugasId, $bulan, $tahun);

    $stmt->execute();

    $rekap = stmtFetchAssoc($stmt);
    $stmt->close();

    

    return $rekap ?: [];

}

/**
 * Hitung lupa absen untuk tampilan dashboard (reset per bulan)
 * Hanya menghitung lupa absen yang sudah di-approve admin dalam bulan berjalan
 * 
 * @param mysqli $conn Koneksi database
 * @param int $petugasId ID petugas
 * @param int $bulan Bulan (1-12)
 * @param int $tahun Tahun
 * @return int Jumlah lupa absen yang di-approve
 */
function getLupaAbsenDisplayCount($conn, $petugasId, $bulan, $tahun) {
    // Hanya hitung lupa absen yang sudah di-approve admin di bulan ini
    // Data diambil dari tabel pengajuan dengan status 'disetujui' dan jenis 'lupa absen'
    $stmtApproved = $conn->prepare("
        SELECT COUNT(*) as count
        FROM pengajuan 
        WHERE petugas_id = ? 
        AND MONTH(tanggal) = ? 
        AND YEAR(tanggal) = ?
        AND status = 'disetujui'
        AND jenis = 'lupa absen'
    ");
    
    $approvedCount = 0;
    if ($stmtApproved) {
        $stmtApproved->bind_param("iii", $petugasId, $bulan, $tahun);
        $stmtApproved->execute();
        $result = stmtFetchAssoc($stmtApproved);
        $approvedCount = $result['count'] ?? 0;
        $stmtApproved->close();
    }
    
    return $approvedCount;
}



/**

 * Generate random token

 * @param int $length Token length

 * @return string Random token

 */

function generateToken($length = 32) {

    return bin2hex(random_bytes($length / 2));

}



/**

 * Sanitize input

 * @param string $input Input string

 * @return string Sanitized string

 */

function sanitize($input) {

    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');

}



/**

 * JSON response helper

 * @param bool $success Success status

 * @param string $message Message

 * @param array $data Additional data

 * @param int $httpCode HTTP status code

 */

function jsonResponse($success, $message, $data = [], $httpCode = 200) {

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($httpCode);

    header('Content-Type: application/json');

    echo json_encode([

        'success' => $success,

        'message' => $message,

        'data' => $data

    ]);

    exit;

}



/**

 * Check if request is AJAX

 * @return bool

 */

function isAjax() {

    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 

           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

}



/**

 * Redirect with message

 * @param string $url Redirect URL

 * @param string $message Message

 * @param string $type Message type (success, error, warning, info)

 */

function redirectWithMessage($url, $message, $type = 'info') {

    $_SESSION['flash_message'] = $message;

    $_SESSION['flash_type'] = $type;

    header('Location: ' . $url);

    exit;

}



/**

 * Get and clear flash message

 * @return array|null ['message' => string, 'type' => string]

 */

function getFlashMessage() {

    if (isset($_SESSION['flash_message'])) {

        $flash = [

            'message' => $_SESSION['flash_message'],

            'type' => $_SESSION['flash_type'] ?? 'info'

        ];

        unset($_SESSION['flash_message'], $_SESSION['flash_type']);

        return $flash;

    }

    return null;

}

function dbHasColumn($conn, $table, $column) {
    $stmt = $conn->prepare(
        "SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->store_result();
    $ok = ($stmt->num_rows > 0);
    $stmt->close();
    return $ok;
}

function shiftDateTimeTs($tanggal, $time, $rolloverFromTime = null) {
    $base = strtotime($tanggal . ' ' . $time);
    if ($base === false) {
        return null;
    }
    if ($rolloverFromTime === null) {
        return $base;
    }
    if (strcmp($time, $rolloverFromTime) < 0) {
        $nextDay = date('Y-m-d', strtotime($tanggal . ' +1 day'));
        $base = strtotime($nextDay . ' ' . $time);
        return ($base === false) ? null : $base;
    }
    return $base;
}

function syncAbsensiStatusOtomatisPetugasTanggal($conn, $petugasId, $tanggal) {
    $petugasId = (int)$petugasId;
    $tanggal = (string)$tanggal;

    $hasAbsensiShiftId = dbHasColumn($conn, 'absensi', 'shift_id');
    $hasAbsensiShiftNama = dbHasColumn($conn, 'absensi', 'shift');
    $hasAbsensiJadwalId = dbHasColumn($conn, 'absensi', 'jadwal_id');

    $stmtJ = $conn->prepare("SELECT jp.id, jp.shift_id, s.mulai_masuk, s.akhir_masuk, s.mulai_keluar, s.akhir_keluar
                              FROM jadwal_petugas jp
                              LEFT JOIN shift s ON jp.shift_id = s.id
                              WHERE jp.petugas_id = ? AND jp.tanggal = ?
                              LIMIT 1");
    if (!$stmtJ) {
        return;
    }
    $stmtJ->bind_param('is', $petugasId, $tanggal);
    $stmtJ->execute();
    $jadwal = stmtFetchAssoc($stmtJ);
    $stmtJ->close();

    if (!$jadwal) {
        return;
    }

    $jadwalId = isset($jadwal['id']) ? (int)$jadwal['id'] : 0;
    $shiftId = isset($jadwal['shift_id']) ? (int)$jadwal['shift_id'] : 0;

    $mulaiMasuk = $jadwal['mulai_masuk'] ?? null;
    $akhirMasuk = $jadwal['akhir_masuk'] ?? null;
    $mulaiKeluar = $jadwal['mulai_keluar'] ?? null;
    $akhirKeluar = $jadwal['akhir_keluar'] ?? null;

    if (!$mulaiMasuk || !$akhirMasuk || !$mulaiKeluar || !$akhirKeluar) {
        return;
    }

    $akhirMasukTs = shiftDateTimeTs($tanggal, $akhirMasuk, $mulaiMasuk);
    $akhirKeluarTs = shiftDateTimeTs($tanggal, $akhirKeluar, $mulaiKeluar);
    if ($akhirMasukTs === null || $akhirKeluarTs === null) {
        return;
    }

    $nowTs = time();

    $sql = "SELECT id, jam_masuk, jam_keluar, status
            FROM absensi
            WHERE petugas_id = ? AND tanggal = ?";
    $types = 'is';
    $params = [$petugasId, $tanggal];

    if ($hasAbsensiJadwalId && $jadwalId > 0) {
        $sql .= " AND jadwal_id = ?";
        $types .= 'i';
        $params[] = $jadwalId;
    } elseif ($hasAbsensiShiftId && $shiftId > 0) {
        $sql .= " AND shift_id = ?";
        $types .= 'i';
        $params[] = $shiftId;
    }

    $sql .= " ORDER BY id DESC LIMIT 1";
    $stmtA = $conn->prepare($sql);
    if (!$stmtA) {
        return;
    }
    $stmtA->bind_param($types, ...$params);
    $stmtA->execute();
    $absen = stmtFetchAssoc($stmtA);
    $stmtA->close();

    if (!$absen) {
        if ($nowTs <= $akhirMasukTs) {
            return;
        }

        $cols = ['petugas_id', 'tanggal', 'status'];
        $placeholders = ['?', '?', "'tidak hadir'"];
        $typesI = 'is';
        $vals = [$petugasId, $tanggal];

        if ($hasAbsensiJadwalId && $jadwalId > 0) {
            $cols[] = 'jadwal_id';
            $placeholders[] = '?';
            $typesI .= 'i';
            $vals[] = $jadwalId;
        }
        if ($hasAbsensiShiftId && $shiftId > 0) {
            $cols[] = 'shift_id';
            $placeholders[] = '?';
            $typesI .= 'i';
            $vals[] = $shiftId;
        }

        $sqlIns = "INSERT INTO absensi (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
        $stmtIns = $conn->prepare($sqlIns);
        if (!$stmtIns) {
            return;
        }
        $stmtIns->bind_param($typesI, ...$vals);
        $stmtIns->execute();
        $stmtIns->close();
        return;
    }

    $absensiId = (int)$absen['id'];
    $statusNow = isset($absen['status']) ? trim((string)$absen['status']) : '';
    if ($statusNow !== '--' && $statusNow !== 'absen masuk') {
        return;
    }

    $jamMasuk = $absen['jam_masuk'] ?? null;
    $jamKeluar = $absen['jam_keluar'] ?? null;
    $hasMasuk = !empty($jamMasuk);
    $hasKeluar = !empty($jamKeluar);

    $newStatus = $statusNow;
    if (!$hasMasuk && !$hasKeluar) {
        if ($nowTs > $akhirMasukTs) {
            $newStatus = 'tidak hadir';
        }
    } elseif ($hasMasuk && !$hasKeluar) {
        if ($nowTs > $akhirKeluarTs) {
            $newStatus = 'tidak hadir';
        }
    } elseif ($hasMasuk && $hasKeluar) {
        $newStatus = 'hadir';
    } else {
        if ($nowTs > $akhirKeluarTs) {
            $newStatus = 'tidak hadir';
        }
    }

    if ($newStatus !== $statusNow) {
        $stmtU = $conn->prepare("UPDATE absensi SET status = ? WHERE id = ? AND (status = '--' OR status = 'absen masuk')");
        if ($stmtU) {
            $stmtU->bind_param('si', $newStatus, $absensiId);
            $stmtU->execute();
            $stmtU->close();
        }
    }
}

function syncAbsensiStatusOtomatisTanggal($conn, $tanggal, $bagianId = null) {
    $tanggal = (string)$tanggal;

    $sql = "SELECT jp.petugas_id
            FROM jadwal_petugas jp
            JOIN petugas p ON jp.petugas_id = p.id
            WHERE jp.tanggal = ?";
    $types = 's';
    $params = [$tanggal];

    if ($bagianId !== null) {
        $sql .= " AND p.bagian_id = ?";
        $types .= 'i';
        $params[] = (int)$bagianId;
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = stmtFetchAllAssoc($stmt);
    $stmt->close();

    foreach ($rows as $r) {
        if (isset($r['petugas_id'])) {
            syncAbsensiStatusOtomatisPetugasTanggal($conn, (int)$r['petugas_id'], $tanggal);
        }
    }
}