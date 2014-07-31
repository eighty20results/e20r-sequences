/**
 * Created by Thomas Sjolshagen of Eighty / 20 Results (c) 2014
 */
//console.log('Loading pmpro-sequences-admin.js script');

jQuery.noConflict();
jQuery(document).ready(function(){
    (function($){

        /* Controls that are reused (optimization) */

        /* Select */
        var $sendAlertCtl   = $('#pmpro_sequence_sendnotice');
        var $sortOrderCtl   = $('#pmpro_sequence_sortorder');
        var $delayCtl       = $('#pmpro_sequence_delaytype');
        var $templCtl       = $('#pmpro_sequence_template');
        var $timeCtl        = $('#pmpro_sequence_noticetime');
        var $dateCtl        = $('#pmpro_sequence_dateformat');

        /* Input */
        var $excerptCtl     = $('#pmpro_sequence_excerpt');
        var $subjCtl        = $('#pmpro_sequence_subject');
        var $fromCtl        = $('#pmpro_sequence_fromname');
        var $replyCtl       = $('#pmpro_sequence_replyto');

        console.log('Sort Order on load: ' + $sortOrderCtl.find('option:selected').val());

        /* Get the current values */
        var $sortOrder = $sortOrderCtl.find('option:selected').val();
        var $sortText = $sortOrderCtl.find('option:selected').text();
        var $delayText = $delayCtl.find('option:selected').text();
        var $delayType = $delayCtl.find('option:selected').val();
        var $templateName = $templCtl.find('option:selected').text();
        var $template = $templCtl.find('option:selected').val();
        var $alertText = $timeCtl.find('option:selected').text();
        var $alertTime = $timeCtl.find('option:selected').val();
        var $dateformatTxt = $dateCtl.find('option:selected').text();
        var $dateformatVal = $dateCtl.find('option:selected').val();

        var $excerpt = $excerptCtl.val();
        var $subject = $subjCtl.val();
        var $fromname = $fromCtl.val();
        var $replyto = $replyCtl.val();

        // console.log('Sort Order is: ' + jQuery('#pmpro_sequence_sortorder option:selected').text());

        if ( $sendAlertCtl.is(':checked') ) {
 //           console.log('Show all notice related variables');
            $('.pmpro-sequence-email').show();
            $('.pmpro-sequence-template').show();
            $('.pmpro-sequence-dateformat').show();
            $('.pmpro-sequence-noticetime').show();
        }

        /** Edit button events **/

        /* Admin clicked the 'Edit' button for the SortOrder settings. Show the select field & hide the "edit" button */
        $('#pmpro-seq-edit-sort').click(function(){
 //           console.log('Edit button for sort order clicked');
            $('#pmpro-seq-edit-sort').slideToggle();
            $('#pmpro-seq-sort-select').slideToggle();
        });

        /* Admin clicked the 'Edit' button for the delayType settings. Show the select field & hide the "edit" button */
        $('#pmpro-seq-edit-delay').click(function(){
 //           console.log('Edit button for delay type clicked');
            $('#pmpro-seq-edit-delay').slideToggle();
            $('#pmpro-seq-delay-select').slideToggle();
        });

        /* Show/Hide the alert template information */
        $sendAlertCtl.click(function(){
 //           console.log('Checkbox to allow sending notice clicked');
            $('#hidden_pmpro_seq_sendnotice').val( this.checked ? 1 : 0 );
            $('.pmpro-sequence-template').slideToggle();
            $('.pmpro-sequence-noticetime').slideToggle();
            $('.pmpro-sequence-email').slideToggle();
        });

        /* Admin clicked the 'Edit' button for the delayType settings. Show the select field & hide the "edit" button */
        $('#pmpro-seq-edit-dateformat').click(function(){
            //           console.log('Edit button for delay type clicked');
            $('#pmpro-seq-edit-dateformat').slideToggle();
            $('#pmpro-seq-dateformat-select').slideToggle();
        });

        /* Save the value for the setting for the 'hide future posts in sequence' checkbox*/
        $('#pmpro_sequence_hidden').click(function(){
  //          console.log('Checkbox to hide upcoming posts changed');
            $('#hidden_pmpro_seq_future').val( this.checked ? 1 : 0 );
        });

        /* Save new value for the lengthVisible variable */
        $('#pmpro_sequence_lengthvisible').click(function(){
  //          console.log('Checkbox to show length of membership notice changed');
            $('#hidden_pmpro_seq_lengthvisible').val( this.checked ? 1 : 0 );
        });

        /* Admin clicked the 'Edit' button for the New Content Notice Template settings. Show the select field & hide the "edit" button */
        $('#pmpro-seq-edit-template').click(function(){
  //          console.log('Edit button for email template selection clicked');
            $('#pmpro-seq-edit-template').slideToggle();
            $('#pmpro-seq-template-select').slideToggle();
        });

        /* Admin clicked the 'Edit' button for the New Content Notice Template settings. Show the select field & hide the "edit" button */
        $('#pmpro-seq-edit-noticetime').click(function(){
 //           console.log('Edit button for email template selection clicked');
            $('#pmpro-seq-edit-noticetime').slideToggle();
            $('#pmpro-seq-noticetime-select').slideToggle();
        });

        $('#pmpro-seq-edit-excerpt').click(function(){
 //           console.log('Edit button for excerpt intro edit field clicked');
            $('#pmpro-seq-edit-excerpt').slideToggle();
            $('#pmpro-seq-excerpt-input').slideToggle();
        });

        $('#pmpro-seq-edit-subject').click(function(){
 //           console.log('Edit button for Subject prefix edit field clicked');
            $('#pmpro-seq-edit-subject').slideToggle();
            $('#pmpro-seq-subject-input').slideToggle();
        });

        $('#pmpro-seq-edit-fromname').click(function(){
 //           console.log('Edit button for email edit field clicked');
            $('#pmpro-seq-email-input').slideToggle();
            $('#pmpro-seq-edit-fromname').slideToggle();
        });

        $('#pmpro-seq-edit-replyto').click(function(){
 //           console.log('Edit button for email edit field clicked');
            $('#pmpro-seq-email-input').slideToggle();
            $('#pmpro-seq-edit-replyto').slideToggle();
        });

        /** Cancel button events **/

        /** Admin clicked the 'Cancel' button for the SortOrder edit settings. Reset
         * the value of the label & select, then hide everything again.
         */
        $('#cancel-pmpro-seq-sort').click(function(){
//            console.log('Cancel button for Sort order was clicked');
            // $('#pmpro_sequence_sortorder').getAttribute('hidden_pmpro_seq_sortorder');
            $('#pmpro-seq-sort-select').slideToggle();
            $('#pmpro-seq-edit-sort').slideToggle();

        });
        /** Admin clicked the 'Cancel' button for the DelayType edit settings. Reset
         * the value of the label & select, then hide everything again.
         */
        $('#cancel-pmpro-seq-delay').click(function(){
//            console.log('Cancel button for Delay type was clicked');
            // $delayCtl.getAttribute('hidden_pmpro_seq_delaytype');
            $('#pmpro-seq-delay-select').slideToggle();
            $('#pmpro-seq-edit-delay').slideToggle();

        });

        /**
         * Admin clicked the 'Cancel' button for the New content alert Template edit settings. Reset
         * the value of the label & select, then hide everything again.
         */
        $('#cancel-pmpro-seq-template').click(function(){
//            console.log('Cancel button to set template was clicked');
            // $('#pmpro_sequence_sortorder').getAttribute('hidden_pmpro_seq_sortorder');
            $('#pmpro-seq-template-select').slideToggle();
            $('#pmpro-seq-edit-template').slideToggle();

        });

        /**
         * Admin clicked the 'Cancel' button for the date format edit settings. Reset
         * the value of the label & select, then hide everything again.
         */
        $('#cancel-pmpro-seq-dateformat').click(function(){
//            console.log('Cancel button to set date format was clicked');
            // $('#pmpro_sequence_dateformat').getAttribute('hidden_pmpro_seq_sortorder');
            $('#pmpro-seq-dateformat-select').slideToggle();
            $('#pmpro-seq-edit-dateformat').slideToggle();

        });

        /**
         * Admin clicked the 'Cancel' button for the New content alert Template edit settings. Reset
         * the value of the label & select, then hide everything again.
         */
        $('#cancel-pmpro-seq-noticetime').click(function(){
//            console.log('Cancel button to set alert time was clicked');
            // $('#pmpro_sequence_sortorder').getAttribute('hidden_pmpro_seq_sortorder');
            $('#pmpro-seq-noticetime-select').slideToggle();
            $('#pmpro-seq-edit-noticetime').slideToggle();

        });

        /** Admin clicked the 'Cancel' button for the SortOrder edit settings. Reset
         * the value of the label & select, then hide everything again.
         */
        $('#cancel-pmpro-seq-excerpt').click(function(){
//            console.log('Cancel button for Excerpt Intro was clicked');
            // $('#pmpro_sequence_sortorder').getAttribute('hidden_pmpro_seq_sortorder');
            $('#pmpro-seq-excerpt-input').slideToggle();
            $('#pmpro-seq-edit-excerpt').slideToggle();

        });

        $('#cancel-pmpro-seq-subject').click(function(){
//            console.log('Cancel button for Subject Intro was clicked');
            // $('#pmpro_sequence_sortorder').getAttribute('hidden_pmpro_seq_sortorder');
            $('#pmpro-seq-subject-input').slideToggle();
            $('#pmpro-seq-edit-subject').slideToggle();

        });

        $('#cancel-pmpro-seq-email').click(function(){
//            console.log('Cancel button for email settings was clicked');

            $('#pmpro-seq-email-input').slideToggle();
            $('#pmpro-seq-edit-replyto').slideToggle();
            $('#pmpro-seq-edit-fromname').slideToggle();

        });

        /** OK button events **/

        $('#ok-pmpro-seq-sort').click(function(){

 //           console.log('OK button for Sort order was clicked');
            var $hCtl = $('#hidden_pmpro_seq_sortorder');

            configSelected(
                $sortOrderCtl,
                $hCtl.val(),
                $('#pmpro-seq-sort-status'),
                $hCtl,
                $('#pmpro-seq-edit-sort'),
                $('#pmpro-seq-sort-select')
            );
/*
            if ( sortOrderCtl.find('option:selected').val() != $sortOrder) {

                // Save the new sortOrder setting
                $sortText = sortOrderCtl.find('option:selected').text();
                $sortOrder = sortOrderCtl.find('option:selected').val();

                $('#pmpro-seq-sort-status').text($sortText);
                $('#hidden_pmpro_seq_sortorder').val($sortOrder);
            }
            // Hide the select info and enable the edit button.
            $('#pmpro-seq-sort-select').slideToggle();
            $('#pmpro-seq-edit-sort').slideToggle();
            */
        });

        $('#ok-pmpro-seq-delay').click(function(){

            var $hCtl = $('#hidden_pmpro_seq_delaytype');

            configSelected(
                $delayCtl,
                $hCtl.val(),
                $('#pmpro-seq-delay-status'),
                $hCtl,
                $('#pmpro-seq-edit-delay'),
                $('#pmpro-seq-delay-select')
            );

            /*
            if ( $delayCtl.find('option:selected').val() != $delayType) {

                // Save the new sortOrder setting
                $delayText = $delayCtl.find('option:selected').text();
                $delayType = $delayCtl.find('option:selected').val();

                $('#pmpro-seq-delay-status').text($delayText);
                $('#hidden_pmpro_seq_delaytype').val($delayType);
            }

            // Hide the select info and enable the edit button.
            $('#pmpro-seq-delay-select').slideToggle();
            $('#pmpro-seq-edit-delay').slideToggle();
            */
        });

        $('#ok-pmpro-seq-template').click(function(){

            var $hCtl = $('#hidden_pmpro_seq_noticetemplate');

            configSelected(
                $templCtl,
                $hCtl.val(),
                $('#pmpro-seq-template-status'),
                $hCtl,
                $('#pmpro-seq-edit-template'),
                $('#pmpro-seq-template-select')
            );

            /*
//            console.log('OK button for template was clicked');
            if ( $('#pmpro-seq-template-status option:selected').val() != $template) {
                // Save the new sortOrder setting
                $templateName = $('#pmpro_sequence_template option:selected').text();
                $template = $('#pmpro_sequence_template option:selected').val();
                $('#pmpro-seq-template-status').text($templateName);
                $('#hidden_pmpro_seq_noticetemplate').val($template);
 //               console.log('Template was changed and is now: ' + $templateName);
            }
            $('#pmpro-seq-template-select').slideToggle();
            $('#pmpro-seq-edit-template').slideToggle();
            */
        });

        $('#ok-pmpro-seq-dateformat').click(function(){

            var $hCtl = $('#hidden_pmpro_seq_dateformat');

            configSelected(
                $dateCtl,
                $hCtl.val(),
                $('#pmpro-seq-dateformat-status'),
                $hCtl,
                $('#pmpro-seq-edit-dateformat'),
                $('#pmpro-seq-dateformat-select')
            );

            /*
            //           console.log('OK button for Sort order was clicked');
            if ( $('#pmpro-seq-dateformat-status option:selected').val() != $dateformatVal) {
                // Save the new sortOrder setting
                // $dateformatTxt = $('#pmpro_sequence_dateformat option:selected').text();
                $dateformatVal = $('#pmpro_sequence_dateformat option:selected').val();
                $('#pmpro-seq-dateformat-status').text('"' + $dateformatVal + '"');
                $('#hidden_pmpro_seq_dateformat').val($dateformatVal);
//                console.log('Sort order was changed and is now: ' + $sortText);
            }
            $('#pmpro-seq-dateformat-select').slideToggle();
            $('#pmpro-seq-edit-dateformat').slideToggle();
            */
        });

        $('#ok-pmpro-seq-noticetime').click(function(){

            var $hCtl = $('#hidden_pmpro_seq_noticetime');

            configSelected(
                $timeCtl,
                $hCtl.val(),
                $('#pmpro-seq-noticetime-status'),
                $hCtl,
                $('#pmpro-seq-edit-noticetime'),
                $('#pmpro-seq-noticetime-select')
            );

            /*
//            console.log('OK button for alert notice time was clicked');
            if ( $('#pmpro-seq-noticetime-status option:selected').val() != $alertTime) {
                // Save the new sortOrder setting
                $alertText = $('#pmpro_sequence_noticetime option:selected').text();
                $alertTime = $('#pmpro_sequence_noticetime option:selected').val();
                $('#pmpro-seq-noticetime-status').text($alertText);
                $('#hidden_pmpro_seq_noticetime').val($alertTime);
//                console.log('Content change notice was changed and is now: ' + $alertText);
            }
            $('#pmpro-seq-noticetime-select').slideToggle();
            $('#pmpro-seq-edit-noticetime').slideToggle();
            */
        });

        $('#ok-pmpro-seq-excerpt').click(function(){

            var $hCtl = $('#hidden_pmpro_seq_excerpt');

            configInput(
                $excerptCtl,
                $hCtl.val(),
                $('#pmpro-seq-excerpt-status'),
                $hCtl,
                $('#pmpro-seq-edit-excerpt'),
                $('#pmpro-seq-excerpt-input')
            );

        });

        $('#ok-pmpro-seq-subject').click(function(){

            var $hCtl = $('#hidden_pmpro_seq_subject');

            configInput(
                $subjCtl,
                $hCtl.val(),
                $('#pmpro-seq-subject-status'),
                $hCtl,
                $('#pmpro-seq-edit-subject'),
                $('#pmpro-seq-subject-input')
            );

            /*
//            console.log('OK button for Subject Intro was clicked');
            if ( $('#pmpro_sequence_subject').val() != $subject) {
                // Save the new excerpt info
                $subject = $('#pmpro_sequence_subject').val();
                $('#hidden_pmpro_seq_subject').val($subject);
                $('#pmpro-seq-subject-status').text('"' + $subject + '"');
//                console.log('Content of Subject Intro was changed and is now: ' + $subject);
            }
            $('#pmpro-seq-subject-input').slideToggle();
            $('#pmpro-seq-edit-subject').slideToggle();
            */
        });

        $('#ok-pmpro-seq-email').click(function(){

            // Declare variables we need/want
            var $newfrom;
            var $newreply;

            // Check whether the settings have been changed
            if ( ( ($newfrom = $('#pmpro_sequence_fromname').val()) != $fromname)  ||
                 ( ($newreply = $('#pmpro_sequence_replyto').val()) != $replyto) )
            {

                $('#hidden_pmpro_seq_fromname').val($newfrom);
                $('#hidden_pmpro_seq_replyto').val($newreply);

                $('#pmpro-seq-fromname-status').text('"' + $newfrom + '"');
                $('#pmpro-seq-replyto-status').text('"' + $newreply + '"');
            }

            // Toogle visibility of related edit buttons and input fields
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
    var lvCtl = jQuery('input#pmpro_sequence_lengthvisible');

    // var lengthVisible = lvCtl.val();
//    console.log('lengthVisible checkbox value: ' + lengthVisible);

    if ( lvCtl.is(":checked"))
    {
//        console.log('lengthVisible setting is checked');
        return lvCtl.val();
    }
    else
        return 0;
}

/**
 *
 * Will update the hidden input field for the setting & toggle visible/invisible controls as needed.
 *
 * @param $selectCtl -- jQuery control for selected item ( jQuery('#id').find('option:selected') )
 * @param $oldValue -- Contains original configured value for this setting
 * @param $statusCtl -- The displayed text for the setting
 * @param $hiddenCtl -- Hidden input field containing actual setting value
 * @param $editBtn -- The 'Edit' button
 * @param $selCtl -- The '<select><option>' (normally hidden unless it's being edited)
 */
function configSelected( $selectCtl, $oldValue, $statusCtl, $hiddenCtl, $editBtn, $selCtl) {

    /* Check whether the setting has changed */
    var $val;

    if ( ($val = $selectCtl.find('option:selected').val()) != $oldValue ) {

        /* Save the new text (for label) */
        var $text = $selectCtl.find('option:selected').text();

        $statusCtl.text($text); // Displayed setting value in label
        $hiddenCtl.val($val); // Set the value='' for the hidden input field
    }

    $selCtl.slideToggle(); // Hide the Input field + OK & Cancel buttons
    $editBtn.slideToggle(); // Show edit button again
}

/**
 * Will update the hidden input field for the specific setting and toggle visible/invisible controls as needed
 *
 * @param $inputCtl -- jQuery control for the Input field
 * @param $oldValue -- Value stored in settings (hidden field). Saved setting.
 * @param $statusCtl -- The displayed text for this setting
 * @param $hiddenCtl -- Hidden input field control (contains setting value)
 * @param $editBtn -- Edit button
 * @param $inpCtl -- The hidden (editable) input, OK and Cancel form entries
 */
function configInput( $inputCtl, $oldValue, $statusCtl, $hiddenCtl, $editBtn, $inpCtl ) {

    var $val;

    // Only update if the new value is different from the current (may not yet be saved) setting.
    if ( ( $val = $inputCtl.val() ) != $oldValue) {

        $hiddenCtl.val($val);
        $statusCtl.text('"' + $val + '"');
    }

    $inpCtl.slideToggle(); // Hide the Input field + OK & Cancel buttons
    $editBtn.slideToggle(); // Show edit button again

}

/* -- Commented out until we support 24/12 hour clock choices
function formatTime($h_24) {
    var $time = $h_24.split(':');

    var $h = $h_24 % 12;
    if ($h === 0) $h = 12;
    return ($h < 10 ? "0" + $h : $h) + ":" + $time[1] + ($h_24 < 12 ? ' AM' : ' PM');
}
*/

function pmpro_sequence_addEntry() {

    var saveBtn = jQuery('#pmpro_sequencesave');

    if ('' == jQuery('#pmpro_sequence_post').val() || undefined != saveBtn.attr('disabled'))
        return false; //already processing, ignore this request

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
            pmpro_sequencepost: jQuery('#pmpro_sequencepost').val(),
            pmpro_sequencedelay: jQuery('#pmpro_sequencedelay').val(),
            pmpro_sequence_addpost_nonce: jQuery('#pmpro_sequence_addpost_nonce').val()
        },
        error: function(data){
            if (data.message != null)
                alert(data.message);
        },
        success: function(data){
            if (data.success)
                jQuery('#pmpro_sequence_posts').html(data.html);

        },
        complete: function(){

            // Re-enable save button
            saveBtn.html(pmpro_sequence.lang.save);
            saveBtn.removeAttr('disabled');

        }
    });
}

function pmpro_sequence_editPost(post_id) {
    var win = window.open('/wp-admin/post.php?post=' + post_id + '&action=edit', '_blank');
    if (win)
        win.focus();
    else
        alert('Your browser settings prevents this action. You need to allow pop-ups');
}

function pmpro_sequence_editEntry(post_id, delay)
{
    jQuery('#newmeta').focus();
    jQuery('#pmpro_sequencepost').val(post_id).trigger("change");
    jQuery('#pmpro_sequencedelay').val(delay);
    jQuery('#pmpro_sequencesave').html(pmpro_sequence.lang.save);
}

function pmpro_sequence_removeEntry(post_id)
{
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
            pmpro_sequence_rmpost_nonce: jQuery('#pmpro_sequence_rmpost_nonce').val()
        },
        error: function(data){
            if (data.message != null)
                alert(data.message);
        },
        success: function(data){
            if (data.success)
                jQuery('#pmpro_sequence_posts').html(data.html);
        },
        complete: function() {
            // Enable the Save button again.
            jQuery('#pmpro_sequencesave').removeAttr('disabled');
        }
    });
}

function pmpro_sequence_delayTypeChange( sequence_id ) {

    var selected = jQuery('#pmpro_sequence_delaytype').val();
    var current = jQuery('input[name=pmpro_sequence_settings_hidden_delay]').val();

//    console.log('Process changes to delayType option');
//    console.log('Sequence # ' + sequence_id);
//    console.log('delayType: ' + selected);
//    console.log('Current: ' + current);

    if (jQuery('#pmpro_sequence_delaytype').val() != jQuery('#pmpro_sequence_settings_hidden_delay').val()) {

        // if (! confirm("Changing the delay type will erase all\n existing posts or pages in the Sequence list.\n\nAre you sure?\n (Cancel if 'No')\n\n"))

        if (!confirm(pmpro_sequence.lang.delay_change_confirmation)) {
            jQuery('#pmpro_sequence_delaytype').val(jQuery.data('#pmpro_sequence_delaytype', 'pmpro_sequence_settings_hidden_delay'));
            jQuery('#pmpro_sequence_delaytype').val(current);
            jQuery('#hidden_pmpro_seq_wipesequence').val(0);
            return false;
        } else
            jQuery('#hidden_pmpro_seq_wipesequence').val(1);

        jQuery.data('#pmpro_sequence_delaytype', 'pmpro_sequence_settings_delaytype', jQuery('#pmpro_sequence_delaytype').val());

        // console.log('Selected: ' + jQuery('#pmpro_sequence_delaytype').val());
        // console.log('Current (hidden): ' + jQuery('input[name=pmpro_sequence_settings_hidden_delay]').val());
    }
}

// For the Sequence Settings 'Save Settings' button
function pmpro_sequence_saveSettings( sequence_id ) {

    var saveBtn = jQuery('#pmpro_settings_save');

    if (undefined != saveBtn.attr('disabled'))
        return false;

    // Enable the spinner
    jQuery('div .seq_spinner').show();

    // Disable save button
    saveBtn.attr('disabled', 'disabled');
    saveBtn.html(pmpro_sequence.lang.saving);


    jQuery.ajax({
        url: pmpro_sequence.ajaxurl,
        type: 'POST',
        timeout: 5000,
        dataType: 'JSON',
        data: {
            action: 'pmpro_save_settings',
            pmpro_sequence_settings_nonce: jQuery('#pmpro_sequence_settings_nonce').val(),
            pmpro_sequence_id: sequence_id,
            hidden_pmpro_seq_future: isHidden(),
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
            hidden_pmpro_seq_dateformat: jQuery('#hidden_pmpro_seq_dateformat').val(),
            hidden_pmpro_seq_subject: jQuery('#hidden_pmpro_seq_subject').val(),
            hidden_pmpro_seq_wipesequence: jQuery('#hidden_pmpro_seq_wipesequence').val()
        },
        error: function(data){

            if (data.message != null)
                alert(data.message);
        },
        success: function(data){

            setLabels();

            // Refresh the sequence post list (include the new post.
            if (data.html != '')
                jQuery('#pmpro_sequence_posts').html(data.html);
        },
        complete: function() {

            // Enable the Save button again.
            saveBtn.removeAttr('disabled');

            // Reset the text for the 'Save Settings" button
            saveBtn.html(pmpro_sequence.lang.saveSettings);

            // Disable the spinner again
            jQuery('div .seq_spinner').hide();
        }
    });
}
