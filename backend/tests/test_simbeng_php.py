"""
Backend tests for the PHP-native simbeng_v1 app (running on preview URL).
Covers:
  - Login flow (session cookies)
  - HSC search endpoint (auto detect nama vs kode)
  - Import parts (mode=baru: skip existing; mode=upgrade: upsert)
  - Regression: parts autocomplete lookup used by stock page
"""
import os
import re
import pytest
import requests

BASE_URL = os.environ.get("REACT_APP_BACKEND_URL", "").rstrip("/")
if not BASE_URL:
    BASE_URL = "https://simbeng-stock-search.preview.emergentagent.com"

USER = "admin"
PWD = "admin123"


@pytest.fixture(scope="module")
def session():
    s = requests.Session()
    # Login via POST to index.php?page=login
    r = s.post(f"{BASE_URL}/?page=login",
               data={"username": USER, "password": PWD},
               allow_redirects=True, timeout=30)
    assert r.status_code == 200, f"login http {r.status_code}"
    # After login, the dashboard should be reachable
    dash = s.get(f"{BASE_URL}/?page=dashboard", timeout=30)
    assert "login" not in dash.url.lower() or "page=login" not in dash.url, \
        f"still on login page after auth: {dash.url}"
    return s


# ---------- Login ----------
def test_login_success(session):
    r = session.get(f"{BASE_URL}/?page=parts", timeout=30)
    assert r.status_code == 200
    # parts page markers
    assert "hsc-query" in r.text
    assert "import-mode-baru" in r.text
    assert "import-mode-upgrade" in r.text


def test_parts_page_no_field_dropdown(session):
    """The old <select id="hscField"> dropdown must be gone."""
    r = session.get(f"{BASE_URL}/?page=parts", timeout=30)
    assert r.status_code == 200
    assert 'id="hscField"' not in r.text
    assert 'hscField' not in r.text


# ---------- HSC search ----------
def test_hsc_search_by_name(session):
    r = session.get(f"{BASE_URL}/ajax/lookup_hsc.php",
                    params={"field": "auto", "q": "shock"}, timeout=45)
    assert r.status_code == 200, r.text[:200]
    data = r.json()
    assert "results" in data
    assert isinstance(data["results"], list)
    assert data.get("source") in ("live", "local")
    # We expect at least one result (either live or cached-local)
    assert len(data["results"]) >= 1, f"no results: {data}"
    # Must NOT contain the old error string
    assert "Gagal memuat hasil" not in (data.get("error") or "")


def test_hsc_search_by_code_autodetect(session):
    r = session.get(f"{BASE_URL}/ajax/lookup_hsc.php",
                    params={"field": "auto", "q": "3XP"}, timeout=45)
    assert r.status_code == 200
    data = r.json()
    assert "results" in data
    # Live source ideally; but at minimum request should succeed
    assert data.get("source") in ("live", "local", "none")
    # If live/local returned rows, validate structure
    for row in data["results"][:5]:
        assert "kode" in row and "nama" in row


def test_hsc_search_short_query(session):
    r = session.get(f"{BASE_URL}/ajax/lookup_hsc.php",
                    params={"field": "auto", "q": "a"}, timeout=15)
    data = r.json()
    assert data["results"] == []
    assert "minimal 2 karakter" in (data.get("error") or "")


# ---------- Import parts ----------
def test_import_baru_skips_existing(session):
    payload = {
        "mode": "baru",
        "rows": [
            {"kode": "OLI-001", "nama": "X", "harga_beli": 0, "harga_jual": 0, "stok": 0, "stok_min": 0},
            {"kode": "TEST-NEW-001", "nama": "TEST Sparepart Baru", "kategori": "UjiBaru",
             "harga_beli": 1000, "harga_jual": 2000, "stok": 1, "stok_min": 1},
        ],
    }
    r = session.post(f"{BASE_URL}/ajax/import_parts.php", json=payload, timeout=30)
    assert r.status_code == 200, r.text[:200]
    data = r.json()
    assert data.get("ok") is True, data
    msg = data.get("message", "")
    assert "1 ditambah" in msg and "1 dilewati" in msg, f"unexpected message: {msg}"

    # Verify OLI-001 name is NOT "X"
    page = session.get(f"{BASE_URL}/?page=parts&q=OLI-001", timeout=30).text
    assert "OLI-001" in page
    # If name was clobbered to 'X' we'd see '<td>X</td>' in the row
    row_match = re.search(r"OLI-001</td>\s*<td>([^<]+)", page)
    assert row_match, "OLI-001 row not found on page"
    assert row_match.group(1).strip() != "X", "existing OLI-001 was overwritten by mode=baru!"

    # Verify TEST-NEW-001 got inserted
    page2 = session.get(f"{BASE_URL}/?page=parts&q=TEST-NEW-001", timeout=30).text
    assert "TEST-NEW-001" in page2


def test_import_upgrade_updates_existing(session):
    # Save original OLI-001 name
    page = session.get(f"{BASE_URL}/?page=parts&q=OLI-001", timeout=30).text
    orig = re.search(r"OLI-001</td>\s*<td>([^<]+)", page)
    original_name = orig.group(1).strip() if orig else "Oli Mesin 10W-40 800ml"

    payload = {
        "mode": "upgrade",
        "rows": [
            {"kode": "OLI-001", "nama": "Oli Mesin Upgraded", "kategori": "Oli",
             "harga_beli": 38000, "harga_jual": 55000, "stok": 25, "stok_min": 5},
        ],
    }
    r = session.post(f"{BASE_URL}/ajax/import_parts.php", json=payload, timeout=30)
    assert r.status_code == 200
    data = r.json()
    assert data.get("ok") is True
    msg = data.get("message", "")
    assert "0 ditambah" in msg and "1 diperbarui" in msg, f"unexpected: {msg}"

    # verify update
    page2 = session.get(f"{BASE_URL}/?page=parts&q=OLI-001", timeout=30).text
    assert "Oli Mesin Upgraded" in page2

    # Restore original name to keep DB tidy
    restore = {"mode": "upgrade", "rows": [
        {"kode": "OLI-001", "nama": original_name, "kategori": "Oli",
         "harga_beli": 35000, "harga_jual": 50000, "stok": 25, "stok_min": 5},
    ]}
    session.post(f"{BASE_URL}/ajax/import_parts.php", json=restore, timeout=30)


# ---------- Regression: stock autocomplete ----------
def test_stock_lookup_search_parts(session):
    r = session.get(f"{BASE_URL}/ajax/lookup.php",
                    params={"action": "search_parts", "q": "OLI"}, timeout=30)
    assert r.status_code == 200, r.text[:200]
    data = r.json()
    # Might be {results:[...]} or list; be tolerant
    items = data.get("results") if isinstance(data, dict) else data
    assert items, f"no autocomplete results: {data}"
    assert any("OLI" in (it.get("kode") or "").upper() for it in items)


def test_stock_page_loads(session):
    r = session.get(f"{BASE_URL}/?page=stock", timeout=30)
    assert r.status_code == 200
    assert "stock-in-part-search" in r.text
    assert "stock-out-part-search" in r.text


# ---------- Cleanup: remove TEST-NEW-001 via UI form is not exposed for external DELETE.
# It will be visible in DB, prefix TEST- makes it identifiable.
