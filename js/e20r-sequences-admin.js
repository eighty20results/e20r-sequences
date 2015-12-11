/**
 * Created by Thomas Sjolshagen of Eighty / 20 Results (c) 2014

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

jQuery.noConflict();

var sequenceSettings = {
    init: function() {

        var $class = this;

        this.email_settings = jQuery('.pmpro-sequence-email');
        this.sendAlertCtl   = jQuery('#pmpro_sequence_sendnotice');
        this.checkboxes     = jQuery('.pmpro-sequence-settings-display .pmpro-sequence-setting-col-1 > input[type="checkbox"]');
        this.saveBtn        = jQuery('a[class^="save-pmproseq"]');
        this.cancelBtn      = jQuery('a[class^="cancel-pmproseq"]');
        this.editBtn        = jQuery('a.pmpro-seq-edit');

        this.selects        = jQuery('div.pmpro-sequence-settings-input select');
        this.sortOrderCtl   = jQuery('#pmpro_sequence_sortorder');
        this.delayCtl       = jQuery('#pmpro_sequence_delaytype');
        this.showDelayCtl   = jQuery('#pmpro_sequence_showdelayas');
        this.templCtl       = jQuery('#pmpro_sequence_template');
        this.timeCtl        = jQuery('#pmpro_sequence_noticetime');
        this.dateCtl        = jQuery('#pmpro_sequence_dateformat');
        this.offsetCtl      = jQuery('#pmpro_sequence_offset');
        this.repeatCtl      = jQuery('#pmpro_sequence_allowRepeatPosts');
        this.sndAsCtl       = jQuery('#pmpro_sequence_sendas');

        /* Input */
        this.excerptCtl     = jQuery('#pmpro_sequence_excerpt');
        this.subjCtl        = jQuery('#pmpro_sequence_subject');
        this.fromCtl        = jQuery('#pmpro_sequence_fromname');
        this.replyCtl       = jQuery('#pmpro_sequence_replyto');

        this.offsetChkCtl   = jQuery('#pmpro_sequence_offsetchk');

        this.spinner = jQuery('div .seq_spinner');

        this.hide_rows();
        this.bind_buttons();
    },
    bind_buttons: function() {

        var $class = this;

        // Process 'edit' button clicks in settings.
        $class.editBtn.each( function() {

            jQuery(this).unbind().on('click', function() {
                jQuery(this).closest('.pmpro-sequence-settings-display').next('.pmpro-sequence-settings-input').slideToggle();
                jQuery(this).slideToggle();
            });

        });

        // Process manual 'Send now' request for alerts/notifications
        jQuery("#pmpro_seq_send").unbind().on('click', function() {
            console.log("Sending email alerts manually");
            $class.send_alert();
        });

        // Process checkboxes for the Sequence settings
        $class.checkboxes.each(function() {

            $checkbox = jQuery(this);

            if ( 'pmpro_sequence_offsetchk' == $checkbox.attr('id') && ( $checkbox.is(':checked'))) {

                var $status = $checkbox.closest('.pmpro-sequence-settings-display').next('.pmpro-sequence-offset');
                console.log("The checkbox for the preview functionality is set, show its status", $status);
                $status.show();
            }

            $checkbox.unbind().on('click', function() {

                $class.checked_box( this );
            });
        });

        $class.saveBtn.each(function() {

            console.log("Found save button for setting");

            var $btn = jQuery(this);
            $btn.unbind().on('click', function(){

                var isSelect = $btn.closest('.pmpro-sequence-settings-input').find('select');
                var isInput = $btn.closest('.pmpro-sequence-settings-input').find('input[type="text"]');

                console.log("Contains select element?", isSelect );

                if ( isSelect.length ) {

                    console.log("Saving value(s) for select element...");
                    $class.save_select( isSelect );
                    return;
                }

                if ( isInput.length ) {

                    console.log("Saving value(s) for input element...");
                    $class.save_input( isInput );
                    return;
                }

                $class.hide_after_ok(jQuery(this));
                console.log("Clicked the OK button");
            });
        });

        $class.cancelBtn.each(function() {

            jQuery(this).unbind().on('click', function(){

                jQuery(this).closest('.pmpro-sequence-settings-input').slideToggle();
                jQuery(this).closest('.pmpro-sequence-settings-input').prev('.pmpro-sequence-settings-display').find('.pmpro-seq-edit').slideToggle();
                console.log("Clicked the cancel button");
            });

        });
    },
    _show_preview: function() {
        jQuery('#pmpro_sequence_offset');
    },
    checked_box: function( $checkbox ) {

        console.log("Updating checkbox for ", $checkbox );

        var $class = this;

        if ( !( $checkbox instanceof jQuery ) ) {
            $checkbox = jQuery($checkbox);
        }

        if ( $checkbox.is(':checked') &&
            $checkbox.closest('.pmpro-sequence-settings-display').next('.pmpro-sequence-settings-display').hasClass('pmpro-sequence-offset') ) {

            console.log("Need to manage visibility for offset setting");
            $checkbox.closest('.pmpro-sequence-settings-display').next('.pmpro-sequence-offset').show();
        }
        else if ( $checkbox.not(':checked') &&
            $checkbox.closest('.pmpro-sequence-settings-display').next('.pmpro-sequence-settings-display').hasClass('pmpro-sequence-offset') ) {

            var $inputs = $checkbox.closest('.pmpro-sequence-settings-display').next('.pmpro-sequence-offset').next('.pmpro-sequence-offset');
            var $status = $checkbox.closest('.pmpro-sequence-settings-display').next('.pmpro-sequence-offset');

            $inputs.find('input[type="hidden"]').val(0);
            $inputs.find('#pmpro_sequence_offset').val(0);

            var $text = '<span class="pmpro-sequence-status">' + $inputs.find('#pmpro_sequence_offset option:selected').text() + '</span>';
            $status.find('.pmpro-sequence-setting-col-2').html( $text );

            $checkbox.closest('.pmpro-sequence-settings-display').next('.pmpro-sequence-offset').hide();
            // jQuery('.pmpro-sequence-offset').hide();
        }

        if ( 'pmpro_sequence_sendnotice' == $checkbox.attr('id')  ) {

            console.log('Show all alert related variables');
            if ( $checkbox.is(':checked') ) {

                $class.email_settings.each(function () {
                    jQuery(this).show();
                    $class.hide_rows();
                });
            }
            else {

                $class.email_settings.each( function() {
                    jQuery(this).hide();
                });

            };
        }

        // if ( $checkbox.is(':checked') ) {
            console.log("Setting checkbox hidden value for: ", $checkbox.attr('id'));
            $checkbox.closest('.pmpro-sequence-setting-col-1').find('input[type=hidden]').val( ( $checkbox.is(':checked') ? 1 : 0 ));
        // }

        /* if ( $checkbox.not(':checked') &&
            console.log("Setting checkbox hidden value for: ", $checkbox.attr('id'));
            $checkbox.closest('.pmpro-sequence-setting-col-1').find('#hidden_pmpro_seq_allowRepeatPosts').val(1);
        }*/

    },
    save_input: function( input ) {

        input = jQuery(input);

        var container = input.closest('.pmpro-sequence-settings-input');
        var hidden_input = container.find('input[type="hidden"]');
        var status = container.prev('.pmpro-sequence-settings-display').find('.pmpro-sequence-setting-col-2')
        var editBtn = container.prev('.pmpro-sequence-settings-display').find('.pmpro-seq-edit');

        /* Check whether the setting has changed */
        var $val;

        // Only update if the new value is different from the current (may not yet be saved) setting.
        if ( ( $val = input.val() ) != hidden_input.val() ) {

            hidden_input.val($val);
            status.html( '<span class="pmpro-sequence-status">' + $val + '</span>');
        }

        input.css('margin-top', '10px');

        container.hide(); // Hide the Input field + OK & Cancel buttons
        editBtn.show(); // Show edit button again

    },
    save_select: function( select ) {

        var $class = this;
        select = jQuery(select);

        if ( 'pmpro_sequence_delaytype' ==  select.attr('id')) {

            $class.change_delay_type();
        }

        var container = select.closest('.pmpro-sequence-settings-input');
        var hidden_input = container.find('input[type="hidden"]');
        var status = container.prev('.pmpro-sequence-settings-display').find('.pmpro-sequence-setting-col-2')
        var editBtn = container.prev('.pmpro-sequence-settings-display').find('.pmpro-seq-edit');

        /* Check whether the setting has changed */
        var $val;

        if ( ($val = select.find('option:selected').val()) != hidden_input.val() ) {

            /* Save the new text (for label) */
            var $text = '<span class="pmpro-sequence-status">' + select.find('option:selected').text() +"</span>";

            status.html($text); // Displayed setting value in label
            hidden_input.val($val); // Set the value='' for the hidden input field
        }

        select.css('margin-top', '10px');

        container.hide(); // Hide the Input field + OK & Cancel buttons
        editBtn.show(); // Show edit button again
    },
    change_delay_type: function() {

        var dtCtl = jQuery('#pmpro_sequence_delaytype');

        var selected = dtCtl.val();
        var current = jQuery('input[name=pmpro_sequence_settings_hidden_delay]').val();

        if (dtCtl.val() != jQuery('#pmpro_sequence_settings_hidden_delay').val()) {

            if (!confirm(pmpro_sequence.lang.delay_change_confirmation)) {

                dtCtl.val(current);
                jQuery('#hidden_pmpro_seq_wipesequence').val(0);

                return false;
            } else
                jQuery('#hidden_pmpro_seq_wipesequence').val(1);

        }
    },
    delay_as_choice: function( $visibility ) {

        if ($visibility == 'hide') {
            console.log('Hide the showDelayAs options');
            jQuery('.pmpro-seq-showdelayas').hide(); // hide
            jQuery('#pmpro-seq-showdelayas-select').hide(); // hide
            jQuery('.pmpro-seq-delay-btns').hide(); // hide
        }
        else {
            jQuery('.pmpro-seq-showdelayas').show(); // show
            jQuery('.pmpro-seq-delay-btns').show(); // show
            jQuery('#pmpro-seq-showdelayas-select').show(); // hide
        }
    },
    set_error_message: function( $message ) {

        var errCtl = jQuery('#pmpro-seq-error');

        errCtl.text($message);
        errCtl.show();

        var timeout = window.setTimeout(function() {
            console.log('Hiding the error status again');
            errCtl.hide();
        }, 15000);

        console.log('Message: ' + $message);
    },
    manageDelayLabels: function( $currentDelayType ) {

        console.log('In manageDelayLabels()');

        var delayDlg = jQuery('#pmpro-seq-showdelayas');
        var delayEdit = jQuery('#pmpro-seq-edit-showdelayas');

        if ($currentDelayType == 'byDays') {
            delayDlg.show(); // Show
            delayDlg.css('margin-top', '0px');
            delayEdit.show(); // Show
        }
        else {
            delayDlg.hide(); // Hide
            delayEdit.hide(); // Hide
        }
    },
    hide_after_ok: function( $okBtn ) {

        var $settings = $okBtn.closest('.pmpro-sequence-settings-input');
        $settings.slideToggle();
        $settings.prev('.pmpro-sequence-settings-display').find('.pmpro-seq-edit').slideToggle();
    },
    hide_rows: function() {

        jQuery('.pmpro-sequence-hidden').each(function() {
            jQuery(this).hide();
        });
    },
    close_setting: function( $me ) {

        var $btnDiv = $me.closest('div.pmpro-sequence-full-row');
    },
    send_alert: function() {

        var $sequence = jQuery('#post_ID').val();
        var $class = this;

        console.log("send_alert: ", $sequence );

        if ( $sequence === 'undefined' ) {
            alert(pmpro_sequence.lang.alert_not_saved);
        }
        else {

            jQuery.ajax({
                url: pmpro_sequence.ajaxurl,
                type: 'POST',
                timeout: 5000,
                dataType: 'JSON',
                data: {
                    action: 'pmpro_send_notices',
                    pmpro_sequence_sendalert_nonce: jQuery('#pmpro_sequence_sendalert_nonce').val(),
                    pmpro_sequence_id: $sequence
                },
                error: function (data) {
                    if (data.message != null) {
                        alert(data.message);
                        $class.set_error_message( data.message );
                    }
                }
            });
        }
    }
};

var postMeta = {
    init: function() {

        this.sequence_list = jQuery( 'select.pmpro_seq-memberof-sequences');
        this.spinner = jQuery('div.seq_spinner');
        this.delay_type     = jQuery('#pmpro-seq-hidden-delay-type').val();

        var $class = this;

        $class.bind_controls();
    },
    bind_controls: function() {

        var $class = this;

        $class.sequence_list = jQuery( 'select.pmpro_seq-memberof-sequences');

        jQuery('select.new-sequence-select, .pmpro_seq-memberof-sequences').each(function() {
            jQuery(this).unbind('change').on( 'change', function () {

                console.log("User changed the content of the select box.")
                $class.meta_select_changed( this );
            });
        });

        jQuery("#pmpro-seq-new-meta").unbind('click').on( "click", function() {

            $class.manage_meta_rows();
            console.log("Add new table row for metabox");
            $class.spinner.show();

            // $class.row_visibility( jQuery( '.new-sequence-select' ), 'select' );
            $class.add_sequence_post_row();

            $class.spinner.hide();
            $class.manage_meta_rows();
        });

        jQuery('.delay-row-input input:checkbox').unbind('click').on( 'click', function() {

            console.log("The 'remove' checkbox was clicked...");
            var $checkbox = this;

            if ( !( $checkbox instanceof jQuery ) ) {
                $checkbox = jQuery(this);
            }

            var delay_input = $checkbox.closest('td').find('.pmpro-seq-delay-info').val();

            if ( ( '' == delay_input ) ) {

                console.log("Value is empty so just clearing (removing) the HTML from the page");

                var s_label = $checkbox.closest("tr.delay-row-input.sequence-delay").prev().prev().prev();
                var s_input = $checkbox.closest("tr.delay-row-input.sequence-delay").prev().prev();
                var d_label = $checkbox.closest("tr.delay-row-input.sequence-delay").prev();
                var d_input = $checkbox.closest("tr.delay-row-input.sequence-delay");

                s_label.remove();
                s_input.remove();
                d_label.remove();
                d_input.remove();
            }
            else {

                $class.remove_sequence(this);
            }
        });

        jQuery('.delay-row-input.sequence-delay button.pmpro-sequence-remove-alert').unbind('click').on('click', function() {
            console.log("The 'clear alerts' button was clicked");

            var button = this;

            if ( !( button instanceof jQuery ) ) {
                button = jQuery( button );
            }

            var delay_input = button.closest('td').find('.pmpro-seq-delay-info').val();
            var sequence_id = button.closest('td').find('input.pmpro_seq-remove-seq').val();
            var post_id = jQuery('#post_ID').val();

            if ( !post_id ) {

                alert("Warning: Post has not been saved yet, so there are no alerts to clear");
                return;
            }

            $class.clear_post_notice_alerts( sequence_id, post_id, delay_input );
        });

        $class.show_controls();
    },
    clear_post_notice_alerts: function( sequence_id, post_id, delay ) {

        event.preventDefault();

        var data = {
            'action': 'e20r_remove_alert',
            'pmpro_sequence_postmeta_nonce': jQuery('#pmpro_sequence_postmeta_nonce').val(),
            'pmpro_sequence_id': sequence_id,
            'pmpro_sequence_post': post_id,
            'pmpro_sequence_post_delay': delay
        };

        jQuery.ajax({
            url: pmpro_sequence.ajaxurl,
            type: 'POST',
            timeout: 5000,
            dataType: 'JSON',
            data: data,
            error: function( $response ) {

                alert("Unable to clear settings for the following user IDs:\n\n" + $response.data );
                return false;
            },
            success: function( $response ) {
                alert("Alert notification setting cleared for sequence number " + sequence_id );
                return;
            }
        });
    },
    remove_entry: function( post_id, delay ) {

        var $class = this;

        jQuery('#pmpro_sequencesave').attr('disabled', 'disabled');
        // jQuery('#pmpro_sequencesave').html(pmpro_sequence.lang.saving);

        jQuery.ajax({
            url: pmpro_sequence.ajaxurl,
            type:'POST',
            timeout:5000,
            dataType: 'JSON',
            data: {
                action: 'pmpro_sequence_rm_post',
                pmpro_sequence_id: jQuery('#pmpro_sequence_id').val(),
                pmpro_seq_post: post_id,
                pmpro_seq_delay: delay,
                pmpro_sequence_rmpost_nonce: jQuery('#pmpro_sequence_postmeta_nonce').val()
            },
            error: function($data){

                console.dir($data);

                if ($data.data != '') {

                    alert($data.data);
                    $class.set_error_message( $data.data );
                }
            },
            success: function($data){

                console.dir($data);

                if ($data.data) {
                    jQuery('#pmpro_sequence_posts').html( $data.data );
                }

            },
            complete: function() {
                // Enable the Save button again.
                jQuery('#pmpro_sequencesave').removeAttr('disabled');
                $class.bind_controls();
            }
        });
    },
    edit_entry: function(post_id, delay) {

        jQuery('#newmeta').focus();
        jQuery('#pmpro_sequencepost').val(post_id).trigger("change");
        jQuery('#pmpro_sequencedelay').val(delay);
        jQuery('#pmpro_sequencesave').html(pmpro_sequence.lang.save);
    },
    edit_post: function( post_id ) {

        var win = window.open('/wp-admin/post.php?post=' + post_id + '&action=edit', '_blank');
        if (win)
            win.focus();
        else
            alert('Your browser settings prevents this action. You need to allow pop-ups');
    },
    add_entry: function() {

        var saveBtn = jQuery('#pmpro_sequencesave');
        var $class = this;

        if ('' == jQuery('#pmpro_sequence_post').val() || undefined != saveBtn.attr('disabled')) {

            return false; //already processing, ignore this request
        }

        // Disable save button
        saveBtn.attr('disabled', 'disabled');
        saveBtn.html(pmpro_sequence.lang.saving);

        //pass field values to AJAX service and refresh table above - Timeout is 5 seconds
        jQuery.ajax({
            url: pmpro_sequence.ajaxurl,
            type:'POST',
            timeout:5000,
            dataType: 'JSON',
            data: {
                action: 'pmpro_sequence_add_post',
                pmpro_sequence_id: jQuery('#pmpro_sequence_id').val(),
                pmpro_sequence_post: jQuery('#pmpro_sequencepost').val(),
                pmpro_sequence_delay: jQuery('#pmpro_sequencedelay').val(),
                pmpro_sequence_addpost_nonce: jQuery('#pmpro_sequence_addpost_nonce').val()
            },
            error: function( $response, $errString, $errType ) {
                console.log("error() - Returned data: " + $response + " and error:" + $errString + " and type: " + $errType );
                console.dir( $response );

                if ( '' != $response.data ) {

                    // alert($data.data);
                    $class.set_error_message( $response.data );
                }
            },
            success: function($returned, $success ){

                console.log("success() - Returned data: ", $returned.data );
                console.log("success() - Returned status: ", $returned.success );

                if ( ( $returned.success === true ) ) {

                    console.log('Entry added to sequence & refreshing metabox content');
                    jQuery('#pmpro_sequence_posts').html($returned.data);
                } else {

                    if ( typeof $returned.data  === 'string' ) {

                        console.log("Received a string as the error status");
                        $class.set_error_message( $returned.data );
                    }
                    else {

                        console.log("Received an object as the error status");
                        $class.set_error_message( $returned.data[0].message );
                    }

                }

            },
            complete: function($data) {

                // Re-enable save button
                saveBtn.html(pmpro_sequence.lang.save);
                saveBtn.removeAttr('disabled');
                $class.bind_controls();

            }
        });
    },
    remove_sequence: function( $me ) {

        var $class = this;

        $class.manage_meta_rows();

        $class.spinner.show();

        console.log("Removing sequence info: ", $me );

        if ( !( $me instanceof jQuery ) ){

            $me = jQuery($me);
        }

        var delay_ctrl = $me.closest('td').find('input.pmpro-seq-delay-info.pmpro-seq-days');
        console.log("Delay value: ", delay_ctrl.val() );

        jQuery.ajax({
            url: pmpro_sequence.ajaxurl,
            type: 'POST',
            timeout: 10000,
            dataType: 'JSON',
            data: {
                action: 'pmpro_rm_sequence_from_post',
                pmpro_sequence_id: $me.val(),
                pmpro_seq_delay: delay_ctrl.val(),
                pmpro_seq_post_id: jQuery('#post_ID').val(),
                pmpro_sequence_postmeta_nonce: jQuery('#pmpro_sequence_postmeta_nonce').val()
            },
            error: function ($data) {

                console.dir($data);

                if ($data.data != '') {
                    alert($data.data);
                }

            },
            success: function ($data) {

                console.dir($data);

                if ($data.data) {
                    jQuery('#pmpro_seq-configure-sequence').html($data.data);
                }

            },
            complete: function () {

                $class.show_controls();
                $class.manage_meta_rows();
                $class.bind_controls();
                $class.spinner.hide();
            }
        });

    },
    show_controls: function() {

        var $count = 0;
        var $class = this;

        $class.sequence_list.each( function() {

            $class.row_visibility( this, 'all' );
            $count++;
        });

        console.log('Number of selects with defined sequences: ' + $count);

        // Check if there's more than one select box in metabox. If so, the post already belongs to sequences
        if ( $count >= 1 ) {

            // Hide the 'new sequence' select and show the 'new' button.
            $class.row_visibility( jQuery( 'select.new-sequence-select') , 'none' );

            jQuery('#pmpro-seq-new').show();
            jQuery('#pmpro-seq-new-meta').show();
            jQuery('#pmpro-seq-new-meta-reset').hide();
        }
        else {

            // Show the row for the 'Not defined' in the New sequence drop-down
            $class.row_visibility( jQuery( 'select.new-sequence-select' ), 'select' );

            // Hide all buttons
            jQuery('#pmpro-seq-new').hide();
        }
    },
    manage_meta_rows: function() {

        jQuery( '.pmpro_seq-memberof-sequences, .new-sequence-select, .pmpro-seq-delay-info, .pmpro_seq-remove-seq' ).each( function() {

            if (! jQuery( this ).is( ':disabled') ) {

                jQuery(this).attr('disabled', false);
                console.log("Enable row");
            }
            else {
                jQuery(this).attr('disabled', true);
                console.log("Disable row");;
            }
        });

        jQuery( '#pmpro-seq-new-meta' ).attr( 'disabled', true );
        jQuery( '#pmpro-seq-new-meta-reset' ).attr( 'disabled', true );
    },
    row_visibility: function( $element, $show ) {

        var $selectLabelRow = jQuery($element).parent().parent().prev();
        var $selectRow = jQuery($element).parent().parent();

        var $delayLabelRow = jQuery($element).parent().parent().next();
        var $delayRow = jQuery($delayLabelRow).next();

        if ( $show == 'all') {

            $selectLabelRow.show();
            $selectRow.show();
            $delayLabelRow.show();
            $delayRow.show();
        }
        else if (  $show == 'none') {

            $selectLabelRow.hide();
            $selectRow.hide();
            $delayLabelRow.hide();
            $delayRow.hide();
        }
        else if ( $show == 'select' ) {

            $selectLabelRow.show();
            $selectRow.show();
            $delayLabelRow.hide();
            $delayRow.hide();
        }
    },
    _delay_label: function( sequence_id ) {

        // console.log("Delay settings: ", pmpro_sequence.delay_config);
        var $html;
        var $label;

        if ( 'byDate' == pmpro_sequence.delay_config[ sequence_id ]  ) {
            $label = "Delay (Format: Date)";

        }

        if ( 'byDays' ==  pmpro_sequence.delay_config[ sequence_id ] ) {
            $label = "Delay (Format: Day count)";
        }

        $html = '<label for="pmpro_seq-delay_' + sequence_id + '">' + $label + '</label>';
        return $html;
    },
    _delay_input: function( sequence_id ) {

        console.log("Delay settings: ", pmpro_sequence.delay_config);
        var $html;

        if ( 'byDate' == pmpro_sequence.delay_config[ sequence_id ]  ) {

            var today = new Date();
            var dd = today.getDate();
            var mm = today.getMonth()+1; //January is 0!
            var yyyy = today.getFullYear();

            if ( dd < 10 ) {
                dd = '0' + dd;
            }

            if ( mm < 10 ) {
                mm = '0' + mm;
            }

            var starts = yyyy + '-' + mm - '-' + dd;

            $html = "<input class='pmpro-seq-delay-info pmpro-seq-date' type='date' value='' name='pmpro_seq-delay[]' min='" + starts + "'>";
        }

        if ( 'byDays' ==  pmpro_sequence.delay_config[ sequence_id ] ) {
            $html = "<input class='pmpro-seq-delay-info pmpro-seq-days' type='text' name='pmpro_seq-delay[]' value=''>";
        }

        return $html;
    },
    set_error_message: function( $message ) {

        console.log("Setting error message: " + $message );

        var errCtl = jQuery('#pmpro-seq-error');

        errCtl.text($message);
        errCtl.show();

        var timeout = window.setTimeout(function() {
            console.log('Hiding the error status again');
            errCtl.hide();
        }, 15000);

        console.log('Message: ' + $message);
    },
    add_sequence_post_row: function( ) {

        var $class = this;

        var table = jQuery("#pmpro-seq-metatable").find('tbody');

        var select_label_row = table.find('tr.select-row-label.sequence-select-label:first').clone();
        var select_row = table.find('tr.select-row-input.sequence-select:first').clone();
        var delay_label_row = table.find('tr.delay-row-label.sequence-delay-label:first').clone();
        var delay_row = table.find('tr.delay-row-input.sequence-delay:first').clone();

        console.log("Unselect the new sequence ID and clear any delay value(s)");
        select_row.find('.pmpro_seq-memberof-sequences').val('');
        delay_row.find('.pmpro-seq-delay-info.pmpro-seq-days').val('');

        table.append( select_label_row );
        table.append( select_row );
        table.append( delay_label_row );
        table.append( delay_row );

        $class.bind_controls();

        console.log("Added 4 rows for the new sequence.");
    },
    /* _duplicate_meta_entry: function( sequence_id, delay_val, entry ) {

        var $class = this;
        console.log("Using sequence ID: " + sequence_id + ' at element: ', entry );

        jQuery("tr.select-row-input.sequence-select select.pmpro_seq-memberof-sequences").each( function() {

            var sequence = jQuery( this );

            if ( sequence_id === sequence.val() ) {
                select_label = sequence.closest('tr .select-row-label.sequence-select-label');
                return false;
            }
        });

        console.log("Select label entry for the specific sequence ID: " + sequence_id, select_label );

        if ( ( null !== select_label ) || ( false !== select_label ) ) {
            var select = select_label.next();
            var delay_label = select.next();
            var delay = delay_label.next();

            console.log("Delay value is now: ", delay.find('input.pmpro-seq-delay-info.pmpro-seq-days').val() );
            delay.find('input.pmpro-seq-delay-info.pmpro-seq-days').val('');
            console.log("After (supposed) clear... Delay value is now: ", delay.find('input.pmpro-seq-delay-info.pmpro-seq-days').val() );

            console.log("Find the last delay value input in the list: ", jQuery("tr.delay-row-input.sequence-delay:last") );

            jQuery("tr.delay-row-input.sequence-delay:last").appendTo(select_label);
            console.log("Attempted to add select label: ", select_label );
            jQuery("tr.select-row-label.sequence-select-label:last").appendTo(select);
            jQuery("tr.select-row-input.sequence-select:last").appendTo(delay_label);
            jQuery("tr.delay-row-label.sequence-delay-label:last").appendTo(delay);

            console.log("Added rows");
        }
    }, */
    meta_select_changed: function( $self ) {

        var $class = this;
        $class.manage_meta_rows();

        console.log("Changed the Sequence this post is a member of");
        $class.spinner.show();

        console.log("Self is: ", $self );

        if ( !( $self instanceof jQuery ) ) {

            $self = jQuery( $self );
        }

        var managed_sequences = {};

        $self.closest('#pmpro-seq-metatable').find('tr.select-row-input').find('select.pmpro_seq-memberof-sequences option:selected').each(function() {

            var input = jQuery(this);

            if ( '' != input.val() ) {
                var key = input.val();
                console.log("Is_managed: Sequence ID: ", key );
                managed_sequences[ key ] = input.closest('tr');
            }
        });

        var sequence_id = $self.val();
        var delay_value =  $self.closest('tr.select-row-input.new-sequence-select').next().next().find('input.pmpro-seq-delay-info').val();

        if ( ( ( managed_sequences.hasOwnProperty( sequence_id ) ) && ( managed_sequences[ sequence_id ] instanceof jQuery ) ) && ('' == delay_value ) ) {

            console.log("No delay value specified but the current sequence_id is already being managed");
            console.log("Duplicate all 4 rows and insert after the last (with empty delay value)");

            // $self.closest('tr.select-row-input.sequence-select').
            $class.add_sequence_post_row();

            return;
        }

        if ( ( '' == sequence_id ) ) {

            console.log("sequence_id is empty for: ", $self );
            return;
            console.log("Should have exited...")
        }

        console.log("Sequence ID: " + sequence_id );

        var $input = $class._delay_input( sequence_id );
        var $label = $class._delay_label( sequence_id );

        console.log("Setting label to: ", $label);
        console.log("Setting input to: ", $input);

        // Disable delay and sequence input.
        var delay_label_row = $self.closest('tr.select-row-input.sequence-select').next();
        var delay_row = $self.closest('tr.select-row-input.sequence-select').next().next();

        delay_label_row.find('label[for^="pmpro_seq-delay"]').replaceWith($label);
        delay_row.find('input.pmpro-seq-delay-info.pmpro-seq-days').replaceWith($input);

        // $class.show_controls();
        $class.manage_meta_rows();
        $class.bind_controls();
        jQuery( '#pmpro-seq-new-meta' ).attr( 'disabled', false );
        jQuery( '#pmpro-seq-new-meta-reset' ).attr( 'disabled', false );

        $class.spinner.hide();

        /*
                jQuery.ajax({
                    url: pmpro_sequence.ajaxurl,
                    type:'POST',
                    timeout:10000,
                    dataType: 'JSON',
                    data: {
                        action: 'pmpro_sequence_update_post_meta',
                        pmpro_sequence_id: sequence_id,
                        pmpro_sequence_postmeta_nonce: jQuery('#pmpro_sequence_postmeta_nonce').val(),
                        pmpro_sequence_post_id: jQuery('#post_ID').val()
                    },
                    error: function($data){
                        console.log("error() - Returned data: " + $data.success + " and " + $data.data);
                        console.dir($data);

                        if ( $data.data ) {
                            alert($data.data);
                        }
                    },
                    success: function($data){
                        console.log("success() - Returned data: " + $data.success);

                        if ($data.data) {

                            console.log('Entry added to sequence & refreshing metabox content');
                            jQuery('#pmpro_seq-configure-sequence').html($data.data);
                            console.log("Loaded sequence meta info.");
                        } else {
                            console.log('No HTML returned???');
                        }

                    },
                    complete: function($data) {

                        $class.spinner.hide();
                        console.log("Ajax function complete...");
                        $class.show_controls();
                        $class.manage_meta_rows();
                        $class.bind_controls();
                        jQuery( '#pmpro-seq-new').hide();
                    }
                });
                */
    },
    _set_labels: function() {

        var delayType = jQuery('#pmpro_sequence_delaytype').val();
        var headerHTML_start = '<th id="pmpro_sequence_delaytype">';
        var headerHTML_end = '</th>';
        var entryHTML_start = '<th id="pmpro_sequence_delayentrytype">';
        var entryHTML_end = '</th>';


        var labelText = pmpro_sequence.lang.undefined; // 'Not Defined';
        var entryText = pmpro_sequence.lang.undefined;

        if (delayType == 'byDays')
        {
            labelText = pmpro_sequence.lang.daysLabel; // "Delay";
            entryText = pmpro_sequence.lang.daysText; //"Days to delay";
        }

        if (delayType == 'byDate')
        {
            labelText = pmpro_sequence.lang.dateLabel; // "Avail. on";
            entryText = pmpro_sequence.lang.dateText; // "Release on (YYYY-MM-DD)";
        }

        jQuery('#pmpro_sequence_delaylabel').html( headerHTML_start + labelText + headerHTML_end);
        jQuery('#pmpro_sequence_delayentrylabel').html( entryHTML_start + entryText + entryHTML_end);
    }
};

function pmpro_sequence_addEntry() {

    postMeta.add_entry();
}

function pmpro_sequence_editPost(post_id) {

    postMeta.edit_post( post_id );
}

function pmpro_sequence_editEntry(post_id, delay) {

    postMeta.edit_entry( post_id, delay );
}

function pmpro_sequence_removeEntry(post_id, delay) {

    postMeta.remove_entry( post_id, delay );
}

jQuery(document).ready(function(){

    jQuery('#pmpro_sequencepost').select2();
    jQuery('div#pmpro-seq-error').hide();

    var adminUI = sequenceSettings;
    var posts = postMeta;

    adminUI.init();
    posts.init();
});


/**
 * Set the pmpro_seq_error element in the Sequence Posts meta box
 */
/*
function pmpro_seq_setErroMsg( $msg ) {

    console.log('Showing error message in meta box: ' + $msg);

    var errCtl = jQuery('div#pmpro-seq-error');

    errCtl.text($msg);
    errCtl.show();

    var timeout = window.setTimeout(function() {
        console.log('Hiding the error status again');
        errCtl.hide();
    }, 15000);

    console.log('Message: ' + $msg);
}
*/
