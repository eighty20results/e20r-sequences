<?php
/*
Plugin Name: Sequences for Paid Memberships Pro
Plugin URI: https://eighty20results.com/wordpress-plugins/e20r-sequences/
Description: Simple to configure drip feed content plugin for your PMPro users.
Version: 5.0.1
Author: Eighty / 20 Results (Thomas Sjolshagen)
Author Email: thomas@eighty20results.com
Author URI: https://eighty20results.com/thomas-sjolshagen
Text Domain: e20r-sequences
Domain Path: /languages
License: GPL2

	Copyright 2014-2018 Eighty / 20 Results by Wicked Strong Chicks, LLC (thomas@eighty20results.com)

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

use E20R\Sequences\Sequence\Controller;
use E20R\Sequences\Sequences_License;;
use E20R\Utilities\Utilities;

/* Version number */
define('E20R_SEQUENCE_VERSION', '5.0.1');

/* Sets the 'hoped for' PHP version - used to display warnings & change date/time calculations if needed */
define('E20R_SEQ_REQUIRED_PHP_VERSION', '5.4');

/* Set the path to the Sequences plugin */
define('E20R_SEQUENCE_PLUGIN_DIR', plugin_dir_path(__FILE__) );
define('E20R_SEQUENCE_PLUGIN_FILE', plugin_dir_path(__FILE__) . 'class.e20r-sequences.php');
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

define( 'E20R_DEFAULT_PROTECTION', 1 );

if ( ! defined( 'MAX_LOG_SIZE' ) ) {
	define( 'MAX_LOG_SIZE', 3 * 1024 * 1024 );
}

/* Enable / Disable DEBUG logging to separate file */
define( 'E20R_SEQUENCE_DEBUG', true );
define( 'E20R_DEBUG_SEQ_LOG_LEVEL', E20R_DEBUG_SEQ_INFO );

class E20R_Sequences {
	
	/**
	 * @var null|E20R_Sequences
	 */
	private static $instance = null;
	
	const plugin_slug = 'e20r-sequences';
	
	const cache_key = 'E20RSEQUENCE';
	
	/**
	 * @var null|string
	 */
	private $class_name = null;
	
	/**
	 * E20R_Sequences constructor.
	 */
	private function __construct() {
		
		global $converting_sequence;
		$converting_sequence = false;
		
		if ( is_null( self::$instance ) ) {
			self::$instance = $this;
		}
		
		add_filter( 'e20r-licensing-text-domain', array( $this, 'set_stub_name' ) );
		add_action( 'plugins_loaded', array( Sequences_License::get_instance(), 'load_hooks' ), 99 );
		
	}
	
	/**
	 * Set the name of the add-on (using the class name as an identifier)
	 *
	 * @param null $name
	 *
	 * @return null|string
	 */
	public function set_stub_name( $name = null ) {
		
		$name = strtolower( $this->get_class_name() );
		
		return $name;
	}
	
	/**
	 * Get the add-on name
	 *
	 * @return string
	 */
	public function get_class_name() {
		
		if ( empty( $this->class_name ) ) {
			$this->class_name = $this->maybe_extract_class_name( get_class( self::$instance ) );
		}
		
		return $this->class_name;
	}
	
	/**
	 * Extract the class name from the Namespace
	 *
	 * @param string $string
	 *
	 * @return string
	 */
	private function maybe_extract_class_name( $string ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "Supplied (potential) class name: {$string}" );
		
		$class_array = explode( '\\', $string );
		$name        = $class_array[ ( count( $class_array ) - 1 ) ];
		
		return $name;
	}
	
	public function set_class_name() {
		return E20R_Sequences::plugin_slug;
	}
	
	
	/**
	 * @return E20R_Sequences|null
	 */
	public static function get_instance() {
		
		if ( is_null( self::$instance ) ) {
			self::$instance = new self;
		}
		
		return self::$instance;
	}
	
	/**
	 * Class auto-loader for the E20R Sequences plugin
	 *
	 * @param string $class_name Name of the class to auto-load
	 *
	 * @since  1.0
	 * @access public static
	 */
	public static function auto_loader( $class_name ) {
		
		if ( false === stripos( $class_name, 'e20r' ) ) {
			return;
		}
		
		$parts     = explode( '\\', $class_name );
		$c_name      = strtolower( preg_replace( '/_/', '-', $parts[ ( count( $parts ) - 1 ) ] ) );
		$base_path = plugin_dir_path( __FILE__ ) . 'classes/';
		
		if ( file_exists( plugin_dir_path( __FILE__ ) . 'class/' ) ) {
			$base_path = plugin_dir_path( __FILE__ ) . 'class/';
		}
		
		$filename  = "class.{$c_name}.php";
		$iterator = new \RecursiveDirectoryIterator( $base_path, \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveIteratorIterator::SELF_FIRST | \RecursiveIteratorIterator::CATCH_GET_CHILD | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS );
		
		$filter = new \RecursiveCallbackFilterIterator($iterator, function ($current, $key, $iterator) use ($filename) {
			
			// Skip hidden files and directories.
			if ($current->getFilename()[0] == '.' || $current->getFilename() == '..' ) {
				return FALSE;
			}
			
			if ($current->isDir()) {
				// Only recurse into intended subdirectories.
				return $current->getFilename() === $filename;
			} else {
				// Only consume files of interest.
				return strpos($current->getFilename(), $filename) === 0;
			}
		});
		
		foreach( new \ RecursiveIteratorIterator( $iterator ) as $f_filename => $f_file ) {
			
			$class_path = $f_file->getPath() . "/" . $f_file->getFilename();
			
			if ( $f_file->isFile() && false !== strpos( $class_path, $filename ) ) {
				
				require_once( $class_path );
			}
		}
	}
}

if ( version_compare( PHP_VERSION, E20R_SEQ_REQUIRED_PHP_VERSION, '<=' ) ) {
    add_action( 'admin_notices', create_function( '', "echo '<div class=\"error\"><p>".sprintf( __('The Sequences by Eighty / 20 Results plugin <strong>requires PHP %s or later</strong> and will not function properly without it. Please upgrade PHP on this server, or deactivate Sequences by Eighty / 20 Results.', 'e20r-sequences'), E20R_SEQ_REQUIRED_PHP_VERSION ) ."</p></div>';" ) );
    return;
} else {
 
	try {
		
		spl_autoload_register( 'E20R\\Sequences\\Main\\E20R_Sequences::auto_loader' );
		
		register_activation_hook( E20R_SEQUENCE_PLUGIN_FILE, array( Controller::get_instance() , 'activation' ) );
		register_deactivation_hook( E20R_SEQUENCE_PLUGIN_FILE, array( Controller::get_instance(), 'deactivation' ) );
		
		add_filter( 'e20r-licensing-text-domain', array( E20R_Sequences::get_instance(), 'set_class_name') );
		add_action( 'plugins_loaded', array( Controller::get_instance(), 'load_actions' ), 5 );
		
		
	} catch ( \Exception $exception ) {
		error_log( "E20R Sequences startup: Error initializing the specified sequence...: " . $exception->getMessage() );
	}

	if ( file_exists(  E20R_SEQUENCE_PLUGIN_DIR . "/lib/plugin-updates/plugin-update-checker.php" ) ) {
		require_once( E20R_SEQUENCE_PLUGIN_DIR . "/lib/plugin-updates/plugin-update-checker.php" );
		
		$plugin_updates = \Puc_v4_Factory::buildUpdateChecker(
			'https://eighty20results.com/protected-content/e20r-sequences/metadata.json',
			__FILE__,
			'e20r-sequences'
		);
	}  else {
		error_log("Missing Plugin Update Checked code!!");
	}
}

global $e20r_sequences;
$stub = apply_filters( "e20r_sequences_name", null );

$e20r_sequences[ $stub ] = array(
	'class_name'            => 'E20R_Sequences',
	'handler_name'          => 'sequence_webhook',
	'is_active'             => ( get_option( "e20r_{$stub}_enabled", false ) == 1 ? true : false ),
	'active_license'        => ( get_option( "e20r_{$stub}_licensed", false ) == 1 ? true : false ),
	'status'                => 'deactivated',
	// ( 1 == get_option( "e20r_{$stub}_enabled", false ) ? 'active' : 'deactivated' ),
	'label'                 => 'E20R Sequences Plus',
	'admin_role'            => 'manage_options',
	'required_plugins_list' => array(
		'paid-memberships-pro/paid-memberships-pro.php' => array(
			'name' => 'Paid Memberships Pro',
			'url'  => 'https://wordpress.org/plugins/paid-memberships-pro/',
		),
	),
);
