<?php
namespace E20R\Sequences\Main;
/*
License:

	Copyright 2014-2016 Eighty / 20 Results by Wicked Strong Chicks, LLC (info@eighty20results.com)

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
use E20R\Sequences\Modules as Modules;
use E20R\Tools as E20RTools;

define(__NAMESPACE__ . '\NS', __NAMESPACE__ . '\\');

// use NS as Sequence;

/* Set the max number of email alerts to send in one go to one user */
define('E20R_SEQUENCE_MAX_EMAILS', 3);

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

                    if (!$seq->add_post($seq_member->id, $seq_member->delay)) {
                        return new \WP_Error('sequence_import',
                            sprintf(__('Could not complete import of post id %d for series %s', "e20rsequence"), $seq_member->id, $series->post_title), $seq->getError());
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

            E20RTools\DBG::log("PMPro Sequences is still active. Can't convert!");
            return;
        }
    }
endif;

if (!function_exists('e20r_sequence_loader')) {
    function e20r_sequence_loader($class_name)
    {
        if (false === stripos($class_name, 'sequence') && ( false === stripos($class_name, 'e20r'))) {
            return;
        }

        $parts = explode('\\', $class_name);

        $base_path = plugin_dir_path(__FILE__) . "classes";
        $name = strtolower($parts[(count($parts) - 1)]);

        $types = array('shortcodes', 'tools', 'widgets', 'license');

        foreach ($types as $type) {

            if ( false !== stripos($name, "controller") || ( false !== stripos($name, 'views'))) {
                $dir = "{$base_path}";
            } else {
                $dir = "{$base_path}/{$type}";
            }

            if (file_exists("{$dir}/class-{$name}.php")) {

                require_once("{$dir}/class-{$name}.php");
            }

            // For the license class.
            if (file_exists( "{$dir}/class.{$name}.php")) {
	            require_once("{$dir}/class.{$name}.php");
            }
/*
            else {
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

    global $converting_sequence;
    $converting_sequence = false;

	$sequence = new Sequence\Controller();

    E20RTools\DBG::set_plugin_name('e20r-sequences');

    register_activation_hook(E20R_SEQUENCE_PLUGIN_FILE, array($sequence, 'activation'));
    register_deactivation_hook(E20R_SEQUENCE_PLUGIN_FILE, array($sequence, 'deactivation'));

	add_action('plugins_loaded', array( $sequence, 'load_actions' ), 5 );

} catch (\Exception $e) {
    error_log("E20R Sequences startup: Error initializing the specified sequence...: " . $e->getMessage());
}

