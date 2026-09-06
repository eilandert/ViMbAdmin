var deleteDialog;
var oDataTable;

$(document).ready( function()
{
    oDataTable = $( '#list_table' ).dataTable({
        'fnDrawCallback': function() {
            if( vm_prefs['iLength'] !=  $( "select[name|='list_table_length']" ).val() )
                vm_prefs['iLength'] = $( "select[name|='list_table_length']" ).val();

            $.jsonCookie( 'vm_prefs', vm_prefs, vm_cookie_options );
        },
        'iDisplayLength': ( typeof vm_prefs != 'undefined' && 'iLength' in vm_prefs )
                ? parseInt( vm_prefs['iLength'] )
                : {if isset( $options.defaults.table.entries )}{$options.defaults.table.entries}{else}10{/if},
        "sDom": "<'row'<'span6'l><'span6'f>r>t<'row'<'span6'i><'span6'p>>",
        "sPaginationType": "bootstrap",
        'aoColumns': [
            null,
            null,
            { 'bSortable': false, "bSearchable": false }
        ]
    });

    $( "button[id|='delete-alias']" ).on( 'click', deleteAlias );

}); // document onready

function deleteAlias( event ) {

    event.preventDefault();

    if( $( event.target ).is( "i" ) )
        element = $( event.target ).parent();
    else
        element = $( event.target );

    $( "#purge_alias_name" ).text( element.attr( 'ref' ) );

    delDialog = $( '#purge_dialog' ).modal({
        backdrop: true,
        keyboard: true,
        show: true
    });

    // The control is a submit button inside a CSRF-bearing POST form; the
    // dialog's confirm button submits that form so the token stays in the body.
    var targetForm = element.closest( 'form' );
    $( '#purge_dialog_delete' ).off( 'click' ).on( 'click', function( ev ){
        ev.preventDefault();
        targetForm.get( 0 ).submit();
    });

    $( '#purge_dialog_cancel' ).click( function(){
        delDialog.modal('hide');
    });
};
