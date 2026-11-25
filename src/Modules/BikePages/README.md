# Module: BikePages

## Purpose
Generates and maintains automatic, indexable motorcycle landing pages (`motored_bike` CPT collapsed per model + OEM/approved-alternative specification signature), brand hubs (`motored_bike_brand`), optional tire-size landing pages (`motored_tire_size`), internal links from Compatibility tabs/banners, and "Notify me when in stock" demand capture (`motored_bike_demand`).

## Public contracts
- `BikePages\Config`: typed accessors for option `motored_bike_pages` — **autoload=yes** (the single exception in
  `Support\Options::AUTOLOAD_MODULES`): the CPT/taxonomy rewrite slugs are read on every request by `register_post_type()`,
  so autoloading the ~0.5 KB option avoids +1 query on every page. `Options::sync_autoload()` flips older installs on
  `admin_init` (no query when already autoloaded).
- `BikePages\Signature`: deterministic SHA-1 fitment signature and year-range grouping per model.
- `BikePages\BikePageSync`: idempotent Action Scheduler upsert keyed by `_motored_sig`; listens to `motored_compat_catalog_changed` and `motored_compat_product_index_changed`.
- `BikePages\HubLinks`: cached O(1) lookup via filter `motored_compat_bike_page_url`.
- `BikePages\BikeRenderer`: `[motored_moto]` shortcode + `pair_block_html()` helper.
- `BikePages\SizePages`: `[motored_medida]` shortcode + filter `motored_compat_size_page_url` (when `dimension_pages` enabled).
- `Integration\BikeDemandHooks`: `motored_bike_demand_created`, `motored_bike_demand_message`, `motored_bike_demand_notified`.

## Hooks and filters used
| Hook/filter | Context | Why | Verification status |
|---|---|---|---|
| `motored_compat_catalog_changed` | Admin/Cron/CLI/REST/AJAX | Queues deduped AS sync per affected model | `unverified` (VERIFY #127) |
| `motored_compat_product_index_changed` | Admin/Cron/CLI/REST/AJAX | Queues batched `_motored_indexable` recompute | `unverified` (VERIFY #127) |
| `motored_compat_bike_page_url` | Frontend/REST | Resolves motorcycle slugs to `motored_bike` permalinks. Registered in REST too (`HubLinks::register_url_filter()`): banner, garage and hydrated tab rows read profiles from REST | `unverified` (VERIFY #128) |
| `init@20` → `register_block_template()` | Block themes | Plugin `single-motored_bike` template (header + post title H1 + content + footer), no blog post meta | `unverified` (VERIFY #130) |
| `admin_init` (plugin screens) | Admin | `BikePageSync::maybe_schedule_resync()` queues one full rebuild when `SYNC_VERSION` changes (option `motored_bike_pages_sync_ver`) | — |
| `wp`, `wp_enqueue_scripts`, `the_content` | Frontend | Populates `SeoContext`, loads `compat.css`/JS in `<head>`, renders `[motored_moto]` | `unverified` (VERIFY #128) |
| `woocommerce_product_set_stock_status`, `transition_post_status` | All contexts | An indexed tire changing stock or publish status queues the debounced indexability sweep (`Availability` rule) | `unverified` (VERIFY #133) |
| `init@20` → `register_block_template()` (hubs) | Block themes | `archive-motored_bike` / `taxonomy-motored_bike_brand`: H1 + `[motored_bike_hub]` directory instead of the theme post loop | `partial` (VERIFY #134) |
| `get_the_archive_title`, shortcode `motored_bike_hub` | Frontend | Hub H1 ("Cubiertas por moto" / "Cubiertas para motos {marca}") and hub body | `partial` (VERIFY #134) |
| `template_redirect@20` | Frontend | `GoneHandler`: 410 for `/{base}/{slug}/` of a trashed bike page (only on 404) | `partial` (VERIFY #132) |
| `wp_privacy_personal_data_exporters/erasers` | Admin/AJAX | Exports and erases `motored_bike_demand` rows by e-mail | `unverified` (VERIFY #129) |

## Data model
- Option: `motored_bike_pages` (autoload=yes, see Public contracts).
- Table: `{prefix}motored_bike_demand` (migrations `2026112406` / `2026112407`, dropped on uninstall; delivery fields: `version_slug`, `user_id`, `status`, `channel`, `last_error`, `updated_gmt`, `notified_gmt`; indexed by `(status,id)`).
- CPT/Taxonomies: `motored_bike`, `motored_bike_brand`, optional `motored_tire_size`.
- Post meta: `_motored_sig`, `_motored_model_id`, `_motored_primary_slug`, `_motored_version_slugs`, `_motored_synced_title`, `_motored_synced_content`, `_motored_prev_slug`, `_motored_years_label`, `_motored_indexable`, `_motored_size_canonical`.

## Configuration keys
Edited in WooCommerce > Settings > Motored > Motorcycle pages:
- `enabled` (`yes`), `auto_create` (`yes`), `pages_for_drafts` (`no`), `base_slug` (`cubiertas-para`), `brand_base` (`marca`), `index_min_products` (`1`), `faq_enabled` (`yes`), `notify_enabled` (`yes`), `notify_retention_days` (`180`), `notify_email` (`''`), `dimension_pages` (`no`).

## Main flows
1. URLs: one page per model owns `{brand}-{model}` (no year); other signatures of that model get `{brand}-{model}-{year_from}[-variant]`. The clean slug is sticky (never moves to a newer generation); renames leave a 301 (`Seo\Redirects`).
   Indexable = >= `index_min_products` AVAILABLE compatible products (`Availability`: published, visible, purchasable, in stock or backorder) — the same rule as the page hero.
   `<title>`: `BikeRenderer::seo_title()` picks the longest variant <= 60 chars.
1. Outside its own singular page (`/cubiertas-para/`, brand hubs on classic themes, site search), `[motored_moto]` prints a 0-query card from `_motored_oem_sizes` (written by the sync, `SYNC_VERSION = 5`) — never the full page.
1. Generated titles are always built in the SITE locale (`switch_to_locale( get_locale() )` in `sync_model()`), never the triggering user's.
2. Catalog write in `CatalogRepository` emits `motored_compat_catalog_changed` -> `BikePageSync` queues AS job -> `sync_model()` groups versions by `Signature`, upserts CPT posts (preserving manual edits), registers 301 redirects via `Seo\Redirects` on slug changes/orphans, and sets `_motored_indexable`.
3. Frontend `BikeRenderer` computes live product sections via `MatchEngine::matching_product_ids()` + `wc_get_template_part('content','product')` and feeds `Seo\SeoContext`.
4. `POST /motored/v1/compat/notify` stores deduplicated demand in `motored_bike_demand`, logs masked contact, and emits `BikeDemandHooks::created()` for notification modules.

## Performance and security notes
- Zero heavy work on frontend requests (Rule 7); page HTML is guest-identical (page-cache safe).
- `HubLinks::version_slug_url_map()` caches all links in 1 query (0 with Redis) so Compatibility tabs incur zero N+1 lookups.
- Demand endpoint: accounts need the core `wp_rest` nonce plus `X-Motored-Nonce`; guests need neither (no session to forge, and a page-cached nonce older than 24 h would break the button) and are limited by honeypot + rate limit. Phones are normalized with `Waha\Phone::normalize()` at capture (undeliverable numbers get a 422 with a specific es_AR message; formats dedupe to one E.164). Accounts without a billing phone are asked for WhatsApp in the dialog (empty = e-mail). The success response names the channel (`channel`: whatsapp|email). The fixed-window 5 req/10 min counter is serialized with an atomic option lock (customer ID or guest IP). Honeypot and masked logging remain.
- Authenticated contacts come from `WC_Customer` billing phone/account email; submitted contact/account IDs cannot override them. Guests provide one contact; arbitrary submitted secondary contacts are ignored. Optional registration uses the native WooCommerce account form, without automatic creation, login, or linking of existing accounts.
- Counts, grids, recommendations, summary and delivery use `Availability`. Summaries examine the full set; grids render at most 24 cards and provide a native filtered-shop continuation. Missing technical specifications now carry an explicit manual-check warning.
- Sitemap requires positive `_motored_indexable = 1` and excludes explicit `noindex,follow`; `index,follow` cannot override a live availability noindex. Stock updates still reconcile stored sitemap metadata asynchronously; external page caches require their own purge integration.

## How to test
- Unit tests: `tests/Unit/Modules/BikePages/` (`BikePagesModuleTest`, `SignatureTest`, `BikePageSyncTest`, `DemandStoreTest`, `DemandRestControllerTest`, `DemandPrivacyTest`).
- Staging: `docs/STAGING-CHECKLIST.md` + `docs/VERIFY.md` (#127–#129).

## Known limitations
- `OrderNotifications\\BikeDemandNotifier` now consumes demand events, catalog/index events and daily AS reconciliation. It atomically claims rows, rechecks matching commercial stock at send time, uses WAHA first, then native `wc_mail()` templates only after terminal WAHA failure or when no phone exists. Scheduler arguments contain IDs/attempt numbers only.
- Delivery states: `pending` -> `queued` -> `sending` -> `notified`; safety switches/configuration errors produce `paused`, terminal exhausted delivery produces `failed`. OFF/test mode never leaks into real fallback email. WAHA owns its retries; email has three bounded attempts. Provider acceptance does not prove inbox delivery/read receipt. Renewing a completed/failed alert requires an explicit new subscription request; duplicates in flight never reset the claim.
- Signed single-subscription cancellation: messages link to `/?motored_alert_cancel={id}&token=...` (`DemandCancelHandler`): GET shows an es_AR confirmation, only the POST deletes (mail scanners and link previews fetch with GET). `POST /motored/v1/compat/notify/{id}/unsubscribe?token=...` remains for scripts. Works while capture/delivery are disabled.
- Existing legacy rows with no `version_slug` do not guess a model/year. A renewed request can populate it; otherwise review/migrate these rows explicitly. Stuck `sending` rows after process crashes need operator review rather than unsafe automatic re-sends.
- Real delivery requires a configured, enabled WAHA installation and working cron/Action Scheduler; the local implementation was tested with external HTTP blocked and mocked mail, not a real WhatsApp recipient.

## Changelog
- 1.1.0 — 2026-11-24 — Functional WAHA-first demand delivery, account/guest single-CTA flow, native optional registration, signed cancellation, commercial availability parity, full-set summaries, paginated-shop continuation, alternative-aware grouping, scoped WC card layout/mobile table scrolling, and safer robots/sitemap policy. Verification: local integration 19 assertions; PHPUnit 726 tests/3986 assertions, one pre-existing skip; PHPStan clean (see VERIFY).
- 1.0.0 — 2026-11-24 — Initial BikePages module (CPT/hubs, `BikePageSync`, `BikeRenderer`, `HubLinks`, `DemandStore`/`DemandRestController`, `SizePages`).
