<?php

require_once '../../config/database.php';

require_once '../../config/session.php';

require_once '../../includes/functions.php';



if (
    !isset($_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin','superadmin'], true)
) {
    header("Location: ../../auth/login-v2.php");
    exit;
}

$role = $_SESSION['role'];
$bagianId = $_SESSION['bagian_id'] ?? null;



if (!isset($_GET['id'])) {

    echo "<script>alert('ID shift tidak ditemukan!'); window.location.href='index.php';</script>";

    exit;

}



$id = (int)$_GET['id'];



$namaShift = null;

if ($role === 'superadmin') {
    // Superadmin: bisa hapus shift mana pun
    $get = $conn->prepare(
        "SELECT nama_shift, bagian_id FROM shift WHERE id = ?"
    );
    $get->bind_param("i", $id);
} elseif ($bagianId === null) {
    // Admin global: bisa hapus shift global
    $get = $conn->prepare(
        "SELECT nama_shift, bagian_id FROM shift WHERE id = ?"
    );
    $get->bind_param("i", $id);
} else {
    // Admin bagian: hanya bisa hapus shift bagiannya
    $bagian_id = (int) $bagianId;
    $get = $conn->prepare(
        "SELECT nama_shift, bagian_id FROM shift WHERE id = ? AND bagian_id = ?"
    );
    $get->bind_param("ii", $id, $bagian_id);
}

$get->execute();

$rowShift = stmtFetchAssoc($get);
$get->close();

if ($rowShift && isset($rowShift['nama_shift'])) {

    $namaShift = $rowShift['nama_shift'];

}



$shiftIdColumnExists = false;

$checkShiftIdColumn = $conn->query("SHOW COLUMNS FROM petugas LIKE 'shift_id'");

if ($checkShiftIdColumn && $checkShiftIdColumn->num_rows > 0) {

    $shiftIdColumnExists = true;

}



$petugasShiftColumnExists = false;

$checkPetugasShift = $conn->query("SHOW COLUMNS FROM petugas LIKE 'shift'");

if ($checkPetugasShift && $checkPetugasShift->num_rows > 0) {

    $petugasShiftColumnExists = true;

}



if ($shiftIdColumnExists) {

    $cek = $conn->prepare("SELECT id FROM petugas WHERE shift_id = ? LIMIT 1");

    $cek->bind_param("i", $id);

    $cek->execute();

    $used = stmtFetchAssoc($cek);

    if ($used) {

        echo "<script>alert('Shift tidak bisa dihapus karena masih dipakai petugas.'); window.location.href='index.php';</script>";

        exit;

    }

    $cek->close();

} else if ($petugasShiftColumnExists && $namaShift !== null) {

    $cek = $conn->prepare("SELECT id FROM petugas WHERE shift = ? LIMIT 1");

    $cek->bind_param("s", $namaShift);

    $cek->execute();

    $used = stmtFetchAssoc($cek);

    if ($used) {

        echo "<script>alert('Shift tidak bisa dihapus karena masih dipakai petugas.'); window.location.href='index.php';</script>";

        exit;

    }

    $cek->close();

}



$stmt = $conn->prepare("DELETE FROM shift WHERE id = ?");

$stmt->bind_param("i", $id);



if ($stmt->execute()) {

    echo "<script>alert('Shift berhasil dihapus!'); window.location.href='index.php';</script>";

    exit;

}



echo "<script>alert('Gagal menghapus shift: " . addslashes($stmt->error) . "'); window.location.href='index.php';</script>";

exit;

?>

