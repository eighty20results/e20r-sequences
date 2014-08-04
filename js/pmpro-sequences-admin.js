/**
 * Created by Thomas Sjolshagen of Eighty / 20 Results (c) 2014
 */
//console.log('Loading pmpro-sequences-admin.js script');

jQuery.noConflict();
jQuery(document).ready(function(){
    (function($){

        jQuery('div#pmpro-seq-error').hide();

        /* Controls that are reused (optimization) */

        /* Select */
        var $sendAlertCtl   = $('#pmpro_sequence_sendnotice');
        var $sortOrderCtl   = $('#pmpro_sequence_sortorder');
        var $delayCtl       = $('#pmpro_sequence_delaytype');
        var $showDelayCtl   = $('#pmpro_sequence_showdelayas');
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
        var $showasText = $showDelayCtl.find('option:selected').text();
        var $showasType = $showDelayCtl.find('option:selected').val();
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

        if ( $delayCtl.find('option:selected').val() == 'byDate') {
            delayAsChoice( 'hide' );
        }

        manageDelayLabels( $delayCtl.val() );

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
        /* Also need to decide whether the 'List delay as' editable settings need to be shown.
        *  Only show the edit controls for the 'list delay as' if the _current_ 'Delay type' value is or gets set to ('byDays') (i.e. 'delayType == byDays')
         *
        * */
        $('#pmpro-seq-edit-delay').click(function(){

            editDelaySettings();
        });

        $('#pmpro-seq-edit-showdelayas').click(function(){

            editDelaySettings();
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

        /** The Email and Name for the "From" line **/
        $('#pmpro-seq-edit-fromname').click(function(){
 //           console.log('Edit button for email edit field clicked');
            $('#pmpro-seq-email-input').slideToggle();
            $('#pmpro-seq-edit-fromname').slideToggle();
            $('#pmpro-seq-edit-replyto').slideToggle();
        });

        $('#pmpro-seq-edit-replyto').click(function(){
 //           console.log('Edit button for email edit field clicked');
            $('#pmpro-seq-email-input').slideToggle();
            $('#pmpro-seq-edit-replyto').slideToggle();
            $('#pmpro-seq-edit-fromname').slideToggle();
        });
        /** Done processing Email and Name for the "From" line **/

        /**
         *
         *
         * Cancel button events
         *
         *
         **/

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

            manageDelayLabels( $delayCtl.val() );

            $('#pmpro-seq-delay-select').hide();
            $('#pmpro-seq-showdelayas-select').hide();

            $('#pmpro-seq-edit-delay').show();
            $('#pmpro-seq-edit-showdelayas').show();
            $('#pmpro-seq-delay-btns').hide();

            setDelayEditBtns();
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
        });

        $('#ok-pmpro-seq-delay').click(function(){

            var $hCtl1 = $('#hidden_pmpro_seq_delaytype');
            var $hCtl2 = $('#hidden_pmpro_seq_showdelayas');

            configSelected(
                $delayCtl,
                $hCtl1.val(),
                $('#pmpro-seq-delay-status'),
                $hCtl1,
                $('#pmpro-seq-edit-delay'),
                $('#pmpro-seq-delay-select')
            );

            configSelected(
                $showDelayCtl,
                $hCtl2.val(),
                $('#pmpro-seq-showdelayas-status'),
                $hCtl2,
                $('#pmpro-seq-edit-showdelayas'),
                $('#pmpro-seq-showdelayas-select')
            );

            jQuery('#pmpro-seq-showdelayas').css('margin-top', '0px');
            $('#pmpro-seq-delay-btns').hide();

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


/**
 * Set the pmpro_seq_error element in the Sequence Posts meta box
 */

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

/**
 *
 * Check whether to hide all future posts for the sequence.
 *
 * @returns {0|1} -
 */
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

    $selCtl.hide(); // Hide the Input field + OK & Cancel buttons
    $editBtn.show(); // Show edit button again
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

    $inpCtl.hide(); // Hide the Input field + OK & Cancel buttons
    $editBtn.show(); // Show edit button again

}

function editDelaySettings() {
    console.log('Edit button for delay type clicked');

    jQuery('#pmpro-seq-edit-delay').hide();
    jQuery('#pmpro-seq-edit-showdelayas').hide();
    jQuery('#pmpro-seq-delay-select').show();

    console.log('Show the select boxes for the delay type and the "show availability as" variables');
    manageDelayEditCtrls( jQuery('#pmpro_sequence_delaytype').val() );

}

function manageDelayLabels( $currentDelayType ) {

    console.log('In manageDelayLabels()');

    if ($currentDelayType == 'byDays') {
        jQuery('#pmpro-seq-showdelayas').show(); // Show
        jQuery('#pmpro-seq-showdelayas').css('margin-top', '0px');
        jQuery('#pmpro-seq-edit-showdelayas').show(); // Show
    }
    else {
        jQuery('#pmpro-seq-showdelayas').hide(); // Hide
        jQuery('#pmpro-seq-edit-showdelayas').hide(); // Hide
    }
}

function manageDelayEditCtrls( $currentDelayType ) {
    console.log('In manageDelayEditCtrls()');

    if ($currentDelayType == 'byDays') {
        jQuery('#pmpro-seq-edit-showdelayas').hide(); // Hide
        jQuery('#pmpro-seq-showdelayas').show(); // Show
        jQuery('#pmpro-seq-showdelayas').css('margin-top', '10px');

        setDelayEditBtns();

        jQuery('#pmpro-seq-showdelayas-select').show() //Show
    }

    if ($currentDelayType == 'byDate') {
        console.log('Showing select controls');
        jQuery('#pmpro-seq-edit-showdelayas').show(); // Show
        jQuery('#pmpro-seq-showdelayas').hide(); // Hide

        setDelayEditBtns();

        jQuery('#pmpro-seq-showdelayas-select').hide() // hide
    }
}

function setDelayEditBtns() {
    console.log('In setDelayEditBtns()');

    if ( jQuery('#pmpro-seq-edit-delay').is(':visible') ||
        jQuery('#pmpro-seq-edit-showdelayas').is(':visible') ) {
        console.log('Edit button is visible');
        jQuery('pmpro-seq-delay-btns').hide(); // Show
    } else {
        console.log('Edit button is hidden');
        jQuery('#pmpro-seq-delay-btns').show(); // Hide
    }

}

function delayAsChoice( $visibility ) {

    // TODO: (done?) This needs to take both the current value of the delayType and the edit visibility of delayType into account

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


    /*
    // Checking whether the user set the value to 'byDays'
    if ( ( jQuery('#pmpro_sequence_delaytype').val() == 'byDays' ) &&
        (! jQuery('#pmpro_sequence_delaytype').is(':visible')) )
    {
        console.log('Make "days" visible');
        jQuery('#pmpro-seq-edit-showdelayas').hide();
        jQuery('#pmpro-seq-showdelayas-select').show();
    }
    else {
    }
    */
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

            console.log('Return value for error: ' + data.message);

            if (data.message != null) {
                alert(data.message);
                pmpro_seq_setErroMsg(data.message);
            }
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
            if (data.message != null) {
                alert(data.message);
                pmpro_seq_setErroMsg(data.message);
            }

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

    var dtCtl = jQuery('#pmpro_sequence_delaytype');

    var selected = dtCtl.val();
    var current = jQuery('input[name=pmpro_sequence_settings_hidden_delay]').val();

//    console.log('Process changes to delayType option');
//    console.log('Sequence # ' + sequence_id);
//    console.log('delayType: ' + selected);
//    console.log('Current: ' + current);

    if (dtCtl.val() != jQuery('#pmpro_sequence_settings_hidden_delay').val()) {

        if (!confirm(pmpro_sequence.lang.delay_change_confirmation)) {
            dtCtl.val( jQuery.data('delayType') );
            dtCtl.val(current);
            jQuery('#hidden_pmpro_seq_wipesequence').val(0);

            return false;
        } else
            jQuery('#hidden_pmpro_seq_wipesequence').val(1);

        if (dtCtl.val() == 'byDate')
            delayAsChoice( 'hide' );
        else if (dtCtl.val() == 'byDays')
            delayAsChoice( 'show' );

        console.log('Selected: ' + dtCtl.val());
        // console.log('Current (hidden): ' + jQuery('input[name=pmpro_sequence_settings_hidden_delay]').val());

        jQuery.data('delayType', dtCtl.val() );

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
            hidden_pmpro_seq_showdelayas: jQuery('#hidden_pmpro_seq_showdelayas').val(),
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
            if (data.message != null) {
                alert(data.message);
                pmpro_seq_setErroMsg(data.message);
            }
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
