/**
 * Mi Garage client store + cache-safe compatibility hydration (badges, product banner, shop CTA, bar switch).
 *
 * Guest storage and the per-account session cache use separate keys.
 * Logged-in shoppers are synced with REST /garage (user meta is the source of truth; local is a cache).
 * `ev()` MUST mirror MatchEngine::evaluate_product(); tests/js/compat-parity.test.js checks both on the same fixture.
 */
(function () {
	'use strict';

	var GUEST_KEY = 'motored_garage_guest_v2';
	var EVENT = 'motored:garage';
	var PRIORITY = { oem: 3, alt: 2, equiv: 1 };
	var BADGE = 'motored-compat-badge';
	var PROFILE_TTL = 432e5; // 12 h

	var c = window.motoredCompatCfg || {};
	var accountKey = 'motored_garage_account_' + (c.accountId || 'current');
	var KEY = c.loggedIn ? accountKey : GUEST_KEY;
	var t = c.i18n || {};
	var base = (c.restBase || '').replace(/\/$/, '');
	var D = document;
	var memory = {};
	var requestQueue = Promise.resolve();

	/* ---------- storage (works with storage disabled: in-memory fallback) ---------- */

	function store(session) {
		try {
			return session ? window.sessionStorage : window.localStorage;
		} catch (e) {
			return null;
		}
	}

	function getItem(session, key) {
		try {
			var v = store(session).getItem(key);
			return v != null ? v : memory[session + key] || null;
		} catch (e) {
			return memory[session + key] || null;
		}
	}

	function setItem(session, key, value) {
		memory[session + key] = value;
		try {
			store(session).setItem(key, value);
		} catch (e) {}
	}

	function removeItem(session, key) {
		delete memory[session + key];
		try {
			store(session).removeItem(key);
		} catch (e) {}
	}

	/** Always returns a well-formed state, whatever is in storage (old format, manual edit, other tab). */
	function normalize(raw) {
		var out = { active: '', bikes: [], profiles: {} };
		if (!raw || typeof raw !== 'object') {
			return out;
		}
		var profiles = raw.profiles && typeof raw.profiles === 'object' ? raw.profiles : {};
		var seen = {};
		(Array.isArray(raw.bikes) ? raw.bikes : []).forEach(function (b) {
			if (b && typeof b.slug === 'string' && b.slug && !seen[b.slug]) {
				seen[b.slug] = 1;
				out.bikes.push({ slug: b.slug, label: String(b.label || b.slug) });
				if (profiles[b.slug] && typeof profiles[b.slug] === 'object') {
					out.profiles[b.slug] = profiles[b.slug];
				}
			}
		});
		out.active = typeof raw.active === 'string' && seen[raw.active] ? raw.active : out.bikes.length ? out.bikes[0].slug : '';
		return out;
	}

	function readState(session, key) {
		try {
			return normalize(JSON.parse(getItem(session, key)));
		} catch (e) {
			return normalize(null);
		}
	}

	function read() {
		return readState(!!c.loggedIn, KEY);
	}

	function write(state) {
		setItem(!!c.loggedIn, KEY, JSON.stringify(normalize(state)));
		D.dispatchEvent(new Event(EVENT));
	}

	function activeProfile() {
		var s = read();
		var profile = s.profiles[s.active];
		return profile && profile.schema === 2 ? profile : null;
	}

	function restUrl(path) {
		var url = new URL(base, window.location.href);
		if (url.searchParams.has('rest_route')) {
			url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/$/, '') + path);
		} else {
			url.pathname = url.pathname.replace(/\/$/, '') + path;
		}
		return url.href;
	}

	/* ---------- matching (mirror of MatchEngine::evaluate_product) ---------- */

	function checkConstraints(req, load, speed, construction) {
		var strict = !!c.strict;
		var caution = false;
		var pairs = [
			[req.l, load, 'min'],
			[req.s, speed, 'min'],
			[req.c, construction, 'eq'],
		];
		for (var i = 0; i < pairs.length; i++) {
			var need = pairs[i][0];
			var have = pairs[i][1];
			if (need == null || need === '') {
				continue;
			}
			if (have == null || have === '') {
				if (strict) {
					return null;
				}
				caution = true;
			} else if (pairs[i][2] === 'min' ? have < need : have !== need) {
				return null;
			}
		}
		return caution;
	}

	/**
	 * @param {Object} fit     Marker payload {p,f,r,fl,rl,fs,rs,c,fc?,rc?}.
	 * @param {Object} profile Client profile {front:{size:{t,f,l,s,c}}, rear:{...}}.
	 * @return {{ok:boolean, ps:Array, m:string, w:boolean, fr:string}}
	 */
	function ev(fit, profile) {
		var pos = fit.p;
		var matched = [];
		var best = '';
		var caution = false;
		var from = [];
		var axes = [
			['front', 'f'],
			['rear', 'r'],
		];
		for (var i = 0; i < 2; i++) {
			var name = axes[i][0];
			var ax = axes[i][1];
			var map = (profile && profile[name]) || {};
			var size = fit[ax];
			var req = size && map[size];
			if (!req || !(pos === name || pos === 'both' || pos === 'kit')) {
				continue;
			}
			// Kit sheet: per-axle construction (fc/rc); otherwise the product-wide one (c).
			var axc = fit[ax + 'c'] != null && fit[ax + 'c'] !== '' ? fit[ax + 'c'] : fit.c;
			var res = checkConstraints(req, fit[ax + 'l'], fit[ax + 's'], axc);
			if (res === null) {
				continue;
			}
			matched.push(name);
			if (!best || (pos === 'kit' ? PRIORITY[req.t] < PRIORITY[best] : PRIORITY[req.t] > PRIORITY[best])) {
				best = req.t;
			}
			caution = caution || res;
			if (req.f && from.indexOf(req.f) < 0) {
				from.push(req.f);
			}
		}
		if (!matched.length || (pos === 'kit' && matched.length < 2)) {
			return { ok: false, ps: [], m: '', w: false, fr: '' };
		}
		return {
			ok: true,
			ps: pos === 'kit' ? ['kit'] : matched,
			m: best,
			w: caution,
			fr: from.join(pos === 'kit' ? ' / ' : ', '),
		};
	}

	/* ---------- REST sync ---------- */

	function api(method, path, body) {
		if (!c.loggedIn || !base) {
			return Promise.resolve(null);
		}
		var opts = {
			method: method,
			credentials: 'same-origin',
			keepalive: method !== 'GET',
			headers: { 'X-WP-Nonce': c.nonce || '' },
		};
		if (body) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}
		var operation = requestQueue.then(function () {
			return fetch(restUrl(path), opts);
		})
			.then(function (r) {
				if (r.status === 401 || r.status === 403) {
					D.dispatchEvent(new CustomEvent('motored:garage-error', { detail: 'session' }));
				}
				if (!r.ok) {
					if (method !== 'GET' && r.status !== 401 && r.status !== 403) {
						D.dispatchEvent(new CustomEvent('motored:garage-error', { detail: 'storage' }));
					}
					throw new Error('HTTP ' + r.status);
				}
				return r.json();
			})
			.then(function (d) {
				if (d && Array.isArray(d.bikes)) {
					var p = d.profiles || {};
					for (var k in p) {
						p[k].ts = Date.now();
					}
					write({ active: d.active || '', bikes: d.bikes, profiles: p });
					if (Array.isArray(d.unavailable) && d.unavailable.length) {
						D.dispatchEvent(new CustomEvent('motored:garage-unavailable', { detail: d.unavailable }));
					}
				}
				return d;
			});
		requestQueue = operation.catch(function () {});
		return operation;
	}

	/** Stores a profile; with add=true it becomes the newest + active bike. Returns the label dropped at capacity. */
	function put(profile, add) {
		var s = read();
		var dropped = '';
		profile.ts = Date.now();
		s.profiles[profile.slug] = profile;
		if (add) {
			var max = Math.max(1, parseInt(c.maxBikes, 10) || 5);
			s.bikes = s.bikes
				.filter(function (x) {
					return x.slug !== profile.slug;
				})
				.concat({ slug: profile.slug, label: profile.label });
			while (s.bikes.length > max) {
				var gone = s.bikes.shift();
				dropped = dropped || gone.label;
				delete s.profiles[gone.slug];
			}
			s.active = profile.slug;
		}
		write(s);
		return dropped;
	}

	function setActive(slug) {
		var prev = read();
		var next = read();
		if (!next.profiles[slug]) {
			return Promise.resolve();
		}
		next.active = slug;
		write(next);
		return api('POST', '/garage', { action: 'set_active', slug: slug }).catch(function () {
			write(prev);
		});
	}

	/* ---------- DOM helpers ---------- */

	function el(tag, cls, text) {
		var e = D.createElement(tag);
		if (cls) {
			e.className = cls;
		}
		if (text != null) {
			e.textContent = text;
		}
		return e;
	}

	// Fills the single sprintf-style placeholder of a translated string (%s for labels, %d for counts).
	function fmt(s, v) {
		return String(s || '').replace(/%[sd]/, String(v));
	}

	function urlWithMoto(slug, pos, from) {
		var u = new URL(from || location.href, location.href);
		u.pathname = u.pathname.replace(/\/page\/\d+\/?$/, '/');
		u.searchParams.delete('paged');
		u.searchParams.delete('product-page');
		u.searchParams.set('moto', slug);
		if (pos) {
			u.searchParams.set('pos', pos);
		} else {
			u.searchParams.delete('pos');
		}
		return u.toString();
	}

	function positionText(r) {
		if (r.ps[0] === 'kit') {
			return t.kit || '';
		}
		return r.ps.length === 2 ? t.front + ' / ' + t.rear : t[r.ps[0]] || '';
	}

	function detailText(r, fit) {
		var sz = '';
		if (fit) {
			if (r.ps[0] === 'kit' && fit.f && fit.r) {
				sz = ' (' + fit.f + ' + ' + fit.r + ')';
			} else if (r.ps.indexOf('front') > -1 && fit.f) {
				sz = ' (' + fit.f + ')';
			} else if (r.ps.indexOf('rear') > -1 && fit.r) {
				sz = ' (' + fit.r + ')';
			}
		}
		return (
			positionText(r) +
			' · ' +
			(t[r.m] || r.m) +
			sz +
			(r.fr ? ': ' + t.equivNote + ' ' + r.fr : '') +
			(r.w ? ' — ' + t.verifySpecs : '')
		);
	}

	function oemText(pf) {
		if (!pf || !pf.oem) {
			return '';
		}
		var parts = [];
		if (pf.oem.f) {
			parts.push(t.front + ' ' + pf.oem.f);
		}
		if (pf.oem.r) {
			parts.push(t.rear + ' ' + pf.oem.r);
		}
		return parts.join(' · ');
	}

	function shopUrl() {
		return c.shopUrl || '/';
	}

	/* ---------- product page picker ---------- */

	function renderSaved(picker, s) {
		var box = picker.querySelector('[data-role="saved-bikes"]');
		if (!box) {
			return;
		}
		box.textContent = '';
		if (s.bikes.length < 2) {
			box.hidden = true;
			return;
		}
		box.hidden = false;
		box.appendChild(el('span', 'motored-compat-banner__saved-label', t.savedBikes || ''));
		var wrap = el('div', 'motored-compat-banner__chips');
		s.bikes.forEach(function (bk) {
			var on = s.active === bk.slug;
			var btn = el('button', 'motored-compat-banner__chip' + (on ? ' is-active' : ''), bk.label);
			btn.type = 'button';
			btn.setAttribute('aria-pressed', on ? 'true' : 'false');
			btn.addEventListener('click', function () {
				var banner = picker.closest('.motored-compat-banner');
				if (banner) {
					banner.dataset.checked = '1';
				}
				picker.hidden = true;
				setActive(bk.slug);
			});
			wrap.appendChild(btn);
		});
		box.appendChild(wrap);
	}

	function pickerButton(picker, label, cls) {
		var btn = el('button', cls, label);
		btn.type = 'button';
		btn.setAttribute('aria-expanded', 'false');
		btn.addEventListener('click', function () {
			picker.hidden = !picker.hidden;
			btn.setAttribute('aria-expanded', picker.hidden ? 'false' : 'true');
			if (!picker.hidden) {
				renderSaved(picker, read());
				var form = picker.querySelector('.motored-compat-selector');
				if (form) {
					form.dispatchEvent(new Event('motored:open-selector'));
				}
			}
		});
		return btn;
	}

	/* ---------- renderers ---------- */

	function drawLoopBadge(marker, fit, pf) {
		var holder = marker.parentNode;
		if (!holder) {
			return;
		}
		var old = holder.querySelector('.' + BADGE + '--loop');
		if (old) {
			old.remove();
		}
		if (!pf) {
			return;
		}
		var r = ev(fit, pf);
		var badge;
		if (r.ok) {
			badge = el('p', BADGE + ' ' + BADGE + '--ok ' + BADGE + '--loop', '✓ ' + fmt(t.compatLoop || t.compatWith, pf.label) + ' · ' + positionText(r));
			badge.title = detailText(r, fit);
		} else if (c.showIncomp) {
			badge = el('p', BADGE + ' ' + BADGE + '--no ' + BADGE + '--loop', fmt(t.incompatWith, pf.label));
		}
		if (badge) {
			holder.insertBefore(badge, marker.nextSibling);
		}
	}

	function drawBanner(marker, fit, pf) {
		var banner = marker.closest('.motored-compat-banner');
		var status = banner && banner.querySelector('.motored-compat-banner__status');
		var picker = banner && banner.querySelector('[data-role="picker"]');
		if (!status) {
			return;
		}
		status.textContent = '';
		if (!pf) {
			var ask = el('div', BADGE + ' ' + BADGE + '--empty');
			ask.appendChild(el('p', BADGE + '__title', t.askFit || ''));
			if (picker) {
				ask.appendChild(pickerButton(picker, t.pickBike || '', 'button wp-element-button ' + BADGE + '__btn'));
			}
			status.appendChild(ask);
			return;
		}
		var r = ev(fit, pf);
		var actions = el('div', BADGE + '__actions');
		var allTarget = function (pos) {
			if (pf.pageUrl) {
				if (pos === 'front') {
					return pf.pageUrl + '#delanteras';
				}
				if (pos === 'rear') {
					return pf.pageUrl + '#traseras';
				}
				return pf.pageUrl;
			}
			return urlWithMoto(pf.slug, pos || '', shopUrl());
		};
		var box;
		if (r.ok) {
			box = el('div', BADGE + ' ' + BADGE + '--ok');
			// A kit is not "una cubierta": say what the shopper is looking at.
			var okTpl = r.ps[0] === 'kit' && t.compatWithKit ? t.compatWithKit : t.compatWith;
			box.appendChild(el('p', BADGE + '__title', '✓ ' + fmt(okTpl, pf.label)));
			box.appendChild(el('p', BADGE + '__meta', detailText(r, fit)));
			var totalCnt = pf.counts && typeof pf.counts.all === 'number' ? pf.counts.all : null;
			var allLabel = totalCnt !== null && t.seeCompatCount ? fmt(t.seeCompatCount, totalCnt) : (t.seeCompat || '');
			var all = el('a', BADGE + '__link', allLabel);
			all.href = allTarget('');
			actions.appendChild(all);
			// Pair suggestion: only link when the other axle actually has products; otherwise offer demand notify.
			if (fit.p === 'front' || fit.p === 'rear') {
				var other = fit.p === 'front' ? 'rear' : 'front';
				var otherCnt = pf.counts && typeof pf.counts[other] === 'number' ? pf.counts[other] : null;
				if (otherCnt === null || otherCnt > 0) {
					var pairTpl = otherCnt !== null
						? (other === 'rear' ? (t.pairRearCount || t.pairRear) : (t.pairFrontCount || t.pairFront))
						: (other === 'rear' ? t.pairRear : t.pairFront);
					var pairText = otherCnt !== null ? fmt(pairTpl, otherCnt) : fmt(pairTpl, pf.label);
					var pair = el('a', BADGE + '__link', pairText);
					pair.href = allTarget(other);
					actions.appendChild(pair);
				} else {
					var missingText = fmt(other === 'rear' ? t.noPairRear : t.noPairFront, pf.label);
					var missingWrap = el('div', BADGE + '__missing');
					missingWrap.appendChild(el('span', BADGE + '__missing-text', missingText + ' '));
					missingWrap.appendChild(buildNotifyForm(pf.slug, other, pf.label));
					box.appendChild(missingWrap);
				}
			}
		} else {
			// With an active bike the product page always tells the truth (no "select your bike" here).
			box = el('div', BADGE + ' ' + BADGE + '--no');
			box.appendChild(el('p', BADGE + '__title', fmt(t.incompatWith, pf.label)));
			var oem = oemText(pf);
			if (oem) {
				box.appendChild(el('p', BADGE + '__meta', (t.yourBikeUses ? t.yourBikeUses + ' ' : '') + oem));
			}
			var seeCnt = pf.counts && typeof pf.counts.all === 'number' ? pf.counts.all : null;
			var seeLbl = seeCnt !== null && t.seeCompatCount ? fmt(t.seeCompatCount, seeCnt) : (t.seeCompat || '');
			var see = el('a', 'button alt wp-element-button ' + BADGE + '__btn', seeLbl);
			see.href = allTarget('');
			actions.appendChild(see);
		}
		if (picker) {
			actions.appendChild(pickerButton(picker, t.changeBike || '', BADGE + '__link'));
		}
		if (c.loggedIn && c.garageUrl) {
			var gLink = el('a', BADGE + '__link', t.viewGarage || '');
			gLink.href = c.garageUrl;
			actions.appendChild(gLink);
		}
		box.appendChild(actions);
		status.appendChild(box);
	}

	function drawCta(s) {
		D.querySelectorAll('[data-role="garage-cta"]').forEach(function (box) {
			var pf = s.profiles[s.active];
			box.textContent = '';
			if (!c.garageCta || !pf) {
				box.hidden = true;
				return;
			}
			box.appendChild(el('span', 'motored-garage-cta__lead', fmt(t.ctaLead, pf.label) + ' '));
			var link = el('a', 'motored-garage-cta__link', t.ctaLink || '');
			link.href = urlWithMoto(pf.slug, '');
			link.addEventListener('click', function () {
				window.motoredUi && window.motoredUi.track('garage_cta', { moto: pf.slug });
			});
			box.appendChild(link);
			box.hidden = false;
		});
	}

	function drawBarSwitch(s) {
		D.querySelectorAll('[data-role="bar-switch"]').forEach(function (box) {
			var bar = box.closest('.motored-compat-bar');
			var current = bar ? bar.dataset.moto : '';
			var others = s.bikes.filter(function (b) {
				return b.slug !== current;
			});
			box.textContent = '';
			if (!others.length) {
				box.hidden = true;
				return;
			}
			box.appendChild(el('span', 'motored-compat-bar__switch-label', t.otherBikes || ''));
			others.forEach(function (bk) {
				var a = el('a', 'motored-compat-banner__chip', bk.label);
				a.href = urlWithMoto(bk.slug, '');
				a.addEventListener('click', function () {
					setActive(bk.slug);
				});
				box.appendChild(a);
			});
			box.hidden = false;
		});
	}

	function buildNotifyForm(moto, pos, label) {
		var box = el('div', 'motored-notify-form');
		if (!c.notifyEnabled) return box;
		var trigger = el('button', 'button alt wp-element-button', t.notifySubmit || '');
		trigger.type = 'button';
		box.appendChild(trigger);
		var result = el('p', 'motored-notify-form__msg');
		result.setAttribute('role', 'status');
		result.hidden = true;
		box.appendChild(result);
		if (c.loggedIn && c.accountPhone) box.appendChild(el('small', '', t.notifyAccountConsent || ''));

		function subscribe(contact, website, submit, message) {
			submit.disabled = true;
			var headers = { 'Content-Type': 'application/json', 'X-Motored-Nonce': c.notifyNonce || '' };
			if (c.loggedIn && c.nonce) headers['X-WP-Nonce'] = c.nonce;
			return fetch(restUrl('/compat/notify'), {
				method: 'POST', credentials: 'same-origin', headers: headers,
				body: JSON.stringify({ moto: moto, position: pos || 'all', contact: contact, website: website, consent: true }),
			}).then(function (r) {
				return r.json().then(function (data) {
					if (!r.ok || !data.ok) throw new Error(data.error || t.notifyErr);
					message.textContent = data.message || t.notifyOk;
					message.className = 'motored-notify-form__msg is-ok';
					message.hidden = false;
					trigger.disabled = true;
					if (c.events) {
						window.dataLayer = window.dataLayer || [];
						window.dataLayer.push({ event: 'notify_submit', moto: moto, pos: pos || 'all' });
					}
				});
			}).catch(function (error) {
				message.textContent = error.message || t.notifyErr;
				message.className = 'motored-notify-form__msg is-error';
				message.hidden = false;
			}).finally(function () { submit.disabled = submit === trigger ? trigger.disabled && !result.classList.contains('is-error') : false; });
		}

		trigger.addEventListener('click', function () {
			if (c.loggedIn && c.accountPhone) {
				subscribe('', '', trigger, result);
				return;
			}
			// Guests, and accounts without a phone: WhatsApp is the main channel, so ask for it here.
			var askPhone = !!c.loggedIn;
			var dialog = D.createElement('dialog');
			dialog.className = 'motored-notify-dialog';
			dialog.setAttribute('aria-label', t.notifyTitle || t.notifySubmit);
			var close = el('button', 'motored-notify-dialog__close', t.notifyClose || '');
			close.type = 'button';
			close.addEventListener('click', function () { dialog.close(); });
			dialog.appendChild(close);
			dialog.appendChild(el('h2', '', t.notifyTitle || ''));
			if (label) dialog.appendChild(el('p', '', label));
			dialog.appendChild(el('p', '', (askPhone ? t.notifyAddPhone : t.notifyPriority) || ''));
			var form = el('form', 'motored-notify-form');
			var inputLabel = el('label', '', (askPhone ? t.notifyPhone : t.notifyContact) || '');
			var input = D.createElement('input');
			input.type = askPhone ? 'tel' : 'text'; input.required = !askPhone; input.maxLength = 191;
			input.autocomplete = 'tel'; input.placeholder = (askPhone ? t.notifyPhone : t.notifyContact) || '';
			input.className = 'motored-notify-form__input';
			inputLabel.appendChild(input); form.appendChild(inputLabel);
			var hp = D.createElement('input');
			hp.type = 'text'; hp.name = 'website'; hp.tabIndex = -1; hp.autocomplete = 'off';
			hp.className = 'motored-notify-form__honeypot'; hp.setAttribute('aria-hidden', 'true');
			form.appendChild(hp);
			if (askPhone) {
				form.appendChild(el('small', '', t.notifyAccountConsent || ''));
			} else {
				var consent = el('label', 'motored-notify-form__consent');
				var check = D.createElement('input'); check.type = 'checkbox'; check.required = true;
				consent.appendChild(check); consent.appendChild(D.createTextNode(' ' + (t.notifyConsent || '')));
				form.appendChild(consent);
			}
			var submit = el('button', 'button alt wp-element-button', t.notifySubmit || '');
			submit.type = 'submit'; form.appendChild(submit);
			var message = el('p', 'motored-notify-form__msg');
			message.setAttribute('role', 'status'); message.hidden = true; form.appendChild(message);
			if (!askPhone) {
				var account = el('a', '', t.notifyRegister || '');
				account.href = c.accountUrl || c.garageUrl; form.appendChild(account);
			}
			form.addEventListener('submit', function (event) {
				event.preventDefault();
				if (!form.reportValidity()) return;
				subscribe(input.value.trim(), hp.value, submit, message).then(function () {
					if (message.classList.contains('is-ok')) {
						input.value = ''; submit.disabled = true;
						result.textContent = message.textContent; result.className = message.className; result.hidden = false;
					}
				});
			});
			dialog.appendChild(form); D.body.appendChild(dialog);
			dialog.addEventListener('close', function () { dialog.remove(); trigger.focus(); });
			dialog.addEventListener('click', function (event) { if (event.target === dialog) {
				var rect = dialog.getBoundingClientRect();
				if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
			} });
			dialog.showModal(); input.focus();
		});
		return box;
	}

	function drawNotifyMounts() {
		D.querySelectorAll('[data-motored-notify]').forEach(function (box) {
			if (box.dataset.mounted === '1') {
				return;
			}
			box.dataset.mounted = '1';
			box.appendChild(buildNotifyForm(box.dataset.moto || '', box.dataset.pos || 'all', box.dataset.label || ''));
		});
	}

	function drawBikePageChips(s) {
		D.querySelectorAll('[data-motored-bike-chip]').forEach(function (chip) {
			chip.hidden = !s.active || s.active !== chip.dataset.slug;
		});
		D.querySelectorAll('[data-motored-use-bike]').forEach(function (btn) {
			if (btn.dataset.bound === '1') {
				return;
			}
			btn.dataset.bound = '1';
			btn.addEventListener('click', function () {
				var slug = btn.dataset.motoredUseBike || '';
				if (!slug || !base) {
					return;
				}
				btn.disabled = true;
				fetch(restUrl('/compat/bike/' + encodeURIComponent(slug)))
					.then(function (r) {
						return r.ok ? r.json() : null;
					})
					.then(function (p) {
						btn.disabled = false;
						if (p && p.slug) {
							window.motoredGarageSave(p);
						}
					})
					.catch(function () {
						btn.disabled = false;
					});
			});
		});
	}

	function draw() {
		var s = read();
		var pf = activeProfile();
		D.querySelectorAll('.motored-fit-marker').forEach(function (marker) {
			var fit;
			try {
				fit = JSON.parse(marker.dataset.motoredFit);
			} catch (e) {
				return;
			}
			if (marker.dataset.ctx === 'single') {
				drawBanner(marker, fit, pf);
			} else {
				drawLoopBadge(marker, fit, pf);
			}
		});
		drawCta(s);
		drawBarSwitch(s);
		drawNotifyMounts();
		drawBikePageChips(s);
	}

	D.addEventListener('click', function (e) {
		var trigger = e.target && e.target.closest ? e.target.closest('[data-motored-notify-trigger]') : null;
		if (!trigger) {
			return;
		}
		var parent = trigger.closest('.motored-compat-badge__missing') || trigger.parentNode;
		if (!parent) {
			return;
		}
		var existing = parent.querySelector('.motored-notify-form');
		if (existing) {
			existing.hidden = !existing.hidden;
			return;
		}
		var notify = buildNotifyForm(trigger.dataset.moto || '', trigger.dataset.pos || 'all', trigger.dataset.label || '');
		trigger.replaceWith(notify);
		var button = notify.querySelector('button');
		if (button) button.click();
	});

	/* ---------- public API ---------- */

	window.motoredGarageActive = activeProfile;
	window.motoredGarageApi = { rd: read, wr: write, api: api, ev: ev, normalize: normalize, fmt: fmt };
	window.motoredGarageSetActive = setActive;
	window.motoredGarageSave = function (profile) {
		if (c.loggedIn) {
			var before = read();
			return api('POST', '/garage', { action: 'add', slug: profile.slug }).then(function (result) {
				var saved = result && result.bikes || [];
				var removed = before.bikes.find(function (bike) {
					return !saved.some(function (entry) { return entry.slug === bike.slug; });
				});
				return { dropped: removed ? removed.label : '' };
			});
		}
		var dropped = put(profile, true);
		return Promise.resolve({ dropped: dropped });
	};

	/* ---------- boot ---------- */

	// Logout on a shared computer: forget the local copy of the account garage.
	if (/(?:^|;\s*)motored_garage_logout=1/.test(D.cookie || '')) {
		removeItem(0, 'motored_garage_v1');
		removeItem(0, GUEST_KEY);
		try {
			var session = store(true);
			for (var i = session.length - 1; i >= 0; i--) {
				var key = session.key(i);
				if (key && key.indexOf('motored_garage_account_') === 0) {
					removeItem(true, key);
				}
			}
		} catch (e) {}
		D.cookie = 'motored_garage_logout=;Max-Age=0;path=/';
	}

	D.addEventListener(EVENT, draw);
	// Another tab changed the garage: re-render here too.
	window.addEventListener('storage', function (e) {
		if (e.key === KEY) {
			D.dispatchEvent(new CustomEvent(EVENT));
		}
	});
	draw();

	var start = read();
	var act = start.profiles[start.active];
	var guest = readState(false, GUEST_KEY);
	var syncedKey = accountKey + '_synced';
	if (base && c.loggedIn && guest.bikes.length) {
		api('POST', '/garage', { action: 'merge', source: 'guest-v2', bikes: guest.bikes, active: guest.active })
			.then(function () {
				if (JSON.stringify(readState(false, GUEST_KEY)) === JSON.stringify(guest)) {
					removeItem(false, GUEST_KEY);
				}
				setItem(true, syncedKey, '1');
			})
			.catch(function () {});
	} else if (base && c.loggedIn && (!getItem(true, syncedKey) || D.querySelector('[data-garage-full-page]'))) {
		api('GET', '/garage').then(function () {
			setItem(true, syncedKey, '1');
		}).catch(function () {});
	} else if (base && start.active && (!act || act.schema !== 2 || !act.ts || Date.now() - act.ts > PROFILE_TTL || !act.oem)) {
		fetch(restUrl('/compat/bike/' + encodeURIComponent(start.active)))
			.then(function (r) {
				if (r.status === 404) {
					// Bike was deleted or reverted to draft: drop the stale entry so the guest is not stuck.
					var cur = read();
					cur.bikes = cur.bikes.filter(function (x) {
						return x.slug !== start.active;
					});
					delete cur.profiles[start.active];
					if (cur.active === start.active) {
						cur.active = cur.bikes[0] ? cur.bikes[0].slug : '';
					}
					write(cur);
					return null;
				}
				return r.ok ? r.json() : null;
			})
			.then(function (p) {
				if (p && p.slug) {
					put(p, false);
				}
			})
			.catch(function () {});
	}
})();
