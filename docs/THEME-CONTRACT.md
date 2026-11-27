# Theme contract (engine / skin)

Contract version: **1.1** (`Motored\Core\Api::CONTRACT`; guarded by `tests/Unit/Support/ThemeContractTest.php`).

Motored is **one product in two packages**:

| Package | Role | Owns |
|---|---|---|
| `plugins/motored-core` | **Engine** | Data, rules, queries, cache, queues, REST/wc-ajax, emails, semantic markup (BEM classes), behavior JS, *structural* CSS |
| `themes/flatsome` (display name "Motored") | **Skin** | Colors, type, radii, shadows, spacing rhythm, page layout, header/footer, where each engine component is placed, theme-specific JS |

They talk **only** through the surface listed below. Anything not listed here is private to its package and
may change without notice. This is what stops one package from overwriting the other: the plugin never ships
the brand look, so the theme never has to fight it with `!important`.

## Rules

1. **The plugin works under any theme.** Without our theme it renders with neutral fallbacks (inherits font
   and text color, `currentColor` lines). It never names, detects or calls a theme (no `Flatsome`, `.mfp-*`,
   `--mt-*`, `.header` …).
2. **The plugin's storefront CSS is structural.** Layout, states (`[hidden]`, `.is-active`, `.is-loading`),
   positioning and accessibility (focus outlines). Every visual value reads a public token below. Color
   literals appear only as `var(--motored-…, fallback)`. No `!important`: win with specificity.
3. **The theme styles through tokens first.** It sets the input tokens on `:root`. When a token is not
   enough, it styles the public BEM classes with **contextual** selectors (`.header .motored-search--smart …`)
   and normal specificity. It never uses `!important` against plugin classes.
4. **The theme reads no plugin internals.** No `get_option( 'motored_*' )`, no plugin localStorage keys, no
   private JS globals (`motoredGarageApi`, `motoredCompatCfg`…), no plugin classes other than `Motored\Core\Api`.
5. **The theme degrades.** With the plugin off, every integration point checks first
   (`class_exists( '\Motored\Core\Api' )`, `shortcode_exists()`, `window.Motored && Motored.garage`) and falls
   back to plain WordPress/WooCommerce output.
6. **Changes to this surface update both packages in the same piece of work**, and this file. Additions bump
   the minor version; removals or renames bump the major and need a theme change first.

## 1. CSS tokens (theme → plugin)

Input tokens are set by the theme on `:root`. The plugin reads them on its component roots, where it derives
private short names (`--motored-accent`, `--motored-radius`, `--motored-ig-*` …) that themes never set.

| Token | Used for | Plugin fallback |
|---|---|---|
| `--motored-accent-color` | Primary actions, active states, drawer header | `currentColor` |
| `--motored-on-accent-color` | Text/icons on the accent | `#fff` |
| `--motored-surface-color` | Background of inline components (search, filters, bars) | `transparent` |
| `--motored-raised-color` | Drawers, dialogs, dropdowns, cards above the page | `Canvas` |
| `--motored-ink-color` | Text on raised surfaces | `CanvasText` |
| `--motored-muted-color` | Secondary text | 72% of `currentColor` |
| `--motored-line-color` | Borders and separators | 20% of `currentColor` |
| `--motored-soft-color` | Tinted bands, hover rows, notices | 4% of `currentColor` |
| `--motored-link-color` | Text links inside components | `currentColor` |
| `--motored-highlight-color` | Hover/focus on the accent (e.g. drawer close) | on-accent |
| `--motored-rating-color` | Review stars | `currentColor` |
| `--motored-ok-color` / `--motored-danger-color` | Fits / does not fit, success / error | green / red |
| `--motored-focus-color` | Keyboard focus outline | accent |
| `--motored-radius-size` | Corner radius of boxes and fields | `8px` |
| `--motored-pill-radius-size` | Chips, tabs, badges | `999px` |
| `--motored-shadow-color` | Color of every elevation shadow (`transparent` = flat) | `rgb(0 0 0 / 14%)` |
| `--motored-backdrop-color` | `::backdrop` of drawers and dialogs | dark translucent |
| `--motored-scrim-color` / `--motored-on-scrim-color` | Labels over photos | dark translucent / `#fff` |
| `--motored-media-color` | Stage behind photos in the lightbox | `#111` |

The optional plugin setting "accent color" prints `:where(:root){--motored-accent-color:…}` (zero
specificity), so a theme value always wins.

## 2. Markup (plugin → theme)

- **Shortcodes:** `[motored_buscador]` (`layout="smart|split|…"`), `[motored_filtros]`,
  `[motored_compat_selector]`, `[motored_compat_list]`, `[motored_mi_garage]`, `[motored_moto]`,
  `[motored_medida]`, `[motored_bike_hub]`, `[motored_payment_plans]`, `[motored_install_gallery]`.
  The theme exposes them in UX Builder (`inc/builder/shortcodes/motored.php`).
- **BEM classes are public API.** Block roots: `.motored-search`, `.motored-filters`, `.motored-compat-*`,
  `.motored-garage-*`, `.motored-bike-*`, `.motored-notify-*`, `.motored-plans`, `.motored-plans-dialog`,
  `.motored-install-gallery`, `.motored-ig-*`, `.motored-suggest__*`, `.motored-trending__*`. Renaming one
  is a breaking change.
- **Data attributes:** `[data-motored-garage-open]` on any link opens the garage drawer (its `href` is the
  no-JS fallback); `[data-motored-filters]` marks the filter sidebar.
- **E-mails** use `wc_get_template_html()`, so the theme can override them in `woocommerce/emails/motored-*.php`.

## 3. PHP (plugin → theme)

| Surface | Meaning |
|---|---|
| `\Motored\Core\Api::CONTRACT`, `Api::supports( '1' )` | Contract version check |
| `\Motored\Core\Api::garage_url()` | Mi Garage URL for the current visitor |
| `do_action( 'motored_garage_trigger' )` | The theme fires it where it renders a garage link; the plugin mounts the drawer once (not on checkout) |
| `MOTORED_CORE_VERSION` | Plugin is active |

## 4. JavaScript (both directions)

| Surface | Direction | Meaning |
|---|---|---|
| `window.Motored.garage.version` | plugin → theme | `1` |
| `window.Motored.garage.active()` | plugin → theme | `{ slug, label }` of the active motorcycle, or `null` |
| `window.Motored.garage.bikes()` | plugin → theme | `[{ slug, label }]` |
| `document` event `motored:garage` | plugin → theme | The garage changed; read it again through the API |
| `document` event `motored:filters-updated` | plugin → theme | The catalog was replaced by AJAX. `detail.url`, `detail.root` (new grid). The theme re-attaches its behaviors on `root` and closes its off-canvas filter panel |

The garage script loads on every page that fires `motored_garage_trigger`, except checkout (performance
budget). There `window.Motored` is absent and the theme shows its empty state.

## Not part of the contract (private)

Option names, table names, REST routes, localStorage/sessionStorage keys, `window.motoredGarage*`,
`window.motoredCompatCfg`, `window.motoredUi`, private CSS custom properties, PHP classes other than `Api`.

## Planned (not implemented yet)

- Theme-overridable storefront templates (`locate_template( 'motored/<file>.php' )`, as WooCommerce does).
  Today storefront markup is built in PHP renderers; markup changes go through the plugin.
