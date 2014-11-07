<?php
/*
Plugin Name: PMPro Sequence
Plugin URI: http://www.eighty20results.com/pmpro-sequence/
Description: Offer serialized (drip feed) content to your PMPro members. Derived from the PMPro Series plugin by Stranger Studios.
Version: 1.4
Author: Thomas Sjolshagen
Author Email: thomas@eighty20results.com
Author URI: http://www.eighty20results.com
Text Domain: pmprosequence
Domain Path: /languages
License:

	Copyright 2014 Thomas Sjolshagen (thomas@eighty20results.com)
	Copyright 2013 Stranger Studios (jason@strangerstudios.com)

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

/* Version number */
define('PMPRO_SEQUENCE_VERSION', '2.0');

/* Enable / Disable DEBUG logging to separate file */
define('PMPRO_SEQUENCE_DEBUG', true);

/* Set the max number of email alerts to send in one go to one user */
define('PMPRO_SEQUENCE_MAX_EMAILS', 3);

/* Sets the 'hoped for' PHP version - used to display warnings & change date/time calculations if needed */
define('PMPRO_SEQ_REQUIRED_PHP_VERSION', '5.2.2');

/* Set the path to the PMPRO Sequence plugin */
define('PMPRO_SEQUENCE_PLUGIN_DIR', plugin_dir_path(__FILE__));

define('PMPRO_SEQ_AS_DAYNO', 1);
define('PMPRO_SEQ_AS_DATE', 2);


/**
  *	Include the class for PMProSequences
  */
if (! class_exists( 'PMProSequence' )):

    require_once( PMPRO_SEQUENCE_PLUGIN_DIR . "/classes/class.PMProSequence.php");
	require_once( PMPRO_SEQUENCE_PLUGIN_DIR ."/scheduled/crons.php");

endif;

if ( ! class_exists( 'PMProSeqRecentPost' )):
	require_once(PMPRO_SEQUENCE_PLUGIN_DIR . "/classes/class.PMProSeqRecentPost.php");
endif;


/** A debug function */
if ( ! function_exists( 'dbgOut' ) ):
	/**
	 * Debug function (if executes if DEBUG is defined)
	 *
	 * @param $msg -- Debug message to print to debug log.
	 */
	function dbgOut( $msg )
	{
		$dbgPath = plugin_dir_path( __FILE__ ) . DIRECTORY_SEPARATOR . 'debug';

		if (PMPRO_SEQUENCE_DEBUG)
		{

			if (!  file_exists( $dbgPath )) {
				// Create the debug logging directory
				mkdir( $dbgPath, 0750 );

				if (! is_writable( $dbgPath )) {
					error_log('PMPro Sequence: Debug log directory is not writable. exiting.');
					return;
				}
			}

			$dbgFile = $dbgPath . DIRECTORY_SEPARATOR . 'sequence_debug_log-' . date('Y-m-d', current_time("timestamp") ) . '.txt';

			if ( ($fh = fopen($dbgFile, 'a')) !== false ) {

				// Format the debug log message
				$dbgMsg = '(' . date('d-m-y H:i:s', current_time( "timestamp" ) ) . ') -- '. $msg;

				// Write it to the debug log file
				fwrite( $fh, $dbgMsg . "\r\n" );
				fclose( $fh );
			}
			else
				error_log('PMPro Sequence: Unable to open debug log');
		}
	}

endif;

/**
  *	Couple functions from PMPro in case we don't have them loaded yet.
  */
if( ! function_exists("pmpro_getMemberStartdate") ):

    /**
     *
     * Get the member's start date (either generally speaking or for a specific level)
     *
     * @param $user_id (int) - ID of user who's start date we're finding
     * @param $level_id (int) - ID of the level to find the start date for (optional)
     *
     * @returns mixed - The start date for this user_id at the specific level_id (or in general)
     */
    function pmpro_getMemberStartdate($user_id = NULL, $level_id = 0)
	{		
		if(empty($user_id))
		{
			global $current_user;
			$user_id = $current_user->ID;
		}

		global $pmpro_startdates;	//for cache

		if(empty($pmpro_startdates[$user_id][$level_id]))
		{			
			global $wpdb;
			
			if(!empty($level_id))
				$sqlQuery = $wpdb->prepare(
					"
						SELECT UNIX_TIMESTAMP(startdate)
						FROM {$wpdb->pmpro_memberships_users}
						WHERE status = %s AND membership_id IN ( %d ) AND user_id = %d
						ORDER BY id LIMIT 1
					",
					'active',
					$level_id,
					$user_id
				);
			else
				$sqlQuery = $wpdb->prepare(
					"
						SELECT UNIX_TIMESTAMP(startdate)
						FROM {$wpdb->pmpro_memberships_users}
						WHERE status = %s AND user_id = %d
						ORDER BY id LIMIT 1
					",
					'active',
					$user_id
				);
				
			$startdate = apply_filters("pmpro_member_startdate", $wpdb->get_var($sqlQuery), $user_id, $level_id);
			
			$pmpro_startdates[$user_id][$level_id] = $startdate;
		}
		
		return $pmpro_startdates[$user_id][$level_id];
	}

    /**
     * Calculate the # of days since the membership level (or membership in general) was started for a specific user_id
     *
     * @param int $user_id -- user_id to calculate # of days since membership start for
     * @param int $level_id -- level_id to calculate the # of days for
     * @return int -- Number of days since user_id started their membership (at this level)
     *//*
    function pmpro_getMemberDays($user_id = NULL, $level_id = 0)
	{
		if(empty($user_id))
		{
			global $current_user;
			$user_id = $current_user->ID;
		}
		
		global $pmpro_member_days;

		if(empty($pmpro_member_days[$user_id][$level_id]))
		{
			// Get the timestamp representing the start date for the specific user_id.
			$startdate = pmpro_getMemberStartdate($user_id, $level_id);

			// Check that there is a start date at all
			if(empty($startdate))
				$days = 0;
			else
				$days = pmpro_seq_datediff($startdate, current_time('timestamp'));

			$pmpro_member_days[$user_id][$level_id] = $days;
		}
		
		return $pmpro_member_days[$user_id][$level_id];
	}
*/
endif;

if ( ! function_exists ('pmpro_seq_import_series') ):

    function pmpro_seq_import_series() {

        global $wpdb;

        //Get all of the defined series on this site
        $sql = $wpdb->prepare(
            "
	                SELECT *
	                FROM {$wpdb->posts}
	                WHERE post_type = 'pmpro_series'
            	"
        );

        $series = $wpdb->get_results( $sql );

        // Process the list of sequences
        foreach ( $series as $s ) {
            dbgOut("Series # {$s->ID}: " . get_the_title( $s->ID ) );

        }
    }
endif;

/**
 * Recursively iterate through an array (of, possibly, arrays) to find the needle in the haystack
 *
 * Thanks to @elusive via http://stackoverflow.com/questions/4128323/in-array-and-multidimensional-array
 *
 * @param $needle -- Comparison value (like the standard PHP function in_array()
 * @param $haystack -- Array (or array of arrays) to check
 * @param bool $strict -- Whether to do strict type-checking
 *
 * @return bool
 */
function in_array_r( $needle, $haystack, $strict = false ) {

    foreach ( $haystack as $item ) {

        if ( ( $strict ? $item === $needle : $item == $needle ) ||
             ( is_array( $item) && in_array_r( $needle, $item, $strict ) ) ) {

            return true;
        }
    }

    return false;
}

function in_object_r( $key = null, $value = null, $object, $strict = false ) {

    if ( $key == null ) {

        trigger_error("in_object_r expects a key as the first parameter", E_USER_WARNING);
        return false;
    }

    if ( $value == null ) {

        trigger_error("in_object_r expects a value as the second parameter", E_USER_WARNING);
        return false;
    }

    if ( ! is_object( $object ) ) {
        $object = (object) $object;
    }

    foreach ( $object as $k => $v ) {

        if ( ( ! is_object( $v ) ) && ( ! is_array( $v ) ) ) {

            if ( ( $k == $key ) && ( $strict ? $v === $value : $v == $value ) ) {
                return true;
            }
        }
        else {
            return in_object_r( $key, $value, $v, $strict );
        }
    }

    return false;
}


try {
    dbgOut("Startup - Loading actions, widgets & shortcodes");
    $sequence = new PMProSequence();
    $sequence->load_actions();
}
catch ( Exception $e ) {
    dbgOut( "PMProSequence startup: Error initializing the specified sequence...: " . $e->getMessage() );
}

register_activation_hook( __FILE__, array( &$sequence, 'activation' ) );
register_deactivation_hook( __FILE__, array( &$sequence, 'deactivation' ) );