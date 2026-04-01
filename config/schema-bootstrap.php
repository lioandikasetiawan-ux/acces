<?php
/**
 * Schema Bootstrap — Cached column/table existence checks
 * 
 * DESIGN: Schema runtime checks (SHOW COLUMNS / SHOW TABLES) dinonaktifkan 
 * untuk menjaga latency absensi realtime tetap rendah.
 * 
 * Schema checks hanya dijalankan:
 * 1. Saat pertama kali session dibuat (login)
 * 2. Saat cache expired (TTL 10 menit)
 * 
 * Endpoint realtime (partial AJAX, update-status, submit absensi) 
 * TIDAK PERNAH menjalankan SHOW COLUMNS / SHOW TABLES.
 * Mereka hanya membaca dari $_SESSION['schema_cache'].
 */

function getSchemaCache($conn, $forceRefresh = false) {
    $cacheKey = 'schema_cache';
    $cacheTTL = 600; // 10 menit

    // Gunakan cache jika ada dan belum expired
    if (!$forceRefresh && isset($_SESSION[$cacheKey]) && isset($_SESSION[$cacheKey]['_ts']) 
        && (time() - $_SESSION[$cacheKey]['_ts']) < $cacheTTL) {
        return $_SESSION[$cacheKey];
    }

    // Jalankan schema check sekali, lalu simpan ke session
    $cache = ['_ts' => time()];

    $r = $conn->query("SHOW TABLES LIKE 'shift'");
    $cache['shiftTableExists'] = ($r && $r->num_rows > 0);

    $r = $conn->query("SHOW COLUMNS FROM absensi LIKE 'shift_id'");
    $cache['absensiShiftIdExists'] = ($r && $r->num_rows > 0);

    $r = $conn->query("SHOW TABLES LIKE 'bagian'");
    $cache['bagianTableExists'] = ($r && $r->num_rows > 0);

    $r = $conn->query("SHOW COLUMNS FROM petugas LIKE 'bagian'");
    $cache['petugasBagianColumnExists'] = ($r && $r->num_rows > 0);

    $r = $conn->query("SHOW COLUMNS FROM petugas LIKE 'bagian_id'");
    $cache['petugasBagianIdColumnExists'] = ($r && $r->num_rows > 0);

    $r1 = $conn->query("SHOW COLUMNS FROM absensi LIKE 'lat_masuk'");
    $r2 = $conn->query("SHOW COLUMNS FROM absensi LIKE 'long_masuk'");
    $cache['hasLokasiMasukV2'] = ($r1 && $r1->num_rows > 0) && ($r2 && $r2->num_rows > 0);

    $r = $conn->query("SHOW COLUMNS FROM absensi LIKE 'foto_masuk'");
    $cache['hasFotoMasuk'] = ($r && $r->num_rows > 0);

    $r1 = $conn->query("SHOW COLUMNS FROM absensi LIKE 'latitude_keluar'");
    $r2 = $conn->query("SHOW COLUMNS FROM absensi LIKE 'longitude_keluar'");
    $hasLatKeluar = ($r1 && $r1->num_rows > 0);
    $hasLongKeluar = ($r2 && $r2->num_rows > 0);

    $r3 = $conn->query("SHOW COLUMNS FROM absensi LIKE 'lat_keluar'");
    $r4 = $conn->query("SHOW COLUMNS FROM absensi LIKE 'long_keluar'");
    $hasLatKeluarV2 = ($r3 && $r3->num_rows > 0);
    $hasLongKeluarV2 = ($r4 && $r4->num_rows > 0);

    $cache['hasLatKeluar'] = $hasLatKeluar;
    $cache['hasLongKeluar'] = $hasLongKeluar;
    $cache['hasLatKeluarV2'] = $hasLatKeluarV2;
    $cache['hasLongKeluarV2'] = $hasLongKeluarV2;
    $cache['hasLokasiKeluar'] = ($hasLatKeluar && $hasLongKeluar) || ($hasLatKeluarV2 && $hasLongKeluarV2);

    $_SESSION[$cacheKey] = $cache;
    return $cache;
}

/**
 * Ambil schema cache TANPA menjalankan SHOW queries.
 * Digunakan oleh endpoint realtime (partial, AJAX).
 * Jika cache belum ada, gunakan default values yang aman.
 */
function getSchemaCacheReadOnly() {
    $cacheKey = 'schema_cache';
    if (isset($_SESSION[$cacheKey])) {
        return $_SESSION[$cacheKey];
    }
    // Default fallback — asumsi semua kolom ada (safe default)
    return [
        '_ts' => 0,
        'shiftTableExists' => true,
        'absensiShiftIdExists' => true,
        'bagianTableExists' => true,
        'petugasBagianColumnExists' => false,
        'petugasBagianIdColumnExists' => true,
        'hasLokasiMasukV2' => true,
        'hasFotoMasuk' => true,
        'hasLatKeluar' => false,
        'hasLongKeluar' => false,
        'hasLatKeluarV2' => true,
        'hasLongKeluarV2' => true,
        'hasLokasiKeluar' => true,
    ];
}
?>
