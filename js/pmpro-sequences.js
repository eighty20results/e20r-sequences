/*
 License:

 Copyright 2014 Thomas Sjolshagen (thomas@eighty20results.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License, version 2, as
 published by the Free Software Foundation.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

 */

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

    var hiddenOptin = jQuery('#hidden_pmpro_seq_useroptin');

    /* Show/Hide save button & store state of current user opt-in setting */
    hiddenOptin.val( jQuery('#pmpro_sequence_useroptin').is(':checked') ? 1 : 0 );

    // Enable the spinner during the save operation
    jQuery('div .seq_spinner').show();

    // Send POST to back-end server with new opt-in value
    jQuery.ajax({
        url: pmpro_sequence.ajaxurl,
        type: 'POST',
        timeout: 5000,
        dataType: 'JSON',
        data: {
            action: 'pmpro_sequence_save_user_optin',
            hidden_pmpro_seq_id: jQuery('#hidden_pmpro_seq_id').val(),
            hidden_pmpro_seq_useroptin: hiddenOptin.val(),
            hidden_pmpro_seq_uid: jQuery('#hidden_pmpro_seq_uid').val(),
            pmpro_sequence_optin_nonce: jQuery('#pmpro_sequence_optin_nonce').val()
        },
        error: function(data)
        {
            alert(data.data);

        },
        complete: function() {

            var doCheck = (hiddenOptin.val() == 1 ? true : false);
            jQuery('#pmpro_sequence_useroptin').prop('checked', doCheck);

            jQuery('div .seq_spinner').hide();
        }

    });
}

