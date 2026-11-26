/**
 * Admin: live Google SERP snippet preview & character counters for the Motored SEO meta box.
 * Loaded only on product/page/post/bike edit screens; zero dependencies.
 */
(function () {
	'use strict';

	function init() {
		var box = document.getElementById('motored-seo-box');
		if (!box) {
			return;
		}
		var titleInput = document.getElementById('motored-seo-title');
		var descInput  = document.getElementById('motored-seo-desc');
		var serpTitle  = box.querySelector('[data-serp-title]');
		var serpDesc   = box.querySelector('[data-serp-desc]');
		var fbTitle    = box.dataset.fallbackTitle || '';
		var fbDesc     = box.dataset.fallbackDesc || '';

		function updateCounter(input) {
			if (!input) {
				return;
			}
			var el = box.querySelector('[data-counter-for="' + input.id + '"]');
			if (!el) {
				return;
			}
			var max = parseInt(el.dataset.max || '60', 10);
			var len = input.value.trim().length;
			el.textContent = '(' + len + ' / ' + max + ')';
			el.classList.toggle('motored-box__counter--over', len > max);
		}

		function refresh() {
			if (titleInput && serpTitle) {
				var t = titleInput.value.trim();
				serpTitle.textContent = t || fbTitle;
			}
			if (descInput && serpDesc) {
				var d = descInput.value.trim();
				serpDesc.textContent = d || fbDesc;
			}
			updateCounter(titleInput);
			updateCounter(descInput);
		}

		if (titleInput) {
			titleInput.addEventListener('input', refresh);
		}
		if (descInput) {
			descInput.addEventListener('input', refresh);
		}
		refresh();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
