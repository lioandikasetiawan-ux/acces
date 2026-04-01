<?php

// auth/logout.php

session_start();



// 1. Hapus semua data session

$_SESSION = [];

session_unset();

session_destroy();



// 2. Redirect langsung ke halaman login

// Pastikan path-nya benar (karena file ini ada di folder auth, maka login.php ada di sebelahnya)

header("Location: ../petugas/index.php");

exit;

?>