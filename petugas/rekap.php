<?php
require_once '../config/database.php';
require_once '../config/session.php';

require_once '../includes/functions.php';

if (!isset($_SESSION['petugas_id'])) {
    header('Location: ../auth/login-v2.php');
    exit;
}

// RBAC: Hanya Petugas ATAU Admin dengan Bagian yang boleh akses
if ($_SESSION['role'] !== 'petugas' && !($_SESSION['role'] === 'admin' && !empty($_SESSION['bagian_id']))) {
     header('Location: ../auth/login-v2.php');
     exit;
}
$petugasId = $_SESSION['petugas_id'];
$month = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('n');
$year = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');

if ($month < 1 || $month > 12) {
    $month = (int)date('n');
}

if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
}

$bagianTableExists = false;
$petugasBagianIdColumnExists = false;
$petugasBagianColumnExists = false;
$checkBagianTable = $conn->query("SHOW TABLES LIKE 'bagian'");
if ($checkBagianTable && $checkBagianTable->num_rows > 0) {
    $bagianTableExists = true;
}
$checkPetugasBagianId = $conn->query("SHOW COLUMNS FROM petugas LIKE 'bagian_id'");
if ($checkPetugasBagianId && $checkPetugasBagianId->num_rows > 0) {
    $petugasBagianIdColumnExists = true;
}
$checkPetugasBagian = $conn->query("SHOW COLUMNS FROM petugas LIKE 'bagian'");
if ($checkPetugasBagian && $checkPetugasBagian->num_rows > 0) {
    $petugasBagianColumnExists = true;
}
$joinBagian = ($bagianTableExists && $petugasBagianIdColumnExists) ? " LEFT JOIN bagian b ON p.bagian_id = b.id " : "";
$selectBagian = $petugasBagianColumnExists
    ? "p.bagian AS nama_bagian"
    : (($bagianTableExists && $petugasBagianIdColumnExists) ? "COALESCE(b.nama_bagian, '-') AS nama_bagian" : "'-' AS nama_bagian");

$sql = "
    SELECT a.tanggal, {$selectBagian}, s.nama_shift, a.jam_masuk, a.jam_keluar, a.status
    FROM absensi a
    JOIN petugas p ON a.petugas_id = p.id
    {$joinBagian}
    JOIN jadwal_petugas jp ON a.jadwal_id = jp.id
    JOIN shift s ON jp.shift_id = s.id
    WHERE a.petugas_id = ?
      AND MONTH(a.tanggal) = ?
      AND YEAR(a.tanggal) = ?
    ORDER BY a.tanggal DESC, a.jam_masuk DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('iii', $petugasId, $month, $year);
$stmt->execute();
$rows = stmtFetchAllAssoc($stmt);
$stmt->close();

$bulanNama = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Absensi</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50 min-h-screen">
    <div class="bg-white border-b sticky top-0 z-10">
        <div class="max-w-5xl mx-auto px-4 py-4 flex items-center gap-3">
            <a href="dashboard-v2.php" class="text-gray-700"><i class="fas fa-arrow-left"></i></a>
            <div>
                <div class="font-bold text-gray-800">Rekap Absensi</div>
                <div class="text-xs text-gray-500"><?= $bulanNama[$month] ?? $month ?> <?= (int)$year ?></div>
            </div>
        </div>
    </div>

    <main class="max-w-5xl mx-auto px-4 py-6">
        <form method="GET" class="bg-white rounded-xl border p-4 mb-4 grid grid-cols-2 md:grid-cols-4 gap-3">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Bulan</label>
                <select name="bulan" class="w-full border rounded-lg p-2">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= $bulanNama[$m] ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Tahun</label>
                <input type="number" name="tahun" value="<?= (int)$year ?>" class="w-full border rounded-lg p-2" />
            </div>
            <div class="col-span-2 md:col-span-2 flex items-end">
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 rounded-lg">Tampilkan</button>
            </div>
        </form>

        <div class="bg-white rounded-xl border overflow-hidden">
            <div class="px-4 py-3 border-b font-bold text-gray-800 text-sm">Daftar Absensi</div>

            <?php if (empty($rows)): ?>
                <div class="p-6 text-center text-gray-500 text-sm">Belum ada data absensi untuk periode ini.</div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-gray-600">
                            <tr>
                                <th class="text-left px-4 py-3">Tanggal</th>
                                <th class="text-left px-4 py-3">Bagian</th>
                                <th class="text-left px-4 py-3">Shift</th>
                                <th class="text-left px-4 py-3">Masuk</th>
                                <th class="text-left px-4 py-3">Keluar</th>
                                <th class="text-left px-4 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr class="border-t">
                                    <td class="px-4 py-3 whitespace-nowrap"><?= htmlspecialchars($r['tanggal'] ?? '-') ?></td>
                                    <td class="px-4 py-3"><?= htmlspecialchars($r['nama_bagian'] ?? '-') ?></td>
                                    <td class="px-4 py-3"><?= htmlspecialchars($r['nama_shift'] ?? '-') ?></td>
                                    <td class="px-4 py-3 whitespace-nowrap"><?= !empty($r['jam_masuk']) ? date('H:i', strtotime($r['jam_masuk'])) : '-' ?></td>
                                    <td class="px-4 py-3 whitespace-nowrap"><?= !empty($r['jam_keluar']) ? date('H:i', strtotime($r['jam_keluar'])) : '-' ?></td>
                                    <td class="px-4 py-3">
                                        <span class="px-2 py-1 rounded-full text-xs font-bold bg-gray-100 text-gray-700"><?= htmlspecialchars($r['status'] ?? '-') ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
