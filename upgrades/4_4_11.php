<?php
use E20R\Utilities\Utilities;
use E20R\Sequences\Sequence\Controller;

// Set startdate for all users and their sequences.
function e20r_sequence_upgrade_settings_4411()
{
    global $wpdb;

    $levels = array();
	$utils= Utilities::get_instance();
	
    if (function_exists('pmpro_getAllLevels')) {

        $levels = pmpro_getAllLevels(true, true);
    }
	
	$utils->log("Updating user startdate per sequence for all active users");

    $levels = apply_filters('e20r-sequences-membership-module-get-level-id-array', $levels);
    $sequence = Controller::get_instance();

    if ($sequence != null ) {

        foreach( $levels as $level ) {

            $sequences = $sequence->sequences_for_membership_level( $level->id);
            $u_sql = $wpdb->prepare("SELECT mu.user_id FROM {$wpdb->pmpro_memberships_users} AS mu WHERE mu.membership_id = %d AND mu.status = 'active'", $level->id);

            $users = $wpdb->get_col($u_sql);

            foreach( $sequences as $seq_id ) {

                foreach ($users as $user_id ) {

                    $startdate = $sequence->get_user_startdate( $user_id, $level->id, $seq_id );
	                $utils->log("Setting startdate for user {$user_id} for sequence {$seq_id} in level {$level->id}: {$startdate}");
                }
            }
        }
    }
}
add_action('e20r_sequence_update_4.4.11', 'e20r_sequence_upgrade_settings_4411');