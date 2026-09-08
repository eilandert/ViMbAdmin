/*
 * Native Bootstrap 5 modal helpers for ViMbAdmin.
 *
 * This file used to vendor bootbox.js v3.3.0. bootbox 3.x builds Bootstrap 2
 * modal markup (`.modal.hide.fade` with `.modal-header`/`.modal-body`/
 * `.modal-footer` as direct children) and reveals it by toggling the BS2-only
 * `.in` class. Bootstrap 5 requires a `.modal > .modal-dialog > .modal-content`
 * tree, reveals on `.modal.show`, and drives everything through its own
 * `bootstrap.Modal` lifecycle, so bootbox 3.x cannot show a dialog at all
 * under BS5.
 *
 * Rather than upgrading to bootbox 6.x for the single API this application
 * actually uses, the two helpers below are implemented directly on top of the
 * Bootstrap 5 Modal component:
 *
 *   bootbox.alert( html [, callback] )
 *       An informational dialog with a single dismiss button. The `bootbox`
 *       global and this signature are preserved because
 *       library/OSS/Smarty/functions/function.OSS_Message.php emits
 *       `bootbox.alert( '...' )` for OSS_Message_Pop_Up messages, and that
 *       Smarty helper is a stable API that must keep working unchanged.
 *
 *   ossModal( selector )
 *       Shows an existing in-page modal and returns the jQuery object for it.
 *       Bootstrap 5's jQuery bridge treats an object argument to `.modal({...})`
 *       as configuration only and does NOT show the dialog, so the former
 *       `$( '#x' ).modal({ backdrop: true, keyboard: true, show: true })` call
 *       sites go through this helper instead. The returned jQuery object still
 *       accepts `.modal( 'hide' )`, which BS5's bridge does honour, so existing
 *       teardown code is unaffected.
 *
 * Both helpers are globals to match how the rest of this application's scripts
 * are written (plain globals in numbered bundle files, no module system).
 */

/* global bootstrap */

var bootbox = window.bootbox || {};

(function( $, window, document ) {
    'use strict';

    /**
     * Resolve the Bootstrap 5 Modal constructor.
     *
     * Returns null when Bootstrap's JS has not loaded, so callers can degrade
     * instead of throwing.
     */
    function modalCtor()
    {
        if( typeof bootstrap !== 'undefined' && bootstrap && bootstrap.Modal )
            return bootstrap.Modal;

        if( window.bootstrap && window.bootstrap.Modal )
            return window.bootstrap.Modal;

        return null;
    }

    /**
     * Show an existing in-page modal and return its jQuery object.
     *
     * @param {string|Element|jQuery} target The modal element or a selector for it.
     * @return {jQuery} The jQuery object wrapping the modal element.
     */
    function ossModal( target )
    {
        var $el = $( target );
        var Modal = modalCtor();

        if( Modal && $el.length )
            Modal.getOrCreateInstance( $el.get( 0 ), { backdrop: true, keyboard: true } ).show();

        return $el;
    }

    /**
     * Informational dialog with a single dismiss button.
     *
     * The dialog element is created per call, shown, and removed from the DOM
     * once Bootstrap has finished hiding it, so repeated alerts do not
     * accumulate detached markup.
     *
     * @param {string} message  Message body. Rendered as HTML, matching bootbox 3.x.
     * @param {Function} [callback] Invoked after the dialog has been dismissed.
     * @return {jQuery} The jQuery object wrapping the dialog element.
     */
    function alertDialog( message, callback )
    {
        var $dialog = $(
            '<div class="modal fade" tabindex="-1" aria-hidden="true">' +
                '<div class="modal-dialog modal-dialog-centered">' +
                    '<div class="modal-content">' +
                        '<div class="modal-body"></div>' +
                        '<div class="modal-footer">' +
                            '<button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>'
        );

        // bootbox 3.x rendered the message as HTML and callers rely on that
        // (e.g. application/views/mailbox/js/list.js builds a <table>). Callers
        // are responsible for escaping any untrusted value they interpolate.
        $dialog.find( '.modal-body' ).html( message );

        $( document.body ).append( $dialog );

        $dialog.on( 'hidden.bs.modal', function() {
            $dialog.remove();

            if( typeof callback === 'function' )
                callback();
        } );

        var Modal = modalCtor();

        if( Modal )
        {
            Modal.getOrCreateInstance( $dialog.get( 0 ), { backdrop: true, keyboard: true } ).show();
        }
        else
        {
            // Bootstrap's JS has not loaded. There is no lifecycle left to show
            // or hide the dialog, so degrade the same way callers expect a
            // dismissed alert to behave: drop the detached markup immediately
            // and still run the callback, instead of leaving hidden markup in
            // the DOM with the callback never firing. Capture the message text
            // BEFORE removing the dialog and surface it via the native alert --
            // without this the message was silently discarded and the caller
            // believed the user had acknowledged it.
            window.alert( $dialog.find( '.modal-body' ).text() || String( message ) );

            $dialog.remove();

            if( typeof callback === 'function' )
                callback();
        }

        return $dialog;
    }

    /*
     * Bootstrap 2 dismissed an alert via `data-dismiss="alert"`; Bootstrap 5
     * renamed the attribute to `data-bs-dismiss` and only binds its own
     * handler to that spelling.
     *
     * library/OSS/Smarty/functions/function.OSS_Message.php emits the legacy
     * `data-dismiss="alert"` spelling. That Smarty helper has a stable API and
     * its own contract test (tests/test-oss-message.php), so it is left
     * unchanged and the legacy attribute is honoured here instead, by
     * delegation so it also covers alerts injected after page load.
     *
     * Scoped to `[data-dismiss="alert"]` only: modal dismissal is Bootstrap
     * 5's own `data-bs-dismiss="modal"` throughout this application.
     */
    $( document ).on( 'click', '[data-dismiss="alert"]', function( event ) {
        event.preventDefault();

        var $alert = $( this ).closest( '.alert' );

        if( !$alert.length )
            return;

        if( typeof bootstrap !== 'undefined' && bootstrap && bootstrap.Alert )
            bootstrap.Alert.getOrCreateInstance( $alert.get( 0 ) ).close();
        else
            $alert.remove();
    } );

    bootbox.alert = alertDialog;

    window.bootbox  = bootbox;
    window.ossModal = ossModal;
} )( jQuery, window, document );
