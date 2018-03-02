<?php
/*
  License:

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

namespace E20R\Sequences\Shortcodes;

use E20R\Sequences\Sequence\Controller;
use E20R\Utilities\Utilities;

class Available_On {
	
	private static $instance = null;
	
	/**
	 * availableOn_shortcode constructor.
	 *
	 * @since v4.0.1
	 */
	public function __construct() {
		
		if ( isset( self::$instance ) ) {
			wp_die( sprintf( __( '%s is a singleton class and you are not allowed to create a second instance', 'e20r-sequences' ), get_class( $this ) ) );
		}
		
		self::$instance = $this;
	}
	
	public function load_hooks() {
		add_shortcode( 'e20r_available_on', array( $this, 'load_shortcode' ) );
	}
	/**
	 *
	 * Process the e20r_available_on shortcode
	 *
	 * @param null $attr    - Attributes included in shortcode
	 * @param null $content -- The content between [e20r_available_on][/e20r_available_on]
	 *
	 * @return mixed|string - $content or a message about unavailability (default is null)
	 *
	 * @since v4.0.1
	 */
	public function load_shortcode( $attr = null, $content = null ) {
		
		/**
		 * Valid shortcode arguments:
		 *  'type' => 'day value (numeric)' | 'date value (MM-DD-YYYY)'
		 */
		
		global $current_user;
		$delay = 0;
		
		$sequence_obj = Controller::get_instance();
		$utils = Utilities::get_instance();
		
		$utils->log( "Processing attributes." );
		
		$when = 'today';
		
		$attributes = shortcode_atts( array(
			'when' => 'today',
		), $attr );
		
		/*
		if (!in_array($attributes['type'], array('days', 'date'))) {
			$utils->log("User didn't specify the correct type attribute in the shortcode definition. Used: {$attributes['type']}");
			wp_die( sprintf(__('%s is not a valid type attribute for the e20r_available_on shortcode', 'e20r-sequences'), $type));
		}
		*/
		$utils->log( "When attribute is specified: {$attributes['when']}" );
		
		if ( ! is_numeric( $attributes['when'] ) && ( false === strtotime( $attributes['when'] ) ) ) {
			
			$utils->log( "User didn't specify a recognizable format for the 'when' attribute" );
			wp_die( sprintf( __( '%s is not a recognizable format for the when attribute in the e20r_available_on shortcode', 'e20r-sequences' ), $attributes['when'] ) );
		}
		
		
		// Converts to "days since startdate" for the current user, if provided a date
		// Otherwise assuming that the number is the number of days requested.
		$delay            = $sequence_obj->convert_date_to_days( $attributes['when'], $current_user->ID );
		$days_since_start = $sequence_obj->get_membership_days( $current_user->ID );
		
		$utils->log( "Days since start: {$days_since_start} vs delay {$delay}" );
		
		if ( $delay <= $days_since_start ) {
			
			$utils->log( "We need to display the content for the shortcode." );
			
			return do_shortcode( $content );
		}
		
		$utils->log( "We can't display the content within the shortcode block: {$delay} vs {$days_since_start}" );
		
		return apply_filters( 'e20r-sequence-shortcode-text-not-available', null );
	}
	
	/**
	 * Returning the instance (used by the 'get_available_class_instance' hook)
	 *
	 * @return Available_On
	 *
	 * * @since v4.0.1
	 */
	public static function get_instance() {
		
		if ( is_null( self::$instance ) ) {
			self::$instance = new self;
		}
		
		return self::$instance;
	}
}
