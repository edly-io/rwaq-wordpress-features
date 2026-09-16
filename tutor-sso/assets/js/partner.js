/**
 * Partner detail behaviour.
 *
 * The section "load more" buttons. Every card is already in the HTML — the
 * organization detail endpoint returns all courses, programs and instructors in
 * one response — so this is a pure reveal, with no request.
 *
 * Vanilla JS, no dependencies. Class names match
 * includes/partners/partner-detail.php and assets/css/partner-detail.css.
 */
(function () {
	'use strict';

	/**
	 * Wire one section's "load more" button.
	 *
	 * @param {Element} section The .rwaq-pt__section wrapper.
	 */
	function initSection(section) {
		var button = section.querySelector('[data-more]');
		var cells = Array.prototype.slice.call(section.querySelectorAll('.rwaq-pt__cell'));

		if (!button || !cells.length) {
			return;
		}

		var step = parseInt(section.getAttribute('data-step'), 10) || 4;

		button.addEventListener('click', function () {
			var hidden = cells.filter(function (cell) {
				return cell.classList.contains('is-hidden');
			});

			hidden.slice(0, step).forEach(function (cell) {
				cell.classList.remove('is-hidden');
			});

			var left = hidden.length - step;

			if (left <= 0) {
				// Nothing further to reveal — the button has done its job.
				button.hidden = true;
				return;
			}

			// The label counts the *next* click's reveal, so it has to be redrawn.
			var next = Math.min(step, left);
			button.textContent = button.textContent.replace(/\d[\d,٠-٩]*/, String(next));

			// Move focus to the first newly revealed card, so a keyboard user is
			// not left at a button that may have just vanished.
			var revealed = hidden[0];
			var target = revealed && revealed.querySelector('a, [tabindex]');
			if (target) {
				target.focus({ preventScroll: true });
			}
		});
	}

	function init() {
		Array.prototype.forEach.call(document.querySelectorAll('.rwaq-pt'), function (root) {
			Array.prototype.forEach.call(root.querySelectorAll('.rwaq-pt__section'), initSection);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
