<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$extraHead = "\n<link rel=\"stylesheet\" href=\"https://unpkg.com/leaflet@1.9.4/dist/leaflet.css\" />\n" .
             "<script src=\"https://unpkg.com/leaflet@1.9.4/dist/leaflet.js\"></script>\n" .
             "<style>#map { height: 400px; border-radius: 0.5rem; }</style>\n";

require_once '../layout/header.php';
require_once '../layout/sidebar.php';

$bagianId = (int)($_GET['bagian_id'] ?? 0);
if (!$bagianId) {
    redirectWithMessage('index.php', 'ID bagian tidak valid', 'error');
}

$koordinatBagianCol = 'bagian_id';
$checkKoordinatBagianId = $conn->query("SHOW COLUMNS FROM bagian_koordinat LIKE 'bagian_id'");
if (!$checkKoordinatBagianId || $checkKoordinatBagianId->num_rows === 0) {
    $koordinatBagianCol = 'bagian';
}

// Get bagian data
$stmt = $conn->prepare("SELECT * FROM bagian WHERE id = ?");
$stmt->bind_param("i", $bagianId);
$stmt->execute();
$bagian = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$bagian) {
    redirectWithMessage('index.php', 'Bagian tidak ditemukan', 'error');
}

$flash = getFlashMessage();
$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $namaTitik = sanitize($_POST['nama_titik'] ?? '');
        $latitude = (float)($_POST['latitude'] ?? 0);
        $longitude = (float)($_POST['longitude'] ?? 0);
        $radius = (int)($_POST['radius_meter'] ?? 1000);
        
        if (empty($namaTitik) || $latitude == 0 || $longitude == 0) {
            $error = 'Nama titik, latitude, dan longitude wajib diisi';
        } else {
            $stmt = $conn->prepare("INSERT INTO bagian_koordinat ({$koordinatBagianCol}, nama_titik, latitude, longitude, radius_meter) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("isddi", $bagianId, $namaTitik, $latitude, $longitude, $radius);
            
            if ($stmt->execute()) {
                $success = 'Titik koordinat berhasil ditambahkan';
            } else {
                $error = 'Gagal menyimpan: ' . $conn->error;
            }
            $stmt->close();
        }
    } elseif ($action === 'delete') {
        $koordinatId = (int)($_POST['koordinat_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM bagian_koordinat WHERE id = ? AND {$koordinatBagianCol} = ?");
        $stmt->bind_param("ii", $koordinatId, $bagianId);
        
        if ($stmt->execute()) {
            $success = 'Titik koordinat berhasil dihapus';
        } else {
            $error = 'Gagal menghapus: ' . $conn->error;
        }
        $stmt->close();
    } elseif ($action === 'edit') {
        $koordinatId = (int)($_POST['koordinat_id'] ?? 0);
        $namaTitik = sanitize($_POST['nama_titik'] ?? '');
        $latitude = (float)($_POST['latitude'] ?? 0);
        $longitude = (float)($_POST['longitude'] ?? 0);
        $radius = (int)($_POST['radius_meter'] ?? 1000);
        
        if (empty($namaTitik) || $latitude == 0 || $longitude == 0) {
            $error = 'Nama titik, latitude, dan longitude wajib diisi';
        } else {
            $stmt = $conn->prepare("UPDATE bagian_koordinat SET nama_titik = ?, latitude = ?, longitude = ?, radius_meter = ? WHERE id = ? AND {$koordinatBagianCol} = ?");
            $stmt->bind_param("sddiii", $namaTitik, $latitude, $longitude, $radius, $koordinatId, $bagianId);
            if ($stmt->execute()) {
                $success = 'Titik koordinat berhasil diperbarui';
            } else {
                $error = 'Gagal memperbarui: ' . $conn->error;
            }
            $stmt->close();
        }
    } elseif ($action === 'toggle') {
        $koordinatId = (int)($_POST['koordinat_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE bagian_koordinat SET is_active = NOT is_active WHERE id = ? AND {$koordinatBagianCol} = ?");
        $stmt->bind_param("ii", $koordinatId, $bagianId);
        $stmt->execute();
        $stmt->close();
        $success = 'Status titik berhasil diubah';
    }
}

// Get koordinat list
$stmt = $conn->prepare("SELECT * FROM bagian_koordinat WHERE {$koordinatBagianCol} = ? ORDER BY nama_titik");
$stmt->bind_param("i", $bagianId);
$stmt->execute();
$koordinatList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<div class="p-8">
        <div class="mb-6">
            <a href="index.php" class="text-blue-600 hover:underline"><i class="fas fa-arrow-left mr-2"></i>Kembali ke Daftar Bagian</a>
            <h1 class="text-2xl font-bold text-gray-800 mt-2">
                Kelola Titik Koordinat: <?= htmlspecialchars($bagian['nama_bagian']) ?>
                <span class="text-sm font-normal text-gray-500">(<?= htmlspecialchars($bagian['kode_bagian']) ?>)</span>
            </h1>
            <p class="text-gray-600">Petugas dapat absen jika berada dalam radius salah satu titik berikut</p>
        </div>

        <?php if ($error): ?>
        <div class="mb-4 p-4 rounded-lg bg-red-100 text-red-700"><?= $error ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="mb-4 p-4 rounded-lg bg-green-100 text-green-700"><?= $success ?></div>
        <?php endif; ?>
        <?php if ($flash): ?>
        <div class="mb-4 p-4 rounded-lg <?= $flash['type'] === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
            <?= $flash['message'] ?>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Form Tambah Titik -->
            <div class="bg-white rounded-xl shadow-md p-6">
                <h2 class="text-lg font-semibold mb-4"><i class="fas fa-plus-circle text-green-600 mr-2"></i>Tambah Titik Baru</h2>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="add">
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nama Titik *</label>
                        <input type="text" name="nama_titik" required maxlength="100"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                               placeholder="Contoh: Pos Utama, Gerbang Selatan">
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Latitude *</label>
                            <input type="number" name="latitude" id="input_lat" required step="any"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                   placeholder="-6.2088">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Longitude *</label>
                            <input type="number" name="longitude" id="input_lng" required step="any"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                                   placeholder="106.8456">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Radius (meter)</label>
                        <input type="number" name="radius_meter" value="1000" min="100" max="5000"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <p class="text-xs text-gray-500 mt-1">Default 1000m (1km). Min 100m, Max 5000m</p>
                    </div>

                    <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-map-marker-alt mr-2"></i>Tambah Titik
                    </button>
                </form>

                <div class="mt-4 p-3 bg-blue-50 rounded-lg">
                    <p class="text-sm text-blue-700">
                        <i class="fas fa-info-circle mr-1"></i>
                        <strong>Tip:</strong> Klik pada peta untuk mengisi koordinat otomatis
                    </p>
                </div>
            </div>

            <!-- Peta -->
            <div class="bg-white rounded-xl shadow-md p-6">
                <h2 class="text-lg font-semibold mb-4"><i class="fas fa-map text-blue-600 mr-2"></i>Peta Lokasi</h2>
                <div id="map"></div>
                <p class="text-xs text-gray-500 mt-2">Klik pada peta untuk memilih lokasi. Lingkaran menunjukkan radius absensi.</p>
            </div>
        </div>

        <!-- Daftar Titik -->
        <div class="mt-6 bg-white rounded-xl shadow-md overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <h2 class="text-lg font-semibold"><i class="fas fa-list text-gray-600 mr-2"></i>Daftar Titik Koordinat (<?= count($koordinatList) ?> titik)</h2>
                <div class="relative">
                    <input type="text" id="searchTitik" placeholder="Cari nama titik..." 
                           class="border rounded-lg pl-10 pr-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-500 w-full sm:w-64">
                    <i class="fas fa-search absolute left-3 top-3 text-gray-400 text-xs"></i>
                </div>
            </div>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nama Titik</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Koordinat</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Radius</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Aksi</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($koordinatList)): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                            Belum ada titik koordinat. Tambahkan titik baru di atas.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($koordinatList as $k): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900">
                            <?= htmlspecialchars($k['nama_titik']) ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <code class="bg-gray-100 px-2 py-1 rounded"><?= $k['latitude'] ?>, <?= $k['longitude'] ?></code>
                            <a href="https://www.google.com/maps?q=<?= $k['latitude'] ?>,<?= $k['longitude'] ?>" target="_blank" 
                               class="ml-2 text-blue-600 hover:underline text-xs">
                                <i class="fas fa-external-link-alt"></i>
                            </a>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?= number_format($k['radius_meter']) ?> m
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php if ($k['is_active']): ?>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Aktif</span>
                            <?php else: ?>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">Nonaktif</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <button onclick='openEditKoordinat(<?= json_encode($k) ?>)' 
                                    class="text-green-600 hover:text-green-900 mr-3" title="Edit Titik">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="koordinat_id" value="<?= $k['id'] ?>">
                                <button type="submit" class="text-yellow-600 hover:text-yellow-900 mr-3" title="Toggle Status">
                                    <i class="fas fa-toggle-<?= $k['is_active'] ? 'on' : 'off' ?>"></i>
                                </button>
                            </form>
                            <button onclick="focusOnMap(<?= $k['latitude'] ?>, <?= $k['longitude'] ?>, <?= $k['radius_meter'] ?>)" 
                                    class="text-blue-600 hover:text-blue-900 mr-3" title="Lihat di Peta">
                                <i class="fas fa-crosshairs"></i>
                            </button>
                            <form method="POST" class="inline" onsubmit="return confirm('Yakin hapus titik ini?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="koordinat_id" value="<?= $k['id'] ?>">
                                <button type="submit" class="text-red-600 hover:text-red-900" title="Hapus">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal Edit Koordinat -->
    <div id="modalEditKoordinat" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 10000;">
        <div class="bg-white rounded-xl shadow-2xl max-w-lg w-full">
            <div class="p-5 border-b flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-800"><i class="fas fa-edit text-green-600 mr-2"></i>Edit Titik Koordinat</h3>
                <button onclick="closeEditKoordinat()" class="text-gray-500 hover:text-gray-700"><i class="fas fa-times text-xl"></i></button>
            </div>
            <form method="POST" class="p-5 space-y-4">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="koordinat_id" id="edit_koordinat_id">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nama Titik *</label>
                    <input type="text" name="nama_titik" id="edit_nama_titik" required maxlength="100"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Latitude *</label>
                        <input type="number" name="latitude" id="edit_latitude" required step="any"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Longitude *</label>
                        <input type="number" name="longitude" id="edit_longitude" required step="any"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Radius (meter)</label>
                    <input type="number" name="radius_meter" id="edit_radius" min="100" max="5000"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="flex gap-3">
                    <button type="submit" class="flex-1 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-semibold">
                        <i class="fas fa-save mr-2"></i>Simpan Perubahan
                    </button>
                    <button type="button" onclick="closeEditKoordinat()" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg">
                        Batal
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // Search/filter titik koordinat
    document.getElementById('searchTitik')?.addEventListener('input', function() {
        const filter = this.value.toLowerCase();
        const rows = document.querySelectorAll('tbody tr.hover\\:bg-gray-50, tbody tr[class*="hover"]');
        rows.forEach(row => {
            const namaTitik = row.querySelector('td')?.textContent?.toLowerCase() || '';
            row.style.display = namaTitik.includes(filter) ? '' : 'none';
        });
    });

    // Open edit modal
    function openEditKoordinat(data) {
        document.getElementById('edit_koordinat_id').value = data.id;
        document.getElementById('edit_nama_titik').value = data.nama_titik;
        document.getElementById('edit_latitude').value = data.latitude;
        document.getElementById('edit_longitude').value = data.longitude;
        document.getElementById('edit_radius').value = data.radius_meter;
        document.getElementById('modalEditKoordinat').classList.remove('hidden');
    }

    // Close edit modal
    function closeEditKoordinat() {
        document.getElementById('modalEditKoordinat').classList.add('hidden');
    }

    // Initialize map
    const koordinatList = <?= json_encode($koordinatList) ?>;
    let map, markers = [], circles = [];

    // Default center (Jakarta)
    let center = [-6.2088, 106.8456];
    if (koordinatList.length > 0) {
        center = [parseFloat(koordinatList[0].latitude), parseFloat(koordinatList[0].longitude)];
    }

    map = L.map('map').setView(center, 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap'
    }).addTo(map);

    // Add markers for each koordinat
    koordinatList.forEach(function(k) {
        const lat = parseFloat(k.latitude);
        const lng = parseFloat(k.longitude);
        const radius = parseInt(k.radius_meter);
        
        const marker = L.marker([lat, lng]).addTo(map)
            .bindPopup('<strong>' + k.nama_titik + '</strong><br>Radius: ' + radius + 'm');
        markers.push(marker);

        const circle = L.circle([lat, lng], {
            color: k.is_active == 1 ? 'blue' : 'gray',
            fillColor: k.is_active == 1 ? '#3b82f6' : '#9ca3af',
            fillOpacity: 0.2,
            radius: radius
        }).addTo(map);
        circles.push(circle);
    });

    // Fit bounds if there are markers
    if (markers.length > 0) {
        const group = new L.featureGroup(markers);
        map.fitBounds(group.getBounds().pad(0.1));
    }

    // Click on map to get coordinates
    map.on('click', function(e) {
        document.getElementById('input_lat').value = e.latlng.lat.toFixed(8);
        document.getElementById('input_lng').value = e.latlng.lng.toFixed(8);
    });

    function focusOnMap(lat, lng, radius) {
        map.setView([lat, lng], 16);
    }
    </script>

<?php require_once '../layout/footer.php'; ?>
