<?php
/*
  License:

	Copyright 2014-2016 Eighty / 20 Results by Wicked Strong Chicks, LLC (thomas@eighty20results.com)

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
namespace E20R\Sequences\Modules;

use E20R\Sequences\Main as Main;
use E20R\Sequences\Sequence as Sequence;
use E20R\Sequences\Tools as Tools;

abstract class Membership_Module  {
	
    public function __construct() {

        $this->load_filters();
        $this->load_actions();
    }

    public function load_filters() {
	    add_filter( 'e20r-email-notice-membership-level-for-user', array( $this, 'get_level_for_user' ), 10, 3 );
    }

    public function load_actions() {

    }
	
	/**
	 * Trigger membership level specific fetch of the level if applicable.
	 *
	 * @param \stdClass $level
	 * @param int $user_id
	 * @param bool $force
	 *
	 * @return mixed
	 */
    public function get_level_for_user( $level, $user_id, $force ) {
	    
    	return apply_filters( 'e20r-sequence-mmodule-membership-level-for-user', $level, $user_id, $force );
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
    abstract public function get_member_startdate( $startdate_ts, $user_id, $level_id, $sequence_id );
	
	/**
	 * Membership specific "access denied" message
	 *
	 * @param $msg     - A previously received message.
	 * @param $post_id - Post ID for the post/sequence ID the message applies to
	 * @param $user_id - User ID for the user the message applies to
	 *
	 * @return string - The text message
	 */
	abstract public function access_denied_msg( $msg, $post_id, $user_id );
	
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
	abstract public function get_membership_settings( $val, $option_name );
	
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
	abstract public function calc_member_days( $calc_days, $user_id, $level_id );
	
	/**
	 * Use the membership function to check for access to a post ID for the user ID
	 *
	 * @param bool  $access
	 * @param int   $post_id
	 * @param int   $user_id
	 * @param array $return_membership_levels
	 *
	 * @return mixed
	 */
	abstract public function check_member_access( $access, $post_id, $user_id, $return_membership_levels );
	
	/**
	 * Is the specified Sequence ID protected (is access restricted)
	 *
	 * @param bool $protected
	 * @param int  $sequence_id
	 *
	 * @return bool
	 */
	abstract public function is_protected( $protected, $sequence_id );
	
	/**
	 * Fetch the combination of sequences that a user ID is supposed to have access to
	 *
	 * @param array    $result
	 * @param null|int $sequence_id
	 *
	 * @return array
	 */
	abstract public function protected_users_posts( $result, $sequence_id = null );
	
	/**
	 * The hook used by the membership plugin when a member has been signed up (successfully)
	 */
	abstract public function membership_module_signup_hook();
	
	/**
	 * Set the per-sequence startdate whenever the user signs up for a PMPro membership level.
	 * TODO: Add functionality to do the same as part of activation/startup for the Sequence.
	 *
	 * @param              $user_id - the ID of the user
	 * @param \MemberOrder $order   - The PMPro Membership order object
	 */
	abstract public function after_checkout( $user_id, $order );
	
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
	abstract public function email_body( $phpmailer );
	
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
	abstract public function has_membership_level( $has_level, $levels, $user_id );
	
	/**
	 * Allow update of package if the package is licensed
	 *
	 * @param bool         $reply
	 * @param string       $package
	 * @param \WP_Upgrader $ug_class
	 *
	 * @return bool
	 */
	abstract public function package_is_licensed( $reply, $package, $ug_class );
}
