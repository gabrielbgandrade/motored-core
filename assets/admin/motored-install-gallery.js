(function () {
	var box = document.getElementById('motored-ig'), frame;
	if (!box) {
		return;
	}
	var input = box.querySelector('input[name$="[ids]"]');
	var list = box.querySelector('.motored-box__thumbs');
	var countEl = box.querySelector('[data-count]');
	var data = box.dataset;
	if (!input || !list) {
		return;
	}

	function sync() {
		var ids = [].map.call(list.children, function (li) { return li.dataset.id; });
		input.value = ids.join(',');
		if (countEl) {
			countEl.textContent = ids.length + ' / ' + (+data.max || 12);
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

	function buildItem(att) {
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

	box.addEventListener('click', function (e) {
		var rmBtn = e.target.closest('[data-rm]');
		if (rmBtn) {
			e.preventDefault();
			var item = rmBtn.closest('li');
			if (item) {
				item.remove();
				sync();
			}
			return;
		}
		var prevBtn = e.target.closest('[data-prev]');
		if (prevBtn) {
			e.preventDefault();
			var li = prevBtn.closest('li');
			if (li && li.previousElementSibling) {
				list.insertBefore(li, li.previousElementSibling);
				sync();
			}
			return;
		}
		var nextBtn = e.target.closest('[data-next]');
		if (nextBtn) {
			e.preventDefault();
			var cur = nextBtn.closest('li');
			if (cur && cur.nextElementSibling) {
				list.insertBefore(cur.nextElementSibling, cur);
				sync();
			}
			return;
		}
		if (!e.target.closest('[data-add]')) {
			return;
		}
		e.preventDefault();
		if (!window.wp || !window.wp.media) {
			return;
		}
		if (!frame) {
			frame = wp.media({
				title: data.title,
				button: { text: data.btn },
				library: { type: 'image' },
				multiple: 'add'
			});
			frame.on('open', function () {
				var sel = frame.state().get('selection');
				if (sel) {
					sel.reset();
				}
			});
			frame.on('select', function () {
				var sel = frame.state().get('selection');
				if (!sel) {
					return;
				}
				sel.each(function (model) {
					var att = model.toJSON();
					var id = String(att.id || '');
					if (!id || list.children.length >= (+data.max || 12) || list.querySelector('[data-id="' + id + '"]')) {
						return;
					}
					list.appendChild(buildItem(att));
				});
				sync();
			});
		}
		frame.open();
	});
})();
