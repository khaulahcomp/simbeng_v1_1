"""
Backend tests for the PHP-native simbeng_v1 app (running on preview URL).
Covers new features (iter-2):
  - Preview import (no DB write) + commit + import_logs
  - Upgrade preview + commit + restore
  - Sync HSC GET/POST endpoints
Regression:
  - Login, HSC search auto-detect, short query validation,
    baru skip-existing, stock autocomplete, page loads
"""
import os
import re
import time
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
    r = s.post(f"{BASE_URL}/?page=login",
               data={"username": USER, "password": PWD},
               allow_redirects=True, timeout=30)
    assert r.status_code == 200, f"login http {r.status_code}"
    dash = s.get(f"{BASE_URL}/?page=dashboard", timeout=30)
    assert "page=login" not in dash.url, f"still on login: {dash.url}"
    return s


# ---------- Login / page ----------
def test_login_and_parts_page(session):
    r = session.get(f"{BASE_URL}/?page=parts", timeout=30)
    assert r.status_code == 200
    for tid in ["hsc-query", "hsc-filters", "hsc-sync-info", "hsc-sync-now-btn",
                "import-mode-baru", "import-mode-upgrade", "import-file",
                "import-preview-modal", "import-preview-summary",
                "import-preview-confirm", "import-preview-cancel",
                "import-history-card", "import-history-count"]:
        assert tid in r.text, f"missing data-testid '{tid}' on parts page"


# ---------- HSC search (regression) ----------
def test_hsc_search_by_name(session):
    r = session.get(f"{BASE_URL}/ajax/lookup_hsc.php",
                    params={"field": "auto", "q": "shock"}, timeout=45)
    assert r.status_code == 200
    data = r.json()
    assert isinstance(data.get("results"), list)
    assert data.get("source") in ("live", "local")
    assert len(data["results"]) >= 1


def test_hsc_search_short_query(session):
    r = session.get(f"{BASE_URL}/ajax/lookup_hsc.php",
                    params={"field": "auto", "q": "a"}, timeout=15)
    data = r.json()
    assert data["results"] == []


# ---------- Preview import: mode baru ----------
def test_import_preview_baru_no_write(session):
    payload = {
        "preview": 1,
        "mode": "baru",
        "rows": [
            {"kode": "OLI-001", "nama": "X"},
            {"kode": "PVW-1", "nama": "Item Baru", "kategori": "Uji"},
            {"kode": "", "nama": "no code"},
        ],
    }
    r = session.post(f"{BASE_URL}/ajax/import_parts.php", json=payload, timeout=30)
    assert r.status_code == 200, r.text[:200]
    data = r.json()
    assert data.get("ok") is True
    assert data.get("preview") is True
    s = data["summary"]
    assert s["total"] == 3
    assert s["new"] == 1
    assert s["skip"] == 1
    assert s["invalid"] == 1
    assert s["update"] == 0
    # Verify per-row statuses
    statuses = {r["kode"] or "(empty)": r["status"] for r in data["rows"]}
    assert statuses["OLI-001"] == "skip"
    assert statuses["PVW-1"] == "new"
    assert statuses["(empty)"] == "invalid"
    # Verify no DB write: search for PVW-1 on parts page (check table cell, not URL/input value)
    page = session.get(f"{BASE_URL}/?page=parts&q=PVW-1", timeout=30).text
    assert "<td>PVW-1</td>" not in page, "PREVIEW MODE WROTE TO DB: PVW-1 found in parts list"


# ---------- Commit import: mode baru writes + import_logs ----------
def test_import_commit_baru_writes_and_logs(session):
    payload = {
        "mode": "baru",
        "filename": "TEST_pvw.csv",
        "rows": [
            {"kode": "OLI-001", "nama": "X"},
            {"kode": "PVW-1", "nama": "Item Baru", "kategori": "Uji",
             "harga_beli": 100, "harga_jual": 200, "stok": 1, "stok_min": 1},
            {"kode": "", "nama": "no code"},
        ],
    }
    r = session.post(f"{BASE_URL}/ajax/import_parts.php", json=payload, timeout=30)
    assert r.status_code == 200
    data = r.json()
    assert data.get("ok") is True
    assert data.get("preview") is False
    s = data["summary"]
    assert s["new"] == 1 and s["skip"] == 1 and s["invalid"] == 1

    # Verify PVW-1 inserted
    page = session.get(f"{BASE_URL}/?page=parts&q=PVW-1", timeout=30).text
    assert "<td>PVW-1</td>" in page, "PVW-1 was not inserted after commit"

    # Verify import history card shows the new log with filename TEST_pvw.csv
    parts_page = session.get(f"{BASE_URL}/?page=parts", timeout=30).text
    assert "import-history-table" in parts_page
    assert "TEST_pvw.csv" in parts_page, "import_logs row not showing filename in history"
    assert "Administrator" in parts_page, "history row does not show user_nama"


# ---------- Preview + Commit upgrade ----------
def test_import_preview_and_commit_upgrade(session):
    # Fetch current harga_jual of OLI-001 for restore (best effort from UI text)
    original_harga = 52000

    # Preview upgrade
    payload = {
        "preview": 1,
        "mode": "upgrade",
        "rows": [{"kode": "OLI-001", "nama": "NewName", "harga_jual": 99999}],
    }
    r = session.post(f"{BASE_URL}/ajax/import_parts.php", json=payload, timeout=30)
    data = r.json()
    assert data.get("ok") is True and data.get("preview") is True
    assert data["summary"]["update"] == 1
    row0 = data["rows"][0]
    assert row0["status"] == "update"
    assert "sudah ada" in row0["reason"].lower()

    # Commit upgrade
    payload["preview"] = 0
    payload.pop("preview")  # ensure absent
    payload["preview"] = 0
    del payload["preview"]
    r2 = session.post(f"{BASE_URL}/ajax/import_parts.php", json=payload, timeout=30)
    data2 = r2.json()
    assert data2.get("ok") is True and data2.get("preview") is False
    assert data2["summary"]["update"] == 1

    # Verify update visible
    page = session.get(f"{BASE_URL}/?page=parts&q=OLI-001", timeout=30).text
    assert "NewName" in page

    # Restore original
    restore = {"mode": "upgrade", "rows": [
        {"kode": "OLI-001", "nama": "Oli Mesin 10W-40 800ml", "kategori": "Oli",
         "harga_beli": 38000, "harga_jual": original_harga, "stok": 25, "stok_min": 5},
    ]}
    session.post(f"{BASE_URL}/ajax/import_parts.php", json=restore, timeout=30)


# ---------- Sync HSC GET ----------
def test_sync_hsc_get(session):
    r = session.get(f"{BASE_URL}/ajax/sync_hsc.php", timeout=30)
    assert r.status_code == 200, r.text[:200]
    data = r.json()
    assert data.get("ok") is True
    assert "last_sync" in data
    assert "catalog_count" in data
    assert isinstance(data["catalog_count"], int)
    assert data["catalog_count"] > 0, f"expected populated part_catalog, got {data['catalog_count']}"


# ---------- Sync HSC POST ----------
def test_sync_hsc_post_updates_timestamp(session):
    before = session.get(f"{BASE_URL}/ajax/sync_hsc.php", timeout=30).json().get("last_sync", "")
    time.sleep(1)
    r = session.post(f"{BASE_URL}/ajax/sync_hsc.php", timeout=180)
    assert r.status_code == 200, r.text[:300]
    data = r.json()
    assert data.get("ok") is True
    assert "result" in data
    result = data["result"]
    for k in ("inserted", "updated", "rows", "errors", "duration_sec"):
        assert k in result, f"sync result missing key {k}"
    after = data.get("last_sync", "")
    assert after and after != before, f"last_sync not refreshed: before={before} after={after}"


# ---------- Regression: stock autocomplete ----------
def test_stock_lookup_search_parts(session):
    r = session.get(f"{BASE_URL}/ajax/lookup.php",
                    params={"action": "search_parts", "q": "OLI"}, timeout=30)
    assert r.status_code == 200
    data = r.json()
    items = data.get("results") if isinstance(data, dict) else data
    assert items
    assert any("OLI" in (it.get("kode") or "").upper() for it in items)


def test_stock_page_loads(session):
    r = session.get(f"{BASE_URL}/?page=stock", timeout=30)
    assert r.status_code == 200
    assert "stock-in-part-search" in r.text
    assert "stock-out-part-search" in r.text


# ---------- Cleanup: delete PVW-1 via direct DB is not exposed;
# Try to remove via parts delete endpoint if available.
def test_cleanup_pvw1(session):
    # Cleanup PVW-1 and import log via direct DB access to keep DB tidy.
    import subprocess
    subprocess.run(
        ["php", "-r",
         "require '/app/bengkel/includes/db.php'; init_db(); "
         "db()->exec(\"DELETE FROM parts WHERE kode='PVW-1'\"); "
         "db()->exec(\"DELETE FROM import_logs WHERE filename='TEST_pvw.csv'\");"],
        check=False, timeout=15,
    )
