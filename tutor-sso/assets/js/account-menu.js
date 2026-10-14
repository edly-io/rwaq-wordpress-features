/**
 * Logged-in account dropdown.
 *
 * One delegated listener on the document rather than a listener per menu, so
 * a menu rendered later (a mobile header revealed on toggle, a block editor
 * preview) works without re-initialising anything.
 */
( function () {
	'use strict';

	var SELECTOR = '[data-tutor-sso-account]';

	/**
	 * Open/close one menu.
	 *
	 * @param {Element} root Menu root.
	 * @param {boolean} open Target state.
	 */
	function setOpen( root, open ) {
		var trigger = root.querySelector( '.tutor-sso-account__trigger' );
		var panel = root.querySelector( '.tutor-sso-account__panel' );

		if ( ! trigger || ! panel ) {
			return;
		}

		trigger.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		panel.hidden = ! open;
	}

	/**
	 * Close every open menu, optionally sparing one.
	 *
	 * @param {Element} [except] Menu to leave alone.
	 */
	function closeAll( except ) {
		var menus = document.querySelectorAll( SELECTOR );

		for ( var i = 0; i < menus.length; i++ ) {
			if ( menus[ i ] !== except ) {
				setOpen( menus[ i ], false );
			}
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var trigger = event.target.closest
			? event.target.closest( '.tutor-sso-account__trigger' )
			: null;

		if ( trigger ) {
			var root = trigger.closest( SELECTOR );

			if ( root ) {
				event.preventDefault();

				var willOpen = 'true' !== trigger.getAttribute( 'aria-expanded' );

				closeAll( root );
				setOpen( root, willOpen );

				return;
			}
		}

		// A click anywhere outside an open menu closes it — but a click on a
		// menu item is a real navigation, so the panel is left alone and the
		// link follows as normal.
		if ( ! event.target.closest || ! event.target.closest( SELECTOR ) ) {
			closeAll();
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' !== event.key && 'Esc' !== event.key ) {
			return;
		}

		var open = document.querySelector(
			'.tutor-sso-account__trigger[aria-expanded="true"]'
		);

		if ( ! open ) {
			return;
		}

		closeAll();
		open.focus();
	} );
}() );
