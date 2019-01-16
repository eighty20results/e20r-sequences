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

class Upcoming_Content {
	
	private static $_this;
	private $class_name;
	
	/**
	 * Upcoming_Content constructor.
	 *
	 * @since v4.2.9
	 */
	public function __construct() {
		$this->class_name = get_class( $this );
		
		if ( isset( self::$_this ) ) {
			wp_die( sprintf( __( '%s is a singleton class and you are not allowed to create a second instance', 'e20r-sequences' ), $this->class_name ) );
		}
		
		self::$_this = $this;
		
		add_filter( "get_{$this->class_name}_class_instance", array( $this, 'get_instance' ) );
		// add_shortcode('sequence_upcoming_content', [$this, 'load_shortcode']);
	}
	
	/**
	 *
	 * Process the sequence_upcoming_content shortcode
	 *
	 * @package Upcoming_Content
	 *
	 * @param null $attr - Attributes included in shortcode
	 *
	 * @return mixed - Div list of shortcodes (default is null)
	 *
	 * @since   v4.2.9
	 */
	public function load_shortcode( $attr = null, $content = null ) {
		/**
		 * Valid shortcode arguments:
		 *  'id'    => numeric - the Post ID for the pmpro_sequences CPT (i.e. the sequence to display).
		 *  'number_of_posts' => 'all' | a numeric counter for the number of upcoming posts in the sequence we'll include.
		 *  'include_past' => number of prior posts to include. Valid entries are: 'all', 'none', number (i.e. the number of posts into the past we'll include)
		 */
		
		global $current_user;
		$delay         = 0;
		$include_count = 1;
		$future        = array();
		$past          = array();
		$now           = array();
		
		$sequence_obj = apply_filters( "get_sequence_class_instance", null );
		DBG::log( "Processing attributes." );
		
		$attributes = shortcode_atts( array(
			'number_of_posts' => 'all',
			'include_past'    => 'none',
			'id'              => null,
			'cta_shortcode'   => '',
			'cta_attrs'       => '',
		), $attr );
		
		if ( empty( $attributes['id'] ) ) {
			
			DBG::log( "Error: NO Sequence ID specified!" );
			
			return sprintf( '<div class="e20r-sequences-error">%s</div>', __( 'No upcoming content to be listed (Error: Unknown ID)', 'e20r-sequences' ) );
		}
		
		DBG::log( "When attribute is specified: {$attributes['when']}" );
		
		if ( ! is_numeric( $attributes['number_of_posts'] ) && 'all' === $attributes['number_of_posts'] ) {
			
			DBG::log( "User specified 'all' as the number of upcoming posts we'll include" );
			$include_count = - 1;
			
		} else {
			$include_count = $attributes['number_of_posts'];
		}
		
		// Grab the next 4 posts if no attribute was specified in the shortcode.
		if ( 'all' === $attributes['number_of_posts'] ) {
			$attributes['number_of_posts'] = 4;
		}
		
		$post_count = (int) $attr['number_of_posts'];
		$today      = $sequence_obj->get_membership_days();
		
		// Get content being released after today.
		$future = $sequence_obj->load_sequence_post( $sequence_obj->sequence_id, $today, null, '>=', $post_count, true );
		
		// Get content to be released before today (if 'include_past' != 'none')
		if ( 'none' !== $attributes['include_past'] ) {
			$past = $sequence_obj->load_sequence_post( $sequence_obj->sequence_id, $today, null, '<', $post_count, true );
		}
		
		// Get content that already is available today, or as close to today as possible
		//    (Last entry of 'past_content' if sort order is ascending)
		//    (First entry of 'past_content' if sort order is descending)
		if ( ! empty( $past ) ) {
			
			if ( SORT_ASC == $sequence_obj->get_sort_order() ) {
				
				$now = array( $past[ count( $past ) - 1 ] );
			} else {
				
				$now = array( $past[0] );
			}
			
		} else {
			
			$now = $sequence_obj->load_sequence_post( $sequence_obj->sequence_id, $today, null, '=', $post_count, true );
		}
		
		// Load posts per the shortcode attributes we received using external loop.
		$posts = $past + $future;
		
		foreach ( $posts as $post ) {
		
		}
	}
	
	/**
	 * @param \WP_Post $content    - Valid post object
	 * @param          $cta_action - Action (shortcode or something else.
	 *
	 * @return string - Completed div (HTML) containing title, $excerpt & action info
	 */
	private function view_content_div( $content, $cta_action = null, $cta_attrs = null ) {
		
		ob_start(); ?>
        <div class="e20r-sequence-uce e20r-sequence-float-left">
        <a href="<?php echo esc_url_raw( get_permalink( $content->ID ) ); ?>" target="_blank">
            <div class="e20r-sequence-uce-title"><?php esc_attr_e( $content->post_title ); ?></div>
            <div class="e20r-sequence-uce-body">
				<?php echo apply_filters( 'the_excerpt', get_post_field( 'post_excerpt', $content->ID ) );; ?>
            </div>
        </a>
		<?php
		if ( ! is_null( $cta_action ) ) {
			?>
            <div class="e20r-sequence-uce-action">
			<?php echo do_shortcode( '[' . $cta_action . ( is_null( $cta_attrs ) ? null : ' ' . $cta_attrs ) . ']' ); ?>
            </div><?php
		} ?>
        </div><?php
		
		$html = ob_get_clean();
		
		return $html;
	}
	
	/**
	 * Returning the instance (used by the 'get_available_class_instance' hook)
	 *
	 * @return Upcoming_Content
	 *
	 * * @since v4.0.1
	 */
	public function get_instance() {
		if ( is_null( self::$_this ) ) {
			self::$_this = new self;
		}
		
		return self::$_this;
	}
}