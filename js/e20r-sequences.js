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

// Set no-conflict mode for JQuery.
jQuery.noConflict();

/**
 *
 * Update the "new content alert" for the logged in user.
 *
 * @param sequence_id -- ID of the sequence we're changing the user's opt-in setting for
 * @param user_id -- ID of user
 */
function e20r_sequence_optinSelect( sequence_id, user_id ) {

    var hiddenOptin = jQuery('#hidden_e20r_seq_useroptin');
    var userOptin = jQuery('#e20r_sequence_useroptin');

    /* Show/Hide save button & store state of current user opt-in setting */
    hiddenOptin.val( userOptin.is(':checked') ? 1 : 0 );

    // Enable the spinner during the save operation
    jQuery('div .seq_spinner').show();

    // Send POST to back-end server with new opt-in value
    jQuery.ajax({
        url: e20r_sequence.ajaxurl,
        type: 'POST',
        timeout: 5000,
        dataType: 'JSON',
        data: {
            action: 'e20r_sequence_save_user_optin',
            hidden_e20r_seq_id: jQuery('#hidden_e20r_seq_id').val(),
            hidden_e20r_seq_useroptin: hiddenOptin.val(),
            hidden_e20r_seq_uid: jQuery('#hidden_e20r_seq_uid').val(),
            e20r_sequence_optin_nonce: jQuery('#e20r_sequence_optin_nonce').val()
        },
        error: function(data)
        {
            alert(data.data);

        },
        complete: function() {

            var doCheck = (hiddenOptin.val() == 1 ? true : false);
            userOptin.prop('checked', doCheck);

            jQuery('div .seq_spinner').hide();
        }

    });
}

