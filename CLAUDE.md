# trh-price-data — how to update this feed

This repo is the data feed for **texasroadhouseusamenu.com** (owner: Shahbaz Ali). The site's WordPress plugin *TRH Price Engine* pulls `items.csv`, `events.csv`, `meta.json`, `pages.json` and `pages/*.html` from the repo root every hour. Whatever is pushed to `main` goes live on the site within ~1 hour. The owner does not review each push, so accuracy is on you.

## Layout
- `src/data/items.csv` — master prices. Columns: id,name,category,price,price_max,calories,serves,note,status,verified_on,sources,force
- `src/data/events.csv` — holidays/events. `rule` computes the date (fixed:MM-DD, nth:N:dow:M, last:dow:M, easter, +N offsets). `confirmed` = yes only when the official terms for THIS year are published.
- `src/pages/*.html` — page sources with a `---` header (type, slug, title, seo_title, meta, focus, category, status, takeover). Body = Gutenberg block HTML that uses the plugin shortcodes ([trh_price], [trh_range], [trh_table], [trh_holiday], [trh_event], [trh_updated], [trh_holiday_table], [trh_countdown]).
- `src/tools/build_feed.py` — builds the root feed: `python3 src/tools/build_feed.py . --verified YYYY-MM-DD`
- `src/trh-price-engine.php` — plugin source; copy to `plugin/trh-price-engine/` and re-zip when changed.
- Never edit root `items.csv`, `events.csv`, `pages/` by hand — edit `src/`, then build.

## Price rules
- Sources legend is in README.md (A–E). Prefer sources that state a method (online-ordering menus, many stores). Ignore delivery-app prices (inflated).
- `price` = typical U.S. price. `crosschecked` + `verified_on` = today only when 2+ independent sources agree within ~$1. Otherwise `estimate`, and when sources disagree put the low in `price` and the high in `price_max`.
- Keep `id` values stable — pages reference them. New items get new ids; removed items get `status = discontinued` (don't delete rows).
- The plugin holds back moves >30% unless `force=1`. Only set force after confirming the change in 2 sources.
- Only numbers come from other sites. Never copy their wording.

## Content rules
- Few, useful pages (2–4 a month max). Each answers a real search question, starts with a 40–60 word direct answer, links official/news sources, and says it's an independent guide.
- Never claim an offer is confirmed for this year without an official or news source dated this year. Use [trh_event] — it shows "not yet confirmed" until `confirmed = yes`.
- Evergreen slugs without years. Year goes in titles via %%trh_year%% / %%trh_month_year%%.
- `takeover: true` lets a page overwrite an existing page the owner wrote (only works if the owner enabled it). Use only for full, better rewrites.

## Commit
Commit as Claude with the session attribution lines, push to `main`, then verify with WebFetch of the raw URL.
