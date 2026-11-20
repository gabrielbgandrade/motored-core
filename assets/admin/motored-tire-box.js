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
	var sizeInput = document.getElementById('motored-tire-size');
	var sizeLabel = box.querySelector('label[for="motored-tire-size"]');
	function update() {
		var cur = sel.value || 'none';
		var cells = box.querySelectorAll('[data-motored-for]');
		for (var i = 0; i < cells.length; i++) {
			cells[i].hidden = cells[i].getAttribute('data-motored-for').split(' ').indexOf(cur) < 0;
		}
		if (sizeInput && sizeLabel) {
			var nextText = cur === 'kit' ? sizeInput.getAttribute('data-label-kit') : sizeInput.getAttribute('data-label-single');
			if (nextText) {
				sizeLabel.textContent = nextText;
			}
		}
	}
	sel.addEventListener('change', update);
	update();
})();
