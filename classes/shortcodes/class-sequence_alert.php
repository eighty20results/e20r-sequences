<?php
/**
License:

Copyright 2014-2016 Eighty/20 Results by Wicked Strong Chicks, LLC (thomas@eighty20results.com)

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

namespace E20R\Sequences\Shortcodes;
use E20R\Sequences\Shortcodes as Shortcodes;
use E20R\Sequences\Sequence as Sequence;
use E20R\Tools as E20RTools;

class sequence_alert {

	private static $_this;
	private $class_name;

	/**
	 * sequence_alert shortcode constructor.
	 *
	 * @since v4.2.9
	 */
	public function __construct()
	{
		$this->class_name = get_class($this);

		if (isset(self::$_this)) {
			wp_die(sprintf(__('%s is a singleton class and you are not allowed to create a second instance', 'e20rsequence'), $this->class_name));
		}

		self::$_this = $this;

		add_filter("get_{$this->class_name}_class_instance", array( $this, 'get_instance' ) );
		// add_shortcode('sequence_alert', array( $this, 'load_shortcode' ) );
	}

	/**
	 * Returning the instance (used by the 'get_{class_name}_class_instance' hook)
	 *
	 * @return Shortcodes\sequence_links
	 *
	 * * @since v4.2.9
	 */
	public function get_instance()
	{
		return self::$_this;
	}

	/**
	 * Shortcode to display notification opt-in checkbox
	 * @param string $attributes - Shortcode attributes (required attribute is 'sequence=<sequence_id>')
	 * @param string $content - Would be unexpected. Included for completeness purposes
	 *
	 * @return string - HTML of the opt-in
	 */
	public function load_shortcode( $attributes, $content = '' ) {

		E20RTools\DBG::log("Loading user alert opt-in");
		$sequence_id = null;

		extract( shortcode_atts( array(
			'sequence_id' => 0,
		), $attributes ) );

		E20RTools\DBG::log("shortcode specified sequence id: {$sequence_id}");

		if ( !empty( $sequence_id ) ) {

			$sequence = apply_filters('get_sequence_class_instance');

			if ( !$sequence->init( $sequence_id ) ) {

				return $sequence->get_error_msg();
			}

			return $sequence->view_user_notice_opt_in();
		}
		else {

			E20RTools\DBG::log("ERROR: No sequence ID specified!", E20R_DEBUG_SEQ_WARNING );
		}

		return null;
	}
}