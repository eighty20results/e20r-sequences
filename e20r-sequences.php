<?php
/*
Plugin Name: Sequences for Paid Memberships Pro
Plugin URI: https://eighty20results.com/wordpress-plugins/e20r-sequences/
Description: Simple to configure drip feed content plugin for your PMPro users.
Version: 4.6.8
Author: Eighty / 20 Results (Thomas Sjolshagen)
Author Email: thomas@eighty20results.com
Author URI: https://eighty20results.com/thomas-sjolshagen
Text Domain: e20r-sequences
Domain Path: /languages
License: GPL2

	Copyright 2014-2017 Eighty / 20 Results by Wicked Strong Chicks, LLC (thomas@eighty20results.com)

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

namespace E20R\Sequences\Main;

use E20R\Sequences\Sequence\Sequence_Controller;
use E20R\Tools\DBG;

/* Version number */
define('E20R_SEQUENCE_VERSION', '4.6.8');

/* Sets the 'hoped for' PHP version - used to display warnings & change date/time calculations if needed */
define('E20R_SEQ_REQUIRED_PHP_VERSION', '5.4');

/* Set the path to the Sequences plugin */
define('E20R_SEQUENCE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('E20R_SEQUENCE_PLUGIN_FILE', plugin_dir_path(__FILE__) . 'e20r-sequences.php');
define('E20R_SEQUENCE_PLUGIN_URL', plugin_dir_url(__FILE__));

define( __NAMESPACE__ . '\NS', __NAMESPACE__ . '\\' );

// use NS as Sequence;

/* Set the max number of email alerts to send in one go to one user */
define( 'E20R_SEQUENCE_MAX_EMAILS', 3 );

define( 'E20R_SEQ_AS_DAYNO', 1 );
define( 'E20R_SEQ_AS_DATE', 2 );

define( 'E20R_SEQ_SEND_AS_SINGLE', 10 );
define( 'E20R_SEQ_SEND_AS_LIST', 20 );

define( 'E20R_DEBUG_SEQ_INFO', 10 );
define( 'E20R_DEBUG_SEQ_WARNING', 100 );
define( 'E20R_DEBUG_SEQ_CRITICAL', 1000 );

if ( ! defined( 'MAX_LOG_SIZE' ) ) {
	define( 'MAX_LOG_SIZE', 3 * 1024 * 1024 );
}

/* Enable / Disable DEBUG logging to separate file */
define( 'E20R_SEQUENCE_DEBUG', true );
define( 'E20R_DEBUG_SEQ_LOG_LEVEL', E20R_DEBUG_SEQ_INFO );

if ( version_compare( PHP_VERSION, E20R_SEQ_REQUIRED_PHP_VERSION, '<=' ) ) {
    add_action( 'admin_notices', create_function( '', "echo '<div class=\"error\"><p>".sprintf( __('The Sequences by Eighty / 20 Results plugin <strong>requires PHP %s or later</strong> and will not function properly without it. Please upgrade PHP on this server, or deactivate Sequences by Eighty / 20 Results.', 'e20r-sequences'), E20R_SEQ_REQUIRED_PHP_VERSION ) ."</p></div>';" ) );
    return;
} else {
 
	try {
		
		require_once( E20R_SEQUENCE_PLUGIN_DIR . "classes/class.sequence-controller.php" );
		
		spl_autoload_register( array( Sequence_Controller::get_instance(), 'auto_loader' ) );
		
		global $converting_sequence;
		$converting_sequence = false;
		
		DBG::set_plugin_name( 'e20r-sequences' );
		
		register_activation_hook( E20R_SEQUENCE_PLUGIN_FILE, array( Sequence_Controller::get_instance() , 'activation' ) );
		register_deactivation_hook( E20R_SEQUENCE_PLUGIN_FILE, array( Sequence_Controller::get_instance(), 'deactivation' ) );
		
		add_action( 'plugins_loaded', array( Sequence_Controller::get_instance(), 'load_actions' ), 5 );
		
	} catch ( \Exception $exception ) {
		error_log( "E20R Sequences startup: Error initializing the specified sequence...: " . $exception->getMessage() );
	}

	if ( file_exists(  E20R_SEQUENCE_PLUGIN_DIR . "/classes/plugin-updates/plugin-update-checker.php" ) ) {
		require_once( E20R_SEQUENCE_PLUGIN_DIR . "/classes/plugin-updates/plugin-update-checker.php" );
		
		$plugin_updates = \Puc_v4_Factory::buildUpdateChecker(
			'https://eighty20results.com/protected-content/e20r-sequences/metadata.json',
			__FILE__,
			'e20r-sequences'
		);
	}  else {
		error_log("Missing Plugin Update Checked code!!");
	}
}


