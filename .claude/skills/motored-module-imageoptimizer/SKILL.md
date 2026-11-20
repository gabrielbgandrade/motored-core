---
name: motored-module-imageoptimizer
description: Rules for ImageOptimizer and shared Support\ImageProcessor (upload-time optimization, ERP-safe in-place bulk compression via Action Scheduler, dry-run scans). Use when touching src/Modules/ImageOptimizer or src/Support/ImageProcessor.php.
---

# Module ImageOptimizer
Read `src/Modules/ImageOptimizer/README.md` first (flows, option keys, ERP URL safety, hooks).

- Public storefront budget: `ImageOptimizerModule::should_load()` MUST return `! $context->is_frontend()` (0 hooks, 0 queries, 0 assets on public pages). Do NOT read `Config` inside `register()` or `UploadHooks::register()`.
- Always route image resizing, EXIF stripping, WebP checks, and editor loading through `Support\ImageProcessor` instead of duplicating `WP_Image_Editor` code in modules.
- Preserve ERP compatibility: `bulk_webp_subsizes` defaults to `no` and `optimize_in_place()` keeps file names/URLs intact and never replaces an original image with a larger output unless resized down.
- In-place saves MUST keep the original MIME/path: `ImageProcessor::save_with_exif_policy()` neutralizes `image_editor_output_format` and `optimize_in_place()` discards any other output (`format_changed`). Keep only resized or >= 3% smaller results (`worth_keeping()`). Bulk resize is opt-in (`bulk_resize`, default no).
- `BulkRunner` re-reads the job from the DB (uncached) before saving/scheduling, has a 20 s per-batch time budget and LOCK_TTL 300 s.
- `BulkRunner` uses `motored_image_opt_batch` (registered in `Plugin::ACTION_HOOKS` and unscheduled in `uninstall.php`), cursor-based pagination, `Cache` lock (`img_opt`), and `BulkRunner::$test_scheduler` in unit tests (never define global `as_*` stubs via Brain Monkey in PHPUnit).
- Cleanup contract: `uninstall.php` deletes `motored_image_optimizer`, `motored_image_opt_job`, `motored_image_opt_saved` (option prefix/list), unschedules `motored_image_opt_batch`, and clears `_motored_img_opt` post meta when `delete_data_on_uninstall` is enabled.
- Admin UI under `Admin\SectionRegistry` (`images`, prio 65) uses shared `.motored-table`/`.motored-box` classes via `Admin\Ui` only (no inline `style=`, custom CSS, or colors; enforced by `SharedAdminUiTest`).
- Strings: English source + complete `es_AR` voseo in `.pot/.po/.mo`. Tests: `tests/Unit/Modules/ImageOptimizer` + `tests/Unit/Support/ImageProcessorTest.php`.
- After changes: run `composer check` + `motored-review`; update `VERIFY.md`, `STAGING-CHECKLIST.md`, and `PROJECT.md`.
