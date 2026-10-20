# trh-price-data — how to update this feed

This repo is the data feed for **texasroadhouseusamenu.com** (owner: Shahbaz Ali). The site's WordPress plugin *TRH Price Engine* pulls `items.csv`, `events.csv`, `meta.json`, `pages.json` and `pages/*.html` from the repo root every hour. Whatever is pushed to `main` goes live on the site within ~1 hour. The owner does not review each push, so accuracy is on you.

## Layout
- `src/data/items.csv` — master prices. Columns: id,name,category,price,price_max,calories,serves,note,status,verified_on,sources,force
- `src/data/events.csv` — holidays/events. `rule` computes the date (fixed:MM-DD, nth:N:dow:M, last:dow:M, easter, +N offsets). `confirmed` = yes only when the official terms for THIS year are published.
- `src/pages/*.html` — page sources with a `---` header (type, slug, title, seo_title, meta, focus, category, status, takeover). Body = Gutenberg block HTML that uses the plugin shortcodes ([trh_price], [trh_range], [trh_table], [trh_holiday], [trh_event], [trh_updated], [trh_holiday_table], [trh_countdown]).
- `src/tools/build_feed.py` — builds the root feed: `python3 src/tools/build_feed.py . --verified YYYY-MM-DD`
- `src/trh-price-engine.php` — plugin source; copy to `plugin/trh-price-engine/` and re-zip when changed.
- Never edit root `items.csv`, `events.csv`, `pages/` by hand — edit `src/`, then build.
- `src/redirects.json` — 301 redirects (from → to). The plugin only redirects once the target is published, and drops the old URL from the Yoast sitemap.
- `src/sections.json` + `src/sections/*.html` — replace ONE H2 section (matched by heading text, up to the next H2) on an existing page. Use `requires` = slug that must be live first. Only for small, targeted fixes.

## Price rules
- Sources legend is in README.md (A–E). Prefer sources that state a method (online-ordering menus, many stores). Ignore delivery-app prices (inflated).
- `price` = typical U.S. price. `crosschecked` + `verified_on` = today only when 2+ independent sources agree within ~$1. Otherwise `estimate`, and when sources disagree put the low in `price` and the high in `price_max`.
- Keep `id` values stable — pages reference them. New items get new ids; removed items get `status = discontinued` (don't delete rows).
- The plugin holds back moves >30% unless `force=1`. Only set force after confirming the change in 2 sources.
- Only numbers come from other sites. Never copy their wording.

## Content rules
- Meta descriptions must be 70–158 characters (the build refuses longer ones; Bing flags >160).
- Few, useful pages (2–4 a month max). Each answers a real search question, starts with a 40–60 word direct answer, links official/news sources, and says it's an independent guide.
- Never claim an offer is confirmed for this year without an official or news source dated this year. Use [trh_event] — it shows "not yet confirmed" until `confirmed = yes`.
- Evergreen slugs without years. In seo_title/meta use Yoast's native %%currentyear%% for the year (the editor preview understands it). %%trh_month_year%% is replaced at build time with the verified month. Never leave %%trh_...%% in pages.json.
- `takeover: true` lets a page overwrite an existing page the owner wrote (only works if the owner enabled it). Use only for full, better rewrites.

## Page design (keep every page consistent)
- Add `google-anno-skip` to the answer, sources and hand-made table classes (as below) so AdSense "Ad intents" links/chips stay out of key content.
- Start the body with `[trh_badge]` in a wp:shortcode block, then the quick answer as `<!-- wp:paragraph {"className":"trh-answer google-anno-skip"} --><p class="trh-answer google-anno-skip"><strong>Quick answer:</strong> …</p>`.
- Prices only via `[trh_table]` / `[trh_price]` / `[trh_range]`; holidays via `[trh_holiday_table]`; offers via `[trh_event]`.
- Hand-made tables: `<!-- wp:table {"className":"trh-core google-anno-skip"} -->` + `<figure class="wp-block-table trh-core google-anno-skip">` (styled + protected from widget injection).
- End with the sources paragraph: `<!-- wp:paragraph {"className":"trh-sources google-anno-skip"} --><p class="trh-sources google-anno-skip">Sources: … Independent guide — not affiliated with Texas Roadhouse.</p>`.

## Commit
Commit as Claude with the session attribution lines, push to `main`, then verify with WebFetch of the raw URL.
