# Motored Core

Base WordPress/WooCommerce plugin for **Motored Neumáticos** (Argentina). It provides the shared
foundation (lazy module loader, settings tab, logging, cache and migration helpers, es_AR i18n) and
the functional modules: WhatsApp transport (WAHA), order notifications, recovery (abandoned carts
and unpaid orders), review photos, review requests and payment plans.

- Requires WordPress 6.8+, PHP 8.1+ (the code stays compatible with 8.3) and WooCommerce active.
- No Composer autoload, jQuery or third-party libraries at runtime: `vendor/` is development-only.

## Requirements (development)

| Tool       | Version | Needed for                                              |
| ---------- | ------- | ------------------------------------------------------- |
| PHP CLI    | 8.2+    | locked development tools; use 8.3 for the local site     |
| Composer   | 2.x     | dev dependencies (phpcs, PHPStan, PHPUnit, WP stubs)    |
| Git        | any     | cloning and contributing                                |
| WordPress  | 6.8+    | running the plugin (not needed for unit tests)          |
| WooCommerce| current | plugin dependency (HPOS-compatible)                     |

Optional: `zip` for `bin/build-zip.sh`; GNU gettext (`xgettext`, `msgmerge`, `msgfmt`) or WP-CLI for
`bin/i18n.sh`; Redis object cache; a WAHA server for WhatsApp messages.

## WordPress Studio on Windows

Create a site with PHP 8.3, install WooCommerce and create a directory junction from
`<Studio site>/wp-content/plugins/motored-core` to this checkout. Run this from the repository root:

```powershell
$studioSite = 'C:\path\to\studio-site'
New-Item -ItemType Junction -Path (Join-Path $studioSite 'wp-content\plugins\motored-core') -Target (Get-Location).Path
```

Activate Motored Core in the local dashboard. Reload after edits and disable the browser cache
while debugging CSS or JavaScript. Use the Studio database for local development and run schema,
queue, Redis and HPOS checks on a production-like installation.

## Checkout and data retention

PaymentPlans supports classic checkout. Motored Core declares Cart and Checkout Blocks incompatible
while the classic-only feature ships in the plugin.

Deactivation cancels the Motored Action Scheduler group and preserves data. Uninstall also preserves
data by default. Enabling **Delete data on uninstall** removes Motored options, custom tables, cached
transients and selected metadata. WooCommerce order history and attributes are preserved.
See `uninstall.php` for the exact list.

## Setup on a new machine

```bash
# 1. Clone the repository (HTTPS; use git@github.com:... if you have an SSH key)
git clone https://github.com/gabrielbgandrade/motored-core.git
cd motored-core

# 2. Install development dependencies (creates vendor/; never committed)
composer install

# 3. Check that the toolchain works
composer check
```

`vendor/` and `composer.lock` handling: `composer.lock` is committed, so `composer install` always
installs the same versions. Run `composer update` only when you intend to bump them.

### Install the plugin in a local WordPress

```bash
# Either symlink the repository into an existing WordPress install...
ln -s "$PWD" /path/to/wordpress/wp-content/plugins/motored-core

# ...or build the runtime-only zip and upload it in wp-admin > Plugins > Add New > Upload
bin/build-zip.sh          # writes build/motored-core.zip
```

Then activate **WooCommerce** and **Motored Core**, and open **WooCommerce > Settings > Motored**.
The zip contains runtime files only (no `vendor/`, tests, docs or skills).

### Keeping the copy up to date

```bash
git pull            # on main
composer install    # only downloads when composer.lock changed
```

## Development commands

```bash
composer lint       # phpcs (WordPress-Extra + Docs)
composer stan       # PHPStan level 6
composer test       # PHPUnit (Brain Monkey; no WordPress required)
composer check      # lint + stan + test

bin/build-zip.sh    # runtime-only motored-core.zip in ./build
bin/i18n.sh         # regenerate .pot, merge es_AR and compile .mo
```

## Documentation

Read [AGENTS.md](AGENTS.md) first for the non-negotiable rules and conventions, then:

- `docs/ARCHITECTURE.md` — layers, lifecycle, contexts, cache, queues.
- `docs/PROJECT.md` — decisions, roadmap and review log.
- `docs/FEATURES.md` — module behavior in Portuguese (owner-facing guide).
- `docs/TESTING.md` — what is covered by automated tests and what needs staging.
- `docs/VERIFY.md` — unverified assumptions.
- `docs/STAGING-CHECKLIST.md` — manual checks on a real store.

Every module has its own `README.md` under `src/Modules/<Name>/` and a skill under
`.claude/skills/`.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
