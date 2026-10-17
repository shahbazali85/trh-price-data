#!/usr/bin/env python3
"""
trh_audit.py — crawl the site and flag outdated / contradictory content automatically.

  python trh_audit.py https://texasroadhouseusamenu.com            # crawls the Yoast sitemap
  python trh_audit.py https://texasroadhouseusamenu.com --out audit.csv

Checks on every page:
  1. Wrong weekday-date pairs   ("Monday, September 1, 2026" when Sept 1 2026 is a Tuesday)
  2. Old years in title / meta description / H1 / og tags / URL slug
  3. Stale month labels           ("August 2026" on a page when it's now October)
  4. Missing visible "updated" date
  5. Same menu item, different price on different pages  (cross-page conflict)
  6. Prices that don't match the TRH Price Engine data   (if the plugin is installed)
  7. Hard-coded prices in text (should be shortcodes so they update themselves)

Run it monthly (or in GitHub Actions) — the output is a CSV you can sort in Google Sheets.
"""
import argparse
import csv
import datetime as dt
import re
import sys
from collections import defaultdict
from urllib.parse import urlparse

import requests
from bs4 import BeautifulSoup

UA = {"User-Agent": "Mozilla/5.0 (compatible; TRH-Content-Audit/1.0)"}
MONTHS = "January|February|March|April|May|June|July|August|September|October|November|December"
WEEKDAY_DATE = re.compile(
    rf"\b(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday|Mon|Tue|Wed|Thu|Fri|Sat|Sun)\.?,?\s+"
    rf"({MONTHS}|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)\.?\s+(\d{{1,2}}),?\s+(20\d\d)"
)
MONTH_YEAR = re.compile(rf"\b({MONTHS})\s+(20\d\d)\b")
PRICE = re.compile(r"\$\s?(\d{1,3}(?:\.\d{2})?)")


def fetch(url):
    r = requests.get(url, headers=UA, timeout=30)
    r.raise_for_status()
    return r.text


def sitemap_urls(base):
    urls, todo = [], [base.rstrip("/") + "/sitemap_index.xml"]
    while todo:
        sm = todo.pop()
        try:
            soup = BeautifulSoup(fetch(sm), "xml")
        except Exception as e:  # noqa
            print("sitemap failed:", sm, e, file=sys.stderr)
            continue
        for loc in soup.find_all("loc"):
            u = loc.text.strip()
            if u.endswith(".xml"):
                if "author" not in u and "category" not in u:
                    todo.append(u)
            else:
                urls.append(u)
    return urls


def norm_name(s):
    s = s.lower().replace("®", "").replace("’", "'")
    s = re.sub(r"\(.*?\)", "", s)
    return re.sub(r"[^a-z0-9]+", " ", s).strip()


def audit_page(url, html, today, engine_prices):
    soup = BeautifulSoup(html, "html.parser")
    issues = []
    title = (soup.title.string or "").strip() if soup.title else ""
    desc = (soup.find("meta", attrs={"name": "description"}) or {}).get("content", "")
    ogd = (soup.find("meta", attrs={"property": "og:description"}) or {}).get("content", "")
    h1 = soup.find("h1").get_text(" ", strip=True) if soup.find("h1") else ""
    for s in soup(["script", "style", "noscript"]):
        s.decompose()
    body = soup.get_text(" ", strip=True)
    year = today.year

    # 1 weekday/date mismatch
    for m in WEEKDAY_DATE.finditer(body):
        wd, mon, day, yr = m.groups()
        try:
            d = dt.datetime.strptime(f"{mon[:3]} {day} {yr}", "%b %d %Y").date()
            if d.strftime("%a").lower() != wd[:3].lower():
                issues.append(("HIGH", "wrong weekday", f"'{m.group(0)}' — {d:%B %-d, %Y} is a {d:%A}"))
        except ValueError:
            pass

    # 2 old years in key places
    for label, text in (("title", title), ("meta description", desc), ("og:description", ogd), ("H1", h1), ("URL", urlparse(url).path)):
        for y in set(re.findall(r"\b(20\d\d)\b", text)):
            if int(y) < year:
                issues.append(("HIGH" if label != "URL" else "MEDIUM", f"old year in {label}", f"{y}: {text[:120]}"))

    # 3 stale month labels in title/H1/body headline areas
    for label, text in (("title", title), ("H1", h1), ("body", body[:4000])):
        for mon, yr in set(MONTH_YEAR.findall(text)):
            d = dt.datetime.strptime(f"{mon} {yr}", "%B %Y").date()
            months_old = (today.year - d.year) * 12 + today.month - d.month
            if months_old >= 2 or (label != "body" and months_old >= 1):
                issues.append(("MEDIUM", f"stale month in {label}", f"{mon} {yr}"))

    # 4 visible updated date
    if not re.search(r"(last )?updated|reviewed|verified", body, re.I):
        issues.append(("LOW", "no visible updated date", ""))

    # 5/6/7 item prices — look at table rows and "Item – $x.xx" patterns
    found = {}
    for tr in soup.find_all("tr"):
        cells = [c.get_text(" ", strip=True) for c in tr.find_all(["td", "th"])]
        if len(cells) >= 2:
            m = PRICE.search(" ".join(cells[1:]))
            if m and cells[0]:
                found[norm_name(cells[0])] = float(m.group(1))
    hard_coded = len(found)
    if "trh-table" in html:
        hard_coded = 0
    if hard_coded >= 5:
        issues.append(("LOW", "hard-coded price table", f"{hard_coded} prices typed by hand — replace with [trh_table]"))
    for name, price in found.items():
        if name in engine_prices and abs(engine_prices[name] - price) > 0.009:
            issues.append(("HIGH", "price differs from master data", f"{name}: page ${price:.2f} vs master ${engine_prices[name]:.2f}"))
    return {"url": url, "title": title, "issues": issues, "prices": found}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("site")
    ap.add_argument("--out", default="trh_audit.csv")
    ap.add_argument("--urls", nargs="*", help="audit only these URLs")
    a = ap.parse_args()
    today = dt.date.today()

    engine = {}
    try:
        data = requests.get(a.site.rstrip("/") + "/wp-json/trhpe/v1/items", headers=UA, timeout=30).json()
        engine = {norm_name(i["name"]): float(i["price"]) for i in data.get("items", [])}
        print(f"Loaded {len(engine)} master prices from the plugin.")
    except Exception:
        print("Plugin data not reachable — skipping master-price check.")

    urls = a.urls or sitemap_urls(a.site)
    print(f"Auditing {len(urls)} URLs…")
    results = []
    for u in urls:
        try:
            results.append(audit_page(u, fetch(u), today, engine))
        except Exception as e:  # noqa
            results.append({"url": u, "title": "", "issues": [("HIGH", "fetch failed", str(e))], "prices": {}})

    # 5 cross-page conflicts
    by_item = defaultdict(dict)
    for r in results:
        for name, p in r["prices"].items():
            by_item[name][r["url"]] = p
    conflicts = []
    for name, pages in by_item.items():
        if len(set(pages.values())) > 1:
            detail = " | ".join(f"${p:.2f} on {urlparse(u).path}" for u, p in pages.items())
            conflicts.append(("HIGH", "cross-page price conflict", f"{name}: {detail}"))

    with open(a.out, "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["severity", "url", "issue", "detail"])
        for r in results:
            for sev, kind, detail in r["issues"]:
                w.writerow([sev, r["url"], kind, detail])
        for sev, kind, detail in conflicts:
            w.writerow([sev, "(site-wide)", kind, detail])

    total = sum(len(r["issues"]) for r in results) + len(conflicts)
    high = sum(1 for r in results for i in r["issues"] if i[0] == "HIGH") + len(conflicts)
    print(f"Done: {total} issues ({high} high) → {a.out}")


if __name__ == "__main__":
    main()
