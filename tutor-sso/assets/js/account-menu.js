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
	var FLIP = 'tutor-sso-account__panel--flip';

	// Gap kept between the panel and the edge of the window.
	var EDGE = 8;

	/**
	 * How far a box runs off the window, left and right together.
	 *
	 * @param {DOMRect} rect Box.
	 * @return {number} Pixels outside, 0 when it fits.
	 */
	function overflow( rect ) {
		var width = document.documentElement.clientWidth;

		return Math.max( 0, EDGE - rect.left ) + Math.max( 0, rect.right - ( width - EDGE ) );
	}

	/**
	 * Open the panel towards whichever side it fits.
	 *
	 * It normally extends away from the trigger's start edge; when that runs
	 * off the window it is anchored to the other edge instead. If neither side
	 * fits — a centred trigger on a very narrow phone — the side that hides
	 * less is kept and the panel is slid back onto the screen.
	 *
	 * @param {Element} panel Open panel.
	 */
	function place( panel ) {
		panel.classList.remove( FLIP );
		panel.style.transform = '';

		var normal = overflow( panel.getBoundingClientRect() );

		if ( 0 === normal ) {
			return;
		}

		panel.classList.add( FLIP );

		if ( 0 === overflow( panel.getBoundingClientRect() ) ) {
			return;
		}

		if ( overflow( panel.getBoundingClientRect() ) >= normal ) {
			panel.classList.remove( FLIP );
		}

		var rect = panel.getBoundingClientRect();
		var width = document.documentElement.clientWidth;
		var shift = 0;

		if ( rect.left < EDGE ) {
			shift = EDGE - rect.left;
		} else if ( rect.right > width - EDGE ) {
			shift = ( width - EDGE ) - rect.right;
		}

		if ( 0 !== shift ) {
			panel.style.transform = 'translateX(' + Math.round( shift ) + 'px)';
		}
	}

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

		// Measured after un-hiding: a hidden panel has no box to measure.
		if ( open ) {
			place( panel );
		}
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

	// The room on each side changes with the window, so an open panel is
	// placed again.
	window.addEventListener( 'resize', function () {
		var open = document.querySelectorAll(
			SELECTOR + ' .tutor-sso-account__trigger[aria-expanded="true"]'
		);

		for ( var i = 0; i < open.length; i++ ) {
			var panel = open[ i ].closest( SELECTOR ).querySelector( '.tutor-sso-account__panel' );

			if ( panel && ! panel.hidden ) {
				place( panel );
			}
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
