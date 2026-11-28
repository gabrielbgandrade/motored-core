(function () {
	'use strict';
	// Opens the shared <dialog> with the rows rendered by the server (data attribute, formatted text).
	// Nothing is calculated here. Variation events come from WooCommerce's own jQuery triggers, used only if present.
	var A = 'data-motored-plans', M;

	function dlg() { return M || (M = document.getElementById('motored-plans-dialog')); }

	function fill(v) {
		var b = dlg().querySelector('tbody'), i, r, tr, th, td, s;
		b.textContent = '';
		for (i = 0; i < v.r.length; i++) {
			r = v.r[i];
			tr = b.insertRow();
			th = document.createElement('th');
			th.scope = 'row';
			th.textContent = r[0];
			tr.appendChild(th);
			td = tr.insertCell();
			td.textContent = r[1];
			if (r[2]) {
				s = document.createElement('small');
				s.textContent = r[2];
				td.appendChild(document.createElement('br'));
				td.appendChild(s);
			}
		}
	}

	document.addEventListener('click', function (e) {
		var l = e.target.closest ? e.target.closest('.motored-plans-link') : null, m = dlg(), v;
		if (!m) { return; }
		if (!l) {
			if (e.target === m && m.close) { m.close(); }
			return;
		}
		e.preventDefault();
		try { v = JSON.parse(l.getAttribute(A)); } catch (x) { return; }
		if (!v || !v.r) { return; }
		fill(v);
		if (m.showModal) { m.showModal(); } else { m.setAttribute('open', ''); }
	});

	function update(v, reset) {
		var w = document.querySelector('.motored-plans[data-mv]'), a, l;
		if (!w) { return; }
		a = w.querySelector('.motored-plans-link');
		l = w.querySelector('.motored-plans-line');
		if (!a || !l) { return; }
		var c = w.querySelector('.motored-plans-cash'), p, o;
		if (!w.o) { w.o = { l: l.innerHTML, a: a.getAttribute(A), c: c ? c.innerHTML : '' }; }
		if (v) {
			l.textContent = v.l;
			a.setAttribute(A, JSON.stringify(v));
			// Transfer price of the chosen variation (contract 1.8): update it, or hide it when none discounts.
			if (c) {
				p = c.querySelector('.motored-plans-cash__price');
				o = c.querySelector('.motored-plans-cash__off');
				if (v.c && p) {
					p.textContent = v.c.p;
					if (o) { o.textContent = o.textContent.replace(/^[\d.,]+/, v.c.o); }
				}
				c.hidden = !v.c;
			}
		} else if (reset) {
			l.innerHTML = w.o.l;
			a.setAttribute(A, w.o.a);
			if (c) { c.innerHTML = w.o.c; c.hidden = false; }
		}
		w.hidden = !v && !reset;
	}

	if (window.jQuery) {
		window.jQuery(document)
			.on('found_variation', '.variations_form', function (e, v) { update(v && v.motored_plans, false); })
			.on('reset_data', '.variations_form', function () { update(null, true); });
	}
})();
