<?php
/**
 * Copyright (c) 2017 - Eighty / 20 Results by Wicked Strong Chicks.
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

namespace E20R\Sequences\Modules\Membership_Plugins;


use E20R\Licensing\Licensing;
use E20R\Sequences\Modules\Membership_Module;
use E20R\Sequences\Sequence\Controller;
use E20R\Utilities\Utilities;

class Paid_Memberships_Pro extends Membership_Module {
	
	/**
	 * @var null|Paid_Memberships_Pro
	 */
	private static $instance = null;
	
	private $module_name = 'paid-memberships-pro';
	
	/**
	 * Load any action hooks and filter hooks
	 *
	 * @access private
	 */
	public function load_hooks() {
		
		$utils = Utilities::get_instance();
		
		$utils->log("Loading module specific hooks for Paid Memberships Pro");
		add_action( 'upgrader_pre_download', array( $this, 'package_is_licensed' ), 9, 3 );
		
		// TODO: Implement any hook handler(s)
		add_filter( 'e20r-sequence-mmodule-user-startdate', array( $this, 'get_member_startdate' ), 10, 4 );
		add_filter( 'e20r-sequence-use-membership-startdate', '__return_true' );
		add_filter( 'e20r-sequence-use-global-startdate', '__return_true' );
		add_filter( 'e20r-sequence-mmodule-access-denied-msg', array( $this, 'access_denied_msg' ), 15, 3 );
		add_action( 'e20r_sequence_load_membership_signup_hook', array( $this, 'membership_module_signup_hook', ) );
		
		add_filter( "pmpro_after_phpmailer_init", array( $this, "email_body" ) );
		
		add_filter( "pmpro_has_membership_access_filter", array( Controller::get_instance(), "has_membership_access_filter" ), 9, 4 );
		add_filter( "pmpro_non_member_text_filter", array( Controller::get_instance(), "text_filter" ) );
		add_filter( "pmpro_not_logged_in_text_filter", array( Controller::get_instance(), "text_filter" ) );
		
	}
	
	public function membership_module_signup_hook() {
		add_action( 'pmpro_after_checkout', array( $this, 'pmpro_after_checkout' ), 10, 2 );
	}
	
	/**
	 * Set the per-sequence startdate whenever the user signs up for a PMPro membership level.
	 * TODO: Add functionality to do the same as part of activation/startup for the Sequence.
	 *
	 * @param              $user_id - the ID of the user
	 * @param \MemberOrder $order   - The PMPro Membership order object
	 */
	public function pmpro_after_checkout( $user_id, $order ) {
		
		global $wpdb;
		global $current_user;
		
		$startdate_ts = null;
		$timezone     = null;
		$utils        = Utilities::get_instance();
		
		if ( function_exists( 'pmpro_getMemberStartdate' ) ) {
			$startdate_ts = pmpro_getMemberStartdate( $user_id, $order->membership_id );
		}
		
		
		if ( empty( $startdate_ts ) ) {
			
			$startdate_ts = strtotime( $current_user->user_registered );
		}
		
		if ( empty( $startdate_ts ) ) {
			
			$timezone = get_option( 'timezone_string' );
			
			// and there's a valid Timezone setting
			if ( ! empty( $timezone ) ) {
				
				// use 'right now' local time' as their startdate.
				$utils->log( "Using timezone: {$timezone}" );
				$startdate_ts = strtotime( 'today ' . get_option( 'timezone_string' ) );
			} else {
				$startdate_ts = current_time( 'timestamp' );
			}
			
		}
		
		$member_sequences = $this->sequences_for_membership_level( $order->membership_id );
		
		if ( ! empty( $member_sequences ) ) {
			
			foreach ( $member_sequences as $user_sequence ) {
				
				$m_startdate_ts = get_user_meta( $user_id, "_e20r-sequence-startdate-{$user_sequence}", true );
				
				if ( empty( $m_startdate_ts ) ) {
					
					update_user_meta( $user_id, "_e20r-sequence-startdate-{$user_sequence}", $startdate_ts );
				}
				
			}
		}
	}
	
	/**
	 * Changes the content of the following placeholders as described:
	 *
	 * TODO: Simplify and just use a more standardized and simple way of preparing the mail object before wp_mail'ing
	 * it.
	 *
	 *  !!excerpt_intro!! --> The introduction to the excerpt (Configure in "Sequence" editor ("Sequence Settings
	 *  pane")
	 *  !!post_title!! --> The title of the lesson/post we're emailing an alert about.
	 *  !!today!! --> Today's date (in the configured format).
	 *
	 * @param $phpmailer -- PMPro Mail object (contains the Body of the message)
	 *
	 * @access private
	 */
	public function email_body( $phpmailer ) {
		
		$utils = Utilities::get_instance();
		$utils->log( 'email_body() action: Update body of message if it is sent by PMPro Sequence' );
		
		if ( isset( $phpmailer->excerpt_intro ) ) {
			$phpmailer->Body = apply_filters( 'e20r-sequence-alert-message-excerpt-intro', str_replace( "!!excerpt_intro!!", $phpmailer->excerpt_intro, $phpmailer->Body ) );
		}
		
		if ( isset( $phpmailer->ptitle ) ) {
			$phpmailer->Body = apply_filters( 'e20r-sequence-alert-message-title', str_replace( "!!ptitle!!", $phpmailer->ptitle, $phpmailer->Body ) );
		}
		
	}
	/**
	 * Return the timestamp for the start of the user's membership level purchase
	 *
	 * @param int $startdate_ts Seconds since Epoch
	 * @param \WP_User $user User we're getting the timestamp for
	 * @param int $membership_level Membership Level ID we're checking
	 * @param string $module_name Name of the membership module we're processing (Needs to be paid-memberships-pro for this module)
	 *
	 * @return int
	 */
	public function get_member_startdate( $startdate_ts, $user_id, $level_id, $sequence_id ) {
		
		if ( function_exists( 'pmpro_getMemberStartdate' ) ) {
			$startdate_ts = pmpro_getMemberStartdate( $user_id, $level_id );
		}
		
		return $startdate_ts;
	}
	
	/**
	 * Paid Memberships Pro specific "access denied" message
	 *
	 * @param $msg     - A previously received message.
	 * @param $post_id - Post ID for the post/sequence ID the message applies to
	 * @param $user_id - User ID for the user the message applies to
	 *
	 * @return string - The text message
	 */
	public function access_denied_msg( $msg, $post_id, $user_id ) {
		
		if ( ! function_exists( 'pmpro_has_membership_access' ) ||
		     ! function_exists( 'pmpro_getLevel' ) ||
		     ! function_exists( 'pmpro_implodeToEnglish' ) ||
		     ! function_exists( 'pmpro_getOption' )
		) {
			return $msg;
		}
		
		global $current_user;
		$utils = Utilities::get_instance();
		
		remove_filter( "pmpro_has_membership_access_filter", array( &$this, "has_membership_access_filter" ), 9 );
		$hasaccess = pmpro_has_membership_access( $post_id, $user_id, true );
		add_filter( "pmpro_has_membership_access_filter", array( &$this, "has_membership_access_filter" ), 9, 4 );
		
		if ( is_array( $hasaccess ) ) {
			//returned an array to give us the membership level values
			$post_membership_levels_ids   = $hasaccess[1];
			$post_membership_levels_names = $hasaccess[2];
		}
		
		foreach ( $post_membership_levels_ids as $key => $id ) {
			//does this level allow registrations?
			$level_obj = pmpro_getLevel( $id );
			if ( ! $level_obj->allow_signups ) {
				unset( $post_membership_levels_ids[ $key ] );
				unset( $post_membership_levels_names[ $key ] );
			}
		}
		
		$utils->log( "Available PMPro Membership Levels to access this post: " );
		$utils->log( $post_membership_levels_names );
		
		$pmpro_content_message_pre  = '<div class="pmpro_content_message">';
		$pmpro_content_message_post = '</div>';
		
		$sr_search  = array( "!!levels!!", "!!referrer!!" );
		$sr_replace = array(
			pmpro_implodeToEnglish( $post_membership_levels_names ),
			urlencode( site_url( $_SERVER['REQUEST_URI'] ) ),
		);
		
		$content = '';
		
		if ( is_feed() ) {
			$newcontent = apply_filters( "pmpro_rss_text_filter", stripslashes( pmpro_getOption( "rsstext" ) ) );
			$content    .= $pmpro_content_message_pre . str_replace( $sr_search, $sr_replace, $newcontent ) . $pmpro_content_message_post;
		} else if ( $current_user->ID ) {
			//not a member
			$newcontent = apply_filters( "pmpro_non_member_text_filter", stripslashes( pmpro_getOption( "nonmembertext" ) ) );
			$content    .= $pmpro_content_message_pre . str_replace( $sr_search, $sr_replace, $newcontent ) . $pmpro_content_message_post;
		} else {
			//not logged in!
			$newcontent = apply_filters( "pmpro_not_logged_in_text_filter", stripslashes( pmpro_getOption( "notloggedintext" ) ) );
			$content    .= $pmpro_content_message_pre . str_replace( $sr_search, $sr_replace, $newcontent ) . $pmpro_content_message_post;
		}
		
		return ( ! empty( $content ) ? $content : $msg );
	}
	
	/**
	 * Allow update of package if the package is licensed
	 *
	 * @param bool         $reply
	 * @param string       $package
	 * @param \WP_Upgrader $ug_class
	 *
	 * @return bool
	 */
	public function package_is_licensed( $reply, $package, $ug_class ) {
		
		$utils = Utilities::get_instance();
		
		if ( false !== stripos( $package, 'e20r-sequences' ) ) {
			
			// Test if the plugin is licensed (use cached result)
			$reply = Licensing::is_licensed( Controller::plugin_slug );
			$utils->log( "Checking upgrade license for {$package}: Is licensed? " . ( $reply ? 'Yes' : 'No' ) );
		}
		
		return $reply;
	}
	
	/**
	 * Returns an instance of the class (Singleton pattern)
	 *
	 * @return Paid_Memberships_Pro
	 */
	public static function get_instance() {
		
		if ( is_null( self::$instance ) ) {
			
			self::$instance = new self;
			
			self::$instance->load_hooks();
		}
		
		return self::$instance;
	}
}