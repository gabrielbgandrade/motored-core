/**
 * Faceted sidebar [motored_filtros] and quick bar [motored_filtros layout="quick"]: options are real links (work without JS).
 * On desktop (>= 850px), clicking any filter, chip or clear link applies immediately via AJAX
 * (swapping the catalog grid, counts, chips and URL without a full page reload).
 * On mobile (< 850px), the shopper can tick several options and apply them at once via AJAX.
 */
(function () {
	'use strict';
	var C = window.motoredFacetCfg || {};
	var T = C.i18n || {};
	var DESKTOP_MQ = '(min-width: 850px)';
	var GRID_SEL = '.shop-container, .mt-catalog__results';
	var BAR_SEL = '.motored-compat-bar';
	var inflight = null;

	function isDesktop() {
		return window.matchMedia && window.matchMedia(DESKTOP_MQ).matches;
	}

	function track(name, data) {
		if (window.motoredUi) {
			window.motoredUi.track(name, data);
		} else if (C.events && Array.isArray(window.dataLayer)) {
			data.event = 'motored_' + name;
			window.dataLayer.push(data);
		}
	}

	function setLoading(on) {
		var grid = document.querySelector(GRID_SEL);
		if (grid) {
			grid.classList.toggle('motored-catalog-loading', on);
			grid.setAttribute('aria-busy', on ? 'true' : 'false');
		}
		document.querySelectorAll('[data-motored-filters]').forEach(function (el) {
			el.classList.toggle('is-loading', on);
		});
	}

	function applyAjax(targetUrl, pushHistory) {
		var currentGrid = document.querySelector(GRID_SEL);
		if (!currentGrid || typeof window.fetch !== 'function' || typeof window.DOMParser !== 'function') {
			window.location.href = targetUrl;
			return;
		}

		if (inflight && typeof inflight.abort === 'function') {
			inflight.abort();
		}
		var controller = typeof window.AbortController === 'function' ? new AbortController() : null;
		inflight = controller;
		setLoading(true);
		// Public contract event (docs/THEME-CONTRACT.md): an AJAX catalog swap started; `motored:filters-updated`
		// follows (or a full page load to `detail.url` when the request fails). The theme shows its progress UI.
		document.dispatchEvent(new CustomEvent('motored:filters-loading', { detail: { url: targetUrl } }));

		window.fetch(targetUrl, {
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' },
			signal: controller ? controller.signal : undefined
		})
			.then(function (res) {
				if (!res.ok) {
					throw new Error('HTTP ' + res.status);
				}
				if (res.redirected && new URL(res.url).origin === location.origin) {
					targetUrl = res.url;
				}
				return res.text();
			})
			.then(function (html) {
				if (inflight === controller) {
					inflight = null;
				}
				var nextDoc = new DOMParser().parseFromString(html, 'text/html');
				var nextGrid = nextDoc.querySelector(GRID_SEL);
				if (!nextGrid) {
					window.location.href = targetUrl;
					return;
				}

				var currentBar = document.querySelector(BAR_SEL);
				var nextBar = nextDoc.querySelector(BAR_SEL);
				var externalBar = currentBar && !currentGrid.contains(currentBar);
				var nextExternalBar = nextBar && !nextGrid.contains(nextBar);
				if (!!externalBar !== !!nextExternalBar) {
					window.location.href = targetUrl;
					return;
				}
				if (externalBar) {
					currentBar.replaceWith(nextBar);
				}
				currentGrid.innerHTML = nextGrid.innerHTML;
				currentGrid.className = nextGrid.className;

				// Sync result count (often rendered in the page header outside the grid container).
				var curCounts = document.querySelectorAll('.woocommerce-result-count');
				var nextCounts = nextDoc.querySelectorAll('.woocommerce-result-count');
				curCounts.forEach(function (el, idx) {
					if (nextCounts[idx]) {
						el.innerHTML = nextCounts[idx].innerHTML;
					}
				});

				// Sync all filter panels (desktop sidebar + off-canvas mobile sidebar if both exist).
				var curPanels = document.querySelectorAll('[data-motored-filters]');
				var nextPanels = nextDoc.querySelectorAll('[data-motored-filters]');
				curPanels.forEach(function (panel, idx) {
					var replacement = nextPanels[idx] || nextPanels[0];
					if (replacement) {
						panel.replaceWith(replacement.cloneNode(true));
					}
				});

				// Public contract (docs/THEME-CONTRACT.md): every [data-motored-swap="key"] region (theme catalog title,
				// sort form, our quick bar) is replaced by the element with the same key in the new page.
				document.querySelectorAll('[data-motored-swap]').forEach(function (region) {
					var key = region.getAttribute('data-motored-swap') || '';
					var next = key ? nextDoc.querySelector('[data-motored-swap="' + key.replace(/["\\]/g, '') + '"]') : null;
					if (next) {
						region.replaceWith(document.importNode(next, true));
					}
				});

				if (nextDoc.title) {
					document.title = nextDoc.title;
				}
				if (pushHistory && window.history && typeof window.history.pushState === 'function') {
					window.history.pushState({ motoredFilter: true }, '', targetUrl);
				} else if (!pushHistory && targetUrl !== location.href) {
					window.history.replaceState(window.history.state, '', targetUrl);
				}

				initAll();
				setLoading(false);

				// Smoothly bring the top of the catalog into view if the user scrolled far down.
				var rect = currentGrid.getBoundingClientRect();
				if (rect.top < 0) {
					currentGrid.scrollIntoView({ behavior: 'smooth', block: 'start' });
				}

				// Re-hydrate Mi Garage CTA ("Tu moto: ..."), product card compatibility badges and bike switcher bar.
				document.dispatchEvent(new Event('motored:garage'));
				// Public contract event (docs/THEME-CONTRACT.md): the theme re-attaches its own behaviors on `root`
				// and closes its off-canvas filter panel. The plugin never calls theme code.
				document.dispatchEvent(new CustomEvent('motored:filters-updated', { detail: { url: targetUrl, root: currentGrid } }));
			})
			.catch(function (err) {
				if (err && err.name === 'AbortError') {
					return;
				}
				setLoading(false);
				window.location.href = targetUrl;
			});
	}

	function initPanel(box) {
		if (box.dataset.motoredFiltersBound) {
			return;
		}
		box.dataset.motoredFiltersBound = '1';

		var base = box.dataset.baseUrl || '/';
		var moto = box.dataset.moto || '';
		var pos = box.querySelector('[data-facet="pos"]') ? '' : box.dataset.pos || '';
		var kit = box.dataset.kit || '';
		var apply = box.querySelector('[data-role="apply"]');
		var extra = {};
		var initial = {};
		try {
			extra = JSON.parse(box.dataset.extra || '{}') || {};
		} catch (e) {}

		var opts = box.querySelectorAll('.motored-opt');
		opts.forEach(function (a) {
			initial[a.dataset.facet + ':' + a.dataset.slug] = a.classList.contains('is-active');
		});

		function pendingCount() {
			var n = 0;
			opts.forEach(function (a) {
				if (a.classList.contains('is-active') !== initial[a.dataset.facet + ':' + a.dataset.slug]) {
					n++;
				}
			});
			return n;
		}

		function buildUrl() {
			var url = new URL(base, location.href);
			var operators = JSON.parse(box.dataset.operators || '{}');
			var params = [];
			var byFacet = {};
			for (var k in extra) {
				if (extra[k]) {
					params.push(encodeURIComponent(k) + '=' + encodeURIComponent(extra[k]));
				}
			}
			if (moto) {
				params.push('moto=' + encodeURIComponent(moto));
				if (pos) {
					params.push('pos=' + encodeURIComponent(pos));
				}
			}
			if (kit) {
				params.push('kit_medida=' + encodeURIComponent(kit));
			}
			if (box.dataset.stock) {
				params.push('stock=1');
			}
			box.querySelectorAll('.motored-opt.is-active').forEach(function (a) {
				var f = a.dataset.facet;
				var s = a.dataset.slug;
				if (!f || !s) {
					return;
				}
				byFacet[f] = byFacet[f] || [];
				if (byFacet[f].indexOf(s) < 0) {
					byFacet[f].push(s);
				}
			});
			for (var fk in byFacet) {
				if (fk === 'oferta') {
					params.push('oferta=1');
				} else if (fk === 'product_brand') {
					params.push('product_brand=' + byFacet[fk].map(encodeURIComponent).join(','));
				} else if (fk === 'pos') {
					params.push('pos=' + encodeURIComponent(byFacet[fk][0]));
				} else {
					params.push('filter_' + encodeURIComponent(fk) + '=' + byFacet[fk].map(encodeURIComponent).join(','), 'query_type_' + encodeURIComponent(fk) + '=' + (operators[fk] || 'or'));
				}
			}
			new URLSearchParams(params.join('&')).forEach(function (value, key) {
				url.searchParams.set(key, value);
			});
			return url.href;
		}

		function refreshApply() {
			if (!apply) {
				return;
			}
			var n = pendingCount();
			apply.hidden = n === 0;
			apply.textContent = n > 0 && T.applyCount ? T.applyCount.replace('%d', n) : T.apply || apply.textContent;
		}

		if (apply) {
			apply.addEventListener('click', function () {
				track('filter_apply', { changes: pendingCount() });
				applyAjax(buildUrl(), true);
			});
		}

		opts.forEach(function (a) {
			a.addEventListener('click', function (e) {
				// Modifier clicks keep native link behavior (open in new tab, etc.).
				if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
					return;
				}
				e.preventDefault();
				var on = !a.classList.contains('is-active');
				if (on && a.dataset.facet === 'pos') {
					box.querySelectorAll('[data-facet="pos"].is-active').forEach(function (previous) {
						previous.classList.remove('is-active');
						previous.setAttribute('aria-pressed', 'false');
					});
				}
				a.classList.toggle('is-active', on);
				a.setAttribute('aria-pressed', on ? 'true' : 'false');

				if (isDesktop()) {
					track('filter_apply', { changes: 1, mode: 'instant' });
					applyAjax(buildUrl(), true);
				} else {
					refreshApply();
				}
			});
		});

		// Instant AJAX on active filter chips & "Clear all" links.
		box.querySelectorAll('.motored-chip, .motored-filters__clear, .motored-filters__clear-all').forEach(function (link) {
			link.addEventListener('click', function (e) {
				if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
					return;
				}
				e.preventDefault();
				applyAjax(link.href, true);
			});
		});
	}

	function initAll() {
		document.querySelectorAll('[data-motored-filters]').forEach(initPanel);
	}

	initAll();

	// Quick bar ([motored_filtros layout="quick"]): each link toggles one filter and applies at once.
	document.addEventListener('click', function (e) {
		var link = e.target.closest && e.target.closest('[data-motored-quick] a[href]');
		if (!link || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
			return;
		}
		if (!document.querySelector(GRID_SEL)) {
			return;
		}
		e.preventDefault();
		track('filter_apply', { changes: 1, mode: 'quick' });
		applyAjax(link.href, true);
	});

	window.addEventListener('popstate', function () {
		if (document.querySelector(GRID_SEL) && document.querySelector('[data-motored-filters]')) {
			applyAjax(window.location.href, false);
		}
	});
})();
