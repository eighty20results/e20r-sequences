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

use E20R\Sequences\Tools\E20RError as E20RError;
use E20R\Tools as E20RTools;

class e20rSequenceUpdates
{
    private $current_version;
    private static $_this = null;

    public function __construct()
    {
        E20RTools\DBG::log("Loading the sequence update class");

        if ( null !== self::$_this ) {

            E20RTools\DBG::log("Error loading the sequence update class");
            $error_message = sprintf(__("Attempted to load a second instance of this singleton class (%s)", "e20rsequence"),
                get_class($this)
            );

            error_log($error_message);
            wp_die( $error_message);

        }

        self::$_this = $this;
    }

    public static function init()
    {
        E20RTools\DBG::log("Getting plugin data for E20R Sequences");

        $history = array( '4.4', '4.4.0', '4.4.1', '4.4.2', '4.4.3', '4.4.4', '4.4.5', '4.4.6' );

        if (function_exists('get_plugin_data'))
            $plugin_status = get_plugin_data(E20R_SEQUENCE_PLUGIN_DIR . 'e20r-sequences.php', false, false);

        $version = ( !empty($plugin_status['Version']) ? $plugin_status['Version'] : E20R_SEQUENCE_VERSION );

        $me = self::$_this;
        $me->set_version($version);

        $is_updated = get_option('e20r-sequence-updated', array() );
        $is_updated = array_unique(array_merge($history, $is_updated));

        if (!in_array( $version, $is_updated )) {

            E20RTools\DBG::log("Appending {$version} to list of stuff to upgrade");
            $is_updated[] = $version;
        }

        foreach( $is_updated as $v )
        {
            E20RTools\DBG::log("Adding update action for {$v}");
            add_action("e20r_sequence_before_update_{$v}", array(self::$_this, 'e20r_sequence_before_update'));
            add_action("e20r_sequence_update_{$v}", array(self::$_this, 'e20r_sequence_update'));
            add_action("e20r_sequence_after_update_{$v}", array(self::$_this, 'e20r_sequence_after_update'));
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
        if ( null == self::$_this )
        {
            E20RTools\DBG::log("Instantiating the " . get_class(self::$_this) . " class");
            self::$_this = new self;
        }

        return self::$_this;
    }

    static public function update()
    {
        $su_class = apply_filters('get_sequence_update_class_instance',null);
        $version = $su_class->get_version();

        $is_updated = get_option('e20r-sequence-updated', array() );

        if (!in_array( $version, $is_updated )) {

            E20RTools\DBG::log("Appending {$version} to list of stuff to upgrade");
            $is_updated[] = $version;
        }

        foreach ($is_updated as $v) {

            $upgrade_file = str_replace('.', '_', $v);

            if (file_exists(E20R_SEQUENCE_PLUGIN_DIR . "upgrades/{$upgrade_file}.php")) {

                require_once(E20R_SEQUENCE_PLUGIN_DIR . "upgrades/{$upgrade_file}.php");

                E20RTools\DBG::log("Running pre (before) update action for {$v}");
                do_action("e20r_sequence_before_update_{$v}");

                E20RTools\DBG::log("Running update action for {$v}");
                do_action("e20r_sequence_update_{$v}");

                E20RTools\DBG::log("Running clean-up (after) update action for {$v}");
                do_action("e20r_sequence_after_update_{$v}");

                if (!in_array($v, $is_updated)) {
                    $is_updated[] = $v;
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