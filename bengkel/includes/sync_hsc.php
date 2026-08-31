<?php
// ============================================================
// sync_hsc.php - Sinkronisasi katalog hargasukucadang.online (CLI-safe).
// Dipakai baik oleh cron/supervisor harian, atau dipanggil manual via
// ajax/sync_hsc.php dari tombol "Sync Sekarang" di menu Sparepart.
//
// Strategi refresh (agar mode offline tetap segar):
//  1) Untuk daftar kata kunci umum (oli, busi, kampas, dll.) -> fetch by nama
//  2) Untuk seluruh kode yang sudah ada di part_catalog -> fetch by kode
//     (dibatasi supaya sinkronisasi tidak berlangsung terlalu lama)
// Setelah selesai, catat waktu ke settings.last_hsc_sync (ISO UTC).
// ============================================================
require_once __DIR__ . '/../includes/db.php';

// Batas maksimum kode yang diperbarui per menjalankan supaya cepat & sopan.
if (!defined('HSC_SYNC_MAX_KODE')) define('HSC_SYNC_MAX_KODE', 25);

function hsc_sync_run(?callable $log = null): array {
    init_db();
    $db = db();
    $started = time();
    $inserted = 0; $updated = 0; $totalRows = 0; $errors = [];

    $keywords = ['oli', 'busi', 'kampas', 'rantai', 'ban', 'shock', 'lampu', 'aki', 'filter', 'gear', 'piston', 'karburator'];

    if ($log) $log('Sync HSC dimulai pada ' . date('Y-m-d H:i:s'));

    // 1) refresh by nama untuk kata kunci umum
    foreach ($keywords as $kw) {
        $rows = hsc_sync_fetch('nama', $kw);
        if ($rows === null) { $errors[] = "Gagal fetch nama=$kw"; continue; }
        $r = hsc_sync_upsert($db, $rows);
        $inserted += $r['inserted']; $updated += $r['updated']; $totalRows += count($rows);
        if ($log) $log(" - nama=$kw: " . count($rows) . " hasil");
        usleep(300000); // 300ms sopan-santun antar request
    }

    // 2) refresh by kode untuk kode yang paling lama diperbarui
    $kodes = $db->query("SELECT kode FROM part_catalog ORDER BY updated_at ASC LIMIT " . (int)HSC_SYNC_MAX_KODE)
                ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($kodes as $kd) {
        if (mb_strlen($kd) < 2) continue;
        $rows = hsc_sync_fetch('kode', $kd);
        if ($rows === null) { $errors[] = "Gagal fetch kode=$kd"; continue; }
        $r = hsc_sync_upsert($db, $rows);
        $inserted += $r['inserted']; $updated += $r['updated']; $totalRows += count($rows);
        if ($log) $log(" - kode=$kd: " . count($rows) . " hasil");
        usleep(300000);
    }

    // Simpan waktu sinkronisasi terakhir (UTC ISO).
    set_setting('last_hsc_sync', gmdate('Y-m-d\TH:i:s\Z'));
    set_setting('last_hsc_sync_summary', json_encode([
        'inserted' => $inserted, 'updated' => $updated, 'rows' => $totalRows,
        'errors' => count($errors), 'duration_sec' => time() - $started,
    ]));

    $result = ['inserted' => $inserted, 'updated' => $updated, 'rows' => $totalRows, 'errors' => $errors, 'duration_sec' => time() - $started];
    if ($log) $log("Selesai. rows=$totalRows inserted=$inserted updated=$updated errors=" . count($errors));
    return $result;
}

function hsc_sync_fetch(string $field, string $q): ?array {
    $payload = ['kodepart' => '--Kode Part--', 'namapart' => '--Nama Part--', 'tipe' => '--Motor--', 'submit' => ''];
    if ($field === 'kode')      $payload['kodepart'] = $q;
    elseif ($field === 'tipe')  $payload['tipe']     = $q;
    else                        $payload['namapart'] = $q;

    $body = http_build_query($payload);
    if (!function_exists('curl_init')) return null;
    $ch = curl_init('https://hargasukucadang.online/index.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SimbengBengkel/1.0)',
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($html === false || $code < 200 || $code >= 400) return null;
    return hsc_sync_parse($html);
}

function hsc_sync_parse(string $html): array {
    $out  = [];
    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $xp = new DOMXPath($doc);
    $rows = $xp->query("//table[@id='customers']/tbody/tr");
    if ($rows === false) return $out;
    foreach ($rows as $tr) {
        $tds = $xp->query('./td', $tr);
        if ($tds->length < 5) continue;
        $get = function ($i) use ($tds) { return trim(preg_replace('/\s+/', ' ', $tds->item($i)->textContent)); };
        $kode = $get(0);
        if ($kode === '') continue;
        $out[] = ['kode' => $kode, 'nama' => $get(1), 'harga' => $get(2), 'status' => $get(3), 'tipe' => $get(4)];
        if (count($out) >= 40) break;
    }
    return $out;
}

function hsc_sync_upsert(PDO $db, array $rows): array {
    $ins = 0; $upd = 0;
    $find = $db->prepare("SELECT id FROM part_catalog WHERE kode = ?");
    $insert = $db->prepare("INSERT INTO part_catalog (kode, nama, harga, status, tipe) VALUES (?,?,?,?,?)");
    $update = $db->prepare("UPDATE part_catalog SET nama=?, harga=?, status=?, tipe=?, updated_at=CURRENT_TIMESTAMP WHERE kode=?");
    foreach ($rows as $r) {
        if (($r['kode'] ?? '') === '') continue;
        $find->execute([$r['kode']]);
        if ($find->fetchColumn()) { $update->execute([$r['nama'], $r['harga'], $r['status'], $r['tipe'], $r['kode']]); $upd++; }
        else                       { $insert->execute([$r['kode'], $r['nama'], $r['harga'], $r['status'], $r['tipe']]); $ins++; }
    }
    return ['inserted' => $ins, 'updated' => $upd];
}

// Cek apakah waktu sinkronisasi terakhir lebih tua dari $hours jam.
function hsc_sync_stale(int $hours = 24): bool {
    $last = setting('last_hsc_sync', '');
    if ($last === '') return true;
    $ts = strtotime($last);
    if ($ts === false) return true;
    return (time() - $ts) >= ($hours * 3600);
}
