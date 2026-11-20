/**
 * Mi Garage client store + cache-safe compatibility hydration (badges, product banner, shop CTA, bar switch).
 *
 * Storage: localStorage `motored_garage_v1` = { active, bikes:[{slug,label}], profiles:{slug: profile} }.
 * Logged-in shoppers are synced with REST /garage (user meta is the source of truth; local is a cache).
 * `ev()` MUST mirror MatchEngine::evaluate_product(); tests/js/compat-parity.test.js checks both on the same fixture.
 */
(function () {
	'use strict';

	var KEY = 'motored_garage_v1';
	var EVENT = 'motored:garage';
	var PRIORITY = { oem: 3, alt: 2, equiv: 1 };
	var BADGE = 'motored-compat-badge';
	var PROFILE_TTL = 432e5; // 12 h

	var c = window.motoredCompatCfg || {};
	var t = c.i18n || {};
	var base = (c.restBase || '').replace(/\/$/, '');
	var D = document;
	var memory = {};

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

	function read() {
		try {
			return normalize(JSON.parse(getItem(0, KEY)));
		} catch (e) {
			return normalize(null);
		}
	}

	function write(state) {
		setItem(0, KEY, JSON.stringify(normalize(state)));
		D.dispatchEvent(new Event(EVENT));
	}

	function activeProfile() {
		var s = read();
		return s.profiles[s.active] || null;
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
	 * @param {Object} fit     Marker payload {p,f,r,fl,rl,fs,rs,c}.
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
			var res = checkConstraints(req, fit[ax + 'l'], fit[ax + 's'], fit.c);
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
		return fetch(base + path, opts)
			.then(function (r) {
				if (r.status === 401 || r.status === 403) {
					D.dispatchEvent(new CustomEvent('motored:garage-error', { detail: 'session' }));
				}
				if (!r.ok) {
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
				}
				return d;
			});
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

	function fmt(s, v) {
		return String(s || '').replace('%s', v);
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

	function detailText(r) {
		return (
			positionText(r) +
			' · ' +
			(t[r.m] || r.m) +
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
			parts.push(t.front + ': ' + pf.oem.f);
		}
		if (pf.oem.r) {
			parts.push(t.rear + ': ' + pf.oem.r);
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
			badge = el('p', BADGE + ' ' + BADGE + '--ok ' + BADGE + '--loop', '✓ ' + fmt(t.compatWith, pf.label) + ' · ' + positionText(r));
			badge.title = detailText(r);
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
				ask.appendChild(pickerButton(picker, t.pickBike || '', 'button ' + BADGE + '__btn'));
			}
			status.appendChild(ask);
			return;
		}
		var r = ev(fit, pf);
		var actions = el('div', BADGE + '__actions');
		var box;
		if (r.ok) {
			box = el('div', BADGE + ' ' + BADGE + '--ok');
			box.appendChild(el('p', BADGE + '__title', '✓ ' + fmt(t.compatWith, pf.label)));
			box.appendChild(el('p', BADGE + '__meta', detailText(r)));
			var all = el('a', BADGE + '__link', t.seeCompat || '');
			all.href = urlWithMoto(pf.slug, '', shopUrl());
			actions.appendChild(all);
			// Pair suggestion: a front tire fits -> offer the rear ones (and vice versa).
			if (fit.p === 'front' || fit.p === 'rear') {
				var other = fit.p === 'front' ? 'rear' : 'front';
				var pair = el('a', BADGE + '__link', fmt(other === 'rear' ? t.pairRear : t.pairFront, pf.label));
				pair.href = urlWithMoto(pf.slug, other, shopUrl());
				actions.appendChild(pair);
			}
		} else {
			// With an active bike the product page always tells the truth (no "select your bike" here).
			box = el('div', BADGE + ' ' + BADGE + '--no');
			box.appendChild(el('p', BADGE + '__title', fmt(t.incompatWith, pf.label)));
			var oem = oemText(pf);
			if (oem) {
				box.appendChild(el('p', BADGE + '__meta', (t.yourBikeUses ? t.yourBikeUses + ' ' : '') + oem));
			}
			var see = el('a', 'button alt ' + BADGE + '__btn', t.seeCompat || '');
			see.href = urlWithMoto(pf.slug, '', shopUrl());
			actions.appendChild(see);
		}
		if (picker) {
			actions.appendChild(pickerButton(picker, t.changeBike || '', BADGE + '__link'));
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

	function draw() {
		var s = read();
		var pf = s.profiles[s.active] || null;
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
	}

	/* ---------- public API ---------- */

	window.motoredGarageActive = activeProfile;
	window.motoredGarageApi = { rd: read, wr: write, api: api, ev: ev, normalize: normalize };
	window.motoredGarageSetActive = setActive;
	window.motoredGarageSave = function (profile) {
		var dropped = put(profile, true);
		return api('POST', '/garage', { action: 'add', slug: profile.slug })
			.catch(function () {})
			.then(function () {
				return { dropped: dropped };
			});
	};

	/* ---------- boot ---------- */

	// Logout on a shared computer: forget the local copy of the account garage.
	if (/(?:^|;\s*)motored_garage_logout=1/.test(D.cookie || '')) {
		removeItem(0, KEY);
		removeItem(1, 'mg_s');
		D.cookie = 'motored_garage_logout=;Max-Age=0;path=/';
	}

	D.addEventListener(EVENT, draw);
	// Another tab changed the garage: re-render here too.
	window.addEventListener('storage', function (e) {
		if (e.key === KEY) {
			draw();
		}
	});
	draw();

	var start = read();
	var act = start.profiles[start.active];
	if (base && c.loggedIn && !getItem(1, 'mg_s')) {
		api('POST', '/garage', { action: 'merge', bikes: start.bikes, active: start.active })
			.then(function () {
				setItem(1, 'mg_s', '1');
			})
			.catch(function () {});
	} else if (base && start.active && (!act || !act.ts || Date.now() - act.ts > PROFILE_TTL || !act.oem)) {
		fetch(base + '/compat/bike/' + encodeURIComponent(start.active))
			.then(function (r) {
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
