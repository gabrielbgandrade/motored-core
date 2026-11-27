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

	// Sets the active motorcycle (REST for accounts, local store for guests).
	function setActive(bike, button) {
		commitPending();
		if (c.loggedIn) {
			button.disabled = true;
			G.api('POST', '/garage', { action: 'set_active', slug: bike.slug })
				.catch(showNetworkError).finally(function () { button.disabled = false; });
		} else {
			var next = G.rd();
			next.active = bike.slug;
			G.wr(next);
		}
	}

	function sizesLine(pf) {
		if (!pf || !pf.oem || !(pf.oem.f || pf.oem.r)) {
			return null;
		}
		var parts = [];
		if (pf.oem.f) {
			parts.push(t.front + ' ' + pf.oem.f);
		}
		if (pf.oem.r) {
			parts.push(t.rear + ' ' + pf.oem.r);
		}
		return el('p', 'motored-garage-card__sizes', (t.oemSizes ? t.oemSizes + ' ' : '') + parts.join(' · '));
	}

	// Centered dialog (theme support "motored-garage-modal"): one active motorcycle or the picker.
	var picking = false;

	function openPicker(page) {
		var form = page && page.querySelector('[data-role="garage-pick"] .motored-compat-selector');
		if (form) {
			form.dispatchEvent(new CustomEvent('motored:open-selector'));
		}
	}

	function setPick(page, show, cancellable) {
		var pick = page && page.querySelector('[data-role="garage-pick"]');
		if (!pick) {
			return;
		}
		pick.hidden = !show;
		var list = page.querySelector('.motored-garage-list');
		if (list) {
			list.hidden = show && cancellable;
		}
		var cancel = pick.querySelector('[data-role="garage-pick-cancel"]');
		if (cancel) {
			cancel.hidden = !cancellable;
		}
	}

	function drawCurrent(wrap, s) {
		var page = wrap.closest('[data-shop-url]');
		var shop = (page && page.dataset.shopUrl) || c.shopUrl || '/';
		var bikes = s.bikes.slice();
		wrap.textContent = '';
		if (!bikes.length) {
			picking = false;
			setPick(page, true, false);
			return;
		}
		setPick(page, picking, true);
		var bike = bikes.filter(function (b) { return b.slug === s.active; })[0] || bikes[0];
		var pf = s.profiles[bike.slug];
		var box = el('div', 'motored-garage-current');
		box.dataset.bike = bike.slug;
		var links = el('p', 'motored-garage-current__links');
		var change = el('button', 'motored-garage-current__link', t.changeBike || '');
		change.type = 'button';
		change.addEventListener('click', function () {
			commitPending();
			picking = true;
			setPick(page, true, true);
			openPicker(page);
			var first = page.querySelector('[data-role="garage-pick"] [data-role="brand"]');
			if (first) {
				first.focus();
			}
		});
		var sep = el('span', 'motored-garage-current__sep', '/');
		sep.setAttribute('aria-hidden', 'true');
		var del = el('button', 'motored-garage-current__link motored-garage-current__remove', t.removeBike || t.remove || '');
		del.type = 'button';
		del.setAttribute('aria-label', (t.removeBike || t.remove || '') + ' ' + bike.label);
		del.addEventListener('click', function () {
			remove(bike);
		});
		links.append(change, sep, del);
		box.appendChild(links);
		box.appendChild(el('p', 'motored-garage-current__name', bike.label));
		var sizes = sizesLine(pf);
		if (sizes) {
			box.appendChild(sizes);
		}
		var view = el('a', 'button alt wp-element-button motored-garage-current__primary', t.filterTires || '');
		view.href = (pf && pf.pageUrl) ? pf.pageUrl : withMoto(bike.slug, shop);
		box.appendChild(view);
		wrap.appendChild(box);
		var others = bikes.filter(function (b) { return b.slug !== bike.slug; });
		if (others.length) {
			var more = el('div', 'motored-garage-others');
			more.appendChild(el('p', 'motored-garage-others__title', t.otherBikes || ''));
			var list = el('ul', 'motored-garage-others__list');
			others.forEach(function (other) {
				var item = el('li', 'motored-garage-others__item');
				item.appendChild(el('span', 'motored-garage-others__name', other.label));
				var use = el('button', 'motored-garage-current__link motored-garage-others__use', t.useBike || '');
				use.type = 'button';
				use.addEventListener('click', function () {
					setActive(other, use);
				});
				item.appendChild(use);
				list.appendChild(item);
			});
			more.appendChild(list);
			wrap.appendChild(more);
		}
	}

	function draw() {
		var s = G.rd();
		D.querySelectorAll('.motored-garage-list').forEach(function (wrap) {
			drawList(wrap, s);
		});
	}

	function drawList(wrap, s) {
		if (wrap.closest('.motored-garage-page--modal')) {
			drawCurrent(wrap, s);
			return;
		}
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
			var sizes = sizesLine(pf);
			if (sizes) {
				card.appendChild(sizes);
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
					setActive(bike, use);
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
		var lastActive = G.rd().active;
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
			picking = false;
			draw();
			drawer.showModal();
			var modalPick = drawer.querySelector('[data-role="garage-pick"]');
			if (modalPick && !modalPick.hidden) {
				openPicker(modalPick.closest('[data-shop-url]'));
			}
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
		drawer.querySelectorAll('[data-role="garage-pick-cancel"]').forEach(function (cancel) {
			cancel.addEventListener('click', function () {
				picking = false;
				draw();
			});
		});
		D.addEventListener('motored:garage', function () {
			var count = G.rd().bikes.length;
			var active = G.rd().active;
			// A saved or newly chosen motorcycle closes the picker of the centered dialog.
			if (count !== lastBikeCount || active !== lastActive) {
				picking = false;
			}
			lastActive = active;
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
			if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) {
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
