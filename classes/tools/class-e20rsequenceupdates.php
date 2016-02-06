<?php
/**
 * Created by PhpStorm.
 * User: sjolshag
 * Date: 2/5/16
 * Time: 8:52 AM
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
        $plugin_status = get_plugin_data(E20R_SEQUENCE_PLUGIN_DIR . 'e20r-sequences.php', false, false);

        $version = $plugin_status['Version'];
        $me = self::$_this;
        $me->set_version($version);

        E20RTools\DBG::log("Running INIT for updates related to {$version}");

        add_action("e20r_sequence_before_update_{$version}", array( self::$_this, 'e20r_sequence_before_update' ) );
        add_action("e20r_sequence_update_{$version}", array( self::$_this, 'e20r_sequence_update'));
        add_action("e20r_sequence_after_update_{$version}", array( self::$_this, 'e20r_sequence_after_update' ));
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

        $upgrade_file = str_replace('.', '_', $version);

        $is_updated = get_option('e20r-sequence-updated', array() );

        if (in_array( $version, $is_updated ) ) {
            E20RTools\DBG::log("Update for {$version} previously completed");
            return;
        }

        if (file_exists(E20R_SEQUENCE_PLUGIN_DIR . "upgrades/{$upgrade_file}.php") )
        {
            require_once(E20R_SEQUENCE_PLUGIN_DIR . "upgrades/{$upgrade_file}.php");

            E20RTools\DBG::log("Running pre (before) update action for {$version}");
            do_action("e20r_sequence_before_update_{$version}");

            E20RTools\DBG::log("Running update action for {$version}");
            do_action("e20r_sequence_update_{$version}");

            E20RTools\DBG::log("Running clean-up (after) update action for {$version}");
            do_action("e20r_sequence_after_update_{$version}");

            $is_updated[] = $version;
            update_option('e20r-sequence-updated', $is_updated, 'no');
        }
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