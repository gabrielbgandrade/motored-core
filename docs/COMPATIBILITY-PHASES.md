# Compatibility + Mi Garage — Implementation Phases

> Concise implementation roadmap for the `Compatibility` module (`Motored\Core\Modules\Compatibility`).

| Phase | Scope | Status |
|---|---|---|
| **1** | Configuration (`Config`), tire size parser (`SizeParser`), ETRTO specs & attribute schema (`TireSpecs`), WooCommerce attribute adapter (`AttributeHooks`) and two-step global attribute/term installer (`AttributeInstaller`). | implemented |
| **2** | Schema migrations (`CompatibilityMigration` — 7 `motored_compat_*` tables, `product_id` PK without `variation_id`), persistent-only Redis cache wrapper (`CompatCache`), CRUD repositories (`CatalogRepository`, `ProductIndexRepository`), ERP-safe product index sync + CLI commands (`ProductIndexSync`, `CliCommands`). | implemented |
| **3** | Admin interface: WooCommerce > Settings > Motored > Compatibilidad, `Productos > Compatibilidad` (Brands, Models, Versions/Fitments, Sizes, Directed Equivalences, Import/Export, Invalid Sizes Report) using shared `Admin\Ui` + `motored-admin.css`, plus simple-product `_motored_full_size` meta field. | implemented |
| **4** | JSON Import & Export (`JsonImporter`, `JsonExporter`, `ImportRunner`): schema v1 validation, 2 MB guard, dry-run preview (new/updated/unchanged/rejected/suspicious/overlaps), synchronous or Action Scheduler batching (50 versions/batch), draft-by-default safety. | implemented |
| **5** | Matching engine (`MatchEngine` — 1-hop directed equivalences, optional strict specs, kit dual-axle verification), public/garage REST API (`RestController`), selector shortcode (`[motored_compat_selector]`), `?moto=` + `&pos=` filtering (`woocommerce_product_query`, `query_loop_block_query_vars`, `wp_robots` `noindex`, active bike bar), and the 3 product tabs (`Descripción`, `Información técnica`, lazy-loaded `Compatibilidad`). | implemented |
| **6** | Mi Garage (`GarageRepository`, `GarageEndpoint` My Account `mi-garage`, `Privacy` exporter/eraser) + 3 vanilla `defer` scripts < 3 KB (`compat-selector.js`, `compat-garage.js`, `compat-tab.js`) with `data-motored-fit` client-side evaluation (0 REST calls on normal browsing, 12 h TTL revalidation, logout cleanup). | implemented |
| **7** | Optional automatic dimension pre-fill (`ancho`, `perfil`, `rodado` for single tires only when empty, recursion-guarded, never on kits). | implemented |
