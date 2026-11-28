/**
 * Product banner image picker (motored-core ProductBanner): native media modal, vanilla JS.
 * Markup: [data-motored-banner-picker] > input[type=hidden] + [data-preview] + [data-choose] + [data-remove].
 */
(function () {
	'use strict';
	if (!window.wp || !window.wp.media) {
		return;
	}
	document.querySelectorAll('[data-motored-banner-picker]').forEach(function (box) {
		var input = box.querySelector('input[type="hidden"]');
		var preview = box.querySelector('[data-preview]');
		var choose = box.querySelector('[data-choose]');
		var remove = box.querySelector('[data-remove]');
		var frame;
		if (!input || !preview || !choose || !remove) {
			return;
		}
		choose.addEventListener('click', function () {
			if (!frame) {
				frame = window.wp.media({
					title: box.getAttribute('data-title') || '',
					button: { text: box.getAttribute('data-button') || '' },
					library: { type: 'image' },
					multiple: false
				});
				frame.on('select', function () {
					var item = frame.state().get('selection').first().toJSON();
					var size = item.sizes && (item.sizes.medium || item.sizes.full);
					var img = document.createElement('img');
					img.className = 'motored-box__preview';
					img.src = size ? size.url : item.url;
					img.alt = '';
					input.value = String(item.id);
					preview.textContent = '';
					preview.appendChild(img);
					remove.hidden = false;
				});
			}
			frame.open();
		});
		remove.addEventListener('click', function () {
			input.value = '';
			preview.textContent = '';
			remove.hidden = true;
		});
	});
})();
