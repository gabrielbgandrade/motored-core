# Theme contract (engine / skin)

Contract version: **1.10** (`Motored\Core\Api::CONTRACT`; guarded by `tests/Unit/Support/ThemeContractTest.php`).

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

- **Shortcodes:** `[motored_buscador]` (`layout="smart|split|…"`), `[motored_filtros]` (`layout="quick"` (1.4): the
  bar above the grid with the "In stock" toggle and the quick filters, `.motored-quick*`),
  `[motored_compat_selector]`, `[motored_compat_list]`, `[motored_mi_garage]`, `[motored_moto]`,
  `[motored_medida]`, `[motored_bike_hub]`, `[motored_payment_plans]`, `[motored_install_gallery]`.
  The theme exposes them in UX Builder (`inc/builder/shortcodes/motored.php`).
- **BEM classes are public API.** Block roots: `.motored-search`, `.motored-filters`, `.motored-compat-*`,
  `.motored-garage-*`, `.motored-bike-*`, `.motored-notify-*`, `.motored-plans`, `.motored-plans-dialog`,
  `.motored-install-gallery`, `.motored-ig-*`, `.motored-plans--loop` / `.motored-plans-cash*` (1.5, payment block on
  product cards: list line, transfer price, "See payment methods" button; 1.8: the product page `.motored-plans`
  also prints `.motored-plans-line__amount` and `.motored-plans-cash` after the line, kept in sync on variations), `.motored-suggest__*`, `.motored-trending__*`. Renaming one
  is a breaking change.
- **Product banner (1.10):** at the top of the product summary (priority 3), `.motored-promo-banner` holds
  `__link` (only with a link) > `__img` (full-size image, animated GIFs keep moving) and, with an end date and the
  countdown on, `__countdown[data-motored-countdown="<ms>"]` > `__ends` ("Termina en") + four `__unit` >
  `__num[data-u="d|h|m|s"]` + `__lbl`. The banner gets `[hidden]` when the countdown reaches zero. Optional
  token: none (the countdown band uses `--motored-soft-color`).
- **Data attributes:** `[data-motored-garage-open]` on any link opens the garage drawer (its `href` is the
  no-JS fallback); `[data-motored-filters]` marks the filter sidebar; `[data-motored-swap="key"]` (1.4) on any
  element outside the grid that depends on the active filters (catalog title, sort form, the quick bar): after an
  AJAX filter change the plugin replaces it with the element of the same key in the new page.
- **Compatibility slot (1.6):** an element with `[data-motored-fit-slot]` inside a loop product (`.product`) gets
  the compact badge instead of the text line: `.motored-compat-badge--icon` (with `--ok` / `--no`) holding the
  plugin bike icon (`__icon`) and a tooltip (`__tip`) shown on hover, keyboard focus or tap (`.is-open`; tap
  elsewhere or Escape closes it). The text is also the badge's `aria-label`. Without a slot nothing changes.
- **Catalog URLs:** native WooCommerce filters plus `?oferta=1`, `?stock=1` (1.4, only products in stock),
  `?moto=`, `&pos=`, `?kit_medida=`.
- **E-mails** use `wc_get_template_html()`, so the theme can override them in `woocommerce/emails/motored-*.php`.

## 3. PHP (plugin → theme)

| Surface | Meaning |
|---|---|
| `\Motored\Core\Api::CONTRACT`, `Api::supports( '1' )` | Contract version check |
| `\Motored\Core\Api::garage_url()` | Mi Garage URL for the current visitor |
| `\Motored\Core\Api::tire_card( $product )` | Technical card title of a tire (1.5): `size` "160/60R17 69H TL" (kits "120/70ZR17 + 160/60ZR17"), `model` "MT60 RS", `kit`; 1.9: `load` `{code:"69", kg:325}` and `speed` `{code:"H", kmh:210}` (empty arrays for kits or missing data); empty array for non-tires. The product name never changes |
| `\Motored\Core\Api::active_bike_bar()` | Catalog motorcycle bar (1.7): with `?moto=` the `.motored-compat-bar` (`__actions` with `__change` "Elegir otra moto" opening the garage and `__clear` "Quitar moto", `__title`, `__count`, `__sizes`, `__positions` with `__tab`, `__switch`), otherwise the cache-safe `.motored-garage-cta` mount (`__lead`, `__link`); '' when it does not apply. Printed once per request: a theme that prints it above the catalog takes it from its default place above the grid. AJAX filters replace `.motored-compat-bar` wherever it sits, so do not wrap it in `[data-motored-swap]` |
| `\Motored\Core\Api::active_filter_labels()` | Values of the active catalog filters for headings (1.4), e.g. `["Michelin", "Rodado 17", "En stock"]` |
| `do_action( 'motored_garage_trigger' )` | The theme fires it where it renders a garage link; the plugin mounts the drawer once (not on checkout) |
| `add_theme_support( 'motored-garage-modal' )` | Theme → plugin (1.3): the garage opens as a centered dialog instead of the side drawer. Same `#motored-garage-drawer` dialog with `.motored-garage-drawer--modal`: close button first, `__header` with `h2 > .motored-garage-drawer__icon` (empty span the theme fills) and `.motored-garage-drawer__lead`; `.motored-garage-page--modal` shows `.motored-garage-current` (`__links`, `__link`, `__sep`, `__remove`, `__name`, `__primary`), `.motored-garage-others` (`__title`, `__list`, `__item`, `__name`, `__use`), `.motored-garage-pick` (the selector, `__cancel`) and `.motored-garage-guest--inline` (`__title`, `__login`) |
| `MOTORED_CORE_VERSION` | Plugin is active |

## 4. JavaScript (both directions)

| Surface | Direction | Meaning |
|---|---|---|
| `window.Motored.garage.version` | plugin → theme | `1` |
| `window.Motored.garage.active()` | plugin → theme | `{ slug, label }` of the active motorcycle, or `null` |
| `window.Motored.garage.bikes()` | plugin → theme | `[{ slug, label }]` |
| `document` event `motored:garage` | plugin → theme | The garage changed; read it again through the API |
| `document` event `motored:filters-loading` | plugin → theme | An AJAX catalog swap started (`detail.url`). `motored:filters-updated` follows, or a full page load to `detail.url` if the request fails. The theme may show a progress indicator (1.2) |
| `document` event `motored:filters-updated` | plugin → theme | The catalog was replaced by AJAX. `detail.url`, `detail.root` (new grid). The theme re-attaches its behaviors on `root` and closes its off-canvas filter panel |

The garage script loads on every page that fires `motored_garage_trigger`, except checkout (performance
budget). There `window.Motored` is absent and the theme shows its empty state.

## Not part of the contract (private)

Option names, table names, REST routes, localStorage/sessionStorage keys, `window.motoredGarage*`,
`window.motoredCompatCfg`, `window.motoredUi`, private CSS custom properties, PHP classes other than `Api`.

## Planned (not implemented yet)

- Theme-overridable storefront templates (`locate_template( 'motored/<file>.php' )`, as WooCommerce does).
  Today storefront markup is built in PHP renderers; markup changes go through the plugin.
