/**
 * Created by sjolshag on 7/8/14.
 */
console.log('Loading pmpro-sequences-admin.js script');

jQuery.noConflict();
jQuery(document).ready(function(){
    (function($){
        /* Get the current sortOrder values */
        var $sortOrder = $('#pmpro_sequence_sortorder option:selected').val();
        var $sortText = $('#pmpro_sequence_sortorder option:selected').text();
        var $delayText = $('#pmpro_sequence_delaytype option:selected').text();
        var $delayType = $('#pmpro_sequence_delaytype option:selected').val();
        var $templateName = $('#pmpro_sequence_template option:selected').text();
        var $template = $('#pmpro_sequence_template option:selected').val();
        var $alertText = $('#pmpro_sequence_noticetime option:selected').text();
        var $alertTime = $('#pmpro_sequence_noticetime option:selected').val();
        var $excerpt = $('#pmpro_sequence_excerpt').val();
        var $subject = $('#pmpro_sequence_subject').val();
        var $fromname = $('#pmpro_sequence_fromname').val();
        var $replyto = $('#pmpro_sequence_replyto').val();

        // console.log('Sort Order is: ' + jQuery('#pmpro_sequence_sortorder option:selected').text());

        if ( $('#pmpro_sequence_sendnotice').is(':checked') ) {
            console.log('Show all notice related variables');
            $('.pmpro-sequence-email').show();
            $('.pmpro-sequence-template').show();
            $('.pmpro-sequence-noticetime').show();
        }

        /** Edit button events **/

        /* Admin clicked the 'Edit' button for the SortOrder settings. Show the select field & hide the "edit" button */
        $('#pmpro-seq-edit-sort').click(function(){
            console.log('Edit button for sort order clicked');
            $('#pmpro-seq-edit-sort').slideToggle();
            $('#pmpro-seq-sort-select').slideToggle();
        });

        /* Admin clicked the 'Edit' button for the delayType settings. Show the select field & hide the "edit" button */
        $('#pmpro-seq-edit-delay').click(function(){
            console.log('Edit button for delay type clicked');
            $('#pmpro-seq-edit-delay').slideToggle();
            $('#pmpro-seq-delay-select').slideToggle();
        });

        /* Show/Hide the alert template information */
        $('#pmpro_sequence_sendnotice').click(function(){
            console.log('Checkbox to allow sending notice clicked');
            $('#hidden_pmpro_seq_sendnotice').val( this.checked ? 1 : 0 );
            $('.pmpro-sequence-template').slideToggle();
            $('.pmpro-sequence-noticetime').slideToggle();
            $('.pmpro-sequence-email').slideToggle();
        });

        /* Save the value for the setting for the 'hide future posts in sequence' checkbox*/
        $('#pmpro_sequence_hidden').click(function(){
            console.log('Checkbox to hide upcoming posts changed');
            $('#hidden_pmpro_seq_future').val( this.checked ? 1 : 0 );
        });

        /* Save new value for the lengthVisible variable */
        $('#pmpro_sequence_lengthvisible').click(function(){
            console.log('Checkbox to show length of membership notice changed');
            $('#hidden_pmpro_seq_lengthvisible').val( this.checked ? 1 : 0 );
        });

        /* Admin clicked the 'Edit' button for the New Content Notice Template settings. Show the select field & hide the "edit" button */
        $('#pmpro-seq-edit-template').click(function(){
            console.log('Edit button for email template selection clicked');
            $('#pmpro-seq-edit-template').slideToggle();
            $('#pmpro-seq-template-select').slideToggle();
        });

        /* Admin clicked the 'Edit' button for the New Content Notice Template settings. Show the select field & hide the "edit" button */
        $('#pmpro-seq-edit-noticetime').click(function(){
            console.log('Edit button for email template selection clicked');
            $('#pmpro-seq-edit-noticetime').slideToggle();
            $('#pmpro-seq-noticetime-select').slideToggle();
        });

        $('#pmpro-seq-edit-excerpt').click(function(){
            console.log('Edit button for excerpt intro edit field clicked');
            $('#pmpro-seq-edit-excerpt').slideToggle();
            $('#pmpro-seq-excerpt-input').slideToggle();
        });

        $('#pmpro-seq-edit-subject').click(function(){
            console.log('Edit button for Subject prefix edit field clicked');
            $('#pmpro-seq-edit-subject').slideToggle();
            $('#pmpro-seq-subject-input').slideToggle();
        });

        $('#pmpro-seq-edit-fromname').click(function(){
            console.log('Edit button for email edit field clicked');
            $('#pmpro-seq-email-input').slideToggle();
            $('#pmpro-seq-edit-fromname').slideToggle();
        });

        $('#pmpro-seq-edit-replyto').click(function(){
            console.log('Edit button for email edit field clicked');
            $('#pmpro-seq-email-input').slideToggle();
            $('#pmpro-seq-edit-replyto').slideToggle();
        });

        /** Cancel button events **/

        /** Admin clicked the 'Cancel' button for the SortOrder edit settings. Reset
         * the value of the label & select, then hide everything again.
         */
        $('#cancel-pmpro-seq-sort').click(function(){
            console.log('Cancel button for Sort order was clicked');
            // $('#pmpro_sequence_sortorder').getAttribute('hidden_pmpro_seq_sortorder');
            $('#pmpro-seq-sort-select').slideToggle();
            $('#pmpro-seq-edit-sort').slideToggle();

        });
        /** Admin clicked the 'Cancel' button for the DelayType edit settings. Reset
         * the value of the label & select, then hide everything again.
         */
        $('#cancel-pmpro-seq-delay').click(function(){
            console.log('Cancel button for Delay type was clicked');
            // $('#pmpro_sequence_delaytype').getAttribute('hidden_pmpro_seq_delaytype');
            $('#pmpro-seq-delay-select').slideToggle();
            $('#pmpro-seq-edit-delay').slideToggle();

        });

        /**
         * Admin clicked the 'Cancel' button for the New content alert Template edit settings. Reset
         * the value of the label & select, then hide everything again.
         */
        $('#cancel-pmpro-seq-template').click(function(){
            console.log('Cancel button to set template was clicked');
            // $('#pmpro_sequence_sortorder').getAttribute('hidden_pmpro_seq_sortorder');
            $('#pmpro-seq-template-select').slideToggle();
            $('#pmpro-seq-edit-template').slideToggle();

        });

        /**
         * Admin clicked the 'Cancel' button for the New content alert Template edit settings. Reset
         * the value of the label & select, then hide everything again.
         */
        $('#cancel-pmpro-seq-noticetime').click(function(){
            console.log('Cancel button to set alert time was clicked');
            // $('#pmpro_sequence_sortorder').getAttribute('hidden_pmpro_seq_sortorder');
            $('#pmpro-seq-noticetime-select').slideToggle();
            $('#pmpro-seq-edit-noticetime').slideToggle();

        });

        /** Admin clicked the 'Cancel' button for the SortOrder edit settings. Reset
         * the value of the label & select, then hide everything again.
         */
        $('#cancel-pmpro-seq-excerpt').click(function(){
            console.log('Cancel button for Excerpt Intro was clicked');
            // $('#pmpro_sequence_sortorder').getAttribute('hidden_pmpro_seq_sortorder');
            $('#pmpro-seq-excerpt-input').slideToggle();
            $('#pmpro-seq-edit-excerpt').slideToggle();

        });

        $('#cancel-pmpro-seq-subject').click(function(){
            console.log('Cancel button for Subject Intro was clicked');
            // $('#pmpro_sequence_sortorder').getAttribute('hidden_pmpro_seq_sortorder');
            $('#pmpro-seq-subject-input').slideToggle();
            $('#pmpro-seq-edit-subject').slideToggle();

        });

        $('#cancel-pmpro-seq-email').click(function(){
            console.log('Cancel button for email settings was clicked');

            $('#pmpro-seq-email-input').slideToggle();
            $('#pmpro-seq-edit-replyto').slideToggle();
            $('#pmpro-seq-edit-fromname').slideToggle();

        });



        /** OK button events **/
        $('#ok-pmpro-seq-sort').click(function(){
            console.log('OK button for Sort order was clicked');
            if ( $('#pmpro-seq-sort-status option:selected').val != $sortOrder) {
                /* Save the new sortOrder setting */
                $sortText = $('#pmpro_sequence_sortorder option:selected').text();
                $sortOrder = $('#pmpro_sequence_sortorder option:selected').val();
                $('#pmpro-seq-sort-status').text($sortText);
                $('#hidden_pmpro_seq_sortorder').val($sortOrder);
                console.log('Sort order was changed and is now: ' + $sortText);
            }
            $('#pmpro-seq-sort-select').slideToggle();
            $('#pmpro-seq-edit-sort').slideToggle();
        });

        $('#ok-pmpro-seq-delay').click(function(){
            console.log('OK button for delay type was clicked');
            if ( $('#pmpro-seq-delay-status option:selected').val != $delayType) {
                /* Save the new sortOrder setting */
                $delayText = $('#pmpro_sequence_delaytype option:selected').text();
                $delayType = $('#pmpro_sequence_delaytype option:selected').val();
                $('#pmpro-seq-delay-status').text($delayText);
                $('#hidden_pmpro_seq_delaytype').val($delayType);
                console.log('Sort order was changed and is now: ' + $delayText);
            }
            $('#pmpro-seq-delay-select').slideToggle();
            $('#pmpro-seq-edit-delay').slideToggle();
        });

        $('#ok-pmpro-seq-template').click(function(){
            console.log('OK button for template was clicked');
            if ( $('#pmpro-seq-template-status option:selected').val != $template) {
                /* Save the new sortOrder setting */
                $templateName = $('#pmpro_sequence_template option:selected').text();
                $template = $('#pmpro_sequence_template option:selected').val();
                $('#pmpro-seq-template-status').text($templateName);
                $('#hidden_pmpro_seq_noticetemplate').val($template);
                console.log('Template was changed and is now: ' + $templateName);
            }
            $('#pmpro-seq-template-select').slideToggle();
            $('#pmpro-seq-edit-template').slideToggle();
        });

        $('#ok-pmpro-seq-noticetime').click(function(){
            console.log('OK button for alert notice time was clicked');
            if ( $('#pmpro-seq-noticetime-status option:selected').val != $alertTime) {
                /* Save the new sortOrder setting */
                $alertText = $('#pmpro_sequence_noticetime option:selected').text();
                $alertTime = $('#pmpro_sequence_noticetime option:selected').val();
                $('#pmpro-seq-noticetime-status').text($alertText);
                $('#hidden_pmpro_seq_noticetime').val($alertTime);
                console.log('Content change notice was changed and is now: ' + $alertText);
            }
            $('#pmpro-seq-noticetime-select').slideToggle();
            $('#pmpro-seq-edit-noticetime').slideToggle();
        });

        $('#ok-pmpro-seq-excerpt').click(function(){
            console.log('OK button for Excerpt Intro was clicked');
            if ( $('#pmpro_sequence_excerpt').val != $excerpt) {
                /* Save the new excerpt info */
                $excerpt = $('#pmpro_sequence_excerpt').val();
                $('#hidden_pmpro_seq_excerpt').val($excerpt);
                $('#pmpro-seq-excerpt-status').text('"' + $excerpt + '"');
                console.log('Content of Excerpt Intro was changed and is now: ' + $excerpt);
            }
            $('#pmpro-seq-excerpt-input').slideToggle();
            $('#pmpro-seq-edit-excerpt').slideToggle();
        });

        $('#ok-pmpro-seq-subject').click(function(){
            console.log('OK button for Subject Intro was clicked');
            if ( $('#pmpro_sequence_subject').val != $subject) {
                /* Save the new excerpt info */
                $subject = $('#pmpro_sequence_subject').val();
                $('#hidden_pmpro_seq_subject').val($subject);
                $('#pmpro-seq-subject-status').text('"' + $subject + '"');
                console.log('Content of Subject Intro was changed and is now: ' + $subject);
            }
            $('#pmpro-seq-subject-input').slideToggle();
            $('#pmpro-seq-edit-subject').slideToggle();
        });

        $('#ok-pmpro-seq-email').click(function(){
            console.log('An OK button for email settings was clicked');
            if ( ( $('#pmpro_sequence_fromname').val != $fromname)  ||
                 ( $('#pmpro_sequence_replyto').val() != $replyto) ) {
                /* Save the new excerpt info */
                $fromname = $('#pmpro_sequence_fromname').val();
                $replyto = $('#pmpro_sequence_replyto').val();
                $('#hidden_pmpro_seq_fromname').val($fromname);
                $('#hidden_pmpro_seq_replyto').val($replyto);
                $('#pmpro-seq-fromname-status').text('"' + $fromname + '"');
                $('#pmpro-seq-replyto-status').text('"' + $replyto + '"');
                console.log('Content of email settings was changed and is now: ' + $fromname + ' and ' + $replyto);
            }
            $('#pmpro-seq-email-input').slideToggle();
            $('#pmpro-seq-edit-replyto').slideToggle();
            $('#pmpro-seq-edit-fromname').slideToggle();
        });

    })(jQuery);
});

function setLabels()
{
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

function isHidden()
{
    if (jQuery('#pmpro_sequence_hidden').is(":checked"))
        return jQuery('input#pmpro_sequence_hidden').val();
    else
        return 0;
}
function showLength()
{
    var lengthVisible = jQuery('input#pmpro_sequence_lengthvisible').val();
    console.log('lengthVisible checkbox value: ' + lengthVisible);

    if ( jQuery('#pmpro_sequence_lengthvisible').is(":checked"))
    {
        console.log('lengthVisible setting is checked');
        return jQuery('input#pmpro_sequence_lengthvisible').val();;
    }
    else
        return 0;
}

function formatTime($h_24) {
    var $time = $h_24.split(':');

    var $h = $h_24 % 12;
    if ($h === 0) $h = 12;
    return ($h < 10 ? "0" + $h : $h) + ":" + $time[1] + ($h_24 < 12 ? ' AM' : ' PM');
}

function pmpro_sequence_addPost() {

    if ('' == jQuery('#pmpro_sequence_post').val() || undefined != jQuery('#pmpro_sequencesave').attr('disabled'))
        return false; //already processing, ignore this request


    // Disable save button
    jQuery('#pmpro_sequencesave').attr('disabled', 'disabled');
    jQuery('#pmpro_sequencesave').html(pmpro_sequence.lang.saving);

    //pass field values to AJAX service and refresh table above - Timeout is 5 seconds
    jQuery.ajax({
        url: pmpro_sequence.ajaxurl,
        type:'POST',
        timeout:5000,
        dataType: 'html',
        data: {
            action: 'pmpro_sequence_add_post',
            pmpro_sequence_id: jQuery('#pmpro_sequence_id').val(),
            pmpro_sequencepost: jQuery('#pmpro_sequencepost').val(),
            pmpro_sequencedelay: jQuery('#pmpro_sequencedelay').val(),
            pmpro_sequence_addpost_nonce: jQuery('#pmpro_sequence_addpost_nonce').val()
        },
        error: function(data){
            if (! data.success)
                alert(data.error);
        },
        success: function(data){
            if ( ! data.success )
                alert(data.error);
            else
                jQuery('#pmpro_sequence_posts').html(data.result);

        },
        complete: function(){

            // Re-enable save button
            jQuery('#pmpro_sequencesave').html(pmpro_sequence.lang.save);
            jQuery('#pmpro_sequencesave').removeAttr('disabled');

        }
    });
}

function pmpro_sequence_editPost(post_id, delay)
{
    jQuery('#newmeta').focus();
    jQuery('#pmpro_sequencepost').val(post_id).trigger("change");
    jQuery('#pmpro_sequencedelay').val(delay);
    jQuery('#pmpro_sequencesave').html(pmpro_sequence.lang.save);
}

function pmpro_sequence_removePost(post_id)
{
    jQuery('#pmpro_sequencesave').attr('disabled', 'disabled');
    // jQuery('#pmpro_sequencesave').html(pmpro_sequence.lang.saving);

    jQuery.ajax({
        url: pmpro_sequence.ajaxurl,
        type:'POST',
        timeout:5000,
        dataType: 'html',
        data: {
            action: 'pmpro_sequence_rm_post',
            pmpro_sequence_id: jQuery('#pmpro_sequence_id').val(),
            pmpro_seq_post: post_id,
            pmpro_sequence_rmpost_nonce: jQuery('#pmpro_sequence_rmpost_nonce').val()
        },
        error: function(data){
            if (! data.success)
                alert(data.error);
        },
        success: function(data){
            if (! data.success)
                alert(data.error);
            else
                jQuery('#pmpro_sequence_posts').html(data.result);
        },
        complete: function() {
            // Enable the Save button again.
            jQuery('#pmpro_sequencesave').removeAttr('disabled');
        }
    });
}

<!-- Test whether the sequence delay type has been changed. Submit AJAX request to delete existing posts if it has -->
//    jQuery("#pmpro_sequence_delaytype")
//        .change(function() {
function pmpro_sequence_delayTypeChange( sequence_id ) {

    var selected = jQuery('#pmpro_sequence_delaytype').val();
    var current = jQuery('input[name=pmpro_sequence_settings_hidden_delay]').val();

    console.log('Process changes to delayType option');
    console.log('Sequence # ' + sequence_id);
    console.log('delayType: ' + selected);
    console.log('Current: ' + current);

    if (jQuery('#pmpro_sequence_delaytype').val() != jQuery('#pmpro_sequence_settings_hidden_delay').val()) {

        // if (! confirm("Changing the delay type will erase all\n existing posts or pages in the Sequence list.\n\nAre you sure?\n (Cancel if 'No')\n\n"))

        if (!confirm(pmpro_sequence.lang.delay_change_confirmation)) {
            jQuery('#pmpro_sequence_delaytype').val(jQuery.data('#pmpro_sequence_delaytype', 'pmpro_sequence_settings_hidden_delay'));
            jQuery('#pmpro_sequence_delaytype').val(current);
            jQuery('#hidden_pmpro_seq_wipesequence').val(0);
            return false;
        } else {
            jQuery('#hidden_pmpro_seq_wipesequence').val(1);
        }

        jQuery.data('#pmpro_sequence_delaytype', 'pmpro_sequence_settings_delaytype', jQuery('#pmpro_sequence_delaytype').val());

        console.log('Selected: ' + jQuery('#pmpro_sequence_delaytype').val());
        console.log('Current (hidden): ' + jQuery('input[name=pmpro_sequence_settings_hidden_delay]').val());
    }
}

// For the Sequence Settings 'Save Settings' button
function pmpro_sequence_saveSettings( sequence_id ) {

    if (undefined != jQuery('#pmpro_settings_save').attr('disabled'))
        return false;

    // Enable the spinner
    jQuery('div .seq_spinner').show();

    // Disable save button
    jQuery('#pmpro_settings_save').attr('disabled', 'disabled');
    jQuery('#pmpro_settings_save').html(pmpro_sequence.lang.saving);


    jQuery.ajax({
        url: pmpro_sequence.ajaxurl,
        type: 'POST',
        timeout: 5000,
        dataType: 'html',
        data: {
            action: 'pmpro_save_settings',
            pmpro_sequence_settings_nonce: jQuery('#pmpro_sequence_settings_nonce').val(),
            pmpro_sequence_id: sequence_id,
            hidden_pmpro_seq_hidden: isHidden(),
            hidden_pmpro_seq_lengthvisible: showLength(),
            hidden_pmpro_seq_startwhen: jQuery('#pmpro_sequence_startwhen').val(),
            hidden_pmpro_seq_sortorder: jQuery('#hidden_pmpro_seq_sortorder').val(),
            hidden_pmpro_seq_delaytype: jQuery('#hidden_pmpro_seq_delaytype').val(),
            hidden_pmpro_seq_sendnotice: jQuery('#hidden_pmpro_seq_sendnotice').val(),
            hidden_pmpro_seq_noticetime: jQuery('#hidden_pmpro_seq_noticetime').val(),
            hidden_pmpro_seq_noticetemplate: jQuery('#hidden_pmpro_seq_noticetemplate').val(),
            hidden_pmpro_seq_fromname: jQuery('#hidden_pmpro_seq_fromname').val(),
            hidden_pmpro_seq_replyto: jQuery('#hidden_pmpro_seq_replyto').val(),
            hidden_pmpro_seq_excerpt: jQuery('#hidden_pmpro_seq_excerpt').val(),
            hidden_pmpro_seq_subject: jQuery('#hidden_pmpro_seq_subject').val(),
            hidden_pmpro_seq_wipesequence: jQuery('#hidden_pmpro_seq_wipesequence').val()
        },
        error: function(data){

            if (! data.success)
                alert(data.error);
        },
        success: function(data){

            if (! data.success )
                alert(data.error);
            else
            {
                setLabels();

                // Refresh the sequence post list (include the new post.
                if (data.result != '')
                    jQuery('#pmpro_sequence_posts').html(data.result);
            }
        },
        complete: function() {

            // Enable the Save button again.
            jQuery('#pmpro_settings_save').removeAttr('disabled');

            // Reset the text for the 'Save Settings" button
            jQuery('#pmpro_settings_save').html(pmpro_sequence.lang.saveSettings);

            // Disable the spinner again
            jQuery('div .seq_spinner').hide();
        }
    });
}
