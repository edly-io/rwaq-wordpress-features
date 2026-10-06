/* global jQuery, tutorSsoCourseAdmin, acf */
/**
 * Course edit screen: lock the LMS-owned ACF fields, validate the prices.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.tutorSsoCourseAdmin || {};
	var i18n = cfg.i18n || {};
	var lockedFields = cfg.lockedFields || [];
	var priceFields = cfg.priceFields || {};

	// Accepts "", "499", "399.50", ".5" — a plain decimal number, nothing else.
	// Deliberately not a currency parser: the LMS is expected to send bare
	// numbers (see the price fields' sanitizing in the sync controller).
	var NUMBER_RE = /^\d*\.?\d+$/;

	$( function () {
		init();

		// ACF re-renders fields inside repeaters / flexible content and when a
		// field group loads late; re-apply on those hooks when ACF is present.
		if ( window.acf && typeof window.acf.addAction === 'function' ) {
			window.acf.addAction( 'ready', init );
			window.acf.addAction( 'append', init );
		}
	} );

	function init() {
		lockedFields.forEach( lockField );
		bindPriceValidation();
	}

	// ── Locking ──────────────────────────────────────────────────────────────
	function $fieldWrap( name ) {
		// ACF markup: .acf-field[data-name="…"]. The attribute selector is
		// escaped by jQuery, but field names are plain slugs anyway.
		return $( '.acf-field[data-name="' + name + '"]' );
	}

	function lockField( name ) {
		var $wrap = $fieldWrap( name );

		if ( ! $wrap.length || $wrap.hasClass( 'tutor-sso-locked' ) ) {
			return;
		}

		$wrap.addClass( 'tutor-sso-locked' );

		$wrap.find( 'input, textarea, select' ).each( function () {
			var $input = $( this );
			var type = ( $input.attr( 'type' ) || '' ).toLowerCase();

			if ( 'hidden' === type ) {
				return;
			}

			if ( 'checkbox' === type || 'radio' === type ) {
				// Remember the synced state and restore it after any attempt to
				// change it. `disabled` is not an option here — see the header.
				$input.data( 'tutorSsoChecked', $input.prop( 'checked' ) );
				$input.attr( 'aria-readonly', 'true' );
				return;
			}

			if ( $input.is( 'select' ) ) {
				$input.data( 'tutorSsoValue', $input.val() );
				$input.attr( 'aria-readonly', 'true' );
				return;
			}

			$input.prop( 'readonly', true ).attr( 'aria-readonly', 'true' );
		} );

		if ( i18n.lockedNote ) {
			$( '<p class="description tutor-sso-locked__note"></p>' )
				.text( i18n.lockedNote )
				.appendTo( $wrap.find( '.acf-input' ).first() );
		}
	}

	// Revert any interaction with a locked control. Delegated from the document
	// so late-rendered fields are covered without re-binding.
	$( document ).on( 'click keydown', '.tutor-sso-locked input[type="checkbox"], .tutor-sso-locked input[type="radio"]', function ( e ) {
		// Let tabbing and modifier keys through; block only what would toggle.
		if ( 'keydown' === e.type && e.key !== ' ' && e.key !== 'Enter' && e.which !== 32 && e.which !== 13 ) {
			return;
		}

		e.preventDefault();
		$( this ).prop( 'checked', !! $( this ).data( 'tutorSsoChecked' ) );
	} );

	$( document ).on( 'change', '.tutor-sso-locked input[type="checkbox"], .tutor-sso-locked input[type="radio"]', function () {
		$( this ).prop( 'checked', !! $( this ).data( 'tutorSsoChecked' ) );
	} );

	$( document ).on( 'change', '.tutor-sso-locked select', function () {
		var previous = $( this ).data( 'tutorSsoValue' );

		if ( typeof previous !== 'undefined' ) {
			$( this ).val( previous );
		}
	} );

	// ── Price validation ─────────────────────────────────────────────────────
	function $priceInput( name ) {
		return $fieldWrap( name ).find( 'input[type="text"], input[type="number"]' ).first();
	}

	function priceValue( name ) {
		var $input = $priceInput( name );

		return $input.length ? $.trim( $input.val() ) : '';
	}

	/**
	 * The problem with one price field, or '' when it is fine. An empty value is
	 * valid — that is how a course with no sale price is expressed.
	 *
	 * @param {string} value Raw field value.
	 * @return {string} Message or ''.
	 */
	function priceError( value ) {
		if ( '' === value ) {
			return '';
		}

		if ( 0 === value.indexOf( '-' ) ) {
			return i18n.negativePrice || 'A price cannot be negative.';
		}

		if ( ! NUMBER_RE.test( value ) ) {
			return i18n.invalidPrice || 'Enter a price as a number.';
		}

		return '';
	}

	/**
	 * Validate both price fields, render the messages, and report whether the
	 * form may be submitted.
	 *
	 * @return {boolean} True when every price is acceptable.
	 */
	function validatePrices() {
		var errors = {};
		var name;

		for ( var role in priceFields ) {
			if ( Object.prototype.hasOwnProperty.call( priceFields, role ) ) {
				name = priceFields[ role ];
				errors[ name ] = priceError( priceValue( name ) );
			}
		}

		// Only worth comparing once both sides are individually valid numbers.
		var regular = priceFields.regular ? priceValue( priceFields.regular ) : '';
		var sale = priceFields.sale ? priceValue( priceFields.sale ) : '';

		if (
			'' !== regular && '' !== sale &&
			! errors[ priceFields.regular ] && ! errors[ priceFields.sale ] &&
			parseFloat( sale ) > parseFloat( regular )
		) {
			errors[ priceFields.sale ] = i18n.saleTooHigh || 'The sale price cannot be higher than the regular price.';
		}

		var valid = true;

		for ( name in errors ) {
			if ( Object.prototype.hasOwnProperty.call( errors, name ) ) {
				renderError( name, errors[ name ] );
				if ( errors[ name ] ) {
					valid = false;
				}
			}
		}

		return valid;
	}

	function renderError( name, message ) {
		var $wrap = $fieldWrap( name );

		if ( ! $wrap.length ) {
			return;
		}

		$wrap.find( '.tutor-sso-field-error' ).remove();

		if ( ! message ) {
			$wrap.removeClass( 'acf-error tutor-sso-field-invalid' );
			return;
		}

		// `acf-error` borrows ACF's own invalid-field styling.
		$wrap.addClass( 'acf-error tutor-sso-field-invalid' );
		$( '<div class="acf-notice -error tutor-sso-field-error"></div>' )
			.text( message )
			.appendTo( $wrap.find( '.acf-input' ).first() );
	}

	function bindPriceValidation() {
		var selectors = [];

		for ( var role in priceFields ) {
			if ( Object.prototype.hasOwnProperty.call( priceFields, role ) ) {
				selectors.push( '.acf-field[data-name="' + priceFields[ role ] + '"] input' );
			}
		}

		if ( ! selectors.length ) {
			return;
		}

		// Validate as the value changes so the message clears itself, and again
		// on submit — the click-to-save path can bypass `input` entirely.
		$( document ).off( 'input.tutorSsoPrice blur.tutorSsoPrice', selectors.join( ',' ) );
		$( document ).on( 'input.tutorSsoPrice blur.tutorSsoPrice', selectors.join( ',' ), function () {
			validatePrices();
		} );

		var $form = $( 'form#post' );

		if ( ! $form.length || $form.data( 'tutorSsoPriceBound' ) ) {
			return;
		}

		$form.data( 'tutorSsoPriceBound', true );
		$form.on( 'submit', function ( e ) {
			if ( validatePrices() ) {
				return;
			}

			e.preventDefault();

			// Re-enable the buttons WordPress disables on submit, or the screen
			// is left with no way to save after a blocked attempt.
			$( '#publishing-action .spinner, #saving-action .spinner' ).removeClass( 'is-active' );
			$form.find( '#publish, #save-post' ).removeClass( 'disabled' ).prop( 'disabled', false );
			$( 'body' ).removeClass( 'is-saving' );

			var $first = $( '.tutor-sso-field-invalid' ).first();

			if ( $first.length ) {
				$( 'html, body' ).animate( { scrollTop: $first.offset().top - 80 }, 200 );
				$first.find( 'input' ).first().trigger( 'focus' );
			}

			if ( window.console && i18n.fixErrors ) {
				window.console.warn( '[tutor-sso] ' + i18n.fixErrors );
			}
		} );
	}
} )( jQuery );
