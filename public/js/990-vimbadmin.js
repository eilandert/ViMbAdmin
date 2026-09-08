/*
 * Open Solutions' ViMbAdmin Project.
 *
 * This file is part of Open Solutions' ViMbAdmin Project which is a
 * project which provides an easily manageable web based virtual
 * mailbox administration system.
 *
 * Copyright (c) 2011 Open Source Solutions Limited
 *
 * ViMbAdmin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * ViMbAdmin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with ViMbAdmin.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Open Source Solutions Limited T/A Open Solutions
 *   147 Stepaside Park, Stepaside, Dublin 18, Ireland.
 *   Barry O'Donovan <barry _at_ opensolutions.ie>
 *
 * @copyright Copyright (c) 2011 Open Source Solutions Limited
 * @license http://opensource.org/licenses/gpl-3.0.html GNU General Public License, version 3 (GPLv3)
 * @author Open Source Solutions Limited <info _at_ opensolutions.ie>
 * @author Barry O'Donovan <barry _at_ opensolutions.ie>
 * @author Roland Huszti <roland _at_ opensolutions.ie>
 * @package ViMbAdmin
 */


//****************************************************************************
// ViMbAdmin cookies
//****************************************************************************

var vm_cookie_options = {
    'expires': 90,
    'path': "/",
    // The retired plugin set neither; a preferences cookie is same-site only,
    // and over TLS it has no reason to travel in the clear.
    'sameSite': 'Lax',
    'secure': window.location.protocol === 'https:'
};

var vm_prefs = {
};

/**
 * Read or write the JSON preferences cookie without legacy jQuery plugins.
 *
 * The cookie value and attributes deliberately match the retired helper so
 * existing installations retain their saved preferences across the upgrade.
 */
function vmPrefsCookie( key, value, options )
{
    if( arguments.length > 1 ) {
        options = $.extend( {}, options );

        if( value === null || value === undefined )
            options.expires = -1;

        if( typeof options.expires === 'number' ) {
            var days = options.expires;
            options.expires = new Date();
            options.expires.setDate( options.expires.getDate() + days );
        }

        return document.cookie = [
            key, '=', JSON.stringify( value ),
            options.expires ? '; expires=' + options.expires.toUTCString() : '',
            options.path    ? '; path=' + options.path : '',
            options.domain  ? '; domain=' + options.domain : '',
            options.sameSite ? '; SameSite=' + options.sameSite : '',
            options.secure  ? '; secure' : ''
        ].join( '' );
    }

    // Scan every segment: an empty one (a trailing '; ' some clients emit) must
    // not terminate the search before a later entry is reached.
    var pairs = document.cookie.split( '; ' );
    for( var i = 0; i < pairs.length; i++ ) {
        var pair = pairs[i].split( '=' );

        if( pair[0] === key ) {
            try {
                var parsed = JSON.parse( pair.slice( 1 ).join( '=' ) );
                return parsed !== null && typeof parsed === 'object' ? parsed : null;
            } catch( error ) {
                return null;
            }
        }
    }

    return null;
}

var cprefs = vmPrefsCookie( 'vm_prefs' );

if( cprefs != null )
	vm_prefs = cprefs;


//****************************************************************************
//****************************************************************************



$( 'document' ).ready( function(){

	// Activate the modal dialog pop up
    $( "a[id|='modal-dialog']" ).on( 'click', tt_openModalDialog );

    $("[rel=popover]").popover( { html: true } );

    $( '.have-tooltip' ).tooltip( { html: true, delay: { show: 500, hide: 2 }, trigger: 'hover' } );
    $( '.have-tooltip-below' ).tooltip( { html: true, delay: { show: 500, hide: 2 }, trigger: 'hover', placement: 'bottom' } );
    $( '.have-tooltip-long' ).tooltip( { html: true, trigger: 'hover', placement: 'top' } );

});




//****************************************************************************
// ViMbAdmin global js functions
//****************************************************************************


/**
 * This function creates throbber with some default parameters and return the throbber object.
 *
 * @param size  This is size of throbber in pixels.
 * @param lines This is lines count, defines how many lines per throbber.
 * @param strokewidth This is the widh of line.
 * @param fallback This is path to alternative throbber image if browser not compatible with this one.
 * @return Throbber The throbber object
 */

function tt_throbber( size, lines, strokewidth, fallback )
{
    // Bootstrap 5 ships exactly two spinner sizes: the default and -sm.
    var sizeClass = size >= 25 ? '' : 'spinner-border-sm';

    var $el = $('<div></div>')
        .addClass('spinner-border')
        .addClass(sizeClass)
        .attr('role', 'status')
        .append($('<span></span>').addClass('visually-hidden').text('Loading...'));

    // Return a controller object that survives jQuery DOM operations like appendTo
    var controller = {
        $el: $el,
        appendTo: function(target) {
            this.$el.appendTo(target);
            return this;
        },
        start: function() {
            return this;
        },
        stop: function() {
            var el = this.$el;
            el.fadeOut(750, function() {
                el.remove();
            });
            return this;
        }
    };

    return controller;
}

/**
 * This function is handling toggle elements.
 *
 * First function unbinds toggle element, removes label type and pointer.
 * Then creates throbber and add it to div trobber with id throb-{toggle element id}.
 * div for throbber should be created manually. Function only assigns throbber to it. After
 * that it calls AJAX for passed URL and data. If response ok flag ok is set to true otherwise
 * error message is show. If we have AJAX error ten ossAjaxErrorHandler calls. After AJAX error
 * or success handlers function sets back label type and pointer by flags On and Ok , kills throbber
 * end bind same function again for toggle element.
 *
 * @param e Element witch will be edited
 * @param Url This is URL for AJAX.
 * @param data Data for AJAX to post.
 * @param delElement Element witch will be removed
 */
function ossToggle( e, Url, data, delElement )
{
    e.off();

    if( e.hasClass( 'disabled' ) )
        return;


    var on = true;
    if( e.hasClass( 'btn-danger' ) ) {
        e.removeClass( "btn-danger" ).prop( 'disabled', true );
    } else {
        on = false;
        e.removeClass( "btn-success" ).prop( 'disabled', true );
    }

    var Throb = tt_throbber( 18, 10, 1, 'images/throbber_16px.gif' ).appendTo( $( '#throb-' + e.attr( 'id' ) ).get(0) ).start();

    var ok = false;

    $.ajax({
        url: Url,
        data: data,
        async: true,
        cache: false,
        type: 'POST',
        timeout: 10000,
        success: function( data ){
            if( data == "ok" ) {
                ok = true;
            } else {
                ossAddMessage( data, 'danger' );
            }
        },
        error: ossAjaxErrorHandler,
        complete: function(){

            if( !ok ) on = !on;

            if( on ) {
                e.html( "Yes" ).addClass( "btn-success" ).prop( 'disabled', false );
            } else {
                e.html( "No" ).addClass( "btn-danger" ).prop( 'disabled', false );
            }

            $( '#throb-' + e.attr( 'id' ) ).html( "" );

            e.on( 'click', function( event ){
                ossToggle( e, Url, data, delElement );
            });

            if( delElement && ok ) {
            	$( delElement ).hide( 'slow', function(){ $( delElement ).remove() } );
            }

        }
    });

    return on;
}

/**
 * This function is opening modal dialog with contact us form.
 *
 * First it creates the throbber witch is shown while form is loading by ajax.
 * When function creates and opens modal dialog witch is showing throbber.
 * When form is load the throbber is replaced by it. If ajax gets en error the
 * ossAjaxErrorHandler is called.
 *
 * @param event event Its jQuery event, needed to prevent element from default actions.
 */
function tt_openModalDialog(event) {

    event.preventDefault();

    if( $( event.target ).is( "i" ) )
        element = $( event.target ).parent();
    else
        element = $( event.target );


    id = element.attr( 'id' ).substr( element.attr( 'id' ).lastIndexOf( '-' ) + 1 );

    if( id.substring( 0, 4 ) == "wide" )
    {
        $( '#modal_dialog_shell .modal-dialog' ).addClass( 'modal-wide' );
        $( '#modal_dialog_shell .modal-dialog' ).removeClass( 'modal-email' );
    }
    else if( id.substring( 0, 5 ) == "email" )
    {
        $( '#modal_dialog_shell .modal-dialog' ).addClass( 'modal-email' );
        $( '#modal_dialog_shell .modal-dialog' ).removeClass( 'modal-wide' );
    }
    else
    {
        $( '#modal_dialog_shell .modal-dialog' ).removeClass( 'modal-wide' );
        $( '#modal_dialog_shell .modal-dialog' ).removeClass( 'modal-email' );
    }

    $('#modal_dialog').html( '<div id="throb" style="padding-left:230px; padding-top:175px; height:275px;"></div>' );


    var Throb = tt_throbber( 100, 20, 1.8 ).appendTo( $( '#throb' ).get(0) ).start();

    dialog = ossModal( '#modal_dialog_shell' );

    $.ajax({
        url: element.attr( 'href' ) ,
        async: true,
        cache: false,
        type: 'POST',
        timeout: 10000,
        success:    function(data) {
                        $('#modal_dialog').html( data );
                        $( '.modal-body' ).scrollTop( 0 );
                        $( '#modal_dialog_cancel' ).on( 'click', function(){
                            dialog.modal('hide');
                        });
                     },

        error:     ossAjaxErrorHandler
    });
};

/**
 * This function is handling ajax errors.
 *
 * First function is checking if ajax was called on modal window, if so when
 * it checks if buttons are shown that mean that ajax crashed then modal dialog was
 * submitting and enabling modal dialog buttons. If buttons not visible that means
 * that ajax crashed then the content was loading so it close modal dialog.
 * After that it checks if throbber (canvas) is showing and if so it closes that too.
 * And after that it calls ossAddMessage.
 *
 */
function ossAjaxErrorHandler( XMLHttpRequest, textStatus, errorThrown )
{
    if( $('#modal_dialog_shell:visible').length )
    {
        if( $('#modal_dialog_save').length ){
            $('#modal_dialog_save').prop( 'disabled', false ).removeClass( 'disabled' );
            $('#modal_dialog_cancel').prop( 'disabled', false ).removeClass( 'disabled' );
        }
        else
        {
            if( dialog )
            {
                dialog.modal('hide');
            }
        }
    }

    if( $('canvas').length ){
        $('canvas').remove();
    }

    if( $('.spinner-border').length ){
        $('.spinner-border').remove();
    }
    ossAddMessage( 'An unexpected error occurred.', 'danger', true );
}


/**
 * This function adding oss messages.
 *
 * Function defines message box. And when check where the message should be shown.
 * First it is looking for modal dialog to display oss message in it.
 * If modal dialog was not found it looks for class breadcrumb, witch is page header,
 * and insert oss message after it. And finally if no modal dialog or breadcrumb was found
 * it insert oss message at the top of main div.
 *
 * @param msg  This is main text of oss message.
 * @param type This is type of oss message(success, error, info, etc.).
 * @param handled This is means that it came from ossAjaxErrorHandler and message can be displayed on modal dialog
 */
function ossAddMessage( msg, type, handled )
{
    rand = Math.floor( Math.random() * 1000000 );

    msgbox = '<div id="oss-message-' + rand + '" class="alert alert-' + type + ' alert-dismissible fade show">\
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>\
                                    '+ msg + '</div>';

    if( $('.modal-body:visible').length && handled )
    {
        $('.modal-body').prepend( msgbox );


    }
    else if( $('.page-header').length )
    {
        $('.page-header').after( msgbox );

    }
    else if( $('.page-content').length )
    {
        $('.page-content').prepend( msgbox );

    }
    else if( $( ".container" ).length )
    {
        $('.container').before( msgbox );
    }
    else if( $('#main').length )
    {
        $('#main').prepend( msgbox );
    }

    $( "#oss-message-" + rand ).alert();
}

/**
 * This function is for validating input field.
 *
 * Function checks if the input field has the value if not set error,
 * and sets valid to false. If value not empty and email flag sets to
 * true then function calls validate email, and if email validate function
 * removes class error, if email not valid function add set error and sets
 * valid to false. and if email flag is false, and value is not empty, we remove
 * error from input field.
 *
 * @param string fieldName The field id, we need only id because we have to build other id from it.
 * @param bool email The email flag, witch means that input field is email and we need to validate it as email.
 */
function ossJscriptFieldValidator( fieldName, email )
{
    if( $( '#' + fieldName ).val() != "" )
    {
        if( email )
        {
            if( ossValidateEmail( $( '#' + fieldName ).val() ) )
            {
               $( '#div-form-' + fieldName ).removeClass( 'error' );
               $( '#help-' + fieldName ).html( "" );
            }
        }
        else
        {
            $( '#div-form-' + fieldName ).removeClass( 'error' );
            $( '#help-' + fieldName ).html( "" );
        }
    }
}


/**
 * Add tab for plugin tabs.
 * 
 * If there was no plugins it will not show tabas menu at all, until first tab will be added.
 *
 * @param string title Title of the tab.
 * @param string id Id of tab content to show.
 */
function addPluginTab( title, id )
{    
        if( id.substr( 0, 4 ) != "tab_" )
            id = "tab_" + id;

	    var tab = "<li><a data-bs-toggle=\"tab\"";
	    
	    if( $( "#" + id ).has( ".error" ).length )
	        tab += " class=\"text-danger\"";
	    
	    tab += " href=\"#" + id + "\">" + title + "</a></li>\n";
	    $( "#plugin_tabs" ).show().append( tab );
}


/**
 * This function is simply checks regular expression of given string, and return if it is email address, otherwise return false.
 *
 * @param string email The string witch is validating as email address.
 * @return bool
 */
function ossValidateEmail( email)
{
    var emailReg = /^([\w-\.]+@([\w-]+\.)+[\w-]{2,4})?$/;
    if( emailReg.test( email ) )
    {
        return true;
    }
    else
    {
        return false;
    }
}

/**
 * This function generates random password and set to field by given id.
 *
 * @param int len The wanted password length.
 * @param string email The field id to set the password.
 */
function randPasword( len, id )
{
    $( '#' + id ).val( randomPassword( len ) );
    $( '#' + id ).trigger( 'blur' );
}


//****************************************************************************
// DataTables http://datatables.net/blog/Twitter_Bootstrap_2
//****************************************************************************


/* Default class modification */
function vmDataTableServerData( source, data, callback, minimum, tableSelector, settings )
{
        var search = '', echo = 1;
        $.each( data, function( _, parameter ) {
                if( parameter.name === 'sSearch' ) search = String( parameter.value || '' ).trim();
                if( parameter.name === 'sEcho' ) echo = parseInt( parameter.value, 10 ) || 1;
        } );

        var searchLength = search.replace( /[\uD800-\uDBFF][\uDC00-\uDFFF]/g, '_' ).length;
        var emptyResult = { sEcho: echo, iTotalRecords: 0, iTotalDisplayRecords: 0, aaData: [] };
        if( searchLength > 0 && searchLength < minimum ) {
                callback( emptyResult );
                setTimeout( function() {
                        $( tableSelector + ' tbody td.dataTables_empty' )
                                .text( 'Enter at least ' + minimum + ' characters to search.' );
                }, 0 );
                return;
        }

        return $.ajax( {
                url: source,
                data: data,
                dataType: 'json',
                success: callback,
                error: function( xhr, error ) {
                        var api = $.fn.dataTableExt.oApi;
                        var handled = api._fnCallbackFire(
                                settings, null, 'xhr', [settings, null, xhr]
                        );
                        if( $.inArray( true, handled ) === -1 ) {
                                api._fnLog(
                                        settings,
                                        0,
                                        error === 'parsererror' ? 'Invalid JSON response' : 'Ajax error',
                                        error === 'parsererror' ? 1 : 7
                                );
                        }
                        api._fnProcessingDisplay( settings, false );
                }
        } );
}

$.extend( $.fn.dataTableExt.oStdClasses, {
        "sWrapper": "dataTables_wrapper form-inline"
} );

/* API method to get paging information */
$.fn.dataTableExt.oApi.fnPagingInfo = function ( oSettings )
{
        return {
                "iStart":         oSettings._iDisplayStart,
                "iEnd":           oSettings.fnDisplayEnd(),
                "iLength":        oSettings._iDisplayLength,
                "iTotal":         oSettings.fnRecordsTotal(),
                "iFilteredTotal": oSettings.fnRecordsDisplay(),
                "iPage":          Math.ceil( oSettings._iDisplayStart / oSettings._iDisplayLength ),
                "iTotalPages":    Math.ceil( oSettings.fnRecordsDisplay() / oSettings._iDisplayLength )
        };
}

/* Bootstrap style pagination control */
$.extend( $.fn.dataTableExt.oPagination, {
        "bootstrap": {
                "fnInit": function( oSettings, nPaging, fnDraw ) {
                        var oLang = oSettings.oLanguage.oPaginate;
                        var fnClickHandler = function ( e ) {
                                e.preventDefault();
                                if ( oSettings.oApi._fnPageChange(oSettings, e.data.action) ) {
                                        fnDraw( oSettings );
                                }
                        };

                        $(nPaging).addClass('pagination').append(
                                '<ul>'+
                                        '<li class="prev disabled"><a href="#">&larr; '+oLang.sPrevious+'</a></li>'+
                                        '<li class="next disabled"><a href="#">'+oLang.sNext+' &rarr; </a></li>'+
                                '</ul>'
                        );
                        var els = $('a', nPaging);
                        $(els[0]).on( 'click.DT', { action: "previous" }, fnClickHandler );
                        $(els[1]).on( 'click.DT', { action: "next" }, fnClickHandler );
                },

                "fnUpdate": function ( oSettings, fnDraw ) {
                        var iListLength = 5;
                        var oPaging = oSettings.oInstance.fnPagingInfo();
                        var an = oSettings.aanFeatures.p;
                        var i, j, sClass, iStart, iEnd, iHalf=Math.floor(iListLength/2);

                        if ( oPaging.iTotalPages < iListLength) {
                                iStart = 1;
                                iEnd = oPaging.iTotalPages;
                        }
                        else if ( oPaging.iPage <= iHalf ) {
                                iStart = 1;
                                iEnd = iListLength;
                        } else if ( oPaging.iPage >= (oPaging.iTotalPages-iHalf) ) {
                                iStart = oPaging.iTotalPages - iListLength + 1;
                                iEnd = oPaging.iTotalPages;
                        } else {
                                iStart = oPaging.iPage - iHalf + 1;
                                iEnd = iStart + iListLength - 1;
                        }

                        for ( i=0, iLen=an.length ; i<iLen ; i++ ) {
                                // Remove the middle elements
                                $('li:gt(0)', an[i]).filter(':not(:last)').remove();

                                // Add the new list items and their event handlers
                                for ( j=iStart ; j<=iEnd ; j++ ) {
                                        sClass = (j==oPaging.iPage+1) ? 'class="active"' : '';
                                        $('<li '+sClass+'><a href="#">'+j+'</a></li>')
                                                .insertBefore( $('li:last', an[i])[0] )
                                                .on('click', function (e) {
                                                        e.preventDefault();
                                                        oSettings._iDisplayStart = (parseInt($('a', this).text(),10)-1) * oPaging.iLength;
                                                        fnDraw( oSettings );
                                                } );
                                }

                                // Add / remove disabled classes from the static elements
                                if ( oPaging.iPage === 0 ) {
                                        $('li:first', an[i]).addClass('disabled');
                                } else {
                                        $('li:first', an[i]).removeClass('disabled');
                                }

                                if ( oPaging.iPage === oPaging.iTotalPages-1 || oPaging.iTotalPages === 0 ) {
                                        $('li:last', an[i]).addClass('disabled');
                                } else {
                                        $('li:last', an[i]).removeClass('disabled');
                                }
                        }
                }
        }
} );

//Adding more sort filters
jQuery.extend( jQuery.fn.dataTableExt.oSort, {
    "num-html-pre": function ( a ) {
        var x = String(a).replace( /<[\s\S]*?>/g, "" );
        return parseFloat( x );
    },

    "num-html-asc": function ( a, b ) {
        return ((a < b) ? -1 : ((a > b) ? 1 : 0));
    },

    "num-html-desc": function ( a, b ) {
        return ((a < b) ? 1 : ((a > b) ? -1 : 0));
    }
} );


//****************************************************************************
// Delegated confirmation guard for destructive submits (VIM-D07)
//****************************************************************************
//
// These confirmations used to live in inline onsubmit="return confirm('...')"
// attributes. A CSP nonce does not whitelist inline event handlers -- only
// 'unsafe-inline' did -- so with script-src nonce-only they would silently stop
// firing and every destructive action would proceed without asking. The prompt
// now travels as a data-confirm attribute and one delegated handler enforces it,
// which also covers rows the DataTables renderers build after page load.
//
// preventDefault() on cancel is what actually blocks the submit; returning false
// from a delegated jQuery handler would too, but being explicit keeps the
// behaviour obvious and testable.
jQuery( document ).on( 'submit', 'form[data-confirm]', function( event ) {
    var message = jQuery( this ).attr( 'data-confirm' );

    if ( typeof message !== 'string' || message === '' ) {
        return;
    }

    if ( !window.confirm( message ) ) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
} );
