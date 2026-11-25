---
name: motored-module-seo
description: Rules for Modules\Seo (titles, meta descriptions, canonicals, wp_robots, OG, JSON-LD, wp_sitemaps filtering, 404 redirects). Use when editing src/Modules/Seo.
---

# Module Seo
Read `src/Modules/Seo/README.md` first.

- **Core-first & zero bloat:** built exclusively on WordPress core (`pre_get_document_title`, `get_canonical_url`, `wp_robots`, `wp_sitemaps_*`). Never fork third-party SEO plugins.
- **Query budget:** 0 DB queries on unrelated 200 requests. `Redirects::maybe_redirect()` runs ONLY when `is_404()` is true.
- **Landing-page schema rules:** NEVER emit `Product` schema on `motored_bike` or `motored_tire_size` landing pages (`ItemList`, `BreadcrumbList`, `Motorcycle`, `FAQPage` only); NEVER emit `FAQPage` without visible FAQ items.
- **Indexability guard:** Search, `paged > 1`, and `_motored_indexable = '0'` always emit `noindex, follow` and are excluded from `wp_sitemaps`.
- **Coexistence:** never print a second title/description/canonical/OG when a full SEO plugin is active (`HeadRenderer::external_seo_plugin()`, checked at hook time, not at boot).
- **Admin UI:** `SeoAdmin` uses `Admin\Ui` + `motored-admin.css` only (`SharedAdminUiTest`).
- After changes: `composer check`, `motored-review`, update `docs/VERIFY.md` and `docs/PROJECT.md`.
