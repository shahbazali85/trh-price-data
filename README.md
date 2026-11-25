# TRH price data feed

Data feed for **texasroadhouseusamenu.com**. The site's *TRH Price Engine* plugin reads this folder every hour:

| File | What it is |
|---|---|
| `items.csv` | Typical U.S. Texas Roadhouse prices (125 items). `status = crosschecked` = 2+ independent sources agree within about $1; `estimate` = one source or sources disagree (a range is shown). |
| `events.csv` | Holidays and events. Dates are calculated from rules, so they stay correct every year. |
| `meta.json` | Date prices were last checked + how. |
| `pages.json` + `pages/` | Posts/pages the plugin creates or updates. |
| `plugin/trh-price-engine.zip` | The WordPress plugin. |

Feed URL to paste in WordPress → Tools → TRH Prices → **Feed folder URL**:

```
https://raw.githubusercontent.com/shahbazali85/trh-price-data/main/
```

### Price sources (the `sources` column)
- **A** — texas-roadhouse-menus.us: state medians from online-ordering menus at 614 restaurants in 47 states (Oct 14, 2026)
- **B** — thetexasroadhouse.com (Sep 19, 2026)
- **C** — menu-info.com (Oct 1, 2026)
- **D** — usafoodjournal.com (Oct 15, 2026)
- **E** — a delivery-app menu (item exists; delivery prices not used)
- **F** — The Takeout, "Best deals at Texas Roadhouse" (Apr 20, 2026)
- **G** — Chowhound, Wild West Wednesday article (Aug 17, 2025)
- **H** — Tasting Table, Early Dine prices article (Mar 13, 2026)
- **O** — official texasroadhouse.com online-ordering menus, read directly for 7 restaurants (Madison Heights MI, West Dallas TX, Orlando FL, Grove City OH, Elk Grove CA, Phoenix AZ, Albany NY) on Nov 25, 2026. `price` = median of the 7; the note gives the low–high range. Preferred source from now on.

Only numbers are used from these sources; all page text is original.
