/**
 * Created by Thomas Sjolshagen of Eighty / 20 Results (c) 2014

 License:

 Copyright 2014-2019 Thomas Sjolshagen (thomas@eighty20results.com)

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

        this.email_settings = jQuery('.e20r-sequence-email');
        this.sendAlertCtl   = jQuery('#e20r-sequence_sendnotice');
        this.checkboxes     = jQuery('.e20r-sequence-settings-display .e20r-sequence-setting-col-1 > input[type="checkbox"]');
        this.saveBtn        = jQuery('a[class^="save-e20rseq"]');
        this.cancelBtn      = jQuery('a[class^="cancel-e20rseq"]');
        this.editBtn        = jQuery('a.e20r-seq-edit');

        this.selects        = jQuery('div.e20r-sequence-settings-input select');
        this.sortOrderCtl   = jQuery('#e20r-sequence_sortorder');
        this.delayCtl       = jQuery('#e20r-sequence_delaytype');
        this.showDelayCtl   = jQuery('#e20r-sequence_showdelayas');
        this.templCtl       = jQuery('#e20r-sequence_template');
        this.timeCtl        = jQuery('#e20r-sequence_noticetime');
        this.dateCtl        = jQuery('#e20r-sequence_dateformat');
        this.offsetCtl      = jQuery('#e20r-sequence_offset');
        this.repeatCtl      = jQuery('#e20r-sequence_allowRepeatPosts');
        this.sndAsCtl       = jQuery('#e20r-sequence_sendas');

        /* Input */
        this.excerptCtl     = jQuery('#e20r-sequence_excerpt');
        this.subjCtl        = jQuery('#e20r-sequence_subject');
        this.fromCtl        = jQuery('#e20r-sequence_fromname');
        this.replyCtl       = jQuery('#e20r-sequence_replyto');

        this.offsetChkCtl   = jQuery('#e20r-sequence_offsetchk');

        this.spinner = jQuery('div .seq_spinner');

        this.hide_rows();
        this.bind_buttons();
    },
    bind_buttons: function() {

        var $class = this;

        // Process 'edit' button clicks in settings.
        $class.editBtn.each( function() {

            jQuery(this).unbind().on('click', function() {
                jQuery(this).closest('.e20r-sequence-settings-display').next('.e20r-sequence-settings-input').slideToggle();
                jQuery(this).slideToggle();
            });

        });

        // Process manual 'Send now' request for alerts/notifications
        jQuery("#e20r_seq_send").unbind().on('click', function() {
            window.console.log("Sending email alerts manually");
            $class.send_alert();
        });

        // Process checkboxes for the Sequence settings
        $class.checkboxes.each(function() {

            $checkbox = jQuery(this);

            if ( 'e20r-sequence-checkbox_previewOffset' == $checkbox.attr('id') && ( $checkbox.is(':checked'))) {

                var $status = $checkbox.closest('.e20r-sequence-settings-display').next('.e20r-sequence-offset');
                window.console.log("The checkbox for the preview functionality is set, show its status", $status);
                $status.show();
            }

            $checkbox.unbind().on('click', function() {

                $class.checked_box( this );
            });
        });

        $class.saveBtn.each(function() {

            window.console.log("Found save button for setting");

            var $btn = jQuery(this);
            $btn.unbind().on('click', function(){

                var isSelect = $btn.closest('.e20r-sequence-settings-input').find('select');
                var isInput = $btn.closest('.e20r-sequence-settings-input').find('input[type="text"]');

                window.console.log("Contains select element?", isSelect );

                if ( isSelect.length ) {

                    window.console.log("Saving value(s) for select element...");
                    $class.save_select( isSelect );
                    return;
                }

                if ( isInput.length ) {

                    window.console.log("Saving value(s) for input element...");
                    $class.save_input( isInput );
                    return;
                }

                $class.hide_after_ok(jQuery(this));
                window.console.log("Clicked the OK button");
            });
        });

        $class.cancelBtn.each(function() {

            jQuery(this).unbind().on('click', function(){

                jQuery(this).closest('.e20r-sequence-settings-input').slideToggle();
                jQuery(this).closest('.e20r-sequence-settings-input').prev('.e20r-sequence-settings-display').find('.e20r-seq-edit').slideToggle();
                window.console.log("Clicked the cancel button");
            });

        });
    },
    _show_preview: function() {
        jQuery('#e20r_sequence_offset');
    },
    checked_box: function( $checkbox ) {

        window.console.log("Updating checkbox for ", $checkbox );

        var $class = this;

        if ( !( $checkbox instanceof jQuery ) ) {
            $checkbox = jQuery($checkbox);
        }

        if ( $checkbox.is(':checked') &&
            $checkbox.closest('.e20r-sequence-settings-display').next('.e20r-sequence-settings-display').hasClass('e20r-sequence-offset') ) {

            window.console.log("Need to manage visibility for offset setting");
            $checkbox.closest('.e20r-sequence-settings-display').next('.e20r-sequence-offset').show();
        }
        else if ( $checkbox.not(':checked') &&
            $checkbox.closest('.e20r-sequence-settings-display').next('.e20r-sequence-settings-display').hasClass('e20r-sequence-offset') ) {

            var $inputs = $checkbox.closest('.e20r-sequence-settings-display').next('.e20r-sequence-offset').next('.e20r-sequence-offset');
            var $status = $checkbox.closest('.e20r-sequence-settings-display').next('.e20r-sequence-offset');

            $inputs.find('input[type="hidden"]').val(0);
            $inputs.find('#e20r-sequence_offset').val(0);

            var $text = '<span class="e20r-sequence-status">' + $inputs.find('#e20r_sequence_offset option:selected').text() + '</span>';
            $status.find('.e20r-sequence-setting-col-2').html( $text );

            $checkbox.closest('.e20r-sequence-settings-display').next('.e20r-sequence-offset').hide();
            // jQuery('.e20r-sequence-offset').hide();
        }

        if ( 'e20r-sequence_sendnotice' == $checkbox.attr('id')  ) {

            window.console.log('Show all alert related variables');
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
            window.console.log("Setting checkbox hidden value for: ", $checkbox.attr('id'));
            $checkbox.closest('.e20r-sequence-setting-col-1').find('input[type=hidden]').val( ( $checkbox.is(':checked') ? 1 : 0 ));
        // }

        /* if ( $checkbox.not(':checked') &&
            window.console.log("Setting checkbox hidden value for: ", $checkbox.attr('id'));
            $checkbox.closest('.e20r-sequence-setting-col-1').find('#hidden_e20r_seq_allowRepeatPosts').val(1);
        }*/

    },
    save_input: function( input ) {

        input = jQuery(input);

        var container = input.closest('.e20r-sequence-settings-input');
        var hidden_input = container.find('input[type="hidden"]');
        var status = container.prev('.e20r-sequence-settings-display').find('.e20r-sequence-setting-col-2')
        var editBtn = container.prev('.e20r-sequence-settings-display').find('.e20r-seq-edit');

        /* Check whether the setting has changed */
        var $val;

        // Only update if the new value is different from the current (may not yet be saved) setting.
        if ( ( $val = input.val() ) != hidden_input.val() ) {

            hidden_input.val($val);
            status.html( '<span class="e20r-sequence-status">' + $val + '</span>');
        }

        input.css('margin-top', '10px');

        container.hide(); // Hide the Input field + OK & Cancel buttons
        editBtn.show(); // Show edit button again

    },
    save_select: function( select ) {

        var $class = this;
        select = jQuery(select);

        if ( 'e20r_sequence_delaytype' ==  select.attr('id')) {

            $class.change_delay_type();
        }

        var container = select.closest('.e20r-sequence-settings-input');
        var hidden_input = container.find('input[type="hidden"]');
        var status = container.prev('.e20r-sequence-settings-display').find('.e20r-sequence-setting-col-2')
        var editBtn = container.prev('.e20r-sequence-settings-display').find('.e20r-seq-edit');

        /* Check whether the setting has changed */
        var $val;

        if ( ($val = select.find('option:selected').val()) != hidden_input.val() ) {

            /* Save the new text (for label) */
            var $text = '<span class="e20r-sequence-status">' + select.find('option:selected').text() +"</span>";

            status.html($text); // Displayed setting value in label
            hidden_input.val($val); // Set the value='' for the hidden input field
        }

        select.css('margin-top', '10px');

        container.hide(); // Hide the Input field + OK & Cancel buttons
        editBtn.show(); // Show edit button again
    },
    change_delay_type: function() {

        var dtCtl = jQuery('#e20r-sequence_delayType');

        var selected = dtCtl.val();
        var current = jQuery('input[name=e20r_sequence_settings_hidden_delay]').val();

        if (dtCtl.val() != jQuery('#e20r_sequence_settings_hidden_delay').val()) {

            if (!confirm(e20r_sequence.lang.delay_change_confirmation)) {

                dtCtl.val(current);
                jQuery('#hidden_e20r_seq_wipesequence').val(0);

                return false;
            } else
                jQuery('#hidden_e20r_seq_wipesequence').val(1);

        }
    },
    delay_as_choice: function( $visibility ) {

        if ($visibility == 'hide') {
            window.console.log('Hide the showDelayAs options');
            jQuery('.e20r-seq-showdelayas').hide(); // hide
            jQuery('#e20r-seq-showdelayas-select').hide(); // hide
            jQuery('.e20r-seq-delay-btns').hide(); // hide
        }
        else {
            jQuery('.e20r-seq-showdelayas').show(); // show
            jQuery('.e20r-seq-delay-btns').show(); // show
            jQuery('#e20r-seq-showdelayas-select').show(); // hide
        }
    },
    set_error_message: function( $message ) {

        var errCtl = jQuery('#e20r-seq-error');

        errCtl.text($message);
        errCtl.show();

        var timeout = window.setTimeout(function() {
            window.console.log('Hiding the error status again');
            errCtl.hide();
        }, 15000);

        window.console.log('Message: ' + $message);
    },
    manageDelayLabels: function( $currentDelayType ) {

        window.console.log('In manageDelayLabels()');

        var delayDlg = jQuery('#e20r-seq-showdelayas');
        var delayEdit = jQuery('#e20r-seq-edit-showdelayas');

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

        var $settings = $okBtn.closest('.e20r-sequence-settings-input');
        $settings.slideToggle();
        $settings.prev('.e20r-sequence-settings-display').find('.e20r-seq-edit').slideToggle();
    },
    hide_rows: function() {

        jQuery('.e20r-sequence-hidden').each(function() {
            jQuery(this).hide();
        });
    },
    close_setting: function( $me ) {

        var $btnDiv = $me.closest('div.e20r-sequence-full-row');
    },
    send_alert: function() {

        var $sequence = jQuery('#post_ID').val();
        var $class = this;

        window.console.log("send_alert: ", $sequence );

        if ( $sequence === 'undefined' ) {
            alert(e20r_sequence.lang.alert_not_saved);
        }
        else {

            jQuery.ajax({
                url: e20r_sequence.ajaxurl,
                type: 'POST',
                timeout: 5000,
                dataType: 'JSON',
                data: {
                    action: 'e20r_send_notices',
                    e20r_sequence_sendalert_nonce: jQuery('#e20r_sequence_sendalert_nonce').val(),
                    e20r_sequence_id: $sequence
                },
                error: function (data) {
                    if (data.message != null) {
                        alert(data.message);
                        $class.set_error_message( data.message );
                    }
                }
            });
        }
    },
    set_responsive: function() {

        window.console.log("Processing responsive layout for Sequence post metabox");

        var headertext = [];
        var headers = document.querySelectorAll("table#e20r_sequencetable thead");
        var tablebody = document.querySelectorAll("table#e20r_sequencetable tbody");

        for (var i = 0; i < headers.length; i++) {
            headertext[i]=[];
            for (var j = 0, headrow; headrow = headers[i].rows[0].cells[j]; j++) {
                var current = headrow;
                headertext[i].push(current.textContent);
            }
        }

        for (var h = 0, tbody; tbody = tablebody[h]; h++) {
            for (var i = 0, row; row = tbody.rows[i]; i++) {
                for (var j = 0, col; col = row.cells[j]; j++) {
                    col.setAttribute("data-th", headertext[h][j]);
                }
            }
        }
    },
    _waitForFinalEvent: function () {
        var timers = {};
        return function (callback, ms, uniqueId) {
            if (!uniqueId) {
                uniqueId = "Don't call this twice without a uniqueId";
            }
            if (timers[uniqueId]) {
                clearTimeout (timers[uniqueId]);
            }
            timers[uniqueId] = setTimeout(callback, ms);
        };
    }
};

var postMeta = {
    init: function() {

        this.sequence_list = jQuery( 'select.e20r_seq-memberof-sequences');
        this.spinner = jQuery('div.seq_spinner');
        this.delay_type     = jQuery('#e20r-seq-hidden-delay-type').val();

        var $class = this;

        $class.bind_controls();
    },
    bind_controls: function() {

        var $class = this;

        $class.sequence_list = jQuery( 'select.e20r_seq-memberof-sequences');

        jQuery('select.new-sequence-select, .e20r_seq-memberof-sequences').each(function() {
            jQuery(this).unbind('change').on( 'change', function () {

                window.console.log("User changed the content of the select box.")
                $class.meta_select_changed( this );
            });
        });

        jQuery("button.e20r-sequences-clear-cache").unbind('click').on('click', function() {

            $class.clear_cache();
        });

        jQuery("#e20r-seq-new-meta").unbind('click').on( "click", function() {

            $class.manage_meta_rows();
            window.console.log("Add new table row for metabox");
            $class.spinner.show();

            // $class.row_visibility( jQuery( '.new-sequence-select' ), 'select' );
            $class.add_sequence_post_row();

            $class.spinner.hide();
            $class.manage_meta_rows();
        });

        jQuery('.delay-row-input input:checkbox').unbind('click').on( 'click', function() {

            window.console.log("The 'remove' checkbox was clicked...");
            var $checkbox = this;

            if ( !( $checkbox instanceof jQuery ) ) {
                $checkbox = jQuery(this);
            }

            var delay_input = $checkbox.closest('td').find('.e20r-seq-delay-info').val();

            if ( ( '' == delay_input ) ) {

                window.console.log("Value is empty so just clearing (removing) the HTML from the page");

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

        jQuery('.delay-row-input.sequence-delay button.e20r-sequence-remove-alert').unbind('click').on('click', function() {
            window.console.log("The 'clear alerts' button was clicked");

            var button = this;

            if ( !( button instanceof jQuery ) ) {
                button = jQuery( button );
            }

            var delay_input = button.closest('td').find('.e20r-seq-delay-info').val();
            var sequence_id = button.closest('td').find('input.e20r_seq-remove-seq').val();
            var post_id = jQuery('#post_ID').val();

            if ( !post_id ) {

                alert("Warning: Post has not been saved yet, so there are no alerts to clear");
                return;
            }

            $class.clear_post_notice_alerts( sequence_id, post_id, delay_input );
        });

        $class.show_controls();
        jQuery('#e20r_sequencepost').select2();
    },
    clear_post_notice_alerts: function( sequence_id, post_id, delay ) {

        event.preventDefault();

        var data = {
            'action': 'e20r_remove_alert',
            'e20r_sequence_postmeta_nonce': jQuery('#e20r_sequence_postmeta_nonce').val(),
            'e20r_sequence_id': sequence_id,
            'e20r_sequence_post': post_id,
            'e20r_sequence_post_delay': delay
        };

        jQuery.ajax({
            url: e20r_sequence.ajaxurl,
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
        var in_admin_panel = false;

        if ( jQuery('#e20r_sequence_meta').length ) {
            in_admin_panel = true;
        }
        jQuery('#e20r_sequencesave').attr('disabled', 'disabled');
        // jQuery('#e20r_sequencesave').html(e20r_sequence.lang.saving);

        jQuery.ajax({
            url: e20r_sequence.ajaxurl,
            type:'POST',
            timeout:5000,
            dataType: 'JSON',
            data: {
                action: 'e20r_sequence_rm_post',
                e20r_sequence_id: jQuery('#e20r_sequence_id').val(),
                in_admin_panel: in_admin_panel,
                e20r_seq_post: post_id,
                e20r_seq_delay: delay,
                e20r_sequence_post_nonce: jQuery('#e20r_sequence_post_nonce').val()
            },
            error: function(response, $errString, $errType){

                window.console.log("Error during remove operation:", response, $errString, $errType);

                if ($errString === 'timeout') {
                    window.console.log("Error: Timeout...");
                    return;
                }

                if (typeof response.data.message != 'undefined') {

                    alert(response.data.message);
                    $class.set_error_message( $data.data.message );
                }

                if (typeof response.data === 'object') {

                    window.console.log("Received an object as the error status");
                    var last_element = response.data.length - 1;

                    window.console.log("Received " + response.data.length + " error messages");
                    $class.set_error_message( response.data[last_element].message );
                    alert(response.data.message);
                }

            },
            success: function($data){

                window.console.log("Returned for remove operation: ", $data);

                // Fix: Handle case where there are no posts left in sequence.
                if ($data.data.html !== null && $data.data.html.length) {
                    jQuery('#e20r_sequence_posts').html( $data.data.html );
                }

                if ($data.data.message !== null && $data.data.message.length) {
                    $class.set_error_message($data.data.message);
                }

                if (typeof $data.data === 'object') {

                    window.console.log("Received an object as the error status");
                    var last_element = $data.data.length - 1;

                    window.console.log("Received " + $data.data.length + " error messages");
                    $class.set_error_message( $data.data[last_element].message );
                    alert($data.data.message);
                }


            },
            complete: function() {
                // Enable the Save button again.
                jQuery('#e20r_sequencesave').removeAttr('disabled');
                $class.bind_controls();
            }
        });
    },
    edit_entry: function(post_id, delay) {

        jQuery('#newmeta').focus();
        jQuery('#e20r_sequencepost').val(post_id).trigger("change");
        jQuery('#e20r_sequencedelay').val(delay);
        jQuery('#e20r_sequencesave').html(e20r_sequence.lang.save);
    },
    edit_post: function( post_id ) {

        var win = window.open('/wp-admin/post.php?post=' + post_id + '&action=edit', '_blank');
        if (win)
            win.focus();
        else
            alert('Your browser settings prevents this action. You need to allow pop-ups');
    },
    add_entry: function() {

        var saveBtn = jQuery('#e20r_sequencesave');
        var $class = this;

        if ('' == jQuery('#e20r_sequence_post').val() || undefined != saveBtn.attr('disabled')) {

            return false; //already processing, ignore this request
        }

        // Disable save button
        saveBtn.attr('disabled', 'disabled');
        saveBtn.html(e20r_sequence.lang.saving);

        //pass field values to AJAX service and refresh table above - Timeout is 5 seconds
        jQuery.ajax({
            url: e20r_sequence.ajaxurl,
            type:'POST',
            timeout:5000,
            dataType: 'JSON',
            data: {
                action: 'e20r_sequence_add_post',
                e20r_sequence_id: jQuery('#e20r_sequence_id').val(),
                e20r_sequence_post: jQuery('#e20r_sequencepost').val(),
                e20r_sequence_delay: jQuery('#e20r_sequencedelay').val(),
                e20r_sequence_post_nonce: jQuery('#e20r_sequence_post_nonce').val()
            },
            error: function( $response, $errString, $errType ) {
                window.console.log("error() - Returned data: " + $response + " and error:" + $errString + " and type: " + $errType );

                if ($errString === 'timeout') {
                    $class.set_error_message("Timeout: Unable to add post/page (ID: " + jQuery("#e20r_sequencepost").val() + ")");
                    return;
                }

                if (typeof $response.data === 'object') {

                    window.console.log("Received an object as the error status");
                    var last_element = $response.data.length - 1;
                    window.console.log("Received " + $response.data.length + " error messages");
                    $class.set_error_message( $response.data[last_element].message );
                    return;
                }

                if ($response.data.message !== null && $response.data.message.length) {

                    // alert($data.data);
                    $class.set_error_message( $response.data.message );
                }
            },
            success: function($returned, $success ){

                window.console.log("success() - Returned data: ", $returned );

                if ($returned.data.html !== null && $returned.data.html.length) {
                    window.console.log('Entry added to sequence & refreshing metabox content');
                    jQuery('#e20r_sequence_posts').html($returned.data.html);
                }

                if( null !== $returned.data.message && !$returned.data.message.length){
                    $class.set_error_message($returned.data.message);
                    return;
                }

                if (($returned.data.html !== null)|| ($returned.data.message !== null)) {

                    if (typeof $returned.data === 'object') {

                        window.console.log("Received an object as the error status");
                        var last_element = $returned.data.length - 1;
                        window.console.log("Received " + $returned.data.length + " error messages");
                        $class.set_error_message( $returned.data[last_element].message );
                    }

                }
            },
            complete: function($data) {

                // Re-enable save button
                saveBtn.html(e20r_sequence.lang.save);
                saveBtn.removeAttr('disabled');
                $class.bind_controls();

            }
        });
    },
    remove_sequence: function( $me ) {

        event.preventDefault();

        var $class = this;

        $class.manage_meta_rows();

        $class.spinner.show();

        window.console.log("Removing sequence info: ", $me );

        if ( !( $me instanceof jQuery ) ){

            $me = jQuery($me);
        }

        var delay_ctrl = $me.closest('td').find('input.e20r-seq-delay-info.e20r-seq-days');
        window.console.log("Delay value: ", delay_ctrl.val() );

        jQuery.ajax({
            url: e20r_sequence.ajaxurl,
            type: 'POST',
            timeout: 10000,
            dataType: 'JSON',
            data: {
                action: 'e20r_rm_sequence_from_post',
                e20r_sequence_id: $me.val(),
                e20r_seq_delay: delay_ctrl.val(),
                e20r_seq_post_id: jQuery('#post_ID').val(),
                e20r_sequence_postmeta_nonce: jQuery('#e20r_sequence_postmeta_nonce').val()
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
                    jQuery('#e20r_seq-configure-sequence').html($data.data);
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

        if ( jQuery('div#e20r_seq-configure-sequence').length === 0) {
            return;
        }

        var $count = 0;
        var $class = this;

        $class.sequence_list.each( function() {

            $class.row_visibility( this, 'all' );
            $count++;
        });

        window.console.log('Number of selects with defined sequences: ' + $count);

        // Check if there's more than one select box in metabox. If so, the post already belongs to sequences
        if ( $count >= 1 ) {

            // Hide the 'new sequence' select and show the 'new' button.
            $class.row_visibility( jQuery( 'select.new-sequence-select') , 'none' );

            jQuery('#e20r-seq-new').show();
            jQuery('#e20r-seq-new-meta').show();
            jQuery('#e20r-seq-new-meta-reset').hide();
        }
        else {

            // Show the row for the 'Not defined' in the New sequence drop-down
            $class.row_visibility( jQuery( 'select.new-sequence-select' ), 'select' );

            // Hide all buttons
            jQuery('#e20r-seq-new').hide();
        }
    },
    clear_cache: function() {

        event.preventDefault();
        var $class = this;
        var sequence_id = jQuery("#post_ID").val();

        window.console.log("Attempting to clear the sequence cache for: " + sequence_id);
        jQuery.ajax({
            url: e20r_sequence.ajaxurl,
            type:'POST',
            timeout:5000,
            dataType: 'JSON',
            data: {
                action: 'e20r_sequence_clear_cache',
                e20r_sequence_id: sequence_id,
                e20r_sequence_post_nonce: jQuery('#e20r_sequence_post_nonce').val()
            },
            error: function($data, $errString, $errType){

                window.console.log("Returned error object", $data);
                if ($errString === 'timeout') {
                    $class.set_error_message("Timeout: Unable to clear cache)");
                    return;
                }

                if (typeof $data.data === 'object') {

                    window.console.log("Received an object as the error status");
                    var last_element = $data.data.length - 1;

                    window.console.log("Received " + $data.data.length + " error messages");
                    $class.set_error_message( $data.data[last_element].message );
                    alert($data.data.message);
                    return;
                }

                if ($data.data.message !== null && $data.data.message.length) {

                    $class.set_error_message( $data.data.message );
                }
            },
            success: function(){

                location.reload();
            },
        });

    },
    manage_meta_rows: function() {

        event.preventDefault();

        jQuery( '.e20r_seq-memberof-sequences, .new-sequence-select, .e20r-seq-delay-info, .e20r_seq-remove-seq' ).each( function() {

            if (! jQuery( this ).is( ':disabled') ) {

                jQuery(this).attr('disabled', false);
                window.console.log("Enable row");
            }
            else {
                jQuery(this).attr('disabled', true);
                window.console.log("Disable row");;
            }
        });

        jQuery( '#e20r-seq-new-meta' ).attr( 'disabled', true );
        jQuery( '#e20r-seq-new-meta-reset' ).attr( 'disabled', true );
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

        // window.console.log("Delay settings: ", e20r_sequence.delay_config);
        var $html;
        var $label;

        if ( 'byDate' == e20r_sequence.delay_config[ sequence_id ]  ) {
            $label = "Delay (Format: Date)";

        }

        if ( 'byDays' ==  e20r_sequence.delay_config[ sequence_id ] ) {
            $label = "Delay (Format: Day count)";
        }

        $html = '<label for="e20r_seq-delay_' + sequence_id + '">' + $label + '</label>';
        return $html;
    },
    _delay_input: function( sequence_id ) {

        window.console.log("Delay settings: ", e20r_sequence.delay_config);
        var $html;

        if ( 'byDate' == e20r_sequence.delay_config[ sequence_id ]  ) {

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

            $html = "<input class='e20r-seq-delay-info e20r-seq-date' type='date' value='' name='e20r_seq-delay[]' min='" + starts + "'>";
        }

        if ( 'byDays' ==  e20r_sequence.delay_config[ sequence_id ] ) {
            $html = "<input class='e20r-seq-delay-info e20r-seq-days' type='text' name='e20r_seq-delay[]' value=''>";
        }

        return $html;
    },
    set_error_message: function( $message ) {

        window.console.log("Setting error message: " + $message );

        var errCtl = jQuery('#e20r-seq-error');

        errCtl.html($message);
        errCtl.show();

        var timeout = window.setTimeout(function() {
            window.console.log('Hiding the error status again');
            errCtl.hide();
        }, 15000);

        window.console.log('Message: ' + $message);
    },
    add_sequence_post_row: function( ) {

        event.preventDefault();

        var $class = this;

        var table = jQuery("#e20r-seq-metatable").find('tbody');

        var select_label_row = table.find('tr.select-row-label.sequence-select-label:first').clone();
        var select_row = table.find('tr.select-row-input.sequence-select:first').clone();
        var delay_label_row = table.find('tr.delay-row-label.sequence-delay-label:first').clone();
        var delay_row = table.find('tr.delay-row-input.sequence-delay:first').clone();

        window.console.log("Unselect the new sequence ID and clear any delay value(s)");
        select_row.find('.e20r_seq-memberof-sequences').val('');
        delay_row.find('.e20r-seq-delay-info.e20r-seq-days').val('');

        table.append( select_label_row );
        table.append( select_row );
        table.append( delay_label_row );
        table.append( delay_row );

        $class.bind_controls();

        window.console.log("Added 4 rows for the new sequence.");
    },
    /* _duplicate_meta_entry: function( sequence_id, delay_val, entry ) {

        var $class = this;
        window.console.log("Using sequence ID: " + sequence_id + ' at element: ', entry );

        jQuery("tr.select-row-input.sequence-select select.e20r_seq-memberof-sequences").each( function() {

            var sequence = jQuery( this );

            if ( sequence_id === sequence.val() ) {
                select_label = sequence.closest('tr .select-row-label.sequence-select-label');
                return false;
            }
        });

        window.console.log("Select label entry for the specific sequence ID: " + sequence_id, select_label );

        if ( ( null !== select_label ) || ( false !== select_label ) ) {
            var select = select_label.next();
            var delay_label = select.next();
            var delay = delay_label.next();

            window.console.log("Delay value is now: ", delay.find('input.e20r-seq-delay-info.e20r-seq-days').val() );
            delay.find('input.e20r-seq-delay-info.e20r-seq-days').val('');
            window.console.log("After (supposed) clear... Delay value is now: ", delay.find('input.e20r-seq-delay-info.e20r-seq-days').val() );

            window.console.log("Find the last delay value input in the list: ", jQuery("tr.delay-row-input.sequence-delay:last") );

            jQuery("tr.delay-row-input.sequence-delay:last").appendTo(select_label);
            window.console.log("Attempted to add select label: ", select_label );
            jQuery("tr.select-row-label.sequence-select-label:last").appendTo(select);
            jQuery("tr.select-row-input.sequence-select:last").appendTo(delay_label);
            jQuery("tr.delay-row-label.sequence-delay-label:last").appendTo(delay);

            window.console.log("Added rows");
        }
    }, */
    meta_select_changed: function( $self ) {

        event.preventDefault();

        var $class = this;
        $class.manage_meta_rows();

        window.console.log("Changed the Sequence this post is a member of");
        $class.spinner.show();

        window.console.log("Self is: ", $self );

        if ( !( $self instanceof jQuery ) ) {

            $self = jQuery( $self );
        }

        var managed_sequences = {};

        $self.closest('#e20r-seq-metatable').find('tr.select-row-input').find('select.e20r_seq-memberof-sequences option:selected').each(function() {

            var input = jQuery(this);

            if ( '' != input.val() ) {
                var key = input.val();
                window.console.log("Is_managed: Sequence ID: ", key );
                managed_sequences[ key ] = input.closest('tr');
            }
        });

        var sequence_id = $self.val();
        var delay_value =  $self.closest('tr.select-row-input.new-sequence-select').next().next().find('input.e20r-seq-delay-info').val();

        if ( ( ( managed_sequences.hasOwnProperty( sequence_id ) ) && ( managed_sequences[ sequence_id ] instanceof jQuery ) ) && ('' == delay_value ) ) {

            window.console.log("No delay value specified but the current sequence_id is already being managed");
            window.console.log("Duplicate all 4 rows and insert after the last (with empty delay value)");

            // $self.closest('tr.select-row-input.sequence-select').
            $class.add_sequence_post_row();

            return;
        }

        if ( ( '' == sequence_id ) ) {

            window.console.log("sequence_id is empty for: ", $self );
            return;
            window.console.log("Should have exited...")
        }

        window.console.log("Sequence ID: " + sequence_id );

        var $input = $class._delay_input( sequence_id );
        var $label = $class._delay_label( sequence_id );

        window.console.log("Setting label to: ", $label);
        window.console.log("Setting input to: ", $input);

        // Disable delay and sequence input.
        var delay_label_row = $self.closest('tr.select-row-input.sequence-select').next();
        var delay_row = $self.closest('tr.select-row-input.sequence-select').next().next();

        delay_label_row.find('label[for^="e20r_seq-delay"]').replaceWith($label);
        delay_row.find('input.e20r-seq-delay-info.e20r-seq-days').replaceWith($input);

        // $class.show_controls();
        $class.manage_meta_rows();
        $class.bind_controls();
        jQuery( '#e20r-seq-new-meta' ).attr( 'disabled', false );
        jQuery( '#e20r-seq-new-meta-reset' ).attr( 'disabled', false );

        $class.spinner.hide();

    },
    _set_labels: function() {

        var delayType = jQuery('#e20r_sequence_delaytype').val();
        var headerHTML_start = '<th id="e20r_sequence_delaytype">';
        var headerHTML_end = '</th>';
        var entryHTML_start = '<th id="e20r_sequence_delayentrytype">';
        var entryHTML_end = '</th>';


        var labelText = e20r_sequence.lang.undefined; // 'Not Defined';
        var entryText = e20r_sequence.lang.undefined;

        if (delayType == 'byDays')
        {
            labelText = e20r_sequence.lang.daysLabel; // "Delay";
            entryText = e20r_sequence.lang.daysText; //"Days to delay";
        }

        if (delayType == 'byDate')
        {
            labelText = e20r_sequence.lang.dateLabel; // "Avail. on";
            entryText = e20r_sequence.lang.dateText; // "Release on (YYYY-MM-DD)";
        }

        jQuery('#e20r_sequence_delaylabel').html( headerHTML_start + labelText + headerHTML_end);
        jQuery('#e20r_sequence_delayentrylabel').html( entryHTML_start + entryText + entryHTML_end);
    }
};

function e20r_sequence_addEntry() {

    postMeta.add_entry();
}

function e20r_sequence_editPost(post_id) {

    postMeta.edit_post( post_id );
}

function e20r_sequence_editEntry(post_id, delay) {

    postMeta.edit_entry( post_id, delay );
}

function e20r_sequence_removeEntry(post_id, delay) {

    postMeta.remove_entry( post_id, delay );
}

jQuery(document).ready(function(){

    if ( '' === jQuery('div#e20r-seq-error' ).text ) {
        jQuery('div#e20r-seq-error').hide();
    }

    var adminUI = sequenceSettings;
    var posts = postMeta;

    adminUI.init();
    posts.init();

    if ( jQuery(".wp-admin table#e20r_sequencetable").length !== 0) {

        adminUI.set_responsive();

        jQuery(window).on('resize', function() {

            adminUI._waitForFinalEvent( function(){
                window.console.log("Resized the document");
                adminUI.set_responsive();

            }, 500, "128934jkdse");
        });
    }

});


/**
 * Set the e20r_seq_error element in the Sequence Posts meta box
 */
/*
function e20r_seq_setErroMsg( $msg ) {

    window.console.log('Showing error message in meta box: ' + $msg);

    var errCtl = jQuery('div#e20r-seq-error');

    errCtl.text($msg);
    errCtl.show();

    var timeout = window.setTimeout(function() {
        window.console.log('Hiding the error status again');
        errCtl.hide();
    }, 15000);

    window.console.log('Message: ' + $msg);
}
*/
