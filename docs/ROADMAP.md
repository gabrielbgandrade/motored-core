# Roadmap

| Stage | Scope | Status |
|-------|-------|--------|
| 1 | Base plugin: skeleton, quality tools, module infrastructure, settings tab, i18n, docs, skills | **done** (code, tests, docs, skills; staging validation and independent review pending — see REVIEW-LOG / VERIFY) |
| 2 | WAHA module: client, session health, send text via Action Scheduler, masked logging | planned |
| 3 | Notifications module: order/status messages, templates (text only), opt-out link | planned |
| 4 | Recovery module (data model, event history, tables/migrations) | planned |
| 5 | Recovery: abandoned carts + pending/failed orders flows, coupons, Mercado Pago return handling | planned |
| 6 | Recovery admin screen, privacy exporter/eraser, reporting | planned |
| 7 | Hardening: load/perf validation on staging, docs/skills final pass, release | planned |

Each module stage must deliver: module README, module skill (`motored-module-<name>`), VERIFY.md rows,
tests, es_AR strings.
