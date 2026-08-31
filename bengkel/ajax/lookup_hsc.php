<?php
// ============================================================
// lookup_hsc.php - Proxy + cache pencarian sparepart hargasukucadang.online
// Mengembalikan JSON: { results: [ {kode,nama,harga,status,tipe} ], source, offline }
//  - source = 'live'  : hasil segar dari situs sumber (sekaligus disimpan ke katalog lokal)
//  - source = 'local' : situs sumber tak terjangkau -> pakai katalog lokal (offline=true)
// ============================================================
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
init_db();
header('Content-Type: application/json; charset=utf-8');

$field = $_GET['field'] ?? 'auto';           // auto | nama | kode | tipe
$q     = trim($_GET['q'] ?? '');

if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode(['results' => [], 'source' => 'none', 'error' => 'Kata kunci minimal 2 karakter.']);
    exit;
}

// Auto-detect: kalau kata kunci mirip kode part (mengandung digit + uppercase,
// tanpa spasi & lebih dari 3 karakter), utamakan pencarian by kode. Selain itu
// pakai pencarian by nama. User tidak perlu memilih field lagi.
if ($field === 'auto') {
    $noSpace = preg_replace('/\s+/', '', $q);
    $looksLikeCode = ($noSpace === $q)
        && strlen($q) >= 3
        && preg_match('/[0-9]/', $q)
        && preg_match('/[A-Z0-9\-]/i', $q)
        && preg_match('/^[A-Z0-9\-]+$/i', $q);
    $field = $looksLikeCode ? 'kode' : 'nama';
}

$db = db();

// 1) Hasil dari katalog lokal (instan, tahan-offline)
$local = catalog_search($db, $field, $q);

// 2) Coba ambil data terbaru dari situs sumber
$payload = ['kodepart' => '--Kode Part--', 'namapart' => '--Nama Part--', 'tipe' => '--Motor--', 'submit' => ''];
if ($field === 'kode')      $payload['kodepart'] = $q;
elseif ($field === 'tipe')  $payload['tipe']     = $q;
else                        $payload['namapart'] = $q;

$html = hsc_fetch('https://hargasukucadang.online/index.php', $payload);

if ($html !== null) {
    $live = hsc_parse($html);
    if ($live) { try { catalog_upsert($db, $live); } catch (Exception $e) { /* abaikan gagal cache */ } }
    // Gabungkan: utamakan hasil live, tambahkan item katalog lokal yang belum ada
    $seen = [];
    foreach ($live as $r) $seen[$r['kode']] = true;
    $merged = $live;
    foreach ($local as $r) {
        if (empty($seen[$r['kode']])) { $merged[] = $r; }
        if (count($merged) >= 40) break;
    }
    echo json_encode(['results' => array_slice($merged, 0, 40), 'source' => 'live']);
    exit;
}

// 3) Situs sumber tak terjangkau -> pakai katalog lokal
echo json_encode([
    'results' => $local,
    'source'  => 'local',
    'offline' => true,
    'error'   => $local ? null : 'Situs hargasukucadang.online sedang tidak dapat dihubungi, dan belum ada data di katalog lokal untuk kata kunci ini.',
]);

// ------------------------------------------------------------
// Cari di katalog lokal berdasarkan kolom (whitelist).
function catalog_search(PDO $db, string $field, string $q): array {
    $col = $field === 'kode' ? 'kode' : ($field === 'tipe' ? 'tipe' : 'nama');
    $stmt = $db->prepare("SELECT kode, nama, harga, status, tipe FROM part_catalog WHERE $col LIKE ? ORDER BY kode LIMIT 40");
    $stmt->execute(["%$q%"]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Simpan/segarkan hasil ke katalog lokal (upsert berdasarkan kode).
function catalog_upsert(PDO $db, array $rows): void {
    $ins = $db->prepare("INSERT INTO part_catalog (kode, nama, harga, status, tipe)
        VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE nama=VALUES(nama), harga=VALUES(harga), status=VALUES(status), tipe=VALUES(tipe), updated_at=CURRENT_TIMESTAMP");
    foreach ($rows as $r) {
        if (($r['kode'] ?? '') === '') continue;
        $ins->execute([$r['kode'], $r['nama'] ?? '', $r['harga'] ?? '', $r['status'] ?? '', $r['tipe'] ?? '']);
    }
}

// ------------------------------------------------------------
// Ambil HTML hasil pencarian (POST). Utamakan cURL, fallback file_get_contents.
function hsc_fetch(string $url, array $post): ?string {
    $body = http_build_query($post);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SimbengBengkel/1.0)',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res !== false && $code >= 200 && $code < 400) return $res;
        return null;
    }

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: SimbengBengkel/1.0\r\n",
            'content' => $body,
            'timeout' => 20,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $res = @file_get_contents($url, false, $ctx);
    return $res === false ? null : $res;
}

// ------------------------------------------------------------
// Parse tabel hasil (id="customers") menjadi array asosiatif.
function hsc_parse(string $html): array {
    $out  = [];
    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $xp   = new DOMXPath($doc);
    $rows = $xp->query("//table[@id='customers']/tbody/tr");
    if ($rows === false) return $out;

    foreach ($rows as $tr) {
        $tds = $xp->query('./td', $tr);
        if ($tds->length < 5) continue;
        $get = function ($i) use ($tds) {
            return trim(preg_replace('/\s+/', ' ', $tds->item($i)->textContent));
        };
        $kode = $get(0);
        $nama = $get(1);
        if ($kode === '' && $nama === '') continue;
        $out[] = [
            'kode'   => $kode,
            'nama'   => $nama,
            'harga'  => $get(2),
            'status' => $get(3),
            'tipe'   => $get(4),
        ];
        if (count($out) >= 40) break;
    }
    return $out;
}
