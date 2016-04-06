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

class sequence_links {

	private static $_this;
	private $class_name;

	/**
	 * sequence_links shortcode constructor.
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
	 * Returning the instance (used by the 'get_available_class_instance' hook)
	 *
	 * @return Shortcodes\sequence_alert
	 *
	 * * @since v4.2.9
	 */
	public function get_instance()
	{
		return self::$_this;
	}

	/**
	 * Generates a formatted list of posts in the specified sequence.
	 *
	 * @param $attributes -- Shortcode attributes
	 *
	 * @return string -- HTML output containing the list of posts for the specified sequence(s)
	 */
	public function load_shortcode( $attributes ) {

		global $current_user;
		global $load_e20r_sequence_script;

		$load_e20r_sequence_script = true;

		// To avoid errors in development tool
		$highlight = true;
		$button = true;
		$scrollbox = false;
		$pagesize = 30;
		$id = 0;
		$title = null;

		extract( shortcode_atts( array(
			'id' => 0,
			'pagesize' => 30,
			'title' => '',
			'button' => true,
			'highlight' => true,
			'scrollbox' => false,
		), $attributes ) );

		if ( $pagesize == 0 ) {

			$pagesize = 30; // Default
		}
		$sequence = apply_filters('get_sequence_class_instance', null);
		$view = apply_filters('get_sequence_views_class_instance', null);

		if ( ( $id == 0 ) && ( $sequence->sequence_id == 0 ) ) {

			global $wp_query;

			// Try using the current WP post ID
			if (! empty( $wp_query->post->ID ) ) {

				$id = $wp_query->post->ID;
			}
			else {

				return ''; // No post given so returning no info.
			}
		}
		E20RTools\DBG::log("We're given the ID of: {$id} ");

		// Make sure the sequence exists.
		if ( ! $sequence->sequence_exists( $id ) ) {

			E20RTools\DBG::log("shortcode() - The requested sequence (id: {$id}) does not exist", E20R_DEBUG_SEQ_WARNING );
			$errorMsg = '<p class="error" style="text-align: center;">The specified Sequence was not found. <br/>Please report this error to the webmaster.</p>';

			return apply_filters( 'e20r-sequence-not-found-msg', $errorMsg );
		}

		if ( !$sequence->init( $id ) ) {
			return $sequence->get_error_msg();
		}

		E20RTools\DBG::log("shortcode() - Ready to build link list for sequence with ID of: " . $id);

		if ( $sequence->has_post_access( $current_user->ID, $id, false, $id ) ) {

			return $view->create_sequence_list( $highlight, $pagesize, $button, $title, $scrollbox );
		}
		else {

			return '';
		}
	}
}