/**
 * Faceted sidebar [motored_filtros]: options are real links (work without JS); with JS the shopper can tick several
 * options and apply them at once with "Apply filters (N)" (no reload per click). Accordion state survives reloads.
 */
(function () {
	'use strict';
	var C = window.motoredFacetCfg || {};
	var T = C.i18n || {};
	var ACC_KEY = 'motored_facets_open';

	function readOpen() {
		try {
			return JSON.parse(window.sessionStorage.getItem(ACC_KEY)) || {};
		} catch (e) {
			return {};
		}
	}

	function writeOpen(map) {
		try {
			window.sessionStorage.setItem(ACC_KEY, JSON.stringify(map));
		} catch (e) {}
	}

	function track(name, data) {
		if (window.motoredUi) {
			window.motoredUi.track(name, data);
		} else if (C.events && Array.isArray(window.dataLayer)) {
			data.event = 'motored_' + name;
			window.dataLayer.push(data);
		}
	}

	document.querySelectorAll('[data-motored-filters]').forEach(function (box) {
		var base = box.dataset.baseUrl || '/';
		var moto = box.dataset.moto || '';
		var pos = box.dataset.pos || '';
		var kit = box.dataset.kit || '';
		var apply = box.querySelector('[data-role="apply"]');
		var extra = {};
		var initial = {};
		var openMap = readOpen();
		try {
			extra = JSON.parse(box.dataset.extra || '{}') || {};
		} catch (e) {}

		// Accordions (aria-expanded + remembered per facet for this browsing session).
		box.querySelectorAll('.motored-acc__btn').forEach(function (btn) {
			var group = btn.closest('[data-facet-group]');
			var key = group ? group.dataset.facetGroup : '';
			var panel = document.getElementById(btn.getAttribute('aria-controls') || '');
			var icon = btn.querySelector('.motored-acc__icon');
			function set(open) {
				btn.setAttribute('aria-expanded', open ? 'true' : 'false');
				if (panel) {
					panel.hidden = !open;
				}
				if (icon) {
					icon.textContent = open ? '−' : '+';
				}
			}
			if (key && Object.prototype.hasOwnProperty.call(openMap, key)) {
				// Never collapse a group that has an active option.
				set(openMap[key] || !!(panel && panel.querySelector('.motored-opt.is-active')));
			}
			btn.addEventListener('click', function () {
				var open = btn.getAttribute('aria-expanded') !== 'true';
				set(open);
				if (key) {
					openMap[key] = open;
					writeOpen(openMap);
				}
			});
		});

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
				location.href = buildUrl();
			});
		}

		opts.forEach(function (a) {
			a.addEventListener('click', function (e) {
				// Modifier clicks keep native link behavior (open in new tab, etc.).
				if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || !apply) {
					return;
				}
				e.preventDefault();
				var on = !a.classList.contains('is-active');
				a.classList.toggle('is-active', on);
				a.setAttribute('aria-pressed', on ? 'true' : 'false');
				refreshApply();
			});
		});
	});
})();
