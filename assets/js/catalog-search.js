/**
 * Home search [motored_buscador]: tabs (keyboard accessible), size cascade with stock counts, motorcycle cascade
 * (shared motored-ui.js) and smart search combobox (WAI-ARIA 1.2 pattern, smart Enter).
 * REST data is fetched only when a panel becomes visible.
 */
(function () {
	'use strict';
	var C = window.motoredFiltersCfg || {};
	var B = (C.restBase || '').replace(/\/$/, '');
	var T = C.i18n || {};
	var ui = window.motoredUi;
	var SIZE_PREFIX = 'motored_sizes_';
	var S_KEY = SIZE_PREFIX + (C.sizesVer || '1');
	var memory = {};
	var sizesPromise = null;
	if (!B || !ui) {
		return;
	}

	function lsGet(k) {
		try {
			return window.localStorage.getItem(k);
		} catch (e) {
			return memory[k] || null;
		}
	}

	function lsSet(k, v) {
		memory[k] = v;
		try {
			window.localStorage.setItem(k, v);
			// Drop size lists of previous snapshot versions.
			for (var i = window.localStorage.length - 1; i >= 0; i--) {
				var key = window.localStorage.key(i);
				if (key && key !== k && key.indexOf(SIZE_PREFIX) === 0) {
					window.localStorage.removeItem(key);
				}
			}
		} catch (e) {}
	}

	function withQuery(url, query) {
		return url + (url.indexOf('?') > -1 ? '&' : '?') + query;
	}

	function message(el, text, isError) {
		if (!el) {
			return;
		}
		el.hidden = !text;
		el.textContent = text || '';
		el.className = 'motored-search__msg' + (text ? (isError ? ' is-error' : ' is-ok') : '');
	}

	function loadSizes() {
		if (sizesPromise) {
			return sizesPromise;
		}
		var raw = lsGet(S_KEY);
		if (raw) {
			try {
				var cached = JSON.parse(raw);
				if (Array.isArray(cached)) {
					sizesPromise = Promise.resolve(cached);
					return sizesPromise;
				}
			} catch (e) {}
		}
		sizesPromise = ui
			.getJson(B + '/filters/sizes/' + encodeURIComponent(C.sizesVer || '1'))
			.then(function (d) {
				var list = (d && d.sizes) || [];
				lsSet(S_KEY, JSON.stringify(list));
				return list;
			})
			.catch(function (err) {
				sizesPromise = null;
				throw err;
			});
		return sizesPromise;
	}

	/** Distinct values with summed stock counts: "120 (14)". */
	function options(list, valueKey, labelKey) {
		var byValue = {};
		var out = [];
		list.forEach(function (row) {
			var v = row[valueKey];
			if (v === null || v === undefined || v === '') {
				return;
			}
			v = String(v);
			if (!byValue[v]) {
				byValue[v] = { v: v, label: String(row[labelKey] || v), n: 0 };
				out.push(byValue[v]);
			}
			byValue[v].n += parseInt(row.c, 10) || 0;
		});
		return out.map(function (o) {
			return { v: o.v, l: o.n ? o.label + ' (' + o.n + ')' : o.label };
		});
	}

	/* ---------- tabs (roving tabindex + arrow keys) ---------- */

	function initTabs(root) {
		var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-tab]'));
		var panels = root.querySelectorAll('[data-panel]');
		if (!tabs.length) {
			return;
		}
		function select(tab, focus) {
			tabs.forEach(function (x) {
				var on = x === tab;
				x.classList.toggle('is-active', on);
				x.setAttribute('aria-selected', on ? 'true' : 'false');
				x.tabIndex = on ? 0 : -1;
			});
			panels.forEach(function (p) {
				var on = p.dataset.panel === tab.dataset.tab;
				p.classList.toggle('is-active', on);
				p.hidden = !on;
			});
			if (focus) {
				tab.focus();
			}
		}
		tabs.forEach(function (tab, i) {
			tab.tabIndex = tab.getAttribute('aria-selected') === 'true' ? 0 : -1;
			tab.addEventListener('click', function () {
				select(tab, false);
			});
			tab.addEventListener('keydown', function (e) {
				var next = null;
				if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
					next = tabs[(i + 1) % tabs.length];
				} else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
					next = tabs[(i - 1 + tabs.length) % tabs.length];
				} else if (e.key === 'Home') {
					next = tabs[0];
				} else if (e.key === 'End') {
					next = tabs[tabs.length - 1];
				}
				if (next) {
					e.preventDefault();
					select(next, true);
				}
			});
		});
	}

	/* ---------- size cascade ---------- */

	function initSizes(root, shop) {
		var form = root.querySelector('[data-role="size-form"]');
		if (!form) {
			return;
		}
		var ws = form.querySelector('[data-role="width"]');
		var ps = form.querySelector('[data-role="profile"]');
		var rs = form.querySelector('[data-role="rim"]');
		var submit = form.querySelector('[data-role="size-submit"]');
		var msg = form.querySelector('[data-role="size-msg"]');
		var all = [];

		function sync() {
			var w = ws.value;
			var p = ps.value;
			var r = rs.value;
			var forW = all.filter(function (x) {
				return (!p || x.p === p) && (!r || x.r === r);
			});
			var forP = all.filter(function (x) {
				return (!w || x.w === w) && (!r || x.r === r);
			});
			var forR = all.filter(function (x) {
				return (!w || x.w === w) && (!p || x.p === p);
			});
			ui.fillSelect(ws, options(forW, 'w', 'wl'), T.widthPlaceholder, false);
			var pOpts = options(forP, 'p', 'pl');
			var noProfile = !!w && !pOpts.length;
			ui.fillSelect(ps, pOpts, noProfile ? T.noProfile : T.profilePlaceholder, noProfile);
			ui.fillSelect(rs, options(forR, 'r', 'rl'), T.rimPlaceholder, false);
			// The rim is required (width or profile alone match too many unrelated tires).
			submit.disabled = !rs.value;
		}

		function failed(text, isError) {
			[ws, ps, rs].forEach(function (s, i) {
				ui.fillSelect(s, [], [T.widthPlaceholder, T.profilePlaceholder, T.rimPlaceholder][i], true);
			});
			submit.disabled = true;
			message(msg, text, isError);
		}

		ui.whenVisible(form, function () {
			loadSizes()
				.then(function (list) {
					all = list;
					if (!list.length) {
						failed(T.noSizes || '', false);
						return;
					}
					message(msg, '');
					sync();
				})
				.catch(function () {
					failed(T.loadSelectErr || '', true);
				});
		});
		[ws, ps, rs].forEach(function (s) {
			s.addEventListener('change', sync);
		});
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var q = [];
			if (ws.value) {
				q.push('filter_ancho=' + encodeURIComponent(ws.value), 'query_type_ancho=or');
			}
			if (ps.value && !ps.disabled) {
				q.push('filter_perfil=' + encodeURIComponent(ps.value), 'query_type_perfil=or');
			}
			if (rs.value) {
				q.push('filter_rodado=' + encodeURIComponent(rs.value), 'query_type_rodado=or');
			}
			if (!q.length) {
				return;
			}
			ui.track('size_search', { width: ws.value, profile: ps.value, rim: rs.value });
			location.href = withQuery(shop, q.join('&'));
		});
	}

	/* ---------- motorcycle cascade ---------- */

	function saveAndGo(slug, url) {
		return ui
			.getJson(B + '/compat/bike/' + encodeURIComponent(slug))
			.then(function (p) {
				ui.track('moto_select', { moto: p.slug, source: 'search' });
				return window.motoredGarageSave ? window.motoredGarageSave(p) : null;
			})
			.catch(function () {})
			.then(function () {
				location.href = url;
			});
	}

	function initMoto(root, shop) {
		var form = root.querySelector('[data-role="moto-form"]');
		if (!form) {
			return;
		}
		var fields = form.querySelector('[data-role="moto-fields"]');
		var submit = form.querySelector('[data-role="moto-submit"]');
		var msg = form.querySelector('[data-role="moto-msg"]');
		var cc = ui.cascade({
			brand: form.querySelector('[data-role="brand"]'),
			model: form.querySelector('[data-role="model"]'),
			year: form.querySelector('[data-role="year"]'),
			variant: form.querySelector('[data-role="variant"]'),
			variantWrap: form.querySelector('[data-role="variant-wrap"]'),
			submit: submit,
			t: T,
			base: B,
			lazy: true,
			message: function (text, isError) {
				message(msg, text, isError);
			},
			onVariant: function (on) {
				if (!fields) {
					return;
				}
				if (on) {
					fields.dataset.hasVariant = '1';
				} else {
					delete fields.dataset.hasVariant;
				}
			},
		});
		ui.whenVisible(form, cc.load);
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var slug = cc.slug();
			if (!slug) {
				return;
			}
			submit.disabled = true;
			message(msg, '');
			saveAndGo(slug, withQuery(shop, 'moto=' + encodeURIComponent(slug)));
		});
	}

	/* ---------- smart search combobox ---------- */

	function initSmart(root) {
		var form = root.querySelector('[data-role="smart-form"]');
		if (!form) {
			return;
		}
		var input = form.querySelector('[data-role="smart-input"]');
		var list = form.querySelector('[data-role="smart-list"]');
		var live = form.querySelector('[data-role="smart-live"]');
		var timer;
		var seq = 0;
		var items = [];
		var itemsFor = '';
		var activeIdx = -1;

		function close() {
			list.hidden = true;
			list.textContent = '';
			items = [];
			activeIdx = -1;
			input.setAttribute('aria-expanded', 'false');
			input.removeAttribute('aria-activedescendant');
		}

		function highlight(idx) {
			var opts = list.querySelectorAll('[role="option"]');
			activeIdx = idx;
			opts.forEach(function (o, i) {
				var on = i === idx;
				o.setAttribute('aria-selected', on ? 'true' : 'false');
				o.classList.toggle('is-active', on);
				if (on) {
					input.setAttribute('aria-activedescendant', o.id);
					if (o.scrollIntoView) {
						o.scrollIntoView({ block: 'nearest' });
					}
				}
			});
			if (idx < 0) {
				input.removeAttribute('aria-activedescendant');
			}
		}

		function go(item) {
			ui.track('suggest_click', { type: item.type, label: item.type === 'moto' ? item.slug : item.label });
			if (item.type === 'moto' && item.slug) {
				saveAndGo(item.slug, item.url);
			} else {
				location.href = item.url;
			}
		}

		function render(data, q) {
			items = (data && data.items) || [];
			itemsFor = q;
			list.textContent = '';
			activeIdx = -1;
			if (!items.length) {
				close();
				return;
			}
			items.forEach(function (it, i) {
				var li = document.createElement('li');
				li.id = list.id + '-o' + i;
				li.setAttribute('role', 'option');
				li.setAttribute('aria-selected', 'false');
				li.className = 'motored-search__option';
				var label = document.createElement('span');
				label.textContent = it.label;
				var meta = document.createElement('span');
				meta.className = 'motored-search__suggest-meta';
				meta.textContent = it.meta || '';
				li.appendChild(label);
				li.appendChild(meta);
				// mousedown (not click) so the input does not lose the list before navigation.
				li.addEventListener('mousedown', function (e) {
					e.preventDefault();
					go(it);
				});
				list.appendChild(li);
			});
			list.hidden = false;
			input.setAttribute('aria-expanded', 'true');
			if (live) {
				live.textContent = String(T.suggestCount || '').replace('%d', items.length);
			}
		}

		function fetchFor(q) {
			var mine = ++seq;
			return ui.getJson(B + '/filters/suggest?q=' + encodeURIComponent(q)).then(function (d) {
				if (mine === seq) {
					render(d, q);
				}
				return d;
			});
		}

		/** Enter without a highlighted option: jump straight to an exact size/rim/motorcycle match. */
		function smartTarget(q, list2) {
			var first = list2 && list2[0];
			if (!first) {
				return null;
			}
			if (first.type === 'size' || first.type === 'rim') {
				return /^\s*(?:\d|r\s*\d|rodado|aro)/i.test(q) ? first : null;
			}
			if (first.type === 'moto') {
				return ui.compact(first.label).indexOf(ui.compact(q)) === 0 && list2.filter(function (x) {
					return x.type === 'moto';
				}).length === 1
					? first
					: null;
			}
			return null;
		}

		input.addEventListener('input', function () {
			clearTimeout(timer);
			var q = input.value.trim();
			if (q.length < 2) {
				seq++;
				close();
				return;
			}
			timer = setTimeout(function () {
				fetchFor(q).catch(close);
			}, 250);
		});

		input.addEventListener('keydown', function (e) {
			var count = items.length;
			if (e.key === 'ArrowDown' && count) {
				e.preventDefault();
				highlight((activeIdx + 1) % count);
			} else if (e.key === 'ArrowUp' && count) {
				e.preventDefault();
				highlight(activeIdx <= 0 ? count - 1 : activeIdx - 1);
			} else if (e.key === 'Escape') {
				close();
			} else if (e.key === 'Enter' && activeIdx >= 0 && items[activeIdx]) {
				e.preventDefault();
				go(items[activeIdx]);
			}
		});

		input.addEventListener('blur', function () {
			setTimeout(close, 150);
		});

		form.addEventListener('submit', function (e) {
			var q = input.value.trim();
			if (q.length < 2) {
				return;
			}
			e.preventDefault();
			clearTimeout(timer);
			var ready = itemsFor === q ? Promise.resolve({ items: items }) : fetchFor(q);
			ready
				.then(function (d) {
					var target = smartTarget(q, (d && d.items) || []);
					if (target) {
						go(target);
					} else {
						form.submit();
					}
				})
				.catch(function () {
					form.submit();
				});
		});
	}

	document.querySelectorAll('[data-motored-search]').forEach(function (root) {
		var shop = root.dataset.shopUrl || C.shopUrl || '/';
		initTabs(root);
		initSizes(root, shop);
		initMoto(root, shop);
		initSmart(root);
	});
})();
