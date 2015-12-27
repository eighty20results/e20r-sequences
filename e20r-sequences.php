<?php
namespace E20R\Sequences\Main;
/*
Plugin Name: Eighty / 20 Results Sequences for Paid Memberships Pro
Plugin URI: http://www.eighty20results.com/pmpro-sequences/
Description: Offer serialized (drip feed) content to your PMPro members. Derived from the PMPro Series plugin by Stranger Studios.
Version: 4.2.2
Author: Thomas Sjolshagen
Author Email: thomas@eighty20results.com
Author URI: http://www.eighty20results.com
Text Domain: e20rsequence
Domain Path: /languages
License:

	Copyright 2015 Wicked Strong Chicks, LLC (info@eighty20results.com)
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

/* Define namespaces */
use E20R\Sequences\Main as Main;
use E20R\Sequences\Sequence as Sequence;
use E20R\Sequences\Tools as Tools;

define(__NAMESPACE__ . '\NS', __NAMESPACE__ . '\\');

// use NS as Sequence;

/* Version number */
define('E20R_SEQUENCE_VERSION', '4.2.1');

/* Set the max number of email alerts to send in one go to one user */
define('E20R_SEQUENCE_MAX_EMAILS', 3);

/* Sets the 'hoped for' PHP version - used to display warnings & change date/time calculations if needed */
define('E20R_SEQ_REQUIRED_PHP_VERSION', '5.3');

/* Set the path to the PMPRO Sequence plugin */
define('E20R_SEQUENCE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('E20R_SEQUENCE_PLUGIN_URL', plugin_dir_url(__FILE__));

define('E20R_SEQ_AS_DAYNO', 1);
define('E20R_SEQ_AS_DATE', 2);

define('E20R_SEQ_SEND_AS_SINGLE', 10);
define('E20R_SEQ_SEND_AS_LIST', 20);

define('E20R_DEBUG_SEQ_INFO', 10);
define('E20R_DEBUG_SEQ_WARNING', 100);
define('E20R_DEBUG_SEQ_CRITICAL', 1000);

define('MAX_LOG_SIZE', 3 * 1024 * 1024);

/* Enable / Disable DEBUG logging to separate file */
define('E20R_SEQUENCE_DEBUG', true);
define('E20R_DEBUG_SEQ_LOG_LEVEL', E20R_DEBUG_SEQ_INFO);

/**
 * Include the class for the update checker
 */
require_once(E20R_SEQUENCE_PLUGIN_DIR . "/classes/plugin-updates/plugin-update-checker.php");

/**
 *    Include the class for PMProSequences
 */
/* if (!class_exists("\\E20R\\Sequences\\Sequence")):

    require_once(E20R_SEQUENCE_PLUGIN_DIR . "/classes/class-controller.php");
    require_once(E20R_SEQUENCE_PLUGIN_DIR . "/classes/tools/class-cron.php");

endif;

if (!class_exists("\\E20R\\Sequences\\Tools\\Widgets\\PostWidget")):
    require_once(E20R_SEQUENCE_PLUGIN_DIR . "/classes/widgets/class-postwidget.php");
endif;
*/

/** A debug function */

/**
 *    Couple functions from PMPro in case we don't have them loaded yet.
 */
if (!function_exists("pmpro_getMemberStartdate")):

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
        if (empty($user_id)) {

            global $current_user;
            $user_id = $current_user->ID;
        }

        global $pmpro_startdates;    //for cache

        if (empty($e20r_startdates[$user_id][$level_id])) {

            global $wpdb;

            if (!empty($level_id)) {

                $sqlQuery = $wpdb->prepare(
                    "
						SELECT UNIX_TIMESTAMP( startdate )
						FROM {$wpdb->pmpro_memberships_users}
						WHERE status = %s AND membership_id IN ( %d ) AND user_id = %d
						ORDER BY id LIMIT 1
					",
                    'active',
                    $level_id,
                    $user_id
                );
            } else {
                $sqlQuery = $wpdb->prepare(
                    "
						SELECT UNIX_TIMESTAMP( startdate )
						FROM {$wpdb->pmpro_memberships_users}
						WHERE status = %s AND user_id = %d
						ORDER BY id LIMIT 1
					",
                    'active',
                    $user_id
                );
            }

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

if (!function_exists('e20r_sequences_import_all_PMProSeries')):

    /**
     * Import PMPro Series as specified by the pmpro-sequence-import-pmpro-series filter
     */
    function e20r_sequences_import_all_PMProSeries()
    {

        $importStatus = apply_filters('pmpro-sequence-import-pmpro-series', __return_false());

        // Don't import anything.
        if (__return_false() === $importStatus) {

            return;
        }

        global $wpdb;

        if ((__return_true() === $importStatus) || ('all' === $importStatus)) {

            //Get all of the defined PMPro Series posts to import from this site.
            $series_sql = "
                        SELECT *
                        FROM {$wpdb->posts}
                        WHERE post_type = 'pmpro_series'
                    ";
        } elseif (is_array($importStatus)) {

            //Get the specified list of PMPro Series posts to import
            $series_sql = "
                        SELECT *
                        FROM {$wpdb->posts}
                        WHERE post_type = 'pmpro_series'
                        AND ID IN (" . implode(",", $importStatus) . ")
                    ";
        } elseif (is_numeric($importStatus)) {

            //Get the specified (by Post ID, we assume) PMPro Series posts to import
            $series_sql = $wpdb->prepare(
                "
                        SELECT *
                        FROM {$wpdb->posts}
                        WHERE post_type = 'pmpro_series'
                        AND ID = %d
                    ",
                $importStatus
            );
        }

        $series_list = $wpdb->get_results($series_sql);

        // Series meta: '_post_series' => the series this post belongs to.
        //              '_series_posts' => the posts in the series
        /*
                $format = array(
                    '%s', '%s', '%s', '%s', '%s', '%s', '%s','%s','%s','%s',
                    '%s', '%s', '%s', '%s', '%s', '%s', '%d','%s','%d','%s',
                    '%s', '%d'
                );
        */
        // Process the list of sequences
        foreach ($series_list as $series) {

            $wp_error = true;

            $seq_id = wp_insert_post(array(
                'post_author' => $series->post_author,
                'post_date' => date_i18n('Y-m-d H:i:s'),
                'post_date_gmt' => date_i18n('Y-m-d H:i:s'),
                'post_content' => $series->post_content,
                'post_title' => $series->post_title,
                'post_excerpt' => $series->post_excerpt,
                'post_status' => $series->post_status,
                'comment_status' => $series->comment_status,
                'ping_status' => $series->ping_status,
                'post_password' => $series->post_password,
                'post_name' => $series->post_name,
                'to_ping' => $series->to_ping,
                'pinged' => $series->pinged,
                'post_modified' => $series->post_modified,
                'post_modified_gmt' => $series->post_modified_gmt,
                'post_content_filtered' => $series->post_content_filtered,
                'post_parent' => $series->post_parent,
                'guid' => $series->guid,
                'menu_order' => $series->menu_order,
                'post_type' => 'pmpro_sequence',
                'post_mime_type' => $series->post_mime_type,
                'comment_count' => $series->comment_count
            ),
                $wp_error);

            if (!is_wp_error($seq_id)) {

                $post_list = get_post_meta($series->ID, '_series_posts', true);

                $seq = apply_filters('get_sequence_class_instance', null);
                $seq->init($seq_id);

                foreach ($post_list as $seq_member) {

                    if (!$seq->addPost($seq_member->id, $seq_member->delay)) {
                        return new \WP_Error('sequence_import',
                            sprintf(__('Could not complete import for series %s', "e20rsequence"), $series->post_title), $seq->getError());
                    }
                } // End of foreach

                // Save the settings for this Drip Feed Sequence
                $seq->save_sequence_meta();

                // update_post_meta( $seq_id, "_sequence_posts", $post_list );
            } else {

                return new \WP_Error('db_query_error',
                    sprintf(__('Could not complete import for series %s', "e20rsequence"), $series->post_title), $wpdb->last_error);

            }
        } // End of foreach (DB result)
    }
endif;

if (!function_exists('e20r_sequences_import_all_PMProSequence')):
    /**
     * Convert PMPro Sequences metadata
     */
    function e20r_sequences_import_all_PMProSequence()
    {
        // TODO: Implement e20r_sequences_import_all_PMProSequence(): Convert pmpro_sequence metadata to e20r_sequence
        $sequence = apply_filters('get_sequence_class_instance', null);

        if (class_exists('PMProSequence')) {

            $sequence->dbg_log("convert_pmpro_sequence() - PMPro Sequences is still active. Can't convert!");
            return;
        }
    }
endif;

if (!function_exists('e20r_sequence_loader')) {
    function e20r_sequence_loader($class_name)
    {

        if (false === stripos($class_name, 'sequence')) {
            return;
        }

        $parts = explode('\\', $class_name);

        $base_path = plugin_dir_path(__FILE__) . "classes";
        $name = strtolower($parts[(count($parts) - 1)]);

        $types = array('shortcodes', 'tools', 'widgets');

        foreach ($types as $type) {

            if ( false !== stripos($name, "controller")) {
                $dir = "{$base_path}";
            } else {
                $dir = "{$base_path}/{$type}";
            }

            if (file_exists("{$dir}/class-{$name}.php")) {

                require_once("{$dir}/class-{$name}.php");
            }
/*            else {
                error_log("e20r_sequence_loader() - {$dir}/class-{$name}.php not found!");
            }
*/
        }
    }
}
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
function in_array_r($needle, $haystack, $strict = false)
{

    foreach ($haystack as $item) {

        if (($strict ? $item === $needle : $item == $needle) ||
            (is_array($item) && in_array_r($needle, $item, $strict))
        ) {

            return true;
        }
    }

    return false;
}

function in_object_r($key = null, $value = null, $object, $strict = false)
{

    if ($key == null) {

        trigger_error("in_object_r expects a key as the first parameter", E_USER_WARNING);
        return false;
    }

    if ($value == null) {

        trigger_error("in_object_r expects a value as the second parameter", E_USER_WARNING);
        return false;
    }

    if (!is_object($object) && (is_array($object))) {
        $object = (object)$object;
    }

    foreach ($object as $k => $v) {

        if ((!is_object($v)) && (!is_array($v))) {

            if (($k == $key) && ($strict ? $v === $value : $v == $value)) {
                return true;
            }
        } else {
            return in_object_r($key, $value, $v, $strict);
        }
    }

    return false;
}


try {

    spl_autoload_register("E20R\\Sequences\\Main\\e20r_sequence_loader");

    $sequence = new Sequence\Controller();
    $cron = new Tools\Cron();
    $er = new Tools\E20RError();

    $sequence->load_actions();

} catch (\Exception $e) {
    error_log("PMProSequence startup: Error initializing the specified sequence...: " . $e->getMessage());
}

register_activation_hook(__FILE__, array(&$sequence, 'activation'));
register_deactivation_hook(__FILE__, array(&$sequence, 'deactivation'));

$plugin_updates = \PucFactory::buildUpdateChecker(
    'https://eighty20results.com/protected-content/e20r-sequences/metadata.json',
    __FILE__,
    'e20r-sequences'
);