<?php
/**
 * Copyright (c) 2018 - Eighty / 20 Results by Wicked Strong Chicks.
 * ALL RIGHTS RESERVED
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace E20R\Sequences\Modules\Licensed\Timed_Release;

use E20R\Licensing\Licensing;
use E20R\Sequences\Sequence\Controller;

/**
 * Allow admin to configure a "make protected content visible until" or "hide protected content for the first" number
 * of days
 */
class Timed_Release {
	
	/**
	 * @var null|Timed_Release
	 */
	private static $instance = null;
	
	/**
	 * Returns an instance of the class (Singleton pattern)
	 *
	 * @return Timed_Release|null
	 */
	public static function get_instance() {
		
		if ( is_null( self::$instance ) ) {
			
			self::$instance = new self;
			
			self::$instance->load_hooks();
		}
		
		return self::$instance;
	}
	
	/**
	 * Load any action hooks and filter hooks
	 *
	 * @access private
	 */
	private function load_hooks() {
		
		// TODO: Implement any hook handler(s) for Timed_Release
		// Don't enable functionality if the plugin isn't licensed
		if ( false === Licensing::is_licensed( Controller::plugin_prefix ) ) {
			
			add_action( '' );
			if ( ! is_user_logged_in() ) {
				add_filter( 'e20r-sequence-membership-access', array( $this, 'non_member_protected' ), 99, 4 );
			}
			
			if( is_user_logged_in() ) {
				add_filter( 'e20r-sequence-membership-access', array( $this, 'is_available_until' ), 5, 4 );
			}
		}
		
		
	}
	
	/**
	 * Determine whether or not we should grant access to content for non-members
	 *
	 * @param bool     $has_access
	 * @param \WP_Post $post
	 * @param \WP_User $user
	 * @param array    $required_membership_levels
	 *
	 * @return bool
	 */
	public function non_member_protected( $has_access, $post, $user, $required_membership_levels ) {
		
		// Return true if we've already granted access
		if ( true === $has_access ) {
			return true;
		}
		
		// Post doesn't require membership
		if ( empty( $required_membership_levels ) ) {
			return true;
		}
		
		$sequence              = Controller::get_instance();
		$excluded_posts        = $this->get_excluded_posts_for_sequence( $sequence->get_current_sequence_id() );
		$timed_release_enabled = $sequence->get_option_by_name( 'timedRelease' );
		$show                  = ( $sequence->get_option_by_name( 'protectShow' ) == true ) ? true : false;
		$cutoff_delay          = $sequence->get_option_by_name( 'cutoffDelayDays' );
		$ret_val               = $has_access;
		$show                  = false;
		$sign                  = '-';
		
		// Don't time release any of the excluded posts
		if ( in_array( $post->ID, $excluded_posts ) ) {
			return $has_access;
		}
		
		if ( true === $show ) {
			$sign = "+";
			$show = true;
		}
		
		// The post requires membership. Get their membership start date
		$start_date    = apply_filters( 'e20r-sequence-mmodule-user-startdate', $user->user_registered, $user->ID, $user->membership_level->id, $sequence->get_current_sequence_id() );
		$release_delay = $sequence->get_delay_for_post( $post->ID, true );
		
		// When is the cut-off for the post (how long is it supposed to be visible or hidden for non-members)?
		$time_window  = strtotime( "{$start_date} {$sign}{$cutoff_delay} days", current_time( 'timestamp' ) );
		$available_on = strtotime( "{$start_date} +{$release_delay} days", current_time( 'timestamp' ) );
		
		if ( true === $show && $time_window <= $available_on ) {
			$ret_val = true;
		}
		
		if ( false === $show && $time_window > $available_on ) {
			$ret_val = true;
		}
		
		return $ret_val;
	}
	
	public function load_timed_release_metabox() {
	
	}
	
	/**
	 * Return the list of excluded posts (i.e. posts that we shouldn't override)
	 *
	 * @param int $sequence_id
	 *
	 * @return array
	 */
	private function get_excluded_posts_for_sequence( $sequence_id ) {
		
		$excluded = array();
		
		// The key for the post(s) to ignore
		$meta_key = sprintf( '_e20r_sequence_%d_do_not_override', $sequence_id );
		
		return $excluded;
	}
	
	/**
	 * @param bool     $has_access
	 * @param \WP_Post $post
	 * @param \WP_User $user
	 * @param array    $required_membership_levels
	 *
	 * @return bool
	 */
	public function is_available_until( $has_access, $post, $user, $required_membership_levels ) {
		
		// Denied access already? Keep denying
		if ( false === $has_access ) {
			return $has_access;
		}
		
		$sequence              = Controller::get_instance();
		
		// The post requires membership. Get their membership start date
		$start_date = apply_filters( 'e20r-sequence-mmodule-user-startdate', $user->user_registered, $user->ID, $user->membership_level->id, $sequence->get_current_sequence_id() );
		
		// Doesn't have one?
		if ( empty( $start_date ) ) {
			$has_access = false;
		}
		
		// Membership started before the post was published, so give access
		if ( !empty( $startdate) && $start_date < strtotime( $post->post_date, current_time('timestamp' ) ) ) {
			$has_access = true;
		}
		
		return $has_access;
	}
}
