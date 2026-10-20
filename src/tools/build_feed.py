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


import re as _re0
def _re_meta(text, items, v):
    """Fill [price:id] and [verified] placeholders in titles/meta at build time."""
    def price(m):
        for i in items:
            if i["id"] == m.group(1):
                return "$" + i["price"]
        sys.exit(f"unknown item id in meta: {m.group(1)}")
    text = _re0.sub(r"\[price:([\w-]+)\]", price, text)
    return text.replace("[verified]", v.strftime("%b %-d, %Y"))

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
        meta[k] = _re_meta(meta[k], items, _v)
        if "[range]" in meta[k]:
            meta[k] = meta[k].replace("[range]", rng("family-meals"))
    meta["takeover"] = meta.get("takeover") == "true"
    meta["file"] = f"pages/{meta['slug']}.html"
    import re as _re
    body = _re.sub(r"<table>.*?</table>", lambda m: _re.sub(r">\s+<", "><", m.group(0)), body, flags=_re.S)
    (out / meta["file"]).write_text(body.strip() + "\n", encoding="utf-8")
    pages.append(meta)
_bad = [(p["slug"], len(p.get("meta", ""))) for p in pages if not 70 <= len(p.get("meta", "")) <= 158]
_bad += [(r["slug"], len(r["meta"])) for r in json.load(open(root / "sections.json")) if "meta" in r and not 70 <= len(r["meta"]) <= 158] if (root / "sections.json").exists() else []
if _bad:
    sys.exit(f"Meta description must be 70–158 characters (Bing/Google limit): {_bad}")
json.dump(pages, open(out / "pages.json", "w"), indent=2, ensure_ascii=False)
for extra in ("redirects.json", "sections.json"):
    if (root / extra).exists():
        shutil.copy(root / extra, out / extra)
if (root / "sections").is_dir():
    (out / "sections").mkdir(exist_ok=True)
    for f in (root / "sections").glob("*.html"):
        shutil.copy(f, out / "sections" / f.name)
print(f"{len(items)} items, {len(pages)} pages → {out}")
