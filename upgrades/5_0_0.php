<?php
use E20R\Utilities\Utilities;
use E20R\Sequences\Sequence\Controller;

/**
 * Rename all pmpro_sequence* options to e20r_sequence*
 */
function e20r_sequence_upgrade_settings_500()  {
	
	//_pmpro_sequence_post_belongs_to -> _e20r_sequence_post_belongs_to
	// _pmpro_sequence_ -> _e20r_sequence_
	// pmpro_sequence_notices -> e20r_sequence_notices
}
add_action('e20r_sequence_update_5.0.0', 'e20r_sequence_upgrade_settings_500');