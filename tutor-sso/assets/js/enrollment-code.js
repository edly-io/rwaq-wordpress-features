/* global jQuery, tutorSsoEnrollmentCode */
/**
 * The enrollment-code form (includes/enrollment-code.php).
 *
 * A button with `.rwaq-enrollment-code-open` stands in for enroll on a course or
 * program that needs a code. It opens this dialog; the code goes to the server,
 * which hands it to Open edX. On success the dialog closes and the button's
 * wrapper gets a `tutorsso:code-redeemed` event — enroll.js and
 * program-enroll.js answer it by switching the wrapper to "go to course /
 * program", exactly as after a normal enroll, with no page reload.
 *
 * Built on a native <dialog>, like confirm-dialog.js: the page behind is inert,
 * focus stays inside, and Esc closes it.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.tutorSsoEnrollmentCode || {};
	var i18n = cfg.i18n || {};

	var dialog = null;
	var form = null;
	var messageEl = null;
	var input = null;
	var errorEl = null;
	var submitBtn = null;
	var opener = null;
	var busy = false;

	/**
	 * Create the dialog once, on first use.
	 */
	function build() {
		if ( dialog ) {
			return;
		}

		dialog = document.createElement( 'dialog' );
		dialog.className = 'rwaq-confirm rwaq-code-dialog';
		dialog.setAttribute( 'aria-labelledby', 'rwaq-code-title' );
		dialog.setAttribute( 'aria-describedby', 'rwaq-code-message' );

		form = document.createElement( 'form' );
		form.className = 'rwaq-confirm__box';
		form.noValidate = true;

		var title = document.createElement( 'h2' );
		title.className = 'rwaq-confirm__title';
		title.id = 'rwaq-code-title';
		title.textContent = i18n.title || '';

		messageEl = document.createElement( 'p' );
		messageEl.className = 'rwaq-confirm__message';
		messageEl.id = 'rwaq-code-message';

		input = document.createElement( 'input' );
		input.type = 'text';
		input.className = 'rwaq-code-dialog__input';
		input.name = 'enrollment_code';
		input.autocomplete = 'off';
		input.spellcheck = false;
		input.maxLength = 100;
		input.dir = 'ltr';
		input.placeholder = i18n.placeholder || '';
		input.setAttribute( 'aria-label', i18n.placeholder || i18n.title || '' );
		input.setAttribute( 'autocapitalize', 'characters' );

		errorEl = document.createElement( 'p' );
		errorEl.className = 'rwaq-code-dialog__error';
		errorEl.id = 'rwaq-code-error';
		errorEl.setAttribute( 'role', 'alert' );
		errorEl.hidden = true;
		input.setAttribute( 'aria-describedby', 'rwaq-code-error' );

		var actions = document.createElement( 'div' );
		actions.className = 'rwaq-confirm__actions';

		submitBtn = document.createElement( 'button' );
		submitBtn.type = 'submit';
		submitBtn.className = 'rwaq-confirm__btn rwaq-confirm__btn--confirm';
		submitBtn.textContent = i18n.submit || 'OK';

		var cancelBtn = document.createElement( 'button' );
		cancelBtn.type = 'button';
		cancelBtn.className = 'rwaq-confirm__btn rwaq-confirm__btn--cancel';
		cancelBtn.textContent = i18n.cancel || 'Cancel';

		actions.appendChild( submitBtn );
		actions.appendChild( cancelBtn );
		form.appendChild( title );
		form.appendChild( messageEl );
		form.appendChild( input );
		form.appendChild( errorEl );
		form.appendChild( actions );
		dialog.appendChild( form );
		document.body.appendChild( dialog );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			submit();
		} );

		cancelBtn.addEventListener( 'click', close );

		// Esc: not while a request is in flight, so its answer is not lost.
		dialog.addEventListener( 'cancel', function ( e ) {
			e.preventDefault();
			close();
		} );

		// A click on the backdrop lands on the <dialog> itself.
		dialog.addEventListener( 'click', function ( e ) {
			if ( e.target === dialog ) {
				close();
			}
		} );

		input.addEventListener( 'input', function () {
			showError( '' );
		} );
	}

	function close() {
		if ( busy ) {
			return;
		}

		if ( dialog.open ) {
			dialog.close();
		}

		if ( opener && document.contains( opener ) ) {
			opener.focus();
		}

		opener = null;
	}

	function showError( text ) {
		errorEl.textContent = text;
		errorEl.hidden = '' === text;
		input.setAttribute( 'aria-invalid', '' === text ? 'false' : 'true' );
	}

	function setBusy( on ) {
		busy = on;
		submitBtn.disabled = on;
		input.readOnly = on;
		submitBtn.textContent = on ? ( i18n.submitting || i18n.submit ) : i18n.submit;
	}

	function submit() {
		var code = input.value.trim();

		if ( busy || ! opener ) {
			return;
		}

		if ( '' === code ) {
			showError( i18n.empty || '' );
			input.focus();
			return;
		}

		var $btn = $( opener );

		setBusy( true );
		showError( '' );

		$.ajax( {
			url: cfg.ajaxurl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'tutor_sso_redeem_enrollment_code',
				nonce: cfg.nonce,
				kind: $btn.data( 'code-kind' ),
				id: $btn.data( 'code-id' ),
				code: code
			}
		} )
			.done( function ( response ) {
				if ( response && response.success ) {
					succeed( $btn, response.data || {} );
				} else {
					fail( response );
				}
			} )
			.fail( function ( jqXHR ) {
				fail( jqXHR.responseJSON );
			} );
	}

	function succeed( $btn, data ) {
		var $wrap = $btn.closest( '.tutor-sso-enroll-wrap, .rwaq-pd__enroll-wrap' );

		setBusy( false );
		input.value = '';
		opener = null;
		dialog.close();

		// enroll.js / program-enroll.js swap the wrapper to its enrolled state.
		$wrap.trigger( 'tutorsso:code-redeemed', [ data ] );

		var $goto = $wrap.find( 'a' ).first();
		if ( $goto.length ) {
			$goto.trigger( 'focus' );
		}
	}

	function fail( response ) {
		setBusy( false );
		showError(
			response && response.data && response.data.message
				? response.data.message
				: ( i18n.error || 'Something went wrong. Please try again.' )
		);
		input.focus();
		input.select();
	}

	$( document ).on( 'click', '.rwaq-enrollment-code-open', function ( e ) {
		e.preventDefault();

		if ( typeof window.HTMLDialogElement !== 'function' ) {
			return;
		}

		build();

		opener = this;
		messageEl.textContent = 'program' === $( this ).data( 'code-kind' )
			? ( i18n.messageProgram || '' )
			: ( i18n.messageCourse || '' );
		input.value = '';
		showError( '' );
		setBusy( false );

		dialog.showModal();
		input.focus();
	} );
}( jQuery ) );
