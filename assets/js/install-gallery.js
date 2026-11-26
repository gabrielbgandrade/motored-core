/**
 * InstallGallery storefront: horizontal thumbnail strip with prev/next arrows, source filters,
 * and a split-view native <dialog> lightbox displaying customer reviews alongside photos.
 * Zero jQuery, zero external libraries, zero theme code. Loaded with `defer` only when a gallery renders.
 */
(function () {
	'use strict';
	var D = document;
	var ROOT = 'motored-install-gallery';
	var P = 'motored-ig-dialog__';
	var C = 'motored-ig__';
	var dlg, shell, card, side, foot, footBadge, footCap, footCount, img, count, badge, author, stars, bodyText, reviewLink, prodBox, prodImg, prodName, prevBtn, nextBtn, opener;
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

	function renderStars(rating) {
		stars.textContent = '';
		if (rating < 1) {
			stars.hidden = true;
			return;
		}
		stars.hidden = false;
		stars.setAttribute('aria-label', rating + ' / 5');
		var on = el('span', P + 'stars-on');
		on.textContent = '★'.repeat(rating);
		stars.appendChild(on);
		if (rating < 5) {
			var off = el('span', P + 'stars-off');
			off.textContent = '★'.repeat(5 - rating);
			stars.appendChild(off);
		}
	}

	function show(n) {
		if (!list.length) {
			return;
		}
		idx = (n + list.length) % list.length;
		var a = list[idx];
		var data = a.dataset;
		var isReview = data.src === 'review';
		var rating = parseInt(data.rating, 10) || 0;

		img.src = a.href;
		img.alt = data.alt || data.cap || '';
		var counterText = idx + 1 + ' / ' + list.length;
		count.textContent = counterText;
		footCount.textContent = counterText;

		card.classList.toggle(P + 'card--install', !isReview);
		side.hidden = !isReview;
		foot.hidden = isReview;
		count.hidden = !isReview;

		badge.className = P + 'badge';
		if (isReview) {
			if (data.verified === '1' && labels.verifiedLbl) {
				badge.textContent = '✓ ' + labels.verifiedLbl;
				badge.classList.add(P + 'badge--verified');
			} else {
				badge.textContent = labels.reviewLbl || '';
			}
			var metaParts = [];
			if (data.author) {
				metaParts.push(data.author);
			}
			if (data.date) {
				metaParts.push(data.date);
			}
			author.textContent = metaParts.join(' · ');
			author.hidden = !metaParts.length;
			renderStars(rating);
			bodyText.textContent = data.reviewText || data.cap || '';
			bodyText.hidden = !bodyText.textContent;
			reviewLink.hidden = !data.rv;
			reviewLink.href = data.rv || '#';
			reviewLink.textContent = labels.rv ? labels.rv + ' →' : '';
			badge.hidden = !badge.textContent;

			if (labels.prodName) {
				prodBox.hidden = false;
				prodName.textContent = labels.prodName;
				prodName.href = labels.prodUrl || '#';
				if (labels.prodImg) {
					prodImg.src = labels.prodImg;
					prodImg.alt = labels.prodName;
					prodImg.hidden = false;
				} else {
					prodImg.hidden = true;
				}
			} else {
				prodBox.hidden = true;
			}
		} else {
			footBadge.textContent = labels.installLbl || '';
			footBadge.hidden = !footBadge.textContent;
			footCap.textContent = data.cap || '';
			footCap.hidden = !footCap.textContent;
		}

		prevBtn.hidden = nextBtn.hidden = list.length < 2;
		if (list.length > 1) {
			preload(idx + 1);
		}
	}

	function openReview(e) {
		var hash = reviewLink.href.split('#')[1];
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

	function onProductClick(e) {
		var url = prodName.getAttribute('href') || '';
		if (!url || url === '#') {
			e.preventDefault();
			dlg.close();
			return;
		}
		try {
			var parsed = new URL(url, window.location.href);
			if (parsed.pathname === window.location.pathname) {
				e.preventDefault();
				dlg.close();
			}
		} catch (err) {
			// Let the browser follow the link.
		}
	}

	function build() {
		dlg = el('dialog', 'motored-ig-dialog');
		dlg.setAttribute('aria-label', labels.title || '');

		shell = el('div', P + 'shell');
		var closeBtn = button('close', '×', labels.close, function () {
			dlg.close();
		});
		prevBtn = button('prev', '←', labels.prev, function () {
			show(idx - 1);
		});
		nextBtn = button('next', '→', labels.next, function () {
			show(idx + 1);
		});

		card = el('div', P + 'card');
		var stage = el('div', P + 'stage');
		img = el('img', P + 'img');
		img.decoding = 'async';
		count = el('span', P + 'count');
		count.setAttribute('aria-live', 'polite');
		stage.append(img, count);

		side = el('aside', P + 'side');
		var sideHead = el('div', P + 'side-head');
		badge = el('div', P + 'badge');
		author = el('div', P + 'author');
		sideHead.append(badge, author);

		var sideBody = el('div', P + 'side-body');
		stars = el('div', P + 'stars');
		bodyText = el('p', P + 'text');
		reviewLink = el('a', P + 'rv');
		reviewLink.addEventListener('click', openReview);
		sideBody.append(stars, bodyText, reviewLink);

		prodBox = el('div', P + 'product');
		prodImg = el('img', P + 'prod-img');
		prodImg.loading = 'lazy';
		prodImg.decoding = 'async';
		prodName = el('a', P + 'prod-name');
		prodName.addEventListener('click', onProductClick);
		prodBox.append(prodImg, prodName);

		side.append(sideHead, sideBody, prodBox);

		foot = el('div', P + 'foot');
		var footInfo = el('div', P + 'foot-info');
		footBadge = el('span', P + 'foot-badge');
		footCap = el('span', P + 'foot-cap');
		footInfo.append(footBadge, footCap);
		footCount = el('span', P + 'foot-count');
		footCount.setAttribute('aria-live', 'polite');
		foot.append(footInfo, footCount);

		card.append(stage, side, foot);
		shell.append(closeBtn, prevBtn, nextBtn, card);
		dlg.append(shell);

		dlg.addEventListener('click', function (e) {
			if (e.target === dlg || e.target === shell) {
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

	function updateNav(gallery) {
		var track = gallery.querySelector('[data-ig-track]');
		var prev = gallery.querySelector('[data-ig-nav="-1"]');
		var next = gallery.querySelector('[data-ig-nav="1"]');
		if (!track || !prev || !next) {
			return;
		}
		var overflow = track.scrollWidth > track.clientWidth + 2;
		prev.hidden = !overflow;
		next.hidden = !overflow;
		if (overflow) {
			prev.disabled = track.scrollLeft <= 2;
			next.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 2;
		}
	}

	function initStrips() {
		all(D, '.' + ROOT).forEach(function (gallery) {
			var track = gallery.querySelector('[data-ig-track]');
			if (track && !track.dataset.igBound) {
				track.dataset.igBound = '1';
				track.addEventListener(
					'scroll',
					function () {
						updateNav(gallery);
					},
					{ passive: true }
				);
			}
			updateNav(gallery);
		});
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
		var track = gallery.querySelector('[data-ig-track]');
		if (track) {
			track.scrollLeft = 0;
		}
		updateNav(gallery);
	}

	D.addEventListener(
		'click',
		function (e) {
			var t = e.target;
			if (!t || !t.closest) {
				return;
			}
			var navBtn = t.closest('[data-ig-nav]');
			if (navBtn) {
				var ng = navBtn.closest('.' + ROOT);
				var track = ng && ng.querySelector('[data-ig-track]');
				if (track) {
					var dir = parseInt(navBtn.dataset.igNav, 10) || 1;
					var step = Math.max(Math.round(track.clientWidth * 0.8), 120);
					track.scrollBy({ left: dir * step, behavior: 'smooth' });
				}
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

	var resizeRaf = 0;
	window.addEventListener('resize', function () {
		if (resizeRaf) {
			return;
		}
		resizeRaf = window.requestAnimationFrame(function () {
			resizeRaf = 0;
			initStrips();
		});
	});

	if (D.readyState === 'loading') {
		D.addEventListener('DOMContentLoaded', initStrips);
	} else {
		initStrips();
	}
	window.addEventListener('load', initStrips);
})();
