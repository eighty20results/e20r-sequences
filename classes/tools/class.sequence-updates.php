<?php
/*
  License:

	Copyright 2014-2016 Eighty / 20 Results by Wicked Strong Chicks, LLC (thomas@eighty20results.com)

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

namespace E20R\Sequences\Tools;

use E20R\Utilities\Utilities;

class Sequence_Updates
{
    private $current_version;
    private static $instance = null;

    public function __construct()
    {
    	$utils = Utilities::get_instance();
        $utils->log("Loading the sequence_update class");

        if ( ! is_null( self::$instance ) ) {

            $utils->log("Error loading the sequence update class");
            $error_message = sprintf(__("Attempted to load a second instance of this singleton class (%s)", "e20r-sequences"),
                get_class($this)
            );

            $utils->log($error_message);
            wp_die( $error_message);

        }

        self::$instance = $this;
    }

    public static function init()
    {
	    $utils = Utilities::get_instance();
        $utils->log("Getting plugin data for E20R Sequences");

        $update_functions = array(
            '4.4.0', '4.4.11'
        );

        if (function_exists('get_plugin_data'))
            $plugin_status = get_plugin_data(E20R_SEQUENCE_PLUGIN_DIR . 'class.e20r-sequences.php', false, false);

        $version = ( !empty($plugin_status['Version']) ? $plugin_status['Version'] : E20R_SEQUENCE_VERSION );

        $me = self::get_instance();
        $me->set_version($version);

        $is_updated = get_option('e20r-sequence-updated', array() );
        $a_versions = array_keys($is_updated);
        $a_versions = array_unique(array_merge($update_functions, $a_versions));

        if (false === in_array( $version, $a_versions ) ) {

            $utils->log("Appending {$version} to list of stuff to upgrade");
            $is_updated[$version] = false;
            $a_versions[] = $version;
        }

        foreach($is_updated as $upd_ver => $status) {

            $upgrade_file = str_replace('.', '_', $upd_ver);

            if (!empty($upd_ver) &&
                (file_exists(E20R_SEQUENCE_PLUGIN_DIR . "upgrades/{$upgrade_file}.php") &&
                    (false == $status)  ) ) {

                $utils->log("Adding update action for {$upd_ver}");
                add_action("e20r_sequence_before_update_{$upd_ver}", array(self::get_instance(), 'e20r_sequence_before_update'));
                add_action("e20r_sequence_update_{$upd_ver}", array(self::get_instance(), 'e20r_sequence_update'));
                add_action("e20r_sequence_after_update_{$upd_ver}", array(self::get_instance(), 'e20r_sequence_after_update'));
            }
        }

        update_option('e20r-sequence-updated', $is_updated, 'no');
    }

    public function get_version()
    {
        return isset( $this->current_version ) ? $this->current_version : null;
    }

    public function set_version( $version )
    {
        $this->current_version = $version;
    }

    public static function get_instance()
    {
	    $utils = Utilities::get_instance();
	    
        if ( is_null( self::$instance ) ) {
        	
            $utils->log("Instantiating the " . get_class(self::$instance) . " class");
            self::$instance = new self;
        }

        return self::$instance;
    }

    static public function update()
    {
	    $utils = Utilities::get_instance();
        $su_class = self::get_instance();
        $version = $su_class->get_version();

        if (empty($version)) {
            return;
        }

        $is_updated = get_option('e20r-sequence-updated', array() );

        $processed = array_keys($is_updated);

        if (!array_key_exists( $version, $is_updated )) {

            $utils->log("Appending {$version} to list of stuff to upgrade");
            $is_updated[$version] = false;
        }

        foreach ($is_updated as $update_ver => $status ) {

            $upgrade_file = str_replace('.', '_', $update_ver);

            if (file_exists(E20R_SEQUENCE_PLUGIN_DIR . "upgrades/{$upgrade_file}.php") && (false == $status)  ) {

                require_once(E20R_SEQUENCE_PLUGIN_DIR . "upgrades/{$upgrade_file}.php");

                $utils->log("Running pre (before) update action for {$update_ver}");
                do_action("e20r_sequence_before_update_{$update_ver}");

                // FIXME: Will always run, every time the plugin loads (prevent this!)
                $utils->log("Running update action for {$update_ver}");
                do_action("e20r_sequence_update_{$update_ver}");

                $utils->log("Running clean-up (after) update action for {$update_ver}");
                do_action("e20r_sequence_after_update_{$update_ver}");

                if (array_key_exists($v, $is_updated)) {
                    $is_updated[$v] = true;
                }
            }
        }

        update_option('e20r-sequence-updated', $is_updated, 'no');
    }

    /**
     * Stub function
     */
    public function e20r_sequence_before_update() {
        return;
    }

    /**
     * Stub function
     */
    public function e20r_sequence_after_update() {
        return;
    }

    /**
     * Stub function
     */
    public function e20r_sequence_update() {
        return;
    }
}