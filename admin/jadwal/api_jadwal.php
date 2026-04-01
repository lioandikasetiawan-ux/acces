<?php
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (
    !isset($_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin','superadmin'], true)
) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$role = $_SESSION['role'];
$bagianId = $_SESSION['bagian_id'] ?? null;
$userId = $_SESSION['user_id'] ?? null;

$action = $_REQUEST['action'] ?? '';

// GET: Ambil data jadwal by ID
if ($action === 'get' && isset($_GET['id'])) {
    $jadwalId = (int)$_GET['id'];
    
    $stmt = $conn->prepare("SELECT * FROM jadwal_petugas WHERE id = ?");
    $stmt->bind_param("i", $jadwalId);
    $stmt->execute();
    $jadwal = stmtFetchAssoc($stmt);
    $stmt->close();
    
    if (!$jadwal) {
        echo json_encode(['success' => false, 'message' => 'Jadwal tidak ditemukan']);
        exit;
    }
    
    // Validasi akses: admin hanya bisa akses jadwal petugas di bagiannya
    if ($bagianId !== null) {
        $stmtCheck = $conn->prepare("SELECT bagian_id FROM petugas WHERE id = ?");
        $stmtCheck->bind_param("i", $jadwal['petugas_id']);
        $stmtCheck->execute();
        $petugas = stmtFetchAssoc($stmtCheck);
        $stmtCheck->close();
        
        if (!$petugas || $petugas['bagian_id'] != $bagianId) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
            exit;
        }
    }
    
    // Ambil lokasi
    $stmtLok = $conn->prepare("SELECT * FROM jadwal_lokasi WHERE jadwal_id = ? ORDER BY urutan");
    $stmtLok->bind_param("i", $jadwalId);
    $stmtLok->execute();
    $lokasi = stmtFetchAllAssoc($stmtLok);
    $stmtLok->close();
    
    echo json_encode([
        'success' => true,
        'jadwal' => $jadwal,
        'lokasi' => $lokasi
    ]);
    exit;
}

// SAVE: Simpan atau update jadwal
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $jadwalId = !empty($_POST['jadwal_id']) ? (int)$_POST['jadwal_id'] : null;
    $petugasId = (int)$_POST['petugas_id'];
    $tanggal = $_POST['tanggal'];
    $shiftId = !empty($_POST['shift_id']) ? (int)$_POST['shift_id'] : null;
    $keterangan = trim($_POST['keterangan'] ?? '');
    $lokasiIds = $_POST['lokasi_ids'] ?? [];
    
    // Validasi
    if (!$petugasId || !$tanggal) {
        echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
        exit;
    }
    
    if (empty($lokasiIds)) {
        echo json_encode(['success' => false, 'message' => 'Pilih minimal 1 lokasi']);
        exit;
    }
    
    // Validasi tanggal: tidak bisa edit tanggal yang sudah lewat
    if ($tanggal < date('Y-m-d')) {
        echo json_encode(['success' => false, 'message' => 'Tidak bisa mengubah jadwal tanggal yang sudah lewat']);
        exit;
    }
    
    // Validasi akses: admin hanya bisa kelola jadwal petugas di bagiannya
    $stmtCheck = $conn->prepare("SELECT bagian_id FROM petugas WHERE id = ?");
    $stmtCheck->bind_param("i", $petugasId);
    $stmtCheck->execute();
    $petugas = stmtFetchAssoc($stmtCheck);
    $stmtCheck->close();
    
    if (!$petugas) {
        echo json_encode(['success' => false, 'message' => 'Petugas tidak ditemukan']);
        exit;
    }
    
    $petugasBagianId = $petugas['bagian_id'];
    
    if ($bagianId !== null && $petugasBagianId != $bagianId) {
        echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
        exit;
    }
    
    // Validasi shift: harus sesuai bagian petugas
    if ($shiftId) {
        $stmtShift = $conn->prepare("SELECT bagian_id FROM shift WHERE id = ?");
        $stmtShift->bind_param("i", $shiftId);
        $stmtShift->execute();
        $shift = stmtFetchAssoc($stmtShift);
        $stmtShift->close();
        
        if ($shift && $shift['bagian_id'] !== null && $shift['bagian_id'] != $petugasBagianId) {
            echo json_encode(['success' => false, 'message' => 'Shift tidak sesuai dengan bagian petugas']);
            exit;
        }
    }
    
    // Validasi lokasi: harus sesuai bagian petugas dan aktif
    foreach ($lokasiIds as $lokId) {
        $lokId = (int)$lokId;
        $stmtLok = $conn->prepare("SELECT bagian_id, is_active FROM bagian_koordinat WHERE id = ?");
        $stmtLok->bind_param("i", $lokId);
        $stmtLok->execute();
        $lokasi = stmtFetchAssoc($stmtLok);
        $stmtLok->close();
        
        if ($lokasi && $lokasi['bagian_id'] != $petugasBagianId) {
            echo json_encode(['success' => false, 'message' => 'Lokasi tidak sesuai dengan bagian petugas']);
            exit;
        }
        if ($lokasi && isset($lokasi['is_active']) && (int)$lokasi['is_active'] === 0) {
            echo json_encode(['success' => false, 'message' => 'Lokasi yang dipilih sudah tidak aktif']);
            exit;
        }
    }
    
    $conn->begin_transaction();
    
    try {
        if ($jadwalId) {
            // Update existing jadwal (auto-lock = 1)
            $stmt = $conn->prepare("UPDATE jadwal_petugas 
                                   SET shift_id = ?, is_locked = 1, keterangan = ?, updated_at = NOW()
                                   WHERE id = ?");
            $stmt->bind_param("isi", $shiftId, $keterangan, $jadwalId);
            $stmt->execute();
            $stmt->close();
            
            // Hapus lokasi lama
            $stmtDel = $conn->prepare("DELETE FROM jadwal_lokasi WHERE jadwal_id = ?");
            $stmtDel->bind_param("i", $jadwalId);
            $stmtDel->execute();
            $stmtDel->close();
        } else {
            // Insert new jadwal (auto-lock = 1)
            $stmt = $conn->prepare("INSERT INTO jadwal_petugas 
                                   (petugas_id, tanggal, shift_id, is_locked, keterangan, created_by) 
                                   VALUES (?, ?, ?, 1, ?, ?)");
            $stmt->bind_param("isisi", $petugasId, $tanggal, $shiftId, $keterangan, $userId);
            $stmt->execute();
            $jadwalId = $conn->insert_id;
            $stmt->close();
        }
        
        // Insert lokasi baru
        $stmtLok = $conn->prepare("INSERT INTO jadwal_lokasi (jadwal_id, bagian_koordinat_id, urutan) VALUES (?, ?, ?)");
        $urutan = 1;
        foreach ($lokasiIds as $lokId) {
            $lokId = (int)$lokId;
            $stmtLok->bind_param("iii", $jadwalId, $lokId, $urutan);
            $stmtLok->execute();
            $urutan++;
        }
        $stmtLok->close();
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Jadwal berhasil disimpan',
            'jadwal_id' => $jadwalId
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    
    exit;
}

// SAVE GLOBAL: Generate jadwal 1 bulan penuh
if ($action === 'save_global' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $petugasId = (int)($_POST['petugas_id'] ?? 0);
    $bulanGlobal = (int)($_POST['bulan'] ?? 0);
    $tahunGlobal = (int)($_POST['tahun'] ?? 0);
    $shiftId = !empty($_POST['shift_id']) ? (int)$_POST['shift_id'] : null;
    $lokasiIds = $_POST['lokasi_ids'] ?? [];
    $replaceExisting = (int)($_POST['replace_existing'] ?? 0);

    if (!$petugasId || !$bulanGlobal || !$tahunGlobal || !$shiftId) {
        echo json_encode(['success'=>false,'message'=>'Data tidak lengkap']);
        exit;
    }
    if (empty($lokasiIds)) {
        echo json_encode(['success'=>false,'message'=>'Pilih minimal 1 lokasi']);
        exit;
    }
    if ($bulanGlobal < 1 || $bulanGlobal > 12 || $tahunGlobal < 2020) {
        echo json_encode(['success'=>false,'message'=>'Bulan/tahun tidak valid']);
        exit;
    }

    // Validasi akses bagian
    $stmtP = $conn->prepare("SELECT bagian_id FROM petugas WHERE id = ?");
    $stmtP->bind_param("i", $petugasId);
    $stmtP->execute();
    $pet = stmtFetchAssoc($stmtP);
    $stmtP->close();
    if (!$pet) { echo json_encode(['success'=>false,'message'=>'Petugas tidak ditemukan']); exit; }
    $petBagianId = $pet['bagian_id'];
    if ($bagianId !== null && $petBagianId != $bagianId) {
        echo json_encode(['success'=>false,'message'=>'Akses ditolak']); exit;
    }

    // Validasi shift
    if ($shiftId) {
        $stmtS = $conn->prepare("SELECT bagian_id FROM shift WHERE id = ?");
        $stmtS->bind_param("i", $shiftId);
        $stmtS->execute();
        $sh = stmtFetchAssoc($stmtS);
        $stmtS->close();
        if ($sh && $sh['bagian_id'] !== null && $sh['bagian_id'] != $petBagianId) {
            echo json_encode(['success'=>false,'message'=>'Shift tidak sesuai bagian']); exit;
        }
    }

    // Validasi lokasi
    foreach ($lokasiIds as $lId) {
        $lId = (int)$lId;
        $stmtL = $conn->prepare("SELECT bagian_id, is_active FROM bagian_koordinat WHERE id = ?");
        $stmtL->bind_param("i", $lId);
        $stmtL->execute();
        $lk = stmtFetchAssoc($stmtL);
        $stmtL->close();
        if ($lk && $lk['bagian_id'] != $petBagianId) {
            echo json_encode(['success'=>false,'message'=>'Lokasi tidak sesuai bagian']); exit;
        }
        if ($lk && isset($lk['is_active']) && (int)$lk['is_active'] === 0) {
            echo json_encode(['success'=>false,'message'=>'Lokasi tidak aktif']); exit;
        }
    }

    $jumlahHari = cal_days_in_month(CAL_GREGORIAN, $bulanGlobal, $tahunGlobal);
    $created = 0;
    $skipped = 0;
    $replaced = 0;

    $conn->begin_transaction();
    try {
        for ($d = 1; $d <= $jumlahHari; $d++) {
            $tgl = sprintf('%04d-%02d-%02d', $tahunGlobal, $bulanGlobal, $d);

            // Cek apakah sudah ada jadwal di tanggal ini
            $stmtCek = $conn->prepare("SELECT id FROM jadwal_petugas WHERE petugas_id=? AND tanggal=?");
            $stmtCek->bind_param("is", $petugasId, $tgl);
            $stmtCek->execute();
            $existing = stmtFetchAssoc($stmtCek);
            $stmtCek->close();

            if ($existing) {
                if (!$replaceExisting) {
                    $skipped++;
                    continue;
                }
                // Replace: hapus jadwal lama (CASCADE hapus lokasi)
                $stmtDel = $conn->prepare("DELETE FROM jadwal_petugas WHERE id=?");
                $stmtDel->bind_param("i", $existing['id']);
                $stmtDel->execute();
                $stmtDel->close();
                $replaced++;
            }

            // Insert jadwal baru
            $stmtIns = $conn->prepare("INSERT INTO jadwal_petugas (petugas_id,tanggal,shift_id,is_locked,keterangan,created_by) VALUES (?,?,?,0,'Jadwal Global',?)");
            $stmtIns->bind_param("isis", $petugasId, $tgl, $shiftId, $userId);
            $stmtIns->execute();
            $newJadwalId = $conn->insert_id;
            $stmtIns->close();

            // Insert lokasi
            $stmtLok = $conn->prepare("INSERT INTO jadwal_lokasi (jadwal_id,bagian_koordinat_id,urutan) VALUES (?,?,?)");
            $ur = 1;
            foreach ($lokasiIds as $lId) {
                $lId = (int)$lId;
                $stmtLok->bind_param("iii", $newJadwalId, $lId, $ur);
                $stmtLok->execute();
                $ur++;
            }
            $stmtLok->close();
            $created++;
        }

        $conn->commit();
        $msg = "Jadwal global berhasil dibuat: $created hari baru";
        if ($skipped > 0) $msg .= ", $skipped hari dilewati (sudah ada)";
        if ($replaced > 0) $msg .= ", $replaced hari diganti";
        echo json_encode(['success'=>true,'message'=>$msg,'created'=>$created,'skipped'=>$skipped,'replaced'=>$replaced]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
    }
    exit;
}

// DELETE: Hapus jadwal
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $jadwalId = (int)$_POST['jadwal_id'];
    
    if (!$jadwalId) {
        echo json_encode(['success' => false, 'message' => 'ID jadwal tidak valid']);
        exit;
    }
    
    // Validasi akses
    $stmt = $conn->prepare("SELECT jp.*, p.bagian_id 
                           FROM jadwal_petugas jp
                           JOIN petugas p ON jp.petugas_id = p.id
                           WHERE jp.id = ?");
    $stmt->bind_param("i", $jadwalId);
    $stmt->execute();
    $jadwal = stmtFetchAssoc($stmt);
    $stmt->close();
    
    if (!$jadwal) {
        echo json_encode(['success' => false, 'message' => 'Jadwal tidak ditemukan']);
        exit;
    }
    
    if ($bagianId !== null && $jadwal['bagian_id'] != $bagianId) {
        echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
        exit;
    }
    
    // Hapus jadwal (lokasi akan terhapus otomatis karena ON DELETE CASCADE)
    $stmtDel = $conn->prepare("DELETE FROM jadwal_petugas WHERE id = ?");
    $stmtDel->bind_param("i", $jadwalId);
    
    if ($stmtDel->execute()) {
        echo json_encode(['success' => true, 'message' => 'Jadwal berhasil dihapus']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal menghapus jadwal']);
    }
    
    $stmtDel->close();
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>
