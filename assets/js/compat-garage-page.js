/**
 * My Account -> Mi Garage list: activate, view tires, remove with "Undo" (the DELETE is sent only after the undo window).
 * Depends on compat-garage.js (motoredGarageApi).
 */
(function () {
	'use strict';
	var D = document;
	var c = window.motoredCompatCfg || {};
	// Page-only strings ship with this script (GarageAssets::page_i18n()), not on every catalog/product page.
	var t = Object.assign({}, c.i18n || {}, window.motoredGaragePageI18n || {});
	var G = window.motoredGarageApi;
	var UNDO_MS = 5000;
	var pending = null; // { slug, timer, prev }

	if (!G) {
		return;
	}

	function el(tag, cls, text) {
		var e = D.createElement(tag);
		if (cls) {
			e.className = cls;
		}
		if (text != null) {
			e.textContent = text;
		}
		return e;
	}

	function withMoto(slug, url) {
		url = url || c.shopUrl || '/';
		return url + (url.indexOf('?') > -1 ? '&' : '?') + 'moto=' + encodeURIComponent(slug);
	}

	function notice(text, undo) {
		var box = D.querySelector('.motored-garage-notice');
		if (!box) {
			return;
		}
		box.textContent = '';
		if (!text) {
			box.hidden = true;
			return;
		}
		box.appendChild(el('span', '', text + ' '));
		if (undo) {
			var b = el('button', 'motored-compat-badge__link', t.undo || '');
			b.type = 'button';
			b.addEventListener('click', undo);
			box.appendChild(b);
		}
		box.hidden = false;
	}

	function optimistic(prev, next, method, path, body) {
		G.wr(next);
		if (!c.loggedIn) {
			return Promise.resolve(next);
		}
		return G.api(method, path, body).catch(function () {
			G.wr(prev);
		});
	}

	function commitPending() {
		if (!pending) {
			return;
		}
		var p = pending;
		pending = null;
		clearTimeout(p.timer);
		if (!c.loggedIn) {
			return;
		}
		G.api('DELETE', '/garage/' + encodeURIComponent(p.slug)).catch(function () {
			G.wr(p.prev);
		});
	}

	function remove(bike) {
		commitPending();
		var prev = G.rd();
		var next = G.rd();
		next.bikes = next.bikes.filter(function (x) {
			return x.slug !== bike.slug;
		});
		delete next.profiles[bike.slug];
		if (next.active === bike.slug) {
			next.active = next.bikes[0] ? next.bikes[0].slug : '';
		}
		G.wr(next);
		pending = { slug: bike.slug, prev: prev, timer: setTimeout(commitPending, UNDO_MS) };
		notice(String(t.removedBike || '%s').replace('%s', bike.label), function () {
			if (pending && pending.slug === bike.slug) {
				clearTimeout(pending.timer);
				G.wr(pending.prev);
				pending = null;
			}
			notice('');
		});
	}

	function draw() {
		var wrap = D.querySelector('.motored-garage-list');
		if (!wrap) {
			return;
		}
		var s = G.rd();
		var page = wrap.closest('[data-shop-url]');
		var shop = (page && page.dataset.shopUrl) || c.shopUrl || '/';
		wrap.textContent = '';
		if (!s.bikes.length) {
			var empty = el('div', 'motored-garage-empty');
			empty.appendChild(el('p', 'motored-garage-empty__title', t.emptyGarage || ''));
			if (t.emptyGarageHint) {
				empty.appendChild(el('p', 'motored-garage-empty__hint', t.emptyGarageHint));
			}
			wrap.appendChild(empty);
			return;
		}
		var list = el('ul', 'motored-garage-cards');
		s.bikes.forEach(function (bike) {
			var isActive = s.active === bike.slug;
			var card = el('li', 'motored-garage-card' + (isActive ? ' is-active' : ''));
			var head = el('div', 'motored-garage-card__head');
			head.appendChild(el('strong', 'motored-garage-card__name', bike.label));
			if (isActive) {
				head.appendChild(el('span', 'motored-garage-badge', t.activeBadge || ''));
			}
			card.appendChild(head);
			var pf = s.profiles[bike.slug];
			if (pf && pf.oem && (pf.oem.f || pf.oem.r)) {
				var parts = [];
				if (pf.oem.f) {
					parts.push(t.front + ' ' + pf.oem.f);
				}
				if (pf.oem.r) {
					parts.push(t.rear + ' ' + pf.oem.r);
				}
				card.appendChild(el('p', 'motored-garage-card__sizes', (t.oemSizes ? t.oemSizes + ' ' : '') + parts.join(' · ')));
			}
			var actions = el('div', 'motored-garage-card__actions');
			var view = el('a', 'button alt wp-element-button', t.filterTires || '');
			view.href = (pf && pf.pageUrl) ? pf.pageUrl : withMoto(bike.slug, shop);
			actions.appendChild(view);
			if (!isActive) {
				var use = el('button', 'button wp-element-button', t.useBike || '');
				use.type = 'button';
				use.addEventListener('click', function () {
					var prev = G.rd();
					var next = G.rd();
					next.active = bike.slug;
					optimistic(prev, next, 'POST', '/garage', { action: 'set_active', slug: bike.slug });
				});
				actions.appendChild(use);
			}
			var del = el('button', 'button wp-element-button motored-garage-card__remove', t.remove || '');
			del.type = 'button';
			del.addEventListener('click', function () {
				remove(bike);
			});
			actions.appendChild(del);
			card.appendChild(actions);
			list.appendChild(card);
		});
		wrap.appendChild(list);
	}

	D.addEventListener('motored:garage', draw);
	D.addEventListener('motored:garage-error', function (event) {
		notice((event.detail === 'session' ? t.sessionExpired : t.actionFailed) || '');
	});
	D.addEventListener('motored:garage-unavailable', function (event) {
		var labels = Array.isArray(event.detail) ? event.detail.join(', ') : '';
		if (labels && t.bikeUnavailable) {
			notice(t.bikeUnavailable.replace('%s', labels));
		}
	});
	// Leaving the page inside the undo window still deletes (keepalive request).
	window.addEventListener('pagehide', commitPending);
	draw();

})();
