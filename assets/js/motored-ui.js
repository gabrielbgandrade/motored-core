/**
 * Motored storefront helpers shared by the Compatibility selector, the home search and the Compatibility tab.
 *
 * - motoredUi.norm() / compact(): accent and spacing insensitive text (mirrors Support\SearchText).
 * - motoredUi.track(): optional anonymous dataLayer events (only when enabled in settings and dataLayer exists).
 * - motoredUi.cascade(): brand -> model -> year -> version selects backed by the Compatibility REST API.
 *
 * Vanilla, no dependencies, loaded with `defer`.
 */
(function () {
	'use strict';

	var FILTER_MIN = 30;
	var brandsPromise = null;

	function cfg() {
		return window.motoredCompatCfg || window.motoredFiltersCfg || {};
	}

	function norm(s) {
		return String(s == null ? '' : s)
			.toLowerCase()
			.normalize('NFD')
			.replace(/[̀-ͯ]/g, '')
			.replace(/\s+/g, ' ')
			.trim();
	}

	function compact(s) {
		return norm(s).replace(/[\s\-._/]+/g, '');
	}

	/** Same rule as SearchText::matches(): whole compact query, or every token (plain or compact). */
	function matches(haystack, query) {
		var q = norm(query);
		if (!q) {
			return true;
		}
		var whole = compact(query);
		if (whole && haystack.indexOf(whole) > -1) {
			return true;
		}
		return q.split(' ').every(function (tok) {
			return !tok || haystack.indexOf(tok) > -1 || haystack.indexOf(compact(tok)) > -1;
		});
	}

	function track(name, data) {
		if (!cfg().events || !Array.isArray(window.dataLayer)) {
			return;
		}
		var ev = { event: 'motored_' + name };
		for (var k in data || {}) {
			ev[k] = data[k];
		}
		window.dataLayer.push(ev);
	}

	function restUrl(base, path) {
		var url = new URL(base, window.location.href);
		if (url.searchParams.has('rest_route')) {
			url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/$/, '') + path);
		} else {
			url.pathname = url.pathname.replace(/\/$/, '') + path;
		}
		return url.href;
	}

	function getJson(url) {
		return fetch(url, { credentials: 'same-origin' }).then(function (r) {
			if (!r.ok) {
				throw new Error('HTTP ' + r.status);
			}
			return r.json();
		});
	}

	function fillSelect(select, items, placeholder, disabled) {
		var current = select.value;
		select.textContent = '';
		var first = document.createElement('option');
		first.value = '';
		first.textContent = placeholder || '';
		select.appendChild(first);
		var keep = false;
		items.forEach(function (it) {
			var o = document.createElement('option');
			o.value = it.v;
			o.textContent = it.l;
			if (it.v === current) {
				keep = true;
			}
			select.appendChild(o);
		});
		select.value = keep ? current : '';
		select.disabled = disabled != null ? !!disabled : !items.length;
	}

	/**
	 * Adds (or removes) a native "type to filter" box above long selects; options are rebuilt, never hidden
	 * (hidden <option> is not supported everywhere).
	 */
	function setFilterable(select, items, placeholder, label) {
		var box = select.previousElementSibling;
		var has = box && box.classList && box.classList.contains('motored-cascade__filter');
		if (items.length <= FILTER_MIN) {
			if (has) {
				box.remove();
			}
			return;
		}
		if (!has) {
			box = document.createElement('input');
			box.type = 'search';
			box.className = 'motored-cascade__filter';
			box.autocomplete = 'off';
			box.placeholder = label || '';
			box.setAttribute('aria-label', label || '');
			select.parentNode.insertBefore(box, select);
		}
		box.value = '';
		box.oninput = function () {
			var q = box.value;
			fillSelect(
				select,
				items.filter(function (it) {
					return matches(it.h, q);
				}),
				placeholder,
				false
			);
		};
	}

	/**
	 * Wires a brand/model/year/version cascade.
	 *
	 * @param {Object} o Elements and options: brand, model, year, variant, variantWrap, submit, t (i18n),
	 *                   base (REST base), lazy (bool), message(text, isError), onVariant(bool), onReady(slug).
	 * @return {{slug: function(): string, reset: function(): void, load: function(): void}}
	 */
	function cascade(o) {
		var t = o.t || {};
		var versions = [];
		var slug = '';
		var brandSeq = 0;
		var modelSeq = 0;
		var loaded = false;
		var loading = false;
		var msg = o.message || function () {};

		function setVariant(on) {
			if (o.variantWrap) {
				o.variantWrap.hidden = !on;
			}
			if (o.onVariant) {
				o.onVariant(on);
			}
		}

		function setSlug(s) {
			slug = s || '';
			if (o.submit) {
				o.submit.disabled = !slug;
			}
		}

		function resetYears() {
			versions = [];
			setSlug('');
			fillSelect(o.year, [], t.yearPlaceholder, true);
			setVariant(false);
			if (o.variant) {
				o.variant.textContent = '';
			}
		}

		function load() {
			if (loaded || loading) {
				return;
			}
			loading = true;
			msg('');
			fillSelect(o.brand, [], t.loading || t.brandPlaceholder, true);
			brandsPromise = brandsPromise || getJson(restUrl(o.base, '/compat/brands'));
			brandsPromise
				.then(function (d) {
					loading = false;
					loaded = true;
					var list = (d.brands || []).map(function (x) {
						return { v: String(x.id), l: x.name, h: compact(x.name) + ' ' + norm(x.name) };
					});
					fillSelect(o.brand, list, t.brandPlaceholder, false);
					setFilterable(o.brand, list, t.brandPlaceholder, t.filterList);
				})
				.catch(function () {
					loading = false;
					brandsPromise = null;
					fillSelect(o.brand, [], t.brandPlaceholder, false);
					msg(t.loadSelectErr || '', true);
				});
		}

		o.brand.addEventListener('focus', load);
		o.brand.addEventListener('pointerdown', load);
		o.brand.addEventListener('change', function () {
			msg('');
			var seq = ++brandSeq;
			++modelSeq;
			resetYears();
			if (!o.brand.value) {
				fillSelect(o.model, [], t.modelPlaceholder, true);
				return;
			}
			fillSelect(o.model, [], t.loading || t.modelPlaceholder, true);
			getJson(restUrl(o.base, '/compat/brands/' + encodeURIComponent(o.brand.value) + '/models'))
				.then(function (d) {
					if (seq !== brandSeq) {
						return;
					}
					var list = (d.models || []).map(function (x) {
						return { v: String(x.id), l: x.name, h: compact(x.name) + ' ' + norm(x.name) };
					});
					fillSelect(o.model, list, list.length ? t.modelPlaceholder : t.noModels || t.modelPlaceholder, !list.length);
					setFilterable(o.model, list, t.modelPlaceholder, t.filterList);
				})
				.catch(function () {
					if (seq !== brandSeq) {
						return;
					}
					fillSelect(o.model, [], t.modelPlaceholder, true);
					msg(t.loadSelectErr || '', true);
				});
		});

		o.model.addEventListener('change', function () {
			msg('');
			var seq = ++modelSeq;
			resetYears();
			if (!o.model.value) {
				return;
			}
			fillSelect(o.year, [], t.loading || t.yearPlaceholder, true);
			getJson(restUrl(o.base, '/compat/models/' + encodeURIComponent(o.model.value) + '/years'))
				.then(function (d) {
					if (seq !== modelSeq) {
						return;
					}
					versions = d.versions || [];
					var seen = {};
					var years = [];
					versions.forEach(function (v) {
						if (!seen[v.years]) {
							seen[v.years] = 1;
							years.push({ v: v.years, l: v.years });
						}
					});
					fillSelect(o.year, years, t.yearPlaceholder, false);
					if (years.length === 1) {
						o.year.value = years[0].v;
						o.year.dispatchEvent(new Event('change'));
					}
				})
				.catch(function () {
					if (seq !== modelSeq) {
						return;
					}
					fillSelect(o.year, [], t.yearPlaceholder, true);
					msg(t.loadSelectErr || '', true);
				});
		});

		o.year.addEventListener('change', function () {
			setSlug('');
			setVariant(false);
			if (!o.year.value) {
				return;
			}
			var match = versions.filter(function (v) {
				return v.years === o.year.value;
			});
			if (match.length === 1) {
				setSlug(match[0].slug);
			} else if (match.length > 1 && o.variant) {
				setVariant(true);
				fillSelect(
					o.variant,
					match.map(function (x) {
						return { v: x.slug, l: x.variant || x.years };
					}),
					t.varPlaceholder,
					false
				);
			}
		});

		if (o.variant) {
			o.variant.addEventListener('change', function () {
				setSlug(o.variant.value);
			});
		}

		if (!o.lazy) {
			load();
		}

		return {
			slug: function () {
				return slug;
			},
			load: load,
			reset: function () {
				o.brand.value = '';
				var f = o.brand.previousElementSibling;
				if (f && f.classList && f.classList.contains('motored-cascade__filter') && f.value) {
					f.value = '';
					f.dispatchEvent(new Event('input'));
				}
				fillSelect(o.model, [], t.modelPlaceholder, true);
				resetYears();
			},
		};
	}

	/** Runs fn once the element is (or becomes) visible: lazy REST loading for hidden tabs/below-the-fold forms. */
	function whenVisible(el, fn) {
		var done = false;
		function run() {
			if (!done) {
				done = true;
				fn();
			}
		}
		if (!el || !('IntersectionObserver' in window)) {
			run();
			return run;
		}
		var io = new IntersectionObserver(function (entries) {
			if (entries.some(function (e) {
				return e.isIntersecting;
			})) {
				io.disconnect();
				run();
			}
		});
		io.observe(el);
		return run;
	}

	window.motoredUi = {
		norm: norm,
		compact: compact,
		matches: matches,
		track: track,
		restUrl: restUrl,
		getJson: getJson,
		fillSelect: fillSelect,
		cascade: cascade,
		whenVisible: whenVisible,
	};
})();
