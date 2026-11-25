/**
 * [motored_compat_selector] and the product-page "check" picker: cascade -> save to Mi Garage -> redirect or stay.
 * Uses motored-ui.js for the cascade and optional Mi Garage integration for saving.
 */
(function () {
	'use strict';
	var c = window.motoredCompatSelectorCfg || window.motoredCompatCfg || {};
	var t = c.i18n || {};
	var garageText = (window.motoredCompatCfg || {}).i18n || {};
	var base = (c.restBase || '').replace(/\/$/, '');
	var ui = window.motoredUi;
	if (!base || !ui) {
		return;
	}

	function withMoto(url, slug) {
		return url + (url.indexOf('?') > -1 ? '&' : '?') + 'moto=' + encodeURIComponent(slug);
	}

	document.querySelectorAll('.motored-compat-selector').forEach(function (form) {
		var q = function (role) {
			return form.querySelector('[data-role="' + role + '"]');
		};
		var submit = q('submit');
		var cancel = q('cancel');
		var msgEl = q('msg');
		if (!q('brand') || !q('model') || !q('year') || !submit) {
			return;
		}

		function message(text, isError) {
			if (!msgEl) {
				return;
			}
			msgEl.hidden = !text;
			msgEl.textContent = text || '';
			msgEl.className = 'motored-compat-selector__msg' + (text ? (isError ? ' is-error' : ' is-ok') : '');
		}

		var cc = ui.cascade({
			brand: q('brand'),
			model: q('model'),
			year: q('year'),
			variant: q('variant'),
			variantWrap: q('variant-wrap'),
			submit: submit,
			t: t,
			base: base,
			lazy: form.dataset.lazy === '1',
			message: message,
		});

		form.addEventListener('motored:open-selector', function () {
			message('');
			cc.load();
			var b = q('brand');
			if (!b.disabled) {
				b.focus();
			}
		});

		if (cancel) {
			cancel.addEventListener('click', function () {
				var picker = form.closest('[data-role="picker"]');
				if (picker) {
					picker.hidden = true;
				}
			});
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var slug = cc.slug();
			if (!slug) {
				return;
			}
			var mode = form.dataset.mode;
			submit.disabled = true;
			message('');
			ui.getJson(ui.restUrl ? ui.restUrl(base, '/compat/bike/' + encodeURIComponent(slug)) : base + '/compat/bike/' + encodeURIComponent(slug))
				.then(function (profile) {
					var banner = form.closest('.motored-compat-banner');
					var picker = form.closest('[data-role="picker"]');
					if (mode === 'check' && banner) {
						banner.dataset.checked = '1';
					}
					ui.track('moto_select', { moto: profile.slug, source: mode || 'shop' });
					var save = window.motoredGarageSave ? window.motoredGarageSave(profile) : Promise.resolve({});
					return save.then(function (res) {
						var dropped = res && res.dropped;
						if (mode === 'garage' || mode === 'check') {
							cc.reset();
							if (mode === 'check' && picker) {
								picker.hidden = true;
							} else {
								message(dropped ? (garageText.garageFull || '').replace('%s', dropped) : garageText.savedOk || '', false);
							}
							return;
						}
						location.href = withMoto(form.dataset.shopUrl || c.shopUrl || '/', profile.slug);
					});
				})
				.catch(function () {
					submit.disabled = false;
					message(t.loadSelectErr || '', true);
				});
		});
	});
})();
