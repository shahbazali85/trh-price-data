#!/usr/bin/env python3
"""
trh_sync.py — push prices / events from a CSV (or a published Google Sheet) to WordPress.

Usage:
  export WP_URL=https://texasroadhouseusamenu.com
  export WP_USER=your-admin-username
  export WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx"   # Users → Profile → Application Passwords

  python trh_sync.py items  data/items.csv
  python trh_sync.py items  "https://docs.google.com/spreadsheets/d/e/.../pub?gid=0&single=true&output=csv"
  python trh_sync.py events data/events.csv
  python trh_sync.py meta   --stores "Louisville KY, Dallas TX, Phoenix AZ" --verified 2026-10-20
  python trh_sync.py check  data/items.csv        # validate only, nothing is sent

Safety: the plugin holds back any price that moves more than the configured % (default 30)
unless that row has force=1. The script also pre-checks the file and stops on obvious errors.
"""
import argparse
import csv
import io
import os
import sys

import requests


def load_csv(src: str) -> str:
    if src.startswith("http"):
        r = requests.get(src, timeout=30)
        r.raise_for_status()
        if "<html" in r.text[:500].lower():
            sys.exit("That link returned a web page, not CSV. Use File → Share → Publish to web → CSV.")
        return r.text
    with open(src, encoding="utf-8-sig") as f:
        return f.read()


def precheck_items(text: str) -> list:
    problems, seen = [], set()
    rows = list(csv.DictReader(io.StringIO(text)))
    for i, r in enumerate(rows, start=2):
        rid = (r.get("id") or "").strip()
        if not rid or not (r.get("name") or "").strip() or not (r.get("category") or "").strip():
            problems.append(f"row {i}: id/name/category missing")
        if rid in seen:
            problems.append(f"row {i}: duplicate id {rid}")
        seen.add(rid)
        try:
            p = float((r.get("price") or "").replace("$", "").replace(",", ""))
            if not 0 < p < 1000:
                problems.append(f"row {i} ({rid}): price {p} out of range")
        except ValueError:
            problems.append(f"row {i} ({rid}): price '{r.get('price')}' is not a number")
        v = (r.get("verified_on") or "").strip()
        if v and len(v) != 10:
            problems.append(f"row {i} ({rid}): verified_on must be YYYY-MM-DD")
    return problems


def wp_session():
    url, user, pw = os.getenv("WP_URL"), os.getenv("WP_USER"), os.getenv("WP_APP_PASSWORD")
    if not (url and user and pw):
        sys.exit("Set WP_URL, WP_USER and WP_APP_PASSWORD first (see the top of this file).")
    s = requests.Session()
    s.auth = (user, pw)
    s.headers["User-Agent"] = "TRH-Price-Sync/1.0"
    return s, url.rstrip("/") + "/wp-json/trhpe/v1"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("what", choices=["items", "events", "meta", "check"])
    ap.add_argument("src", nargs="?")
    ap.add_argument("--stores")
    ap.add_argument("--verified")
    a = ap.parse_args()

    if a.what in ("items", "check"):
        text = load_csv(a.src)
        problems = precheck_items(text)
        if problems:
            print("Fix these first:\n  " + "\n  ".join(problems))
            sys.exit(1)
        print(f"CSV OK — {text.count(chr(10))} lines.")
        if a.what == "check":
            return
        s, api = wp_session()
        r = s.post(f"{api}/items", json={"csv": text}, timeout=60)
    elif a.what == "events":
        s, api = wp_session()
        r = s.post(f"{api}/events", json={"csv": load_csv(a.src)}, timeout=60)
    else:
        s, api = wp_session()
        body = {}
        if a.stores:
            body["stores_checked"] = a.stores
        if a.verified:
            body["last_verified"] = a.verified
        r = s.post(f"{api}/meta", json=body, timeout=60)

    if r.status_code in (401, 403):
        sys.exit(f"WordPress refused the login ({r.status_code}). Check the app password, and that Cloudflare/security plugins allow /wp-json/.")
    r.raise_for_status()
    rep = r.json()
    if isinstance(rep, dict) and "added" in rep:
        print(f"Added {rep['added']} · changed {rep['changed']} · unchanged {rep['unchanged']}")
        for k, label in (("changes", "Price changes"), ("held", "HELD BACK"), ("errors", "Errors")):
            if rep.get(k):
                print(f"\n{label}:\n  " + "\n  ".join(rep[k]))
    else:
        print(rep)


if __name__ == "__main__":
    main()
