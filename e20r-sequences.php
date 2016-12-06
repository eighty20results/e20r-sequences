<?php
/*
Plugin Name: Sequences by Eighty / 20 Results
Plugin URI: https://eighty20results.com/e20r-sequences/
Description: Easy to configure drip feed content plugin for your users.
Version: 4.5.0
Author: Thomas Sjolshagen
Author Email: thomas@eighty20results.com
Author URI: https://eighty20results.com/thomas-sjolshagen
Text Domain: e20r-sequences
Domain Path: /languages
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
/* Version number */
define('E20R_SEQUENCE_VERSION', '4.5.0');

/* Sets the 'hoped for' PHP version - used to display warnings & change date/time calculations if needed */
define('E20R_SEQ_REQUIRED_PHP_VERSION', '5.4');

/* Set the path to the Sequences plugin */
define('E20R_SEQUENCE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('E20R_SEQUENCE_PLUGIN_FILE', plugin_dir_path(__FILE__) . 'e20r-sequences.php');
define('E20R_SEQUENCE_PLUGIN_URL', plugin_dir_url(__FILE__));

if ( version_compare( PHP_VERSION, E20R_SEQ_REQUIRED_PHP_VERSION, '<=' ) ) {
    add_action( 'admin_notices', create_function( '', "echo '<div class=\"error\"><p>".sprintf( __('The Sequences by Eighty / 20 Results plugin <strong>requires PHP %s or later</strong> and will not function properly without it. Please upgrade PHP on this server, or deactivate Sequences by Eighty / 20 Results.', 'e20r-sequences'), E20R_SEQ_REQUIRED_PHP_VERSION ) ."</p></div>';" ) );
    return;
} else {
    require_once( E20R_SEQUENCE_PLUGIN_DIR . 'e20r-sequences-loader.php');

    $plugin_updates = \PucFactory::buildUpdateChecker(
        'https://eighty20results.com/protected-content/e20r-sequences/metadata.json',
        __FILE__,
        'e20r-sequences'
    );
}