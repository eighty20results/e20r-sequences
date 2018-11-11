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

use E20R\Tools\DBG;

class Sequence_Updates {
	private static $_this = null;
	private $current_version;
	
	public function __construct() {
		DBG::log( "Loading the sequence_update class" );
		
		if ( null !== self::$_this ) {
			
			DBG::log( "Error loading the sequence update class" );
			$error_message = sprintf( __( "Attempted to load a second instance of this singleton class (%s)", "e20r-sequences" ),
				get_class( $this )
			);
			
			error_log( $error_message );
			wp_die( $error_message );
			
		}
		
		self::$_this = $this;
	}
	
	public static function init() {
		DBG::log( "Getting plugin data for E20R Sequences" );
		
		$update_functions = array(
			'4.4.0',
			'4.4.11',
		);
		
		if ( function_exists( 'get_plugin_data' ) ) {
			$plugin_status = get_plugin_data( E20R_SEQUENCE_PLUGIN_DIR . 'e20r-sequences.php', false, false );
		}
		
		$version = ( ! empty( $plugin_status['Version'] ) ? $plugin_status['Version'] : E20R_SEQUENCE_VERSION );
		
		$me = self::$_this;
		$me->set_version( $version );
		
		$is_updated = get_option( 'e20r-sequence-updated', array() );
		$a_versions = array_keys( $is_updated );
		$a_versions = array_unique( array_merge( $update_functions, $a_versions ) );
		
		if ( false === in_array( $version, $a_versions ) ) {
			
			DBG::log( "Appending {$version} to list of stuff to upgrade" );
			$is_updated[ $version ] = false;
			$a_versions[]           = $version;
		}
		
		foreach ( $is_updated as $v => $status ) {
			
			$upgrade_file = str_replace( '.', '_', $v );
			
			if ( ! empty( $v ) &&
			     ( file_exists( E20R_SEQUENCE_PLUGIN_DIR . "upgrades/{$upgrade_file}.php" ) &&
			       ( false == $status ) ) ) {
				
				DBG::log( "Adding update action for {$v}" );
				add_action( "e20r_sequence_before_update_{$v}", array( self::$_this, 'e20r_sequence_before_update' ) );
				add_action( "e20r_sequence_update_{$v}", array( self::$_this, 'e20r_sequence_update' ) );
				add_action( "e20r_sequence_after_update_{$v}", array( self::$_this, 'e20r_sequence_after_update' ) );
			}
		}
		
		update_option( 'e20r-sequence-updated', $is_updated, 'no' );
	}
	
	public static function get_instance() {
		if ( null == self::$_this ) {
			self::$_this = new self;
			DBG::log( "Instantiating the " . get_class( self::$_this ) . " class" );
		}
		
		return self::$_this;
	}
	
	static public function update() {
		$su_class = apply_filters( 'get_sequence_update_class_instance', null );
		$version  = $su_class->get_version();
		
		if ( empty( $version ) ) {
			return;
		}
		
		$is_updated = get_option( 'e20r-sequence-updated', array() );
		
		$processed = array_keys( $is_updated );
		
		if ( ! array_key_exists( $version, $is_updated ) ) {
			
			DBG::log( "Appending {$version} to list of stuff to upgrade" );
			$is_updated[ $version ] = false;
		}
		
		foreach ( $is_updated as $v => $status ) {
			
			$upgrade_file = str_replace( '.', '_', $v );
			
			if ( file_exists( E20R_SEQUENCE_PLUGIN_DIR . "upgrades/{$upgrade_file}.php" ) && ( false == $status ) ) {
				
				require_once( E20R_SEQUENCE_PLUGIN_DIR . "upgrades/{$upgrade_file}.php" );
				
				DBG::log( "Running pre (before) update action for {$v}" );
				do_action( "e20r_sequence_before_update_{$v}" );
				
				// FIXME: Will always run, every time the plugin loads (prevent this!)
				DBG::log( "Running update action for {$v}" );
				do_action( "e20r_sequence_update_{$v}" );
				
				DBG::log( "Running clean-up (after) update action for {$v}" );
				do_action( "e20r_sequence_after_update_{$v}" );
				
				if ( array_key_exists( $v, $is_updated ) ) {
					$is_updated[ $v ] = true;
				}
			} else {
				DBG::log( "No updates to do for v{$v}" );
			}
		}
		
		update_option( 'e20r-sequence-updated', $is_updated, 'no' );
	}
	
	public function get_version() {
		return isset( $this->current_version ) ? $this->current_version : null;
	}
	
	public function set_version( $version ) {
		$this->current_version = $version;
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