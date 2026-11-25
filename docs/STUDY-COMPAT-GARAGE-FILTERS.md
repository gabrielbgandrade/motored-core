# Study — Compatibility + Mi Garage + CatalogFilters (performance, SEO, correctness, flow)

**Status: STUDY ONLY. No code was changed for this document.**
Date: 2026-11-24 · Scope: `src/Modules/Compatibility`, `src/Modules/MiGarage`, `src/Modules/CatalogFilters`,
`src/Integration/WooCommerce/{AttributeHooks,CompatibilityHooks}`, `assets/{js,css}/compat*`,
`assets/js/catalog-*`, `src/Support/StorefrontAssets.php`.

Method: code reading (PHP + JS + SQL), cross-check with `docs/PROJECT.md`, `docs/VERIFY.md` and the module
READMEs; external reference = RevZilla garage / model pages (see §7). No WordPress/browser run in this session,
so every runtime statement below is derived from code and marked as such.

Severity: **P0** blocks scale or trust · **P1** visible defect / wasted budget · **P2** polish.

---

## 1. Architecture in one page

| Layer | What exists | Where |
|---|---|---|
| Static catalog | 7 tables (`brands`, `models`, `versions`, `sizes`, `fitments`, `equivalences`, `product_index`) | `CompatibilityMigration` |
| Matching | pure PHP `MatchEngine` + client mirror `ev()` in `compat-garage.js` | parity-tested |
| Storefront | `?moto=` filter on the main query, hidden `data-motored-fit` markers, client-side badges | `Storefront` |
| Garage | guest `localStorage` (`guest_v2`) + account `user_meta` via REST `/garage` | `MiGarage` |
| Facets | full-catalog snapshot in Redis, disjunctive counts in PHP, SSR sidebar | `FacetEngine` |
| Cache | `CompatCache` → `Support\Cache` **only if** `Cache::is_persistent()` | `CompatCache` |

The core design choice — **page-cache-safe HTML + client-side fit evaluation** — is correct and worth keeping.
Almost every finding below is about *when* assets/options are read and about the last mile of the funnel, not
about the model.

---

## 2. Performance

### P0-1 — Without a persistent object cache, every feature is uncached (by design) and the facet snapshot is a full-catalog scan per request

`CompatCache::remember()` returns `$producer()` when `Cache::is_persistent()` is false
(`src/Modules/Compatibility/CompatCache.php:35-40`). Same for `SnapshotInvalidator` (cooldown/lock/version).

Consequence on a shop page that shows the filter sidebar with Redis down/cleared:
`FilterRenderer::render()` → `FacetEngine::snapshot()` → `build_snapshot_from_db()`
(`FacetEngine.php:92-144`) = **one query that returns every product × every term row of the catalog** into PHP
(`posts` ⋈ `postmeta` ⋈ `term_relationships` ⋈ `term_taxonomy` ⋈ `terms`, ~15 rows per product).

- 500 products ≈ 7k rows: fine.
- 5k products ≈ 75k rows: 30–60 MB of PHP arrays per request. That is the real scaling wall of the module.
- The same page also pays `MatchEngine::bike_profile()` (3 queries) + `query_candidates_for_sizes()` (1 UNION)
  + `find_many()` (1) when `?moto=` is present.

**Fix options (cheap → structured):**
1. Keep the Redis path, but make the *no-Redis* path not the common one: assert in Status that Redis is required
   when CatalogFilters/Compatibility are enabled (already the spirit of rule 3: cache is not the source of truth,
   but a facet snapshot is a derived artifact — it may legitimately live in its own table).
2. Better: **materialize the snapshot** in one table (`motored_cf_snapshot`: `product_id`, `in_stock`,
   `is_kit`, `cats`, `brands`, `attrs` as compact JSON) rebuilt by Action Scheduler on invalidation
   (batch 200, cursor) instead of at request time. The read becomes one `SELECT` of N rows (still N, but ~120 B
   each instead of a 5-table join) or a single pre-built JSON blob in Redis.
3. Minimum viable: cap the snapshot to the products the facet UI can actually show and memois e it per request
   everywhere (today `FilterRenderer` bypasses `snapshot()` on search — see P1-1).

### P1-1 — Search pages re-scan the catalog on every request, even with Redis

`FilterRenderer.php:75`: `is_search() ? FacetEngine::build_snapshot_from_db( true ) : FacetEngine::snapshot()`.
The search variant is never cached (no key) and never memoized. Every product-search page view = one full scan.
**Fix:** cache it under `facet_snapshot_search_v1` in the same bucket, same invalidation, and memoize.

### P1-2 — `compat.css` is enqueued from inside the body → printed in the footer (FOUC/CLS)

`StorefrontAssets::enqueue_compat_css()` is called from `loop_marker_html()` (`Storefront.php:506`),
`single_banner_html()` (`:541`), `active_bike_bar_html()` (`:416`, `:422`) — i.e. from
`woocommerce_after_shop_loop_item_title@12`, `woocommerce_before_shop_loop@15` and
`woocommerce_single_product_summary@12` (`CompatibilityHooks.php:40-44`). By then `wp_head` is done, so
WordPress prints the stylesheet via `print_late_styles()` in the footer.

Observed effect (code-derived): the active-bike bar, the product banner and the loop badges first paint
**unstyled**, then jump when the footer CSS arrives — a guaranteed layout shift on the two most important
templates, and every page pays for a render-blocking-ish request after HTML.
**Fix:** decide in `wp_enqueue_scripts` (it runs before `wp_head`):
- shop / product taxonomy / product search → enqueue (badges are likely);
- `is_product()` → enqueue only when `ProductIndexRepository::find( $id )` is not null (already cached, 0–1 queries);
- `is_account_page()` → only on the `mi-garage` endpoint.
Keep the current calls as a safety net (`wp_style_is()` already guards duplication).

### P1-3 — `compat-garage.js` (18 KB, ~4.5 KB gz) ships on every product page, even for non-tire products

`Storefront::maybe_enqueue_catalog_assets()` (`:123-134`) enqueues `GarageAssets` for every `is_product()`,
`is_shop()`, `is_product_taxonomy()`, `is_account_page()`. On a product that has no index row there is no marker,
so the script boots, finds nothing and does not draw. Same for any account page that is not `mi-garage`.
**Fix:** gate by context (same check as P1-2). Expected saving on non-tire pages: 18 KB JS + ~1.6 KB inline
config + one script request.

### P2-1 — Inline config payload per page

`GarageAssets::client_config()` (`:60-125`) ships ~40 translated strings on every page that loads the script,
including strings only used on the garage page (`emptyGarage`, `emptyGarageHint`, `undo`, `sessionExpired`,
`saveOk`…). Options: (a) split "core" (badges/banner) vs "garage page"; (b) fetch the page strings from
`GET /compat/bike/...`-style endpoint. ~1–2 KB gzip, low priority but it is pure waste on product pages.

### P2-2 — Hidden markers add weight to every loop card

Each card prints `<span hidden class="motored-fit-marker" data-motored-fit="{...}">` (~130–190 B). At 24 cards
that is ~4 KB of HTML. Alternative: one `<script type="application/json" data-motored-fits>` map per page, and
`compat-garage.js` reads the product id from the closest card. Keeps page-cache safety; halves the bytes and
removes N nodes from the DOM. Low priority.

### P2-3 — `hydrate_garage_profiles()` is O(bikes) × profile

`GarageRestController.php:179-195` calls `MatchEngine::bike_profile()` per saved bike (up to 5). With Redis: 5
cache reads. Without: ~15 queries per `GET /garage` (called on garage page load and after every merge).
**Fix:** `bike_profile_many( array $slugs )` = one query for versions + one for fitments + one for equivalences,
then build the profiles in PHP. Worth doing before any "carrossel/compare" feature.

### P2-4 — `keys`/`bump` cost of the compatibility cache

`CompatCache::bump_catalog()` on every motorcycle/fitment/equivalence edit throws away both buckets (all bikes,
all products). With 366 versions this is a cold-start storm after each admin edit + Redis flush after the
import. Acceptable today; if it hurts, use per-entity keys (`bike2_{slug}`, `pm3_{spec}` are already granular)
and bump only on import completion.

### Verified as *fine* (do not "optimize")

- `CompatibilityModule::register()` / `CatalogFiltersModule::register()`: 0 queries, 0 assets, hooks only.
- `prime_loop_fits()` (`Storefront.php:365-389`): exactly 1 query for the whole grid, option read before the query.
- `ProductIndexSync` hash short-circuit: ERP price/stock saves do not rewrite the index.
- `AttributeInstaller`: `has_archives => false` (`:51`) — no thin `pa_*` archive URLs. Good for SEO and for
  crawl budget.
- `Rest/garage` writes: option lock + single met a write. Fine.

---

## 3. SEO

### S0-1 (strategic) — The whole differentiator is currently `noindex`

`?moto=` is correctly excluded from the index (`Storefront.php:110-118`) and so are filtered catalog URLs
(`CatalogFilters/QueryFilter.php:75-90`). That is right for facets — but it also means **"cubiertas Benelli TRK
502" is invisible to Google**, and the store has no page that answers it. Meanwhile the *product* pages are the
only indexable surfaces and they only mention motorcycles inside a tab.

This is the gap RevZilla fills with model pages (§7). It is, by far, the biggest SEO opportunity in these modules.

### S0-2 (bug) — `?moto=` noindexes *any* URL, including product pages and the home page

`Storefront::robots_noindex()` checks only `requested_moto_slug()` and `Config::enabled()` — no
`is_catalog_request()` check, unlike CatalogFilters which requires `wc_query === 'product_query'`.
A product URL that arrives with `?moto=trk-502` (shared link, feed, ad, WhatsApp, retargeting) is emitted as
`noindex, follow` → the product silently drops out of the index. Same for the homepage.
**Fix (one line):** add `self::is_catalog_request() &&` to the condition.

### S1-1 — No canonical, no title/H1, no metadata on filtered pages

`?moto=` keeps "Tienda" as the H1 and the shop title. Harmless today because they are noindex, but it means the
transition to indexable bike pages must add: `<title>`, `meta description`, H1 "Cubiertas para Benelli TRK 502
2017+", breadcrumbs, canonical to self, and JSON-LD.

### S1-2 — Internal links point to noindex URLs (crawl budget)

Every product banner CTA ("Ver cubiertas compatibles"), the loop CTA and the garage CTA link to
`/tienda/?moto=…`, which is `noindex`. With N tire products × ~1 link each, the site systematically feeds the
crawler into dead ends.
**Fix:** keep `?moto=` as the *in-store* filter (selector, bar tabs, garage switch — all user-initiated) and point
the *editorial/SEO* links (product banner primary CTA, compatibility tab rows) to the **model landing page** (§7).
This single change converts wasted crawl budget into link equity.

### S1-3 — The compatibility tab is the only place where bikes and products meet, and it is not linked

`CompatListRenderer::format_entry()` (`:207-234`) prints motorcycles as plain text (`"TRK 502 (2017+ · Rear ·
Original)"`). Each of those is a natural anchor to a model page and to the filtered listing. Today the only exit
is the tab's search box.

### S1-4 — Mi Garage endpoint is crawlable but useless when logged out

`/mi-garage/` is a WC account endpoint; logged-out visitors are redirected to the My Account login, and the page
has no `noindex`. Add `noindex, follow` (and later a public "garage" landing that explains the feature with a
login CTA, if we want the entry point to be indexable).

### S2-1 — Structured data opportunities (currently none)

Per product: `Product` (Woo already does) + `isAccessoryOrSparePartFor`-style hint is not expressive here; better
is per-model page: `BreadcrumbList`, `ItemList` of products, `FAQPage` ("¿Qué medida de cubierta usa la Benelli
TRK 502?" → "Delantera 120/70ZR17, trasera 160/60ZR17"), and `Vehicle`-ish `about` on the page. The FAQ is a
real featured-snippet target for "medida cubierta benelli trk 502", which is exactly the query a rider types.

### S2-2 — Sitemap

`wp_sitemaps` will never learn about the model pages automatically if they are virtual routes. If we generate real
pages (§7 option A) the sitemap comes for free — a strong argument for option A.

---

## 4. Correctness / robustness

### B1 — Dead-end "pair" link on the product page (visible in the reported example)

`compat-garage.js:446-451` always renders "Ver cubiertas delanteras para tu …" for a rear tire (and vice versa),
without checking that the other axle has products. The bar *does* check and hides empty positions
(`Storefront.php:466-470`).

In the reported screenshot the bar shows **Todas · Trasero · Kits** (Delantero hidden ⇒ zero front tires), and the
same session offers "Ver cubiertas delanteras…" → the shopper lands on an empty grid. That inconsistency is the
most visible defect in the current UX.

**Fix:** pass the per-position counts to the client (or a `otherAxleCount` in `data-motored-fit`/config), and render
the pair link only when > 0; otherwise render "No tenemos delantera para esta moto todavía" + contact/notify CTA.

### B2 — `?moto=` + `pos` combinations can produce an empty page with no explanation in the block theme path

`filter_collection_block()` prepends the bar only when `render_block_woocommerce/product-collection` is the main
inherited query (`Storefront.php:561-567`). Themes/blocks that render the collection differently (e.g. a Query
Loop with `isProductCollectionBlock` but no `inherit`) get the filter without any bar. Already partly handled for
the classic "no products" hook; block path has no "no results" equivalent. Verify on the three themes (VERIFY
#115) and, if needed, append the bar when `have_posts()` is false.

### B3 — Facet counts and the grid are two different sources of truth

The headline total now prefers `$wp_query->found_posts` (`FilterRenderer.php:115-118`), but the per-term counts
come from the snapshot. Divergence sources: `wc_get_product_ids_on_sale()` (live), visibility terms read in SQL,
WooCommerce's own `outofstock` visibility, and the `not in stock` setting. It already bit us once (headline vs
grid). Long-term: compute the counts from the same product id set the main query returned
(`$wp_query->posts` + a snapshot restricted to those ids). Cheaper to reason about, and exact.

### B4 — Data readiness is the real failure mode, not the code

The matcher only works if (a) every tire product is indexed (`motored_compat_product_index`) and (b) single tires
carry `pa_ancho` / `pa_perfil` / `pa_rodado` for the by-size search (`auto_fill_dimensions` defaults to `no`).
The admin shows readiness per product (`readiness` indicator, `count_indexed()`), but there is no global
scorecard. Without it, the owner cannot tell the difference between "no demand" and "no data".
**Fix:** a single "Estado de compatibilidad" panel: published tires / indexed %, tires with complete size attrs %,
published versions with ≥1 compatible in-stock product, and per-bike front/rear coverage (top 20 worst offenders).
This is the highest-value *administrative* deliverable of the three modules.

### B5 — Guest garage is write-only

Guests can save bikes (`localStorage`), and see the active one via CTA/badges. They cannot list them, rename them
or delete them: the garage page is login-only and `renderSaved()` in the picker only *activates* a bike
(`compat-garage.js:342-371`). A guest who saved the wrong bike has no UI to remove it.
**Fix:** a public garage view (shortcode/block or `/mi-garage/` rendered for guests from `localStorage`) with
remove + "guardá tu garage" login CTA. Also relevant for shared computers (the logout cookie only helps logged-in
users).

### B6 — Orphan garage entries when a motorcycle is deleted or set to draft

`hydrate_garage_profiles()` drops them server-side and the guest revalidation handles 404
(`compat-garage.js:599-616`), but a *logged-in* garage silently loses the bike with no message during the
session; and nothing tells the owner how many customers were affected before deleting a version.
**Fix:** on `set_version_status( draft )` / delete, count garage references (cheap `LIKE` on user meta over a
capped set, or a daily AS job) and warn in the admin before confirming.

### B7 — Import/reindex leftovers (already largely fixed — keep an eye)

- `SnapshotInvalidator::bump_version()` does nothing without Redis (`:226-231`), so `/filters/sizes` keeps a
  fixed `version` token and the browser may serve a 5-minute-stale sizes list. Acceptable, document it.
- `FacetEngine::size_combinations()` takes `$widths[0]` / `$rims[0]` with no `ORDER BY` in the snapshot query: if
  a product ever carries two widths the chosen one is undefined. Defensive fix: sort or skip multi-valued rows.

---

## 5. Copy / UX corrections (the strings in the report)

The reported strings are correct *technically* but inconsistent in gender/number, ambiguous in vocabulary, and
they duplicate the same information in two places with different wordings. Spanish (es_AR) proposal:

| Where | Today | Proposed | Why |
|---|---|---|---|
| Product banner title (ok) | `✓ Compatible con tu Benelli TRK 502 2017+` | `✓ Esta cubierta sirve para tu Benelli TRK 502 2017+` | Says *what* is compatible; the current line reads like the bike is the subject. |
| Product banner meta | `Trasero · Medida original` | `Trasera · medida de fábrica (160/60ZR17)` | "Trasero" (masc.) vs "cubierta" (fem.); show the actual size, not just the label. |
| Match quality: OEM | `Medida original` | `Original (OEM)` | "Original" alone is ambiguous (original vs. aftermarket tire). |
| Match quality: equivalent | `Equivalente (aprox.)` | `Equivalente a la medida de fábrica (reemplaza 4.10-18)` | Explains *why* it fits. |
| Match quality: alternative | `Alternativa` | `Medida alternativa aprobada para tu moto` | "Alternativa" alone means nothing to a rider. |
| Banner CTA 1 | `Ver cubiertas compatibles` | `Ver todas las cubiertas para tu moto (N)` | Adds the count and the destination (model page, §7). |
| Banner CTA 2 (pair) | `Ver cubiertas delanteras para tu Benelli TRK 502 2017+` | `Completá el juego: delanteras (N)` **only if N>0**, else `No tenemos delantera para esta moto todavía → avisanos` | Shorter, and removes the dead end (B1). |
| Banner CTA 3 | `Cambiar moto` | `Cambiar de moto` (+ `Ver mi garage` when logged in) | Correct es_AR; adds the garage entry point. |
| Catalog bar title | `Cubiertas compatibles con: Benelli TRK 502 2017+` | `Cubiertas compatibles con Benelli TRK 502 2017+ · 12 resultados` | No colon, count included. |
| Catalog bar tabs | `Todas` / `Trasero` / `Kits` | `Todas (12)` / `Delanteras (0)` / `Traseras (8)` / `Kits (4)` | Gender/number agreement, counts, and empty tabs stay visible but disabled (explains the catalog, instead of hiding information). |
| Factory sizes | `Medidas de fábrica: Delantero: 120/70ZR17 · Trasero: 160/60ZR17` | `Medidas de fábrica — delantera 120/70ZR17 · trasera 160/60ZR17` | Agreement + readability. |
| Empty state | `We do not have tires for this motorcycle…` | `Todavía no tenemos cubiertas para esta moto. Dejanos tu contacto y te avisamos cuando lleguen.` + WhatsApp button | A niche bike with no stock is *demand capture*, not a dead end. |
| Disclaimer | `Verificá siempre la medida y los índices de carga y velocidad en el manual de tu moto.` | keep (optionally add `Los datos son de referencia.`) | Already good; RevZilla uses the same idea. |

Notes:
- The masculine/feminine fix must be applied in **one place**: `GarageAssets::client_config()['i18n']['front'|'rear']`
  (`:82-83`) and `CompatListRenderer::position_label()` (`:186-199`) are the two sources. Today they are two
  independent translations of the same concept — a single `TireSpecs::position_label()` used by PHP and passed to
  JS removes the drift permanently.
- "Kits" should stay in the bar only if the store actually has kits in stock for that bike (same rule as B1).

---

## 6. Flow improvements (beyond copy)

1. **Complete-the-set block (highest commercial impact).** On a rear-tire product page, show 2–3 concrete front
   tires that fit the same bike ("Completá el juego"), with the pair total. Today the shopper only gets a link to
   a grid. This is the "buscar el juego" idea from the brief, implemented where the decision happens.
2. **Shape the funnel backwards from the search query.** The rider's query is "cubierta 160/60 17" or "cubierta
   benelli trk 502". Today the site answers the first only through the size search widget and the second only
   through `?moto=` (noindex). The model page (§7) + size landing pages (`/medidas/160-60-17/`) close the loop.
3. **Readiness scorecard** (B4) as an admin panel, with a "fix this" link per row (product → tire box, bike →
   sizes table).
4. **Guest garage management** (B5).
5. **Notification of demand**: when a shopper hits an empty `?moto=` or empty position, offer "avisame"
   (email/WhatsApp) — this is the cheapest possible market research for which motorcycles to stock tires for.
6. **Measurement**: `analytics_events` is off by default. Turn on GA4/GTM events (`moto_select`, `fit_ok`,
   `fit_no`, `complete_set_click`, `garage_save`) — without them the owner cannot prove these modules work.
7. **Sorting inside a bike view**: `oem_first_sort` is a binary. For a tire store the useful order is
   OEM → alternative → equivalent, then price/brand. Already close; document it in the UI ("Primero las medidas
   originales").
8. **One filter system.** CatalogFilters duplicates the native layered nav. Decide: CatalogFilters is the only
   sidebar (native widgets off), otherwise counts and URLs diverge (B3).

---

## 7. RevZilla study and proposal

### 7.1 What RevZilla actually does (observed)

- **Persistent "Shop Your Ride"** in the header: `Type → Year → Make → Model → Go!`, plus **My Garage** with
  "saved vehicles" and "Recent Vehicles" (login required for persistence).
- **Indexable model landing pages**: `https://www.revzilla.com/parts/2026-yamaha-mt-07`, H1
  `2026 Yamaha MT-07 Parts & Accessories`, breadcrumbs `Home › Parts › 2026 Yamaha MT-07`, product grid with
  counts, and a `Fits your 2026 Yamaha MT-07` badge on each card that has been evaluated.
- **Hub pages** for discovery: `Browse By Manufacturer` → per-brand model index by year
  (`/oem-yamaha-motorcycle-parts` lists every model per year), and per-category "shop by bike" entry points.
- **Fitment as a facet**, not a hidden truth: `Fitment: Universal Fit (2351) / Exact Fit (112)` with tooltips, and
  a card badge that says which one it is.
- **Reference data block** on the model page (e.g. `Spark Plug: LMAR8A-9`) always followed by
  *"For reference only, please consult your owner's manual to confirm your fitment."*
- **Vehicle switch** in place: `Select Different Vehicle` next to the active one — the page does not send you back
  to the homepage to change bikes.
- Two catalogs side by side: OEM (fiche/submodels) and aftermarket (fitment), tabbed.

### 7.2 What is worth copying (and what is not)

| RevZilla pattern | Copy? | Why |
|---|---|---|
| Indexable model page per bike with the grid, H1, breadcrumbs | **Yes — core of the proposal** | Turns the differentiator into an indexed entry point. |
| `Fits your 2026 Yamaha MT-07` badge | Already have (better: client-side, cache-safe) | Keep. |
| Fitment facet (Exact/Universal) | **Yes, adapted**: Original / Alternativa / Equivalente | Already in the model; expose it as a facet + counts. |
| "Select Different Vehicle" in place | Yes | We have "Cambiar de moto" only on the product page; add to the bar/model page. |
| Reference data + manual disclaimer | **Yes** | "Medidas de fábrica" table on the model page + disclaimer. |
| Hub pages by brand/year | **Yes (phase 2)** | `/motos/benelli/` etc.; only when we have enough models per brand. |
| OEM fiche / submodel selector | **No** | Different business (parts diagrams); not applicable to tires. |
| Login-required garage | **No** | Our guest `localStorage` + account merge is better; keep it (but see B5). |
| Year-per-page URLs (`/parts/2024-…`, `/parts/2025-…`) | **No, not initially** | 366 versions → 366 near-duplicate thin pages. One page per **model**, years inside. |

### 7.3 Proposed architecture for Motored

**Unit of the page = motorcycle model** (not version/year). Rationale: `models` (179) is the stable entity; versions
differ only by year/variant; a rider searches the model name.

```
URL:      /cubiertas-para/{brand}/{model}/            e.g. /cubiertas-para/benelli/trk-502/
  H1:     Cubiertas para Benelli TRK 502 (2017+)
  title:  Cubiertas Benelli TRK 502 2017+ · Medidas y modelos | Motored
  canon:  self
  robots: index,follow   (only when published versions > 0 AND compatible in-stock products > 0)
Sections:
  1. Intro (2–4 sentences, editable per model) + OEM sizes table
     ("Medidas de fábrica · delantera 120/70ZR17 58W · trasera 160/60ZR17 69W")
  2. Selector "¿Es tu versión?" (variants/years of this model) — client-side, changes the grid via ?v= (noindex)
  3. Product grid, SSR, paginated, best-fit first, faceted by Original/Alternativa/Equivalent + brand + price
  4. "Completá el juego" (front + rear suggestion)
  5. FAQ (3–5 questions, FAQPage JSON-LD)
  6. Internal links: other years/variants, other models of the brand, sizes ("cubiertas 160/60-17")
  7. Disclaimer
```

**Two implementation options**

| | A. Real WP pages generated by the plugin | B. Virtual routes (rewrite + virtual template) |
|---|---|---|
| SEO plugins (Yoast/RankMath), sitemap, `hreflang`, per-page editing | Works natively | Needs custom integration |
| Theme header/footer/nav | Native | Risky (rule 9: no `get_header`) — would need a "shell page + query rewrite" trick |
| Maintenance | 1 page per model (draft/publish per bike) | Zero pages, but the owner cannot edit copy |
| Risk | Page drift when a model is deleted (needs sync/noindex) | Rewrite/permalink edge cases (already had one with `mi-garage`) |

**Recommendation: A**, with:
- a generator in `Productos > Compatibilidad` ("Crear páginas de moto") that creates one page per model with
  `[motored_moto brand="benelli" model="trk-502"]`, idempotent (meta `_motored_moto_page`), draft by default,
  **published only when the model has ≥1 published version and ≥1 in-stock compatible product**;
- the block/shortcode renders H1, sizes table, grid (`post__in` from `MatchEngine`, same matcher) and FAQ, so the
  page is 100% server-side and page-cache friendly;
- product banner/tab links point here (S1-2, S1-3) — that is what creates the internal link graph;
- `noindex` + canonical to `/cubiertas-para/` when the page has no products, so a catalog gap never creates a
  thin page.

**Data model additions (small)**
- `models.intro` / `models.seo_title` / `models.seo_description` (or post meta on the generated page — prefer the
  page meta, so the owner can edit without touching our tables; the JSON import keeps `intro` only).
- Optional: `models.page_id` to link back from the admin list to the generated page.

**Cache & performance**
- The page is guest-identical → full-page cache friendly (no nonce in the HTML; the garage script only hydrates).
- Grid = `post__in` from `matching_product_ids()` (already cached 1 h in `compat_matches`).
- Counts = `FacetEngine` restricted to `universe_ids` (already supported).
- Invalidate: `CompatCache::bump_catalog()` + purge the page on product/bike changes (native `wp_update_post`
  purge from `SnapshotInvalidator`/`ProductIndexSync` hooks, or a `clean_post_cache`).

**Copy that sells (es_AR)**
- H1: `Cubiertas para Benelli TRK 502 (2017+)`
- Above the grid: `12 cubiertas sirven para tu Benelli TRK 502 · medidas de fábrica delantera 120/70ZR17,
  trasera 160/60ZR17`
- FAQ: `¿Qué medida de cubierta usa la Benelli TRK 502?`, `¿Puedo usar una medida equivalente?`, `¿Vendés el par
  completo?`, `¿Cómo sé si la cubierta sirve para mi versión?`

### 7.4 Expected outcome

- One indexable page per model (start with the ~47 verified/published bikes that have stock) targeting
  `<modelo> cubiertas` / `<modelo> medida cubierta`, with the product grid as the answer.
- The `?moto=` filter stays as the *interaction* layer (selector, garage, in-store switching) and stop consuming
  crawl budget.
- The product page becomes a node in a graph: product → model page → other products, instead of product → noindex
  grid.

### 7.5 Cost estimate (build order)

| Step | Effort | Depends on |
|---|---|---|
| 1. Fixes S0-2, B1, P1-1, P1-2, P1-3 (bugs/perf) | 0.5–1 day | — |
| 2. Copy pass (§5) in one place (`TireSpecs::position_label()` + `GarageAssets` i18n) | 0.5 day | — |
| 3. `[motored_moto]` block/shortcode (H1, sizes, grid, FAQ, JSON-LD) | 1.5–2 days | — |
| 4. Page generator + sync/noindex rules + sitemap | 1 day | 3 |
| 5. Links from product banner/tab + hub `/cubiertas-para/` | 0.5 day | 4 |
| 6. "Complete the set" block | 1 day | 3 |
| 7. Readiness scorecard (B4) | 1 day | — |
| 8. Guest garage page (B5) | 0.5 day | — |

Total ≈ 6–7 focused days, no new tables required (only meta on generated pages).

### 7.6 Risks / open questions

- **Content thinness**: 47 pages with 3 products each may be seen as thin. Mitigation: OEM sizes table + FAQ +
  brand/model intro + at least 4–6 products or "equivalents" before publishing; keep the rest as drafts.
- **Duplicate intent with `?moto=`**: must be a single canonical story — model page = indexable, `?moto=` =
  noindex filter, cross-linked.
- **ERP overwrites** of attributes/descriptions must never wipe the page content (page content lives in
  `post_content`, not in product meta — safe).
- **Structured data**: do not emit `Product` markup on the landing page; emit `ItemList` + `BreadcrumbList` +
  `FAQPage`.

---

## 8. Prioritized plan

**P0 — before promoting the feature**
1. S0-2 `?moto=` noindex on product/home (1 line).
2. B1 dead-end pair link (counts to the client).
3. P1-2/P1-3 CSS in the head + gate the garage script (FOUC/CLS + wasted KB).
4. B4 readiness scorecard (are the differentiators actually working on real data?).

**P1 — make it a growth channel**
5. §5 copy pass (single source of position labels).
6. `[motored_moto]` + page generator + internal links (§7.3).
7. P1-1 search snapshot cache, B3 single source for counts.

**P2 — scale and polish**
8. Materialized facet snapshot (P0-1) when the catalog passes ~2k products.
9. Guest garage page, "complete the set", demand capture, analytics events.

---

## 9. Decisions needed from the owner

1. **Model pages**: owner leans **virtual routes**; recommendation below is **real CPT posts** (§10.2).
2. **Page unit**: owner wants **RevZilla-style (year visible)**; recommendation is **per model + tire-size signature** (§10.3).
3. **Pair link**: owner chose **keep it as a "notify me when in stock" entry point** (§10.4).
4. **`?moto=` bleed**: accepted — **pages take over marketing/SEO links, `?moto=` stays interaction-only** (§10.5).
5. **CatalogFilters vs native layered nav**: **CatalogFilters is the only facet system** (§10.6).

---

## 10. Decisions taken (2026-11-24) and the recommended build

### 10.1 Measured baseline (local site, real DB — not an estimate)

| Fact | Value | Implication |
|---|---|---|
| WordPress / theme | 7.1.5 / Twenty Twenty-Five (block theme) | block templates available; no `get_header()` needed or allowed |
| Object cache | none (`object-cache.php` absent) | `CompatCache` is a no-op today → §2 P0-1 applies in this environment |
| Brands / models | 20 / 180 | brand hubs are viable |
| Versions | 184 total, **48 published + verified** | pages are a 48-item problem, not a 366-item one |
| Versions missing front **or** rear fitment | **0** | published data is complete enough to render a page |
| Sizes / equivalences | 38 / 0 | size pages are worth it; equivalences must be filled by hand |
| Indexed products | **2** (store is empty) | the differentiator cannot be validated until products exist |
| Distinct (brand, model, tire-size signature) among published versions | **47** | 47 unique pages; only 3 models have more than one signature |

The last row is the decisive one: **in this catalog a year-specific URL is almost always the same page**. 44 of the 47 content units cover a single version, and only one signature is shared by two model years. Building 366 year pages would create near-duplicates of the same 47 pages.

### 10.2 Decision 1 — real CPT posts, not virtual routes

Both work. Real posts win *in this repository* for five concrete reasons:

1. **Block themes render it with zero hacks.** A CPT is a real post, so `single.html`/`single.php` prints the theme header, nav, breadcrumbs and **the post title as the H1**. A virtual route needs a shell page, and in a block theme the `core/post-title` block calls `get_the_title()`, not the `the_title` filter — the H1 would show the shell page's title unless we filter `render_block`. That is exactly the kind of theme-specific hack rule 9 forbids.
2. **Core gives the SEO surface for free:** `rel_canonical()` (singular canonical), `wp_sitemaps` (public post types and taxonomies are included automatically), `wp_robots`, revisions, preview, `get_permalink()`, nav menus, `post_status` as the index switch.
3. **No rewrite self-heal.** We already had one rewrite incident with `mi-garage`; a virtual router re-introduces flush rules, 404 handling, slug collisions and `is_page()` conflicts.
4. **Size of the problem is small.** 47 posts today; ~150–250 if all 136 draft versions are eventually verified. Nobody maintains that by hand, but a keyed upsert does it in one Action Scheduler job.
5. **It is less code, not more.** Virtual routing forces us to reimplement canonical + sitemap + `<title>`/H1 + 404 + redirects ≈ 2 extra days and three new failure modes, for the benefit of "no rows in wp_posts".

**Separation that removes the drift argument:** the CPT post holds **only the editorial layer** (title, intro, FAQ override, SEO overrides, status, slug). The OEM sizes, the fitments and the product grid are **rendered from the existing tables on every request**. Compatibility data is never copied into the post, so there is nothing to desynchronise; if a version is unpublished the page is regenerated from the new state.

If we ever *do* want virtual routes, the shape is: one hidden shell page (`_motored_shell`) + `pre_get_posts` swap + `render_block` title override + custom canonical + custom sitemap provider. That is the fallback, not the plan.

### 10.3 Decision 2 — RevZilla granularity, collapsed by tire-size signature

RevZilla's URL (`/parts/2026-yamaha-mt-07`) is *model-year*. In our data the equivalent of a model-year is a **version** (`model_id` + `year_from`/`year_to` + `variant`), but two versions that share the same OEM sizes are the same document.

Page unit = **(model, signature)** where

```
signature = sha1( front | front.min_load | front.min_speed | front.req_construction
                · rear  | rear.min_load  | rear.min_speed  | rear.req_construction )
```

Rules:

- One CPT post per signature, keyed by meta `_motored_sig` (idempotent upsert).
- Years that share the signature are **merged into one page**: title `2017–2024`, or `2017+` when `year_to = 0`.
- A year or variant that changes the sizes **produces its own page** (it is genuinely different content). Today that is 3 models; the CBR900RR is the only one with several.
- Inside the page, a "¿Es tu versión?" selector lists the sibling years/variants as links to their pages when they exist, and as no-JS-hidden aliases when they do not.

URL and structure:

```
/cubiertas-para/                        CPT archive — hub, links to brands + best-covered bikes
/cubiertas-para/marca/benelli/          taxonomy archive `motored_bike_brand` — 20 native hubs
/cubiertas-para/benelli-trk-502-2017/   CPT single — the page (2017+ because year_to = 0)
/cubiertas-para/honda-cbr900rr-2002/    second page only because the sizes differ
```

Indexability rule (data-driven, no manual switch):

| Condition | robots | canonical |
|---|---|---|
| ≥1 published version **and** ≥1 compatible product (in stock or backorderable) | `index,follow` | self |
| published but **0** compatible products | `noindex,follow` (still reachable: demand capture) | self |
| duplicate/merged signature | never exists (merged) | — |
| version unpublished/deleted | post trashed → 410, or 301 to the model's canonical signature | — |
| `?pos=`, `?paged=2`, `?v=` | `noindex,follow` | the base page |
| brand hub with 0 indexable bikes | `noindex,follow` | — |

### 10.4 Decision 3 — the pair link becomes a demand-capture entry point

Keep the "complete the set" idea, but never send a rider to an empty grid:

1. **Product page, other axle has stock** → `Completá el juego: delanteras (N)` linking to the bike page section `#delanteras`.
2. **Product page, other axle has 0 products** → `No tenemos delantera para tu TRK 502 todavía` + `Avisame cuando llegue` (email + optional WhatsApp, prefilled message), instead of a link.
3. **Bike page, a section with 0 products** → the same notify block inline in that section.
4. **Empty `?moto=` grid** → the same block, because that is where the demand actually shows up.

Storage and operational use:

- Table `motored_bike_demand` (`id`, `signature`, `position`, `contact` hashed, `email_or_phone`, `created_gmt`, `status`), one row per request, deduplicated by contact+signature+position.
- `POST /compat/notify` (REST, nonce for logged-in, honeypot + rate limit for guests, `Logger::mask_email()` / `mask_phone()` in logs, never the message body).
- Admin screen `Productos > Compatibilidad > Demanda`: grouped by bike/position, count, oldest request, CSV export. This tells the owner **which motorcycle to stock tires for** — the cheapest market research the store can get.
- Retention: purge after 180 days; register the table with the existing `Privacy` eraser/exporter (MiGarage already has the pattern).
- Optional internal ping through `Waha\Sender` (low priority) so the owner sees demand the same day.

### 10.5 Decision 4 — `?moto=` is interaction-only

| Where | Target |
|---|---|
| Product banner primary CTA, compatibility tab rows, garage CTA | **bike page** |
| Ads, WhatsApp links, social, e-mail, Google Business | **bike page** |
| In-store switching (selector, bar tabs, garage chip, "cambiar de moto") | `?moto=` (noindex, correct) |
| Filters inside a bike page | fragment `#delanteras` / `#traseras` / `#kits`, **not** `?pos=` (keeps indexable URLs clean) |
| Size search widget result | `/medidas/160-60-17/` (phase 2), not `?size=` |

Rationale: a `noindex` URL cannot rank, cannot hold link equity and has no `<title>`/description of its own. Every internal link pointing there is crawl budget spent to reach a dead end (S1-2). Once the page exists, `?moto=` keeps its real job: instant filtering without a navigation.

### 10.6 Decision 5 — CatalogFilters is the single facet system

Keep CatalogFilters, retire the native layered-nav widgets from the shop sidebar. Two systems produce two counts, two URL schemes and double queries for the same data (B3). Implementation: an opt-in setting `replace_native_layered_nav` that unhooks the native widgets through `Integration/WooCommerce` (`unregister_widget( 'WC_Widget_Layered_Nav' )` and `WC_Widget_Layered_Nav_Filters`), plus a Status row that warns when both are active in the same sidebar. Do not touch the widgets' code; just do not run them twice.

### 10.7 The SEO module — build it, do not fork a plugin

A fork of Yoast/Rank Math/AIOSEO is the wrong trade here: 20–40 MB and hundreds of options we would have to keep alive, an admin takeover, update conflicts with WooCommerce/HPOS, a permanent maintenance burden on a GPL derivative, and a much larger security surface — to obtain roughly 20% of their features. Most of the surface we need is **WordPress core**:

| Need | Core provides | We write |
|---|---|---|
| Canonical | `rel_canonical()` on singular | override for merged/paged cases |
| XML sitemap | `wp_sitemaps` + `wp_sitemaps_post_types` / `wp_sitemaps_taxonomies` filters | exclusion rules + priority |
| robots meta | `wp_robots` | per-page rules |
| `<title>` | `document_title_parts` / `pre_get_document_title` | templates per entity |
| Redirects | `template_redirect` | 301 map for renamed/deleted slugs |
| Structured data | — | `BreadcrumbList`, `ItemList`, `FAQPage`, `about: Motorcycle` |
| Social | — | OG/Twitter tags |

Proposed `Modules\Seo` (~600–800 lines, 0 queries on unrelated pages):

```
Seo::page([
  'title'       => 'Cubiertas Benelli TRK 502 2017+ · medidas y precios',
  'description' => 'Cubiertas para Benelli TRK 502 (2017+). Medidas de fábrica: delantera 120/70ZR17, trasera 160/60ZR17. 8 modelos en stock, envíos a todo el país.',
  'canonical'   => 'self',
  'robots'      => 'index,follow',
  'og_image'    => ...,
  'schema'      => [ BreadcrumbList, ItemList, FAQPage ],
])
```

It registers hooks only for requests it owns (`is_singular('motored_bike')`, its taxonomies, `is_front_page`, `is_shop`, `is_product`, plus sitemap/robots hooks) — rule 2. Title/description/robots overrides live in post meta, so the owner can fix a single page without touching code. The only feature a plugin really saves us is a redirects UI; a small table + one screen covers it later.

Non-negotiable technical rules for the module: one `<h1>` per page (the post title; the renderer never prints a second one), `noindex` on search/filtered/`?moto=`/`?paged>1`, no `FAQPage` markup unless the FAQ is visible on the page, no `Product` markup on landing pages (`ItemList` only), `Product` stays on product pages via WooCommerce.

### 10.8 Target architecture

```
Compatibility (unchanged matcher)            CatalogFilters (unchanged facets)
        │  MatchEngine::matching_product_ids()      │ FacetEngine (counts on a universe)
        ▼                                            ▼
Modules\BikePages  ── CPT motored_bike + taxonomy motored_bike_brand
   ├─ SignatureSync      (idempotent upsert, keyed _motored_sig, Action Scheduler)
   ├─ BikeRenderer       ([motored_moto]: hero, sizes, sections, pair, notify, FAQ)
   ├─ DemandStore        (notify + admin report)
   └─ HubLinks           (product banner/tab → page, archive → brands)
Modules\Seo        ── titles, meta, canonical, robots, OG, JSON-LD, sitemap, redirects
Support\StorefrontAssets (CSS/JS gating fixes from §2)
```

`BikeRenderer` uses `wc_get_template_part( 'content', 'product' )` inside its own `WP_Query` so the cards are the theme's own markup — no duplicated card CSS, no theme coupling.

### 10.9 Page anatomy (in conversion order)

1. **Hero** — H1 `Cubiertas para Benelli TRK 502 (2017+)`, chip `✓ Tu moto` when it is in the garage, `N cubiertas en stock`, `desde $X`, `3 y 6 cuotas sin interés` (PaymentPlans), `Enviamos a todo el país`.
2. **Sizes table** — `Medidas de fábrica · delantera 120/70ZR17 58W · trasera 160/60ZR17 69W`, plus `¿No coincide? Ver medidas equivalentes`.
3. **Selector `¿Es tu versión?`** — years/variants, plain links.
4. **Product sections, all rendered server-side** — `Delanteras (N)`, `Traseras (N)`, `Kits (N)` as anchors, not query filters. Empty section → notify block (§10.4).
5. **Completá el juego** — cheapest/best-fit pair with the total.
6. **Why it fits** — a one-line explanation per badge (OEM / alternativa / equivalente) — the "why", which is what converts and what differentiates us from a generic grid.
7. **Trust** — load/speed index verified, `Verificá siempre el manual`, returns, fitting service, WhatsApp prefilled with `Hola, busco 160/60ZR17 para mi TRK 502 2017`.
8. **FAQ** — 3–5 questions generated from data, editable.
9. **Internal links** — other years of the same model, same brand, the same size pages, "otras motos que usan 160/60ZR17".
10. **Disclaimer** + breadcrumbs.

Mobile: sticky mini-bar with the active bike + `Cambiar` + `WhatsApp`.

### 10.10 Ranking plan

| Query cluster | Target page | What has to be on the page |
|---|---|---|
| `cubiertas benelli trk 502` | bike page | H1 + sizes + grid + stock |
| `medida cubierta benelli trk 502` / `qué cubierta lleva` | bike page | sizes table + FAQ (`FAQPage`) |
| `cubierta benelli trk 502 2017` | bike page (year in H1/title) | year range text |
| `cubiertas benelli` | brand hub | model list with sizes |
| `cubierta 160/60 17` / `160/60 r17 precio` | size page (phase 2) | products + equivalents + fits-which-bikes |
| `cubierta trasera 160/60 17` | size page + `#traseras` | position content |
| `cubiertas para moto` (generic) | not winnable now | — do not chase it |

Technical checklist: sitemap submitted in Search Console; `noindex` on `?moto=`, facets, `?paged>1`; 301 on slug changes; zero broken internal links; one H1; `ItemList` + `BreadcrumbList`; OG image per page; no new JS on these pages (only the garage hydration); LCP = first product image (`loading="eager"` on the first card only); internal links from every compatible product page to its bike page (that graph is what makes 47 pages rank instead of 47 orphans).

Measurement: `bike_page_view`, `variant_select`, `fit_ok`, `fit_no`, `complete_set_click`, `notify_submit`, `whatsapp_click` (GA4, `analytics_events` is off today), plus Search Console impressions/clicks per page.

### 10.11 Effort and order

| Stage | Effort | Ships |
|---|---|---|
| 0. P0 fixes: S0-2 `noindex`, B1 pair link, P1-1 search snapshot, P1-2 CSS in head, P1-3 asset gating | 0.5–1 d | trust + CWV |
| 1. `Modules\Seo` core (title/meta/canonical/robots/OG/sitemap/redirects) | 1.5–2 d | — |
| 2. CPT + taxonomy + `SignatureSync` | 1 d | 47 pages created (noindex) |
| 3. `BikeRenderer` (hero, sizes, sections, FAQ, schema) | 2 d | pages indexable |
| 4. Hubs + internal links from product banner/tab + sitemap | 1 d | link graph |
| 5. Notify + demand admin + pair block | 1.5 d | demand capture |
| 6. Size pages (`/medidas/{size}/`) | 1.5 d | second query cluster |

MVP that already ranks and converts = stages 0–4 (**≈5–6 focused days**). Full = 8–9 days.

### 10.12 Risks

- **Thin pages.** 47 pages with 2 indexed products today. Mitigation: the indexability rule (§10.3) keeps 0-product pages out of the index; the FAQ + sizes table + pair block give every page unique content; publish products first.
- **Duplicate intent with `?moto=`.** One story only: page = indexable, `?moto=` = filter, cross-linked (§10.5).
- **Year URL expectations.** Only 3 models have more than one signature. Do not promise "one page per year" until the data justifies it.
- **Block theme dependency.** The theme must print post content. TT5 does; add `register_block_template()` as an opt-in fallback for a theme that does not.
- **Slug renames.** Store `_motored_prev_slug` and 301; otherwise every brand/model rename creates 404s.
- **Notify-me is personal data.** Consent text, 180-day retention, privacy exporter/eraser, no PII in logs.
- **Stale pages after ERP edits.** Purge the page cache (and bump `CompatCache`) on version/product-index changes; the page content itself is rendered live, so only the HTML cache can go stale.

### 10.13 Confirmed by the owner (2026-11-24)

1. **Real posts: yes** — but with **zero manual work**: one page per registered motorcycle, created automatically, including through the JSON import.
2. **Merge rule: confirmed** (one page per tire-size signature, years inside).
3. **Notify-me: a single button**, no channel choice for the customer; delivery goes out by e-mail **and** WhatsApp, but the delivery itself is owned by the notification modules — **not** by BikePages/Compatibility/CatalogFilters.

### 10.14 Automatic page generation (no manual step, import included)

All catalog writes already funnel through `CatalogRepository`: `upsert_version`, `update_version`, `set_version_status`, `delete_version`, `upsert_fitment`, `delete_fitment`, `delete_model`, `delete_brand`, `upsert_brand`, `update_brand`. That is the single choke point, so one lifecycle event is enough:

```php
do_action( 'motored_compat_catalog_changed', $entity, $id, $op ); // entity: version|fitment|model|brand
```

`BikePageSync` (in BikePages) listens, resolves the affected version ids, and schedules one Action Scheduler job per version (deduped with `as_has_scheduled_action`). Nothing runs on customer requests (rule 7).

| Trigger | Result |
|---|---|
| version published (new signature) | create post: title, slug `{brand}-{model}-{year_from}`, content marker, meta, brand term |
| version published (signature already has a post) | nothing — years are merged, the page already lists them |
| version drafted / deleted (last one on that signature) | post trashed → 410, or 301 to the model's canonical signature |
| fitment changed (different sizes) | new signature → new post; old post 301 → new (`_motored_prev_slug`) |
| model / brand renamed | `post_name` updated + 301 from the old slug |
| JSON import | same rules, applied **per chunk** (`ImportRunner` cursor): versions touched in the chunk are queued, so pages appear while the import runs, not after it |
| product index change (product becomes compatible) | no post write; only the index/noindex flag is recomputed (the grid is rendered live) |
| owner edits title/intro in the editor | preserved: the sync only overwrites `post_title` while it still equals `_motored_synced_title`, and only overwrites `post_content` while it still equals the generated marker |

Safety rails:

- Idempotent: post located by meta `_motored_sig`; create/update is decided by existence, never by count.
- Batched: 20 versions per job with a cursor; a full rebuild of 184 versions is one queued chain, ~2 minutes.
- Admin: `Productos > Compatibilidad > Páginas de moto` shows `47 páginas · 12 indexables · 35 noindex` with a dry-run **Regenerar** button; the same button exists after an import.
- Optional setting `pages_for_drafts` (default off): create the page for draft versions as `noindex` so the owner can preview copy before verifying the bike.

Net effect for the owner: **register/publish the motorcycle → the page exists**. Import the JSON → the pages exist. There is no per-bike step to remember.

### 10.15 "Avisame" contract — one button, delivery handled elsewhere

BikePages knows nothing about e-mail or WhatsApp. It produces a demand record and fires a domain event; the notification modules consume it.

```
UI        one button "Avisame cuando llegue"
          one field that accepts e-mail OR phone (auto-detected), optional second field,
          consent line ("Acepto que me avisen por e-mail o WhatsApp"), no channel choice.
Store     motored_bike_demand (signature, position, contact, consent_gmt, created_gmt, status)
Event     do_action( 'motored_bike_demand_created', $demand_id, $context )
          $context = [ signature, position, brand, model, years, sizes, has_email, has_phone ]
Copy      apply_filters( 'motored_bike_demand_message', $text, $context )   // modules can rewrite
Ack       do_action( 'motored_bike_demand_notified', $demand_id, $channel, $ok )
```

- E-mail + WhatsApp are both attempted when the customer left both contacts; when only one was given, only that channel is used. The customer never sees a channel switch.
- Hook names live in `src/Integration/` (one small class), following the `CompatibilityHooks` pattern, so the module boundary stays explicit.
- BikePages never calls `wp_mail()` or `Waha\Sender` directly — that is the whole point of the split the owner asked for.
- Privacy: consent timestamp stored, 180-day retention, exporter/eraser registered with the existing `Privacy` pattern, logs masked, message body never logged.

### 10.16 Does this actually help SEO? (honest answer)

**Yes, for the long tail, once the catalog has products.** The mechanism:

1. Each page answers a query that today has no landing page: `cubiertas benelli trk 502`, `qué cubierta lleva la trk 502 2019`, `cubierta 160/60 17`.
2. It is the only indexable surface where a motorcycle and its products meet — the product pages mention bikes only inside a tab.
3. It enters the XML sitemap (native) and receives internal links from every compatible product page, which is what makes 47 pages a cluster instead of 47 orphans.
4. The sizes table + FAQ is a featured-snippet target, which is the cheapest click a tire store can get.
5. `noindex` on `?moto=`/facets stops burning crawl budget on dead ends.

**And the honest limits:** 47 pages is a small corpus; generic terms (`cubiertas para moto`) are not winnable against marketplaces. The gain is long tail + internal structure + snippet capture, and it compounds as models and products are added. Today the store has **2 indexed products**, so under the indexability rule every page would be `noindex` — the SEO benefit only starts when each bike has at least one compatible product. Content architecture is a prerequisite, not a substitute for catalog.

### 10.17 Next step

Implementation plan (files, hooks, tables, order of commits) for stages 0–4, then stage 0 as the first change. Nothing is implemented yet.

