/**
 * Partner detail behaviour.
 *
 * Two independent pieces:
 *
 *   1. The section "load more" buttons. Every card is already in the HTML — the
 *      organization detail endpoint returns all courses, programs and
 *      instructors in one response — so this is a pure reveal, with no request
 *      and nothing that exists only for visitors with JS.
 *   2. The description modal, opened by the hero's "read more". Same behaviour as
 *      the instructor biography modal: the button ships hidden and is shown only
 *      when the two-line clamp is genuinely hiding text.
 *
 * Vanilla JS, no dependencies. Class names match
 * includes/partners/partner-detail.php and assets/css/partner-detail.css.
 */
(function () {
	'use strict';

	/** Elements that can hold keyboard focus inside the dialog. */
	var FOCUSABLE = 'a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])';

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

	/**
	 * Wire the description "read more" button and its modal.
	 *
	 * @param {Element} root The .rwaq-pt wrapper.
	 */
	function initBio(root) {
		var text = root.querySelector('.rwaq-pt__bio-text');
		var toggle = root.querySelector('.rwaq-pt__bio-toggle');
		var modal = root.querySelector('.rwaq-pt__modal');

		if (!text || !toggle || !modal) {
			return;
		}

		var panel = modal.querySelector('.rwaq-pt__modal-panel');
		var body = modal.querySelector('.rwaq-pt__modal-body');
		var lastFocused = null;

		/**
		 * Whether the clamp is hiding any of the description. The 1px tolerance
		 * covers sub-pixel line heights, which make an unclamped element report a
		 * scrollHeight a fraction above its clientHeight.
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
			document.documentElement.classList.add('rwaq-pt-modal-open');
			(body || panel).focus();
		}

		function close() {
			modal.hidden = true;
			document.documentElement.classList.remove('rwaq-pt-modal-open');

			// <body> is not a real origin — it is what activeElement reports when
			// the dialog was opened by something other than a click.
			var restore = (lastFocused && lastFocused !== document.body) ? lastFocused : toggle;
			if (restore && typeof restore.focus === 'function') {
				restore.focus();
			}
			lastFocused = null;
		}

		toggle.addEventListener('click', open);

		modal.querySelectorAll('[data-rwaq-pt-close]').forEach(function (el) {
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

		// A resize re-wraps the description, so whether it overflows can change.
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
		Array.prototype.forEach.call(document.querySelectorAll('.rwaq-pt'), function (root) {
			Array.prototype.forEach.call(root.querySelectorAll('.rwaq-pt__section'), initSection);
			initBio(root);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
