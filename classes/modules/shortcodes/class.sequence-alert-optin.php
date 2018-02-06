<?php
/**
License:

Copyright 2014-2017 Eighty/20 Results by Wicked Strong Chicks, LLC (thomas@eighty20results.com)

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

 **/

namespace E20R\Sequences\Modules\Shortcodes;

use E20R\Sequences\Sequence\Controller;
use E20R\Sequences\Sequence\Sequence_Views;
use E20R\Utilities\Utilities;

class Sequence_Alert_Optin {
	
	/**
	 * @var Sequence_Alert_Optin|null
	 *
	 * @since 5.0 - ENHANCEMENT: Renamed $_this to $instance for singleton pattern
	 */
	private static $instance = null;
	
	private $class_name;

	/**
	 * Sequence_Alert_Optin shortcode constructor.
	 *
	 * @since v4.2.9
	 */
	public function __construct()
	{
		$this->class_name = get_class($this);

		if (isset(self::$instance)) {
			wp_die(sprintf(__('%s is a singleton class and you are not allowed to create a second instance', 'e20r-sequences'), $this->class_name));
		}

		self::$instance = $this;

		add_filter("get_{$this->class_name}_class_instance", array( $this, 'get_instance' ) );
		// add_shortcode('sequence_alert', array( $this, 'load_shortcode' ) );
	}

	/**
	 * Returning the instance (used by the 'get_{class_name}_class_instance' hook)
	 *
	 * @return Sequence_Alert_Optin|null
	 *
	 * * @since v4.2.9
	 */
	public function get_instance()
	{
		if ( is_null( self::$instance ) ) {
			self::$instance = new self;
		}
		
		return self::$instance;
	}

	/**
	 * Shortcode to display notification opt-in checkbox
	 *
	 * @param array $attributes - Shortcode attributes (required attribute is 'sequence=<sequence_id>')
	 * @param string $content - Would be unexpected. Included for completeness purposes
	 *
	 * @return string - HTML of the opt-in
	 */
	public function load_shortcode( $attributes = array(), $content = '' ) {

		$utils = Utilities::get_instance();
		$utils->log("Loading user alert opt-in");
		$sequence_id = null;

		extract( shortcode_atts( array(
			'sequence_id' => 0,
		), $attributes ) );

		$utils->log("shortcode specified sequence id: {$sequence_id}");
		$view_class = Sequence_Views::get_instance();
		$sequence = Controller::get_instance();
		
		if ( !empty( $sequence_id ) ) {
			
			try {
				$sequence->init( $sequence_id );
			} catch( \Exception $exception ) {
				return $sequence->get_error_msg();
			}

			return $view_class->view_user_notice_opt_in();
		}
		
		$utils->log("ERROR: No sequence ID specified!" );
		return $view_class->view_sequence_error( 'ERRNOSEQUENCEID' );
	}
}
