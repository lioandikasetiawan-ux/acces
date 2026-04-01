<?php

/**

 * Login V2 - Unified Login dengan NIP sebagai Username

 * =====================================================

 */



require_once '../config/database.php';

require_once '../config/session.php';

require_once '../includes/functions.php';



// Redirect jika sudah login

if (isset($_SESSION['petugas_id'])) {
    // Jika role adalah admin dan memiliki bagian_id, arahkan ke dashboard petugas
    // Jika role adalah admin dan bagian_id NULL (superadmin), arahkan ke dashboard admin
    // Jika role adalah petugas, arahkan ke dashboard petugas
    if ($_SESSION['role'] === 'admin') {
        if (isset($_SESSION['bagian_id']) && $_SESSION['bagian_id'] !== NULL) {
            header('Location: dashboard-v2.php'); // Admin dengan bagian_id ke dashboard petugas
        } else {
            header('Location: ../admin/dashboard.php'); // Superadmin atau admin tanpa bagian_id ke dashboard admin
        }
    } else { // Role petugas
        header('Location: dashboard-v2.php'); // Petugas ke dashboard petugas
    }
    exit;
}



$error = '';



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nip = sanitize($_POST['nip'] ?? '');

    $password = $_POST['password'] ?? '';



    if (empty($nip) || empty($password)) {

        $error = 'NIP dan password wajib diisi';

    } else {

        // Cari petugas berdasarkan NIP

        $stmt = $conn->prepare("

            SELECT p.*, b.kode_bagian, b.nama_bagian

            FROM petugas p

            LEFT JOIN bagian b ON p.bagian_id = b.id

            WHERE p.nip = ?

        ");

        $stmt->bind_param("s", $nip);

        $stmt->execute();

        $petugas = stmtFetchAssoc($stmt);
        $stmt->close();

        if (!$petugas) {

            $error = 'NIP tidak ditemukan atau akun tidak aktif';

        } else {

            if (!password_verify($password, $petugas['password'])) {

                $error = 'Password salah';

            } else if (isset($petugas['is_active']) && (int)$petugas['is_active'] === 0) {

                $error = 'Akun Anda dinonaktifkan. Hubungi admin untuk mengaktifkan kembali.';

            } else {

                // Set session

                $_SESSION['user_id'] = $petugas['id'];

                $_SESSION['petugas_id'] = $petugas['id'];

                $_SESSION['nip'] = $petugas['nip'];

                $_SESSION['nama'] = $petugas['nama'];

                $_SESSION['role'] = $petugas['role'];

                $_SESSION['bagian_id'] = $petugas['bagian_id'];

                $_SESSION['bagian_nama'] = $petugas['nama_bagian'];

                $_SESSION['login_time'] = time();

                

                session_regenerate_id(true);

                

             $redirect = (
    $petugas['role'] === 'admin' && $petugas['bagian_id'] === NULL
) ? '../admin/dashboard.php' : '../petugas/dashboard-v2.php';

header('Location: ' . $redirect);
exit;

            }

        }

    }

}

?>

<!DOCTYPE html>

<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login - Sistem Absensi Lapangan</title>
	<link rel="icon" href="../assets/img/Logo-Acces.png">


    <script src="https://cdn.tailwindcss.com"></script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- PWA Manifest & Meta -->
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#4f46e5">
    <link rel="apple-touch-icon" href="../assets/img/Logo-Acces.png">
    
    <!-- PWA Service Worker Registration -->
    <script>
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

</head>

<body class="min-h-screen bg-gradient-to-br from-blue-600 to-indigo-800 flex items-center justify-center p-4">

    <div class="w-full max-w-md">

        <!-- Logo/Header -->

        <div class="text-center mb-8">

            <div class="inline-flex items-center justify-center w-24 h-24 bg-white rounded-full shadow-lg mb-4 overflow-hidden">

                <img src="../assets/img/Logo-Acces.png" alt="ACCES Logo" class="w-full h-full object-cover">

            </div>

            <h1 class="text-4xl font-bold text-white">ACCES</h1>

            <p class="text-blue-200 mt-1 text-sm">Absensi Cimanuk-Cisanggarung Elektronik Sistem</p>

            <p class="text-blue-200 text-xs mt-1">Sistem Absensi Pegawai Lapangan</p>

        </div>



        <!-- Login Card -->

        <div class="bg-white rounded-2xl shadow-2xl p-8">

            <h2 class="text-2xl font-bold text-gray-800 text-center mb-6">Masuk ke Sistem</h2>



            <?php if ($error): ?>

            <div class="mb-4 p-4 rounded-lg bg-red-100 text-red-700 flex items-center">

                <i class="fas fa-exclamation-circle mr-2"></i>

                <?= htmlspecialchars($error) ?>

            </div>

            <?php endif; ?>



            <form method="POST" class="space-y-5">

                <div>

                    <label class="block text-sm font-medium text-gray-700 mb-2">

                        <i class="fas fa-id-card mr-1"></i> NIP

                    </label>

                    <input type="text" name="nip" required autofocus

                           class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-lg"

                           placeholder="Masukkan NIP Anda"

                           value="<?= htmlspecialchars($_POST['nip'] ?? '') ?>">

                </div>



                <div>

                    <label class="block text-sm font-medium text-gray-700 mb-2">

                        <i class="fas fa-lock mr-1"></i> Password

                    </label>

                    <div class="relative">

                        <input type="password" name="password" id="password" required

                               class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-lg"

                               placeholder="Masukkan password">

                        <button type="button" onclick="togglePassword()" 

                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700">

                            <i class="fas fa-eye" id="toggleIcon"></i>

                        </button>

                    </div>

                </div>



                <button type="submit" 

                        class="w-full bg-blue-600 hover:bg-blue-700 text-white py-4 rounded-xl font-semibold text-lg transition-all shadow-lg hover:shadow-xl">

                    <i class="fas fa-sign-in-alt mr-2"></i> MASUK

                </button>

            </form>



            <div class="mt-6 pt-6 border-t border-gray-200">

                <p class="text-center text-sm text-gray-500">

                    <i class="fas fa-info-circle mr-1"></i>

                    Gunakan NIP sebagai username untuk login

                </p>

            </div>

        </div>



        <!-- Footer -->

        <div class="text-center mt-6 text-blue-200 text-sm">

            <p>&copy; <?= date('Y') ?> Created by : CV.Arindo Pratama</p>

        </div>

    </div>



    <script>

    function togglePassword() {

        const input = document.getElementById('password');

        const icon = document.getElementById('toggleIcon');

        

        if (input.type === 'password') {

            input.type = 'text';

            icon.classList.remove('fa-eye');

            icon.classList.add('fa-eye-slash');

        } else {

            input.type = 'password';

            icon.classList.remove('fa-eye-slash');

            icon.classList.add('fa-eye');

        }

    }

    </script>

</body>

</html>

