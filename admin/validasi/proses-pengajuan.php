<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../auth/login-v2.php");
    exit;
}



if (isset($_GET['id']) && isset($_GET['aksi'])) {
    $id = (int)$_GET['id'];
    $aksi = $_GET['aksi'];
    
    // Ambil data pengajuan
    $stmt = $conn->prepare("SELECT * FROM pengajuan WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $data = stmtFetchAssoc($stmt);
    $stmt->close();
    
    if (!$data) {
        die("Data tidak ditemukan");
    }
    
    $conn->begin_transaction();
    
    try {

        if ($aksi == 'approve') {
            // Update status pengajuan
            $stmtUpd = $conn->prepare("UPDATE pengajuan SET status = 'disetujui' WHERE id = ?");
            $stmtUpd->bind_param("i", $id);
            $stmtUpd->execute();
            $stmtUpd->close();
            
            $petugasId = (int)$data['petugas_id'];
            $tanggal = $data['tanggal'];
            $jenis = $data['jenis'];
            $jenisLupa = $data['jenis_lupa_absen'] ?? null;
            $keterangan = $data['keterangan'] ?? '';

            if ($jenis === 'lupa absen masuk') {
                $jenis = 'lupa absen';
                $jenisLupa = 'masuk';
            } elseif ($jenis === 'lupa absen keluar') {
                $jenis = 'lupa absen';
                $jenisLupa = 'keluar';
            }

            $pengajuanShiftId = isset($data['shift_id']) ? (int)$data['shift_id'] : 0;
            $pengajuanShiftNama = isset($data['shift']) ? trim((string)$data['shift']) : '';

            $absensiShiftIdColumnExists = $conn->query("SHOW COLUMNS FROM absensi LIKE 'shift_id'");
            $absensiHasShiftId = ($absensiShiftIdColumnExists && $absensiShiftIdColumnExists->num_rows > 0);

            $absensiShiftColumnExists = $conn->query("SHOW COLUMNS FROM absensi LIKE 'shift'");
            $absensiHasShiftNama = ($absensiShiftColumnExists && $absensiShiftColumnExists->num_rows > 0);

            $absensiJadwalIdColumnExists = $conn->query("SHOW COLUMNS FROM absensi LIKE 'jadwal_id'");
            $absensiHasJadwalId = ($absensiJadwalIdColumnExists && $absensiJadwalIdColumnExists->num_rows > 0);

            $absensiKeteranganColumnExists = $conn->query("SHOW COLUMNS FROM absensi LIKE 'keterangan'");
            $absensiHasKeterangan = ($absensiKeteranganColumnExists && $absensiKeteranganColumnExists->num_rows > 0);

            // Resolve shift_id if only shift name stored on pengajuan
            if ($pengajuanShiftId <= 0 && $pengajuanShiftNama !== '') {
                $stmtShift = $conn->prepare("SELECT id FROM shift WHERE nama_shift = ? LIMIT 1");
                $stmtShift->bind_param('s', $pengajuanShiftNama);
                $stmtShift->execute();
                $rowShift = stmtFetchAssoc($stmtShift);
                $stmtShift->close();
                if ($rowShift && isset($rowShift['id'])) {
                    $pengajuanShiftId = (int)$rowShift['id'];
                }
            }

            // Ambil jam shift untuk set jam masuk/keluar otomatis
            $shiftMulaiMasuk = null;
            $shiftAkhirKeluar = null;
            if ($pengajuanShiftId > 0) {
                $stmtShiftJam = $conn->prepare("SELECT mulai_masuk, akhir_keluar FROM shift WHERE id = ? LIMIT 1");
                $stmtShiftJam->bind_param('i', $pengajuanShiftId);
                $stmtShiftJam->execute();
                $shiftJam = stmtFetchAssoc($stmtShiftJam);
                $stmtShiftJam->close();
                if ($shiftJam) {
                    $shiftMulaiMasuk = $shiftJam['mulai_masuk'] ?? null;
                    $shiftAkhirKeluar = $shiftJam['akhir_keluar'] ?? null;
                }
            }

            $nowApproval = date('Y-m-d H:i:s');
            $jamMasukAuto = $nowApproval;
            $jamKeluarAuto = $nowApproval;
            
            // Cari jadwal_id untuk tanggal ini
            $stmtJadwal = $conn->prepare("SELECT id, shift_id FROM jadwal_petugas WHERE petugas_id = ? AND tanggal = ? LIMIT 1");
            $stmtJadwal->bind_param("is", $petugasId, $tanggal);
            $stmtJadwal->execute();
            $jadwalData = stmtFetchAssoc($stmtJadwal);
            $stmtJadwal->close();
            
            $jadwalId = $jadwalData ? (int)$jadwalData['id'] : null;

            // Jika jadwal shift beda, utamakan shift pengajuan untuk update absensi
            if ($pengajuanShiftId <= 0 && $jadwalData && isset($jadwalData['shift_id'])) {
                $pengajuanShiftId = (int)$jadwalData['shift_id'];
            }


            // Cek apakah sudah ada record absensi di hari itu
            $sqlCek = "SELECT id, jam_masuk, jam_keluar, status FROM absensi WHERE petugas_id = ? AND tanggal = ?";
            $typesCek = "is";
            $paramsCek = [$petugasId, $tanggal];

            if ($absensiHasShiftId && $pengajuanShiftId > 0) {
                $sqlCek .= " AND shift_id = ?";
                $typesCek .= "i";
                $paramsCek[] = $pengajuanShiftId;
            } elseif ($absensiHasShiftNama && $pengajuanShiftNama !== '') {
                $sqlCek .= " AND shift = ?";
                $typesCek .= "s";
                $paramsCek[] = $pengajuanShiftNama;
            }

            $sqlCek .= " ORDER BY id DESC LIMIT 1";

            $stmtCek = $conn->prepare($sqlCek);
            $stmtCek->bind_param($typesCek, ...$paramsCek);
            $stmtCek->execute();
            $existingAbsen = stmtFetchAssoc($stmtCek);
            $stmtCek->close();

            // Helper: create empty absensi if not exists (for izin/sakit/lupa absen)
            $ensureAbsensiId = function() use ($conn, $existingAbsen, $petugasId, $tanggal, $jadwalId, $pengajuanShiftId, $pengajuanShiftNama, $absensiHasShiftId, $absensiHasShiftNama, $absensiHasJadwalId) {
                if ($existingAbsen && isset($existingAbsen['id'])) {
                    return (int)$existingAbsen['id'];
                }

                $cols = ['petugas_id', 'tanggal'];
                $placeholders = ['?', '?'];
                $types = 'is';
                $vals = [$petugasId, $tanggal];

                if ($absensiHasJadwalId) {
                    $cols[] = 'jadwal_id';
                    $placeholders[] = '?';
                    $types .= 'i';
                    $vals[] = $jadwalId;
                }

                if ($absensiHasShiftId && $pengajuanShiftId > 0) {
                    $cols[] = 'shift_id';
                    $placeholders[] = '?';
                    $types .= 'i';
                    $vals[] = $pengajuanShiftId;
                } elseif ($absensiHasShiftNama && $pengajuanShiftNama !== '') {
                    $cols[] = 'shift';
                    $placeholders[] = '?';
                    $types .= 's';
                    $vals[] = $pengajuanShiftNama;
                }

                $cols[] = 'status';
                $placeholders[] = "'--'";

                $sqlIns = "INSERT INTO absensi (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
                $stmtIns = $conn->prepare($sqlIns);
                if (!$stmtIns) {
                    throw new Exception('Gagal prepare insert absensi: ' . $conn->error);
                }
                $stmtIns->bind_param($types, ...$vals);
                if (!$stmtIns->execute()) {
                    $err = $stmtIns->error;
                    $stmtIns->close();
                    throw new Exception('Gagal insert absensi: ' . $err);
                }
                $stmtIns->close();
                return (int)$conn->insert_id;
            };
            
            // Logic berdasarkan jenis pengajuan
            if ($jenis === 'izin' || $jenis === 'sakit') {
                $absensiId = $ensureAbsensiId();
                if ($absensiHasKeterangan) {
                    $stmtUpd = $conn->prepare("UPDATE absensi SET status = ?, keterangan = ? WHERE id = ?");
                    $stmtUpd->bind_param("ssi", $jenis, $keterangan, $absensiId);
                } else {
                    $stmtUpd = $conn->prepare("UPDATE absensi SET status = ? WHERE id = ?");
                    $stmtUpd->bind_param("si", $jenis, $absensiId);
                }
                $stmtUpd->execute();
                $stmtUpd->close();

            } elseif ($jenis === 'lupa absen') {
                if ($jenisLupa !== 'masuk' && $jenisLupa !== 'keluar') {
                    throw new Exception('Jenis lupa absen tidak valid');
                }

                if ($pengajuanShiftId <= 0) {
                    throw new Exception('Shift pengajuan tidak valid');
                }

                $absensiId = $ensureAbsensiId();

                if ($jenisLupa === 'masuk') {
                    // Cek apakah jam_keluar sudah ada ? jika ya, status 'hadir'
                    $stmtGet = $conn->prepare("SELECT jam_keluar FROM absensi WHERE id = ? LIMIT 1");
                    $stmtGet->bind_param('i', $absensiId);
                    $stmtGet->execute();
                    $rowJK = stmtFetchAssoc($stmtGet);
                    $stmtGet->close();

                    $hasKeluar = $rowJK && !empty($rowJK['jam_keluar']);
                    $newStatus = $hasKeluar ? 'hadir' : 'lupa absen';

                    if ($absensiHasKeterangan) {
                        $stmtUpd = $conn->prepare("UPDATE absensi SET jam_masuk = ?, status = ?, keterangan = ? WHERE id = ?");
                        $stmtUpd->bind_param("sssi", $jamMasukAuto, $newStatus, $keterangan, $absensiId);
                    } else {
                        $stmtUpd = $conn->prepare("UPDATE absensi SET jam_masuk = ?, status = ? WHERE id = ?");
                        $stmtUpd->bind_param("ssi", $jamMasukAuto, $newStatus, $absensiId);
                    }
                    $stmtUpd->execute();
                    $stmtUpd->close();
                } else {
                    // Cek apakah jam_masuk sudah ada ? jika ya, status 'hadir'
                    $stmtGet = $conn->prepare("SELECT jam_masuk FROM absensi WHERE id = ? LIMIT 1");
                    $stmtGet->bind_param('i', $absensiId);
                    $stmtGet->execute();
                    $rowJM = stmtFetchAssoc($stmtGet);
                    $stmtGet->close();

                    $hasMasuk = $rowJM && !empty($rowJM['jam_masuk']);
                    $newStatus = $hasMasuk ? 'hadir' : 'lupa absen';

                    if ($absensiHasKeterangan) {
                        $stmtUpd = $conn->prepare("UPDATE absensi SET jam_keluar = ?, status = ?, keterangan = ? WHERE id = ?");
                        $stmtUpd->bind_param("sssi", $jamKeluarAuto, $newStatus, $keterangan, $absensiId);
                    } else {
                        $stmtUpd = $conn->prepare("UPDATE absensi SET jam_keluar = ?, status = ? WHERE id = ?");
                        $stmtUpd->bind_param("ssi", $jamKeluarAuto, $newStatus, $absensiId);
                    }
                    $stmtUpd->execute();
                    $stmtUpd->close();
                }
            }



        } else {
            // Reject: update status pengajuan saja
            $stmtReject = $conn->prepare("UPDATE pengajuan SET status = 'ditolak' WHERE id = ?");
            $stmtReject->bind_param("i", $id);
            $stmtReject->execute();
            $stmtReject->close();
        }
        
        $conn->commit();
        echo "<script>alert('Proses Berhasil!'); window.location.href='pengajuan.php';</script>";
        
    } catch (Exception $e) {
        $conn->rollback();
        echo "Error: " . $e->getMessage();
    }
}
?>