/**
 * Instructor detail behaviour.
 *
 * Two small, independent pieces:
 *
 *   1. The courses / programs tab strip — click and arrow-key selection,
 *      following the WAI-ARIA tabs pattern the markup already declares.
 *   2. The hero biography, whose two-line clamp is paired with a "read more"
 *      button that opens the full text in a modal. The button starts hidden and
 *      is revealed only when the clamp is actually hiding something, so a short
 *      biography never offers a dialog that shows nothing new.
 *
 * Vanilla JS, no dependencies. Class names and ids match
 * includes/instructors/instructor-detail.php and assets/css/instructor.css.
 */
(function () {
	'use strict';

	/** Elements that can hold keyboard focus inside the dialog. */
	var FOCUSABLE = 'a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])';

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

	/**
	 * Wire the biography "read more" button and its modal.
	 *
	 * @param {Element} root The .rwaq-ins wrapper.
	 */
	function initBio(root) {
		var text = root.querySelector('.rwaq-ins__bio-text');
		var toggle = root.querySelector('.rwaq-ins__bio-toggle');
		var modal = root.querySelector('.rwaq-ins__modal');

		if (!text || !toggle || !modal) {
			return;
		}

		var panel = modal.querySelector('.rwaq-ins__modal-panel');
		var body = modal.querySelector('.rwaq-ins__modal-body');
		var lastFocused = null;

		/**
		 * Whether the clamp is hiding any of the biography.
		 *
		 * Compared with a 1px tolerance: sub-pixel line heights make an
		 * unclamped element report a scrollHeight a fraction above its
		 * clientHeight, which would otherwise read as overflow.
		 */
		function isClamped() {
			return text.scrollHeight > text.clientHeight + 1;
		}

		function sync() {
			toggle.hidden = !isClamped();
		}

		function open() {
			lastFocused = document.activeElement;
			modal.hidden = false;
			document.documentElement.classList.add('rwaq-ins-modal-open');

			// The body is the scrollable region, so it takes focus — that way
			// the keyboard can scroll a long biography immediately.
			(body || panel).focus();
		}

		function close() {
			modal.hidden = true;
			document.documentElement.classList.remove('rwaq-ins-modal-open');

			// Return focus where it came from. <body> is not a real origin — it
			// is what activeElement reports when the dialog was opened by
			// something other than a click — so fall back to the button, which
			// is where the reader was.
			var restore = (lastFocused && lastFocused !== document.body) ? lastFocused : toggle;
			if (restore && typeof restore.focus === 'function') {
				restore.focus();
			}
			lastFocused = null;
		}

		toggle.addEventListener('click', open);

		// The overlay and the × both carry data-rwaq-ins-close.
		modal.querySelectorAll('[data-rwaq-ins-close]').forEach(function (el) {
			el.addEventListener('click', close);
		});

		modal.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				event.preventDefault();
				close();
				return;
			}

			if (event.key !== 'Tab') {
				return;
			}

			// Keep Tab inside the dialog while it is open.
			var items = Array.prototype.slice.call(panel.querySelectorAll(FOCUSABLE))
				.filter(function (el) { return el.offsetParent !== null; });

			if (!items.length) {
				return;
			}

			var first = items[0];
			var last = items[items.length - 1];

			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		});

		sync();

		// A resize re-wraps the biography, so whether it overflows can change.
		var resizeTimer = null;
		window.addEventListener('resize', function () {
			clearTimeout(resizeTimer);
			resizeTimer = setTimeout(sync, 150);
		});

		// The webfont usually lands after first paint and changes the metrics.
		if (document.fonts && document.fonts.ready) {
			document.fonts.ready.then(sync).catch(function () {});
		}
	}

	function init() {
		Array.prototype.forEach.call(document.querySelectorAll('.rwaq-ins'), function (root) {
			initTabs(root);
			initBio(root);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
