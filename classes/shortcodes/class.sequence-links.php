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


class Sequence_Links {

	private static $_this;
	private $class_name;

	/**
	 * Sequence_Links shortcode constructor.
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
	}

	/**
	 * Returning the instance (used by the 'get_available_class_instance' hook)
	 *
	 * @return Sequence_Links
	 *
	 * * @since v4.2.9
	 */
	public function get_instance()
	{
		if ( is_null( self::$_this ) ) {
			self::$_this = new self;
		}
		
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
		
		$no_values = apply_filters( 'e20r-sequences-shortcode-novalues', array( 'no', 'false', false, '0' ) );
		
		if ( isset($attributes['button']) && in_array( strtolower( $attributes['button'] ), $no_values ) ) {
			$button = false;
		} else {
			$button = true;
		}
		
		if ( isset($attributes['highlight']) && in_array( strtolower( $attributes['highlight'] ), $no_values ) ) {
			$highlight = false;
		} else {
			$highlight = true;
		}
		
		if ( isset($attributes['scrollbox']) && in_array( strtolower( $attributes['scrollbox'] ), $no_values) ) {
			$scrollbox = false;
		} else {
			$scrollbox = true;
		}
		
		$pagesize = isset($attributes['pagesize']) ? intval( $attributes['pagesize'] ) : 30;
		
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
		DBG::log("We're given the ID of: {$id} ");

		$seq_access = $sequence->has_post_access($current_user->ID, $id, false, $id);

		if ( ( is_array( $seq_access ) &&  false == $seq_access[0] ) || (!is_array($seq_access) && false == $seq_access ) )  {

			DBG::log("Not logged in or not a member with access to this sequence. Exiting!");

			$default_message = __("We're sorry, you do not have access to this content. Please either log in to this system, and/or upgrade your membership level", "e20r-sequences");
			return apply_filters('e20r-sequence-mmodule-access-denied-msg', $default_message, $id, $current_user->ID);
		}

		// Make sure the sequence exists.
		if ( ! $sequence->sequence_exists( $id ) ) {

			DBG::log("The requested sequence (id: {$id}) does not exist", E20R_DEBUG_SEQ_WARNING );
			$error_msg = sprintf( '<p class="error" style="text-align: center;">%s<br/>%s</p>', __("The specified Sequence was not found.", "e20r-sequences" ), __( "Please report this error to the webmaster.", "e20r-sequences" ) );

			return apply_filters( 'e20r-sequence-not-found-msg', $error_msg );
		}

		if ( !$sequence->init( $id ) ) {
			return $sequence->get_error_msg();
		}

		DBG::log("shortcode() - Ready to build link list for sequence with ID of: " . $id);

		if ( $sequence->has_post_access( $current_user->ID, $id, false, $id ) ) {

			return $view->create_sequence_list( $highlight, $pagesize, $button, $title, $scrollbox );
		}
		else {

			return '';
		}
	}
}