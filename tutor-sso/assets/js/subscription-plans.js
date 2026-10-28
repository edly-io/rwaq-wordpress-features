/**
 * Subscription plans — the monthly / yearly toggle.
 *
 * A tab list: clicking a period shows its plans and hides the other's. The
 * arrow keys move between periods, as for any tab list. The first period shown
 * is decided server-side (the `hidden` attribute), so the page reads correctly
 * before this runs.
 */
( function () {
	'use strict';

	function select( block, tab ) {
		var period = tab.getAttribute( 'data-rwaq-period' );

		block.querySelectorAll( '.rwaq-plans__tab' ).forEach( function ( t ) {
			var on = t === tab;
			t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			t.setAttribute( 'tabindex', on ? '0' : '-1' );
		} );

		block.querySelectorAll( '.rwaq-plans__group' ).forEach( function ( group ) {
			group.hidden = group.getAttribute( 'data-rwaq-period' ) !== period;
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var tab = event.target.closest( '.rwaq-plans__tab' );
		var block = tab && tab.closest( '[data-rwaq-plans]' );

		if ( block ) {
			select( block, tab );
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		var tab = event.target.closest && event.target.closest( '.rwaq-plans__tab' );
		var block = tab && tab.closest( '[data-rwaq-plans]' );

		if ( ! block || ( 'ArrowLeft' !== event.key && 'ArrowRight' !== event.key ) ) {
			return;
		}

		var tabs = Array.prototype.slice.call( block.querySelectorAll( '.rwaq-plans__tab' ) );
		var index = tabs.indexOf( tab );
		// Right-to-left: the next tab sits to the left.
		var rtl = 'rtl' === getComputedStyle( block ).direction;
		var step = ( 'ArrowLeft' === event.key ) === rtl ? 1 : -1;
		var next = tabs[ ( index + step + tabs.length ) % tabs.length ];

		event.preventDefault();
		select( block, next );
		next.focus();
	} );
}() );
