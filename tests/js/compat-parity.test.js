/**
 * PHP/JS parity: runs ev() from assets/js/compat-garage.js on tests/fixtures/compat-cases.json and compares with the
 * expected results that MatchEngineParityTest (PHPUnit) asserts for MatchEngine::evaluate_product().
 *
 * Usage: node tests/js/compat-parity.test.js   (no dependencies; exit code 1 on mismatch)
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');

const root = path.join(__dirname, '..', '..');
const fixture = JSON.parse(fs.readFileSync(path.join(root, 'tests/fixtures/compat-cases.json'), 'utf8'));
const source = fs.readFileSync(path.join(root, 'assets/js/compat-garage.js'), 'utf8');

const cfg = { strict: false, i18n: {} };
const noop = () => {};
const documentStub = { cookie: '', addEventListener: noop, dispatchEvent: noop, querySelectorAll: () => [] };
const windowStub = { motoredCompatCfg: cfg, addEventListener: noop };
const context = { window: windowStub, document: documentStub, Event: function () {}, CustomEvent: function () {}, Date, JSON, Math, parseInt, URL };
vm.createContext(context);
vm.runInContext(source, context);
const ev = windowStub.motoredGarageApi.ev;

/** Same mapping as RestController::client_profile() / Storefront::fit_marker_html() (also asserted in PHPUnit). */
function clientAxis(axis) {
	const out = {};
	for (const size of Object.keys(axis || {})) {
		const e = axis[size];
		out[size] = {
			t: String(e.type),
			f: String(e.from || ''),
			l: e.min_load == null ? null : Number(e.min_load),
			s: e.min_speed == null ? null : Number(e.min_speed),
			c: e.req_const ? String(e.req_const) : null,
		};
	}
	return out;
}

function marker(fit) {
	const m = { p: fit.position, f: fit.front_canonical, r: fit.rear_canonical, fl: fit.front_load, rl: fit.rear_load, fs: fit.front_speed_rank, rs: fit.rear_speed_rank, c: fit.construction };
	// Same as Storefront::fit_marker_html(): fc/rc only when the kit sheet sets them.
	if (fit.front_construction) {
		m.fc = fit.front_construction;
	}
	if (fit.rear_construction) {
		m.rc = fit.rear_construction;
	}
	return m;
}

let failures = 0;
for (const tc of fixture.cases) {
	cfg.strict = !!tc.strict;
	const r = ev(marker(tc.fit), { front: clientAxis(tc.profile.front), rear: clientAxis(tc.profile.rear) });
	const got = {
		ok: !!r.ok,
		positions: r.ps[0] === 'kit' ? ['front', 'rear'] : r.ps.slice(),
		match: r.m || '',
		caution: !!r.w,
		from: r.fr || '',
	};
	try {
		// JSON round-trip: arrays created inside the vm realm have a different prototype.
		assert.deepStrictEqual(JSON.parse(JSON.stringify(got)), tc.expect);
		console.log('ok   ' + tc.name);
	} catch (e) {
		failures++;
		console.log('FAIL ' + tc.name + '\n     expected ' + JSON.stringify(tc.expect) + '\n     got      ' + JSON.stringify(got));
	}
}
// fmt(): translated strings use %s (labels) and %d (counts); neither may leak to the storefront.
const fmt = windowStub.motoredGarageApi.fmt;
for (const [tpl, val, want] of [
	['Ver todas las cubiertas para tu moto (%d)', 2, 'Ver todas las cubiertas para tu moto (2)'],
	['Completá el juego: delanteras (%d)', 0, 'Completá el juego: delanteras (0)'],
	['Esta cubierta sirve para tu %s', 'Benelli TRK 502 2017+', 'Esta cubierta sirve para tu Benelli TRK 502 2017+'],
	['Completá el juego: traseras', 'x', 'Completá el juego: traseras'],
]) {
	try {
		assert.strictEqual(fmt(tpl, val), want);
		console.log('ok   fmt ' + JSON.stringify(tpl));
	} catch (e) {
		failures++;
		console.log('FAIL fmt ' + JSON.stringify(tpl) + ' -> ' + JSON.stringify(fmt(tpl, val)));
	}
}

console.log(failures ? failures + ' failure(s)' : 'all ' + fixture.cases.length + ' parity cases + fmt cases pass');
process.exit(failures ? 1 : 0);
