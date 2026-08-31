# Simbeng v1 - Sistem Manajemen Bengkel Motor

## Sumber
Repo: https://github.com/khaulahcomp/simbeng_v1 (PHP native + MySQL/MariaDB)
Aplikasi berada di `/app/bengkel/` (di-serve oleh PHP built-in server pada port 3000).

## Environment
- PHP 8.2 + MariaDB 10.11 (via supervisor: `mariadb`, `php_bengkel`)
- Login default: admin / admin123
- Kredensial DB: dari env var atau default `root@localhost/bengkel`

## Fitur yang diminta user (Feb 2026)
[DONE] Pencarian sparepart berdasarkan kode part pada menu Stok Masuk/Keluar dengan autocomplete, sehingga memudahkan input jumlah stok masuk/keluar dari supplier.

### Implementasi
1. Endpoint baru `ajax/lookup.php?action=search_parts&q=<query>` — mencari `parts` berdasarkan `kode` / `barcode` / `nama` dengan prioritas match pada kode.
2. `pages/stock.php` — dropdown `<select>` untuk sparepart pada form Barang Masuk & Barang Keluar diganti dengan **autocomplete search input** (kode/barcode/nama).
   - Debounced fetch (180ms) ke endpoint di atas.
   - Keyboard navigation (ArrowUp/Down/Enter/Escape) + klik.
   - Setelah part dipilih: card ringkas (kode, nama, kategori, stok) muncul, hidden `part_id` terisi, fokus otomatis pindah ke input Jumlah.
   - Untuk Barang Keluar: input jumlah otomatis dibatasi `max=stok`.
   - Validasi submit: form tidak boleh submit tanpa memilih part.
3. Form utama tetap: Jumlah + Supplier + Keterangan seperti semula.

## Backlog
- Riwayat stok movement bisa difilter/cari berdasarkan kode part di panel kanan.
- Bulk barang masuk (multi-part sekaligus dengan no. faktur yang sama).
- Scan barcode via kamera langsung isi kode di search.
