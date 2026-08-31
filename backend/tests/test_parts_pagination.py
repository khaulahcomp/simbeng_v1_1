"""
Backend tests for the parts pagination feature (iter-3).
Verifies default per_page=50, page navigation, per_page switching,
invalid clamping, and integration with search (q) and filter=low.
"""
import os
import re
import html as _html
import pytest
import requests

BASE_URL = os.environ.get("REACT_APP_BACKEND_URL", "").rstrip("/") or \
    "https://simbeng-stock-search.preview.emergentagent.com"

USER, PWD = "admin", "admin123"

# endash used in template: &ndash; -> Unicode – (\u2013)
NDASH = "\u2013"


@pytest.fixture(scope="module")
def session():
    s = requests.Session()
    s.post(f"{BASE_URL}/?page=login",
           data={"username": USER, "password": PWD},
           allow_redirects=True, timeout=30)
    dash = s.get(f"{BASE_URL}/?page=dashboard", timeout=30)
    assert "page=login" not in dash.url, f"login failed: {dash.url}"
    return s


def _tbody_row_count(html: str) -> int:
    """Count <tr> rows inside the parts-table <tbody>."""
    m = re.search(
        r'<table[^>]*data-testid="parts-table"[^>]*>.*?<tbody>(.*?)</tbody>',
        html, re.S)
    assert m, "parts-table tbody not found"
    body = m.group(1)
    # Exclude the "belum ada data" placeholder row (colspan=7)
    if 'colspan="7"' in body:
        return 0
    return len(re.findall(r"<tr\b", body))


def _info_text(html: str) -> str:
    m = re.search(
        r'data-testid="parts-pagination-info"[^>]*>(.*?)</div>',
        html, re.S)
    assert m, "pagination-info block not found"
    text = re.sub(r"<[^>]+>", "", m.group(1))
    text = _html.unescape(text)
    return re.sub(r"\s+", " ", text).strip()


def _total_from_info(info: str) -> int:
    m = re.search(r"dari\s+(\d+)\s+sparepart", info)
    assert m, f"cannot parse total from info: {info!r}"
    return int(m.group(1))


# -------------------- tests --------------------
def test_parts_page_has_pagination_controls(session):
    r = session.get(f"{BASE_URL}/?page=parts", timeout=30)
    assert r.status_code == 200
    for tid in ("part-per-page", "parts-pagination",
                "parts-pagination-info", "parts-pagination-nav",
                "parts-page-prev", "parts-page-next", "parts-table"):
        assert f'data-testid="{tid}"' in r.text, f"missing testid {tid}"


def test_default_per_page_is_50(session):
    r = session.get(f"{BASE_URL}/?page=parts", timeout=30)
    html = r.text
    # default selected option value=50
    assert re.search(
        r'<option value="50"[^>]*selected[^>]*>', html
    ), "default per_page option 50 not selected"

    info = _info_text(html)
    total = _total_from_info(info)
    assert total >= 50, f"DB should have >=50 parts, got {total}"

    expected = f"Menampilkan 1{NDASH}50 dari {total} sparepart"
    assert expected in info, f"info mismatch: {info!r}"

    assert _tbody_row_count(html) == 50


def test_page_2_shows_rows_51_100(session):
    r = session.get(f"{BASE_URL}/?page=parts&p=2&per_page=50", timeout=30)
    html = r.text
    info = _info_text(html)
    total = _total_from_info(info)
    to_no = min(100, total)
    expected = f"Menampilkan 51{NDASH}{to_no} dari {total} sparepart"
    assert expected in info, f"info mismatch: {info!r}"
    assert _tbody_row_count(html) == min(50, total - 50)


def test_page1_rows_differ_from_page2(session):
    p1 = session.get(f"{BASE_URL}/?page=parts&p=1&per_page=50", timeout=30).text
    p2 = session.get(f"{BASE_URL}/?page=parts&p=2&per_page=50", timeout=30).text

    def kodes(html):
        m = re.search(
            r'<table[^>]*data-testid="parts-table".*?<tbody>(.*?)</tbody>',
            html, re.S)
        return re.findall(r"<tr[^>]*>\s*<td>([^<]+)</td>", m.group(1))
    k1, k2 = set(kodes(p1)), set(kodes(p2))
    assert k1 and k2
    assert k1.isdisjoint(k2), "page1 and page2 share rows"


def test_per_page_100(session):
    r = session.get(f"{BASE_URL}/?page=parts&per_page=100", timeout=30)
    html = r.text
    info = _info_text(html)
    total = _total_from_info(info)
    expected = f"Menampilkan 1{NDASH}{min(100, total)} dari {total} sparepart"
    assert expected in info, f"info: {info!r}"
    assert _tbody_row_count(html) == min(100, total)


def test_invalid_per_page_falls_back_to_50(session):
    r = session.get(f"{BASE_URL}/?page=parts&per_page=999", timeout=30)
    html = r.text
    assert re.search(r'<option value="50"[^>]*selected', html), \
        "invalid per_page did not fall back to default 50"
    assert _tbody_row_count(html) == 50


def test_invalid_page_clamped_to_last(session):
    # Get total first
    r0 = session.get(f"{BASE_URL}/?page=parts&per_page=50", timeout=30).text
    total = _total_from_info(_info_text(r0))
    last = (total + 49) // 50

    r = session.get(f"{BASE_URL}/?page=parts&p=9999&per_page=50", timeout=30)
    html = r.text
    info = _info_text(html)
    from_no = (last - 1) * 50 + 1
    to_no = total
    assert f"Menampilkan {from_no}{NDASH}{to_no}" in info, \
        f"invalid p not clamped, info: {info!r}"
    # last page next should be disabled
    m = re.search(r'data-testid="parts-page-next"[^>]*>', html)
    assert m
    # Find enclosing <li> class
    m2 = re.search(
        r'<li class="page-item ([^"]*)">\s*<a[^>]*data-testid="parts-page-next"',
        html)
    assert m2 and "disabled" in m2.group(1), "next not disabled on last page"


def test_page1_prev_disabled(session):
    r = session.get(f"{BASE_URL}/?page=parts&per_page=50", timeout=30).text
    m = re.search(
        r'<li class="page-item ([^"]*)">\s*<a[^>]*data-testid="parts-page-prev"',
        r)
    assert m and "disabled" in m.group(1), "prev should be disabled on page 1"


def test_search_q_with_pagination(session):
    r = session.get(f"{BASE_URL}/?page=parts&q=OLI&per_page=25", timeout=30)
    html = r.text
    info = _info_text(html)
    assert 'hasil filter pencarian "OLI"' in info, f"info: {info!r}"
    total = _total_from_info(info)
    assert total > 0
    # rows must be <=25 and <= total
    assert _tbody_row_count(html) == min(25, total)
    # pagination links preserve q
    if total > 25:
        assert re.search(r'href="[^"]*q=OLI[^"]*"[^>]*data-testid="parts-page-2"',
                         html), "page-2 link doesn't preserve q"


def test_filter_low_with_pagination(session):
    r = session.get(f"{BASE_URL}/?page=parts&filter=low&per_page=25", timeout=30)
    html = r.text
    info = _info_text(html)
    assert "hasil filter" in info and "stok menipis" in info, f"info: {info!r}"
