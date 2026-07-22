/* global jQuery, tutorSsoProgramEnroll */
( function ( $ ) {
	'use strict';

	var cfg = window.tutorSsoProgramEnroll || {};
	var i18n = cfg.i18n || {};

	// ── Enroll ──────────────────────────────────────────────────────────────
	$( document ).on( 'click', '.rwaq-program-enroll', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		if ( $btn.prop( 'disabled' ) ) {
			return;
		}
		var $wrap = $btn.closest( '.rwaq-pd__enroll-wrap' );

		request( $btn, $wrap, 'tutor_sso_program_enroll', i18n.enrolling, function () {
			swapToEnrolled( $wrap );
		} );
	} );

	// ── Unenroll ────────────────────────────────────────────────────────────
	$( document ).on( 'click', '.rwaq-program-unenroll', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		if ( $btn.prop( 'disabled' ) ) {
			return;
		}
		if ( i18n.confirmUnenroll && ! window.confirm( i18n.confirmUnenroll ) ) {
			return;
		}
		var $wrap = $btn.closest( '.rwaq-pd__enroll-wrap' );

		request( $btn, $wrap, 'tutor_sso_program_unenroll', i18n.unenrolling, function () {
			swapToNotEnrolled( $wrap );
		} );
	} );

	/**
	 * Fire an AJAX action for the wrapper's program, toggling button state.
	 */
	function request( $btn, $wrap, action, busyLabel, onSuccess ) {
		var $msg = $wrap.find( '.rwaq-pd__enroll-message' ).first();
		var programId = $wrap.data( 'program-id' );
		var originalLabel = $btn.text();

		$btn.prop( 'disabled', true ).text( busyLabel || originalLabel );
		$msg.text( '' );

		$.ajax( {
			url: cfg.ajaxurl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: action,
				nonce: cfg.nonce,
				program_id: programId
			}
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					$msg.text( ( response.data && response.data.message ) || '' );
					onSuccess( response.data || {} );
				} else {
					$btn.prop( 'disabled', false ).text( originalLabel );
					$msg.text( errorText( response ) );
				}
			} )
			.fail( function ( jqXHR ) {
				$btn.prop( 'disabled', false ).text( originalLabel );
				$msg.text( errorText( jqXHR.responseJSON ) );
			} );
	}

	/**
	 * Rebuild the wrapper into its "enrolled" state: a "go to program" link
	 * (when a URL is known) plus the unenroll button.
	 */
	function swapToEnrolled( $wrap ) {
		var unenrollLabel = $wrap.data( 'unenroll-label' ) || i18n.unenroll;
		var gotoLabel = $wrap.data( 'goto-label' ) || i18n.goToProgram;
		var programUrl = $wrap.data( 'program-url' ) || '';
		var $msg = $wrap.find( '.rwaq-pd__enroll-message' ).first().detach();

		$wrap.find( '.rwaq-pd__enroll' ).remove();

		if ( programUrl ) {
			$( '<a></a>' )
				.addClass( 'rwaq-pd__enroll rwaq-pd__enroll--goto' )
				.attr( 'href', programUrl )
				.text( gotoLabel )
				.appendTo( $wrap );
		}

		$( '<button type="button"></button>' )
			.addClass( 'rwaq-pd__enroll rwaq-pd__enroll--unenroll rwaq-program-unenroll' )
			.text( unenrollLabel )
			.appendTo( $wrap );

		$wrap.append( $msg );
	}

	/**
	 * Rebuild the wrapper into its "not enrolled" state (enroll button).
	 */
	function swapToNotEnrolled( $wrap ) {
		var enrollLabel = $wrap.data( 'enroll-label' ) || i18n.enroll;
		var $msg = $wrap.find( '.rwaq-pd__enroll-message' ).first().detach();

		$wrap.find( '.rwaq-pd__enroll' ).remove();

		$( '<button type="button"></button>' )
			.addClass( 'rwaq-pd__enroll rwaq-program-enroll' )
			.text( enrollLabel )
			.appendTo( $wrap );

		$wrap.append( $msg );
	}

	function errorText( data ) {
		if ( data && data.data && data.data.message ) {
			return data.data.message;
		}
		return i18n.error || 'Something went wrong. Please try again.';
	}
} )( jQuery );
