# Module: ImageOptimizer

## Purpose
Compresses, resizes, strips EXIF/IPTC metadata, and optionally emits WebP sub-sizes for newly uploaded images using WordPress core's native image filters, plus an Action Scheduler cursor-batched bulk optimizer with a non-destructive dry-run scan for existing Media Library attachments (preserving original file names and URLs by default so external ERP links stay intact). Built on the shared `Support\ImageProcessor` helper.

## Public contracts
- Option key `motored_image_optimizer` (`autoload=false`), Action Scheduler hook `motored_image_opt_batch` (cancelled with the `motored-core` group).
- Shared helper `Motored\Core\Support\ImageProcessor`: `supports_webp()`, `active_editor_name()`, `target_size()`, `keep_sizes()`, `load_editor()`, `process_upload()`, `optimize_in_place()`.
- `OptimizerRules` (pure validation, MIME allow-list, size clamping, sub-size filtering, job state normalization).
- `AttachmentOptimizer::optimize( int $attachment_id, bool $dry_run = false, bool $webp_subsizes = false )` and `BulkRunner::start()`, `resume()`, `cancel()`, `status()`, `library_stats()`.

## Hooks and filters used
| Hook/filter | Context | Why | Verification status -> VERIFY.md |
|---|---|---|---|
| `big_image_size_threshold` (filter, prio 20) | non-frontend (`admin`, `ajax`, `rest`, `cron`, `cli`) | Sets max width/height (`max_side`, 800–3000 px) on new uploads | partial (core) -> VERIFY #119 |
| `wp_editor_set_quality` (filter, prio 20, 2 args) | non-frontend | Sets JPEG/WebP compression quality (`quality`, 60–95) | partial (core) -> VERIFY #119 |
| `image_editor_output_format` (filter, prio 20) | non-frontend | Maps JPEG (and optionally PNG) sub-sizes to `image/webp` when supported | partial (core) -> VERIFY #119 |
| `intermediate_image_sizes_advanced` (filter, prio 20) | non-frontend | Drops unused `1536x1536` and `2048x2048` sizes when enabled | partial (core) -> VERIFY #119 |
| `image_strip_meta` (filter, prio 20) | non-frontend | Strips EXIF/IPTC metadata when enabled | partial (core) -> VERIFY #119 |
| `wp_generate_attachment_metadata` (filter, prio 99, 2 args) | non-frontend | Records `_motored_img_opt` marker (`mode => 'upload'`) on newly uploaded attachments | partial (core) -> VERIFY #119 |
| `motored_image_opt_batch` (Action Scheduler action) | `admin`, `ajax`, `rest`, `cron`, `cli` | Executes one cursor-based batch and schedules the next | partial (AS) -> VERIFY #120 |
| `admin_post_motored_img_opt_{dry_run,start,resume,cancel}` | admin | Authenticated (`manage_woocommerce` + nonce) bulk runner tool triggers | own |
| `motored_status_rows` (filter) | admin | Adds server editor + WebP + active status line under Motored > Status | own |

## Data model
- Option `motored_image_optimizer` (`autoload=false`): module settings.
- Option `motored_image_opt_job` (`autoload=false`): bulk runner state (`status`, `run_id`, `dry_run`, `only_unoptimized`, `total`, `processed`, `optimized`, `skipped`, `errors`, `bytes_before`, `bytes_after`, `cursor`, `updated_ts`, `finished_gmt`).
- Option `motored_image_opt_saved` (`autoload=false`): cumulative saved bytes across live runs.
- Attachment post meta `_motored_img_opt`: `{v, mode ('upload'|'bulk'), at, saved}` used to skip already-optimized attachments on incremental runs (`deleted` on full plugin uninstall when enabled). No transients or custom tables; ephemeral admin flash notices and batch lock (`img_opt`) use `Support\Cache`.

## Configuration keys
Edited under WooCommerce > Settings > Motored > Images (section `images`, priority 65, capability `manage_woocommerce`, no secrets):
- `enabled` (`no`): enable upload-time optimization filters (disabled by default for staging opt-in).
- `max_side` (`1600`, 800–3000): longest side threshold in px.
- `quality` (`82`, 60–95): JPEG/WebP quality.
- `convert_webp` (`yes`): emit WebP sub-sizes for new JPEG uploads (when server supports WebP).
- `convert_png_webp` (`no`): opt-in PNG->WebP conversion (kept off by default for alpha safety).
- `strip_exif` (`yes`): remove EXIF/IPTC metadata.
- `skip_unused_sizes` (`no`): skip `1536x1536` and `2048x2048` intermediate sizes on new uploads.
- `bulk_batch_size` (`10`, 5–25): attachments processed per Action Scheduler batch.
- `bulk_webp_subsizes` (`no`): opt-in WebP sub-size regeneration in bulk runs (default `no` preserves existing URLs/extensions for ERP sync).
- `bulk_resize` (`no`): bulk runs also scale existing originals down to `max_side`; off = recompress only (wide banners/theme images keep their resolution).

## Main flows
1. **Upload pipeline:** `ImageOptimizerModule::should_load()` skips `$context->is_frontend()`. In admin/REST/ajax/cron/CLI requests, `UploadHooks` registers core filters cleanly without reading options in `register()`. Each filter short-circuits when `Config::enabled()` is false.
2. **In-place bulk optimization:** Admin starts Dry-Run Scan or Live Optimize via `admin-post.php`. `BulkRunner::start()` counts eligible attachments (`image/jpeg`, `image/png`, `image/webp`) and queues `motored_image_opt_batch` via `ActionScheduler` (falling back to 1 synchronous batch when AS is unavailable).
3. **Worker execution:** `BulkRunner::run_batch()` acquires a `Cache::add` mutex (`120 s`), loads `cursor > ID` attachments up to `bulk_batch_size`, calls `AttachmentOptimizer::optimize()` -> `ImageProcessor::optimize_in_place()` (only keeps smaller output via atomic same-dir temp file swap, or resizes when dimensions exceeded `max_side`), updates attachment metadata + `_motored_img_opt`, accumulates saved bytes, and schedules the next slice or marks `completed`.

## Performance and security notes
- **0 overhead on storefront traffic:** `should_load()` returns `! $context->is_frontend()`, so public page views load 0 files, 0 hooks, and 0 DB queries.
- Never overwrites an existing file with a *larger* re-encoded buffer (only writes if shrunk or resized down); never breaks original attachment URLs unless `bulk_webp_subsizes` is explicitly enabled.
- All admin actions enforce `manage_woocommerce`, nonce (`motored_img_opt_tools`), and shared `.motored-table`/`.motored-box` UI rules (`SharedAdminUiTest`). Logs contain attachment IDs and error codes only (no PII or raw server paths).

## How to test
- `php vendor/phpunit/phpunit/phpunit tests/Unit/Support/ImageProcessorTest.php tests/Unit/Modules/ImageOptimizer`
- Staging verification: `docs/VERIFY.md` #118–#120 and `docs/STAGING-CHECKLIST.md` (ImageOptimizer section).

## Known limitations
- Requires PHP GD or Imagick with WebP write support (`imagewebp` / `Imagick::queryFormats('WEBP')`) to emit WebP files; falls back cleanly to original formats when WebP is unavailable.
- Existing HTML `<img>` tags hardcoded inside post content with old extensions will only change if `bulk_webp_subsizes` is turned on (default `no` prevents broken URLs).

## Changelog
- 0.1.0 — 2026-11-20 — Extracted `Support\ImageProcessor` and implemented `ImageOptimizer` (native upload pipeline, ERP-safe in-place compression, Action Scheduler cursor bulk runner with dry-run scan, admin UI, tests, and `es_AR` translations).
- 0.2.0 — 2026-11-20 — Review fixes: **in-place saves force the requested MIME** (`ImageProcessor::save_with_exif_policy()` neutralizes `image_editor_output_format`; before, with "Convert new JPEG sub-sizes to WebP" on, a bulk run could rename WebP bytes to the original `.jpg` URL) and any output with another MIME/path is discarded (`format_changed`); re-encodes are kept only when resized or at least 3% smaller (`ImageProcessor::worth_keeping()`, avoids generation loss); new setting `bulk_resize` (default **no**): bulk runs only recompress, so wide banners/theme images keep their resolution; `BulkRunner` re-reads the job straight from the DB before saving (a Cancel sent meanwhile is never overwritten), stops a batch after a 20 s time budget and continues in the next one (cursor), lock TTL 300 s; job status shown translated.
