(function () {
	'use strict';
	// Theme-agnostic: only WooCommerce core markup (classic form.checkout, Blocks checkout) and standard
	// input type/autocomplete attributes; no layout ids or theme classes.
	var c = window.motoredCapture, t, last = '', sent = 0;
	var ROOT = 'form.checkout, form.wc-block-checkout__form, .wp-block-woocommerce-checkout';
	if (!c) { return; }

	function root() { return document.querySelector(ROOT); }

	function val(r, sel) {
		var n = r.querySelectorAll(sel), i, v;
		for (i = 0; i < n.length; i++) {
			v = n[i].value.trim();
			if (v) { return v; }
		}
		return '';
	}

	function send(exit) {
		var r = root();
		if (!r) { return; }
		var d = {
			email: val(r, 'input[type="email"], input[autocomplete~="email"]'),
			phone: val(r, 'input[type="tel"], input[autocomplete~="tel"]'),
			name: val(r, 'input[autocomplete~="given-name"]')
		};
		var hasEmail = /^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/.test(d.email);
		var hasPhone = d.phone.replace(/\D/g, '').length >= 8;
		if (!hasEmail && !hasPhone) { return; }
		var key = JSON.stringify(d);
		if (key === last) { return; }
		if (!exit && Date.now() - sent < 10000) { queue(); return; }
		last = key;
		sent = Date.now();
		var body = new URLSearchParams({ email: d.email, phone: d.phone, name: d.name, nonce: c.nonce });
		if (exit && navigator.sendBeacon) {
			navigator.sendBeacon(c.url, body);
		} else if (window.fetch) {
			fetch(c.url, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true }).catch(function () {});
		}
	}

	function queue() {
		clearTimeout(t);
		t = setTimeout(function () { send(false); }, 1500);
	}

	function onEdit(e) {
		var r = root();
		if (r && e.target && r.contains(e.target)) { queue(); }
	}

	// Blocks renders the checkout after this script runs: listen on the document, resolve the form lazily.
	document.addEventListener('input', onEdit);
	document.addEventListener('change', onEdit);
	window.addEventListener('pagehide', function () { send(true); });
	queue();
}());
