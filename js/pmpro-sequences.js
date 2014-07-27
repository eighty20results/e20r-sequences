// console.log('Hide the Save button');
// jQuery('#save_pmpro-seq-useroptin').hide();

// Save the value of the User sequence notification optin for the current user
var userNotice = jQuery('#hidden_pmpro_seq_useroptin').val();

// jQuery('#pmpro_sequence_useroptin').click(function() {

/* Show/Hide save button & store state of current user opt-in setting */
function pmpro_sequence_optinSelect( sequence_id, user_id ) {

    // console.log('Checkbox to opt in for new content notices (by user) clicked');

    jQuery('#hidden_pmpro_seq_useroptin').val( jQuery('#pmpro_sequence_useroptin').is(':checked') ? 1 : 0 );

    /*
    console.log('User modified their opt-in. Saving... Was: ' + userNotice + ' now: ' + ( jQuery('#pmpro_sequence_useroptin').is(':checked') ? 1 : 0)
    + ' this: ' + jQuery('#pmpro_sequence_useroptin').is(':checked') );
    */
    // Enable the spinner during the save operation
    jQuery('div .seq_spinner').show();

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

            var doCheck = jQuery('#hidden_pmpro_seq_useroptin').val() == 1 ? true : false;
            jQuery('#pmpro_sequence_useroptin').prop('checked', doCheck);

            jQuery('div .seq_spinner').hide();
        }

    });
}

// });
