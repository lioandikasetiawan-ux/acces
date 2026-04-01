<div x-show="sidebarOpen && window.innerWidth < 768" x-transition.opacity @click="sidebarOpen = false" class="fixed inset-0 bg-black bg-opacity-50 z-20 md:hidden" x-cloak></div>

<aside :class="sidebarOpen ? 'translate-x-0 md:w-64' : '-translate-x-full md:translate-x-0 md:w-0 md:overflow-hidden'" class="fixed inset-y-0 left-0 z-30 w-64 bg-slate-800 text-white transition-all duration-300 ease-in-out md:static md:inset-0 flex flex-col shadow-xl flex-shrink-0">
    
    <div class="h-16 flex items-center justify-between px-6 border-b border-slate-700">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-white overflow-hidden flex-shrink-0">
                <img src="<?= BASE_URL ?>/assets/img/Logo-Acces.png" alt="ACCES" class="w-full h-full object-cover">
            </div>
            <div>
                <h1 class="text-sm font-bold tracking-wider">ACCES</h1>
                <p class="text-xs text-gray-400">Admin Panel</p>
            </div>
        </div>
        <button @click="sidebarOpen = false" class="md:hidden text-gray-400"><i class="fas fa-times"></i></button>
    </div>

    <nav class="flex-1 overflow-y-auto py-4">
        <ul class="space-y-1">
            <li>
                <a href="<?= BASE_URL ?>/admin/dashboard.php" class="block py-3 px-6 hover:bg-slate-700 transition flex items-center gap-3">
                    <i class="fas fa-home w-5 text-center"></i> Dashboard
                </a>
            </li>

            <li x-data="{ open: localStorage.getItem('dropdown_master') === 'true' }" x-init="$watch('open', val => localStorage.setItem('dropdown_master', val))">
                <button @click="open = !open" class="w-full flex justify-between items-center py-3 px-6 hover:bg-slate-700 transition text-left focus:outline-none">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-database w-5 text-center"></i> Master Data
                    </div>
                    <i :class="open ? 'rotate-180' : ''" class="fas fa-chevron-down text-xs transition-transform"></i>
                </button>
                <ul x-show="open" x-transition class="bg-slate-900">
                    <li>
                        <a href="<?= BASE_URL ?>/admin/petugas/index.php" class="block py-2 pl-14 pr-4 hover:text-yellow-400 text-sm text-gray-300">
                            Data Petugas & User
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/admin/shift/index.php" class="block py-2 pl-14 pr-4 hover:text-yellow-400 text-sm text-gray-300">
                            Master Shift
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/admin/bagian/index.php" class="block py-2 pl-14 pr-4 hover:text-yellow-400 text-sm text-gray-300">
                            Master Bagian/Lokasi
                        </a>
                    </li>
                </ul>
            </li>

            <li>
                <a href="<?= BASE_URL ?>/admin/jadwal/index.php" class="block py-3 px-6 hover:bg-slate-700 transition flex items-center gap-3">
                    <i class="fas fa-calendar-alt w-5 text-center"></i> Jadwal Petugas
                </a>
            </li>

            <li x-data="{ open: localStorage.getItem('dropdown_monitoring') === 'true' }" x-init="$watch('open', val => localStorage.setItem('dropdown_monitoring', val))">
                <button @click="open = !open" class="w-full flex justify-between items-center py-3 px-6 hover:bg-slate-700 transition text-left focus:outline-none">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-desktop w-5 text-center"></i> Monitoring
                    </div>
                    <i :class="open ? 'rotate-180' : ''" class="fas fa-chevron-down text-xs transition-transform"></i>
                </button>
                <ul x-show="open" x-transition class="bg-slate-900">
                    <li><a href="<?= BASE_URL ?>/admin/absensi/index.php" class="block py-2 pl-14 pr-4 hover:text-yellow-400 text-sm text-gray-300">Rekap Absensi</a></li>
                    <li><a href="<?= BASE_URL ?>/admin/absensi/full-absensi.php" class="block py-2 pl-14 pr-4 hover:text-yellow-400 text-sm text-gray-300">Full Absensi</a></li>
                    <li><a href="<?= BASE_URL ?>/admin/laporan/index.php" class="block py-2 pl-14 pr-4 hover:text-yellow-400 text-sm text-gray-300">Laporan Harian</a></li>
                </ul>
            </li>

            <li x-data="{ open: localStorage.getItem('dropdown_validasi') === 'true' }" x-init="$watch('open', val => localStorage.setItem('dropdown_validasi', val))">
                <button @click="open = !open" class="w-full flex justify-between items-center py-3 px-6 hover:bg-slate-700 transition text-left focus:outline-none">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-check-circle w-5 text-center"></i> Validasi
                    </div>
                    <i :class="open ? 'rotate-180' : ''" class="fas fa-chevron-down text-xs transition-transform"></i>
                </button>
                <ul x-show="open" x-transition class="bg-slate-900">
                    <li><a href="<?= BASE_URL ?>/admin/validasi/kejadian.php" class="block py-2 pl-14 pr-4 hover:text-yellow-400 text-sm text-gray-300">Laporan Kejadian</a></li>
                    <li><a href="<?= BASE_URL ?>/admin/validasi/pengajuan.php" class="block py-2 pl-14 pr-4 hover:text-yellow-400 text-sm text-gray-300">Izin / Sakit / Lupa Absen </a></li>
                </ul>
            </li>

        </ul>
    </nav>

    <div class="p-4 border-t border-slate-700">
                <a href="<?= BASE_URL ?>/auth/logout.php" onclick="return confirm('Keluar dari sistem?')" class="block w-full bg-red-600 hover:bg-red-700 text-white text-center py-2 rounded shadow">
            <i class="fas fa-sign-out-alt mr-2"></i> Logout
        </a>
    </div>
</aside>

<div class="flex-1 flex flex-col overflow-hidden">
    <header class="bg-white shadow h-16 flex items-center justify-between px-4 z-10">
        <div class="flex items-center gap-4">
            <button @click="sidebarOpen = !sidebarOpen; if(window.innerWidth>=768) localStorage.setItem('sidebarDesktop', sidebarOpen)" class="text-gray-500 mr-2"><i class="fas fa-bars text-2xl"></i></button>
            <div class="w-10 h-10 rounded-full bg-gradient-to-r from-blue-600 to-indigo-600 overflow-hidden flex-shrink-0 hidden md:flex items-center justify-center">
                <img src="<?= BASE_URL ?>/assets/img/Logo-Acces.png" alt="ACCES" class="w-full h-full object-cover">
            </div>
            <h2 class="text-gray-700 font-semibold text-lg">Absensi Cimanuk -
Cisanggarung 
Elektronik Sistem</h2>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-sm text-gray-600 hidden sm:block">Halo, <b><?= $_SESSION['nama'] ?></b></span>
            <div class="w-8 h-8 rounded-full bg-slate-800 text-white flex items-center justify-center"><i class="fas fa-user"></i></div>
        </div>
    </header>
    <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-100 p-4 sm:p-6">