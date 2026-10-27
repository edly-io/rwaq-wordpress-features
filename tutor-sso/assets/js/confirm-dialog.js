/* global tutorSsoConfirmDialog */
/**
 * A confirmation dialog in the site's language and style, in place of
 * window.confirm().
 *
 * The browser draws window.confirm() itself: its OK / Cancel buttons follow the
 * browser's language, not the page's, and cannot be styled — so an Arabic
 * question came with English buttons. This builds the same yes/no question on
 * a native <dialog>, which brings the modal behaviour with it: the page behind
 * is inert, focus stays inside, and Esc closes it.
 *
 * Usage:
 *   window.tutorSsoConfirm( message, { title, confirmLabel, cancelLabel }, function ( ok ) {} );
 *
 * Without <dialog> support it falls back to window.confirm(), so the question
 * is still asked.
 */
( function () {
	'use strict';

	var cfg = window.tutorSsoConfirmDialog || {};
	var labels = cfg.i18n || {};

	var dialog = null;
	var titleEl = null;
	var messageEl = null;
	var confirmBtn = null;
	var cancelBtn = null;
	var pending = null;
	var opener = null;

	/**
	 * Create the dialog once, on first use.
	 */
	function build() {
		if ( dialog ) {
			return;
		}

		dialog = document.createElement( 'dialog' );
		dialog.className = 'rwaq-confirm';
		dialog.setAttribute( 'aria-labelledby', 'rwaq-confirm-title' );
		dialog.setAttribute( 'aria-describedby', 'rwaq-confirm-message' );

		var box = document.createElement( 'div' );
		box.className = 'rwaq-confirm__box';

		titleEl = document.createElement( 'h2' );
		titleEl.className = 'rwaq-confirm__title';
		titleEl.id = 'rwaq-confirm-title';

		messageEl = document.createElement( 'p' );
		messageEl.className = 'rwaq-confirm__message';
		messageEl.id = 'rwaq-confirm-message';

		var actions = document.createElement( 'div' );
		actions.className = 'rwaq-confirm__actions';

		confirmBtn = document.createElement( 'button' );
		confirmBtn.type = 'button';
		confirmBtn.className = 'rwaq-confirm__btn rwaq-confirm__btn--confirm';

		cancelBtn = document.createElement( 'button' );
		cancelBtn.type = 'button';
		cancelBtn.className = 'rwaq-confirm__btn rwaq-confirm__btn--cancel';

		actions.appendChild( confirmBtn );
		actions.appendChild( cancelBtn );
		box.appendChild( titleEl );
		box.appendChild( messageEl );
		box.appendChild( actions );
		dialog.appendChild( box );
		document.body.appendChild( dialog );

		confirmBtn.addEventListener( 'click', function () {
			settle( true );
		} );

		cancelBtn.addEventListener( 'click', function () {
			settle( false );
		} );

		// Esc. Handled here so the answer is recorded before the dialog closes.
		dialog.addEventListener( 'cancel', function ( e ) {
			e.preventDefault();
			settle( false );
		} );

		// A click on the backdrop lands on the <dialog> itself; one inside the
		// box lands on the box or its children.
		dialog.addEventListener( 'click', function ( e ) {
			if ( e.target === dialog ) {
				settle( false );
			}
		} );

		// Closed some other way (another script, a form inside): still answer.
		dialog.addEventListener( 'close', function () {
			if ( pending ) {
				settle( false );
			}
		} );
	}

	/**
	 * Close the dialog, give focus back, and report the answer.
	 *
	 * @param {boolean} ok Whether the action was confirmed.
	 */
	function settle( ok ) {
		var callback = pending;
		var returnTo = opener;

		pending = null;
		opener = null;

		if ( dialog.open ) {
			dialog.close();
		}

		if ( returnTo && typeof returnTo.focus === 'function' && document.contains( returnTo ) ) {
			returnTo.focus();
		}

		if ( callback ) {
			callback( ok );
		}
	}

	/**
	 * Ask a yes/no question.
	 *
	 * @param {string}   message  The question.
	 * @param {Object}   options  Optional title, confirmLabel, cancelLabel.
	 * @param {Function} callback Called with true (confirmed) or false.
	 */
	window.tutorSsoConfirm = function ( message, options, callback ) {
		options = options || {};
		callback = typeof callback === 'function' ? callback : function () {};

		if ( typeof window.HTMLDialogElement !== 'function' ) {
			callback( window.confirm( message ) );
			return;
		}

		build();

		// A second question while one is open answers the first with "no".
		if ( pending ) {
			settle( false );
		}

		var title = options.title || labels.title || '';

		titleEl.textContent = title;
		titleEl.hidden = '' === title;
		messageEl.textContent = message;
		confirmBtn.textContent = options.confirmLabel || labels.confirm || 'OK';
		cancelBtn.textContent = options.cancelLabel || labels.cancel || 'Cancel';

		pending = callback;
		opener = document.activeElement;

		dialog.showModal();

		// The safe answer has focus, so Enter does not confirm by accident.
		cancelBtn.focus();
	};
}() );
