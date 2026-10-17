#!/usr/bin/env python3
"""
trh_publish.py — create or update WordPress pages/posts from HTML files. No copy-paste.

Each file in pages/ starts with a small header, then the HTML (Gutenberg-friendly) body:

    ---
    type: post                # post | page
    slug: texas-roadhouse-veterans-day
    title: Texas Roadhouse Veterans Day 2026: Free Meal Voucher Details
    seo_title: Texas Roadhouse Veterans Day %%trh_year%%: Free Meal Voucher Rules
    meta: Will Texas Roadhouse give veterans a free meal on Nov 11, 2026? ...
    focus: texas roadhouse veterans day
    category: Chain Update    # posts only (must already exist)
    status: draft             # draft | publish | future
    date: 2026-10-20T09:00:00 # only needed with status: future
    ---
    <p>Body…</p>

Usage:
  export WP_URL=... WP_USER=... WP_APP_PASSWORD=...
  python trh_publish.py pages/veterans-day.html            # one file
  python trh_publish.py pages/                               # every .html file in the folder
  python trh_publish.py pages/ --dry-run                     # show what would happen

If a post/page with the same slug exists it is UPDATED (content, title, SEO fields);
otherwise it is created. Default status is draft so you can review before it goes live.
"""
import argparse
import os
import pathlib
import sys

import requests


def parse(path):
    raw = pathlib.Path(path).read_text(encoding="utf-8")
    if not raw.startswith("---"):
        sys.exit(f"{path}: missing --- header")
    _, head, body = raw.split("---", 2)
    meta = {}
    for line in head.strip().splitlines():
        if ":" in line:
            k, v = line.split(":", 1)
            meta[k.strip()] = v.split("  #")[0].strip()
    for req in ("slug", "title"):
        if req not in meta:
            sys.exit(f"{path}: header needs '{req}'")
    meta.setdefault("type", "post")
    meta.setdefault("status", "draft")
    return meta, body.strip()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("path")
    ap.add_argument("--dry-run", action="store_true")
    a = ap.parse_args()

    url, user, pw = os.getenv("WP_URL"), os.getenv("WP_USER"), os.getenv("WP_APP_PASSWORD")
    if not a.dry_run and not (url and user and pw):
        sys.exit("Set WP_URL, WP_USER and WP_APP_PASSWORD first.")
    api = (url or "").rstrip("/") + "/wp-json/wp/v2"
    s = requests.Session()
    s.auth = (user, pw)

    p = pathlib.Path(a.path)
    files = sorted(p.glob("*.html")) if p.is_dir() else [p]
    for f in files:
        meta, body = parse(f)
        endpoint = f"{api}/{'pages' if meta['type'] == 'page' else 'posts'}"
        payload = {
            "title": meta["title"],
            "slug": meta["slug"],
            "content": body,
            "status": meta["status"],
            "meta": {k2: meta[k1] for k1, k2 in (("seo_title", "_yoast_wpseo_title"), ("meta", "_yoast_wpseo_metadesc"), ("focus", "_yoast_wpseo_focuskw")) if k1 in meta},
        }
        if meta.get("date"):
            payload["date"] = meta["date"]
        if a.dry_run:
            print(f"[dry-run] {meta['type']} /{meta['slug']}/  status={meta['status']}  words≈{len(body.split())}")
            continue
        if meta["type"] == "post" and meta.get("category"):
            cats = s.get(f"{api}/categories", params={"search": meta["category"]}, timeout=30).json()
            if cats:
                payload["categories"] = [cats[0]["id"]]
        existing = s.get(endpoint, params={"slug": meta["slug"], "status": "any", "context": "edit"}, timeout=30)
        existing.raise_for_status()
        found = existing.json()
        if found:
            r = s.post(f"{endpoint}/{found[0]['id']}", json=payload, timeout=60)
            action = "updated"
        else:
            r = s.post(endpoint, json=payload, timeout=60)
            action = "created"
        if r.status_code >= 400:
            print(f"✖ {f.name}: {r.status_code} {r.text[:300]}")
            continue
        print(f"✔ {action}: {r.json().get('link')}  ({meta['status']})")


if __name__ == "__main__":
    main()
