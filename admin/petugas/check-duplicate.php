<?php
require_once '../../config/database.php';
require_once '../../config/session.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$field = $_GET['field'] ?? '';
$value = $_GET['value'] ?? '';
$excludeId = isset($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : 0;

$allowedFields = ['nip'];

if (!in_array($field, $allowedFields) || empty($value)) {
    echo json_encode(['exists' => false]);
    exit;
}

$value = $conn->real_escape_string($value);

if ($excludeId > 0) {
    $query = "SELECT id FROM petugas WHERE $field = '$value' AND id != $excludeId LIMIT 1";
} else {
    $query = "SELECT id FROM petugas WHERE $field = '$value' LIMIT 1";
}

$result = $conn->query($query);
$exists = ($result && $result->num_rows > 0);

echo json_encode(['exists' => $exists]);
