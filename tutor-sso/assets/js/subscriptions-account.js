/**
 * "الاشتراك" — opens and closes the cancel dialog.
 *
 * A native <dialog> does the heavy lifting: showModal() puts it in the top
 * layer with a backdrop, keeps focus inside it, and closes it on Escape. This
 * adds the two things it does not: closing on a click outside the box, and
 * sending focus back to the button that opened it.
 */
( function () {
	'use strict';

	var opener = null;

	document.addEventListener( 'click', function ( event ) {
		var open = event.target.closest( '[data-rwaq-sub-open]' );

		if ( open ) {
			var dialog = document.getElementById( open.getAttribute( 'data-rwaq-sub-open' ) );

			if ( dialog && typeof dialog.showModal === 'function' ) {
				opener = open;
				dialog.showModal();
			}

			return;
		}

		var close = event.target.closest( '[data-rwaq-sub-close]' );

		if ( close ) {
			var parent = close.closest( 'dialog' );

			if ( parent ) {
				parent.close();
			}

			return;
		}

		// A click on the backdrop lands on the <dialog> element itself, outside
		// its content box.
		if ( event.target.matches( 'dialog.rwaq-sub-dialog[open]' ) ) {
			var box = event.target.getBoundingClientRect();
			var inside = event.clientX >= box.left && event.clientX <= box.right
				&& event.clientY >= box.top && event.clientY <= box.bottom;

			if ( ! inside ) {
				event.target.close();
			}
		}
	} );

	document.addEventListener( 'close', function ( event ) {
		if ( event.target.matches && event.target.matches( 'dialog.rwaq-sub-dialog' ) && opener ) {
			opener.focus();
			opener = null;
		}
	}, true );

	// Submitting is final; stop a double click sending it twice.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;

		if ( ! form.closest || ! form.closest( '.rwaq-sub' ) ) {
			return;
		}

		var button = form.querySelector( 'button[type="submit"]' );

		if ( button ) {
			if ( button.getAttribute( 'aria-disabled' ) === 'true' ) {
				event.preventDefault();
				return;
			}

			button.setAttribute( 'aria-disabled', 'true' );
		}
	} );
}() );
