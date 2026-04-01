<?php

require_once __DIR__ . '/../../config/env.php';

require_once __DIR__ . '/../../config/session.php';



if (
    !isset($_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin','superadmin'], true)
) {
    header("Location: ../../auth/login-v2.php");
    exit;
}


?>

<!DOCTYPE html>

<html lang="id">

<head>



    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Panel - Absensi</title>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/Logo-Acces.png">


    <script src="https://cdn.tailwindcss.com"></script>

    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <?php if (isset($extraHead) && is_string($extraHead)) { echo $extraHead; } ?>

    <style>

        .active-nav { background-color: rgba(255, 255, 255, 0.1); border-left: 4px solid #fbbf24; }

        /* Tweak untuk scrollbar halus */

        ::-webkit-scrollbar { width: 6px; height: 6px; }

        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }

        /* Hide x-cloak elements until Alpine.js loads */
        [x-cloak] { display: none !important; }

    </style>

</head>

<body class="bg-gray-100 font-sans antialiased">

    

    <div x-data="{ sidebarOpen: window.innerWidth >= 768 ? (localStorage.getItem('sidebarDesktop') !== 'false') : false }" class="flex h-screen overflow-hidden">