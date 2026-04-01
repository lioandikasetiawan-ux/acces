<?php
/**
 * Login Process V2 - Menggunakan tabel petugas (NIP sebagai username)
 * ===================================================================
 */

require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Hanya terima POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed', [], 405);
}

// Ambil input
$input = json_decode(file_get_contents('php://input'), true);

// Fallback ke form data jika bukan JSON
if (empty($input)) {
    $input = $_POST;
}

$nip = sanitize($input['nip'] ?? $input['username'] ?? '');
$password = $input['password'] ?? '';

// Validasi input
if (empty($nip) || empty($password)) {
    jsonResponse(false, 'NIP dan password wajib diisi', [], 400);
}

try {
    // Cari petugas berdasarkan NIP
    $stmt = $conn->prepare("
        SELECT p.*, b.kode_bagian,b.kode_sync, b.nama_bagian
        FROM petugas p
        LEFT JOIN bagian b ON p.bagian_id = b.id
        WHERE p.nip = ?
    ");
    $stmt->bind_param("s", $nip);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        jsonResponse(false, 'NIP tidak ditemukan atau akun tidak aktif', [], 401);
    }
    
    $petugas = $result->fetch_assoc();
    $stmt->close();
    
    // Verifikasi password
    if (!password_verify($password, $petugas['password'])) {
        jsonResponse(false, 'Password salah', [], 401);
    }
    
    // Cek apakah petugas aktif
    if (isset($petugas['is_active']) && (int)$petugas['is_active'] === 0) {
        jsonResponse(false, 'Akun Anda dinonaktifkan. Hubungi admin untuk mengaktifkan kembali.', [], 403);
    }
    // Set session
    $_SESSION['user_id'] = $petugas['id'];
    $_SESSION['petugas_id'] = $petugas['id'];
    $_SESSION['nip'] = $petugas['nip'];
    $_SESSION['nama'] = $petugas['nama'];
    $_SESSION['role'] = $petugas['role'];
    $_SESSION['bagian_id'] = $petugas['bagian_id'];
    $_SESSION['kode_sync'] = $petugas['kode_sync'];
    $_SESSION['bagian_nama'] = $petugas['nama_bagian'];
    $_SESSION['login_time'] = time();
    
    // Regenerate session ID untuk keamanan
    session_regenerate_id(true);
    
    // Tentukan redirect berdasarkan role
    if ($petugas['role'] === 'admin' && $petugas['bagian_id'] === NULL) {
        $redirectUrl = BASE_URL . '/admin/dashboard.php';
    } else {
        $redirectUrl = BASE_URL . '/petugas/dashboard-v2.php';
    }
    
    jsonResponse(true, 'Login berhasil!', [
        'petugas_id' => $petugas['id'],
        'nama' => $petugas['nama'],
        'nip' => $petugas['nip'],
        'role' => $petugas['role'],
        'bagian' => $petugas['nama_bagian'],
        'kode_sync' => $petugas['kode_sync'],
        'redirect' => $redirectUrl
    ]);
    
} catch (Exception $e) {
    jsonResponse(false, 'Terjadi kesalahan sistem: ' . $e->getMessage(), [], 500);
}
?>
