/**
 * Copyright 2014 - Thomas Sjolshagen for Eighty / 20 Results by Wicked Strong Chicks, LLC
 *
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you may not use this work except in
 * compliance with the License. You may obtain a copy of the License in the LICENSE file, or at:
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software distributed under the License is
 * distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and limitations under the License.
 *
 */


//function changeDelayType() {
jQuery(document).ready(function () {
    jQuery("#pmpros_sequence_delaytype")
        .change(function(){
            console.log('Process changes to delayType option');
            var selected = jQuery(this).val();
            var current = jQuery('input[name=pmpros_settings_hidden_delay]').val();
            console.log( 'delayType: ' + selected );
            console.log( 'Current: ' + current );

            if ( jQuery(this).val() != jQuery('#pmpros_settings_hidden_delay').val() ) {
                if (! confirm("Changing the delay type will erase all existing posts/pages in the Sequence list.\n\nAre you sure?")) {
                    jQuery(this).val(jQuery.data(this, 'pmpros_settings_hidden_delay'));
                    jQuery(this).val(current);

                    return false;
                };

                jQuery.data(this, 'pmpros_settings_delaytype', jQuery(this).val());
                // TODO: Send Ajax request to delete all existing articles/posts in sequence.
                jQuery.ajax({
                    url: '<?php echo home_url()?>',type:'GET',timeout:5000,
                    dataType: 'html',
                    data: "pmpros_clear_series=1&pmpros_sequence=<?php echo get_the_ID();?>",
                    error: function(xml)
                    {
                        alert('Error clearing Sequence posts [1]');
                    },
                    success: function(responseHTML)
                    {
                        if (responseHTML == 'error')
                        {
                            alert('Error clearing sequence posts');
                        }
                    }
                });
            };

            console.log('Selected: '+ jQuery(this).val());
            console.log('Current (hidden): ' + jQuery('input[name=pmpros_settings_hidden_delay]').val());
        });
});
//}
