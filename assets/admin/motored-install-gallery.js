/**
 * Admin: "Installation photos" box of the product screen.
 *
 * Opens the native WordPress media modal (`wp.media`) to pick gallery images. No library, no jQuery
 * of our own: WordPress loads `media-views` (and its dependencies) through the script handle.
 */
(function () {
	'use strict';

	var frame = null;

	/**
	 * Logs a diagnostic message without throwing, so a failure never becomes a silent no-op.
	 *
	 * @param {string}          message Message.
	 * @param {Error|undefined} error   Optional error object.
	 */
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
		return {
			box: box,
			input: box.querySelector('input[name$="[ids]"]'),
			list: box.querySelector('.motored-box__thumbs'),
			countEl: box.querySelector('[data-count]'),
			data: box.dataset
		};
	}

	function sync(p) {
		if (!p.input || !p.list) {
			return;
		}
		var ids = [].map.call(p.list.children, function (li) {
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
		var li = document.createElement('li');
		li.dataset.id = String(att.id);
		var img = new Image();
		var thumb = att.sizes && (att.sizes.thumbnail || att.sizes.medium) ? (att.sizes.thumbnail || att.sizes.medium) : att;
		img.src = thumb.url || '';
		img.alt = att.alt || '';
		var cap = document.createElement('span');
		var text = (att.caption || '').trim();
		cap.className = 'motored-box__cap' + (text ? '' : ' motored-box__cap--empty');
		cap.textContent = text || data.nocap || '';
		var controls = document.createElement('span');
		controls.className = 'motored-box__controls';
		controls.append(
			makeBtn('data-prev', '\u2190', data.prev, ''),
			makeBtn('data-next', '\u2192', data.next, ''),
			makeBtn('data-rm', '\u00d7', data.rm, 'button-link-delete')
		);
		li.append(img, cap, controls);
		return li;
	}

	/**
	 * Returns the current selection of the frame, guarding every step (state may be null while opening).
	 *
	 * @param {Object} f Media frame.
	 * @return {Object|null}
	 */
	function selectionOf(f) {
		var state = f && typeof f.state === 'function' ? f.state() : null;
		return state && typeof state.get === 'function' ? state.get('selection') : null;
	}

	/**
	 * Builds the media frame once (native WordPress pattern: title + button + image library + multiple).
	 *
	 * @param {Function} media `wp.media` factory.
	 * @param {Object}   p     Box parts.
	 * @return {Object} Frame.
	 */
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

	document.addEventListener('click', function (e) {
		var target = e.target;
		if (!target || typeof target.closest !== 'function') {
			return;
		}
		var box = target.closest('#motored-ig');
		if (!box) {
			return;
		}
		var p = partsOf(box);
		if (!p.input || !p.list) {
			return;
		}

		var rmBtn = target.closest('[data-rm]');
		if (rmBtn) {
			e.preventDefault();
			var item = rmBtn.closest('li');
			if (item) {
				item.remove();
				sync(p);
			}
			return;
		}

		var prevBtn = target.closest('[data-prev]');
		if (prevBtn) {
			e.preventDefault();
			var li = prevBtn.closest('li');
			if (li && li.previousElementSibling) {
				p.list.insertBefore(li, li.previousElementSibling);
				sync(p);
			}
			return;
		}

		var nextBtn = target.closest('[data-next]');
		if (nextBtn) {
			e.preventDefault();
			var cur = nextBtn.closest('li');
			if (cur && cur.nextElementSibling) {
				p.list.insertBefore(cur.nextElementSibling, cur);
				sync(p);
			}
			return;
		}

		if (!target.closest('[data-add]')) {
			return;
		}
		e.preventDefault();

		// `wp.media` is provided by `media-editor` (via `wp_enqueue_media`); check at click time.
		var media = window.wp && window.wp.media;
		if (typeof media !== 'function') {
			logError(
				'wp.media is not available, so the media modal cannot open. Check that media-editor is loaded on this screen.'
			);
			return;
		}

		try {
			if (!frame) {
				frame = buildFrame(media, p);
			}
			frame.open();
		} catch (err) {
			// A broken frame must not be reused; report instead of silently doing nothing.
			frame = null;
			logError('Could not open the media modal:', err);
		}
	});
})();
