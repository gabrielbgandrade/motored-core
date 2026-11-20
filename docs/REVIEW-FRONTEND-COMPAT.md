# Revisão — Compatibility + Mi Garage + CatalogFilters (frontend da loja)

Data: 2026-11-20 · Escopo: alterações não commitadas (working tree sobre `2e1f414`), foco no que o cliente vê:
banner da ficha de produto, badges no loop, barra `?moto=`, aba Compatibilidade, Mi Garage (inclui "segunda moto"),
buscador `[motored_buscador]` e filtros `[motored_filtros]`.

> **Status (2026-11-20, mesma data):** todas as fases (0–5) implementadas — exceto os commits da Fase 0, que ficam
> para o usuário. Veja o changelog 0.6.0 no README de Compatibility, o changelog do README de CatalogFilters, a
> entrada do `docs/PROJECT.md` e a seção "Storefront review fixes (2026-11-20)" do `docs/STAGING-CHECKLIST.md`.
> Achado extra durante a implementação: a cor de destaque (`accent_color`) nunca funcionava, porque cada componente
> redefinia `--motored-accent: currentColor`; e os selos do loop ficavam sem CSS nas páginas de loja comuns.
> Ambos foram corrigidos.

**Limitações:** `composer check` NÃO foi executado (não há PHP/Composer instalado neste Windows). Os 6 JS passaram em
`node --check`. Nada foi testado em navegador contra um WordPress real — os itens marcados "confirmar em staging" vêm
da leitura do código.

Severidade: **Alta** = cliente vê informação errada ou fica sem saída · **Média** = UX/robustez/perf · **Baixa** = acabamento.

---

## 1. Problemas encontrados

### Alta

| # | Problema | Onde | Por que importa |
|---|----------|------|-----------------|
| A1 | **Barra da moto some quando não há resultados.** A barra (`render_active_bike_bar`) está pendurada em `woocommerce_before_shop_loop`, que o WooCommerce NÃO dispara quando a query volta vazia (`post__in = [0]`). A mensagem "Todavía no tenemos cubiertas para esta moto…" e o link "Quitar filtro" nunca aparecem — o cliente vê só "No se encontraron productos", sem saber que tem um filtro de moto ativo nem como sair dele. | `src/Integration/WooCommerce/CompatibilityHooks.php:40`, `src/Modules/Compatibility/Storefront.php:425` | É exatamente o caso em que o cliente mais precisa de ajuda. |
| A2 | **Pneu único que serve na dianteira E traseira aparece como "Kit completo".** Quando um pneu (não kit) casa com os dois eixos de uma moto (comum em 2.75-18, 3.00-17 etc.), `positions = [front, rear]` e tanto o PHP quanto o JS rotulam `>= 2 posições` como "Kit completo". | `CompatListRenderer.php:175`, `assets/js/compat-tab.js` (linha `T.kit`) | Informação errada: o cliente pode achar que está comprando 2 pneus. |
| A3 | **Mensagens contraditórias na mesma ficha.** Com moto ativa incompatível e `showIncomp = false`, o banner mostra "¿Le queda a tu moto? / Elegí tu moto" (como se não houvesse moto escolhida), enquanto a aba Compatibilidade diz "No coincide con tu X". | `assets/js/compat-garage.js:267`, `compat-tab.js` (`drawMine`) | Confunde e parece bug; o cliente já escolheu a moto. |
| A4 | **Rótulos mudam ao expandir a lista da aba.** O HTML do servidor usa "Original / Alternativa / Equivalente aprox. (X)"; ao clicar em "Ver todas" o JS re-renderiza com `T.oem/T.alt/T.equiv` ("Medida original", "Medida alternativa", "Equivalente (X)") e ordena marcas com `sort()` sensível a maiúsculas (PHP usa natural/case-insensitive). | `compat-tab.js` (`hydrateFull`) vs `CompatListRenderer::format_entry()` | A lista "pula" e muda de texto/ordem na frente do cliente. |
| A5 | **Loop/barra dependem de hooks clássicos.** Badges usam `woocommerce_after_shop_loop_item_title` e a barra `woocommerce_before_shop_loop`; em templates de bloco (Product Collection / block themes) esses hooks não disparam → sem badges e sem barra, embora o filtro `?moto=` funcione. A regra 9 do AGENTS.md exige classic **e** block. | `CompatibilityHooks.php:40-41` | Se o tema mudar para bloco, a feature "quebra em silêncio". Confirmar em staging e registrar em `VERIFY.md`. |

### Média

| # | Problema | Onde |
|---|----------|------|
| M1 | **`merge` da garagem sem limite de entrada.** `post_garage` valida cada moto do array enviado (`MatchEngine::bike_profile` por item) antes de cortar em `max_garage_bikes`. Um usuário logado pode mandar milhares de slugs → milhares de lookups numa request. Cortar o array (ex.: `array_slice($bikes, 0, max*2)`) antes do loop. | `RestController.php:272-286` |
| M2 | **Lógica de compatibilidade duplicada PHP ↔ JS.** `ev()/chk()` em `compat-garage.js` reimplementam o `MatchEngine` (prioridade oem/alt/equiv, kit, strict, carga/velocidade). Qualquer ajuste no PHP pode deixar o badge do loop dizendo "Compatível" num produto que o filtro `?moto=` não lista (ou vice-versa). Não há teste que garanta paridade. | `compat-garage.js:64-106` vs `MatchEngine.php` |
| M3 | **Buscador da home faz 2 requests REST no carregamento**, mesmo com a aba escondida: `/compat/brands` e `/filters/sizes/{ver}` (este só na 1ª visita, depois localStorage). O `compat-selector.js` já tem modo `data-lazy`; o buscador não. | `catalog-search.js:158,267` |
| M4 | **Contagens dos filtros divergem do resultado real** em: (a) categoria pai — o snapshot só guarda a categoria direta do produto, mas o WooCommerce inclui subcategorias no arquivo pai; (b) busca `?s=` e arquivos de marca/atributo — facets ignoram o termo/arquivo; (c) `only_in_stock = yes` no módulo com "ocultar sem estoque" desligado no WC → sidebar conta menos que a grade. | `FacetEngine.php:258`, `FilterRenderer.php:267` |
| M5 | **Filtros no desktop recarregam a página 700 ms após cada clique.** Seleção múltipla vira várias recargas, perde scroll e estado do acordeão. | `catalog-filters.js` (`setTimeout(go,700)`) |
| M6 | **Lista da aba falha em silêncio.** Se o fetch de `/compat/product/{id}/motos` falha, o botão "Ver todas" não faz nada e nenhuma mensagem aparece (a string `loadErr` existe mas não é usada). O botão também não muda para "Ver menos". | `compat-tab.js` (`hydrateFull`) |
| M7 | **Garagem cheia descarta moto antiga sem avisar** (JS `slice(-maxBikes)` e PHP `array_slice(-limit)`). Além disso, JS move a moto re-adicionada para o fim e o PHP não → a ordem entre dispositivos diverge e "qual moto cai" é diferente no cliente e no servidor. | `compat-garage.js:144-150`, `GarageRepository::merge()` |
| M8 | **Remover moto na Mi Garage é imediato**, sem confirmação nem "desfazer". | `compat-garage-page.js` (botão `remove`) |
| M9 | **Leitura frágil do localStorage.** `rd()` só valida JSON; se o objeto existir sem `bikes`/`profiles` (versão antiga, edição manual, outra aba), `s.bikes.filter`/`delete nx.profiles[...]` lançam erro e o script inteiro para (sem badges). Normalizar o formato ao ler. | `compat-garage.js:32-38`, `compat-garage-page.js` |
| M10 | **Acessibilidade do buscador inteligente e das abas.** `role="listbox"` sem `role="option"` nos itens, input sem `role="combobox"`/`aria-activedescendant`, sem navegação por setas; abas `role="tab"` sem setas/`tabindex` roving; botões de filtro/acordeão/abas/chips sem estilo `:focus-visible` próprio (dependem do tema). | `SearchShortcode.php`, `catalog-search.js:392-466`, `catalog-filters.css` |
| M11 | **Enter na busca inteligente vai para a busca nativa do WC**, que não entende "120/70-17" (medida está em atributos) → "sem resultados", mesmo com sugestão exata disponível. | `catalog-search.js` (submit nativo) |
| M12 | **Normalização de texto fraca** na sugestão e na busca da aba: sem remover acentos, hífens ou espaços ("cb 500" ≠ "cb500", "ybr-125" ≠ "ybr 125"). | `CatalogFilters/RestController::normalize_query()`, `compat-tab.js` |

### Baixa

| # | Problema | Onde |
|---|----------|------|
| B1 | Código JS escrito "minificado à mão" (`compat-tab.js`, `catalog-filters.js`, nomes de 1–2 letras em `compat-garage.js`) — difícil de revisar e manter; não há orçamento de tamanho fora do checkout. | `assets/js/*` |
| B2 | Cascata marca→modelo→ano→versão duplicada em `compat-selector.js` e `catalog-search.js` (~200 linhas cada). Correções precisam ser feitas 2×. | ambos |
| B3 | Strings i18n enviadas e nunca usadas (`noSuggestions`, `searchMotos`, `noMotos`, `loadErr`, `selectUrl`) em toda página de loja/produto. | `Storefront::client_config()`, `StorefrontAssets` |
| B4 | Chaves antigas `motored_sizes_{ver}` acumulam no localStorage a cada invalidação do snapshot. | `catalog-search.js:7` |
| B5 | Botão de submit da medida: `!(rs.value \|\| (ws.value && rs.value))` = `!rs.value`; largura sozinha nunca habilita a busca (comentário diz o contrário). Decidir regra e simplificar. | `catalog-search.js:156` |
| B6 | Botões de posição "Delanteras/Traseras/Kits" aparecem mesmo quando aquela posição tem 0 produtos para a moto. | `Storefront::render_active_bike_bar()` |
| B7 | `filter_block_query_vars` ignora `post_type` em formato array (`['product']`). | `Storefront.php:292` |
| B8 | `catalog-filters.css` (16 KB, não minificado) carrega em toda ficha de produto com banner. | `StorefrontAssets::enqueue_css()` |
| B9 | Nonce REST de páginas abertas por >12–24 h expira; ações da garagem falham e revertem sem mensagem. | `compat-garage.js` (`api()`) |

---

## 2. Soluções (como corrigir)

**A1 — barra sem resultados**
- Em `CompatibilityHooks::register_display_hooks()` registrar o mesmo callback também em `woocommerce_no_products_found` (prioridade 5), com flag estática em `Storefront` para não imprimir duas vezes.
- Teste: `StorefrontTabsTest` (ou novo) garantindo que o hook é registrado e que a mensagem "empty" + link "Quitar filtro" saem quando `matching_product_ids()` é vazio.

**A2 — "Kit completo" errado**
- Rótulo deve vir do **tipo do produto**, não da contagem de eixos: `$data['position'] === TireSpecs::POS_KIT` → "Kit completo"; senão, 2 posições → "Delantera / Trasera".
- PHP: passar `$data['position']` para `format_entry()`. JS: usar `d.position` da resposta REST (já retornado em `get_product_motos`).
- Nova string `Front / Rear` em `.pot/.po/.mo` (es_AR: "Delantera / Trasera").

**A3 — contradição banner × aba**
- Decidir uma regra única. Recomendação: com moto ativa, **sempre** dizer a verdade ("No coincide con tu X" + medidas de fábrica + "Ver cubiertas compatibles" + "Cambiar moto"). Usar `showIncomp` só para os **badges do loop** (onde "não serve" polui a grade).
- Em `drawM()`, a condição vira `if (!pf) { caixa "Elegí tu moto" }`.

**A4 — rótulos na hidratação**
- Fazer o endpoint `/compat/product/{id}/motos` devolver o texto já formatado por `CompatListRenderer::format_entry()` (campo `text`) e o JS só montar `brand: text; text…`. Uma única fonte de verdade, sem i18n duplicado no JS.
- Ordenar no JS com `localeCompare(…, {sensitivity:'base', numeric:true})`, ou receber já agrupado/ordenado do servidor.

**A5 — block themes**
- Adicionar suporte via `render_block` para `woocommerce/product-template` / `core/post-title` dentro de Product Collection (injetar o `fit-marker`) e `woocommerce/product-collection` (barra antes). Isolar em `Integration/WooCommerce/CompatibilityHooks.php`.
- Antes de codar: validar em staging com tema de bloco e registrar a linha em `docs/VERIFY.md` (regra 8 — não inventar APIs).

**M1** — `$guest_bikes = array_slice( $guest_bikes, 0, Config::max_garage_bikes() * 2 );` antes do loop; idem para `bikes` não-array. Teste unitário com 1000 itens.

**M2 — paridade PHP/JS**
- Criar `tests/fixtures/compat-cases.json` (perfil da moto + fit do produto + resultado esperado: ok, posições, tipo, aviso).
- PHPUnit roda os casos contra `MatchEngine::evaluate…`; um script `node tests/js/compat-ev.test.js` (sem libs, só `assert`) roda os mesmos casos contra `ev()` exportado. Incluir no `composer check` via script se Node existir.

**M3 — lazy no buscador** — carregar marcas só no `focus`/`click` do select ou quando a aba "Por moto" ficar visível; sizes só quando a aba "Por medida" abrir (ou `IntersectionObserver` no layout `split`).

**M4 — contagens**
- (a) No snapshot, expandir `cats` com os ancestrais (`get_ancestors()` na construção do snapshot, que já é cacheado).
- (b) Em busca/arquivo de marca: ou esconder a contagem, ou restringir o universo com os IDs da query principal (`wp_list_pluck` do `$wp_query->posts` não basta por paginação; usar a query sem paginação com `fields => ids`, cacheada por hash de args).
- (c) Usar `woocommerce_hide_out_of_stock_items` como fonte única (remover a opção própria ou forçar coerência no admin).

**M5** — no desktop também usar o botão "Aplicar" (fixo/sticky no rodapé do painel) **ou** manter auto-aplicar mas com debounce maior (1,2 s) e preservar `scroll`/acordeões via `sessionStorage`. Recomendação: botão "Aplicar (N)" mostrando quantos filtros pendentes.

**M6** — no `catch` mostrar `T.loadErr` com botão "Reintentar"; alternar texto do toggle entre "Ver todas (N)" e "Ver menos" (nova string).

**M7** — política única "mais recente fica": no PHP, ao re-adicionar, mover para o fim (igual ao JS). Na UI, quando estiver no limite, mostrar aviso "Tu garage está llena: se reemplazará {moto mais antiga}".

**M8** — remover com "Moto eliminada · Deshacer" (toast 5 s; a chamada `DELETE` só sai depois do tempo, ou reverte com `add`).

**M9** — `rd()` retorna sempre `{active: string, bikes: array, profiles: object}` normalizado (filtra itens sem `slug`). Ouvir o evento `storage` para sincronizar abas abertas.

**M10** — padrão WAI-ARIA combobox: `role="combobox"` no input, `role="option"` + `id` nos itens, setas ↑/↓ movem `aria-activedescendant`, Enter abre o item ativo. Abas: setas ←/→, `tabindex="-1"` nas inativas. CSS: `:focus-visible { outline: 2px solid var(--motored-accent); outline-offset: 2px; }` para `.motored-opt`, `.motored-acc__btn`, `.motored-search__tab`, `.motored-chip`, chips da garagem.

**M11** — no submit: se a 1ª sugestão for `size` ou `moto` e o texto digitado for uma medida parseável / bater exato com o rótulo, navegar para `it.url`; senão, busca nativa.

**M12** — normalizar nos dois lados: minúsculas, `remove_accents()` (PHP) / `normalize('NFD').replace(/[̀-ͯ]/g,'')` (JS), e gerar no haystack também a versão sem espaços/hífens.

**Baixa** — B1/B2: extrair um `compat-cascade.js` compartilhado (cascata + cache de marcas) e reescrever os arquivos em estilo legível; se tamanho importar, minificar no `bin/build-zip.sh`. B3: remover chaves mortas. B4: ao gravar a versão nova, apagar outras chaves `motored_sizes_*`. B5: decidir (rodado obrigatório é razoável) e simplificar para `sb.disabled = !rs.value`. B6: usar `matching_product_ids($moto, $pos)` por posição (já memoizado) e esconder/desabilitar as vazias. B7: aceitar `in_array('product', (array) $post_type)`. B8: separar CSS do banner (pequeno) do CSS de filtros/buscador. B9: em 403 `rest_cookie_invalid_nonce`, buscar nonce novo ou mostrar "Recargá la página".

---

## 3. Melhorias sugeridas (produto/UX)

1. **CTA "Ver solo cubiertas para mi X" na loja** quando há moto ativa na garagem e a URL não tem `?moto=` (barra injetada pelo `compat-garage.js`, cache-safe). Hoje o cliente com moto salva vê a loja inteira e só badges.
2. **Trocar de moto direto na barra `?moto=`** (chips das motos salvas, igual ao picker da ficha) — completa a feature "segunda moto" fora da ficha.
3. **Badge do loop mais compacto** ("✓ Tu Tornado · Trasera") e o detalhe longo (equivalência, verificar índices) só no tooltip/ficha; hoje o texto é longo e quebra cards em mobile.
4. **Ordenação por relevância com `?moto=`**: OEM primeiro, depois alternativa, depois equivalente (hoje segue a ordenação padrão da loja).
5. **Sugestão de par**: na ficha de um pneu dianteiro compatível, link "Ver la trasera para tu X" (`?moto=X&pos=rear`).
6. **Selects com busca** para marca/modelo quando a lista passar de ~30 itens (datalist nativo, sem libs).
7. **Contadores no buscador por medida** ("120/70-17 (14)") — os dados já vêm em `sizes[].c`.
8. **Medição**: eventos `dataLayer` opcionais (moto escolhida, filtro aplicado, sugestão clicada) para saber o que o cliente usa — sem PII.

---

## 4. Plano de implementação em fases

Cada fase termina com: `composer check` verde, `.pot/.po/.mo` atualizados juntos, `motored-review`, entrada no
`docs/PROJECT.md` e roteiro do `docs/STAGING-CHECKLIST.md` executado em staging (tema clássico **e** de bloco a partir da Fase 3).

### Fase 0 — Preparação (½ dia)
- Commitar o estado atual em branch própria (há 50 arquivos modificados + não rastreados, incluindo InstallGallery, que é outro escopo — separar commits).
- Rodar `composer check` numa máquina com PHP 8.1+ e corrigir o que aparecer.
- Criar o fixture `compat-cases.json` (M2) com 15–20 casos reais da Motored — vira rede de segurança das fases seguintes.

### Fase 1 — Correções que o cliente vê (1–2 dias) · A1, A2, A3, A4, M6, M9
- Barra no `woocommerce_no_products_found`.
- Rótulo kit × dianteira/traseira (PHP + JS + i18n).
- Regra única do banner (moto ativa = sempre resposta honesta).
- Endpoint de motos devolvendo texto formatado; JS só monta.
- Erro/"Reintentar" e "Ver menos" na aba; `rd()` normalizado.
- Testes: `StorefrontTabsTest` (hooks + rótulos), `CompatListRenderer` (kit vs 2 eixos), casos JS do fixture.

### Fase 2 — Robustez e segurança (1 dia) · M1, M2, M7, B9, B7
- Limite no `merge`, política única de capacidade, aviso de garagem cheia.
- Paridade PHP/JS automatizada (script node opcional no `composer check`).
- Tratamento de nonce expirado; `post_type` array.

### Fase 3 — Filtros e busca coerentes (2–3 dias) · M4, M5, M11, M12, B5, B6, B4
- Ancestrais de categoria no snapshot; coerência de estoque; estratégia para busca/arquivos.
- Botão "Aplicar (N)" no desktop; preservar acordeões.
- Enter inteligente; normalização sem acento/espaço nos dois lados.
- Posições vazias ocultas na barra; limpeza de chaves antigas.
- Validar em staging com tema de bloco → iniciar A5 (registro no `VERIFY.md`).

### Fase 4 — Acessibilidade, performance e manutenção (2 dias) · M3, M10, A5, B1, B2, B3, B8
- Lazy load no buscador; CSS do banner separado.
- Combobox/abas acessíveis + `:focus-visible`.
- Suporte block theme (badges/barra) via `render_block`, isolado em `Integration/`.
- `compat-cascade.js` compartilhado, JS legível, remoção de strings mortas.

### Fase 5 — Melhorias de conversão (3–4 dias) · Seção 3
- Ordem sugerida: (1) CTA "solo para mi moto" → (2) trocar moto na barra → (3) badge compacto → (4) ordenação OEM primeiro → (5) sugestão de par → (6)–(8).
- Cada item com flag em `motored_compat` (`autoload=false`) para ligar/desligar sem deploy.

---

## 5. O que está bem feito (manter)
- HTML 100% cache-safe: marcador de fit estático + hidratação no cliente; nonce só para logado.
- Prime do índice em 1 query por loop (`the_posts`) e memo por request em `matching_product_ids`.
- `noindex, follow` em `?moto=` e filtros; `post__in` intersectado sem sobrescrever outros filtros.
- Tudo com `textContent` no JS (sem XSS), `esc_*` no PHP, REST pública com `Cache-Control` + ETag.
- Cascatas com proteção contra respostas fora de ordem (`bSeq/mSeq/sSeq`).
- Limpeza da garagem no logout (cookie não-HttpOnly de 5 min) para computador compartilhado.
