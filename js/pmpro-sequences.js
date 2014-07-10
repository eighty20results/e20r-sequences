/**
 * Created by sjolshag on 7/8/14.
 */
console.log('Loading pmpro-sequences.js script');

jQuery.noConflict();
jQuery(document).ready(function(){
    (function($){
        /* Get the current sortOrder values */
        var $sortOrder = jQuery('#pmpro_sequence_sortorder option:selected').val();
        var $sortText = jQuery('#pmpro_sequence_sortorder option:selected').text();

        console.log('Sort Order is: ' + jQuery('#pmpro_sequence_sortorder option:selected').text());

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
            if ( $('#pmpro-seq-delay-status option:selected').val != $sortOrder) {
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

    })(jQuery);
});