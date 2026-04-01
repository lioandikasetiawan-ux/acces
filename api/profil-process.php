<?php
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

// Pastikan hanya petugas yang akses
if ($_SESSION['role'] !== 'petugas') {
    header("Location: ../auth/login-v2.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_petugas = $_SESSION['petugas_id'];
    
    // 1. Ambil Input
    $alamat_baru = isset($_POST['alamat']) ? htmlspecialchars($_POST['alamat']) : '';
    $password_lama = isset($_POST['password_lama']) ? $_POST['password_lama'] : '';
    $password_baru = $_POST['password_baru'];

    // Mulai Transaksi
    $conn->begin_transaction();

    try {
        // 2. Update Data Profil (Alamat)
        if ($alamat_baru !== '') {
            $stmt1 = $conn->prepare("UPDATE petugas SET alamat = ? WHERE id = ?");
            $stmt1->bind_param("si", $alamat_baru, $id_petugas);
            if (!$stmt1->execute()) {
                throw new Exception("Gagal update alamat.");
            }
        }

        // 3. Update Password (Jika Diisi)
        if (!empty($password_baru)) {
            if (empty($password_lama)) {
                throw new Exception("Password lama wajib diisi untuk mengganti password.");
            }

            $stmtCheck = $conn->prepare("SELECT password FROM petugas WHERE id = ? LIMIT 1");
            $stmtCheck->bind_param("i", $id_petugas);
            if (!$stmtCheck->execute()) {
                throw new Exception("Gagal validasi password lama.");
            }
            $user = stmtFetchAssoc($stmtCheck);
            $stmtCheck->close();
            if (!$user || !isset($user['password']) || !password_verify($password_lama, $user['password'])) {
                throw new Exception("Password lama tidak sesuai.");
            }

            // Hash password baru
            $hash_password = password_hash($password_baru, PASSWORD_BCRYPT);
            
            $stmt2 = $conn->prepare("UPDATE petugas SET password = ? WHERE id = ?");
            $stmt2->bind_param("si", $hash_password, $id_petugas);
            
            if (!$stmt2->execute()) {
                throw new Exception("Gagal update password.");
            }
        }

        // Komit Transaksi
        $conn->commit();
        
        echo "<script>
            alert('Profil berhasil diperbarui!'); 
            window.location.href='../petugas/profil.php';
        </script>";

    } catch (Exception $e) {
        $conn->rollback();
        echo "<script>
            alert('Error: " . $e->getMessage() . "'); 
            window.history.back();
        </script>";
    }
}
?>