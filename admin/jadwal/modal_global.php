<!-- Modal Jadwal Global -->
<div id="modalJadwalGlobal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
<div class="bg-white rounded-xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
<div class="p-6 border-b flex items-center justify-between sticky top-0 bg-white">
<h3 class="text-xl font-bold text-gray-800"><i class="fas fa-calendar-plus mr-2 text-green-600"></i>Input Jadwal Global</h3>
<button onclick="closeGlobalModal()" class="text-gray-500 hover:text-gray-700"><i class="fas fa-times text-xl"></i></button>
</div>
<form id="formJadwalGlobal" class="p-6">
<input type="hidden" name="petugas_id" value="<?= $petugasId ?>">
<input type="hidden" name="bulan" value="<?= $bulan ?>">
<input type="hidden" name="tahun" value="<?= $tahun ?>">
<div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-4">
<p class="text-sm text-blue-800"><i class="fas fa-info-circle mr-1"></i>
Membuat jadwal untuk <strong>seluruh hari</strong> di bulan <strong><?= $namaBulan[$bulan] ?> <?= $tahun ?></strong> dengan shift dan lokasi yang sama.</p>
</div>
<div class="mb-4">
<label class="block text-sm font-bold text-gray-700 mb-2">Periode</label>
<input type="text" readonly value="<?= $namaBulan[$bulan] ?> <?= $tahun ?> (<?= $jumlahHari ?> hari)" class="w-full border rounded-lg px-3 py-2 bg-gray-50 text-sm">
</div>
<div class="mb-4">
<label class="block text-sm font-bold text-gray-700 mb-2">Shift Default <span class="text-red-500">*</span></label>
<select name="shift_id" id="globalShiftId" required class="w-full border rounded-lg px-3 py-2 outline-none focus:ring-2 focus:ring-green-500">
<option value="">-- Pilih Shift --</option>
<?php foreach ($listShift as $shift): ?>
<option value="<?= $shift['id'] ?>"><?= htmlspecialchars($shift['nama_shift']) ?></option>
<?php endforeach; ?>
</select>
</div>
    <div class="mb-4">
        <label class="block text-sm font-bold text-gray-700 mb-2">Lokasi Default <span class="text-red-500">*</span></label>
        <div class="text-xs text-gray-500 mb-2">Pilih satu atau lebih lokasi</div>
        
        <!-- Search Box Lokasi Global -->
        <div class="relative mb-2">
            <input type="text" id="searchLokasiGlobal" placeholder="Cari lokasi..." 
                   class="w-full border rounded-lg pl-10 pr-3 py-1.5 text-sm outline-none focus:ring-2 focus:ring-green-500">
            <i class="fas fa-search absolute left-3 top-2.5 text-gray-400 text-xs"></i>
        </div>

        <div class="border rounded-lg p-3 max-h-48 overflow-y-auto" id="containerLokasiGlobal">
            <?php foreach ($listLokasi as $lok): ?>
            <label class="flex items-center py-2 hover:bg-gray-50 px-2 rounded cursor-pointer global-lokasi-item">
                <input type="checkbox" name="lokasi_ids[]" value="<?= $lok['id'] ?>" class="mr-2 global-lokasi-cb">
                <span class="text-sm global-lokasi-nama"><?= htmlspecialchars($lok['nama_titik']) ?></span>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
<div class="mb-4">
<label class="flex items-center gap-2 cursor-pointer">
<input type="checkbox" name="replace_existing" value="1" id="cbReplace" class="w-4 h-4">
<span class="text-sm font-semibold text-red-700">Timpa jadwal yang sudah ada</span>
</label>
<p class="text-xs text-gray-500 mt-1 ml-6">Jika tidak dicentang, tanggal yang sudah memiliki jadwal akan dilewati.</p>
</div>
<div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 mb-4 hidden" id="warnReplace">
<p class="text-xs text-yellow-800"><i class="fas fa-exclamation-triangle mr-1"></i><strong>Perhatian:</strong> Jadwal harian yang sudah ada akan ditimpa! Override harian tetap bisa dilakukan setelahnya.</p>
</div>
<div class="flex gap-3">
<button type="submit" id="btnSimpanGlobal" class="flex-1 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition font-semibold">
<i class="fas fa-magic mr-2"></i>Generate Jadwal 1 Bulan
</button>
<button type="button" onclick="closeGlobalModal()" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg transition">Batal</button>
</div>
</form>
</div>
</div>
