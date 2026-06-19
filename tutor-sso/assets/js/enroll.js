/* global jQuery, tutorSsoEnroll */
( function ( $ ) {
	'use strict';

	var cfg = window.tutorSsoEnroll || {};
	var i18n = cfg.i18n || {};

	// ── Enroll ────────────────────────────────────────────────────────────────
	$( document ).on( 'click', '.tutor-sso-enroll', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		if ( $btn.prop( 'disabled' ) ) {
			return;
		}
		var $wrap = $btn.closest( '.tutor-sso-enroll-wrap' );

		request( $btn, $wrap, 'tutor_sso_enroll', i18n.enrolling, function ( data ) {
			swapToEnrolled( $wrap, data );
		}, $wrap.data( 'enroll-message' ) );
	} );

	// ── Unenroll ────────────────────────────────────────────────────────────────
	$( document ).on( 'click', '.tutor-sso-unenroll', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		if ( $btn.prop( 'disabled' ) ) {
			return;
		}
		if ( i18n.confirmUnenroll && ! window.confirm( i18n.confirmUnenroll ) ) {
			return;
		}
		var $wrap = $btn.closest( '.tutor-sso-enroll-wrap' );

		request( $btn, $wrap, 'tutor_sso_unenroll', i18n.unenrolling, function () {
			swapToNotEnrolled( $wrap );
		}, $wrap.data( 'unenroll-message' ) );
	} );

	/**
	 * Fire an AJAX action for the wrapper's course, toggling button state.
	 */
	function request( $btn, $wrap, action, busyLabel, onSuccess, successMsg ) {
		var $msg = $wrap.find( '.tutor-sso-enroll-message' ).first();
		var courseId = $wrap.data( 'course-id' );
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
				course_id: courseId
			}
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					// A configured custom message wins; otherwise use the server's.
					$msg.text( successMsg || ( response.data && response.data.message ) || '' );
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
	 * Rebuild the wrapper into its "enrolled" state.
	 */
	function swapToEnrolled( $wrap, data ) {
		var unenrollLabel = $wrap.data( 'unenroll-label' ) || i18n.unenroll;
		var gotoLabel = $wrap.data( 'goto-label' ) || i18n.goToCourse;
		var courseUrl = data && data.course_url ? data.course_url : '';
		var showGoto = $wrap.data( 'show-goto' ) !== false;
		var showUnenroll = $wrap.data( 'show-unenroll' ) !== false;
		var $msg = $wrap.find( '.tutor-sso-enroll-message' ).first().detach();

		$wrap.find( '.tutor-sso-enroll-btn' ).remove();

		if ( showGoto && courseUrl ) {
			$( '<a></a>' )
				.addClass( 'tutor-sso-enroll-btn tutor-sso-enroll-btn--goto' )
				.attr( 'href', courseUrl )
				.text( gotoLabel )
				.appendTo( $wrap );
		}

		if ( showUnenroll ) {
			$( '<button type="button"></button>' )
				.addClass( 'tutor-sso-enroll-btn tutor-sso-enroll-btn--unenroll tutor-sso-unenroll' )
				.text( unenrollLabel )
				.appendTo( $wrap );
		}

		$wrap.append( $msg );
	}

	/**
	 * Rebuild the wrapper into its "not enrolled" state.
	 */
	function swapToNotEnrolled( $wrap ) {
		var enrollLabel = $wrap.data( 'enroll-label' ) || i18n.enroll;
		var $msg = $wrap.find( '.tutor-sso-enroll-message' ).first().detach();

		$wrap.find( '.tutor-sso-enroll-btn' ).remove();

		$( '<button type="button"></button>' )
			.addClass( 'tutor-sso-enroll-btn tutor-sso-enroll-btn--enroll tutor-sso-enroll' )
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
