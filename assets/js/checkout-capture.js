(function () {
	'use strict';
	var c = window.motoredCapture, form = document.querySelector('form.checkout'), t, last = '', sent = 0;
	if (!c || !form) { return; }

	function field(id) {
		var el = document.getElementById(id);
		return el ? el.value.trim() : '';
	}

	function send(exit) {
		var d = { email: field('billing_email'), phone: field('billing_phone'), name: field('billing_first_name') };
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

	form.addEventListener('input', queue);
	form.addEventListener('change', queue);
	// updated_checkout is a jQuery event: observe the form instead (no jQuery dependency).
	if (window.MutationObserver) {
		new MutationObserver(queue).observe(form, { childList: true, subtree: true });
	}
	window.addEventListener('pagehide', function () { send(true); });
	queue();
}());
