<?php
use E20R\Tools\DBG;

// Set startdate for all users and their sequences.
function e20r_sequence_upgrade_settings_4411()
{
    global $wpdb;

    $levels = array();

    if (function_exists('pmpro_getAllLevels')) {

        $levels = pmpro_getAllLevels(true, true);
    }

    DBG::log("Updating user startdate per sequence for all active users");

    $levels = apply_filters('e20r-sequences-membership-module-get-level-id-array', $levels);
    $seq = apply_filters('get_sequence_class_instance', null);

    if ($seq != null ) {

        foreach( $levels as $level ) {

            $sequences = $seq->sequences_for_membership_level( $level->id);
            $u_sql = $wpdb->prepare("SELECT user_id FROM {$wpdb->pmpro_memberships_users} WHERE membership_id = %d AND status = 'active'", $level->id);

            $users = $wpdb->get_col($u_sql);

            foreach( $sequences as $s_id ) {

                foreach ($users as $u_id ) {

                    $startdate = $seq->get_user_startdate( $u_id, $level->id, $s_id );
                    DBG::log("Setting startdate for user {$u_id} for sequence {$s_id} in level {$level->id}: {$startdate}");
                }
            }
        }
    }
}
add_action('e20r_sequence_update_4.4.11', 'e20r_sequence_upgrade_settings_4411');