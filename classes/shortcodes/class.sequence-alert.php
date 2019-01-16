<?php
/**
 *  Copyright (c) 2014-2019. - Eighty / 20 Results by Wicked Strong Chicks.
 *  ALL RIGHTS RESERVED
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *   You should have received a copy of the GNU General Public License
 *   along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace E20R\Sequences\Shortcodes;
use E20R\Tools\DBG;

class Sequence_Alert {

	private static $_this;
	private $class_name;

	/**
	 * Sequence_Alert shortcode constructor.
	 *
	 * @since v4.2.9
	 */
	public function __construct()
	{
		$this->class_name = get_class($this);

		if (isset(self::$_this)) {
			wp_die(sprintf(__('%s is a singleton class and you are not allowed to create a second instance', 'e20r-sequences'), $this->class_name));
		}

		self::$_this = $this;

		add_filter("get_{$this->class_name}_class_instance", array( $this, 'get_instance' ) );
		// add_shortcode('sequence_alert', array( $this, 'load_shortcode' ) );
	}

	/**
	 * Returning the instance (used by the 'get_{class_name}_class_instance' hook)
	 *
	 * @return Sequence_Alert
	 *
	 * * @since v4.2.9
	 */
	public function get_instance()
	{
		return self::$_this;
	}

	/**
	 * Shortcode to display notification opt-in checkbox
	 * @param array $attributes - Shortcode attributes (required attribute is 'sequence=<sequence_id>')
	 * @param string $content - Would be unexpected. Included for completeness purposes
	 *
	 * @return string - HTML of the opt-in
	 */
	public function load_shortcode( $attributes = array(), $content = '' ) {

		DBG::log("Loading user alert opt-in");
		$sequence_id = null;

		extract( shortcode_atts( array(
			'sequence_id' => 0,
		), $attributes ) );

		DBG::log("shortcode specified sequence id: {$sequence_id}");
		$view_class = apply_filters('get_sequence_views_class_instance', null);
		$sequence = apply_filters('get_sequence_class_instance', null);
		
		if ( !empty( $sequence_id ) ) {
			
			if ( !$sequence->init( $sequence_id ) ) {

				return $sequence->get_error_msg();
			}

			return $view_class->view_user_notice_opt_in();
		}
		
		DBG::log("ERROR: No sequence ID specified!", E20R_DEBUG_SEQ_WARNING );
		return $view_class->view_sequence_error( 'ERRNOSEQUENCEID' );
	}
}