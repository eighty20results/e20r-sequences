<?php
/**
 * Created by PhpStorm.
 * User: sjolshag
 * Date: 1/21/16
 * Time: 2:23 PM
 */

namespace E20R\Tools;
use E20R\Tools as E20RTools;

class DBG
{
    static private $plugin_name;

    static public function set_plugin_name($string) {
        self::$plugin_name = $string;
    }

    /**
     * Debug function (if executes if DEBUG is defined)
     *
     * @param $msg -- Debug message to print to debug log.
     * @param $plugin - Name of plugin
     * @param $lvl = The Debug level.
     *
     * @access public
     * @since v2.1
     */
    static public function log( $msg, $plugin = '', $lvl = E20R_DEBUG_SEQ_INFO ) {

        // Give up if WP_Debug isn't configured.
        if (!defined('WP_DEBUG') || WP_DEBUG === false) {
            return;
        }

        $uplDir = wp_upload_dir();

        $trace=debug_backtrace();
        $caller=$trace[2];
        $who_called_me = '';

        if (isset($caller['class']))
            $who_called_me .= "{$caller['class']}::";

        $who_called_me .=  "{$caller['function']}() -";

        if (!isset(self::$plugin_name) && empty($plugin) && empty(self::$plugin_name)) {
            $plugin = "/e20r-debug/";
        } else {
            $plugin = "/" . self::$plugin_name . "/";
        }

        $dbgRoot = $uplDir['basedir'] . "${plugin}";
        // $dbgRoot = "${plugin}/";
        $dbgPath = "${dbgRoot}";

        if ( ( WP_DEBUG === true ) && ( ( $lvl >= E20R_DEBUG_SEQ_LOG_LEVEL ) || ( $lvl == E20R_DEBUG_SEQ_INFO ) ) ) {

            if ( !file_exists( $dbgRoot ) ) {

                mkdir($dbgRoot, 0750);

                if (!is_writable($dbgRoot)) {
                    error_log("{$who_called_me} Debug log directory {$dbgRoot} is not writable. exiting.");
                    return;
                }
            }

            if (!file_exists($dbgPath)) {

                // Create the debug logging directory
                mkdir($dbgPath, 0750);

                if (!is_writable($dbgPath)) {
                    error_log("{$who_called_me}: Debug log directory {$dbgPath} is not writable. exiting.");
                    return;
                }
            }

            // $dbgFile = $dbgPath . DIRECTORY_SEPARATOR . 'sequence_debug_log-' . date('Y-m-d', current_time("timestamp") ) . '.txt';
            $dbgFile = $dbgPath . DIRECTORY_SEPARATOR . 'debug_log.txt';

            $tid = sprintf("%08x", abs(crc32($_SERVER['REMOTE_ADDR'] . $_SERVER['REQUEST_TIME'] . $_SERVER['REMOTE_PORT'])));

            $dbgMsg = '(' . date('d-m-y H:i:s', current_time('timestamp')) . "-{$tid}) -- {$who_called_me} " .
                ((is_array($msg) || (is_object($msg))) ? print_r($msg, true) : $msg) . "\n";

            self::add_log_text($dbgMsg, $dbgFile);
        }
    }

    static private function add_log_text($text, $filename) {

        if ( !file_exists($filename) ) {

            touch( $filename );
            chmod( $filename, 0640 );
        }

        if ( filesize( $filename ) > MAX_LOG_SIZE ) {

            $filename2 = "$filename.old";

            if ( file_exists( $filename2 ) ) {

                unlink($filename2);
            }

            rename($filename, $filename2);
            touch($filename);
            chmod($filename,0640);
        }

        if ( !is_writable( $filename ) ) {

            error_log( "Unable to open debug log file ($filename)" );
        }

        if ( !$handle = fopen( $filename, 'a' ) ) {

            error_log("Unable to open debug log file ($filename)");
        }

        if ( fwrite( $handle, $text ) === FALSE ) {

            error_log("Unable to write to debug log file ($filename)");
        }

        fclose($handle);
    }
}