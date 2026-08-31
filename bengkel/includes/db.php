<?php
// ============================================================
// db.php - Koneksi MySQL & skema database aplikasi bengkel.
// Kredensial database diatur di includes/config.php (mudah diedit
// untuk cPanel shared hosting / XAMPP). Skema tabel dibuat otomatis
// saat aplikasi pertama dijalankan.
// ============================================================

// Zona waktu aplikasi (WIB) untuk seluruh fungsi date() PHP
date_default_timezone_set('Asia/Jakarta');

function db_config(): array {
    static $cfg = null;
    if ($cfg === null) $cfg = require __DIR__ . '/config.php';
    return $cfg;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = db_config();
        $charset = $c['charset'] ?? 'utf8mb4';

        // Coba buat database otomatis bila diizinkan (berguna di XAMPP).
        // Di shared hosting yang user-nya tidak punya izin CREATE DATABASE,
        // error diabaikan diam-diam (database dibuat manual via cPanel).
        if (!empty($c['auto_create_database'])) {
            try {
                $tmp = new PDO(
                    "mysql:host={$c['host']};port={$c['port']};charset=$charset",
                    $c['user'], $c['pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $tmp->exec("CREATE DATABASE IF NOT EXISTS `{$c['name']}` CHARACTER SET $charset COLLATE {$charset}_unicode_ci");
                $tmp = null;
            } catch (PDOException $e) {
                // abaikan: kemungkinan database sudah ada / tanpa izin CREATE
            }
        }

        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=$charset";
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // Simpan seluruh timestamp dalam UTC agar konsisten dengan helper
        // lokal() (konversi UTC -> WIB) di seluruh aplikasi. Dibungkus try/catch
        // agar tetap jalan di shared hosting yang membatasi SET time_zone.
        try { $pdo->exec("SET time_zone = '+00:00'"); } catch (PDOException $e) { /* abaikan */ }
    }
    return $pdo;
}

// Buat seluruh tabel (jika belum ada) + seed akun admin default
function init_db(): void {
    $db = db();
    $eng = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        nama VARCHAR(150) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'kasir',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $eng");

    $db->exec("CREATE TABLE IF NOT EXISTS customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama VARCHAR(150) NOT NULL,
        telepon VARCHAR(40) NOT NULL DEFAULT '',
        alamat VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $eng");

    $db->exec("CREATE TABLE IF NOT EXISTS vehicles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        merek VARCHAR(100) NOT NULL,
        model VARCHAR(100) NOT NULL DEFAULT '',
        plat_nomor VARCHAR(30) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (customer_id),
        CONSTRAINT fk_vehicles_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
    ) $eng");

    $db->exec("CREATE TABLE IF NOT EXISTS suppliers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama VARCHAR(150) NOT NULL,
        telepon VARCHAR(40) NOT NULL DEFAULT '',
        email VARCHAR(150) NOT NULL DEFAULT '',
        alamat VARCHAR(500) NOT NULL DEFAULT '',
        keterangan VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $eng");

    $db->exec("CREATE TABLE IF NOT EXISTS parts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kode VARCHAR(100) NOT NULL UNIQUE,
        barcode VARCHAR(100) NOT NULL DEFAULT '',
        nama VARCHAR(200) NOT NULL,
        kategori VARCHAR(150) NOT NULL DEFAULT '',
        harga_beli DECIMAL(14,2) NOT NULL DEFAULT 0,
        harga_jual DECIMAL(14,2) NOT NULL DEFAULT 0,
        stok INT NOT NULL DEFAULT 0,
        stok_min INT NOT NULL DEFAULT 5,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $eng");

    $db->exec("CREATE TABLE IF NOT EXISTS stock_movements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        part_id INT NOT NULL,
        tipe VARCHAR(10) NOT NULL,
        jumlah INT NOT NULL,
        supplier_id INT NULL,
        ref_type VARCHAR(30) NOT NULL DEFAULT '',
        ref_id INT NULL,
        keterangan VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (part_id), INDEX (supplier_id),
        CONSTRAINT fk_sm_part FOREIGN KEY (part_id) REFERENCES parts(id),
        CONSTRAINT fk_sm_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
    ) $eng");

    $db->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        no_nota VARCHAR(100) NOT NULL UNIQUE,
        customer_id INT NOT NULL,
        vehicle_id INT NULL,
        total_jasa DECIMAL(14,2) NOT NULL DEFAULT 0,
        total_part DECIMAL(14,2) NOT NULL DEFAULT 0,
        diskon DECIMAL(14,2) NOT NULL DEFAULT 0,
        grand_total DECIMAL(14,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'selesai',
        catatan VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (customer_id), INDEX (vehicle_id),
        CONSTRAINT fk_trx_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
        CONSTRAINT fk_trx_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
    ) $eng");

    $db->exec("CREATE TABLE IF NOT EXISTS transaction_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_id INT NOT NULL,
        tipe VARCHAR(10) NOT NULL,
        part_id INT NULL,
        nama VARCHAR(200) NOT NULL,
        qty INT NOT NULL DEFAULT 1,
        harga DECIMAL(14,2) NOT NULL DEFAULT 0,
        subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
        garansi_hari INT NOT NULL DEFAULT 0,
        INDEX (transaction_id), INDEX (part_id),
        CONSTRAINT fk_ti_trx FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
        CONSTRAINT fk_ti_part FOREIGN KEY (part_id) REFERENCES parts(id)
    ) $eng");

    $db->exec("CREATE TABLE IF NOT EXISTS warranty_claims (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kode VARCHAR(100) NOT NULL UNIQUE,
        transaction_id INT NOT NULL,
        transaction_item_id INT NOT NULL,
        customer_id INT NOT NULL,
        item_nama VARCHAR(200) NOT NULL,
        tgl_beli VARCHAR(20) NOT NULL,
        tgl_berakhir VARCHAR(20) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        alasan VARCHAR(500) NOT NULL DEFAULT '',
        catatan_teknisi VARCHAR(500) NOT NULL DEFAULT '',
        replacement_part_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (transaction_id), INDEX (transaction_item_id), INDEX (customer_id), INDEX (replacement_part_id),
        CONSTRAINT fk_wc_trx FOREIGN KEY (transaction_id) REFERENCES transactions(id),
        CONSTRAINT fk_wc_item FOREIGN KEY (transaction_item_id) REFERENCES transaction_items(id),
        CONSTRAINT fk_wc_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
        CONSTRAINT fk_wc_part FOREIGN KEY (replacement_part_id) REFERENCES parts(id)
    ) $eng");

    $db->exec("CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama VARCHAR(150) NOT NULL UNIQUE,
        keterangan VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $eng");

    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        `key` VARCHAR(100) PRIMARY KEY,
        `value` TEXT NULL
    ) $eng");

    $db->exec("CREATE TABLE IF NOT EXISTS notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        isi TEXT NOT NULL,
        warna VARCHAR(20) NOT NULL DEFAULT 'kuning',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $eng");

    // Katalog part hasil pencarian dari hargasukucadang.online (cache lokal)
    // agar pencarian tetap cepat & dapat dipakai walau situs sumber sedang down.
    $db->exec("CREATE TABLE IF NOT EXISTS part_catalog (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kode VARCHAR(100) NOT NULL UNIQUE,
        nama VARCHAR(255) NOT NULL DEFAULT '',
        harga VARCHAR(50) NOT NULL DEFAULT '',
        status VARCHAR(50) NOT NULL DEFAULT '',
        tipe VARCHAR(100) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (nama), INDEX (tipe)
    ) $eng");

    // Seed akun admin default (admin / admin123) jika tabel users kosong
    if ((int) db()->query("SELECT COUNT(*) FROM users")->fetchColumn() === 0) {
        $stmt = db()->prepare("INSERT INTO users (username, password_hash, nama, role) VALUES (?,?,?,?)");
        $stmt->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'Administrator', 'admin']);
    }

    // Seed kategori default + serap kategori yang sudah dipakai data sparepart lama
    if ((int) db()->query("SELECT COUNT(*) FROM categories")->fetchColumn() === 0) {
        $defaults = ['Oli', 'Kampas Rem', 'Busi', 'Aki', 'Ban', 'Rantai & Gir', 'Lampu', 'Lainnya'];
        $existing = db()->query("SELECT DISTINCT kategori FROM parts WHERE kategori != ''")->fetchAll(PDO::FETCH_COLUMN);
        $ins = db()->prepare("INSERT IGNORE INTO categories (nama) VALUES (?)");
        foreach (array_unique(array_merge($defaults, $existing)) as $k) $ins->execute([$k]);
    }

    // Seed pengaturan default (INSERT IGNORE -> tidak menimpa pengaturan user)
    $setting_defaults = [
        'nama_bengkel' => 'Bengkel Motor',
        'nib'          => '',
        'pemilik'      => '',
        'alamat'       => 'Jl. Contoh No. 1',
        'telepon'      => '0812-3456-7890',
        'logo'         => '',
        'theme_h1'     => '210',
        'theme_h2'     => '232',
    ];
    $ins = db()->prepare("INSERT IGNORE INTO settings (`key`, `value`) VALUES (?, ?)");
    foreach ($setting_defaults as $k => $v) $ins->execute([$k, $v]);

    // Migrasi DB lama: tambahkan kolom diskon pada tabel transactions bila belum ada
    $has = (int) db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'diskon'")->fetchColumn();
    if ($has === 0) {
        db()->exec("ALTER TABLE transactions ADD COLUMN diskon DECIMAL(14,2) NOT NULL DEFAULT 0");
    }
}

// ---------- Helper umum ----------
function esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function rupiah($n): string { return 'Rp ' . number_format((float)$n, 0, ',', '.'); }
function set_flash(string $type, string $msg): void { $_SESSION['flash'] = ['type' => $type, 'msg' => $msg]; }
function get_flash() { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }

// ---------- Pengaturan aplikasi ----------
function setting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query("SELECT `key`, `value` FROM settings") as $r) $cache[$r['key']] = $r['value'];
    }
    return $cache[$key] ?? $default;
}
function set_setting(string $key, string $value): void {
    db()->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
        ->execute([$key, $value]);
}

// Konversi datetime tersimpan (UTC) ke WIB untuk tampilan & cetakan
function lokal(?string $dt, string $format = 'd/m/Y H:i'): string {
    if (!$dt) return '-';
    try {
        $d = new DateTime($dt, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone('Asia/Jakarta'));
        return $d->format($format);
    } catch (Exception $e) {
        return $dt;
    }
}

// Generator kode berurut per bulan, misal: TRX-202606-001 / GRS-202606-001
// Memakai MAX nomor urut (bukan COUNT) agar aman terhadap penghapusan baris.
// Nomor urut di-parse setelah prefix "PREFIX-YYYYMM-" sehingga mendukung >999/bulan.
function next_kode(string $prefix, string $table, string $col): string {
    $ym = date('Ym');
    $start = strlen($prefix) + 9; // posisi 1-based digit pertama nomor urut
    $stmt = db()->prepare("SELECT MAX(CAST(SUBSTRING($col, $start) AS UNSIGNED)) FROM $table WHERE $col LIKE ?");
    $stmt->execute(["$prefix-$ym-%"]);
    $next = ((int)$stmt->fetchColumn()) + 1;
    return sprintf('%s-%s-%03d', $prefix, $ym, $next);
}

// ============================================================
// Helper laporan: hitung rentang tanggal dari parameter periode
// (harian / mingguan / bulanan / tahunan / custom dari-sampai)
// ============================================================
// Validasi format tanggal Y-m-d (fallback dipakai bila input tidak valid)
function _valid_date($d): bool {
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
}

function resolve_periode(): array {
    $periode = $_GET['periode'] ?? 'harian';
    $today = date('Y-m-d');
    switch ($periode) {
        case 'mingguan':
            $base = _valid_date($_GET['tanggal'] ?? '') ? $_GET['tanggal'] : $today;
            $dari = date('Y-m-d', strtotime('monday this week', strtotime($base)));
            $sampai = date('Y-m-d', strtotime('sunday this week', strtotime($base)));
            $label = 'Mingguan (' . date('d/m/Y', strtotime($dari)) . ' - ' . date('d/m/Y', strtotime($sampai)) . ')';
            break;
        case 'bulanan':
            $bulan = preg_match('/^\d{4}-\d{2}$/', $_GET['bulan'] ?? '') ? $_GET['bulan'] : date('Y-m');
            $dari = $bulan . '-01';
            $sampai = date('Y-m-t', strtotime($dari));
            $label = 'Bulanan (' . date('m/Y', strtotime($dari)) . ')';
            break;
        case 'tahunan':
            $tahun = preg_match('/^\d{4}$/', $_GET['tahun'] ?? '') ? $_GET['tahun'] : date('Y');
            $dari = "$tahun-01-01";
            $sampai = "$tahun-12-31";
            $label = "Tahunan ($tahun)";
            break;
        case 'custom':
            $dari = _valid_date($_GET['dari'] ?? '') ? $_GET['dari'] : $today;
            $sampai = _valid_date($_GET['sampai'] ?? '') ? $_GET['sampai'] : $today;
            // Tukar otomatis bila pengguna memasukkan rentang terbalik
            if ($dari > $sampai) [$dari, $sampai] = [$sampai, $dari];
            $label = date('d/m/Y', strtotime($dari)) . ' s.d. ' . date('d/m/Y', strtotime($sampai));
            break;
        default: // harian
            $periode = 'harian';
            $dari = $sampai = _valid_date($_GET['tanggal'] ?? '') ? $_GET['tanggal'] : $today;
            $label = 'Harian (' . date('d/m/Y', strtotime($dari)) . ')';
    }
    return [$periode, $dari, $sampai, $label];
}

// Ambil daftar transaksi dalam rentang tanggal untuk laporan
function laporan_transaksi(string $dari, string $sampai): array {
    $stmt = db()->prepare("SELECT t.*, c.nama AS customer_nama, v.plat_nomor
        FROM transactions t
        JOIN customers c ON c.id = t.customer_id
        LEFT JOIN vehicles v ON v.id = t.vehicle_id
        WHERE DATE(t.created_at + INTERVAL 7 HOUR) BETWEEN ? AND ?
        ORDER BY t.created_at");
    $stmt->execute([$dari, $sampai]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
