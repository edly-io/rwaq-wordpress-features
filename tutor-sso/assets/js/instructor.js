/**
 * Instructor detail behaviour.
 *
 * The courses / programs tab strip — click and arrow-key selection, following
 * the WAI-ARIA tabs pattern the markup already declares.
 *
 * Vanilla JS, no dependencies. Class names and ids match
 * includes/instructors/instructor-detail.php and assets/css/instructor.css.
 */
(function () {
	'use strict';

	/**
	 * Wire one tab strip and its panels.
	 *
	 * @param {Element} root The .rwaq-ins wrapper.
	 */
	function initTabs(root) {
		var tabs = Array.prototype.slice.call(root.querySelectorAll('.rwaq-ins__tab'));

		if (tabs.length < 2) {
			return;
		}

		function select(tab) {
			tabs.forEach(function (candidate) {
				var isActive = candidate === tab;
				var panel = document.getElementById(candidate.getAttribute('aria-controls'));

				candidate.classList.toggle('is-active', isActive);
				candidate.setAttribute('aria-selected', isActive ? 'true' : 'false');
				candidate.setAttribute('tabindex', isActive ? '0' : '-1');

				if (panel) {
					panel.hidden = !isActive;
				}
			});
		}

		tabs.forEach(function (tab, index) {
			tab.addEventListener('click', function () {
				select(tab);
			});

			tab.addEventListener('keydown', function (event) {
				var step = 0;

				// The strip is laid out RTL, so ArrowLeft advances and
				// ArrowRight goes back — mirrored when rendered LTR.
				var isRtl = getComputedStyle(root).direction === 'rtl';

				if (event.key === 'ArrowLeft') {
					step = isRtl ? 1 : -1;
				} else if (event.key === 'ArrowRight') {
					step = isRtl ? -1 : 1;
				} else if (event.key === 'Home') {
					step = -index;
				} else if (event.key === 'End') {
					step = tabs.length - 1 - index;
				} else {
					return;
				}

				event.preventDefault();

				var next = tabs[(index + step + tabs.length) % tabs.length];
				select(next);
				next.focus();
			});
		});
	}

	function init() {
		Array.prototype.forEach.call(document.querySelectorAll('.rwaq-ins'), function (root) {
			initTabs(root);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
