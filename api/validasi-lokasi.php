<?php
/**
 * API: Validasi Lokasi
 * ====================
 * Cek apakah koordinat user berada dalam radius salah satu titik bagian
 */

require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Cek login
if (!isset($_SESSION['petugas_id'])) {
    jsonResponse(false, 'Unauthorized', [], 401);
}

// Ambil parameter
$bagianId = (int)($_GET['bagian_id'] ?? $_POST['bagian_id'] ?? 0);
$latitude = (float)($_GET['latitude'] ?? $_POST['latitude'] ?? 0);
$longitude = (float)($_GET['longitude'] ?? $_POST['longitude'] ?? 0);

if ($bagianId <= 0) {
    jsonResponse(false, 'Parameter bagian_id diperlukan', [], 400);
}

if ($latitude == 0 || $longitude == 0) {
    jsonResponse(false, 'Parameter latitude dan longitude diperlukan', [], 400);
}

try {
    $result = validasiLokasi($conn, $latitude, $longitude, $bagianId);
    
    // Get semua titik untuk info
    $koordinatList = getKoordinatBagian($conn, $bagianId);
    
    jsonResponse($result['valid'], $result['message'], [
        'valid' => $result['valid'],
        'titik_id' => $result['titik_id'],
        'nama_titik' => $result['nama_titik'] ?? null,
        'jarak' => $result['jarak'],
        'user_koordinat' => [
            'latitude' => $latitude,
            'longitude' => $longitude
        ],
        'titik_tersedia' => array_map(function($k) {
            return [
                'id' => $k['id'],
                'nama' => $k['nama_titik'],
                'latitude' => $k['latitude'],
                'longitude' => $k['longitude'],
                'radius' => $k['radius_meter']
            ];
        }, $koordinatList)
    ]);
} catch (Exception $e) {
    jsonResponse(false, 'Error: ' . $e->getMessage(), [], 500);
}
?>
