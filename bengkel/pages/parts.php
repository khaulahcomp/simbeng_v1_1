<?php
$db = db();
$action = $_POST['action'] ?? '';

// ---- Simpan (tambah / edit) sparepart ----
if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $kode = strtoupper(trim($_POST['kode'] ?? ''));
    $kategori = trim($_POST['kategori'] ?? '');
    // Kategori baru (mis. hasil auto-fill dari hargasukucadang.online) otomatis
    // didaftarkan ke master kategori supaya muncul di dropdown selanjutnya.
    if ($kategori !== '') {
        $db->prepare("INSERT IGNORE INTO categories (nama) VALUES (?)")->execute([$kategori]);
    }
    $data = [
        $kode,
        trim($_POST['barcode'] ?? ''),
        trim($_POST['nama'] ?? ''),
        $kategori,
        (float)($_POST['harga_beli'] ?? 0),
        (float)($_POST['harga_jual'] ?? 0),
        (int)($_POST['stok'] ?? 0),
        (int)($_POST['stok_min'] ?? 5),
    ];
    if ($kode !== '' && $data[2] !== '') {
        try {
            if ($id) {
                $db->prepare("UPDATE parts SET kode=?, barcode=?, nama=?, kategori=?, harga_beli=?, harga_jual=?, stok=?, stok_min=? WHERE id=?")
                   ->execute([...$data, $id]);
                set_flash('success', 'Data sparepart diperbarui.');
            } else {
                $db->prepare("INSERT INTO parts (kode, barcode, nama, kategori, harga_beli, harga_jual, stok, stok_min) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute($data);
                set_flash('success', 'Sparepart baru ditambahkan.');
            }
        } catch (PDOException $e) {
            set_flash('danger', 'Gagal menyimpan: kode sparepart sudah digunakan.');
        }
    }
    header('Location: index.php?page=parts'); exit;
}
if ($action === 'delete') {
    $db->prepare("DELETE FROM parts WHERE id=?")->execute([(int)$_POST['id']]);
    set_flash('success', 'Sparepart dihapus.');
    header('Location: index.php?page=parts'); exit;
}

$q = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? '';
$sql = "SELECT * FROM parts";
$where = []; $params = [];
if ($q !== '') { $where[] = "(nama LIKE ? OR kode LIKE ? OR barcode LIKE ?)"; $params = ["%$q%", "%$q%", "%$q%"]; }
if ($filter === 'low') { $where[] = "stok <= stok_min"; }
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY id DESC";
$stmt = $db->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$edit = null;
if (isset($_GET['edit'])) {
    $s = $db->prepare("SELECT * FROM parts WHERE id=?"); $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch(PDO::FETCH_ASSOC);
}
// Master kategori untuk dropdown pada form input sparepart
$categories = $db->query("SELECT nama FROM categories ORDER BY nama")->fetchAll(PDO::FETCH_COLUMN);
?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card table-card"><div class="card-body">
      <h2 class="h6"><?= $edit ? 'Edit Sparepart' : 'Tambah Sparepart' ?></h2>
      <form method="post" data-testid="part-form">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
        <div class="row g-2">
          <div class="col-12 mb-2">
            <label class="form-label small mb-1">
              <i class="bi bi-cloud-download me-1"></i>Cari dari hargasukucadang.online
              <span class="text-muted" style="font-size:11px">— klik hasil untuk mengisi Kode, Nama &amp; Kategori</span>
            </label>
            <div class="input-group input-group-sm">
              <input type="text" id="hscQuery" class="form-control" placeholder="Ketik nama part (mis. shock, kampas rem) atau kode part (mis. 3XP)..." autocomplete="off" data-testid="hsc-query">
              <button type="button" id="hscSearchBtn" class="btn btn-outline-primary" data-testid="hsc-search-btn"><i class="bi bi-search"></i></button>
            </div>
            <div id="hscResults" class="list-group mt-1" style="max-height:230px;overflow:auto;display:none" data-testid="hsc-results"></div>
            <div id="hscMsg" class="small text-muted mt-1" style="display:none" data-testid="hsc-msg"></div>
          </div>
          <div class="col-6 mb-2"><label class="form-label small">Kode Sparepart</label>
            <input name="kode" class="form-control form-control-sm" required value="<?= esc($edit['kode'] ?? '') ?>" data-testid="part-kode"></div>
          <div class="col-6 mb-2"><label class="form-label small">Barcode <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" data-bs-toggle="modal" data-bs-target="#scanModal" data-scan-target="part-barcode" data-testid="part-scan-btn"><i class="bi bi-upc-scan"></i></button></label>
            <input name="barcode" id="part-barcode" class="form-control form-control-sm" value="<?= esc($edit['barcode'] ?? '') ?>" data-testid="part-barcode"></div>
          <div class="col-12 mb-2"><label class="form-label small">Nama Barang</label>
            <input name="nama" class="form-control form-control-sm" required value="<?= esc($edit['nama'] ?? '') ?>" data-testid="part-nama"></div>
          <div class="col-12 mb-2"><label class="form-label small">Kategori <a href="index.php?page=categories" class="text-decoration-none">(kelola kategori)</a></label>
            <select name="kategori" class="form-select form-select-sm" data-testid="part-kategori">
              <option value="">- Tanpa kategori -</option>
              <?php $katEdit = $edit['kategori'] ?? ''; $katAda = in_array($katEdit, $categories, true); ?>
              <?php foreach ($categories as $cat): ?>
              <option value="<?= esc($cat) ?>" <?= $katEdit === $cat ? 'selected' : '' ?>><?= esc($cat) ?></option>
              <?php endforeach; ?>
              <?php if ($katEdit !== '' && !$katAda): ?><option value="<?= esc($katEdit) ?>" selected><?= esc($katEdit) ?> (lama)</option><?php endif; ?>
            </select></div>
          <div class="col-6 mb-2"><label class="form-label small">Harga Beli</label>
            <input name="harga_beli" type="number" min="0" class="form-control form-control-sm" value="<?= esc($edit['harga_beli'] ?? 0) ?>" data-testid="part-harga-beli"></div>
          <div class="col-6 mb-2"><label class="form-label small">Harga Jual</label>
            <input name="harga_jual" type="number" min="0" class="form-control form-control-sm" value="<?= esc($edit['harga_jual'] ?? 0) ?>" data-testid="part-harga-jual"></div>
          <div class="col-6 mb-2"><label class="form-label small">Stok Saat Ini</label>
            <input name="stok" type="number" min="0" class="form-control form-control-sm" value="<?= esc($edit['stok'] ?? 0) ?>" data-testid="part-stok"></div>
          <div class="col-6 mb-3"><label class="form-label small">Stok Minimum (alert)</label>
            <input name="stok_min" type="number" min="0" class="form-control form-control-sm" value="<?= esc($edit['stok_min'] ?? 5) ?>" data-testid="part-stok-min"></div>
        </div>
        <button class="btn btn-sm btn-primary w-100" data-testid="part-submit"><?= $edit ? 'Simpan Perubahan' : 'Tambah Sparepart' ?></button>
        <?php if ($edit): ?><a href="index.php?page=parts" class="btn btn-sm btn-outline-secondary w-100 mt-2">Batal</a><?php endif; ?>
      </form>
    </div></div>

    <div class="card table-card mt-3"><div class="card-body">
      <h2 class="h6">Import Excel / CSV</h2>
      <p class="small text-muted mb-2">
        Format kolom: <code>kode, nama, kategori, harga_beli, harga_jual, stok, stok_min, barcode</code>.
      </p>
      <div class="mb-2">
        <label class="form-label small mb-1">Mode Import</label>
        <div class="btn-group btn-group-sm w-100" role="group" data-testid="import-mode-group">
          <input type="radio" class="btn-check" name="importMode" id="importModeBaru" value="baru" checked data-testid="import-mode-baru">
          <label class="btn btn-outline-success" for="importModeBaru" title="Hanya menambahkan sparepart baru. Kode yang sudah ada akan dilewati.">
            <i class="bi bi-plus-circle me-1"></i>Format Baru (tambah)
          </label>
          <input type="radio" class="btn-check" name="importMode" id="importModeUpgrade" value="upgrade" data-testid="import-mode-upgrade">
          <label class="btn btn-outline-warning" for="importModeUpgrade" title="Update data lama & tambahkan sparepart baru (upsert).">
            <i class="bi bi-arrow-repeat me-1"></i>Format Lama/Upgrade
          </label>
        </div>
        <div class="small text-muted mt-1" id="importModeHint" data-testid="import-mode-hint">
          Mode "Baru": hanya menambahkan sparepart baru, kode yang sudah ada akan dilewati.
        </div>
      </div>
      <input type="file" id="importFile" accept=".xlsx,.xls,.csv" class="form-control form-control-sm mb-2" data-testid="import-file">
      <div id="importResult" class="small" data-testid="import-result"></div>
      <div class="d-grid gap-1 mt-1">
        <button type="button" class="btn btn-sm btn-outline-success" onclick="downloadTemplate('baru')" data-testid="import-template-baru-btn">
          <i class="bi bi-download me-1"></i>Download Format Baru (Tambah Sparepart)
        </button>
        <button type="button" class="btn btn-sm btn-outline-warning" onclick="downloadTemplate('upgrade')" data-testid="import-template-upgrade-btn">
          <i class="bi bi-download me-1"></i>Download Format Lama / Upgrade
        </button>
      </div>
    </div></div>
  </div>

  <div class="col-lg-8">
    <div class="card table-card"><div class="card-body">
      <div class="d-flex justify-content-end gap-1 mb-2" data-testid="parts-export-buttons">
        <a class="btn btn-sm btn-outline-danger" target="_blank" href="export.php?type=parts&format=pdf" data-testid="parts-export-pdf"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
        <a class="btn btn-sm btn-outline-success" href="export.php?type=parts&format=xls" data-testid="parts-export-xls"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
        <a class="btn btn-sm btn-outline-primary" href="export.php?type=parts&format=doc" data-testid="parts-export-doc"><i class="bi bi-file-earmark-word me-1"></i>Word</a>
      </div>
      <form class="d-flex mb-3" method="get">
        <input type="hidden" name="page" value="parts">
        <input name="q" class="form-control form-control-sm me-2" placeholder="Cari nama / kode / barcode..." value="<?= esc($q) ?>" data-testid="part-search">
        <button class="btn btn-sm btn-outline-primary me-2" data-testid="part-search-btn"><i class="bi bi-search"></i></button>
        <a href="index.php?page=parts&filter=low" class="btn btn-sm btn-outline-danger text-nowrap" data-testid="part-low-filter"><i class="bi bi-exclamation-triangle me-1"></i>Stok Menipis</a>
      </form>
      <div class="table-responsive">
      <table class="table table-sm align-middle" data-testid="parts-table">
        <thead><tr><th>Kode</th><th>Nama</th><th>Kategori</th><th class="text-end">H. Beli</th><th class="text-end">H. Jual</th><th class="text-end">Stok</th><th class="text-end">Aksi</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted">Belum ada data sparepart.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $low = $r['stok'] <= $r['stok_min']; ?>
          <tr class="<?= $low ? 'table-danger' : '' ?>">
            <td><?= esc($r['kode']) ?></td>
            <td><?= esc($r['nama']) ?><?php if ($r['barcode']): ?> <i class="bi bi-upc text-muted" title="<?= esc($r['barcode']) ?>"></i><?php endif; ?></td>
            <td><?= esc($r['kategori']) ?></td>
            <td class="text-end"><?= rupiah($r['harga_beli']) ?></td>
            <td class="text-end"><?= rupiah($r['harga_jual']) ?></td>
            <td class="text-end"><span class="badge bg-<?= $low ? 'danger' : 'success' ?>" data-testid="part-stok-badge-<?= $r['id'] ?>"><?= $r['stok'] ?></span></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-primary" href="index.php?page=parts&edit=<?= $r['id'] ?>" data-testid="part-edit-<?= $r['id'] ?>"><i class="bi bi-pencil"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus sparepart ini?')">
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" data-testid="part-delete-<?= $r['id'] ?>"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div></div>
  </div>
</div>

<!-- Modal scan barcode via kamera HP -->
<div class="modal fade" id="scanModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Scan Barcode</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><div id="scanner" style="width:100%"></div></div>
  </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
let scannerObj = null, scanTarget = null;
const scanModal = document.getElementById('scanModal');
scanModal.addEventListener('shown.bs.modal', e => {
  scanTarget = e.relatedTarget.dataset.scanTarget;
  scannerObj = new Html5Qrcode("scanner");
  scannerObj.start({ facingMode: "environment" }, { fps: 10, qrbox: 250 }, text => {
    document.getElementById(scanTarget).value = text;
    bootstrap.Modal.getInstance(scanModal).hide();
  }, () => {});
});
scanModal.addEventListener('hidden.bs.modal', () => { if (scannerObj) scannerObj.stop().catch(()=>{}); });

// Import Excel/CSV di sisi browser (SheetJS), hasil dikirim JSON ke server
document.getElementById('importFile').addEventListener('change', function() {
  const f = this.files[0]; if (!f) return;
  const mode = (document.querySelector('input[name="importMode"]:checked') || {}).value || 'baru';
  const reader = new FileReader();
  reader.onload = async e => {
    const wb = XLSX.read(e.target.result, { type: 'array' });
    const rows = XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]]);
    const res = await fetch('ajax/import_parts.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ rows, mode })
    });
    const data = await res.json();
    const el = document.getElementById('importResult');
    el.innerHTML = '<div class="alert alert-' + (data.ok ? 'success' : 'danger') + ' py-2">' + data.message + '</div>';
    if (data.ok) setTimeout(() => location.reload(), 1500);
  };
  reader.readAsArrayBuffer(f);
});

// Update hint teks sesuai mode terpilih
document.querySelectorAll('input[name="importMode"]').forEach(function (r) {
  r.addEventListener('change', function () {
    const hint = document.getElementById('importModeHint');
    if (!hint) return;
    hint.textContent = r.value === 'upgrade'
      ? 'Mode "Upgrade": kode yang sudah ada akan diperbarui datanya (nama, kategori, harga, stok), yang belum ada akan ditambahkan.'
      : 'Mode "Baru": hanya menambahkan sparepart baru, kode yang sudah ada akan dilewati.';
  });
});

function downloadTemplate(mode) {
  mode = mode || 'baru';
  const header = "kode,nama,kategori,harga_beli,harga_jual,stok,stok_min,barcode";
  let content, filename;
  if (mode === 'upgrade') {
    // Format lama/upgrade: baris contoh menyertakan kode yang mungkin sudah ada
    // untuk memperbarui datanya, plus baris baru untuk ditambahkan.
    content = header + "\n"
      + "OLI-MPX,Oli MPX 0.8L (update),Oli,36000,48000,25,5,899123456001\n"
      + "KMP-BARU,Kampas Rem Baru,Kampas Rem,45000,65000,10,5,899123456999\n";
    filename = 'template_sparepart_upgrade.csv';
  } else {
    // Format baru: fokus menambah sparepart baru saja.
    content = header + "\n"
      + "OLI-MPX,Oli MPX 0.8L,Oli,35000,45000,10,5,899123456001\n"
      + "BSI-01,Busi Standar NGK,Busi,15000,25000,20,5,899123456002\n";
    filename = 'template_sparepart_baru.csv';
  }
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([content], { type: 'text/csv' }));
  a.download = filename; a.click();
}

// ============================================================
// Cari sparepart dari hargasukucadang.online -> isi Kode & Nama otomatis
// ============================================================
(function () {
  const q = document.getElementById('hscQuery');
  const btn = document.getElementById('hscSearchBtn');
  const box = document.getElementById('hscResults');
  const msg = document.getElementById('hscMsg');
  if (!q || !btn) return;

  const form = document.querySelector('form[data-testid="part-form"]');
  const kodeInput = form ? form.querySelector('[name="kode"]') : null;
  const namaInput = form ? form.querySelector('[name="nama"]') : null;
  const katSelect = form ? form.querySelector('[name="kategori"]') : null;

  function showMsg(t, cls) { msg.className = 'small mt-1 ' + (cls || 'text-muted'); msg.textContent = t; msg.style.display = t ? 'block' : 'none'; }
  function clearResults() { box.innerHTML = ''; box.style.display = 'none'; }
  function esc(s) { return (s || '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

  // Sisipkan / pilih opsi kategori pada dropdown. Jika kategori belum ada di
  // master, tambahkan sebagai opsi dinamis (akan diregistrasikan ke master saat form disimpan).
  function setKategori(val) {
    if (!katSelect || !val) return;
    var v = String(val).trim();
    if (!v) return;
    var found = false;
    for (var i = 0; i < katSelect.options.length; i++) {
      if (katSelect.options[i].value === v) { found = true; break; }
    }
    if (!found) {
      var opt = document.createElement('option');
      opt.value = v; opt.textContent = v + ' (dari hargasukucadang.online)';
      opt.setAttribute('data-hsc', '1');
      katSelect.appendChild(opt);
    }
    katSelect.value = v;
  }

  async function doSearch() {
    const term = q.value.trim();
    if (term.length < 2) { showMsg('Kata kunci minimal 2 karakter.', 'text-danger'); clearResults(); return; }
    showMsg('Mencari di hargasukucadang.online...'); clearResults(); btn.disabled = true;
    try {
      // field=auto: server akan otomatis memilih pencarian by-kode atau by-nama.
      const url = 'ajax/lookup_hsc.php?field=auto&q=' + encodeURIComponent(term);
      const r = await fetch(url, { headers: { 'X-Requested-With': 'fetch' } });
      const data = await r.json();
      btn.disabled = false;
      if (data.error) { showMsg(data.error, 'text-danger'); return; }
      const rows = data.results || [];
      if (!rows.length) { showMsg('Tidak ada hasil untuk "' + term + '".', 'text-warning'); return; }
      if (data.offline) {
        showMsg('Situs sumber sedang offline. Menampilkan ' + rows.length + ' hasil dari katalog lokal. Klik untuk memakai.', 'text-warning');
      } else {
        showMsg(rows.length + ' hasil. Klik salah satu untuk memakainya.', 'text-success');
      }
      box.style.display = 'block';
      rows.forEach(function (it) {
        const st = (it.status || '').toUpperCase();
        const badge = st === 'AKTIF' ? 'success' : (st === 'STOP' ? 'secondary' : 'info');
        const el = document.createElement('button');
        el.type = 'button';
        el.className = 'list-group-item list-group-item-action py-1';
        el.setAttribute('data-testid', 'hsc-result-item');
        el.innerHTML =
          '<div class="d-flex justify-content-between align-items-center">' +
          '<span class="fw-semibold small">' + esc(it.kode) + '</span>' +
          '<span class="badge bg-' + badge + '" style="font-size:10px">' + esc(it.status || '-') + '</span></div>' +
          '<div class="small">' + esc(it.nama) + '</div>' +
          '<div class="text-muted" style="font-size:10px">' + (it.tipe ? ('Kategori: ' + esc(it.tipe) + ' &bull; ') : '') + 'Harga ref: ' + esc(it.harga || '-') + '</div>';
        el.addEventListener('click', function () {
          if (kodeInput) kodeInput.value = it.kode;
          if (namaInput) namaInput.value = it.nama;
          setKategori(it.tipe);
          clearResults();
          showMsg('Terisi: ' + it.kode + ' - ' + it.nama + (it.tipe ? ' [Kategori: ' + it.tipe + ']' : ''), 'text-success');
          if (namaInput) namaInput.focus();
        });
        box.appendChild(el);
      });
    } catch (e) {
      btn.disabled = false;
      showMsg('Gagal memuat hasil. Periksa koneksi internet server.', 'text-danger');
    }
  }

  btn.addEventListener('click', doSearch);
  q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doSearch(); } });
})();
</script>
