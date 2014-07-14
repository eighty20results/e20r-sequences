/**
 * Created by sjolshag on 7/8/14.
 */
console.log('Loading pmpro-sequences.js script');

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

        console.log('Sort Order is: ' + jQuery('#pmpro_sequence_sortorder option:selected').text());

        if ( $('#pmpro_sequence_sendnotice').is(':checked') ) {
            console.log('Show all notice related variables');
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
        });

        /* Save new value for the lengthVisible variable */
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
            $('#pmpro-seq-excerpt-select').slideToggle();
            $('#pmpro-seq-edit-excerp').slideToggle();

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


    })(jQuery);
});

function setLabels()
{

    var delayType = jQuery('#pmpro_sequence_delaytype').val();
    var headerHTML_start = '<th id="pmpro_sequence_delaytype">';
    var headerHTML_end = '</th>';
    var entryHTML_start = '<th id="pmpro_sequence_delayentrytype">';
    var entryHTML_end = '</th>';

    var labelText = 'Not Defined';
    var entryText = 'Not Defined';

    if (delayType == 'byDays')
    {
        labelText = "Delay";
        entryText = "Days to delay";
    }

    if (delayType == 'byDate')
    {
        labelText = "Avail. on";
        entryText = "Release on (YYYY-MM-DD)";
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