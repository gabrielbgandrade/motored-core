/**
 * Compatibility tab / [motored_compat_list]: search (accent and spacing insensitive), expand/collapse,
 * on-demand full list (rows pre-formatted by the server, identical to the inline HTML) and "your bike" line.
 * Depends on compat-garage.js and motored-ui.js.
 */
(function () {
	'use strict';
	var c = window.motoredCompatCfg || {};
	var t = c.i18n || {};
	var ui = window.motoredUi;

	document.querySelectorAll('[data-compat-list]').forEach(function (root) {
		var input = root.querySelector('[data-role="compat-search"]');
		var grid = root.querySelector('[data-role="compat-grid"]');
		var toggle = root.querySelector('[data-role="compat-toggle"]');
		var none = root.querySelector('[data-role="compat-none"]');
		var errBox = root.querySelector('[data-role="compat-error"]');
		var retry = root.querySelector('[data-role="compat-retry"]');
		var mine = root.querySelector('.motored-compat-tab__garage');
		var hasMore = root.dataset.hasMore === '1';
		var url = root.dataset.url || '';
		var expanded = false;
		var loading = null;

		function rows() {
			return grid ? Array.prototype.slice.call(grid.querySelectorAll('[data-brand-row]')) : [];
		}

		function matches(hay, q) {
			return ui ? ui.matches(hay, q) : hay.indexOf(q.toLowerCase()) > -1;
		}

		function apply() {
			var q = input ? input.value.trim() : '';
			var shown = 0;
			rows().forEach(function (li) {
				li.hidden = q ? !matches(li.dataset.search || '', q) : !expanded && li.dataset.extra === '1';
				shown += li.hidden ? 0 : 1;
			});
			if (none) {
				none.hidden = !q || shown > 0;
			}
			if (toggle) {
				toggle.hidden = !!q;
				toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
				toggle.textContent = expanded ? toggle.dataset.labelLess || '' : toggle.dataset.labelMore || '';
			}
		}

		function render(list) {
			grid.textContent = '';
			list.forEach(function (row, idx) {
				var li = document.createElement('li');
				li.className = 'motored-compat-list__brand';
				li.dataset.brandRow = '';
				li.dataset.extra = idx >= 6 ? '1' : '0';
				li.dataset.search = row.search || '';
				li.dataset.slugs = (row.slugs || []).join(',');
				var name = document.createElement('span');
				name.className = 'motored-compat-list__brand-name';
				name.textContent = row.brand + ': ';
				var models = document.createElement('span');
				models.className = 'motored-compat-list__models';
				if (Array.isArray(row.entries) && row.entries.length) {
					row.entries.forEach(function (entry, eIdx) {
						if (entry.url) {
							var a = document.createElement('a');
							a.href = entry.url;
							a.textContent = entry.text || '';
							models.appendChild(a);
						} else {
							models.appendChild(document.createTextNode(entry.text || ''));
						}
						if (eIdx + 1 < row.entries.length) {
							models.appendChild(document.createTextNode('; '));
						}
					});
				} else {
					models.textContent = row.text;
				}
				li.appendChild(name);
				li.appendChild(models);
				grid.appendChild(li);
			});
		}

		function hydrate() {
			if (!hasMore || !url || !grid) {
				return Promise.resolve();
			}
			if (loading) {
				return loading;
			}
			if (errBox) {
				errBox.hidden = true;
			}
			loading = fetch(url, { credentials: 'same-origin' })
				.then(function (r) {
					if (!r.ok) {
						throw new Error('HTTP ' + r.status);
					}
					return r.json();
				})
				.then(function (d) {
					render((d && d.rows) || []);
					hasMore = false;
					apply();
					drawMine();
				})
				.catch(function () {
					loading = null;
					if (errBox) {
						errBox.hidden = false;
					}
				});
			return loading;
		}

		function drawMine() {
			if (!mine) {
				return;
			}
			mine.textContent = '';
			var pf = window.motoredGarageActive ? window.motoredGarageActive() : null;
			if (!pf) {
				return;
			}
			var hit = false;
			var marker = document.querySelector('.motored-fit-marker[data-ctx="single"]');
			var ev = window.motoredGarageApi && window.motoredGarageApi.ev;
			if (marker && ev) {
				// Same evaluation as the product banner, so both always agree.
				try {
					hit = !!ev(JSON.parse(marker.dataset.motoredFit), pf).ok;
				} catch (e) {
					hit = false;
				}
			} else {
				hit = rows().some(function (li) {
					return (',' + (li.dataset.slugs || '') + ',').indexOf(',' + pf.slug + ',') > -1;
				});
				if (!hit && hasMore) {
					hydrate();
					return;
				}
			}
			var p = document.createElement('p');
			p.className = 'motored-compat-list__mine ' + (hit ? 'is-ok' : 'is-no');
			p.textContent = String((hit ? t.compatWith : t.incompatWith) || '').replace('%s', pf.label);
			mine.appendChild(p);
		}

		if (toggle) {
			toggle.addEventListener('click', function () {
				expanded = !expanded;
				if (expanded && hasMore) {
					hydrate().then(apply);
				}
				apply();
			});
		}
		if (input) {
			input.addEventListener('input', function () {
				if (hasMore && input.value.trim().length >= 2) {
					hydrate().then(apply);
				}
				apply();
			});
		}
		if (retry) {
			retry.addEventListener('click', function () {
				hydrate().then(apply);
			});
		}
		document.addEventListener('motored:garage', drawMine);
		drawMine();
	});
})();
