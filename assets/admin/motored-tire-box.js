(function () {
	var box = document.getElementById('motored-tire');
	var sel = box && box.querySelector('select[data-motored-pos]');
	if (!sel) {
		return;
	}
	var head = box.querySelector('.postbox-header');
	var wrap = sel.closest('.motored-box__pos');
	if (head && wrap) {
		box.classList.add('motored-box--postbox');
		head.insertBefore(wrap, head.querySelector('.handle-actions'));
		['mousedown', 'click'].forEach(function (ev) {
			wrap.addEventListener(ev, function (e) {
				e.stopPropagation();
			});
		});
	}
	function update() {
		var cur = sel.value || 'none';
		var cells = box.querySelectorAll('[data-motored-for]');
		for (var i = 0; i < cells.length; i++) {
			cells[i].hidden = cells[i].getAttribute('data-motored-for').split(' ').indexOf(cur) < 0;
		}
	}
	sel.addEventListener('change', update);
	update();
	// Load index -> "Up to N kg per tire" while typing (single tire and each kit tire).
	var kgTable = {};
	try {
		kgTable = JSON.parse(wrap ? wrap.getAttribute('data-motored-load-kg') || '{}' : '{}');
	} catch (e) {}
	var kgLabel = wrap ? wrap.getAttribute('data-motored-kg-label') || '%s kg' : '%s kg';
	var kgEmpty = wrap ? wrap.getAttribute('data-motored-kg-empty') || '' : '';
	box.querySelectorAll('[data-motored-load]').forEach(function (input) {
		var hint = input.parentNode.querySelector('[data-motored-load-hint]');
		function showKg() {
			var kg = kgTable[String(parseInt(input.value, 10))];
			if (hint) {
				hint.textContent = kg ? kgLabel.replace('%s', kg) : kgEmpty;
				hint.classList.toggle('motored-field__hint--active', !!kg);
			}
		}
		input.addEventListener('input', showKg);
		showKg();
	});
	// Size suffix ("120/70ZR17 58W" or "(58W)") fills empty load/speed fields live on blur.
	box.querySelectorAll('[data-motored-size]').forEach(function (input) {
		input.addEventListener('blur', function () {
			var m = input.value.toUpperCase().match(/(?:^|[\s(])(\d{2,3})(\(W\)|[JKLMNPQRSTUHVWY])\)?(?=$|[\s)])/);
			if (!m) {
				return;
			}
			var prefix = input.getAttribute('data-motored-size') === 'rear' ? 'motored-tire-rear-' : 'motored-tire-';
			var loadEl = document.getElementById(prefix + 'load');
			var speedEl = document.getElementById(prefix + 'speed');
			var loadNum = parseInt(m[1], 10);
			if (loadEl && !loadEl.value && loadNum >= 20 && loadNum <= 120) {
				loadEl.value = String(loadNum);
				loadEl.dispatchEvent(new Event('input'));
			}
			if (speedEl && !speedEl.value) {
				for (var i = 0; i < speedEl.options.length; i++) {
					if (speedEl.options[i].value === m[2]) {
						speedEl.value = m[2];
						break;
					}
				}
			}
		});
	});
	// Kit: copy what usually repeats (model, construction, tube) from the front tire to the rear tire.
	var copy = box.querySelector('[data-motored-copy-axle]');
	if (copy) {
		copy.addEventListener('click', function () {
			['line', 'const', 'tube'].forEach(function (key) {
				var from = document.getElementById('motored-tire-' + key);
				var to = document.getElementById('motored-tire-rear-' + key);
				if (from && to) {
					to.value = from.value;
				}
			});
		});
	}
})();
