/**
 * Navigation search bar: do not submit an empty search.
 *
 * Without this, pressing Enter or the search button on an empty bar — or one
 * holding only spaces — opens the results page for nothing, titled with an
 * empty "()" and 0 results. Instead the bar stays put and keeps focus, so the
 * visitor can type.
 *
 * One delegated listener rather than one per form, so bars rendered more than
 * once (desktop and mobile headers) or added later are covered too.
 */
( function () {
	'use strict';

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;

		if ( ! form || ! form.classList || ! form.classList.contains( 'rwaq-searchbar' ) ) {
			return;
		}

		var input = form.querySelector( '.rwaq-searchbar__input' );

		if ( ! input ) {
			return;
		}

		var term = input.value.replace( /^\s+|\s+$/g, '' );

		if ( '' === term ) {
			event.preventDefault();
			input.value = '';
			input.focus();
			return;
		}

		// Sent trimmed, so the results page shows the term without stray spaces.
		input.value = term;
	} );
}() );
