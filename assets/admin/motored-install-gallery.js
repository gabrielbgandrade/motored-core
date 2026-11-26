/**
 * Admin: "Installation photos" box of the product screen.
 *
 * Opens the native WordPress media modal (`wp.media`) to pick gallery images, lets the user edit
 * each photo's caption inline, reorder items with left/right arrows, or unlink them from the product.
 * No jQuery or external libraries of our own: WordPress loads `media-views` through the script handle.
 */
(function () {
	'use strict';

	var frame = null;

	function logError(message, error) {
		if (window.console && typeof window.console.error === 'function') {
			if (typeof error === 'undefined') {
				window.console.error('[Motored InstallGallery] ' + message);
			} else {
				window.console.error('[Motored InstallGallery] ' + message, error);
			}
		}
	}

	function partsOf(box) {
		var input = box.querySelector('input[name*="[ids]"]') || box.querySelector('input[name$="[ids]"]');
		var list  = box.querySelector('.motored-box__thumbs');
		return {
			box: box,
			input: input,
			list: list,
			countEl: box.querySelector('[data-count]'),
			data: box.dataset
		};
	}

	function sync(p) {
		if (!p.input || !p.list) {
			return;
		}
		var items = Array.prototype.slice.call(p.list.children);
		var ids = items.map(function (li, i) {
			var prev = li.querySelector('button[data-prev]');
			var next = li.querySelector('button[data-next]');
			if (prev) {
				prev.disabled = i === 0;
			}
			if (next) {
				next.disabled = i === items.length - 1;
			}
			return li.dataset.id;
		});
		p.input.value = ids.join(',');
		if (p.countEl) {
			p.countEl.textContent = ids.length + ' / ' + (+p.data.max || 12);
		}
	}

	function makeBtn(attr, text, label, extraClass) {
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'button button-small' + (extraClass ? ' ' + extraClass : '');
		btn.setAttribute(attr, '1');
		btn.textContent = text;
		if (label) {
			btn.setAttribute('aria-label', label);
		}
		return btn;
	}

	function buildItem(att, data) {
		var id = String(att.id);
		var li = document.createElement('li');
		li.dataset.id = id;

		var img = new Image();
		var thumb = att.sizes && (att.sizes.thumbnail || att.sizes.medium) ? (att.sizes.thumbnail || att.sizes.medium) : att;
		img.src = thumb.url || '';
		img.alt = att.alt || '';

		var capInput = document.createElement('input');
		capInput.type = 'text';
		capInput.className = 'motored-box__cap-input';
		capInput.name = 'motored_igallery[captions][' + id + ']';
		capInput.value = (att.caption || '').trim();
		capInput.maxLength = 160;
		capInput.placeholder = data.capPh || '';
		if (data.capLbl) {
			capInput.setAttribute('aria-label', data.capLbl);
		}

		var controls = document.createElement('span');
		controls.className = 'motored-box__controls';
		controls.append(
			makeBtn('data-prev', '\u2190', data.prev, ''),
			makeBtn('data-next', '\u2192', data.next, ''),
			makeBtn('data-rm', '\u00d7', data.rm, 'button-link-delete')
		);
		li.append(img, capInput, controls);
		return li;
	}

	function selectionOf(f) {
		var state = f && typeof f.state === 'function' ? f.state() : null;
		return state && typeof state.get === 'function' ? state.get('selection') : null;
	}

	function buildFrame(media, p) {
		var f = media({
			title: p.data.title,
			button: { text: p.data.btn, close: true },
			library: { type: 'image' },
			multiple: true
		});

		f.on('open', function () {
			var sel = selectionOf(f);
			if (sel && typeof sel.reset === 'function') {
				sel.reset();
			}
		});

		f.on('select', function () {
			var sel = selectionOf(f);
			if (!sel || typeof sel.each !== 'function' || !p.list) {
				return;
			}
			sel.each(function (model) {
				var att = model && typeof model.toJSON === 'function' ? model.toJSON() : {};
				var id = String(att.id || '');
				if (!id || p.list.children.length >= (+p.data.max || 12) || p.list.querySelector('[data-id="' + id + '"]')) {
					return;
				}
				p.list.appendChild(buildItem(att, p.data));
			});
			sync(p);
		});

		return f;
	}

	function waitForMedia(cb) {
		var attempts = 0;
		var maxAttempts = 25;
		var timer = setInterval(function () {
			attempts++;
			var media = window.wp && window.wp.media;
			if (typeof media === 'function') {
				clearInterval(timer);
				cb(media);
			} else if (attempts >= maxAttempts) {
				clearInterval(timer);
				cb(null);
			}
		}, 200);
	}

	function openMediaModal(media, p) {
		try {
			if (!frame) {
				frame = buildFrame(media, p);
			}
			frame.open();
		} catch (err) {
			frame = null;
			logError('Could not open the media modal:', err);
		}
	}

	function openAddMedia(box) {
		var p = partsOf(box);
		if (!p.input || !p.list) {
			logError('Missing input or list in #motored-ig.');
			return;
		}
		var media = window.wp && window.wp.media;
		if (typeof media !== 'function') {
			waitForMedia(function (m) {
				if (!m) {
					logError('wp.media is not available on this screen.');
					return;
				}
				openMediaModal(m, p);
			});
			return;
		}
		openMediaModal(media, p);
	}

	function initAll() {
		var box = document.getElementById('motored-ig');
		if (box) {
			sync(partsOf(box));
		}
	}

	document.addEventListener('click', function (e) {
		var target = e.target;
		if (!target || typeof target.closest !== 'function') {
			return;
		}
		var box = target.closest('#motored-ig');
		if (!box) {
			return;
		}

		// Match buttons specifically so #motored-ig's own data-rm / data-prev / data-next label attributes never match.
		var addBtn = target.closest('button[data-add]');
		if (addBtn && box.contains(addBtn)) {
			e.preventDefault();
			openAddMedia(box);
			return;
		}

		var p = partsOf(box);
		if (!p.input || !p.list) {
			return;
		}

		var rmBtn = target.closest('button[data-rm]');
		if (rmBtn && box.contains(rmBtn)) {
			e.preventDefault();
			var item = rmBtn.closest('li');
			if (item) {
				item.remove();
				sync(p);
			}
			return;
		}

		var prevBtn = target.closest('button[data-prev]');
		if (prevBtn && box.contains(prevBtn)) {
			e.preventDefault();
			var li = prevBtn.closest('li');
			if (li && li.previousElementSibling) {
				p.list.insertBefore(li, li.previousElementSibling);
				sync(p);
			}
			return;
		}

		var nextBtn = target.closest('button[data-next]');
		if (nextBtn && box.contains(nextBtn)) {
			e.preventDefault();
			var cur = nextBtn.closest('li');
			if (cur && cur.nextElementSibling) {
				p.list.insertBefore(cur.nextElementSibling, cur);
				sync(p);
			}
			return;
		}
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAll);
	} else {
		initAll();
	}
})();
