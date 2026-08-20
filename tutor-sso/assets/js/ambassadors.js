/**
 * "Rwaq Ambassadors" page behaviour.
 *
 * The designed CV drop zone hides the native file input (see .rwaq-amb-upload in
 * assets/css/ambassadors.css), so the browser's own "no file chosen" text is not
 * visible. This reports the chosen file name back into the box instead, and
 * restores the prompt when Contact Form 7 resets the form after a send.
 */
( function () {
	'use strict';

	var BOX   = '.rwaq-amb-upload';
	var TITLE = '.rwaq-amb-upload__title';

	/**
	 * Put the prompt text back, remembering it on first use.
	 *
	 * @param {Element} box Drop-zone element.
	 */
	function reset( box ) {
		var title = box.querySelector( TITLE );

		if ( title && title.getAttribute( 'data-prompt' ) ) {
			title.textContent = title.getAttribute( 'data-prompt' );
		}

		box.classList.remove( 'is-filled' );
	}

	// Delegated so it survives CF7 replacing the form markup.
	document.addEventListener( 'change', function ( event ) {
		var input = event.target;

		if ( ! input || 'file' !== input.type || ! input.closest ) {
			return;
		}

		var box = input.closest( BOX );

		if ( ! box ) {
			return;
		}

		var title = box.querySelector( TITLE );

		if ( ! title ) {
			return;
		}

		if ( ! title.getAttribute( 'data-prompt' ) ) {
			title.setAttribute( 'data-prompt', title.textContent );
		}

		if ( input.files && input.files.length ) {
			title.textContent = input.files[ 0 ].name;
			box.classList.add( 'is-filled' );
		} else {
			reset( box );
		}
	} );

	// CF7 clears field values on a successful send; mirror that in the box.
	document.addEventListener( 'wpcf7mailsent', function ( event ) {
		var boxes = ( event.target || document ).querySelectorAll( BOX );

		Array.prototype.forEach.call( boxes, reset );
	} );
}() );
