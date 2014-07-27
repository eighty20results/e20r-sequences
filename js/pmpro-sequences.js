// Save the value of the User sequence notification optin for the current user
// var userNotice = jQuery('#hidden_pmpro_seq_useroptin').val();

/**
 *
 * Update the "new content alert" for the logged in user.
 *
 * @param sequence_id -- ID of the sequence we're changing the user's opt-in setting for
 * @param user_id -- ID of user
 */
function pmpro_sequence_optinSelect( sequence_id, user_id ) {

    /* Show/Hide save button & store state of current user opt-in setting */
    jQuery('#hidden_pmpro_seq_useroptin').val( jQuery('#pmpro_sequence_useroptin').is(':checked') ? 1 : 0 );

    /*
    console.log('User modified their opt-in. Saving... Was: ' + userNotice + ' now: ' + ( jQuery('#pmpro_sequence_useroptin').is(':checked') ? 1 : 0)
    + ' this: ' + jQuery('#pmpro_sequence_useroptin').is(':checked') );
    */

    // Enable the spinner during the save operation
    jQuery('div .seq_spinner').show();

    // Send POST to back-end server with new opt-in value
    jQuery.ajax({
        url: pmpro_sequence.ajaxurl,
        type: 'POST',
        timeout: 5000,
        dataType: 'html',
        data: {
            action: 'pmpro_sequence_save_user_optin',
            hidden_pmpro_seq_id: jQuery('#hidden_pmpro_seq_id').val(),
            hidden_pmpro_seq_useroptin: jQuery('#hidden_pmpro_seq_useroptin').val(),
            hidden_pmpro_seq_uid: jQuery('#hidden_pmpro_seq_uid').val(),
            pmpro_sequence_optin_nonce: jQuery('#pmpro_sequence_optin_nonce').val()
        },
        error: function(responseHTML)
        {
            if ( responseHTML.match("^Error") )
                alert(responseHTML);

        },
        success: function(responseHTML) {
            if ( responseHTML.match("^Error") )
                alert(responseHTML);
        },
        complete: function() {

            var doCheck = (jQuery('#hidden_pmpro_seq_useroptin').val() == 1 ? true : false);
            jQuery('#pmpro_sequence_useroptin').prop('checked', doCheck);

            jQuery('div .seq_spinner').hide();
        }

    });
}

