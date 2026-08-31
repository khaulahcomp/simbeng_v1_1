<?php
// ============================================================
// import_parts.php - Import massal sparepart dari Excel/CSV
// Menerima JSON: { rows: [ {kode, nama, kategori, harga_beli,
// harga_jual, stok, stok_min, barcode}, ... ] }
// Upsert berdasarkan kolom "kode".
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
init_db();
header('Content-Type: application/json');

$payload = json_decode(file_get_contents('php://input'), true);
$rows = $payload['rows'] ?? [];
// mode: 'baru' (insert-only, lewati kode yang sudah ada) atau
//       'upgrade' (upsert: update kode yang sudah ada, tambah yang belum ada)
$mode = ($payload['mode'] ?? 'upgrade') === 'baru' ? 'baru' : 'upgrade';
if (!$rows) { echo json_encode(['ok' => false, 'message' => 'File kosong atau format tidak dikenali.']); exit; }

$db = db();
$find = $db->prepare("SELECT id FROM parts WHERE kode = ?");
$ins  = $db->prepare("INSERT INTO parts (kode, nama, kategori, harga_beli, harga_jual, stok, stok_min, barcode) VALUES (?,?,?,?,?,?,?,?)");
$upd  = $db->prepare("UPDATE parts SET nama=?, kategori=?, harga_beli=?, harga_jual=?, stok=?, stok_min=?, barcode=? WHERE kode=?");

$inserted = 0; $updated = 0; $skipped = 0; $skippedExists = 0;
$db->beginTransaction();
foreach ($rows as $r) {
    // Normalisasi nama kolom (dukung variasi huruf besar/kecil & spasi)
    $row = [];
    foreach ($r as $k => $v) $row[strtolower(trim((string)$k))] = $v;
    $kode = strtoupper(trim((string)($row['kode'] ?? '')));
    $nama = trim((string)($row['nama'] ?? ''));
    if ($kode === '' || $nama === '') { $skipped++; continue; }
    $kategori = trim((string)($row['kategori'] ?? ''));
    // Kategori baru dari file import otomatis terdaftar di master kategori
    if ($kategori !== '') {
        $db->prepare("INSERT IGNORE INTO categories (nama) VALUES (?)")->execute([$kategori]);
    }
    $vals = [
        $nama,
        $kategori,
        (float)($row['harga_beli'] ?? 0),
        (float)($row['harga_jual'] ?? 0),
        (int)($row['stok'] ?? 0),
        (int)($row['stok_min'] ?? 5),
        trim((string)($row['barcode'] ?? '')),
    ];
    $find->execute([$kode]);
    $exists = (bool)$find->fetchColumn();
    if ($exists) {
        if ($mode === 'baru') {
            $skippedExists++;
            continue;
        }
        $upd->execute([...$vals, $kode]);
        $updated++;
    } else {
        $ins->execute([$kode, ...$vals]);
        $inserted++;
    }
}
$db->commit();
$msg = $mode === 'baru'
    ? "Import (Format Baru) selesai: $inserted ditambah, $skippedExists dilewati (kode sudah ada), $skipped baris tidak valid."
    : "Import (Upgrade) selesai: $inserted ditambah, $updated diperbarui, $skipped baris tidak valid.";
echo json_encode(['ok' => true, 'message' => $msg]);
