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
	var pending = null;

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
		D.querySelectorAll('.motored-garage-notice').forEach(function (box) {
			box.textContent = text || '';
			box.hidden = !text;
			if (undo) {
				var b = el('button', 'motored-compat-badge__link', t.undo || '');
				b.type = 'button';
				b.addEventListener('click', undo);
				box.append(' ', b);
			}
		});
	}

	function showNetworkError(error) {
		if (error instanceof TypeError) {
			notice(t.actionFailed);
		}
	}

	function commitPending() {
		if (!pending) {
			return;
		}
		var p = pending;
		pending = null;
		clearTimeout(p.timer);
		notice('');
		if (c.loggedIn) {
			G.api('DELETE', '/garage/' + encodeURIComponent(p.slug)).catch(function (error) {
				G.wr(p.prev);
				showNetworkError(error);
			});
		}
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
				var restore = pending.prev;
				pending = null;
				G.wr(restore);
			}
			notice('');
		});
	}

	function draw() {
		var s = G.rd();
		D.querySelectorAll('.motored-garage-list').forEach(function (wrap) {
			drawList(wrap, s);
		});
	}

	function drawList(wrap, s) {
		var bikes = s.bikes.slice();
		var page = wrap.closest('[data-shop-url]');
		var shop = (page && page.dataset.shopUrl) || c.shopUrl || '/';
		var focusedCard = wrap.contains(D.activeElement) && D.activeElement.closest('.motored-garage-card');
		var focusedBike = focusedCard && focusedCard.dataset.bike;
		var nextFocus;
		wrap.textContent = '';
		if (!bikes.length) {
			var empty = el('div', 'motored-garage-empty');
			empty.appendChild(el('p', 'motored-garage-empty__title', t.emptyGarage || ''));
			if (t.emptyGarageHint) {
				empty.appendChild(el('p', 'motored-garage-empty__hint', t.emptyGarageHint));
			}
			wrap.appendChild(empty);
			if (focusedBike) {
				var addControl = page.querySelector('summary, [data-role="brand"]');
				if (addControl) {
					addControl.focus();
				}
			}
			return;
		}
		var list = el('ul', 'motored-garage-cards');
		bikes.sort(function (a, b) {
			return Number(b.slug === s.active) - Number(a.slug === s.active);
		}).forEach(function (bike) {
			var isActive = s.active === bike.slug;
			var card = el('li', 'motored-garage-card' + (isActive ? ' is-active' : ''));
			card.dataset.bike = bike.slug;
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
			var view = el('a', 'button alt wp-element-button motored-garage-card__primary', t.filterTires || '');
			view.href = (pf && pf.pageUrl) ? pf.pageUrl : withMoto(bike.slug, shop);
			actions.appendChild(view);
			var secondary = el('div', 'motored-garage-card__secondary');
			if (!isActive) {
				var use = el('button', 'motored-garage-card__link', t.useBike || '');
				use.type = 'button';
				use.addEventListener('click', function () {
					commitPending();
					if (c.loggedIn) {
						use.disabled = true;
						G.api('POST', '/garage', { action: 'set_active', slug: bike.slug })
							.catch(showNetworkError).finally(function () { use.disabled = false; });
					} else {
						var next = G.rd();
						next.active = bike.slug;
						G.wr(next);
					}
				});
				secondary.appendChild(use);
			}
			var del = el('button', 'motored-garage-card__link motored-garage-card__remove', t.remove || '');
			del.type = 'button';
			del.setAttribute('aria-label', (t.remove || '') + ' ' + bike.label);
			del.addEventListener('click', function () {
				remove(bike);
			});
			secondary.appendChild(del);
			actions.appendChild(secondary);
			card.appendChild(actions);
			list.appendChild(card);
			if (focusedBike === bike.slug) {
				nextFocus = view;
			}
		});
		wrap.appendChild(list);
		if (focusedBike) {
			(nextFocus || list.querySelector('a')).focus();
		}
	}

	var drawer = D.getElementById('motored-garage-drawer');
	if (drawer && typeof drawer.showModal === 'function') {
		var opener;
		var lastBikeCount = G.rd().bikes.length;
		var add = drawer.querySelector('details');
		var picker = add && add.querySelector('.motored-compat-selector');
		var close = drawer.querySelector('.motored-garage-drawer__close');
		D.querySelectorAll('[data-motored-garage-open]').forEach(function (link) {
			link.setAttribute('aria-haspopup', 'dialog');
			link.setAttribute('aria-controls', drawer.id);
		});
		D.addEventListener('click', function (event) {
			var link = event.target.closest('[data-motored-garage-open]');
			if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
				return;
			}
			event.preventDefault();
			if (drawer.open) {
				return;
			}
			opener = link.getClientRects().length ? link : D.activeElement;
			lastBikeCount = G.rd().bikes.length;
			draw();
			drawer.showModal();
			if (add && !lastBikeCount) {
				add.open = true;
				if (picker) {
					picker.dispatchEvent(new CustomEvent('motored:open-selector'));
				}
			}
			D.documentElement.classList.add('motored-garage-drawer-open');
			if (c.loggedIn) {
				G.api('GET', '/garage').catch(showNetworkError);
			}
		});
		if (add) {
			add.addEventListener('toggle', function () {
				if (add.open && picker) {
					picker.dispatchEvent(new CustomEvent('motored:open-selector'));
				}
			});
		}
		D.addEventListener('motored:garage', function () {
			var count = G.rd().bikes.length;
			if (drawer.open && add && count > lastBikeCount) {
				add.open = false;
			}
			lastBikeCount = count;
		});
		close.addEventListener('click', function () { drawer.close(); });
		drawer.addEventListener('click', function (event) {
			if (event.target !== drawer) {
				return;
			}
			var bounds = drawer.getBoundingClientRect();
			if (event.clientX < bounds.left || event.clientX > bounds.right) {
				drawer.close();
			}
		});
		drawer.addEventListener('close', function () {
			commitPending();
			D.documentElement.classList.remove('motored-garage-drawer-open');
			if (opener) {
				opener.focus({ preventScroll: true });
			}
		});
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
