/**
 * Product edit screen: the "Open edX Program" checkbox.
 *
 * Two jobs — reveal the Program tab only when the box is ticked, and keep the
 * program and course checkboxes mutually exclusive, since a product can only be
 * one or the other.
 */
(function ($) {
	'use strict';

	$(function () {
		var $program = $('#_tutor_sso_is_program');
		var $course = $('#is_openedx_course');
		var $tab = $('li.tutor_sso_program_options');

		if (!$program.length) {
			return;
		}

		// Set while we untick the other box, so its change handler cannot tick
		// this one back and bounce the two forever.
		var syncing = false;

		function showTab() {
			var on = $program.is(':checked');

			$tab.toggle(on);

			// Leaving the panel open after unticking would show a field that no
			// longer applies.
			if (!on && $('#tutor_sso_program_product_data').is(':visible')) {
				$('.product_data_tabs li').not($tab).first().find('a').trigger('click');
			}
		}

		$program.on('change', function () {
			if (!syncing && this.checked && $course.length && $course.is(':checked')) {
				syncing = true;
				$course.prop('checked', false).trigger('change');
				syncing = false;
			}

			showTab();
		});

		$course.on('change', function () {
			if (!syncing && this.checked && $program.is(':checked')) {
				syncing = true;
				$program.prop('checked', false).trigger('change');
				syncing = false;
			}
		});

		showTab();
	});
})(jQuery);
