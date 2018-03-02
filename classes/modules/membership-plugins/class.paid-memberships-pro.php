<?php
/**
 * Copyright (c) 2017-2018 - Eighty / 20 Results by Wicked Strong Chicks.
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


use E20R\Utilities\Licensing\Licensing;
use E20R\Sequences\Modules\Membership_Module;
use E20R\Sequences\Sequence\Controller;
use E20R\Utilities\Utilities;
use E20R\Sequences\Data\Model;

class Paid_Memberships_Pro extends Membership_Module {
	
	/**
	 * @var null|Paid_Memberships_Pro
	 */
	private static $instance = null;
	
	/**
	 * The membership module slug/name
	 *
	 * @var string $module_name
	 */
	private $module_name = 'paid-memberships-pro';
	
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
	
	/**
	 * Load any action hooks and filter hooks
	 *
	 * @access private
	 */
	public function load_hooks() {
		
		$utils = Utilities::get_instance();
		
		$utils->log( "Loading module specific hooks for Paid Memberships Pro" );
		
		add_action( 'upgrader_pre_download', array( $this, 'package_is_licensed' ), 9, 3 );
		
		// TODO: Implement any hook handler(s)
		add_filter( 'e20r-sequence-mmodule-user-startdate', array( $this, 'get_member_startdate' ), 10, 4 );
		add_filter( 'e20r-sequence-mmodule-get-membership-setting', array( $this, 'get_membership_settings' ), 10, 2 );
		add_filter( 'e20r-sequence-mmodule-has-membership-level', array( $this, 'has_membership_level' ), 10, 3 );
		add_filter( 'e20r-sequence-mmodule-membership-level-for-user', array(
			$this,
			'get_membership_level_for_user',
		), 10, 3 );
		add_filter( 'e20r-sequence-mmodule-access-denied-msg', array( $this, 'access_denied_msg' ), 15, 3 );
		add_filter( 'e20r-sequence-mmodule-is-active', array( $this, 'is_membership_plugin_active'), 10, 1 );
		
		add_filter( 'e20r-sequence-use-membership-startdate', '__return_true' );
		add_filter( 'e20r-sequence-use-global-startdate', '__return_true' );
		
		add_filter( 'e20r-sequence-get-protected-users-posts', array( $this, 'protected_users_posts' ), 10, 2 );
		add_filter( 'e20r-sequences-membership-is-sequence-protected', array( $this, 'is_protected' ), 10, 2 );
		add_action( 'e20r_sequence_load_membership_signup_hook', array( $this, 'membership_module_signup_hook', ) );
		add_filter( 'e20r-sequence-membership-access', array( $this, 'check_member_access' ), 10, 4 );
		add_filter( 'e20r-sequence-days-as-member', array( $this, 'calc_member_days' ), 10, 3 );
		add_action( 'e20r-sequences-mmodule-load-metabox', array( $this, 'load_metaboxes' ), 10, 1 );
		
		add_filter( 'e20r-sequences-protected-by-membership-level', array( $this, 'sequences_for_membership_level'), 10, 2 );
		
		add_filter( "pmpro_after_phpmailer_init", array( $this, "email_body" ) );
		
		add_filter( "pmpro_has_membership_access_filter", array( Controller::get_instance(), "has_membership_access_filter" ), 9, 4 );
		add_filter( "pmpro_non_member_text_filter", array( Controller::get_instance(), "text_filter" ) );
		add_filter( "pmpro_not_logged_in_text_filter", array( Controller::get_instance(), "text_filter" ) );
		
	}
	
	/**
	 * Load the membership specific metabox(es) on the Edit Sequence page
	 */
	public function load_metaboxes( ) {
		
		if ( function_exists( 'pmpro_getOption' ) ) {
			// Allow setting the membership level required for the sequence
			add_meta_box( 'pmpro_page_meta', __( 'Require Membership', "pmpro" ), 'pmpro_page_meta', 'e20r_sequence', 'side' );
		}
	}
	/**
	 * Is this membership plugin active?
	 *
	 * @param bool $is_active
	 *
	 * @return bool
	 */
	public function is_membership_plugin_active( $is_active ) {
		
		$utils = Utilities::get_instance();
		return  $utils->plugin_is_active( null, 'pmpro_getGateway' );
	}
	
	/**
	 * Return the membership level (for the specific membership plugin - PMPro) for the user
	 *
	 * @param mixed $level   - The membership level definition (integer, string, whatever)
	 * @param int   $user_id - The ID for the User record to check
	 * @param bool  $force   - Whether to ignore cached values or not
	 *
	 * @return mixed
	 */
	public function get_membership_level_for_user( $level, $user_id, $force = false ) {
		
		global $sequence_userlvl_cache;
		
		if ( ( !isset( $sequence_userlvl_cache[$user_id]) || true === $force ) && true === $this->is_membership_plugin_active( false ) )  {
			
			if ( $this->is_membership_plugin_active( false ) ) {
				$sequence_userlvl_cache[$user_id] = pmpro_getMembershipLevelForUser( $user_id, $force );
			}
		}
		
		return $sequence_userlvl_cache;
	}
	
	/**
	 * Return membership plugin specific option/setting value
	 *
	 * @filter 'e20r-sequence-mmodule-get-membership-setting'
	 *
	 * @param mixed  $val
	 * @param string $option_name
	 *
	 * @return mixed
	 */
	public function get_membership_settings( $val, $option_name ) {
		
		if ( function_exists( 'pmpro_getOption' ) ) {
			
			$val = pmpro_getOption( $option_name );
		}
		
		return $val;
	}
	
	/**
	 * Calculate the number of days the user has been a member of the specified membership level
	 *
	 * @filter 'e20r-sequence-days-as-member' - uses membership plugin calculation for # of days the user has been a
	 *         member
	 *
	 * @param int $calc_days
	 * @param int $user_id
	 * @param int $level_id
	 *
	 * @return int
	 */
	public function calc_member_days( $calc_days, $user_id, $level_id ) {
		
		if ( function_exists( 'pmpro_getMemberDays' ) ) {
			$calc_days = round( intval( pmpro_getMemberDays( $user_id, $level_id ) ) + 1, 0 ) ;
		}
		
		return $calc_days;
	}
	
	/**
	 * Use the membership function to check for access to a post ID for the user ID
	 *
	 * @param bool  $access
	 * @param \WP_Post   $post
	 * @param \WP_User   $user
	 * @param array $return_membership_levels
	 *
	 * @return mixed
	 */
	public function check_member_access( $access, $post, $user, $return_membership_levels ) {
		
		$utils = Utilities::get_instance();
		
		if ( function_exists( 'pmpro_has_membership_access' ) ) {
			
			$utils->log( "Found the PMPro Membership access function" );
			
			remove_filter( "pmpro_has_membership_access_filter", array( Controller::get_instance(), "has_membership_access_filter" ), 9 );
			$access = pmpro_has_membership_access( $post->ID, $user->ID, $return_membership_levels );
			add_filter( "pmpro_has_membership_access_filter", array( Controller::get_instance(), "has_membership_access_filter" ), 9, 4 );
			
			if ( ( ( ! is_array( $access ) ) && true == $access ) ) {
				$utils->log( "Didn't receive an array for the access info" );
				$user_level = pmpro_getMembershipLevelForUser( $user->ID, true );
				$access     = array( true, array( $access ), array( $user_level->name ) );
			}
			
			$utils->log( "User {$user->ID} has access? " . ( $access[0] ? "Yes" : "No" ) );
		}
		
		return $access;
	}
	
	/**
	 * Is the specified Sequence ID protected (is access restricted)
	 *
	 * @param bool $protected
	 * @param int  $sequence_id
	 *
	 * @return bool
	 */
	public function is_protected( $protected, $sequence_id ) {
		
		$utils = Utilities::get_instance();
		
		if ( function_exists( 'pmpro_getLevel' ) ) {
			
			global $wpdb;
			
			$is_protected = $wpdb->get_col( $wpdb->prepare( "SELECT mp.membership_id FROM {$wpdb->pmpro_memberships_pages} AS mp WHERE mp.page_id = %d", $sequence_id ) );
			$protected    = ( ! empty( $is_protected ) );
		}
		
		$utils->log( "Sequence {$sequence_id} is protected? " . ( $protected ? 'yes' : 'no' ) );
		
		return $protected;
	}
	
	/**
	 * Fetch the combination of sequences that a user ID is supposed to have access to
	 *
	 * @param array    $result
	 * @param null|int $sequence_id
	 *
	 * @return array
	 */
	public function protected_users_posts( $result, $sequence_id = null ) {
		
		$utils = Utilities::get_instance();
		
		if ( function_exists( 'pmpro_has_membership_access' ) ) {
			
			global $wpdb;
			
			// Prepare SQL to get all sequences and users associated in the system who _may_ need to be notified
			if ( empty( $sequence_id ) ) {
				
				$all_sequences = true;
				$utils->log( "Loading and processing ALL sequences" );
				$sql_stmnt = $wpdb->prepare( "
                        SELECT usrs.*, pgs.page_id AS seq_id
                        FROM {$wpdb->pmpro_memberships_users} AS usrs
                            INNER JOIN {$wpdb->pmpro_memberships_pages} AS pgs
                                ON (usrs.membership_id = pgs.membership_id)
                            INNER JOIN {$wpdb->posts} AS posts
                                ON ( pgs.page_id = posts.ID AND posts.post_type = %s )
                        WHERE (usrs.status = 'active')
                    ",
					apply_filters( 'e20r-sequences-sequence-post-type', Model::cpt_type )
				);
			} else {
				
				// Get the specified sequence and its associated users
				$utils->log( "Loading and processing specific sequence: {$sequence_id}" );
				
				$sql_stmnt = $wpdb->prepare(
					"
                        SELECT usrs.*, pgs.page_id AS seq_id
                        FROM {$wpdb->pmpro_memberships_users} AS usrs
                            INNER JOIN {$wpdb->pmpro_memberships_pages} AS pgs
                                ON (usrs.membership_id = pgs.membership_id)
                            INNER JOIN {$wpdb->posts} AS posts
                                ON ( posts.ID = pgs.page_id AND posts.post_type = %s)
                        WHERE (usrs.status = 'active') AND (pgs.page_id = %d)
                    ",
					apply_filters( 'e20r-sequences-sequence-post-type', Model::cpt_type ),
					$sequence_id
				);
			}
			
			$result = $wpdb->get_results( $sql_stmnt );
		}
		
		return $result;
	}
	
	/**
	 * The hook used by the membership plugin when a member has been signed up (successfully)
	 */
	public function membership_module_signup_hook() {
		add_action( 'pmpro_after_checkout', array( $this, 'after_checkout' ), 10, 2 );
	}
	
	/**
	 * Set the per-sequence startdate whenever the user signs up for a PMPro membership level.
	 * TODO: Add functionality to do the same as part of activation/startup for the Sequence.
	 *
	 * @param              $user_id - the ID of the user
	 * @param \MemberOrder $order   - The PMPro Membership order object
	 */
	public function after_checkout( $user_id, $order ) {
		
		global $wpdb;
		global $current_user;
		
		$controller = Controller::get_instance();
		
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
		
		$member_sequences = $controller->sequences_for_membership_level( $order->membership_id );
		
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
	 * All published sequences that are protected by the specified PMPro Membership Level
	 *
	 * @param int $membership_level_id - the Level ID
	 *
	 * @return array - array of sequences;
	 */
	public function sequences_for_membership_level( $membership_level_id ) {
		
		global $wpdb;
		global $current_user;
		
		$utils = Utilities::get_instance();
		$model = Model::get_instance();
		
		// get all published sequences
		$sequence_list = $model->get_all_sequences( array( 'publish', 'private' ) );
		$in_sequence   = array();
		
		$utils->log( "Found " . count( $sequence_list ) . " sequences have been published on this system" );
		
		// Pull out the ID values (post IDs)
		foreach ( $sequence_list as $sequence ) {
			
			$in_sequence[] = $sequence->ID;
		}
		
		// check that there are sequences found
		if ( ! empty( $in_sequence ) ) {
			
			$utils->log( "Search DB for sequences protected by the specified membership ID: {$membership_level_id}" );
			
			// get all sequences (by page id) from the DB that are protected by
			// a specific membership level.
			$sql = $wpdb->prepare(
				"
                SELECT mp.page_id
                FROM {$wpdb->pmpro_memberships_pages} AS mp
                 WHERE mp.membership_id = %d AND
                 mp.page_id IN ( " . implode( ', ', $in_sequence ) . " )
                ",
				$membership_level_id
			);
			
			// list of page IDs that have the level ID configured
			$sequences = $wpdb->get_col( $sql );
			
			$utils->log( "Found " . count( $sequences ) . " sequences that are protected by level # {$membership_level_id}" );
			
			// list of page IDs that have the level ID configured
			return $sequences;
			
		}
		
		$utils->log( "Found NO sequences protected by level # {$membership_level_id}!" );
		
		// No sequences configured
		return null;
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
	 * Check with the membership plugin (if installed & active) whether the user ID has the requested membership
	 * level(s)
	 *
	 * @param bool        $has_level
	 * @param \stdClass[] $levels
	 * @param int         $user_id
	 *
	 * @return bool
	 */
	public function has_membership_level( $has_level, $levels, $user_id ) {
		
		if ( function_exists( 'pmpro_hasMembershipLevel' ) ) {
			$has_level = pmpro_hasMembershipLevel( $levels, $user_id );
		}
		
		return $has_level;
	}
	
	/**
	 * Return the timestamp for the start of the user's membership level purchase
	 *
	 * @param int $startdate_ts Seconds since Epoch
	 * @param int $user_id      User we're getting the timestamp for
	 * @param int $level_id     Membership Level ID we're checking
	 * @param int $sequence_id  ID of Sequence we're processing)
	 *
	 * @return int
	 */
	public function get_member_startdate( $startdate_ts, $user_id, $level_id, $sequence_id ) {
		
		$utils = Utilities::get_instance();
		
		if ( function_exists( 'pmpro_getMemberStartdate' ) ) {
			
			$startdate_ts = pmpro_getMemberStartdate( $user_id, $level_id );
			$utils->log( "Looking up startdate for user ID / level from PMPro: {$startdate_ts}" );
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
		
		remove_filter( "pmpro_has_membership_access_filter", array( Controller::get_instance(), "has_membership_access_filter" ), 9 );
		$hasaccess = pmpro_has_membership_access( $post_id, $user_id, true );
		add_filter( "pmpro_has_membership_access_filter", array( Controller::get_instance(), "has_membership_access_filter" ), 9, 4 );
		
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
		
		if ( false !== stripos( $package, Controller::plugin_slug ) ) {
			
			// Test if the plugin is licensed (use cached result)
			$reply = Licensing::is_licensed( Controller::plugin_prefix );
			$utils->log( "Checking upgrade license for {$package}: Is licensed? " . ( $reply ? 'Yes' : 'No' ) );
		}
		
		return $reply;
	}
}
