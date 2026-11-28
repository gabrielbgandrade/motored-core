/**
 * Product banner countdown (motored-core ProductBanner, contract 1.10): ticks every second and hides the banner
 * when the end date is reached. Markup: [data-motored-countdown="<ms>"] with [data-u="d|h|m|s"] numbers.
 */
(function () {
	'use strict';
	document.querySelectorAll('[data-motored-countdown]').forEach(function (box) {
		var end = parseInt(box.getAttribute('data-motored-countdown'), 10);
		var banner = box.closest('.motored-promo-banner');
		var nums = {};
		box.querySelectorAll('[data-u]').forEach(function (n) {
			nums[n.getAttribute('data-u')] = n;
		});
		if (!end || !banner) {
			return;
		}
		function pad(v) {
			return (v < 10 ? '0' : '') + v;
		}
		function tick() {
			var left = Math.floor((end - Date.now()) / 1000);
			if (left <= 0) {
				banner.hidden = true;
				return false;
			}
			var parts = { d: Math.floor(left / 86400), h: Math.floor((left % 86400) / 3600), m: Math.floor((left % 3600) / 60), s: left % 60 };
			Object.keys(parts).forEach(function (k) {
				if (nums[k]) {
					nums[k].textContent = pad(parts[k]);
				}
			});
			return true;
		}
		if (tick()) {
			var timer = setInterval(function () {
				if (!tick()) {
					clearInterval(timer);
				}
			}, 1000);
		}
	});
})();
