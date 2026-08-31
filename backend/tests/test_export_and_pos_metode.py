"""
Backend tests (iter-4) for the PHP-native simbeng_v1 app:
  - export.php scope=page / scope=all for type=parts (+ q / filter)
  - POS metode_bayar (cash/transfer) persisted on save + shown on receipt
  - transactions.metode_bayar schema migration
"""
import os
import re
import subprocess
import pytest
import requests

BASE_URL = os.environ.get("REACT_APP_BACKEND_URL", "").rstrip("/") \
    or "https://simbeng-stock-search.preview.emergentagent.com"

USER = "admin"
PWD = "admin123"


@pytest.fixture(scope="module")
def session():
    s = requests.Session()
    r = s.post(f"{BASE_URL}/?page=login",
               data={"username": USER, "password": PWD},
               allow_redirects=True, timeout=30)
    assert r.status_code == 200
    # trigger init_db to run migration
    dash = s.get(f"{BASE_URL}/?page=dashboard", timeout=30)
    assert "page=login" not in dash.url
    return s


def _count_tr(html: str) -> int:
    return len(re.findall(r"<tr\b", html, re.IGNORECASE))


# ------------------- export.php parts scope=page -------------------
def test_export_parts_scope_page_default(session):
    r = session.get(
        f"{BASE_URL}/export.php",
        params={"type": "parts", "format": "xls",
                "scope": "page", "p": 1, "per_page": 25},
        timeout=30,
    )
    assert r.status_code == 200
    assert "vnd.ms-excel" in r.headers.get("Content-Type", "")
    # filename hint
    assert "daftar_sparepart_hlm1_" in r.headers.get("Content-Disposition", "")
    html = r.text
    trs = _count_tr(html)
    # 1 header + 25 data
    assert trs == 26, f"expected 26 <tr>, got {trs}"
    assert "Halaman 1" in html
    # subtitle mentions "25 baris (dari <total>)"
    assert re.search(r"25 baris \(dari \d+\)", html), "subtitle missing row count"


def test_export_parts_scope_all(session):
    # First discover current total to build a resilient assertion
    r_page = session.get(
        f"{BASE_URL}/export.php",
        params={"type": "parts", "format": "xls",
                "scope": "page", "p": 1, "per_page": 25},
        timeout=30,
    ).text
    m = re.search(r"25 baris \(dari (\d+)\)", r_page)
    assert m, "cannot read total from page export subtitle"
    total = int(m.group(1))

    r = session.get(
        f"{BASE_URL}/export.php",
        params={"type": "parts", "format": "xls", "scope": "all"},
        timeout=60,
    )
    assert r.status_code == 200
    trs = _count_tr(r.text)
    # 1 header + total data rows
    assert trs == total + 1, f"expected {total+1} <tr>, got {trs}"
    # filename should NOT contain hlm
    assert "hlm" not in r.headers.get("Content-Disposition", "")


def test_export_parts_scope_page_search_q(session):
    r = session.get(
        f"{BASE_URL}/export.php",
        params={"type": "parts", "format": "xls",
                "scope": "page", "q": "OLI", "per_page": 50, "p": 1},
        timeout=30,
    )
    assert r.status_code == 200
    html = r.text
    assert 'Pencarian: &quot;OLI&quot;' in html or 'Pencarian: "OLI"' in html, \
        "subtitle missing search label"
    # every data row should contain OLI (case-insensitive) in some cell
    # extract data <tr> after header
    body_rows = re.findall(r"<tr[^>]*>(.*?)</tr>", html, re.IGNORECASE | re.DOTALL)
    # first row is the header, skip
    data_rows = body_rows[1:]
    assert data_rows, "no data rows returned for q=OLI"
    for row in data_rows:
        assert "OLI" in row.upper(), "row does not match OLI filter"


def test_export_parts_scope_page_filter_low(session):
    r = session.get(
        f"{BASE_URL}/export.php",
        params={"type": "parts", "format": "xls",
                "scope": "page", "filter": "low", "per_page": 25, "p": 1},
        timeout=30,
    )
    assert r.status_code == 200
    html = r.text
    assert "Stok Menipis" in html
    # each data row's Status column should be MENIPIS
    body_rows = re.findall(r"<tr[^>]*>(.*?)</tr>", html, re.IGNORECASE | re.DOTALL)
    data_rows = body_rows[1:]
    for row in data_rows:
        # skip "no data" placeholder row
        if "Tidak ada data" in row:
            continue
        assert "MENIPIS" in row, "filter=low returned non-low row"


# ------------------- Schema migration -------------------
def test_transactions_metode_bayar_column():
    # Query DB directly via PHP CLI (uses same includes/db.php as the app)
    out = subprocess.check_output(
        ["php", "-r",
         "require '/app/bengkel/includes/db.php'; init_db(); "
         "$r = db()->query(\"SHOW COLUMNS FROM transactions LIKE 'metode_bayar'\")->fetch(PDO::FETCH_ASSOC); "
         "echo json_encode($r);"],
        timeout=15,
    ).decode()
    assert out.strip() not in ("", "false", "null"), f"metode_bayar column missing: {out}"
    import json
    col = json.loads(out)
    assert col["Field"] == "metode_bayar"
    # Default should be 'cash'
    assert (col.get("Default") or "").lower() == "cash"


# ------------------- POS save transaction with metode_bayar -------------------
def _create_trx(session, metode):
    """POST save_trx with a single jasa item; return new transaction id."""
    data = {
        "action": "save_trx",
        "edit_id": "0",
        "customer_id": "1",
        "vehicle_id": "",
        "metode_bayar": metode,
        "jasa_nama[]": f"TEST_{metode}",
        "jasa_biaya[]": "10000",
        "jasa_garansi[]": "0",
    }
    r = session.post(f"{BASE_URL}/?page=pos", data=data,
                     allow_redirects=False, timeout=30)
    assert r.status_code in (301, 302), f"expected redirect, got {r.status_code}: {r.text[:200]}"
    loc = r.headers.get("Location", "")
    m = re.search(r"page=receipt&id=(\d+)", loc)
    assert m, f"redirect not to receipt: {loc}"
    return int(m.group(1))


def _fetch_metode_from_db(trx_id):
    out = subprocess.check_output(
        ["php", "-r",
         f"require '/app/bengkel/includes/db.php'; init_db(); "
         f"$s = db()->prepare('SELECT metode_bayar FROM transactions WHERE id=?'); "
         f"$s->execute([{trx_id}]); echo $s->fetchColumn();"],
        timeout=15,
    ).decode().strip()
    return out


def test_pos_save_transfer_and_receipt(session):
    trx_id = _create_trx(session, "transfer")
    assert _fetch_metode_from_db(trx_id) == "transfer"

    r = session.get(f"{BASE_URL}/?page=receipt&id={trx_id}", timeout=30)
    assert r.status_code == 200
    html = r.text
    assert 'data-testid="receipt-metode-cash"' in html
    assert 'data-testid="receipt-metode-transfer"' in html
    # cash unchecked, transfer checked
    cash_cell = re.search(
        r'data-testid="receipt-metode-cash"[^>]*>(.*?)</td>',
        html, re.DOTALL).group(1)
    trf_cell = re.search(
        r'data-testid="receipt-metode-transfer"[^>]*>(.*?)</td>',
        html, re.DOTALL).group(1)
    assert "&#9744;" in cash_cell, "Cash should be unchecked (☐)"
    assert "&#9746;" in trf_cell, "Transfer should be checked (☑)"
    # WA text line
    assert "Bayar+++%3A+Transfer" in html or "Bayar%20%20%20%20%3A%20Transfer" in html \
        or "Bayar    : Transfer" in html \
        or "Bayar+++%3A+Transfer" in html
    # Simpler check: url-encoded form appears in wa_url
    assert "Transfer" in html


def test_pos_save_cash_default_and_receipt(session):
    trx_id = _create_trx(session, "cash")
    assert _fetch_metode_from_db(trx_id) == "cash"

    r = session.get(f"{BASE_URL}/?page=receipt&id={trx_id}", timeout=30)
    html = r.text
    cash_cell = re.search(
        r'data-testid="receipt-metode-cash"[^>]*>(.*?)</td>',
        html, re.DOTALL).group(1)
    trf_cell = re.search(
        r'data-testid="receipt-metode-transfer"[^>]*>(.*?)</td>',
        html, re.DOTALL).group(1)
    assert "&#9746;" in cash_cell, "Cash should be checked (☑)"
    assert "&#9744;" in trf_cell, "Transfer should be unchecked (☐)"


# ------------------- Cleanup TEST_ transactions -------------------
def test_cleanup_test_trx():
    subprocess.run(
        ["php", "-r",
         "require '/app/bengkel/includes/db.php'; init_db(); "
         "$ids = db()->query(\"SELECT id FROM transactions t JOIN transaction_items ti ON ti.transaction_id=t.id "
         "WHERE ti.nama LIKE 'TEST_%'\")->fetchAll(PDO::FETCH_COLUMN); "
         "if ($ids){ $in = implode(',', array_map('intval',$ids)); "
         "  db()->exec(\"DELETE FROM stock_movements WHERE ref_type='penjualan' AND ref_id IN ($in)\"); "
         "  db()->exec(\"DELETE FROM transaction_items WHERE transaction_id IN ($in)\"); "
         "  db()->exec(\"DELETE FROM transactions WHERE id IN ($in)\"); } "
         "echo 'ok';"],
        check=False, timeout=15,
    )
