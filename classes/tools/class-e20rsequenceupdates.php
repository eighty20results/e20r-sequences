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
    private static $_this;

    public function __construct()
    {
        if (isset(self::$_this)) {
            $error_message = sprintf(__("Attempted to load a second instance of this singleton class (%s)", "e20rsequence"),
                get_class($this)
            );

            error_log($error_message);
            wp_die( $error_message);

        }

        self::$_this = $this;

        add_filter('get_sequence_update_class_instance', array( $this, 'get_instance'));
        add_action('plugins_loaded', array($this, 'init'));
        add_action('wp_loaded', array($this, 'update'), 1); // Run early
    }

    public function init() {

        $plugin_status = get_plugin_data(E20R_SEQUENCE_PLUGIN_DIR, false, false);
        $this->current_version = $plugin_status['Version'];

        E20RTools\DBG::log("Running INIT for updates related to {$this->current_version}");

        add_action("e20r_sequence_before_update_{$this->current_version}");
        add_action("e20r_sequence_update_{$this->current_version}");
        add_action("e20r_sequence_after_update_{$this->current_version}");
    }

    public function get_version()
    {
        return $this->current_version;
    }

    public function get_instance()
    {
        return self::$_this;
    }

    static public function update()
    {
        $su_class = apply_filters('get_sequence_update_class_instance',null);
        $version = $su_class->get_version();

        $upgrade_file = str_replace('.', '_', $version);

        if (file_exists(E20R_SEQUENCE_PLUGIN_DIR . "upgrades/{$upgrade_file}.php"))
        {
            require_once(E20R_SEQUENCE_PLUGIN_DIR . "upgrades/{$upgrade_file}.php");

            E20RTools\DBG::log("Running pre (before) update action for {$version}");
            do_action("e20r_sequence_before_update_{$version}");

            E20RTools\DBG::log("Running update action for {$version}");
            do_action("e20r_sequence_update_{$version}");

            E20RTools\DBG::log("Running clean-up (after) update action for {$version}");
            do_action("e20r_sequence_after_update_{$version}");
        }
    }
}