# Motored Core

Base plugin for the Motored Neumáticos WooCommerce store (Argentina). Stage 1 provides only the
foundation: lazy module loader, settings tab, logging, cache and migration helpers, i18n (es_AR).
No functional modules, tables or external calls yet.

- Requires WordPress 6.8+, PHP 8.1+ (code compatible with 8.3), WooCommerce (active).
- No Composer, jQuery or third-party libraries at runtime.

## Development

```bash
composer install
composer check      # phpcs + phpstan (level 6) + phpunit
bin/build-zip.sh    # runtime-only motored-core.zip in ./build
bin/i18n.sh         # regenerate .pot / merge es_AR / compile .mo
```

Agents and contributors: read [AGENTS.md](AGENTS.md) first, then `docs/ARCHITECTURE.md`.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
