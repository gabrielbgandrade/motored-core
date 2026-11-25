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
	var sizesPromise = null;
	if (!B || !ui) {
		return;
	}

	function restEndpoint(configured, path) {
		var url = new URL(configured || B, location.href);
		if (!configured) {
			if (url.searchParams.has('rest_route')) {
				url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/$/, '') + path);
			} else {
				url.pathname = url.pathname.replace(/\/$/, '') + path;
			}
		}
		return url;
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
		sizesPromise = ui
			.getJson(restEndpoint(C.sizesUrl, '/filters/sizes').href)
			.then(function (d) {
				var list = (d && d.sizes) || [];

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
		var submission = 0;
		var items = [];
		var itemsFor = '';
		var activeIdx = -1;

		function close() {
			seq++;
			list.hidden = true;
			list.textContent = '';
			items = [];
			itemsFor = '';
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
			var prods = (data && Array.isArray(data.products)) ? data.products : [];
			var searchUrl = (data && data.search_url) || '';
			itemsFor = q;
			list.textContent = '';
			activeIdx = -1;
			if (!items.length && !prods.length) {
				close();
				return;
			}

			var isSmartLayout = root.dataset.layout === 'smart';
			if (isSmartLayout && prods.length) {
				list.classList.add('motored-search__suggest--mega');
				var wrap = document.createElement('li');
				wrap.className = 'motored-suggest__shell';
				wrap.setAttribute('role', 'presentation');

				var grid = document.createElement('div');
				grid.className = 'motored-suggest__grid' + (items.length ? '' : ' motored-suggest__grid--solo');

				if (items.length) {
					var side = document.createElement('div');
					side.className = 'motored-suggest__side';
					var hS = document.createElement('h4');
					hS.className = 'motored-trending__heading';
					hS.textContent = T.suggestHeading || 'Sugerencias';
					side.appendChild(hS);

					var ul = document.createElement('ul');
					ul.className = 'motored-suggest__list';
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
						li.addEventListener('mousedown', function (e) {
							e.preventDefault();
							go(it);
						});
						ul.appendChild(li);
					});
					side.appendChild(ul);
					grid.appendChild(side);
				}

				var main = document.createElement('div');
				main.className = 'motored-suggest__main';
				var head = document.createElement('div');
				head.className = 'motored-suggest__header';
				var hP = document.createElement('h4');
				hP.className = 'motored-trending__heading';
				hP.textContent = String(T.productsFor || 'Productos para "%s"').replace('%s', q);
				head.appendChild(hP);
				if (searchUrl) {
					var allLink = document.createElement('a');
					allLink.className = 'motored-suggest__all';
					allLink.href = searchUrl;
					allLink.textContent = (T.viewAllResults || 'Ver todos los resultados') + ' ›';
					allLink.addEventListener('mousedown', function (e) {
						e.preventDefault();
						location.href = searchUrl;
					});
					head.appendChild(allLink);
				}
				main.appendChild(head);

				var cards = document.createElement('div');
				cards.className = 'motored-trending__products';
				prods.forEach(function (p) {
					var a = document.createElement('a');
					a.className = 'motored-trending__card';
					a.href = p.url;
					a.addEventListener('mousedown', function (e) {
						e.preventDefault();
						location.href = p.url;
					});
					if (p.img) {
						var img = document.createElement('img');
						img.src = p.img;
						img.alt = p.title || '';
						img.loading = 'lazy';
						a.appendChild(img);
					}
					var title = document.createElement('span');
					title.className = 'motored-trending__title';
					title.textContent = p.title || '';
					a.appendChild(title);
					if (p.price) {
						var price = document.createElement('strong');
						price.className = 'motored-trending__price';
						price.textContent = p.price;
						a.appendChild(price);
					}
					cards.appendChild(a);
				});
				main.appendChild(cards);
				grid.appendChild(main);

				wrap.appendChild(grid);
				list.appendChild(wrap);
			} else {
				list.classList.remove('motored-search__suggest--mega');
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
			}

			list.hidden = false;
			input.setAttribute('aria-expanded', 'true');
			if (live) {
				live.textContent = String(T.suggestCount || '').replace('%d', items.length + prods.length);
			}
		}

		function fetchFor(q) {
			var mine = ++seq;
			var url = restEndpoint(C.suggestUrl, '/filters/suggest');
			url.searchParams.set('q', q);
			return ui.getJson(url.href).then(function (d) {
				if (mine === seq && input.value.trim() === q && document.activeElement === input) {
					render(d, q);
				}
				return d;
			});
		}

		/** Enter without a highlighted option: jump straight to an exact size/rim/motorcycle match. */
		function smartTarget(q, suggestions) {
			var exact = suggestions.filter(function (item) {
				return item.exact || (item.type === 'moto' && ui.compact(item.label) === ui.compact(q));
			});
			return exact.length === 1 ? exact[0] : null;
		}

		var trendingBox = form.querySelector('[data-role="smart-trending"]');
		var clearBtn = form.querySelector('[data-role="smart-clear"]');
		var trendingLoaded = false;
		var trendingPromise = null;

		function hideTrending() {
			if (trendingBox) {
				trendingBox.hidden = true;
			}
		}

		function syncClear() {
			if (clearBtn) {
				clearBtn.hidden = input.value.length === 0;
			}
		}

		function renderTrending(d) {
			if (!trendingBox || !d) {
				return;
			}
			var sizes = Array.isArray(d.sizes) ? d.sizes : [];
			var brands = Array.isArray(d.brands) ? d.brands : [];
			var prods = Array.isArray(d.products) ? d.products : [];
			if (!sizes.length && !brands.length && !prods.length) {
				return;
			}
			trendingBox.textContent = '';
			var grid = document.createElement('div');
			grid.className = 'motored-trending__grid';

			if (sizes.length || brands.length) {
				var side = document.createElement('div');
				side.className = 'motored-trending__side';
				if (sizes.length) {
					var hS = document.createElement('h4');
					hS.className = 'motored-trending__heading';
					hS.textContent = T.trendingSizes || 'Medidas más buscadas';
					side.appendChild(hS);
					var ulS = document.createElement('ul');
					ulS.className = 'motored-trending__list';
					sizes.forEach(function (s) {
						var li = document.createElement('li');
						var a = document.createElement('a');
						a.href = s.url;
						a.textContent = s.label;
						a.addEventListener('mousedown', function (e) { e.preventDefault(); location.href = s.url; });
						li.appendChild(a);
						ulS.appendChild(li);
					});
					side.appendChild(ulS);
				}
				if (brands.length) {
					var hB = document.createElement('h4');
					hB.className = 'motored-trending__heading';
					hB.textContent = T.trendingBrands || 'Marcas destacadas';
					side.appendChild(hB);
					var ulB = document.createElement('ul');
					ulB.className = 'motored-trending__list motored-trending__list--inline';
					brands.forEach(function (b) {
						var li = document.createElement('li');
						var a = document.createElement('a');
						a.href = b.url;
						a.textContent = b.label;
						a.addEventListener('mousedown', function (e) { e.preventDefault(); location.href = b.url; });
						li.appendChild(a);
						ulB.appendChild(li);
					});
					side.appendChild(ulB);
				}
				grid.appendChild(side);
			}

			if (prods.length) {
				var main = document.createElement('div');
				main.className = 'motored-trending__main';
				var hP = document.createElement('h4');
				hP.className = 'motored-trending__heading';
				hP.textContent = T.trendingProducts || 'Más vendidos';
				main.appendChild(hP);
				var cards = document.createElement('div');
				cards.className = 'motored-trending__products';
				prods.forEach(function (p) {
					var a = document.createElement('a');
					a.className = 'motored-trending__card';
					a.href = p.url;
					a.addEventListener('mousedown', function (e) { e.preventDefault(); location.href = p.url; });
					if (p.img) {
						var img = document.createElement('img');
						img.src = p.img;
						img.alt = p.title || '';
						img.loading = 'lazy';
						a.appendChild(img);
					}
					var title = document.createElement('span');
					title.className = 'motored-trending__title';
					title.textContent = p.title || '';
					a.appendChild(title);
					if (p.price) {
						var price = document.createElement('strong');
						price.className = 'motored-trending__price';
						price.textContent = p.price;
						a.appendChild(price);
					}
					cards.appendChild(a);
				});
				main.appendChild(cards);
				grid.appendChild(main);
			}

			trendingBox.appendChild(grid);
			trendingLoaded = true;
		}

		function showTrending() {
			if (!trendingBox || input.value.trim().length >= 2) {
				return;
			}
			if (trendingLoaded) {
				trendingBox.hidden = false;
				return;
			}
			if (!trendingPromise) {
				var url = restEndpoint(C.trendingUrl, '/filters/trending');
				trendingPromise = ui.getJson(url.href).then(function (d) {
					renderTrending(d);
					return d;
				}).catch(function () {
					trendingPromise = null;
				});
			}
			trendingPromise.then(function () {
				if (trendingLoaded && document.activeElement === input && input.value.trim().length < 2) {
					trendingBox.hidden = false;
				}
			});
		}

		if (clearBtn) {
			clearBtn.addEventListener('mousedown', function (e) {
				e.preventDefault();
				input.value = '';
				syncClear();
				close();
				input.focus();
				showTrending();
			});
			syncClear();
		}

		input.addEventListener('focus', function () {
			if (input.value.trim().length < 2) {
				showTrending();
			}
		});

		input.addEventListener('input', function () {
			submission++;
			clearTimeout(timer);
			syncClear();
			close();
			var q = input.value.trim();
			if (q.length < 2) {
				showTrending();
				return;
			}
			hideTrending();
			timer = setTimeout(function () {
				fetchFor(q).catch(function () {});
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
				hideTrending();
			} else if (e.key === 'Enter' && activeIdx >= 0 && items[activeIdx]) {
				e.preventDefault();
				go(items[activeIdx]);
			}
		});

		input.addEventListener('blur', function () {
			setTimeout(function () {
				close();
				hideTrending();
			}, 160);
		});

		form.addEventListener('submit', function (e) {
			var submitted = ++submission;
			var q = input.value.trim();
			if (q.length < 2) {
				return;
			}
			e.preventDefault();
			clearTimeout(timer);
			var ready = itemsFor === q ? Promise.resolve({ items: items }) : fetchFor(q);
			ready
				.then(function (d) {
					if (submitted !== submission || input.value.trim() !== q) {
						return;
					}
					var target = smartTarget(q, (d && d.items) || []);
					if (target) {
						go(target);
					} else {
						form.submit();
					}
				})
				.catch(function () {
					if (submitted === submission && input.value.trim() === q) {
						form.submit();
					}
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
