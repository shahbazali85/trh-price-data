#!/usr/bin/env python3
"""Build the public data feed (items.csv, events.csv, meta.json, pages.json, pages/*.html) into a repo folder.
   python tools/build_feed.py /home/claude/trh-price-data --verified 2026-10-17
"""
import argparse, csv, json, pathlib, shutil, sys

ap = argparse.ArgumentParser()
ap.add_argument("out"); ap.add_argument("--verified", required=True)
ap.add_argument("--stores", default="Cross-checked against several independent price trackers, including one that samples online-ordering menus at 614 restaurants in 47 states")
a = ap.parse_args()
root = pathlib.Path(__file__).resolve().parent.parent
out = pathlib.Path(a.out); (out / "pages").mkdir(parents=True, exist_ok=True)

items = list(csv.DictReader(open(root / "data/items.csv", encoding="utf-8")))
def rng(cat):
    ps = [float(i["price"]) for i in items if i["category"] == cat] + [float(i["price_max"]) for i in items if i["category"] == cat and i["price_max"]]
    return f"${min(ps):.2f}–${max(ps):.2f}"

shutil.copy(root / "data/items.csv", out / "items.csv")
shutil.copy(root / "data/events.csv", out / "events.csv")
json.dump({"last_verified": a.verified, "stores_checked": a.stores}, open(out / "meta.json", "w"), indent=2)

pages = []
for f in sorted((root / "pages").glob("*.html")):
    raw = f.read_text(encoding="utf-8")
    _, head, body = raw.split("---", 2)
    meta = {}
    for line in head.strip().splitlines():
        k, v = line.split(":", 1); meta[k.strip()] = v.strip()
    import datetime as _dt
    _v = _dt.date.fromisoformat(a.verified)
    for k in list(meta):
        meta[k] = meta[k].replace("%%trh_month_year%%", _v.strftime("%B %Y")).replace("%%trh_year%%", "%%currentyear%%")
        if "[range]" in meta[k]:
            meta[k] = meta[k].replace("[range]", rng("family-meals"))
    meta["takeover"] = meta.get("takeover") == "true"
    meta["file"] = f"pages/{meta['slug']}.html"
    (out / meta["file"]).write_text(body.strip() + "\n", encoding="utf-8")
    pages.append(meta)
json.dump(pages, open(out / "pages.json", "w"), indent=2, ensure_ascii=False)
print(f"{len(items)} items, {len(pages)} pages → {out}")
