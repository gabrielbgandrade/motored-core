/**
 * InstallGallery storefront: source filter pills + native <dialog> viewer (no library, no jQuery, no theme code).
 * Links are real <a href="large.jpg"> (no-JS and crawler fallback); the capture-phase handler keeps theme
 * lightboxes off our links. Loaded with `defer` only when a gallery renders.
 */
(function () {
	'use strict';
	var D = document;
	var ROOT = 'motored-install-gallery';
	var P = 'motored-ig-dialog__';
	var C = 'motored-ig__';
	var dlg, img, cap, stars, count, review, prevBtn, nextBtn, opener;
	var list = [];
	var idx = 0;
	var labels = {};
	var touchX = 0;

	function el(tag, cls) {
		var e = D.createElement(tag);
		if (cls) {
			e.className = cls;
		}
		return e;
	}

	function all(root, sel) {
		return Array.prototype.slice.call(root.querySelectorAll(sel));
	}

	function button(kind, text, label, onClick) {
		var b = el('button', P + 'btn ' + P + 'btn--' + kind);
		b.type = 'button';
		b.textContent = text;
		b.setAttribute('aria-label', label || text);
		b.addEventListener('click', onClick);
		return b;
	}

	function preload(i) {
		var a = list[(i + list.length) % list.length];
		if (a) {
			new Image().src = a.href;
		}
	}

	function show(n) {
		if (!list.length) {
			return;
		}
		idx = (n + list.length) % list.length;
		var a = list[idx];
		var data = a.dataset;
		var rating = parseInt(data.rating, 10) || 0;
		img.src = a.href;
		img.alt = data.alt || data.cap || '';
		cap.textContent = data.cap || '';
		stars.textContent = rating > 0 ? '★'.repeat(rating) + '☆'.repeat(5 - rating) + ' ' : '';
		count.textContent = idx + 1 + ' / ' + list.length;
		review.hidden = !data.rv;
		review.href = data.rv || '#';
		review.textContent = labels.rv || '';
		prevBtn.hidden = nextBtn.hidden = list.length < 2;
		if (list.length > 1) {
			preload(idx + 1);
		}
	}

	function openReview(e) {
		var hash = review.href.split('#')[1];
		var target = hash && D.getElementById(hash);
		dlg.close();
		if (!target) {
			return; // Different page: let the link navigate.
		}
		e.preventDefault();
		// WooCommerce core tab markup (classic template and the Product Details block alike).
		var tab = D.querySelector('a[href="#tab-reviews"], .reviews_tab a');
		if (tab) {
			tab.click();
		}
		target.scrollIntoView({ behavior: 'smooth', block: 'center' });
	}

	function build() {
		dlg = el('dialog', 'motored-ig-dialog');
		dlg.setAttribute('aria-label', labels.title || '');
		var inner = el('div', P + 'inner');
		var stage = el('div', P + 'stage');
		var meta = el('div', P + 'meta');
		var info = el('p', P + 'cap');
		img = el('img', P + 'img');
		img.decoding = 'async';
		prevBtn = button('prev', '‹', labels.prev, function () {
			show(idx - 1);
		});
		nextBtn = button('next', '›', labels.next, function () {
			show(idx + 1);
		});
		stage.append(img, prevBtn, nextBtn);
		stars = el('span', P + 'stars');
		stars.setAttribute('aria-hidden', 'true');
		cap = el('span');
		info.append(stars, cap);
		count = el('span', P + 'count');
		count.setAttribute('aria-live', 'polite');
		review = el('a', P + 'rv');
		review.addEventListener('click', openReview);
		meta.append(info, count, review);
		inner.append(
			button('close', '×', labels.close, function () {
				dlg.close();
			}),
			stage,
			meta
		);
		dlg.append(inner);
		dlg.addEventListener('click', function (e) {
			if (e.target === dlg) {
				dlg.close(); // Backdrop click.
			}
		});
		dlg.addEventListener('keydown', function (e) {
			var step = e.key === 'ArrowLeft' ? -1 : e.key === 'ArrowRight' ? 1 : 0;
			if (step) {
				e.preventDefault();
				show(idx + step);
			}
		});
		dlg.addEventListener('close', function () {
			img.removeAttribute('src');
			if (opener && opener.focus) {
				opener.focus(); // Return focus to the thumbnail that opened the viewer.
			}
		});
		stage.addEventListener(
			'touchstart',
			function (e) {
				touchX = e.touches[0] ? e.touches[0].clientX : 0;
			},
			{ passive: true }
		);
		stage.addEventListener('touchend', function (e) {
			var t = e.changedTouches[0];
			if (!touchX || !t) {
				return;
			}
			var dx = t.clientX - touchX;
			touchX = 0;
			if (Math.abs(dx) > 40) {
				show(dx > 0 ? idx - 1 : idx + 1);
			}
		});
		D.body.appendChild(dlg);
	}

	/** Hidden items get their <img> only when a filter reveals them (no bytes before). */
	function ensureThumb(a) {
		var data = a.dataset;
		if (a.querySelector('img') || !data.thumb) {
			return;
		}
		var i = el('img', C + 'img');
		i.loading = 'lazy';
		i.decoding = 'async';
		i.src = data.thumb;
		i.alt = data.alt || data.cap || '';
		a.insertBefore(i, a.firstChild);
	}

	function applyFilter(gallery, key) {
		all(gallery, '[data-ig-filter]').forEach(function (b) {
			var on = b.dataset.igFilter === key;
			b.classList.toggle('is-active', on);
			b.setAttribute('aria-pressed', on ? 'true' : 'false');
		});
		all(gallery, 'a[data-motored-g]').forEach(function (a) {
			var data = a.dataset;
			var match = key === 'all' || data.src === key;
			a.hidden = key === 'all' ? data.mode === 'hidden' : !match;
			if (key === 'all') {
				delete data.f;
			} else {
				data.f = '1';
				if (match) {
					ensureThumb(a);
				}
			}
		});
	}

	D.addEventListener(
		'click',
		function (e) {
			var t = e.target;
			if (!t || !t.closest) {
				return;
			}
			var filterBtn = t.closest('[data-ig-filter]');
			if (filterBtn) {
				var g = filterBtn.closest('.' + ROOT);
				if (g) {
					applyFilter(g, filterBtn.dataset.igFilter || 'all');
				}
				return;
			}
			var link = t.closest('a[data-motored-g]');
			// Left click without modifiers only: new-tab clicks keep the native link behavior.
			if (!link || !window.HTMLDialogElement || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) {
				return;
			}
			e.preventDefault();
			e.stopPropagation(); // Capture phase: theme lightboxes never see our links.
			var gallery = link.closest('.' + ROOT);
			var active = gallery && gallery.querySelector('.' + C + 'filter.is-active');
			var key = active ? active.dataset.igFilter : 'all';
			labels = gallery ? gallery.dataset : {};
			list = all(gallery || D, 'a[data-motored-g]').filter(function (a) {
				return key === 'all' || a.dataset.src === key;
			});
			opener = link;
			if (!dlg) {
				build();
			}
			show(Math.max(0, list.indexOf(link)));
			dlg.showModal();
		},
		true
	);
})();
