<?php
/*
  License:

	Copyright 2014-2017 Eighty / 20 Results by Wicked Strong Chicks, LLC (thomas@eighty20results.com)

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

namespace E20R\Sequences\Sequence;

use E20R\Sequences\Data\Model;
use E20R\Sequences\Main\E20R_Sequences;
use E20R\Sequences\Modules\Licensed\Analytics\Google;
use E20R\Sequences\Modules\Licensed\Export\WP_All_Export;
use E20R\Sequences\Modules\Licensed\Async_Notices\Handle_Posts;
use E20R\Sequences\Modules\Licensed\Async_Notices\Handle_User;
use E20R\Sequences\Modules\Licensed\Async_Notices\New_Content_Notice;
use E20R\Sequences\Modules\Licensed\Import\Importer;
use E20R\Sequences\Modules\Shortcodes\Sequence_Alert_Optin;
use E20R\Sequences\Modules\Membership_Plugins\Paid_Memberships_Pro;
use E20R\Sequences\Sequences_License;
use E20R\Sequences\Shortcodes\Available_On;
use E20R\Sequences\Shortcodes\Sequence_Links;
use E20R\Sequences\Tools\Cron;
use E20R\Sequences\Tools\Sequence_Updates;
use E20R\Utilities\Utilities;
use E20R\Utilities\Cache;
use E20R\Utilities\Licensing\Licensing;

class Controller {
	
	const plugin_slug = 'e20r-sequences';
	const plugin_prefix = 'e20r_sequences';
	
	private static $select2_version = '4.0.5';
	private static $seq_post_type = null;
	private static $cache_timeout = 5;
	private static $instance = null; // List of available posts for user ID
	public $options; // list of future posts for user ID (if the sequence is configured to show hidden posts)
	public $sequence_id = 0; // WP_POST definition for the sequence
	public $error = null;
	
	// private $cached_for = null;
	public $e20r_sequence_user_level = null;
	public $e20r_sequence_user_id = null;
	// private $current_metadata_versions = array();
	public $is_cron = false;
	private $id;
	
	// private static $transient_option_key = '_transient_timeout_';
	private $sequence; // In minutes
	// private $transient_key = '_';
//	private $expires;
//	private $refreshed;
	
	// Refers to a single instance of this class
	private $managed_types = null;
	/**
	 * @var Utilities $utils Utilities class
	 */
	private $utils = null;
	
	/**
	 * @var Handle_User|null
	 */
	private $user_handler = null;
	
	
	private $post_handler = null;
	
	/**
	 * Constructor for the Sequence
	 *
	 * @param null $id -- The ID of the sequence to initialize
	 *
	 * @throws \Exception - If the sequence doesn't exist.
	 */
	function __construct( $id = null ) {
		
		self::$seq_post_type = apply_filters( 'e20r-sequences-sequence-post-type', 'e20r_sequence' );
		$utils               = Utilities::get_instance();
		
		if ( null !== self::$instance ) {
			$error_message = sprintf( __( "Attempted to load a second instance of a singleton class (%s)", Controller::plugin_slug ),
				get_class( $this )
			);
			
			$utils->log( $error_message );
			wp_die( $error_message );
		}
		
		self::$instance = $this;
		
		// Make sure it's not a dummy construct() call - i.e. for a post that doesn't exist.
		if ( ( $id != null ) && ( $this->sequence_id == 0 ) ) {
			
			$this->sequence_id = $this->get_sequence_by_id( $id ); // Try to load it from the DB
			
			if ( $this->sequence_id == false ) {
				throw new \Exception(
					sprintf(
						__( "A Sequence with the specified ID (%s) does not exist on this system", Controller::plugin_slug ),
						$id
					)
				);
			}
		}
		
		$this->managed_types = apply_filters( "e20r-sequence-managed-post-types", array( "post", "page" ) );
		// $this->current_metadata_versions = $model->load_metadata_versions();
		
		$this->user_handler = new Handle_User( $this );
		$this->post_handler = new Handle_Posts( $this );
		
		// add_filter( "get_sequence_class_instance", 'E20R\Sequences\Sequence\Controller::get_instance' );
		add_action( "init", array( $this, 'load_textdomain' ), 1 );
	}
	
	/**
	 * Fetches the post data for this sequence
	 *
	 * @param $id -- ID of sequence to fetch data for
	 *
	 * @return bool | int -- The ID of the sequence or false if unsuccessful
	 */
	public function get_sequence_by_id( $id ) {
		$this->sequence = get_post( $id );
		
		if ( isset( $this->sequence->ID ) ) {
			
			$this->sequence_id = $id;
		} else {
			$this->sequence_id = false;
		}
		
		return $this->sequence_id;
	}
	
	/**
	 * Static method to return the details about a specific Post belonging to a specific sequence.
	 *
	 * @param $sequence_id
	 * @param $post_id
	 *
	 * @return \WP_Post[] - Array of posts.
	 */
	static public function post_details( $sequence_id, $post_id ) {
		
		$controller = self::get_instance();
		$controller->get_options( $sequence_id );
		
		return $controller->find_by_id( $post_id );
	}
	
	/**
	 * Singleton pattern - returns sequence object (this) to caller (via filter)
	 * @return Controller $this - Current instance of the class
	 * @since 4.0.0
	 */
	public static function get_instance() {
		
		if ( is_null( self::$instance ) ) {
			self::$instance = new self;
		}
		
		return self::$instance;
	}
	
	/**
	 * Fetch any options for this specific sequence from the database (stored as post metadata)
	 * Use default options if the sequence ID isn't supplied*
	 *
	 * @param int $sequence_id - The Sequence ID to fetch options for
	 *
	 * @return mixed -- Returns array of options if options were successfully fetched & saved.
	 */
	public function get_options( $sequence_id = null ) {
		
		$utils = Utilities::get_instance();
		$model = Model::get_instance();
		
		// Does the ID differ from the one this object has stored already?
		if ( ! is_null( $sequence_id ) && ( $this->sequence_id != $sequence_id ) ) {
			
			$utils->log( 'ID defined already but we were given a different sequence ID' );
			$this->sequence_id = $sequence_id;
		} else if ( is_null( $sequence_id ) && $sequence_id != 0 ) {
			
			// This shouldn't be possible... (but never say never!)
			$utils->log( "The defined sequence ID is empty so we'll set it to " . $sequence_id );
			$this->sequence_id = $sequence_id;
		} else {
			$utils->log( "Sequence should be configured already: {$this->sequence_id}" );
		}
		
		$model->set_refreshed( null );
		
		$expires = $model->get_expires();
		
		// Should only do this once, unless the timeout is in the past.
		if ( is_null( $expires ) ||
		     ( ! is_null( $expires ) && $expires < current_time( 'timestamp' ) )
		) {
			
			$expires = $this->get_cache_expiry( $this->sequence_id );
			$model->set_expires( $expires );
		}
		
		// Check that we're being called in context of an actual Sequence 'edit' operation
		$utils->log( 'Loading settings from DB for (' . $this->sequence_id . ') "' . get_the_title( $this->sequence_id ) . '"' );
		
		$settings = get_post_meta( $this->sequence_id, '_e20r_sequence_settings', true );
		// $utils->log("Settings are now: " . print_r( $settings, true ) );
		
		// Fix: Offset error when creating a brand new sequence for the first time.
		if ( empty( $settings ) ) {
			
			$settings = $this->default_options();
			$model->set_refreshed( null );
		}
		
		$loaded_options = $settings;
		$options        = $this->default_options();
		
		foreach ( $loaded_options as $key => $value ) {
			
			$options->{$key} = $value;
		}
		
		// $this->options = (object) array_replace( (array)$default_options, (array)$loaded_options );
		// $utils->log( "For {$this->sequence_id}: Current: " . print_r( $this->options, true ) );
		$options->loaded = true;
		
		return $this->options;
	}
	
	/**
	 * Return the expiration timestamp for the post cache of a specific sequence
	 *
	 * @param $sequence_id
	 *
	 * @return null|string
	 *
	 */
	private function get_cache_expiry( $sequence_id ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "Loading cache timeout value for {$sequence_id}" );
		
		global $wpdb;
		$expires = null;
		
		$c_key  = $this->get_cache_key( $sequence_id );
		$prefix = E20R_Sequences::cache_key;
		
		if ( ! is_null( $c_key ) ) {
			
			$sql = $wpdb->prepare( "
                SELECT option_value
                    FROM {$wpdb->options}
                    WHERE option_name LIKE %s
            ",
				"_transient_timeout_{$prefix}_{$c_key}%"
			);
			
			$expires = $wpdb->get_var( $sql );
		}
		
		$utils->log( "Loaded cache timeout value for {$sequence_id}: " . ( empty( $expires ) ? "NULL" : "{$expires}" ) );
		
		return $expires;
	}
	
	/**
	 * Generate a key & identify transient for a user/sequence combination
	 *
	 * @param int      $sequence_id - Id of sequence
	 * @param int|null $user_id
	 *
	 * @return string - The transient key being used.
	 */
	private function get_cache_key( $sequence_id, $user_id = null ) {
		global $current_user;
		
		// init variables
		$utils = Utilities::get_instance();
		$c_key = null;
		// $user_id = null;
		
		if ( empty( $user_id ) ) {
			$user_id = $current_user->ID;
		}
		
		if ( empty( $this->e20r_sequence_user_id ) ) {
			$this->e20r_sequence_user_id = $user_id;
		}
		
		$utils->log( "Cache key for user: {$this->e20r_sequence_user_id}" );
		
		if ( ( 0 == $current_user->ID && ! empty( $this->e20r_sequence_user_id ) && true === $this->is_cron ) ||
		     ( is_numeric( $this->e20r_sequence_user_id ) && 0 < $this->e20r_sequence_user_id )
		) {
			
			$user_id = $this->e20r_sequence_user_id;
			$c_key   = "{$user_id}_{$sequence_id}";
		}
		
		$utils->log( "Cache key: " . ( is_null( $c_key ) ? 'NULL' : $c_key ) );
		
		return $c_key;
	}
	
	/**
	 * Return the default options for a sequence
	 *  stdClass content:
	 *      hidden (boolean) - Whether to show or hide upcoming (future) posts in sequence from display.
	 *      lengthVisible (boolean) - Whether to show or hide the "You are on day X of your membership" information.
	 *      sortOrder (int) - Constant: Ascending or Descending
	 *      delayType (string) - byDays or byDate
	 *      startWhen (int) - The time window when the first day of the sequence should be considered 'Day 1'
	 *                           (and 'day 1' content becomes available)
	 *                          0 = Immediately (this makes 'day 0' and 'day 1' the same.
	 *                          1 = 24 hours after the membership started (i.e. 'member start date/time + 24 hours)
	 *                          2 = At midnight after the membership started, i.e. if membership starts at 4am on 12/1,
	 *                              Day 1 starts at midnight on 12/2.
	 *                          3 = At midnight at least 24 hours after the membership started. I.e. Start at 3am on
	 *                          12/1, Day 1 starts at midnight on 12/3 sendNotice (bool) - Whether to allow alert
	 *                          notices (emails) noticeTemplate (string) - The filename for the template to use in the
	 *                          message(s) noticeTime (string) - Text representation (in 24 hour clock format) of when
	 *                          to send the notice noticeTimestamp (int)   - The timestamp used to schedule the cron
	 *                          job for the notice processing excerpt_intro (string) - The introductory text used
	 *                          before the message (page/post) excerpt.
	 *
	 * @return \stdClass -- Default options for the sequence
	 * @access public
	 */
	public function default_options() {
		
		$settings = new \stdClass();
		
		$admin = get_user_by( 'email', get_option( 'admin_email' ) );
		
		if ( ! isset( $admin->user_email ) ) {
			
			// Default object to avoid warning notices
			$admin               = new \stdClass();
			$admin->user_email   = 'nobody@example.com';
			$admin->display_name = 'Not Applicable';
		}
		
		$settings->loaded               = false;
		$settings->hideFuture           = 0; // 'hidden' (Show them)
		$settings->showAdmin            = true; // 'hidden' (Show them)
		$settings->includeFeatured      = false; // Show featured image for a sequence member in post listing
		$settings->lengthVisible        = 1; //'lengthVisible'
		$settings->sortOrder            = SORT_ASC; // 'sortOrder'
		$settings->delayType            = 'byDays'; // 'delayType'
		$settings->allowRepeatPosts     = false; // Whether to allow a post to be repeated in the sequence (with different delay values)
		$settings->showDelayAs          = E20R_SEQ_AS_DAYNO; // How to display the time until available
		$settings->previewOffset        = 0; // How many days into the future the sequence should allow somebody to see.
		$settings->startWhen            = 0; // startWhen == immediately (in current_time('timestamp') + n seconds)
		$settings->sendNotice           = 1; // sendNotice == Yes
		$settings->noticeTemplate       = 'new_content.html'; // Default plugin template
		$settings->noticeSendAs         = E20R_SEQ_SEND_AS_SINGLE; // Send the alert notice as one notice per message.
		$settings->noticeTime           = '00:00'; // At Midnight (server TZ)
		$settings->noticeTimestamp      = current_time( 'timestamp' ); // The current time (in UTC)
		$settings->excerptIntro         = __( 'A summary of the post follows below:', Controller::plugin_slug );
		$settings->replyto              = apply_filters( 'e20r-sequence-default-sender-email', $admin->user_email ); // << Update Name
		$settings->fromname             = apply_filters( 'e20r-sequence-default-sender-name', $admin->display_name ); // << Updated Name!
		$settings->subject              = __( 'New Content ', Controller::plugin_slug );
		$settings->dateformat           = __( 'm-d-Y', Controller::plugin_slug ); // Using American MM-DD-YYYY format. << Updated name!
		$settings->trackGoogleAnalytics = false; // Whether to use Google analytics to track message open operations or not
		$settings->gaTid                = null; // The Google Analytics ID to use (TID)
		$settings->nonMemberAccess      = 'default'; // Either hide/protect after a number of days (public_then_protect), or release to public after a number of days
		// (protect_then_public)
		$settings->nonMemberAccessChoice  = - 1; // Either hide/protect after a number of days (public_then_protect), or release to public after a number of days
		$settings->nonMemberExclusionDays = null; // Days after publication when the nonMemberAccess setting is ignored
		$settings->nonMemberAccessDelay   = 0;
		$settings->unsubscribeErrorPage   = null; // Post/Page ID for the Unsubscribe link error page
		$settings->unsubscribeSuccessPage = null; // Post/Page ID for the Unsubscribe link success page
		
		$this->options = $settings; // Save as options for this sequence
		
		return $settings;
	}
	
	/**
	 * Locate the post ID in the specified or current sequence and the specified or current user
	 *
	 * @param int      $post_id
	 * @param int|null $sequence_id
	 * @param int|null $user_id
	 *
	 * @return array
	 */
	public function find_by_id( $post_id, $sequence_id = null, $user_id = null ) {
		
		$utils = Utilities::get_instance();
		$model = Model::get_instance();
		
		$utils->log( "Locating post {$post_id} for {$user_id}." );
		global $current_user;
		
		$found = array();
		
		if ( is_null( $sequence_id ) && ( ! empty( $this->sequence_id ) ) ) {
			$utils->log( "No sequence ID specified in call. Using default value of {$this->sequence_id}" );
			$sequence_id = $this->sequence_id;
		}
		
		if ( is_null( $user_id ) && is_user_logged_in() ) {
			$user_id = $current_user->ID;
		}
		
		$posts = $this->get_cache( $sequence_id );
		
		if ( empty( $posts ) ) {
			
			$utils->log( "Cache is invalid.  Using load_sequence_post to grab the post(s) by ID: {$post_id}." );
			$posts = $model->load_sequence_post( $sequence_id, null, $post_id );
			
			if ( empty( $posts ) ) {
				
				$utils->log( "Couldn't find post based on post ID of {$post_id}. Now loading all posts in sequence" );
				$posts = $model->load_sequence_post();
			} else {
				$utils->log( "Returned " . count( $posts ) . " posts from load_sequnce_post() function" );
			}
		} else {
			
			$utils->log( "Have valid cache. Using cached post list to locate post with ID {$post_id}" );
			$model->set_posts( $posts );
		}
		
		if ( empty( $posts ) ) {
			$utils->log( "No posts in sequence. Returning empty list." );
			
			return array();
		}
		
		foreach ( $posts as $p_data ) {
			
			if ( $p_data->id == $post_id ) {
				
				$utils->log( "Including post # {$post_id}, delay: {$p_data->delay}" );
				$found[] = $p_data;
			}
		}
		
		return $found;
	}
	
	/**
	 * Return the cached post list for the specific cache
	 *
	 * @param $sequence_id - The Sequenece Id
	 *
	 * @return bool|mixed - The cached post list (or false if unable to locate it)
	 */
	public function get_cache( $sequence_id ) {
		
		$utils = Utilities::get_instance();
		$model = Model::get_instance();
		
		$utils->log( "Loading from cache for {$sequence_id}..." );
		
		$cache = false;
		
		$expires = $this->get_cache_expiry( $sequence_id );
		
		$model->set_expires( $expires );
		
		$c_key = $this->get_cache_key( $sequence_id );
		
		if ( ! empty( $c_key ) ) {
			$cache = Cache::get( $c_key, E20R_Sequences::cache_key );
		}
		
		return empty( $cache ) ? false : $cache;
		// $cached_value = wp_cache_get( $key, $group, $force, $found );
	}
	
	/**
	 * Static function that returns all sequence IDs that a specific post_id belongs to
	 *
	 * @param $post_id - Post ID
	 *
	 * @return mixed -- array of sequence Ids
	 */
	static public function sequences_for_post( $post_id ) {
		$c_sequence = Controller::get_instance();
		
		return $c_sequence->get_sequences_for_post( $post_id );
	}
	
	/**
	 * Returns array of sequences that a specific post_id belongs to. Will also force a migration to the V3 data
	 * structure for sequences.
	 *
	 * @param $post_id - ID of post to check
	 *
	 * @return array - Array of sequence IDs
	 */
	public function get_sequences_for_post( $post_id ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "Check whether we've still got old post_sequences data stored. " . $utils->_who_called_me() );
		
		$post_sequences = get_post_meta( $post_id, "_post_sequences", true );
		
		if ( ! empty( $post_sequences ) ) {
			
			$utils->log( "Need to migrate to V3 sequence list for post ID {$post_id}", E20R_DEBUG_SEQ_WARNING );
			$utils->log( $post_sequences );
			
			/*            foreach ( $post_sequences as $seq_id ) {

                add_post_meta( $post_id, '_e20r_sequence_post_belongs_to', $seq_id, true ) or
                    update_post_meta( $post_id, '_e20r_sequence_post_belongs_to', $seq_id );
            }

            $utils->log("Removing old sequence list metadata");
            delete_post_meta( $post_id, '_post_sequences' );
*/
		}
		
		$utils->log( "Attempting to load sequence list for post {$post_id}" );
		$sequence_ids = get_post_meta( $post_id, '_e20r_sequence_post_belongs_to' );
		
		$sequence_count = array_count_values( $sequence_ids );
		
		foreach ( $sequence_count as $seq_id => $count ) {
			
			if ( $count > 1 ) {
				
				if ( delete_post_meta( $post_id, '_e20r_sequence_post_belongs_to', $seq_id ) ) {
					
					if ( ! add_post_meta( $post_id, '_e20r_sequence_post_belongs_to', $seq_id, true ) ) {
						
						$utils->log( "Unable to clean up the sequence list for {$post_id}" );
					}
				}
			}
		}
		
		$sequence_ids = array_unique( $sequence_ids );
		
		$utils->log( sprintf( "Loaded %d sequences that post # %d belongs to", count( $sequence_ids ), $post_id ) );
		
		return ( empty( $sequence_ids ) ? array() : $sequence_ids );
	}
	
	/**
	 * Return the Async/Background sequence handler for Sequences
	 *
	 * @return Handle_User|null
	 */
	public function get_user_handler() {
		
		return $this->user_handler;
	}
	
	/**
	 * Return the Async/Background post handler for Sequences
	 *
	 * @return Handle_Posts|null
	 */
	public function get_post_handler() {
		return $this->post_handler;
	}
	
	/**
	 * Check all sequences in the system for whether or not they've been converted to the v3 metadata format then set
	 * warning banner if not.
	 */
	public function check_conversion() {
		
		$utils = Utilities::get_instance();
		$model = Model::get_instance();
		
		$utils->log( "Check whether we need to convert any sequences" );
		$sequences = $model->get_all_sequences();
		
		foreach ( $sequences as $sequence ) {
			
			$utils->log( "Check whether we need to convert sequence # {$sequence->ID}" );
			
			if ( ! $model->is_converted( $sequence->ID ) ) {
				
				$this->set_error_msg( sprintf( __( "Required action: Please de-activate and then activate the E20R Sequences plugin (%d)", Controller::plugin_slug ), $sequence->ID ) );
			}
		}
	}
	
	/**
	 * Set the private $error value
	 *
	 * @param $msg -- The error message to set
	 *
	 * @access public
	 */
	public function set_error_msg( $msg ) {
		
		$this->error = $msg;
		$this->utils = Utilities::get_instance();
		
		if ( ! empty( $msg ) ) {
			
			$this->utils->log( "{$msg}" );
			
			$this->utils->add_message( $msg, 'error' );
		}
	}
	
	/**
	 * Configure (load) the post cache for the specific sequence ID
	 *
	 * @param $sequence_posts - array of posts that belong to the sequence
	 * @param $sequence_id    - The ID of the sequence to load the cache for
	 *
	 * @return bool - Whether the cache loaded successfully or not
	 */
	public function set_cache( $sequence_posts, $sequence_id ) {
		
		$utils = Utilities::get_instance();
		$model = Model::get_instance();
		
		// $this->delete_cache( $this->transient_key . $sequence_id);
		
		
		$c_key = $this->get_cache_key( $sequence_id );
		$utils->log( "Saving data to cache for {$sequence_id}... using {$c_key}" );
		
		if ( ! empty( $c_key ) ) {
			
			$utils->log( "Saving Cache w/a timeout of: " . self::$cache_timeout );
			$success = Cache::set( $c_key, $sequence_posts, ( self::$cache_timeout * MINUTE_IN_SECONDS ), E20R_Sequences::cache_key );
			
			$expires = $this->get_cache_expiry( $sequence_id );
			$model->set_expires( $expires );
			
			$utils->log( "Cache set to expire: {$expires}" );
			
			return true;
		}
		
		$utils->log( "Unable to update the cache for {$sequence_id}!" );
		$model->set_expires( null );
		
		return false;
		
		// wp_cache_set( $key, $value );
	}
	
	/**
	 * Test whether to show future sequence posts (i.e. not yet available to member)
	 *
	 * @return bool -- True if the admin has requested that unavailable posts not be displayed.
	 *
	 * @access public
	 */
	public function hide_upcoming_posts() {
		// $utils->log('hide_upcoming_posts(): Do we show or hide upcoming posts?');
		return ( $this->options->hideFuture == 1 ? true : false );
	}
	
	/**
	 * Whether to include the featured image(s) for the post in the post listing(s)
	 *
	 * @return bool
	 */
	public function include_featured_image_for_posts() {
		return isset( $this->options->includeFeatured ) && $this->options->includeFeatured == 1 ? true : false;
	}
	
	/**
	 *  Check whether to permit a given Post ID to have multiple entries and as a result delay values.
	 *
	 * @return bool - Depends on the setting.
	 * @access public
	 * @since  2.4.11
	 */
	public function allow_repetition() {
		$utils = Utilities::get_instance();
		$utils->log( "Returning: " . ( $this->options->allowRepeatPosts ? 'true' : 'false' ) );
		
		return $this->options->allowRepeatPosts;
	}
	
	/**
	 * Return all members who use the current sequence ID
	 *
	 * @return array|null
	 */
	public function get_users_of_sequence() {
		
		$utils = Utilities::get_instance();
		
		// TODO: Use filter to identify the  list of users who have access to this sequence
		if ( null === ( $users = Cache::get( "{$this->sequence_id}_user_list", E20R_Sequences::cache_key ) ) ) {
			
			global $wpdb;
			
			// Find all users that are active members of this sequence.
			$sql = $wpdb->prepare(
				"
                SELECT *
                FROM {$wpdb->pmpro_memberships_pages} AS pages
                    INNER JOIN {$wpdb->pmpro_memberships_users} AS users
                    ON (users.membership_id = pages.membership_id)
                WHERE pages.page_id = %d AND users.status = %s
                ORDER BY users.user_id
            ",
				$this->sequence_id,
				"active"
			);
			
			$users = $wpdb->get_results( $sql );
			
			$utils->log( "Fetched " . count( $users ) . " user records for {$this->sequence_id}" );
			
			if ( ! empty( $users ) ) {
				Cache::set( "{$this->sequence_id}_user_list", $users, ( 10 * MINUTE_IN_SECONDS ), E20R_Sequences::cache_key );
			}
		}
		
		return $users;
	}
	
	/**
	 * Adds the 'closest post' flag as "true" for the correct post.
	 *
	 * @param          $post_list - List of posts to process
	 * @param int|null $user_id   - User ID (or empty)
	 *
	 * @return array - The completed/processed post list
	 */
	public function set_closest_post( $post_list, $user_id = null ) {
		
		global $current_user;
		
		$utils = Utilities::get_instance();
		$utils->log( "Received posts: " . count( $post_list ) . " and user ID: " . ( is_null( $user_id ) ? 'None' : $user_id ) );
		
		if ( ! is_null( $user_id ) ) {
			
			$this->e20r_sequence_user_id = $user_id;
		} else if ( empty( $this->e20r_sequence_user_id ) && ( $this->e20r_sequence_user_id != $current_user->ID ) ) {
			
			$user_id = $this->e20r_sequence_user_id;
		} else {
			
			$user_id = $current_user->ID;
		}
		
		$closest_post = apply_filters( 'e20r-sequence-found-closest-post', $this->find_closest_post( $user_id ) );
		
		foreach ( $post_list as $key => $post ) {
			
			if ( isset( $post->id ) ) {
				$post_id = $post->id;
			}
			
			if ( isset( $post->ID ) ) {
				$post_id = $post->ID;
			}
			
			if ( is_array( $post_list ) && ( $post->delay == $closest_post->delay ) && ( $post_id == $closest_post->id ) ) {
				
				$utils->log( "Most current post for user {$user_id} found for post id: {$post_id}" );
				$post_list[ $key ]->closest_post = true;
			}
		}
		
		return $post_list;
	}
	
	/**
	 * Gets and returns the post_id of the post in the sequence with a delay value
	 *     closest to the number of days since startdate for the specified user ID.
	 *
	 * @param null $user_id -- ID of the user
	 *
	 * @return bool|\stdClass -- Post, Post ID or FALSE (if error)
	 *
	 * @access public
	 */
	public function find_closest_post( $user_id = null ) {
		
		$utils = Utilities::get_instance();
		$model = Model::get_instance();
		
		if ( empty( $user_id ) ) {
			
			$utils->log( "No user ID specified by callee: " . $utils->_who_called_me() );
			
			global $current_user;
			$user_id = $current_user->ID;
		}
		
		// Get the current day of the membership (as a whole day, not a float)
		$membership_day = $this->get_membership_days( $user_id );
		
		// Load posts from model
		$posts = $model->get_posts();
		
		// Load all posts in this sequence
		/*
        if ( false === $this->is_cache_valid() ) {
            $model->load_sequence_post();
        }
		*/
		$utils->log( "Have " . count( $posts ) . " posts in sequence." );
		
		// Find the post ID in the postList array that has the delay closest to the $membership_day.
		$closest = $this->find_closest_post_by_delay_val( $membership_day, $user_id );
		
		if ( isset( $closest->id ) ) {
			
			$utils->log( "For user {$user_id} on day {$membership_day}, the closest post is #{$closest->id} (with a delay value of {$closest->delay})" );
			
			return $closest;
		}
		
		return null;
	}
	
	/**
	 * Return the number of days since this users membership started
	 *
	 * @param null|int $user_id  -- ID of the user (can be NULL)
	 * @param int      $level_id -- The ID of the level we're checking gainst.
	 *
	 * @return int - number of days (decimal, possibly).
	 */
	public function get_membership_days( $user_id = null, $level_id = 0 ) {
		
		$utils     = Utilities::get_instance();
		$calc_days = 0;
		
		if ( empty( $user_id ) ) {
			global $current_user;
			$user_id = $current_user->ID;
		}
		
		$startdate = $this->get_user_startdate( $user_id, $level_id );
		$time_zone = get_option( 'timezone_string' );
		
		$utils->log( "Startdate for {$user_id}: {$startdate}" );
		
		//check we received a start date
		if ( empty( $startdate ) ) {
			
			$startdate = strtotime( 'today ' . $time_zone );
		}
		
		$current   = current_time( "timestamp" );
		$calc_days = $this->datediff( $startdate, $current, $time_zone );
		
		$utils->log( "Days since startdate: {$calc_days}" );
		
		return apply_filters( 'e20r-sequence-days-as-member', $calc_days, $user_id, $level_id );
	}
	
	/**
	 * Returns the per-sequence startdate to use for a user
	 *
	 * @param int|null $user_id -- The user ID to find the startdate for
	 * @param int|null $level_id
	 * @param int|null $sequence_id
	 *
	 * @return int  - A UNIX timestamp (seconds since start of epoch)
	 */
	public function get_user_startdate( $user_id = null, $level_id = null, $sequence_id = null ) {
		
		$timezone = get_option( 'timezone_string' );
		$utils    = Utilities::get_instance();
		
		$member_module = 'paid-memberships-pro'; // TODO: Make this dynamic?
		$user_info     = new \WP_User( $user_id );
		
		// $startdate_ts = apply_filters( 'e20r-sequence-user-startdate-ts', $user_info->user_registered, $user_info, $member_module );
		/*
		if ( function_exists( 'pmpro_getMemberStartdate' ) ) {
			$startdate_ts = pmpro_getMemberStartdate( $user_id, $level_id );
		}
		*/
		
		// we didn't get a sequence id to process for so check if it's set by default
		if ( empty( $sequence_id ) && ! empty( $this->sequence_id ) ) {
			$sequence_id = $this->sequence_id;
		}
		
		// neither default nor received sequence id to process.
		if ( empty( $sequence_id ) && empty( $this->sequence_id ) ) {
			
			$utils->log( "No Sequence ID configured. Returning NULL" );
			
			return null;
		}
		
		// if the user doesn't have a membership level...
		if ( empty( $startdate_ts ) ) {
			
			// and there's a valid Timezone setting
			if ( ! empty( $timezone ) ) {
				
				// use 'right now' local time' as their startdate.
				$utils->log( "Using timezone: {$timezone}" );
				$startdate_ts = strtotime( 'today ' . get_option( 'timezone_string' ) );
			} else {
				// or we'll use the registration date for the user.
				$user         = get_user_by( 'ID', $user_id );
				$startdate_ts = strtotime( $user->user_registered );
			}
		}
		
		$use_membership_startdate = apply_filters( 'e20r-sequence-use-membership-startdate', false );
		$user_global_startdate    = apply_filters( 'e20r-sequence-use-global-startdate', false );
		
		if ( true === $use_membership_startdate ) {
			
			// filter this so other membership modules can set the startdate too.
			$startdate_ts = apply_filters( 'e20r-sequence-mmodule-user-startdate', $startdate_ts, $user_id, $level_id, $sequence_id );
			
			$utils->log( "Filtered startdate: {$startdate_ts}" );
			
			if ( false === $user_global_startdate ) {
				// Use a per-sequence startdate
				$m_startdate_ts = get_user_meta( $user_id, "_e20r-sequence-startdate-{$sequence_id}", true );
			} else {
				// use a global startdate
				$m_startdate_ts = get_user_meta( $user_id, "_e20r-sequence-startdate-global", true );
			}
		}
		
		if ( empty( $m_startdate_ts ) ) {
			
			update_user_meta( $user_id, "_e20r-sequence-startdate-{$sequence_id}", $startdate_ts );
			$m_startdate_ts = $startdate_ts;
		}
		
		$startdate = $m_startdate_ts;
		
		$utils->log( "Using startdate value of : {$startdate}" );
		
		// finally, allow the user to filter the startdate to whatever they want it to be.
		return apply_filters( 'e20r-sequence-user-startdate', $startdate, $sequence_id, $user_id, $level_id );
	}
	
	/**
	 * Calculates the difference between two dates (specified in UTC seconds)
	 *
	 * @param int    $startdate (timestamp) - timestamp value for start date
	 * @param int    $enddate   (timestamp) - timestamp value for end date
	 * @param string $time_zone - Timezone to use (default is UTC)
	 *
	 * @return int
	 */
	private function datediff( $startdate, $enddate = null, $time_zone = 'UTC' ) {
		
		$calc_days = 0;
		$utils     = Utilities::get_instance();
		
		$utils->log( "Timezone: {$time_zone}" );
		
		if ( empty( $tz ) ) {
			$time_zone = 'UTC';
		};
		// use current day as $enddate if nothing is specified
		if ( ( is_null( $enddate ) ) && ( $time_zone == 'UTC' ) ) {
			
			$enddate = current_time( 'timestamp', true );
		} else if ( is_null( $enddate ) ) {
			
			$enddate = current_time( 'timestamp' );
		}
		
		// Create two DateTime objects
		$date_start = new \DateTime( date( 'Y-m-d', $startdate ), new \DateTimeZone( $time_zone ) );
		$date_end   = new \DateTime( date( 'Y-m-d', $enddate ), new \DateTimeZone( $time_zone ) );
		
		if ( version_compare( PHP_VERSION, E20R_SEQ_REQUIRED_PHP_VERSION, '>=' ) ) {
			
			/* Calculate the difference using 5.3 supported logic */
			$date_diff = $date_start->diff( $date_end );
			$date_diff->format( '%d' );
			//$date_diff->format('%R%a');
			
			$calc_days = $date_diff->days;
			
			// Invert the value
			if ( $date_diff->invert == 1 ) {
				$calc_days = 0 - $calc_days;
			}
		} else {
			
			// V5.2.x workaround
			$date_start_str = $date_start->format( 'U' );
			$date_end_str   = $date_end->format( 'U' );
			
			// Difference (in seconds)
			$difference = abs( $date_start_str - $date_end_str );
			
			// Convert to days.
			$calc_days = $difference * 86400; // Won't manage DST correctly, but not sure that's a problem here..?
			
			// Sign flip if needed.
			if ( gmp_sign( $date_start_str - $date_end_str ) == - 1 ) {
				$calc_days = 0 - $calc_days;
			}
		}
		
		
		/**
		 * @since 5.0 - BUG FIX: Handle negative (small) day values)
		 */
		$utils->log( "Calc days is: {$calc_days}" );
		// $calc_days = round( $calc_days, 0 );
		
		if ( $calc_days < 1 || $calc_days = 1 ) {
			$calc_days = 0;
		}
		
		return ( $calc_days + 1 );
	}
	
	/**
	 * Compares the object to the array of posts in the sequence
	 *
	 * @param $delay_comp -- Delay value to compare to
	 *
	 * @return \stdClass -- The post ID of the post with the delay value closest to the $delay_val
	 *
	 * @access private
	 */
	private function find_closest_post_by_delay_val( $delay_comp, $user_id = null ) {
		
		$model = Model::get_instance();
		
		if ( null === $user_id ) {
			
			$user_id = $this->e20r_sequence_user_id;
		}
		
		$distances = array();
		$posts     = $model->get_posts();
		
		// $utils->log( $postArr );
		
		foreach ( $posts as $key => $post ) {
			
			$n_delay           = $this->normalize_delay( $post->delay );
			$distances[ $key ] = abs( $delay_comp - ( $n_delay /* + 1 */ ) );
		}
		
		// Verify that we have one or more than one element
		if ( count( $distances ) > 1 ) {
			
			$ret_val = $posts[ array_search( min( $distances ), $distances ) ];
		} else if ( count( $distances ) == 1 ) {
			$ret_val = $posts[ $key ];
		} else {
			$ret_val = null;
		}
		
		return $ret_val;
		
	}
	
	/**
	 * Convert any date string to a number of days worth of delay (since membership started for the current user)
	 *
	 * @param $delay (int | string) -- The delay value (either a # of days or a date YYYY-MM-DD)
	 *
	 * @return mixed (int) -- The # of days since membership started (for this user)
	 *
	 * @access public
	 */
	public function normalize_delay( $delay ) {
		
		if ( $this->is_valid_date( $delay ) ) {
			
			return $this->convert_date_to_days( $delay );
		}
		
		return $delay;
	}
	
	/**
	 * Pattern recognize whether the data is a valid date format for this plugin
	 * Expected format: YYYY-MM-DD
	 *
	 * @param $data -- Data to test
	 *
	 * @return bool -- true | false
	 *
	 * @access private
	 */
	private function is_valid_date( $data ) {
		// Fixed: is_valid_date() needs to support all expected date formats...
		if ( false === strtotime( $data ) ) {
			
			return false;
		}
		
		return true;
	}
	
	/**
	 * Returns a number of days since the users membership started based on the supplied date.
	 * This allows us to mix sequences containing days since membership start and fixed dates for content drips
	 *
	 * @param $date     - Take a date in the format YYYY-MM-DD and convert it to a number of days since membership start
	 *                  (for the current member)
	 * @param $user_id  - Optional ID for the user being processed
	 * @param $level_id - Optional ID for the level of the user
	 *
	 * @return mixed -- Return the # of days calculated
	 *
	 * @access public
	 */
	public function convert_date_to_days( $date, $user_id = null, $level_id = null ) {
		
		$days = 0;
		
		if ( null == $user_id ) {
			
			if ( ! empty ( $this->e20r_sequence_user_id ) ) {
				
				$user_id = $this->e20r_sequence_user_id;
			} else {
				
				global $current_user;
				
				$user_id = $current_user->ID;
			}
		}
		
		if ( null == $level_id ) {
			
			if ( ! empty( $this->e20r_sequence_user_level ) ) {
				
				$level_id = $this->e20r_sequence_user_level;
			} else {
				
				$level = $this->get_membership_level_for_user( $user_id );
				
				if ( is_object( $level ) ) {
					$level_id = $level->id;
				} else {
					$level_id = $level;
				}
				
			}
		}
		
		$utils = Utilities::get_instance();
		
		// Return immediately if the value we're given is a # of days (i.e. an integer)
		if ( is_numeric( $date ) ) {
			return $date;
		}
		
		$utils->log( "User {$user_id}'s level ID {$level_id}" );
		
		if ( $this->is_valid_date( $date ) ) {
			// $utils->log("Using {$user_id} and {$level_id} for the credentials");
			$start_date = $this->get_user_startdate( $user_id, $level_id ); /* Needs userID & Level ID ... */
			
			if ( empty( $start_date ) && true === $this->show_all_for_admin() ) {
				
				$utils->log( "No start date specified, but admin should be shown everything" );
				
				$start_date = strtotime( "2013-01-01" );
			} else if ( empty( $start_date ) ) {
				
				$utils->log( "No start date specified, and admin shouldn't be shown everything" );
				$start_date = strtotime( "tomorrow" );
			}
			
			$utils->log( "Given date: {$date} and startdate: {$start_date} for user {$user_id} with level {$level_id}" );
			
			try {
				
				// Use PHP v5.2 and v5.3 compatible function to calculate difference
				$comp_date = strtotime( "{$date} 00:00:00" );
				$days      = $this->datediff( $start_date, $comp_date ); // current_time('timestamp')
				
			} catch ( \Exception $e ) {
				$utils->log( 'Error calculating days: ' . $e->getMessage() );
			}
		}
		
		$utils->log( "Days calculated: {$days} " );
		
		return $days;
	}
	
	/**
	 * Return a membership level object (stdClass/wpdb row) containing minimally an 'id' parameter
	 * Could also simply return false or null if the user doesn't have a level.
	 *
	 * @param int|null $user_id - Id of user (or null)
	 * @param bool     $force   - Whether to force refresh from a (possible) database table
	 *
	 * @return mixed - Object containing the level information (including an 'id' parameter.
	 */
	private function get_membership_level_for_user( $user_id = null, $force = false ) {
		
		$level = false;
		
		return apply_filters( 'e20r-sequence-mmodule-membership-level-for-user', $level, $user_id, $force );
	}
	
	/**
	 * Whether to show all current and upcoming posts in a sequence list for users with admin privilege
	 *
	 * @return bool
	 */
	public function show_all_for_admin() {
		return ( isset( $this->options->showAdmin ) && $this->options->showAdmin == 1 ? true : false );
	}
	
	public function find_posts_by_delay( $delay, $user_id = null ) {
		
		$found_posts = array();
		
		$utils = Utilities::get_instance();
		$model = Model::get_instance();
		
		$posts = $model->get_posts();
		
		$utils->log( "Have " . count( $posts ) . " to process" );
		
		foreach ( $posts as $post ) {
			
			if ( $post->delay <= $delay ) {
				
				$found_posts[] = $post;
			}
		}
		
		if ( empty( $posts ) ) {
			
			$found_posts = $this->find_closest_post( $user_id );
		}
		
		$utils->log( "Returning " . count( $found_posts ) . " with delay value <= {$delay}" );
		
		return $found_posts;
		
	}
	
	/**
	 * Test whether a post belongs to a sequence & return a stdClass containing Sequence specific meta for the post ID
	 *
	 * @param int $post_id - Post ID to search for.
	 *
	 * @return array - Array of The sequence specific post data for the specified post_id.
	 *
	 * @access public
	 */
	public function get_post_details( $post_id ) {
		
		$post_list = $this->find_by_id( $post_id );
		
		return $post_list;
	}
	
	/**
	 * Configure meta box for the normal Post/Page editor
	 *
	 * @param string   $post_type
	 * @param \WP_Post $post
	 */
	public function post_metabox( $post_type = null, $post = null ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "Post metaboxes being configured" );
		$seq_view = Sequence_Views::get_instance();
		
		global $load_e20r_sequence_admin_script;
		
		$load_e20r_sequence_admin_script = true;
		$this->managed_types             = apply_filters( 'e20r-sequence-managed-post-types', array( 'post', 'page' ) );
		
		foreach ( $this->managed_types as $post_type ) {
			
			$utils->log( "Prepare metabox for: {$post_type}" );
			
			if ( $post_type !== 'e20r_sequence' ) {
				
				add_meta_box( 'e20r-seq-post-meta', __( 'Drip Feed Settings', Controller::plugin_slug ), array(
					$seq_view,
					'render_post_edit_metabox',
				), $post_type, 'side', 'high' );
			}
		}
	}
	
	/**
	 * Add the actual meta box definitions as add_meta_box() functions (3 meta boxes; One for the page meta,
	 * one for the Settings & one for the sequence posts/page definitions.
	 *
	 * @access public
	 */
	public function define_metaboxes() {
		
		$seq_view = Sequence_Views::get_instance();
		$utils    = Utilities::get_instance();
		$utils->log( "Loading sequence settings meta box" );
		
		do_action( 'e20r-sequences-mmodule-load-metabox' );
		
		// Sequence settings box (for posts & pages)
		add_meta_box( 'e20r-sequence-settings', __( 'Settings for Sequence', Controller::plugin_slug ), array(
			$seq_view,
			'settings',
		), 'e20r_sequence', 'side', 'high' );
		
		$utils->log( "Loading sequence post list meta box" );
		
		//sequence meta box (List of posts/pages in sequence)
		add_meta_box( 'e20r_sequence_meta', __( 'Posts in Sequence', Controller::plugin_slug ), array(
			$seq_view,
			"sequence_list_metabox",
		), 'e20r_sequence', 'normal', 'high' );
	}
	
	/**
	 * Get all posts with status 'published', 'draft', 'scheduled', 'pending review' or 'private' from the DB
	 *
	 * @return array | bool -- All posts of the post_types defined in the e20r_sequencepost_types filter)
	 *
	 * @access private
	 */
	public function get_posts_from_db() {
		
		$post_types = apply_filters( "e20r-sequence-managed-post-types", array( "post", "page" ) );
		$status     = apply_filters( "e20r-sequence-can-add-post-status", array(
			'publish',
			'future',
			'pending',
			'private',
		) );
		
		$arg_array = array(
			'post_status'            => $status,
			'posts_per_page'         => - 1,
			'post_type'              => $post_types,
			'orderby'                => 'modified',
//            'order' => 'DESC',
			'cache_results'          => true,
			'update_post_meta_cache' => true,
		);
		
		$all_posts = new \WP_Query( $arg_array );
		$posts     = $all_posts->get_posts();
		
		if ( ! empty( $posts ) ) {
			
			return $posts;
		} else {
			
			return false;
		}
		
	}
	
	/**
	 * Get the sort order variable content
	 *
	 * @return int - The sort order for the sequence ( SORT_ASC | SORT_DESC )
	 */
	public function get_sort_order() {
		
		if ( isset( $this->options->sortOrder ) ) {
			
			return $this->options->sortOrder;
		}
		
		return SORT_ASC;
	}
	
	/**
	 * Show list of sequence posts at the bottom of the specific sequence post.
	 *
	 * @param $content -- The content to process as part of the filter action
	 *
	 * @return string -- The filtered content
	 *
	 * @throws \Exception
	 *
	 * @since 5.0 - BUG FIX: Don't show length of membership if the user isn't logged in
	 */
	public function display_sequence_content( $content ) {
		
		global $post;
		global $pagenow;
		global $current_user;
		
		$utils = Utilities::get_instance();
		
		if ( ( $pagenow == 'post-new.php' || $pagenow == 'edit.php' || $pagenow == 'post.php' ) ) {
			
			return $content;
		}
		
		if ( is_singular() && is_main_query() && ( 'e20r_sequence' == $post->post_type ) && $this->has_membership_access( $post->ID, $current_user->ID ) ) {
			
			global $load_e20r_sequence_script;
			$utils = Utilities::get_instance();
			
			$load_e20r_sequence_script = true;
			
			$utils->log( "E20R Sequence display {$post->ID} - " . get_the_title( $post->ID ) . " : " . $utils->_who_called_me() . ' and page base: ' . $pagenow );
			
			
			try {
				$this->init( $post->ID );
			} catch ( \Exception $exception ) {
				return $utils->get_message() . $content;
			}
			
			/**
			 * @since 5.0 - BUG FIX: Don't show length of membership if the user isn't logged in
			 */
			// If we're supposed to show the "days of membership" information, adjust the text for type of delay.
			if ( is_user_logged_in() && intval( $this->options->lengthVisible ) == 1 ) {
				
				$content .= sprintf( "<p>%s</p>", sprintf( __( "You are on day %s of your membership", Controller::plugin_slug ), $this->get_membership_days() ) );
			}
			
			// Add the list of posts in the sequence to the content.
			$content .= $this->get_post_list_as_html();
		}
		
		return $content;
	}
	
	/**
	 * Decide whether the user ID should have access to the post_id
	 * Anticipates a return value consisting of a 3 element array:
	 * array(
	 *          0 => boolean (true/false to indicate whether $user_id has access to $f_seq_id (sequence id/post id for
	 *          sequence definition)
	 *          1 => array( numeric list of membership type/level IDs that have access to this sequence id )
	 *          2 => array( string list of membership level names/human readable identifiers that reflect the order of
	 *          the numeric array in 1 )
	 * )
	 *
	 * @param int|null $post_id - the post ID to check
	 * @param int|null $user_id - The user ID to check
	 * @param bool     $return_membership_levels
	 *
	 * @return mixed|boolean
	 *
	 * @since 4.2.6
	 */
	private function has_membership_access( $post_id = null, $user_id = null, $return_membership_levels = true ) {
		
		// Default is to deny access if there is no membership module to manage it.
		if ( true === $return_membership_levels ) {
			$access = array(
				false,
				null,
				__( 'No membership level found', Controller::plugin_slug ),
			);
		} else {
			$access = false;
		}
		
		$utils = Utilities::get_instance();
		$utils->log( "Testing access for post # {$post_id} by user {$user_id} via membership function(s)" );
		
		$show_for_admin = $this->show_all_for_admin();
		
		if ( true === $show_for_admin && true === current_user_can( 'manage_options' ) ) {
			
			$utils->log( "Admin is looking at {$post_id}, so returning true for access" );
			
			if ( true === $return_membership_levels ) {
				$access = array(
					true,
					null,
					null,
				);
			} else {
				$access = true;
			}
			
			return $access;
		}
		
		$post = get_post( $post_id );
		$user = get_user_by( 'ID', $user_id );
		
		return apply_filters( 'e20r-sequence-membership-access', $access, $post, $user, $return_membership_levels );
	}
	
	/**
	 * Initialize the sequence and load its posts
	 *
	 * @param null $id -- (optional) ID of the sequence we'd like to start/init.
	 *
	 * @return bool|int -- ID of sequence if it successfully gets loaded
	 * @throws \Exception -- Sequence to load/init wasn't identified (specified).
	 */
	public function init( $id = null ) {
		
		global $current_user;
		$utils = Utilities::get_instance();
		$model = Model::get_instance();
		
		if ( ! is_null( $id ) ) {
			
			$this->sequence = get_post( $id );
			$utils->log( 'Loading the "' . get_the_title( $id ) . '" sequence settings' );
			
			// Set options for the sequence
			$this->get_options( $id );
			
			if ( ( 0 != $current_user->ID || empty( $current_user->ID ) && true === $this->is_cron ) ) {
				$utils->log( 'init() - Loading the "' . get_the_title( $id ) . "\" sequence posts for {$current_user->ID}" );
				$model->load_sequence_post();
			}
			
			$posts = $model->get_posts();
			
			if ( empty( $posts ) && ( ! $model->is_converted( $id ) ) ) {
				
				$utils->log( "Need to convert sequence with ID {$id } to version 3 format!" );
			}
			
			$utils->log( 'Init complete' );
			
			return $this->sequence_id;
		}
		
		if ( ( $id == null ) && ( $this->sequence_id == 0 ) ) {
			throw new \Exception( __( 'No sequence ID specified.', Controller::plugin_slug ) );
		}
		
		return false;
	}
	
	/**
	 * Fetches the posts associated with this sequence, then generates HTML containing the list.
	 *
	 * @param bool $echo -- Whether to immediately 'echo' the value or return the HTML to the calling function
	 *
	 * @return bool|mixed|string -- The HTML containing the list of posts in the sequence
	 *
	 * @access public
	 * @throws \Exception
	 */
	public function get_post_list_as_html( $echo = false ) {
		
		$utils = Utilities::get_instance();
		$model = Model::get_instance();
		
		$utils->log( "Generate HTML list of posts for sequence #: {$this->sequence_id}" );
		$posts = $model->get_posts();
		
		//global $current_user;
		// $model->load_sequence_post(); // Unnecessary
		
		if ( ! empty( $posts ) ) {
			
			$seq_views = Sequence_Views::get_instance();
			
			// TODO: Have upcoming posts be listed before or after the currently active posts (own section?) - based on sort setting
			try {
				$content = $seq_views->create_sequence_list( true, 30, true, null, false );
			} catch ( \Exception $exception ) {
				$utils->log( "Unable to create list of sequence content for {$this->sequence_id}: " . $exception->getMessage() );
				
				return null;
			}
			
			if ( $echo ) {
				
				echo $content;
			}
			
			return $content;
		}
		
		return false;
	}
	
	/**
	 * Filter the message for users to check for sequence info.
	 *
	 * @param string $text (string) -- The text to filter
	 *
	 * @throws \Exception
	 *
	 * @return string -- the filtered text
	 */
	public function text_filter( $return_text ) {
		
		global $current_user;
		global $post;
		global $pagenow;
		
		$utils = Utilities::get_instance();
		
		if ( ( $pagenow == 'post-new.php' || $pagenow == 'edit.php' || $pagenow == 'post.php' ) ) {
			
			return $return_text;
		}
		
		$delay          = null;
		$post_id        = null;
		$post_sequences = array();
		
		//Update text. The user either will have to wait or sign up.
		$insequence = false;
		$level_info = array();
		
		if ( ! empty( $current_user ) && ( ! empty( $post ) ) ) {
			
			$utils->log( "Current sequence ID: {$this->sequence_id} vs Post ID: {$post->ID}" );
			
			$post_sequences   = $this->get_sequences_for_post( $post->ID );
			$days_since_start = $this->get_membership_days( $current_user->ID );
			
			foreach ( $post_sequences as $post_sequence ) {
				
				$utils->log( "Checking access to {$post_sequence}" );
				
				$access = $this->has_sequence_access( $current_user->ID, $post_sequence );
				
				if ( ! is_array( $access ) && false === $access ) {
					
					$level = array();
					
					foreach ( $this->e20r_sequence_user_level as $level_id ) {
						$level = pmpro_getLevel( $level_id )->name;
					}
					
					$utils->log( "Generating access array entry for {$post_sequence}" );
					$level_info[ $post_sequence ] = array(
						'name'   => $level,
						'link'   => add_query_arg( 'level', $this->e20r_sequence_user_level, pmpro_url( 'checkout' ) ),
						'access' => $access,
					);
					
				} else if ( is_array( $access ) ) {
					
					$utils->log( "Using supplied access array for {$post_sequence}" );
					$level_info[ $post_sequence ] = array(
						'name'   => $access[2][0],
						'link'   => add_query_arg( 'level', $access[1][0], pmpro_url( 'checkout' ) ),
						'access' => $access[0],
					);
				}
				
				$utils->log( "Level info: " . print_r( $level_info, true ) );
				
				if ( ( is_array( $access ) && true == $access[0] ) || ( ! is_array( $access ) && true == $access ) ) {
					
					$utils->log( "It's possible user has access to sequence: {$post_sequence} " );
					$insequence = $post_sequence;
					
					try {
						$this->init( $post_sequence );
					} catch ( \Exception $exception ) {
						return $this->display_error() . $return_text;
					}
					
					$post_list = $this->find_by_id( $post->ID );
					$result    = array();
					
					foreach ( $post_list as $post_key => $post_data ) {
						
						if ( $days_since_start >= $post_data->delay ) {
							$result[] = $post_data;
						}
					}
					
					if ( ! empty( $result ) ) {
						
						$delay   = $result[0]->delay;
						$post_id = $result[0]->id;
					} else {
						$utils->log( "Didn't add any delay/post info???" );
					}
				}
				
				if ( false !== $insequence ) {
					
					//user has one of the sequence levels, find out which one and tell him how many days left
					$return_text = sprintf( "%s<br/>",
						sprintf(
							'%1$s <span class="e20r-sequences-required-levels"> %2$s </span><a href="%3$s">%4$s</a>',
							__( "This content is only available to existing members at the specified time or day.", Controller::plugin_slug ),
							sprintf(
								__( 'Required %1$s:', Controller::plugin_slug ),
								__( "membership", Controller::plugin_slug )
							),
							get_permalink( $post_sequence ),
							get_the_title( $post_sequence )
						)
					); // End of return_text
					
					if ( ! empty( $delay ) && ! empty( $post_id ) ) {
						
						switch ( $this->options->delayType ) {
							
							case 'byDays':
								
								switch ( $this->options->showDelayAs ) {
									
									case E20R_SEQ_AS_DAYNO:
										
										$return_text .= sprintf(
											'<span class="e20r-sequence-delay-prompt">%s</span>',
											sprintf(
												__( 'You will be able to access "%s" on day %s of your %s', Controller::plugin_slug ),
												get_the_title( $post_id ),
												$this->display_proper_delay( $delay ),
												__( "membership", Controller::plugin_slug )
											)
										);// End of return_text
										break;
									
									case E20R_SEQ_AS_DATE:
										
										$return_text .= sprintf(
											'<span class="e20r-sequence-delay-prompt">%s</span>',
											sprintf(
												__( 'You will be able to  access "%1$s" on %2$s', Controller::plugin_slug ),
												get_the_title( $post_id ),
												$this->display_proper_delay( $delay )
											)
										);
										break; // End of return_textk;
								}
								
								break;
							
							case 'byDate':
								$return_text .= sprintf( '<span class="e20r-sequence-delay-prompt">%1$s</span>',
									sprintf(
										__( 'You will be able to access "%1$s" on %2$s', Controller::plugin_slug ),
										get_the_title( $post_id ),
										$delay
									)
								); // End of return_text
								break;
							
							default:
							
						}
					}
					
				} else {
					
					$utils->log( "Level info: " . print_r( $level_info, true ) );
					
					// User has to sign up for one of the sequence(s)
					if ( 1 == count( $post_sequences ) ) {
						
						$tmp_info   = $post_sequences;
						$tmp_seq_id = array_pop( $tmp_info );
						
						$return_text = sprintf(
							"%s<br/>",
							sprintf( '%1$s <span class="e20r-sequences-required-levels">%2$s</span><a href="%3$s">%4$s</a>',
								sprintf(
									__( 'This content is only available to active %1$s who have logged in.', Controller::plugin_slug ),
									__( 'members', Controller::plugin_slug )
								),
								sprintf(
									__( 'Required %s: ', Controller::plugin_slug ),
									__( "membership(s)", Controller::plugin_slug )
								),
								( isset( $level_info[ $tmp_seq_id ]['link'] ) ? $level_info[ $tmp_seq_id ]['link'] : pmpro_url( 'levels' ) ),
								( isset( $level_info[ $tmp_seq_id ]['name'] ) ? $level_info[ $tmp_seq_id ]['name'] : 'Unknown' )
							)
						); // End of return_text
						
					} else {
						
						$seq_links = array();
						
						foreach ( $post_sequences as $sequence_id ) {
							// $level =$level_info[$sequence_id];
							
							$seq_links[] = sprintf(
								'<a href="%1$s">%2$s</a>&nbsp;',
								( isset( $level_info[ $sequence_id ]['link'] ) ? $level_info[ $sequence_id ]['link'] : pmpro_url( 'levels' ) ),
								( isset( $level_info[ $sequence_id ]['name'] ) ? $level_info[ $sequence_id ]['name'] : 'Unknown' )
							);
						}
						
						$return_text = sprintf( '<p>%1$s</p>',
							sprintf( '%1$s <span class="e20r-sequences-required-levels">%2$s</span>',
								sprintf(
									__( 'This content is only available to active %1$s who have logged in.', Controller::plugin_slug ),
									__( 'members', Controller::plugin_slug ) // %1
								), // %1$s
								sprintf(
									__( 'Required %1$s: %2$s', Controller::plugin_slug ),
									__( "membership(s)", Controller::plugin_slug ),
									implode(
										sprintf( ', %1$s ',
											__( 'or', Controller::plugin_slug )
										),
										$seq_links
									)
								)
							)
						); // End of return_text
					}
				}
			}
		}
		
		return apply_filters( 'e20r-sequence-post-text-access-filter-value', $return_text, $post_sequences, $level_info, $delay, $post_id, $insequence );
	}
	
	/**
	 * Validate if user ID has access to the sequence
	 *
	 * @param      $user_id
	 * @param null $sequence_id
	 *
	 * @return bool
	 **/
	public function has_sequence_access( $user_id, $sequence_id = null ) {
		
		if ( is_null( $sequence_id ) && empty( $this->sequence_id ) ) {
			return true;
		}
		
		if ( ( ! empty( $sequence_id ) ) && ( 'e20r_sequence' != get_post_type( $sequence_id ) ) ) {
			
			// Not a E20R Sequence CPT post_id
			return true;
		}
		
		$results = $this->has_membership_access( $sequence_id, $user_id, true );
		
		if ( is_array( $results ) ) {
			
			$this->e20r_sequence_user_level = $results[1];
			
			return $results;
		}
		
		return false;
	}
	
	/**
	 * Display the error message (if it's defined).
	 */
	public function display_error() {
		
		$utils = Utilities::get_instance();
		$utils->log( "Display error message(s), if there are any" );
		global $current_screen;
		
		if ( empty( $this->utils ) ) {
			$this->utils = Utilities::get_instance();
		}
		
		$this->utils->display_messages();
	}
	
	/**
	 * Selects & formats the correct delay value in the list of posts, based on admin settings
	 *
	 * @param $delay (int) -- The delay value
	 *
	 * @return bool|string -- The number
	 *
	 * @access public
	 */
	public function display_proper_delay( $delay ) {
		
		if ( $this->options->showDelayAs == E20R_SEQ_AS_DATE ) {
			$utils = Utilities::get_instance();
			
			// Convert the delay to a date
			$member_days = round( $this->get_membership_days(), 0 );
			
			$delay_diff = ( $delay - $member_days );
			$utils->log( "Delay: {$delay}, memberDays: {$member_days}, delayDiff: {$delay_diff}" );
			
			return date_i18n( get_option( 'date_format' ), strtotime( "+ {$delay_diff} days" ) );
		}
		
		return $delay; // It's stored as a number, not a date
		
	}
	
	/**
	 * Used to validate whether the delay specified is less than the number of days since the member joined
	 *
	 * @param $memberFor -- How long the member has been active for (days)
	 * @param $delay     -- The specified delay to test against
	 *
	 * @return bool -- True if delay is less than the time the member has been a member for.
	 *
	 * @access public
	 */
	public function is_after_delay( $memberFor, $delay ) {
		
		$utils = Utilities::get_instance();
		
		// Get the preview offset (if it's defined). If not, set it to 0
		// for compatibility
		if ( ! isset( $this->options->previewOffset ) ) {
			
			$utils->log( "is_after_delay() - the previewOffset value doesn't exist yet {$this->options->previewOffset}. Fixing now." );
			$this->options->previewOffset = 0;
			$this->save_sequence_meta(); // Save the settings (only the first when this variable is empty)
			
		}
		
		$offset = $this->options->previewOffset;
		// $utils->log('is_after_delay() - Preview enabled and set to: ' . $offset);
		
		if ( $this->is_valid_date( $delay ) ) {
			// Fixed: Now supports DST changes (i.e. no "early or late availability" in DST timezones
			// $now = current_time('timestamp') + ($offset * 86400);
			$now = $this->get_now_and_offset( $offset );
			
			// TODO: Add support for startWhen options (once the plugin supports differentiating on when the drip starts)
			$delay_time = strtotime( $delay . ' 00:00:00.0 ' . get_option( 'timezone_string' ) );
			
			// $utils->log('is_after_delay() - Now = ' . $now . ' and delay time = ' . $delay_time );
			
			return ( $now >= $delay_time ? true : false ); // a date specified as the $delay
		}
		
		return ( ( $memberFor + $offset ) >= $delay ? true : false );
	}
	
	/**
	 * Save the settings for the seuqence to the Wordpress DB.
	 *
	 * @param $settings    (array) -- Settings for the Sequence
	 * @param $sequence_id (int) -- The ID for the Sequence
	 *
	 * @return bool - Success or failure for the save operation
	 *
	 * @access public
	 */
	public function save_sequence_meta( $settings = null, $sequence_id = 0 ) {
		// Make sure the settings array isn't empty (no settings defined)
		if ( empty( $settings ) ) {
			
			$settings = $this->options;
		}
		
		$utils = Utilities::get_instance();
		
		if ( ( $sequence_id != 0 ) && ( $sequence_id != $this->sequence_id ) ) {
			
			$utils->log( 'Unknown sequence ID. Need to instantiate the correct sequence first!' );
			
			return false;
		}
		
		try {
			
			// Update the *_postmeta table for this sequence
			update_post_meta( $this->sequence_id, '_e20r_sequence_settings', $settings );
			
			// Preserve the settings in memory / class context
			$utils->log( 'Saved Sequence Settings for ' . $this->sequence_id );
		} catch ( \Exception $exception ) {
			
			$utils->log( "Error saving sequence settings for {$this->sequence_id} Msg: " . $exception->getMessage() );
			
			return false;
		}
		
		return true;
	}
	
	/**
	 * Calculate the # of seconds to use as the offset value while respecting Timezones & Daylight Savings settings.
	 *
	 * @param int $days - Number of days for the offset value.
	 *
	 * @return int - The number of seconds in the offset.
	 */
	private function get_now_and_offset( $days ) {
		
		$seconds   = 0;
		$server_tz = get_option( 'timezone_string' );
		$utils     = Utilities::get_instance();
		
		$now = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		
		if ( $days > 1 ) {
			$day_str = "{$days} days";
		} else {
			$day_str = "{$days} day";
		}
		
		$now->modify( $day_str );
		
		$now->setTimezone( new \DateTimeZone( $server_tz ) );
		$seconds = $now->format( 'U' );
		
		$utils->log( "Offset Days: {$days} = When (in seconds): {$seconds}" );
		
		return $seconds;
	}
	
	/**
	 * Get a list of Custom Post Types to include in the list of available posts for a sequence (drip)
	 *
	 * @param $defaults -- Default post types to include (regardless)
	 *
	 * @return array -- Array of publicly available post types
	 */
	public function included_cpts( $defaults ) {
		
		$cpt_args = array(
			'public'              => true,
			'exclude_from_search' => false,
			'_builtin'            => false,
		);
		
		$output   = 'names';
		$operator = 'and';
		
		$post_types     = get_post_types( $cpt_args, $output, $operator );
		$post_type_list = array();
		
		foreach ( $post_types as $post_type ) {
			$post_type_list[] = $post_type;
		}
		
		return array_merge( $defaults, $post_type_list );
	}
	
	/**
	 * Filter e20r_has_membership_access based on sequence access.
	 *
	 * @param $hasaccess (bool) -- Current access status
	 * @param $post      (WP_Post) -- The post we're processing
	 * @param $user      (WP_User) -- The user ID we're testing
	 * @param $levels    (array) -- The membership level(s) we're testing against
	 *
	 * @return array|bool -- array|true if access is granted, false if not
	 *
	 * @throws \Exception
	 */
	public function has_membership_access_filter( $hasaccess, $post, $user, $levels ) {
		
		$utils = Utilities::get_instance();
		//See if the user has access to the specific post
		if ( isset( $post->ID ) && ! $this->is_managed( $post->ID ) ) {
			$utils->log( "Post {$post->ID} is not managed by a sequence (it is one?). Returning original access value: " . ( $hasaccess ? 'true' : 'false' ) );
			
			return $hasaccess;
		}
		
		if ( $hasaccess ) {
			
			try {
				if ( isset( $user->ID ) && isset( $post->ID ) && $this->has_post_access( $user->ID, $post->ID ) ) {
					
					$hasaccess = true;
				} else {
					$hasaccess = false;
				}
			} catch ( \Exception $exception ) {
				$utils->log( "Error checking access for user/post ({$user->ID}/{$post->ID}: " . $exception->getMessage() );
				
				return false;
			}
		}
		
		return apply_filters( 'e20r-sequence-membership-access', $hasaccess, $post, $user, $levels );
	}
	
	/**
	 * Check whether a post ($post->ID) is managed by any sequence
	 *
	 * @param $post_id - ID of post to check
	 *
	 * @return bool - True if the post is managed by a sequence
	 */
	public function is_managed( $post_id ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "Check whether post ID {$post_id} is managed by a sequence: " . $utils->_who_called_me() );
		
		$is_sequence = get_post_meta( $post_id, '_e20r_sequence_post_belongs_to' );
		$retval      = empty( $is_sequence ) ? false : true;
		
		return $retval;
	}
	
	/**
	 * Check whether $user_id has acess to $post_id
	 *
	 * @param      $user_id
	 * @param      $post_id
	 * @param bool $isAlert
	 * @param null $sequence_id
	 *
	 * @return bool
	 */
	public function has_post_access( $user_id, $post_id, $isAlert = false, $sequence_id = null ) {
		
		$utils = Utilities::get_instance();
		$model = Model::get_instance();
		
		$utils->log( "Checking access to post {$post_id} for user {$user_id} " );
		
		$existing_id = $this->sequence_id;
		$is_ok_ajax  = ( is_admin() || ( false == $this->is_cron ) && ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) && isset( $_POST['in_admin_panel'] ) ) );
		$is_editor   = apply_filters( 'e20r-sequence-has-edit-privileges', $this->user_can_edit( $user_id ) );
		
		if ( true === $is_ok_ajax && false === $is_editor ) {
			
			$utils->log( "User ({$user_id}) does not have edit permissions: " );
			$utils->log( "Editor: " . ( $is_editor ? 'true' : 'false' ) . " AJAX: " . ( $is_ok_ajax ? 'true' : 'false' ) );
			// return false;
		}
		
		$p_type = get_post_type( $post_id );
		$utils->log( "Post with ID {$post_id} is of post type '{$p_type}'..." );
		
		$post_access = $this->has_membership_access( $post_id, $user_id );
		
		if ( 'e20r_sequence' == $p_type && ( ( is_array( $post_access ) && ( false == $post_access[0] ) ) || ( ! is_array( $post_access ) && false == $post_access ) ) ) {
			
			$utils->log( "{$post_id} is a sequence and user {$user_id} does not have access to it!" );
			
			return false;
		}
		
		$retval        = false;
		$sequences     = $this->get_sequences_for_post( $post_id );
		$sequence_list = array_unique( $sequences );
		
		// is the post we're supplied is a sequence?
		if ( count( $sequence_list ) < count( $sequences ) ) {
			
			$utils->log( "Saving the pruned array of sequences" );
			
			$this->set_sequences_for_post( $post_id, $sequence_list );
		}
		
		if ( empty( $sequences ) ) {
			
			return true;
		}
		
		// TODO: Remove dependency on PMPro functions in has_post_access()
		// Does the current user have a membership level giving them access to everything?
		$all_access_lvls = apply_filters( "pmproap_all_access_levels", array(), $user_id, $post_id );
		
		if ( ! empty( $all_access_lvls ) && $this->has_membership_level( $all_access_lvls, $user_id ) ) {
			
			$utils->log( "This user ({$user_id}) has one of the 'all access' membership levels" );
			
			return true; //user has one of the all access levels
		}
		
		if ( $is_ok_ajax ) {
			$utils->log( "User is in admin panel. Allow access to the post" );
			
			return true;
		}
		
		foreach ( $sequence_list as $f_seq_id ) {
			
			if ( ! is_null( $sequence_id ) && ! in_array( $sequence_id, $sequence_list ) ) {
				
				$utils->log( "{$sequence_id} is not one of the sequences managing this ({$post_id}) post: {$f_seq_id}" );
				continue;
			}
			
			if ( is_null( $sequence_id ) && $this->sequence_id != $f_seq_id ) {
				
				$utils->log( "Loading sequence #{$f_seq_id}" );
				$this->get_options( $f_seq_id );
				$model->load_sequence_post( $sequence_id, null, $post_id );
			}
			
			$allowed_post_statuses = apply_filters( 'e20r-sequence-allowed-post-statuses', array(
				'publish',
				'future',
				'private',
			) );
			$curr_post_status      = get_post_status( $post_id );
			
			// Only consider granting access to the post if it is in one of the allowed statuses
			if ( ! in_array( $curr_post_status, $allowed_post_statuses ) ) {
				
				$utils->log( "Post {$post_id} with status {$curr_post_status} isn't accessible", E20R_DEBUG_SEQ_WARNING );
				
				return false;
			}
			
			/**
			 * Anticipates a return value of a 3 element array:
			 * array(
			 *          0 => boolean (true/false to indicate whether $user_id has access to $f_seq_id (sequence id/post id for sequence definition)
			 *          1 => array( numeric list of membership type/level IDs that have access to this sequence id )
			 *          2 => array( string list of membership level names/human readable identifiers that reflect the order of the numeric array in 1 )
			 * )
			 ***/
			try {
				$access = $this->has_membership_access( $f_seq_id, $user_id, true );
			} catch ( \Exception $exception ) {
				$utils->log( "Unable to check access for sequence/user: {$f_seq_id}/{$user_id} - " . $exception->getMessage() );
				
			}
			
			$utils->log( "Checking sequence access for membership level {$f_seq_id}: Access = " . ( $access[0] ? 'true' : 'false' ) );
			$utils->log( print_r( $access, true ) );
			
			// $usersLevels = pmpro_getMembershipLevelsForUser( $user_id );
			
			if ( true == $access[0] ) {
				
				$s_posts = $this->find_by_id( $post_id, $this->sequence_id, $user_id );
				
				if ( ! empty( $s_posts ) ) {
					
					$utils->log( "Found " . count( $s_posts ) . " post(s) in sequence {$this->sequence_id} with post ID of {$post_id}" );
					
					foreach ( $s_posts as $post ) {
						
						$utils->log( "UserID: {$user_id}, post: {$post->id}, delay: {$post->delay}, Alert: {$isAlert} for sequence: {$f_seq_id} " );
						
						if ( user_can( $user_id, 'manage_options' ) && true === $this->show_all_for_admin() ) {
							$utils->log( "Admin user and they're supposed to be able to see all content." );
							
							return true;
						}
						
						if ( $post->id == $post_id ) {
							
							foreach ( $access[1] as $level_id ) {
								
								$utils->log( "Processing for membership level ID {$level_id}" );
								
								if ( $this->options->delayType == 'byDays' ) {
									$utils->log( "Sequence {$this->sequence_id} is configured to store sequence by days since startdate" );
									
									// Don't add 'preview' value if this is for an alert notice.
									if ( ! $isAlert ) {
										
										$duration = $this->get_membership_days( $user_id, $level_id ) + $this->options->previewOffset;
									} else {
										
										$duration = $this->get_membership_days( $user_id, $level_id );
									}
									
									/**
									 * Allow user to add an offset (pos/neg integer) to the 'current day' calculation so they may
									 * offset when this user apparently started their access to the sequence
									 *
									 * @since 2.4.13
									 * @since 4.4.20 - Added 'user_id' so the filter can map user/sequence/offsets.
									 */
									$offset = apply_filters( 'e20r-sequence-add-startdate-offset', __return_zero(), $this->sequence_id, $user_id );
									
									$duration += $offset;
									
									if ( $post->delay <= $duration ) {
										
										// Set users membership Level
										$this->e20r_sequence_user_level = $level_id;
										$retval                         = true;
										break;
									}
								} else if ( $this->options->delayType == 'byDate' ) {
									$utils->log( "Sequence {$this->sequence_id} is configured to store sequence by dates" );
									// Don't add 'preview' value if this is for an alert notice.
									if ( ! $isAlert ) {
										$preview_add = ( ( 60 * 60 * 24 ) * $this->options->previewOffset );
									} else {
										$preview_add = 0;
									}
									
									/**
									 * Allow user to add an offset (pos/neg integer) to the 'current day' calculation so they may
									 * offset when this user apparently started their access to the sequence
									 *
									 * @since 2.4.13
									 */
									$offset = apply_filters( 'e20r-sequence-add-startdate-offset', __return_zero(), $this->sequence_id );
									
									$timestamp = ( current_time( 'timestamp' ) + $preview_add + ( $offset * 60 * 60 * 24 ) );
									
									$today = date( __( 'Y-m-d', Controller::plugin_slug ), $timestamp );
									
									if ( $post->delay <= $today ) {
										
										$this->e20r_sequence_user_level = $level_id;
										$retval                         = true;
										break;
									}
								} // EndIf for delayType
							}
						}
					}
				}
			}
		}
		
		$utils->log( "NO access granted to post {$post_id} for user {$user_id}" );
		
		if ( $this->sequence_id !== $existing_id ) {
			$utils->log( "Resetting sequence info for {$existing_id}" );
			
			try {
				$this->init( $existing_id );
			} catch ( \Exception $exception ) {
				$utils->log( "Unable to reset sequence info for {$existing_id}!!! " . $exception->getMessage() );
			}
		}
		
		return $retval;
	}
	
	/**
	 * Default permission check function.
	 * Checks whether the provided user_id is allowed to publish_pages & publish_posts.
	 *
	 * @param $user_id - ID of user to check permissions for.
	 *
	 * @return bool -- True if the user is allowed to edi/update
	 *
	 * @access private
	 */
	private function user_can_edit( $user_id ) {
		
		$required_permissions = apply_filters( 'e20r-sequence-required-permissions', array(
			'publish_pages',
			'publish_posts',
		) );
		
		// Default is "no access"
		$perm = false;
		
		// Make sure there are permissions to check against
		if ( ! empty( $required_permissions ) ) {
			$perm = true;
		}
		
		// Check the supplied array of permissions for the supplied user_id
		foreach ( $required_permissions as $permission ) {
			
			$perm = $perm && user_can( $user_id, $permission );
		}
		$utils = Utilities::get_instance();
		$utils->log( "User with ID {$user_id} has permission to update/edit this sequence? " . ( $perm ? 'Yes' : 'No' ) );
		
		return $perm;
	}
	
	/**
	 * Set the postmeta about the sequence(s) this post belongs to, if it's not present already
	 *
	 * @param $post_id
	 * @param $sequence_ids
	 *
	 * @return bool
	 */
	public function set_sequences_for_post( $post_id, $sequence_ids ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "Adding sequence info to post # {$post_id}" );
		
		$retval = true;
		
		$seq = get_post_meta( $post_id, '_e20r_sequence_post_belongs_to' );
		if ( is_array( $sequence_ids ) ) {
			
			$utils->log( "Received array of sequences to add to post # {$post_id}" );
			$utils->log( $sequence_ids );
			
			$sequence_ids = array_unique( $sequence_ids );
			
			foreach ( $sequence_ids as $id ) {
				
				if ( ( false === $seq ) || ( ! in_array( $id, $seq ) ) ) {
					
					$utils->log( "Not previously added. Now adding sequence ID meta ({$id}) for post # {$post_id}" );
					$retval = $retval && add_post_meta( $post_id, '_e20r_sequence_post_belongs_to', $id );
				} else {
					$utils->log( "Post # {$post_id} is already included in sequence {$id}" );
				}
			}
		} else {
			
			$utils->log( "Received sequence id ({$sequence_ids} to add for post # {$post_id}" );
			
			if ( ( false === $seq ) || ( ! in_array( $sequence_ids, $seq ) ) ) {
				
				$utils->log( "Not previously added. Now adding sequence ID meta ({$sequence_ids}) for post # {$post_id}" );
				$retval = $retval && add_post_meta( $post_id, '_e204_sequence_post_belongs_to', $sequence_ids );
			}
		}
		
		return $retval;
	}
	
	/**
	 * Check whether the specified user is a member of one of the supplied levels.
	 *
	 * @param int|array|null $levels  - Array or ID of level(s) to check against
	 * @param int|null       $user_id - Id of user to check levels for
	 *
	 * @return boolean
	 *
	 * @since 4.2.6
	 */
	private function has_membership_level( $levels = null, $user_id = null ) {
		
		$has_level = false;
		
		return apply_filters( 'e20r-sequence-mmodule-has-membership-level', $has_level, $levels, $user_id );
	}
	
	/**
	 * Check whether the specific user should receive a notice for the specific post
	 *    FALSE if the $post->delay means the today is NOT the first time this user can access the post
	 *
	 *
	 * @param $user - $wpdb object containing user info
	 * @param $post -- $sequence post object containing post ID & delay
	 *
	 * @return bool -- TRUE if we should let the user get notified about this post, false otherwise.
	 *
	 * @access public
	 */
	public function is_after_opt_in( $user_id, $settings, $post ) {
		
		// = $user_settings->sequence[ $this->sequence_id ]->optinTS;
		$utils = Utilities::get_instance();
		
		if ( $settings->optin_at != - 1 ) {
			
			$utils->log( 'User: ' . $user_id . ' Optin TS: ' . $settings->optin_at .
			             ', Optin Date: ' . date( 'Y-m-d', $settings->optin_at )
			);
			
			$delay_ts = $this->delay_as_timestamp( $post->delay, $user_id );
			$utils->log( "Timestamp for delay value: {$delay_ts}" );
			
			// Compare the Delay to the optin (subtract 24 hours worth of time from the opt-in TS)
			if ( $delay_ts >= ( $settings->last_notice_sent - DAY_IN_SECONDS ) ) {
				
				$utils->log( 'This post SHOULD be allowed to be alerted on' );
				
				return true;
			} else {
				$utils->log( 'This post should NOT be allowed to be alerted on' );
				
				return false;
			}
		} else {
			$utils->log( 'Negative opt-in timestamp value. The user  (' . $user_id . ') does not want to receive alerts' );
			
			return false;
		}
	}
	
	/**
	 * Calculate the delay for a post as a 'seconds since UNIX epoch' value
	 *
	 * @param      $delay    -- The delay value (can be a YYYY-MM-DD date string or a number)
	 * @param null $user_id  -- The User ID
	 * @param null $level_id -- The User's membership level (if applicable)
	 *
	 * @return int|string -- Returns the timestamp (seconds since epoch) for when the delay will be available.
	 *
	 * @access private
	 */
	private function delay_as_timestamp( $delay, $user_id = null, $level_id = null ) {
		
		$utils    = Utilities::get_instance();
		$delay_ts = current_time( 'timestamp', true ); // Default is 'now'
		$start_ts = $this->get_user_startdate( $user_id, $level_id );
		
		switch ( $this->options->delayType ) {
			case 'byDays':
				$delay_ts = strtotime( '+' . $delay . ' days', $start_ts );
				$utils->log( 'byDays:: delay = ' . $delay . ', delayTS is now: ' . $delay_ts . ' = ' . date_i18n( 'Y-m-d', $start_ts ) . ' vs ' . date_i18n( 'Y-m-d', $delay_ts ) );
				break;
			
			case 'byDate':
				$delay_ts = strtotime( $delay );
				$utils->log( 'byDate:: delay = ' . $delay . ', delayTS is now: ' . $delay_ts . ' = ' . date_i18( 'Y-m-d', $start_ts ) . ' vs ' . date_i18n( 'Y-m-d', $delay_ts ) );
				break;
		}
		
		return $delay_ts;
	}
	
	/**
	 * Set a Sequence option by name to the specified $value.
	 *
	 * @param $option
	 * @param $value
	 */
	public function set_option_by_name( $option, $value ) {
		$this->options->{$option} = $value;
	}
	
	/**
	 * Resets the user-specific alert settings for a specified sequence Id.
	 *
	 * @param int $user_id     - User's ID
	 * @param int $sequence_id - ID of the sequence we're clearning
	 *
	 * @return mixed - false means the reset didn't work.
	 */
	public function reset_user_alerts( $user_id, $sequence_id ) {
		
		global $wpdb;
		
		$utils = Utilities::get_instance();
		$utils->log( " Attempting to delete old-style user notices for sequence with ID: {$sequence_id}" );
		$old_style = delete_user_meta( $user_id, "{$wpdb->prefix}e20r_sequence_notices" );
		
		$utils->log( "Attempting to delete v3 style user notices for sequence with ID: {$sequence_id}" );
		$v3_style = delete_user_meta( $user_id, "e20r_sequence_id_{$sequence_id}_notices" );
		
		if ( $old_style || $v3_style ) {
			
			$utils->log( "Successfully deleted user notice settings for user {$user_id}" );
			
			return true;
		}
		
		return false;
	}
	
	/**
	 * Callback (hook) for the save_post action.
	 *
	 * If the contributor has added the necessary settings to include the post in a sequence, we'll add it.
	 *
	 * @param $post_id - The ID of the post being saved
	 */
	public function post_save_action( $post_id ) {
		
		global $current_user;
		global $post;
		$utils = Utilities::get_instance();
		
		$this->managed_types = apply_filters( 'e20r-sequence-managed-post-types', array( 'post', 'page' ) );
		
		if ( ! isset( $post->post_type ) ) {
			$utils->log( "No post type defined for {$post_id}" );
			
			return;
		}
		
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			$utils->log( "Exit during autosave" );
			
			return;
		}
		
		if ( wp_is_post_revision( $post_id ) !== false ) {
			$utils->log( "Not saving revisions ({$post_id}) to sequence" );
			
			return;
		}
		
		if ( ! in_array( $post->post_type, $this->managed_types ) ) {
			$utils->log( "Not saving delay info for {$post->post_type}" );
			
			return;
		}
		
		if ( 'trash' == get_post_status( $post_id ) ) {
			return;
		}
		
		$utils->log( "Sequences & Delays have been configured for page save. " . $utils->_who_called_me() );
		
		$seq_ids          = $utils->get_variable( 'e20r_seq-sequences', array() );
		$delays           = $utils->get_variable( 'e20r_seq-delay', array() );
		$visibility_delay = $utils->get_variable( 'e20r_seq-nonMemberAccessDelay', array() );
		
		if ( empty( $delays ) && ( ! in_array( 0, $delays ) ) ) {
			
			$this->set_error_msg( __( "Error: No delay value(s) received", Controller::plugin_slug ) );
			$utils->log( "Error: delay not specified! " );
			
			return;
		}
		
		$err_msg = null;
		
		// $already_in = $this->get_sequences_for_post( $post_id );
		// $already_in = get_post_meta( $post_id, "_post_sequences", true );
		
		$utils->log( "Saved received variable values..." );
		
		foreach ( $seq_ids as $arr_key => $seq_id ) {
			
			$utils->log( "Processing for sequence {$seq_id}" );
			
			if ( $seq_id == 0 ) {
				continue;
			}
			
			if ( $seq_id != $this->sequence_id ) {
				
				if ( ! $this->get_options( $seq_id ) ) {
					$utils->log( "Unable to load settings for sequence with ID: {$seq_id}" );
					
					return;
				}
			}
			
			$user_can = apply_filters( 'e20r-sequence-has-edit-privileges', $this->user_can_edit( $current_user->ID ) );
			
			if ( ! $user_can ) {
				
				$this->set_error_msg( __( 'Incorrect privileges for this operation', Controller::plugin_slug ) );
				$utils->log( "User lacks privileges to edit" );
				
				return;
			}
			
			if ( $seq_id == 0 ) {
				
				$utils->log( "No specified sequence or it's set to 'nothing'" );
			} else if ( is_null( $delays[ $arr_key ] ) || ( empty( $delays[ $arr_key ] ) && ! is_numeric( $delays[ $arr_key ] ) ) ) {
				
				$utils->log( "Not a valid delay value...: " . $delays[ $arr_key ] );
				$this->set_error_msg( sprintf( __( "You must specify a delay value for the '%s' sequence", Controller::plugin_slug ), get_the_title( $seq_id ) ) );
			} else {
				
				$utils->log( "Processing post {$post_id} for sequence {$this->sequence_id} with delay {$delays[$arr_key]} and visibility delay {$visibility_delay[$arr_key]}" );
				$model = Model::get_instance();
				$model->add_post( $post_id, $delays[ $arr_key ], $visibility_delay[ $arr_key ] );
			}
		}
	}
	
	/**
	 * Save the settings as metadata for the sequence
	 *
	 * @param $post_id -- ID of the sequence these options belong to.
	 *
	 * @return int | mixed - Either the ID of the Sequence or its content
	 *
	 * @access public
	 */
	public function save_post_meta( $post_id ) {
		
		global $post;
		$utils = Utilities::get_instance();
		
		// Check that the function was called correctly. If not, just return
		if ( empty( $post_id ) ) {
			
			$utils->log( 'No post ID supplied...' );
			
			return false;
		}
		
		if ( wp_is_post_revision( $post_id ) ) {
			return $post_id;
		}
		
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $post_id;
		}
		
		if ( ! isset( $post->post_type ) || ( 'e20r_sequence' != $post->post_type ) ) {
			return $post_id;
		}
		
		if ( 'trash' == get_post_status( $post_id ) ) {
			return $post_id;
		}
		
		try {
			$this->init( $post_id );
		} catch ( \Exception $exception ) {
			$utils->log( "Error loading settings for sequence {$post_id}: " . $exception->getMessage() );
			
			return $post_id;
		}
		
		$utils->log( 'save_post_meta(): Saving settings for sequence ' . $post_id );
		// $utils->log('From Web: ' . print_r($_REQUEST, true));
		
		// OK, we're authenticated: we need to find and save the data
		if ( isset( $_POST['e20r_sequence_settings_noncename'] ) ) {
			
			$utils->log( 'save_post_meta() - Have to load new instance of Sequence class' );
			
			if ( ! $this->options ) {
				$this->options = $this->default_options();
			}
			
			if ( ( $retval = $this->save_settings( $post_id ) ) === true ) {
				
				$utils->log( "save_post_meta(): Saved metadata for sequence #{$post_id} and clearing the cache" );
				$this->delete_cache( $post_id );
				
				return true;
			} else {
				return false;
			}
			
		}
		
		return false; // Default
	}
	
	/**
	 * Sanitize supplied field value(s) depending on it's data type
	 *
	 * @param $field - The data to santitize
	 *
	 * @return array|int|string
	 */
	/*
	public function sanitize( $field ) {
		
		if ( ! is_numeric( $field ) ) {
			
			if ( is_array( $field ) ) {
				
				foreach ( $field as $key => $val ) {
					$field[ $key ] = $this->sanitize( $val );
				}
			}
			
			if ( is_object( $field ) ) {
				
				foreach ( $field as $key => $val ) {
					$field->{$key} = $this->sanitize( $val );
				}
			}
			
			if ( ( ! is_array( $field ) ) && ctype_alpha( $field ) ||
			     ( ( ! is_array( $field ) ) && strtotime( $field ) ) ||
			     ( ( ! is_array( $field ) ) && is_string( $field ) )
			) {
				
				$field = sanitize_text_field( $field );
			}
			
		} else {
			
			if ( is_float( $field + 1 ) ) {
				
				$field = sanitize_text_field( $field );
			}
			
			if ( is_int( $field + 1 ) ) {
				
				$field = intval( $field );
			}
		}
		
		return $field;
	}
	*/
	
	/**
	 * Save the settings for a sequence ID as post_meta for that Sequence CPT
	 *
	 * @param $sequence_id -- ID of the sequence to save options for
	 *
	 * @return bool - Returns true if save is successful
	 */
	public function save_settings( $sequence_id ) {
		
		global $current_user;
		
		$settings = $this->options;
		$utils    = Utilities::get_instance();
		
		$utils->log( 'Saving settings for Sequence w/ID: ' . $sequence_id );
		// $utils->log($_POST);
		
		// Check that the function was called correctly. If not, just return
		if ( empty( $sequence_id ) ) {
			$utils->log( 'No sequence ID supplied...' );
			$this->set_error_msg( __( 'No sequence provided', Controller::plugin_slug ) );
			
			return false;
		}
		
		// Is this an auto save routine? If our form has not been submitted (clicked "save"), we'd probably not want to save anything yet
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			$this->set_error_msg( null );
			
			return $sequence_id;
		}
		
		// Verify that we're allowed to update the sequence data
		if ( ! $this->user_can_edit( $current_user->ID ) ) {
			$utils->log( 'save_settings(): User is not allowed to edit this post type' );
			$this->set_error_msg( __( 'User is not allowed to change settings', Controller::plugin_slug ) );
			
			return false;
		}
		
		$utils->log( "Current variables: " . print_r( $_REQUEST, true ) );
		
		if ( isset( $_POST['hidden-e20r-sequence_wipesequence'] ) && ( 1 == intval( $_POST['hidden-e20r-sequence_wipesequence'] ) ) ) {
			
			$utils->log( "Admin requested change of delay type configuration. Resetting the sequence!" );
			
			if ( $sequence_id == $this->sequence_id ) {
				
				if ( ! $this->delete_post_meta_for_sequence( $sequence_id ) ) {
					
					$utils->log( 'Unable to delete the posts in sequence # ' . $sequence_id );
					$this->set_error_msg( __( 'Unable to wipe existing posts', Controller::plugin_slug ) );
				} else {
					$utils->log( 'Reloading sequence info' );
					try {
						$this->init( $sequence_id );
					} catch ( \Exception $exception ) {
						$utils->log( "Unable to reload sequence info for {$sequence_id}! " . $exception->getMessage() );
					}
				}
			} else {
				$utils->log( "the specified sequence id and the current sequence id were different!" );
			}
		}
		
		$new_options = $this->get_options();
		
		if ( false === $new_options->loaded ) {
			
			$utils->log( "No settings loaded/set. Using default settings." );
		}
		
		$form_checkboxes = array(
			'hideFuture'       => array(),
			'showAdmin'        => array(),
			'includeFeatured'  => array(),
			'allowRepeatPosts' => array(),
			'previewOffset'    => array(
				'previewOffset' => null,
			),
			'lengthVisible'    => array(),
			'sendNotice'       => array(),
			'nonMemberAccess'  => array(
				//'nonMemberAccess'        => 0,
				'nonMemberExclusionDays' => 0,
				'nonMemberAccessDelay'   => 0,
				'nonMemberAccessChoice'  => - 1,
			
			),
		);
		
		foreach ( $this->options as $field => $value ) {
			if ( $field == 'loaded' ) {
				continue;
			}
			
			$tmp = $utils->get_variable( "e20r-sequence_{$field}", null );
			// $tmp = isset( $_POST["e20r-sequence_{$field}"] ) ? $this->sanitize( $_POST["e20r-sequence_{$field}"] ) : null;
			
			$utils->log( "Being saved: {$field} => {$tmp}" );
			
			if ( empty( $tmp ) ) {
				$tmp = $this->options->{$field};
			}
			
			$this->options->{$field} = $tmp;
			
			if ( 'noticeTime' == $field ) {
				$this->options->noticeTimestamp = $this->calculate_timestamp( $this->options->{$field} );
			}
			
			if ( in_array( $field, array_keys( $form_checkboxes ) ) ) {
				
				// Disabled the checkbox
				if ( ! isset( $_POST["e20r-sequence-checkbox_{$field}"] ) ) {
					
					$this->options->{$field} = null;
					
					// Empty all related fields
					if ( ! empty( $form_checkboxes[ $field ] ) ) {
						
						foreach ( $form_checkboxes[ $field ] as $f_key => $f_default ) {
							$utils->log( "Processing removal of value for: {$f_key}" );
							$this->options->{$f_key} = $f_default;
							unset( $_POST["e20r-sequence_{$f_key}"] );
						}
					}
				}
				
				if ( isset( $_POST["e20r-sequence-checkbox_{$field}"] ) ) {
					$utils->log( "Saving checkbox value for {$field}: " . $_POST["e20r-sequence-checkbox_{$field}"] );
					$this->options->{$field} = ( isset( $_POST["e20r-sequence-checkbox_{$field}"] ) ? intval( $_POST["e20r-sequence-checkbox_{$field}"] ) : 0 );
					if ( ! empty( $form_checkboxes[ $field ] ) ) {
						
						foreach ( $form_checkboxes[ $field ] as $f_key => $f_default ) {
							$this->options->{$f_key} = ( isset( $_POST["e20r-sequence_{$f_key}"] ) ? intval( $_POST["e20r-sequence_{$f_key}"] ) : 0 );
							$utils->log( "Saving value for: {$f_key}: {$_POST["e20r-sequence_{$f_key}"]}" );
							unset( $_POST["e20r-sequence_{$f_key}"] );
						}
					}
					
					
				} else {
					// $utils->log( "Saving non-checkbox value for {$field}: " . $_POST["e20r-sequence_{$field}"] );
					$this->options->{$field} = ( isset( $_POST["e20r-sequence_{$field}"] ) ? intval( $_POST["e20r-sequence_{$field}"] ) : 0 );
				}
			}
		}
		
		$utils->log( "Trying to save... : " . print_r( $this->options, true ) );
		
		if ( $this->options->sendNotice == 0 ) {
			
			Cron::stop_sending_user_notices( $this->sequence_id );
		}
		
		// Schedule cron jobs if the user wants us to send notices.
		if ( $this->options->sendNotice == 1 ) {
			
			$utils->log( 'Updating the cron job for sequence ' . $this->sequence_id );
			
			if ( ! Cron::update_user_notice_cron() ) {
				$utils->log( 'Error configuring cron() system for sequence ' . $this->sequence_id, E20R_DEBUG_SEQ_CRITICAL );
			}
		}
		
		$utils->log( "Flush the cache for {$this->sequence_id}" );
		$this->delete_cache( $this->sequence_id );
		
		// Save settings to WPDB
		return $this->save_sequence_meta( $this->options, $sequence_id );
	}
	
	/**
	 * Delete all post meta for sequence (can be used by delete_post action).
	 *
	 * @param int $sequence_id - Id of sequence to delete metadata for (optional)
	 *
	 * @return bool - True if successful, false on error
	 */
	public function delete_post_meta_for_sequence( $sequence_id = null ) {
		
		if ( is_null( $sequence_id ) ) {
			global $post;
			
			if ( isset( $post->ID ) && ! empty( $post->ID ) && 'e20r_sequence' == $post->post_type ) {
				$sequence_id = $post->ID;
			}
		}
		
		$retval = false;
		
		if ( delete_post_meta_by_key( "_e20r_sequence_{$sequence_id}_post_delay" ) ) {
			$retval = true;
		}
		
		foreach ( $this->posts as $post ) {
			
			if ( delete_post_meta( $post->id, "_e20r_sequence_post_belongs_to", $sequence_id ) ) {
				$retval = true;
			}
			
			if ( $retval != true ) {
				$utils = Utilities::get_instance();
				$utils->log( "ERROR deleting sequence metadata for post {$post->id}: ", E20R_DEBUG_SEQ_CRITICAL );
			}
		}
		
		
		return $retval;
	}
	
	/**
	 * Converts a timeString to a timestamp value (UTC compliant).
	 * Will use the supplied timeString to calculate & return the UTC seconds-since-epoch for that clock time tomorrow.
	 *
	 * @param $timeString (string) -- A clock value ('12:00 AM' for instance)
	 *
	 * @return int -- The calculated timestamp value
	 *
	 * @access public
	 *
	 * @since  5.0 - BUG FIX: Removed GMT part of timestamp calculation. Using local TZ instead to try and handle DST
	 *         changes
	 */
	private function calculate_timestamp( $time_string ) {
		
		if ( empty( $time_string ) ) {
			return null;
		}
		
		$utils = Utilities::get_instance();
		
		// Use local time (not UTC) for 'current time' at server location
		// This is what Wordpress apparently uses (at least in v3.9) for wp-cron.
		$timezone = get_option( 'timezone_string' );
		
		$saved_tz = ini_get( 'date.timezone' );
		$utils->log( "PHP Configured timezone: {$saved_tz} vs wordpress: {$timezone}" );
		
		// Verify the timezone to use (the Wordpress timezone)
		if ( $timezone != $saved_tz ) {
			
			if ( ! ini_set( "date.timezone", $timezone ) ) {
				$utils->log( "WARNING: Unable to set the timezone value to: {$timezone}!" );
			}
			
			$saved_tz = ini_get( 'date.timezone' );
		}
		
		// Now in the Wordpress local timezone
		$right_now = current_time( 'timestamp' );
		$time_str  = "today {$time_string} {$saved_tz}";
		
		$utils->log( "Using time string for strtotime(): {$time_str}" );
		$required_time = strtotime( $time_str, $right_now );
		
		$utils->log( "Current time: {$right_now} when using UTC vs {$required_time} in {$saved_tz}" );
		
		try {
			
			/* current time & date */
			$sched_hour = date_i18n( 'H', $required_time );
			$now_hour   = date_i18n( 'H', $right_now );
			
			$utils->log( "Timestring: {$time_string}, scheduled hour: {$sched_hour} and current hour: {$now_hour}" );
			
			$timestamp = strtotime( "today {$time_string} {$saved_tz}" );
			
			$utils->log( "{$time_string} will be ({$timestamp}) vs. " . current_time( 'timestamp', true ) );
			
			if ( $timestamp < ( current_time( 'timestamp' ) + 15 * 60 ) ) {
				$timestamp = strtotime( "+1 day", $timestamp );
			}
			
			
			/*
            $hour_diff = $sched_hour - $now_hour;
            $hour_diff += ( ( ($hour_diff == 0) && (($schedMin - $nowMin) <= 0 )) ? 0 : 1);

            if ( $hour_diff >= 1 ) {
                $utils->log('calculate_timestamp() - Assuming current day');
                $when = ''; // Today
            }
            else {
                $utils->log('calculate_timestamp() - Assuming tomorrow');
                $when = 'tomorrow ';
            }

            // Create the string we'll use to generate a timestamp for cron()
            $timeInput = $when . $time_string . ' ' . get_option('timezone_string');
            $timestamp = strtotime($timeInput);
*/
		} catch ( \Exception $exception ) {
			$utils->log( 'Error calculating timestamp: : ' . $exception->getMessage() );
		}
		
		return $timestamp;
	}
	
	/**
	 * Clear the cache for the specified sequence ID
	 *
	 * @param null $sequence_id
	 *
	 * @return bool
	 */
	public function delete_cache( $sequence_id = null ) {
		
		$direct_operation = false;
		$status           = false;
		$utils            = Utilities::get_instance();
		$model            = Model::get_instance();
		
		if ( empty( $sequence_id ) && ( isset( $_POST['e20r_sequence_id'] ) || isset( $_POST['e20r_sequence_post_nonce'] ) ) ) {
			
			$utils->log( "Attempting to clear cache during AJAX operation" );
			$direct_operation = true;
			
			wp_verify_nonce( "e20r-sequence-post", "e20r_sequence_post_nonce" );
			$sequence_id = $utils->get_variable( 'e20r_sequence_id', true );
			
			if ( is_null( $sequence_id ) ) {
				wp_send_json_error( array( array( 'message' => __( "No sequence ID specified. Can't clear cache!", Controller::plugin_slug ) ) ) );
				exit();
			}
		}
		
		$c_key  = $this->get_cache_key( $sequence_id );
		$prefix = E20R_Sequences::cache_key;
		
		$utils->log( "Removing old/stale cache data for {$sequence_id}: {$prefix}_{$c_key}" );
		$model->set_expires( null );
		
		if ( ! is_null( "{$prefix}_{$c_key}" ) ) {
			$status = delete_transient( "{$prefix}_{$c_key}" );
		}
		
		if ( ( false === $status ) && ( true === $direct_operation ) &&
		     ( isset( $_POST['e20r_sequence_id'] ) || isset( $_POST['e20r_sequence_post_nonce'] ) )
		) {
			wp_send_json_error( array( array( 'message' => __( "No cache to clear, or unable to clear the cache", Controller::plugin_slug ) ) ) );
			exit();
		}
		
		if ( ( true === $status ) && ( true === $direct_operation ) &&
		     ( isset( $_POST['e20r_sequence_id'] ) || isset( $_POST['e20r_sequence_post_nonce'] ) )
		) {
			
			wp_send_json_success();
			exit();
		}
		
		return $status;
		// return wp_cache_delete( $key, $group);
	}
	
	/**
	 * Return the single (specified) option/setting from the membership plugin
	 *
	 * @param string $option_name -- The name of the option to fetch
	 *
	 * @return mixed
	 */
	public function get_membership_setting( $option_name ) {
		
		$val = null;
		
		return apply_filters( 'e20r-sequence-mmodule-get-membership-setting', $val, $option_name );
	}
	
	/**
	 * Callback to remove the all recorded post notifications for the specific post in the specified sequence
	 */
	public function remove_post_alert_callback() {
		
		$utils = Utilities::get_instance();
		$utils->log( "Attempting to remove the alerts for a post" );
		
		check_ajax_referer( 'e20r-sequence-post-meta', 'e20r_sequence_postmeta_nonce' );
		
		$model = Model::get_instance();
		
		// Fetch the ID of the sequence to add the post to
		$sequence_id = $utils->get_variable( 'e20r_sequence_id', null );
		$post_id     = $utils->get_variable( 'e20r_sequence_post', null );
		
		if ( isset( $_POST['e20r_sequence_post_delay'] ) && ! empty( $_POST['e20r_sequence_post_delay'] ) ) {
			
			$date = preg_replace( "([^0-9/])", "", $_POST['e20r_sequence_post_delay'] );
			
			if ( ( $date == $_POST['e20r_sequence_post_delay'] ) || ( is_null( $date ) ) ) {
				
				$delay = intval( $_POST['e20r_sequence_post_delay'] );
				
			} else {
				
				$delay = sanitize_text_field( $_POST['e20r_sequence_post_delay'] );
			}
		}
		
		$utils->log( "We received sequence ID: {$sequence_id} and post ID: {$post_id}" );
		
		if ( ! is_null( $sequence_id ) && ! is_null( $post_id ) && is_admin() ) {
			
			$utils->log( "Loading settings for sequence {$sequence_id} " );
			$this->get_options( $sequence_id );
			
			$utils->log( "Requesting removal of alert notices for post {$post_id} with delay {$delay} in sequence {$sequence_id} " );
			$result = $model->remove_post_notified_flag( $post_id, $delay );
			
			if ( is_array( $result ) ) {
				
				$list = join( ', ', $result );
				wp_send_json_error( $list );
				exit();
				
			} else {
				
				wp_send_json_success();
				exit();
			}
		}
		
		wp_send_json_error( 'Missing data in AJAX call' );
		exit();
	}
	
	/**
	 * Callback for saving the sequence alert optin/optout for the current user
	 */
	public function optin_callback() {
		
		global $current_user;
		
		$buffers = $this->clearBuffers();
		$utils   = Utilities::get_instance();
		$model   = Model::get_instance();
		
		$utils->log( "Buffer content: " . print_r( $buffers ) );
		/** @noinspection PhpUnusedLocalVariableInspection */
		$result = '';
		
		try {
			
			check_ajax_referer( 'e20r-sequence-user-optin', 'e20r_sequence_optin_nonce' );
			
			$seq_id = $utils->get_variable( 'hidden_e20r_seq_id', null );
			if ( empty( $seq_id ) ) {
				
				$utils->log( 'No sequence number specified. Ignoring settings for user', E20R_DEBUG_SEQ_WARNING );
				
				wp_send_json_error( __( 'Unable to save your settings', Controller::plugin_slug ) );
				exit();
			}
			
			$user_id = $utils->get_variable( 'hidden_e20r_seq_uid', null );
			$member  = null;
			
			if ( ! empty( $user_id ) ) {
				$member = get_user_by( 'ID', $user_id );
			}
			
			$utils->log( 'Updating user settings for user #: ' . $user_id );
			
			if ( ! empty( $user_id ) ) {
				
				// Grab the metadata from the database
				// $usr_settings = get_user_meta($user_id, $wpdb->prefix . 'pmpro_sequence' . '_notices', true);
				$usr_settings = $this->load_user_notice_settings( $user_id, $seq_id );
				
			} else {
				$utils->log( 'No user ID specified. Ignoring settings!', E20R_DEBUG_SEQ_WARNING );
				
				wp_send_json_error( __( 'Unable to save your settings', Controller::plugin_slug ) );
				exit();
			}
			
			$status = $this->update_notice_settings( $member, $seq_id );
			
			// Add an empty array to store posts that the user has already been notified about
			/*                if ( empty( $usr_settings->posts ) ) {
                $usr_settings->posts = array();
            }
*/
			/* Save the user options we just defined */
			if ( $user_id == $current_user->ID ) {
				
				$utils->log( 'Opt-In Timestamp is: ' . $usr_settings->last_notice_sent );
				$utils->log( 'Saving user_meta for UID ' . $user_id . ' Settings: ' . print_r( $usr_settings, true ) );
				
				$this->save_user_notice_settings( $user_id, $usr_settings, $seq_id );
				// update_user_meta( $user_id, $wpdb->prefix . 'pmpro_sequence' . '_notices', $usr_settings );
				$status = true;
				$this->set_error_msg( null );
			} else {
				
				$utils->log( 'Error: Mismatched User IDs -- user_id: ' . $user_id . ' current_user: ' . $current_user->ID );
				$this->set_error_msg( __( 'Unable to save your settings', Controller::plugin_slug ) );
				$status = false;
			}
		} catch ( \Exception $exception ) {
			$this->set_error_msg( sprintf( __( 'Error: %s', Controller::plugin_slug ), $exception->getMessage() ) );
			$status = false;
			$utils->log( 'optin_save() - Exception error: ' . $exception->getMessage() );
		}
		
		if ( $status ) {
			wp_send_json_success();
			exit();
		} else {
			wp_send_json_error( $this->get_error_msg() );
			exit();
		}
	}
	
	/**
	 * Clear and return any error/notice/warning messages on the buffer before transmission
	 *
	 * @return string
	 */
	public function clearBuffers() {
		
		ob_start();
		
		$buffers = ob_get_clean();
		
		return $buffers;
		
	}
	
	/**
	 * Load all email alert settings for the specified user
	 *
	 * @param      $user_id     - User's ID
	 * @param null $sequence_id - The ID of the sequence
	 *
	 * @return mixed|null|\stdClass - The settings object
	 */
	public function load_user_notice_settings( $user_id, $sequence_id = null ) {
		
		$utils = Utilities::get_instance();
		
		$utils->log( "Attempting to load user settings for user {$user_id} and {$sequence_id}" );
		
		if ( empty( $sequence_id ) && ( empty( $this->sequence_id ) ) ) {
			
			$utils->log( "No sequence id defined. returning null" );
			
			return null;
		}
		
		$opt_in = get_user_meta( $user_id, "e20r_sequence_id_{$sequence_id}_notices", true );
		
		$utils->log( "V3 user alert settings configured: " . ( isset( $opt_in->send_notices ) ? 'Yes' : 'No' ) );
		
		if ( isset( $opt_in->send_notices ) && is_array( $opt_in->posts ) && in_array( '_', $opt_in->posts ) ) {
			
			$utils->log( "Cleaning up post_id/delay combinations" );
			
			foreach ( $opt_in->posts as $k => $id ) {
				
				if ( $id == '_' ) {
					
					unset( $opt_in->posts[ $k ] );
				}
			}
			
			$clean = array();
			
			foreach ( $opt_in->posts as $notified ) {
				$clean[] = $notified;
			}
			
			$opt_in->posts = $clean;
			
			$utils->log( "Current (clean?) settings: " );
			$utils->log( $opt_in );
		}
		
		if ( empty( $opt_in ) || ( ! isset( $opt_in->send_notices ) ) ) {
			
			$utils->log( "No settings for user {$user_id} and sequence {$sequence_id} found. Returning defaults." );
			$opt_in     = $this->create_user_notice_defaults();
			$opt_in->id = $sequence_id;
		}
		
		return $opt_in;
	}
	
	/**
	 * Generates a stdClass() object containing the default user notice (alert) settings
	 *
	 * @return \stdClass
	 */
	public function create_user_notice_defaults() {
		
		$utils = Utilities::get_instance();
		$utils->log( "Loading default opt-in settings" );
		$defaults = new \stdClass();
		
		$defaults->id               = $this->sequence_id;
		$defaults->send_notices     = ( $this->options->sendNotice == 1 ? true : false );
		$defaults->posts            = array();
		$defaults->optin_at         = ( $this->options->sendNotice == 1 ? current_time( 'timestamp' ) : - 1 );
		$defaults->last_notice_sent = - 1; // Never
		
		return $defaults;
	}
	
	/**
	 * Update the Email Alert Notice settings for the user (Member) and sequence
	 *
	 * @param \WP_User $member
	 * @param int      $sequence_id
	 *
	 * @return bool
	 */
	public function update_notice_settings( $member, $sequence_id ) {
		
		$utils = Utilities::get_instance();
		
		try {
			$this->init( $sequence_id );
			
		} catch ( \Exception $exception ) {
			wp_send_json_error( $this->get_error_msg() );
			wp_die();
		}
		
		$utils->log( "Updating user settings for sequence #: {$sequence_id}" );
		$settings = $this->load_user_notice_settings( $member->ID, $sequence_id );
		
		if ( isset( $usr_settings->id ) && ( $settings->id !== $sequence_id ) ) {
			
			$utils->log( "Creating default setting for user {$member->ID} and sequence {$sequence_id}" );
			
			$settings = $this->create_user_notice_defaults();
		}
		
		// $usr_settings->sequence[$seq_id]->sendNotice = ( isset( $_POST['hidden_e20r_seq_useroptin'] ) ?
		$settings->send_notices = $this->get_option_by_name( 'sendNotice' );
		
		// If the user opted in to receiving alerts, set the opt-in timestamp to the current time.
		// If they opted out, set the opt-in timestamp to -1
		if ( $settings->send_notices == 1 ) {
			
			// Fix the alert settings so the user doesn't receive old alerts.
			
			$member_days = $this->get_membership_days( $member->ID );
			$post_list   = Model::get_instance()->load_sequence_post( null, $member_days, null, '<=', null, true );
			
			$settings = $this->fix_user_alert_settings( $settings, $post_list, $member_days );
			
			// Set the timestamp when the user opted in.
			$settings->last_notice_sent = current_time( 'timestamp' );
			$settings->optin_at         = current_time( 'timestamp' );
			
		} else {
			$settings->last_notice_sent = - 1; // Opted out.
			$settings->optin_at         = - 1;
		}
		
		/* Save the user options we just defined */
		$utils->log( "Opt-In Timestamp is: {$settings->last_notice_sent}" );
		$utils->log( "Saving user_meta for UID ({$member->ID}) settings: " . print_r( $settings, true ) );
		
		$status = $this->save_user_notice_settings( $member->ID, $settings, $sequence_id );
		$this->set_error_msg( null );
		
		return $status;
	}
	
	/**
	 * Access the private $error value
	 *
	 * @return string|null|mixed -- Error message or NULL
	 * @access public
	 */
	public function get_error_msg() {
		
		$utils = Utilities::get_instance();
		
		// $e = apply_filters('get_e20rerror_class_instance', null);
		$this->utils = Utilities::get_instance();
		
		$this->error = $this->utils->get_message( 'error' );
		
		if ( ! empty( $this->error ) ) {
			
			$utils->log( "Error info found: " . print_r( $this->error, true ) );
			
			return $this->error;
		} else {
			return null;
		}
	}
	
	/**
	 * Find a Sequence option by name and return it's current setting/value
	 *
	 * @param $option - The name of the setting to return
	 *
	 * @return mixed - The setting value
	 */
	public function get_option_by_name( $option ) {
		if ( ! isset( $this->options->{$option} ) ) {
			
			return false;
		}
		
		return $this->options->{$option};
	}
	
	/**
	 * Test whether it's necessary to convert the post notification flags in the DB for the specified v3 based sequence?
	 * In the v3 format, the notifications changed from simple "delay value" to a concatenated post_id + delay value.
	 * This was to support having multiple repeating post IDs in the sequence and still notify the users on the
	 * correct delay value for that instance.
	 *
	 * @param \stdClass  $v3_seq    - The Sequence to test
	 * @param \WP_Post[] $post_list - The list of posts belonging to the sequence
	 * @param int        $member_days
	 *
	 * @return mixed
	 */
	public function fix_user_alert_settings( $v3_seq, $post_list, $member_days ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "fix_user_alert_settings() - Checking whether to convert the post notification flags for {$v3_seq->id}" );
		
		$need_to_fix = false;
		
		foreach ( $v3_seq->posts as $post_id ) {
			
			if ( false === strpos( $post_id, '_' ) ) {
				
				$utils->log( "fix_user_alert_settings() - Should to fix Post/Delay id {$post_id}" );
				$need_to_fix = true;
			}
		}
		
		if ( count( $v3_seq->posts ) < count( $post_list ) ) {
			
			$utils->log( "fix_user_alert_settings() - Not enough alert IDs (" . count( $v3_seq->posts ) . ") as compared to the posts in the sequence (" . count( $post_list ) . ")" );
			$need_to_fix = true;
		}
		
		if ( true === $need_to_fix ) {
			
			$utils->log( "fix_user_alert_settings() - The number of posts with a delay value less than {$member_days} is: " . count( $post_list ) );
			
			if ( ! empty( $v3_seq->posts ) ) {
				
				foreach ( $post_list as $post_data ) {
					
					$flag_value = "{$post_data->id}_" . $this->normalize_delay( $post_data->delay );
					
					foreach ( $v3_seq->posts as $k => $id ) {
						
						// Do we have a post ID as the identifier (and not a postID_delay flag)
						if ( $post_data->id == $id ) {
							
							$utils->log( "fix_user_alert_settings() - Replacing: {$post_data->id} -> {$flag_value}" );
							$v3_seq->posts[ $k ] = $flag_value;
						} else if ( ! in_array( $flag_value, $v3_seq->posts ) ) {
							
							$utils->log( "fix_user_alert_settings() - Should be in array, but isn't. Adding as 'already alerted': {$flag_value}" );
							$v3_seq->posts[] = $flag_value;
						}
					}
				}
			} else if ( empty( $v3_seq->posts ) && ! empty( $post_list ) ) {
				
				foreach ( $post_list as $post_data ) {
					
					$flag_value = "{$post_data->id}_" . $this->normalize_delay( $post_data->delay );
					
					$utils->log( "fix_user_alert_settings() - Should be in array, but isn't. Adding as 'already alerted': {$flag_value}" );
					$v3_seq->posts[] = $flag_value;
				}
			}
			
			$v3_seq->last_notice_sent = current_time( 'timestamp' );
		}
		
		return $v3_seq;
	}
	
	/**
	 *
	 * Save the email notice/alert settings for the specified user ID
	 *
	 * @param int       $user_id
	 * @param \stdClass $settings
	 * @param null|int  $sequence_id
	 *
	 * @return bool
	 */
	public function save_user_notice_settings( $user_id, $settings, $sequence_id = null ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "Attempting to save settings for {$user_id} and sequence {$sequence_id}" );
		
		if ( is_null( $sequence_id ) && ( empty( $this->sequence_id ) ) ) {
			
			$utils->log( "No sequence ID specified. Exiting!" );
			
			return false;
		}
		
		if ( is_null( $sequence_id ) && ( $this->sequence_id != 0 ) ) {
			
			$utils->log( "No sequence ID specified. Using {$this->sequence_id} " );
			$sequence_id = $this->sequence_id;
		}
		
		$utils->log( "Save V3 style user notification opt-in settings to usermeta for {$user_id} and sequence {$sequence_id}" );
		
		update_user_meta( $user_id, "e20r_sequence_id_{$sequence_id}_notices", $settings );
		
		$test_notices = get_user_meta( $user_id, "e20r_sequence_id_{$sequence_id}_notices", true );
		
		if ( empty( $test_notices ) ) {
			
			$utils->log( "Error saving V3 style user notification settings for ({$sequence_id}) user ID: {$user_id}" );
			
			return false;
		}
		
		$utils->log( "Saved V3 style user alert settings for {$sequence_id}" );
		
		return true;
	}
	
	/**
	 * Callback to catch request from admin to send any new Sequence alerts to the users.
	 *
	 * Triggers the cron hook to achieve it.
	 */
	public function sendalert_callback() {
		
		$utils = Utilities::get_instance();
		$utils->log( 'Processing the request to send alerts manually' );
		
		check_ajax_referer( 'e20r-sequence-sendalert', 'e20r_sequence_sendalert_nonce' );
		
		$utils->log( 'Nonce is OK' );
		
		if ( isset( $_POST['e20r_sequence_id'] ) ) {
			
			$sequence_id = intval( $_POST['e20r_sequence_id'] );
			$utils->log( 'sendalert() - Will send alerts for sequence #' . $sequence_id );
			
			do_action( 'e20r_sequence_cron_hook', array( $sequence_id ) );
			
			$utils->log( 'Completed action for sequence' );
		}
	}
	
	/**
	 * Catches ajax POSTs from dashboard/edit for CPT (clear existing sequence members (posts) )
	 *
	 * @throws \Exception
	 */
	public function sequence_clear_callback() {
		
		// Validate that the ajax referrer is secure
		check_ajax_referer( 'e20r-sequence-save-settings', 'e20r_sequence_settings_nonce' );
		
		$result   = '';
		$seq_view = Sequence_Views::get_instance();
		$utils    = Utilities::get_instance();
		
		// Clear the sequence metadata if the sequence type (by date or by day count) changed.
		if ( isset( $_POST['e20r_sequence_clear'] ) ) {
			if ( isset( $_POST['e20r_sequence_id'] ) ) {
				$sequence_id = intval( $_POST['e20r_sequence_id'] );
				
				try {
					$this->init( $sequence_id );
				} catch ( \Exception $exception ) {
					wp_send_json_error( $this->get_error_msg() );
					exit();
				}
				
				$utils->log( 'sequence_clear_callback() - Deleting all entries in sequence # ' . $sequence_id );
				
				if ( ! $this->delete_post_meta_for_sequence( $sequence_id ) ) {
					$utils->log( 'Unable to delete the posts in sequence # ' . $sequence_id, E20R_DEBUG_SEQ_CRITICAL );
					$this->set_error_msg( __( 'Could not delete posts from this sequence', Controller::plugin_slug ) );
					
				} else {
					$result = $seq_view->get_post_list_for_metabox();
				}
				
			} else {
				$this->set_error_msg( __( 'Unable to identify the Sequence', Controller::plugin_slug ) );
			}
		} else {
			$this->set_error_msg( __( 'Unknown request', Controller::plugin_slug ) );
		}
		
		// Return the status to the calling web page
		if ( $result['success'] ) {
			wp_send_json_success( $result['html'] );
			exit();
		} else {
			wp_send_json_error( $this->get_error_msg() );
			exit();
		}
	}
	
	/**
	 * Used by the Sequence CPT edit page to remove a post from the sequence being processed
	 *
	 * Process AJAX based removals of posts from the sequence list
	 *
	 * Returns 'error' message (or nothing, if success) to calling JavaScript function
	 */
	public function rm_post_callback() {
		
		$utils = Utilities::get_instance();
		$model = Model::get_instance();
		
		$utils->log( "Attempting to remove post from sequence." );
		
		global $current_user;
		
		check_ajax_referer( 'e20r-sequence-post', 'e20r_sequence_post_nonce' );
		
		/** @noinspection PhpUnusedLocalVariableInspection */
		$result = '';
		
		/** @noinspection PhpUnusedLocalVariableInspection */
		$success = false;
		
		$sequence_id = $utils->get_variable( 'e20r_sequence_id', null );
		$seq_post_id = $utils->get_variable( 'e20r_seq_post', null );
		$delay       = $utils->get_variable( 'e20r_seq_delay', null );
		
		try {
			$this->init( $sequence_id );
		} catch ( \Exception $exception ) {
			wp_send_json_error( $this->get_error_msg() );
			exit();
		}
		
		// Remove the post (if the user is allowed to)
		if ( $this->user_can_edit( $current_user->ID ) && ! is_null( $seq_post_id ) ) {
			
			$model->remove_post( $seq_post_id, $delay );
			$this->set_error_msg( sprintf( __( "'%s' has been removed", Controller::plugin_slug ), get_the_title( $seq_post_id ) ) );
			//$result = __('The post has been removed', Controller::plugin_slug);
			$success = true;
			
		} else {
			
			$success = false;
			$this->set_error_msg( __( 'Incorrect privileges: Did not update this sequence', Controller::plugin_slug ) );
		}
		
		$seq_view = Sequence_Views::get_instance();
		
		// Return the content for the new listbox (sans the deleted item)
		$result = $seq_view->get_post_list_for_metabox( true );
		
		/*
        if ( is_null( $result['message'] ) && is_null( $this->get_error_msg() ) && ($success)) {
            $utils->log('rm_post_callback() - Returning success to calling javascript');
            wp_send_json_success( $result['html'] );
			exit();
        }
        else {
            wp_send_json_success( $result );
			exit();
		}
*/
		wp_send_json_success( $result );
		exit();
	}
	
	/**
	 * Removes the sequence from managing this $post_id.
	 * Returns the table of sequences the post_id belongs to back to the post/page editor using JSON.
	 */
	public function rm_sequence_from_post_callback() {
		
		/** @noinspection PhpUnusedLocalVariableInspection */
		$success = false;
		$model   = Model::get_instance();
		$utils   = Utilities::get_instance();
		
		// $utils->log("In rm_sequence_from_post()");
		check_ajax_referer( 'e20r-sequence-post-meta', 'e20r_sequence_postmeta_nonce' );
		
		$utils->log( "NONCE is OK for e20r_sequence_rm" );
		
		$sequence_id = $utils->get_variable( 'e20r_sequence_id', null );
		$post_id     = $utils->get_variable( 'e20r_seq_post_id', null );
		$delay       = $utils->get_variable( 'e20r_seq_delay', null );
		
		try {
			$this->init( $sequence_id );
		} catch ( \Exception $exception ) {
			
			$utils->log( "Error initializing sequence (ID: {$sequence_id})" );
			wp_send_json_error( $this->get_error_msg() );
			exit();
		}
		
		$this->set_error_msg( null ); // Clear any pending error messages (don't care at this point).
		
		// Remove the post (if the user is allowed to)
		if ( current_user_can( 'edit_posts' ) && ( ! is_null( $post_id ) ) && ( ! is_null( $sequence_id ) ) ) {
			
			$utils->log( "Removing post # {$post_id} with delay {$delay} from sequence {$sequence_id}" );
			$model->remove_post( $post_id, $delay, true );
			//$result = __('The post has been removed', Controller::plugin_slug);
			$success = true;
		} else {
			
			$success = false;
			$this->set_error_msg( __( 'Incorrect privileges to remove posts from this sequence', Controller::plugin_slug ) );
		}
		
		$seq_view = Sequence_Views::get_instance();
		$result   = $seq_view->load_sequence_list_meta( $post_id, $sequence_id );
		
		if ( ! empty( $result ) && is_null( $this->get_error_msg() ) && ( $success ) ) {
			
			$utils->log( 'Returning success to caller' );
			wp_send_json_success( $result );
			exit();
		} else {
			
			wp_send_json_error( ( ! is_null( $this->get_error_msg() ) ? $this->get_error_msg() : __( 'Error clearing the sequence from this post', Controller::plugin_slug ) ) );
			exit();
		}
	}
	
	/**
	 * Determines if a post, identified by the specified ID, exist
	 * within the WordPress database.
	 *
	 * @param    int $id The ID of the post to check
	 *
	 * @return   bool          True if the post exists; otherwise, false.
	 * @since    1.0.0
	 */
	public function sequence_exists( $id ) {
		
		return is_string( get_post_status( $id ) );
	}
	
	/**
	 * Return a normalized (as 'days since membership started') number indicating the delay for the post content
	 * to become available/accessible to the user
	 *
	 * @param $post_id -- The ID of the post
	 *
	 * @return bool|int[] -- The delay value for this post (numerical - even when delayType is byDate)
	 *
	 * @access private
	 */
	public function get_delay_for_post( $post_id, $normalize = true ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "Loading post# {$post_id}" );
		
		$posts = $this->find_by_id( $post_id );
		
		$utils->log( "Found " . count( $posts ) . " posts." );
		
		if ( empty( $posts ) ) {
			$posts = array();
		}
		
		// Sort the post order by delay (Ascending)
		usort( $posts, array( $this, "sort_ascending" ) );
		
		foreach ( $posts as $k => $post ) {
			
			// BUG: Would return "days since membership start" as the delay value, regardless of setting.
			// Fix: Choose whether to normalize (but leave default as "yes" to ensure no unintentional breakage).
			if ( true === $normalize ) {
				
				$posts[ $k ]->delay = $this->normalize_delay( $post->delay );
			}
			
			$utils->log( "Delay for post with id = {$post_id} is {$posts[$k]->delay}" );
		}
		
		return $posts;
	}
	
	/**
	 * Updates the delay for a post in the specified sequence (AJAX)
	 *
	 * @throws \Exception
	 */
	public function update_delay_post_meta_callback() {
		
		$utils = Utilities::get_instance();
		$utils->log( "Update the delay input for the post/page meta" );
		
		check_ajax_referer( 'e20r-sequence-post-meta', 'e20r_sequence_postmeta_nonce' );
		
		$utils->log( "Nonce Passed for postmeta AJAX call" );
		
		$seq_id  = $utils->get_variable( 'e20r_sequence_id', null );
		$post_id = $utils->get_variable( 'e20r_sequence_post_id', null );
		
		$utils->log( "Sequence: {$seq_id}, Post: {$post_id}" );
		
		if ( ! $this->init( $seq_id ) ) {
			wp_send_json_error( $this->get_error_msg() );
			exit();
		}
		
		$seq_view = Sequence_Views::get_instance();
		$content  = $seq_view->load_sequence_list_meta( $post_id, $seq_id );
		
		wp_send_json_success( $content );
		exit();
	}
	
	/**
	 * Return the current Sequence ID
	 *
	 * @return int
	 */
	public function get_current_sequence_id() {
		
		return $this->sequence_id;
	}
	
	/**
	 * Process AJAX based additions to the sequence list
	 *
	 * Returns 'error' message (or nothing, if success) to calling JavaScript function
	 */
	public function add_post_callback() {
		
		check_ajax_referer( 'e20r-sequence-post', 'e20r_sequence_post_nonce' );
		$utils    = Utilities::get_instance();
		$seq_view = Sequence_Views::get_instance();
		$model    = Model::get_instance();
		
		global $current_user;
		
		// Fetch the ID of the sequence to add the post to
		$sequence_id = $utils->get_variable( 'e20r_sequence_id', null );
		$seq_post_id = $utils->get_variable( 'e20r_sequence_post', null );
		
		$utils->log( "We received sequence ID: {$sequence_id}" );
		
		if ( ! empty( $sequence_id ) ) {
			
			// Initiate & configure the Sequence class
			try {
				$this->init( $sequence_id );
			} catch ( \Exception $exception ) {
				
				wp_send_json_error( $this->get_error_msg() );
				exit();
			}
			
			if ( isset( $_POST['e20r_sequence_delay'] ) && ( 'byDate' == $this->options->delayType ) ) {
				
				$utils->log( "Could be a date we've been given ({$_POST['e20r_sequence_delay']}), so..." );
				
				if ( ( 'byDate' == $this->options->delayType ) && ( false != strtotime( $_POST['e20r_sequence_delay'] ) ) ) {
					
					$utils->log( "Validated that Delay value is a date." );
					$delay_val = isset( $_POST['e20r_sequence_delay'] ) ? sanitize_text_field( $_POST['e20r_sequence_delay'] ) : null;
				}
			} else {
				
				$utils->log( "Validated that Delay value is probably a day nunmber." );
				$delay_val = isset( $_POST['e20r_sequence_delay'] ) ? intval( $_POST['e20r_sequence_delay'] ) : null;
			}
			
			$utils->log( 'Checking whether delay value is correct' );
			$delay = $this->validate_delay_value( $delay_val );
			
			if ( $this->is_present( $seq_post_id, $delay ) ) {
				
				$utils->log( "Post {$seq_post_id} with delay {$delay} is already present in sequence {$sequence_id}" );
				$this->set_error_msg( __( 'Not configured to allow multiple delays for the same post/page', Controller::plugin_slug ) );
				
				wp_send_json_error( $this->get_error_msg() );
				exit();
			}
			
			// Get the Delay to use for the post (depends on type of delay configured)
			if ( $delay !== false ) {
				
				$user_can = apply_filters( 'e20r-sequence-has-edit-privileges', $this->user_can_edit( $current_user->ID ) );
				
				if ( $user_can && ! is_null( $seq_post_id ) ) {
					
					$utils->log( 'Adding post ' . $seq_post_id . ' to sequence ' . $this->sequence_id );
					
					if ( false === ( $success = $model->add_post( $seq_post_id, $delay ) ) ) {
						
						$success = false;
						$this->set_error_msg( __( sprintf( "Error adding post with ID: %s and delay value: %s to this sequence", Controller::plugin_slug ), esc_attr( $seq_post_id ), esc_attr( $delay ) ) );
					}
					
				} else {
					$success = false;
					$this->set_error_msg( __( 'Not permitted to modify the sequence', Controller::plugin_slug ) );
				}
				
			} else {
				
				$utils->log( 'Delay value was not specified. Not adding the post: ' . esc_attr( $_POST['e20r_sequencedelay'] ) );
				
				if ( empty( $seq_post_id ) && ( $this->get_error_msg() == null ) ) {
					
					$this->set_error_msg( sprintf( __( 'Did not specify a post/page to add', Controller::plugin_slug ) ) );
				} else if ( ( $delay !== 0 ) && empty( $delay ) ) {
					
					$this->set_error_msg( __( 'No delay has been specified', Controller::plugin_slug ) );
				}
				
				$delay       = null;
				$seq_post_id = null;
				
				$success = false;
				
			}
			
			if ( empty( $seq_post_id ) && ( $this->get_error_msg() == null ) ) {
				
				$success = false;
				$this->set_error_msg( sprintf( __( 'Did not specify a post/page to add', Controller::plugin_slug ) ) );
			} else if ( empty( $sequence_id ) && ( $this->get_error_msg() == null ) ) {
				
				$success = false;
				$this->set_error_msg( sprintf( __( 'This sequence was not found on the server!', Controller::plugin_slug ) ) );
			}
			
			$result = $seq_view->get_post_list_for_metabox( true );
			
			// $utils->log("Data added to sequence. Returning to calling JS script");
			
			if ( $result['success'] && $success ) {
				$utils->log( 'Returning success to javascript frontend' );
				
				wp_send_json_success( $result );
				exit();
				
			} else {
				
				$utils->log( 'Returning error to javascript frontend' );
				wp_send_json_error( $result );
				exit();
			}
		} else {
			$utils->log( "Sequence ID was 0. That's a 'blank' sequence" );
			wp_send_json_error( array( array( 'message' => __( 'No sequence specified. Did you remember to save this page first?', Controller::plugin_slug ) ) ) );
			exit();
		}
	}
	
	/**
	 * Check that the delay specified by the user is valid for this plugin
	 *
	 * @param $delay -- The value to test for validity
	 *
	 * @return bool|int|string
	 */
	public function validate_delay_value( $delay ) {
		
		$utils = Utilities::get_instance();
		$delay = ( is_numeric( $delay ) ? intval( $delay ) : esc_attr( $delay ) );
		
		if ( ( $delay !== 0 ) && ( ! empty( $delay ) ) ) {
			
			// Check that the provided delay format matches the configured value.
			if ( $this->is_valid_delay( $delay ) ) {
				
				$utils->log( 'validate_delay_value(): Delay value is recognizable' );
				
				if ( $this->is_valid_date( $delay ) ) {
					
					$utils->log( 'validate_delay_value(): Delay specified as a valid date format' );
					
				} else {
					
					$utils->log( 'validate_delay_value(): Delay specified as the number of days' );
				}
			} else {
				// Ignore this post & return error message to display for the user/admin
				// NOTE: Format of date is not translatable
				$expected_delay = ( $this->options->delayType == 'byDate' ) ? __( 'date: YYYY-MM-DD', Controller::plugin_slug ) : __( 'number: Days since membership started', Controller::plugin_slug );
				
				$utils->log( 'validate_delay_value(): Invalid delay value specified, not adding the post. Delay is: ' . $delay );
				$this->set_error_msg( sprintf( __( 'Invalid delay specified ( %1$s ). Expected format is a %2$s', Controller::plugin_slug ), $delay, $expected_delay ) );
				
				$delay = false;
			}
		} else if ( $delay === 0 ) {
			
			// Special case:
			return $delay;
			
		} else {
			
			$utils->log( 'validate_delay_value(): Delay value was not specified. Not adding the post. Delay is: ' . esc_attr( $delay ) );
			
			if ( empty( $delay ) ) {
				
				$this->set_error_msg( __( 'No delay has been specified', Controller::plugin_slug ) );
			}
		}
		
		return $delay;
	}
	
	/**
	 * Validates that the value received follows a valid "delay" format for the post/page sequence
	 *
	 * @param $delay (string) - The specified post delay value
	 *
	 * @return bool - Delay is recognized (parseable).
	 *
	 * @access private
	 */
	private function is_valid_delay( $delay ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "is_valid_delay(): Delay value is: {$delay} for setting: {$this->options->delayType}" );
		
		switch ( $this->options->delayType ) {
			case 'byDays':
				$utils->log( 'is_valid_delay(): Delay configured as "days since membership start"' );
				
				return ( is_numeric( $delay ) ? true : false );
				break;
			
			case 'byDate':
				$utils->log( 'is_valid_delay(): Delay configured as a date value' );
				
				return ( apply_filters( 'e20r-sequence-check-valid-date', $this->is_valid_date( $delay ) ) ? true : false );
				break;
			
			default:
				$utils->log( 'is_valid_delay(): NOT a valid delay value, based on config' );
				$utils->log( "is_valid_delay() - options Array: " . print_r( $this->options, true ) );
				
				return false;
		}
	}
	
	/**
	 * Check if a specific post ID is present in the currently loaded Sequence
	 *
	 * @param $post_id
	 * @param $delay
	 *
	 * @return bool|int|string
	 */
	public function is_present( $post_id, $delay ) {
		
		$utils = Utilities::get_instance();
		$model = Model::get_instance();
		
		$utils->log( "Checking whether post {$post_id} with delay {$delay} is already included in {$this->sequence_id}" );
		$posts = $model->get_posts();
		
		if ( empty( $posts ) ) {
			$utils->log( "No posts in sequence {$this->sequence_id}yet. Post was NOT found" );
			
			return false;
		}
		
		foreach ( $posts as $k => $post ) {
			
			if ( ( $post_id == $post->id ) && ( $delay == $post->delay ) ) {
				$utils->log( "Post and delay combination WAS found!" );
				
				return $k;
			}
		}
		
		$utils->log( "Post {$post_id} and delay {$delay} combination was NOT found." );
		
		return false;
	}
	
	/**
	 * Deactivate the plugin and clear our stuff.
	 */
	public function deactivation() {
		
		global $e20r_sequence_deactivating;
		$e20r_sequence_deactivating = true;
		
		flush_rewrite_rules();
		$utils = Utilities::get_instance();
		
		/*
        $sql = "
            SELECT *
            FROM {$wpdb->posts}
            WHERE post_type = 'e20r_sequence'
        ";

        $seqs = $wpdb->get_results( $sql );
        */
		
		// Easiest is to iterate through all Sequence IDs and set the setting to 'sendNotice == 0'
		$seqs = new \WP_Query(
			array(
				'post_type'      => Controller::$seq_post_type,
				'posts_per_page' => - 1,
				'fields'         => 'ids',
			)
		);
		
		// Iterate through all sequences and disable any cron jobs causing alerts to be sent to users
		foreach ( $seqs->get_posts() as $sequence_id ) {
			
			$this->get_options( $sequence_id );
			
			if ( $this->options->sendNotice == 1 ) {
				
				// Set the alert flag to 'off'
				$this->options->sendNotice = 0;
				
				// save meta for the sequence.
				$this->save_sequence_meta();
				
				Cron::stop_sending_user_notices( $sequence_id );
				
				$utils->log( 'Deactivated email alert(s) for sequence ' . $sequence_id );
			}
		}
		
		/* Unregister the default Cron job for new content alert(s) */
		Cron::stop_sending_user_notices();
		
		$utils->log( "Trigger deactivation action for modules" );
		do_action( 'e20r_sequence_module_deactivating_core', true );
	}
	
	/**
	 * Activation hook for the plugin
	 * We need to flush rewrite rules on activation/etc for the CPTs.
	 */
	public function activation() {
		
		$old_timeout = ini_get( 'max_execution_time' );
		
		$utils = Utilities::get_instance();
		$utils->log( "Processing activation event using {$old_timeout} secs as timeout" );
		
		add_filter( 'e20r-sequence-mmodule-is-active', array(
			Paid_Memberships_Pro::get_instance(),
			'is_membership_plugin_active',
		), 10, 1 );
		
		$active_plugin = apply_filters( 'e20r-sequence-mmodule-is-active', false );
		
		if ( false === $active_plugin ) {
			
			$error_msg = sprintf(
				__( 'The E20R Sequences Drip Feed plugin requires a %1$ssupported membership plugin%3$s (For instance: %2$sPaid Memberships Pro%3$s).%4$sPlease install and activate the supported membership plugin before you attempt to (re)activate E20R Sequences Drip Feed plugin.', Controller::plugin_slug ),
				'<a href="https://eighty20results.com/wordpress-plugins/e20r-sequences/supported-membership-plugin-list" target="_blank">',
				sprintf( '<a href="https://www.paidmembershipspro.com/" target="_blank" title="%1$s">', __( "Opens in a new window/tab.", Controller::plugin_slug ) ),
				'</a>',
				'<br/><br/>'
			);
			
			$utils->add_message( $error_msg, 'error', 'backend' );
		}
		
		Model::create_sequence_post_type();
		flush_rewrite_rules();
		
		/* Search for existing pmpro_series posts & import */
		Importer::import_all_series();
		
		/* Convert old metadata format to new (v3) format */
		
		$sequence = Controller::get_instance();
		$model    = Model::get_instance();
		
		$sequences = $model->get_all_sequences();
		
		$utils->log( "Found " . count( $sequences ) . " to convert" );
		
		foreach ( $sequences as $sequence ) {
			
			$utils->log( "Converting configuration meta to v3 format for {$sequence->ID}" );
			
			$model->upgrade_sequence( $sequence->ID, true );
		}
		
		/* Register the default cron job to send out new content alerts */
		Cron::schedule_default();
		
		// $sequence->convert_user_notifications();
		
		$utils->log( "Trigger activation action for modules" );
		do_action( 'e20r_sequence_module_activating_core' );
		
	}
	
	/**
	 * Configure & display the icon for the Sequence Post type (in the Dashboard)
	 */
	public function post_type_icon() {
		?>
        <style>
            #adminmenu .menu-top.menu-icon-<?php esc_attr_e( self::$seq_post_type); ?> div.wp-menu-image:before {
                font-family: FontAwesome !important;
                content: '\f160';
            }
        </style>
		<?php
	}
	
	/**
	 * Load the front-end scripts & styles
	 */
	public function register_user_scripts() {
		
		global $e20r_sequence_editor_page;
		global $load_e20r_sequence_script;
		global $post;
		
		if ( ! isset( $post->post_content ) ) {
			
			return;
		}
		
		$utils = Utilities::get_instance();
		$utils->log( "Loading user script(s) & styles" );
		
		$found_links = has_shortcode( $post->post_content, 'sequence_links' );
		$found_optin = has_shortcode( $post->post_content, 'sequence_alert' );
		
		$utils->log( "'sequence_links' or 'sequence_alert' shortcode present? " . ( $found_links || $found_optin ? 'Yes' : 'No' ) );
		
		if ( is_front_page() && ! $found_links && ! $found_optin ) {
			return;
		}
		
		if ( ( true === $found_links ) || ( true === $found_optin ) || ( 'e20r_sequence' == $this->get_post_type() ) || 'e20r_sequence' == $post->post_type ) {
			
			$load_e20r_sequence_script = true;
			
			$utils->log( "Loading client side javascript and CSS" );
			wp_register_script( 'e20r-sequence-user', E20R_SEQUENCE_PLUGIN_URL . 'js/e20r-sequences.js', array( 'jquery' ), E20R_SEQUENCE_VERSION, true );
			
			$user_styles = apply_filters( 'e20r-sequences-userstyles', null );
			wp_register_style( 'e20r-sequence', E20R_SEQUENCE_PLUGIN_URL . 'css/e20r_sequences.css', null, E20R_SEQUENCE_VERSION );
			
			// Attempt to load user style CSS file (if it exists).
			if ( file_exists( $user_styles ) ) {
				
				wp_enqueue_style( 'e20r-sequence-userstyles', $user_styles, array( 'e20r-sequence' ), E20R_SEQUENCE_VERSION );
			}
			
			wp_localize_script( 'e20r-sequence-user', 'e20r_sequence',
				array(
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
				)
			);
			
		} else {
			$load_e20r_sequence_script = false;
			$utils->log( "Didn't find the expected shortcode... Not loading client side javascript and CSS" );
		}
		
	}
	
	/**
	 * Returns the current post type of the post being processed by WP
	 *
	 * @return mixed | null - The post type for the current post.
	 */
	private function get_post_type() {
		
		global $post, $typenow, $current_screen;
		
		//we have a post so we can just get the post type from that
		if ( $post && $post->post_type ) {
			
			return $post->post_type;
		} //check the global $typenow - set in admin.php
		else if ( $typenow ) {
			
			return $typenow;
		} //check the global $current_screen object - set in sceen.php
		else if ( $current_screen && $current_screen->post_type ) {
			
			return $current_screen->post_type;
		} //lastly check the post_type querystring
		else if ( isset( $_REQUEST['post_type'] ) ) {
			
			return sanitize_key( $_REQUEST['post_type'] );
		}
		
		//we do not know the post type!
		return null;
	}
	
	/**
	 * Enqueue the user (front-end) scripts and styles for Sequences (where needed)
	 */
	public function enqueue_user_scripts() {
		
		global $load_e20r_sequence_script;
		$utils = Utilities::get_instance();
		
		$utils->log( "Enqueuing user script if applicable" );
		
		if ( wp_script_is( 'e20r-sequence-user', 'registered' ) &&
		     wp_style_is( 'e20r-sequence', 'registered' ) &&
		     true === $load_e20r_sequence_script ) {
			
			wp_enqueue_style( 'e20r-sequence' );
			wp_enqueue_script( 'e20r-sequence-user' );
		}
	}
	
	/**
	 * Add javascript and CSS for end-users on the front-end of the site.
	 * TODO: Is this a duplicate for register_user_scripts???
	 */
	/*
	public function enqueue_user_scripts() {
		
		global $load_e20r_sequence_script;
		global $post;
		
		if ( $load_e20r_sequence_script !== true ) {
			return;
		}
		
		if ( ! isset( $post->post_content ) ) {
			
			return;
		}
		
		$utils          = Utilities::get_instance();
		$foundShortcode = has_shortcode( $post->post_content, 'sequence_links' );
		
		$utils->log( "enqueue_user_scripts() - 'sequence_links' shortcode present? " . ( $foundShortcode ? 'Yes' : 'No' ) );
		wp_register_script( 'e20r-sequence-user', E20R_SEQUENCE_PLUGIN_URL . 'js/e20r-sequences.js', array( 'jquery' ), E20R_SEQUENCE_VERSION, true );
		
		// load styles
		$user_styles = apply_filters( 'e20r-sequences-userstyle-url', null );
		wp_enqueue_style( 'e20r-sequence', E20R_SEQUENCE_PLUGIN_URL . 'css/e20r_sequences.css', null, E20R_SEQUENCE_VERSION );
		
		// Attempt to load user style CSS file (if it exists).
		if ( file_exists( $user_styles ) ) {
			
			wp_enqueue_style( 'e20r-sequence-userstyles', $user_styles, array( 'e20r-sequence' ), E20R_SEQUENCE_VERSION );
		}
		
		
		wp_localize_script( 'e20r-sequence-user', 'e20r_sequence',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
			)
		);
		
		wp_print_scripts( 'e20r-sequence-user' );
	}
	*/
	
	/**
	 * Load all JS & CSS for Admin page
	 *
	 * @param string $hook
	 *
	 */
	public function register_admin_scripts( $hook ) {
		
		global $post;
		
		$utils = Utilities::get_instance();
		if ( $hook != 'post-new.php' && $hook != 'post.php' && $hook != 'edit.php' ) {
			
			$utils->log( "Unexpected Hook: {$hook}" );
			
			return;
		}
		
		$post_types   = apply_filters( "e20r-sequence-managed-post-types", array( "post", "page" ) );
		$post_types[] = 'e20r_sequence';
		
		if ( isset( $post->ID ) && ! in_array( $post->post_type, $post_types ) ) {
			$utils->log( "Incorrect Post Type: {$post->post_type}" );
			
			return;
		}
		
		$utils->log( "Loading admin scripts & styles for E20R Sequences" );
		
		$delay_config = $this->set_delay_config();
		
		// wp_register_style( 'fontawesome', E20R_SEQUENCE_PLUGIN_URL . '/css/font-awesome.min.css', false, '4.5.0' );
		wp_register_style( 'select2', "//cdnjs.cloudflare.com/ajax/libs/select2/" . self::$select2_version . "/css/select2.min.css", null, self::$select2_version );
		
		wp_register_style( 'e20r-sequence', E20R_SEQUENCE_PLUGIN_URL . 'css/e20r_sequences.css' );
		
		wp_register_script( 'select2', "//cdnjs.cloudflare.com/ajax/libs/select2/" . self::$select2_version . "/js/select2.min.js", array( 'jquery' ), self::$select2_version );
		
		wp_register_script( 'e20r-sequence-admin', E20R_SEQUENCE_PLUGIN_URL . 'js/e20r-sequences-admin.js', array(
			'jquery',
			'select2',
		), E20R_SEQUENCE_VERSION, true );
		
		/* Localize ajax script */
		wp_localize_script( 'e20r-sequence-admin', 'e20r_sequence',
			array(
				'ajaxurl'      => admin_url( 'admin-ajax.php' ),
				'ajaxtimeout'  => apply_filters( 'e20r-sequences-ajax-timeout-seconds', 10 ),
				'delay_config' => $delay_config,
				'lang'         => array(
					'alert_not_saved'           => __( "Error: This sequence needs to be saved before you can send alerts", Controller::plugin_slug ),
					'settings_not_saved'        => __( "Error: This sequence needs to be saved before you can add content to it", Controller::plugin_slug ),
					'save'                      => __( 'Update', Controller::plugin_slug ),
					'saving'                    => __( 'Saving', Controller::plugin_slug ),
					'saveSettings'              => __( 'Update Settings', Controller::plugin_slug ),
					'delay_change_confirmation' => __( 'Changing the delay type will erase all existing posts or pages in the Sequence list. (Cancel if your are unsure)', Controller::plugin_slug ),
					'saving_error_1'            => __( 'Error saving sequence post [1]', Controller::plugin_slug ),
					'saving_error_2'            => __( 'Error saving sequence post [2]', Controller::plugin_slug ),
					'remove_error_1'            => __( 'Error deleting sequence post [1]', Controller::plugin_slug ),
					'remove_error_2'            => __( 'Error deleting sequence post [2]', Controller::plugin_slug ),
					'undefined'                 => __( 'Not Defined', Controller::plugin_slug ),
					'unknownerrorrm'            => __( 'Unknown error removing post from sequence', Controller::plugin_slug ),
					'unknownerroradd'           => __( 'Unknown error adding post to sequence', Controller::plugin_slug ),
					'daysLabel'                 => __( 'Delay', Controller::plugin_slug ),
					'daysText'                  => __( 'Days to delay', Controller::plugin_slug ),
					'dateLabel'                 => __( 'Avail. on', Controller::plugin_slug ),
					'dateText'                  => __( 'Release on (YYYY-MM-DD)', Controller::plugin_slug ),
				),
			)
		);
		
	}
	
	/**
	 * Load array containing the delay type settings for the sequences in the system
	 *
	 * @return array|null - Array of used delay types (days since start / date) for the sequences in the system.
	 */
	public function set_delay_config() {
		
		$model = Model::get_instance();
		
		$sequences = $model->get_all_sequences( array( 'publish', 'pending', 'draft', 'private', 'future' ) );
		$delays    = array();
		
		//Save state for the current sequence
		$current_sequence = $this->sequence_id;
		
		foreach ( $sequences as $sequence ) {
			
			if ( $sequence->ID == 0 ) {
				continue;
			}
			
			$options                 = $this->get_options( $sequence->ID );
			$delays[ $sequence->ID ] = $options->delayType;
		}
		
		// Restore state for the sequence we're processing.
		$this->get_options( $current_sequence );
		
		return ( ! empty( $delays ) ? $delays : null );
	}
	
	/**
	 * Enqueue (load) required admin script(s) when/if needed
	 */
	public function enqueue_admin_scripts() {
		
		if ( wp_style_is( 'fontawesome', 'registered' ) ) {
			wp_enqueue_style( 'fontawesome' );
		}
		
		if ( wp_style_is( 'select2', 'registered' ) ) {
			wp_enqueue_style( 'select2' );
		}
		
		if ( wp_style_is( 'e20r-sequence', 'registered' ) ) {
			wp_enqueue_style( "e20r-sequence" );
		}
		
		if ( wp_script_is( 'select2', 'registered' ) ) {
			wp_enqueue_script( 'select2' );
		}
		
		if ( wp_script_is( 'e20r-sequence-admin', 'registered' ) ) {
			wp_enqueue_script( 'e20r-sequence-admin' );
		}
	}
	
	/**
	 * Register any and all widgets for PMPro Sequence
	 */
	public function register_widgets() {
		
		// Add widget to display a summary for the most recent post/page
		// in the sequence for the logged in user.
		register_widget( 'E20R\Sequences\Modules\Widgets\Post_Widget' );
	}
	
	/**
	 * Register any and all shortcodes for PMPro Sequence
	 */
	public function register_shortcodes() {
		
		$sl = new Sequence_Links();
		$sa = new Sequence_Alert_Optin();
		// $uc = new Upcoming_Content();
		
		
		// Generates paginated list of links to sequence members
		add_shortcode( 'sequence_links', array( $sl, 'load_shortcode' ) );
		add_shortcode( 'sequence_alert', array( $sa, 'load_shortcode' ) );
		// TODO: Implement Upcoming_Content class/shortcode
		// add_shortcode( 'upcoming_content', array( $uc, 'load_shortcode' ) );
	}
	
	/**
	 * Load and use L18N based text (if available)
	 */
	public function load_textdomain() {
		
		$locale = apply_filters( "plugin_locale", get_locale(), Controller::plugin_slug );
		$mofile = Controller::plugin_slug . "-{$locale}.mo";
		
		$mofile_local  = dirname( __FILE__ ) . "/../languages/{$mofile}";
		$mofile_global = WP_LANG_DIR . "/" . Controller::plugin_slug . "/{$mofile}";
		
		load_textdomain( Controller::plugin_slug, $mofile_global );
		load_textdomain( Controller::plugin_slug, $mofile_local );
	}
	
	/**
	 * Return error if an AJAX call is attempted by a user who hasn't logged in.
	 */
	public function unprivileged_ajax_error() {
		
		$utils = Utilities::get_instance();
		$utils->log( 'Unprivileged ajax call attempted' );
		
		wp_send_json_error( array(
			'message' => __( 'You must be logged in to edit PMPro Sequences', Controller::plugin_slug ),
		) );
		exit();
	}
	
	/**
	 * Hooks to action for sending user notifications
	 * TODO: Check whether send_user_alert_notices is redundant?
	 */
	public function send_user_alert_notices() {
		
		$utils       = Utilities::get_instance();
		$sequence_id = $utils->get_variable( 'e20r_sequence_id', null );
		$utils->log( 'Will send alerts for sequence #' . $sequence_id );

//            $sequence = apply_filters('get_sequence_class_instance', null);
//            $sequence->sequence_id = $sequence_id;
//            $sequence->get_options( $sequence_id );
		
		do_action( 'e20r_sequence_cron_hook', array( $sequence_id ) );
		
		$utils->log( 'send_user_alert_notices() - Completed action for sequence #' . $sequence_id );
		wp_redirect( add_query_arg( 'post_type', 'e20r_sequence', admin_url( 'edit.php' ) ) );
		exit();
	}
	
	/**
	 * Trigger send of any new content alert messages for a sequence in the Sequence Edit menu
	 *
	 * @param  array   $actions - Action
	 * @param \WP_Post $post    - Post object
	 *
	 * @return array- Array containing the list of actions to list in the menu
	 */
	public function send_alert_notice_from_menu( $actions, $post ) {
		
		global $current_user;
		
		$utils = Utilities::get_instance();
		
		if ( ( 'e20r_sequence' == $post->post_type ) && $this->user_can_edit( $current_user->ID ) ) {
			
			$options = $this->get_options( $post->ID );
			
			if ( 1 == $options->sendNotice ) {
				
				$utils->log( "send_alert_notice_from_menu() - Adding send action" );
				$send_url                = add_query_arg( array(
					'post'             => $post->ID,
					'action'           => 'send_user_alert_notices',
					'e20r_sequence_id' => $post->ID,
				), admin_url( 'admin.php' ) );
				$actions['send_notices'] = sprintf( '<a href="%s" title="%s" rel="permalink">%s</a>', esc_url_raw( $send_url ), __( "Send user alerts", Controller::plugin_slug ), __( "Send Notices", Controller::plugin_slug ) );
			}
		}
		
		return $actions;
	}
	
	/**
	 * Run the action(s) to load the membership module sign-up hook.
	 * I.e. for PMPro it's the opportunity to hook into pmpro_after_checkout
	 */
	public function membership_signup_hooks() {
		
		do_action( 'e20r_sequence_load_membership_signup_hook' );
	}
	
	/**
	 * All published sequences that are protected by the specified PMPro Membership Level
	 *
	 * @param int $membership_level_id - the Level ID
	 *
	 * @return array - array of sequences;
	 */
	public function sequences_for_membership_level( $membership_level_id ) {
		
		return apply_filters( 'e20r-sequences-protected-by-membership-level', null, $membership_level_id );
	}
	
	public function set_class_name() {
		return Controller::plugin_slug;
	}
	
	/**
	 * Configure the Language domain for the licensing class/code
	 *
	 * @param string $domain
	 *
	 * @return string
	 */
	public function set_translation_domain( $domain ) {
		
		return Controller::plugin_slug;
	}
	
	/**
	 * Loads actions & filters for the plugin.
	 */
	public function load_actions() {
		
		$utils = Utilities::get_instance();
		
		$utils->log( "Checking that we're not working on a license check (loopback)" );
		preg_match( "/eighty20results\.com/i", Licensing::E20R_LICENSE_SERVER_URL, $is_licensing_server );
		
		if ( 'slm_check' == $utils->get_variable( 'slm_action', false ) && ! empty( $is_licensing_server ) ) {
			$utils->log( "Processing license server operation (self referential check). Bailing!" );
			
			return;
		}
		
		// add_filter( 'e20r-license-add-new-licenses', array( self::get_instance(), 'addNewLicenseInfo', ), 10, 2 );
		add_filter( 'e20r-licensing-text-domain', array( $this, 'set_translation_domain' ), 10, 1 );
		
		$utils->log( "Loading unlicensed functionality hooks/filters" );
		add_action( 'plugins_loaded', array( Paid_Memberships_Pro::get_instance(), 'load_hooks' ), 99 );
		
		add_action( 'init', array( Sequence_Updates::get_instance(), 'init' ) );
		add_action( 'wp_loaded', array( Sequence_Updates::get_instance(), 'update' ), 1 ); // Run early
		
		add_action( 'admin_init', array( Sequences_License::get_instance(), 'check_licenses' ) );
		add_action( 'e20r_sequence_cron_hook', array( Cron::get_instance(), 'check_for_new_content' ), 10, 1 );
		
		// TODO: Split pmpro filters into own filter management module
		
		// Load filters
		add_filter( "the_content", array( $this, "display_sequence_content" ) );
		
		// add_filter( "the_posts", array( $this, "set_delay_values" ), 10, 2 );
		
		// Add Custom Post Type
		// add_action( "init", array( $this, "load_textdomain" ), 9 );
		add_action( "init", array( Model::get_instance(), "create_sequence_post_type" ), 10 );
		add_action( "wp_ready", array( $this, "register_shortcodes" ), 11 );
		
		add_filter( "post_row_actions", array( $this, 'send_alert_notice_from_menu' ), 10, 2 );
		add_filter( "page_row_actions", array( $this, 'send_alert_notice_from_menu' ), 10, 2 );
		
		add_action( "admin_action_send_user_alert_notices", array( $this, 'send_user_alert_notices' ) );
		
		
		// Add CSS & Javascript
		
		// Register and enqueue at different times so we can unhook if desired
		add_action( "wp_enqueue_scripts", array( $this, 'register_user_scripts' ), 5 );
		add_action( "wp_enqueue_scripts", array( $this, 'enqueue_user_scripts' ), 99 );
		
		// add_action("wp_footer", array( $this, 'enqueue_user_scripts') );
		
		// Register and enqueue at different times so we can unhook if desired
		add_action( "admin_enqueue_scripts", array( $this, "register_admin_scripts" ), 5 );
		add_action( "admin_enqueue_scripts", array( $this, 'enqueue_admin_scripts' ), 99 );
		
		// add_action( 'admin_head', array( $this, 'post_type_icon' ) );
		
		// Load metaboxes for editor(s)
		add_action( 'add_meta_boxes', array( $this, 'post_metabox' ), 10, 2 );
		
		// Load add/save actions
		add_action( 'admin_init', array( $this, 'check_conversion' ) );
		add_action( 'admin_init', array( $this, 'register_settings_page' ), 10 );
		
		add_action( 'admin_notices', array( $this, 'display_error' ) );
		// add_action( 'save_post', array( $this, 'post_save_action' ) );
		add_action( 'post_updated', array( $this, 'post_save_action' ) );
		
		add_action( 'admin_menu', array( $this, "define_metaboxes" ) );
		add_action( 'admin_menu', array( $this, 'load_admin_settings_page' ), 10 );
		
		add_action( 'save_post', array( $this, 'save_post_meta' ), 10, 2 );
		// add_action('deleted_post', array($this, 'delete_post_meta_for_sequence'), 10, 1);
		add_action( 'widgets_init', array( $this, 'register_widgets' ) );
		
		// Add AJAX handlers for logged in users/admins
		add_action( "wp_ajax_e20r_sequence_add_post", array( $this, "add_post_callback" ) );
		add_action( 'wp_ajax_e20r_sequence_update_post_meta', array( $this, 'update_delay_post_meta_callback' ) );
		add_action( 'wp_ajax_e20r_rm_sequence_from_post', array( $this, 'rm_sequence_from_post_callback' ) );
		add_action( "wp_ajax_e20r_sequence_rm_post", array( $this, "rm_post_callback" ) );
		add_action( "wp_ajax_e20r_remove_alert", array( $this, "remove_post_alert_callback" ) );
		add_action( 'wp_ajax_e20r_sequence_clear', array( $this, 'sequence_clear_callback' ) );
		add_action( 'wp_ajax_e20r_send_notices', array( $this, 'sendalert_callback' ) );
		add_action( 'wp_ajax_e20r_sequence_save_user_optin', array( $this, 'optin_callback' ) );
		add_action( 'wp_ajax_e20r_save_settings', array( $this, 'settings_callback' ) );
		add_action( "wp_ajax_e20r_sequence_clear_cache", array( $this, "delete_cache" ) );
		
		// Add AJAX handlers for unprivileged admin operations.
		add_action( 'wp_ajax_nopriv_e20r_sequence_add_post', array( $this, 'unprivileged_ajax_error' ) );
		add_action( 'wp_ajax_nopriv_e20r_sequence_update_post_meta', array( $this, 'unprivileged_ajax_error' ) );
		add_action( 'wp_ajax_nopriv_e20r_rm_sequence_from_post', array( $this, 'unprivileged_ajax_error' ) );
		add_action( 'wp_ajax_nopriv_e20r_sequence_rm_post', array( $this, 'unprivileged_ajax_error' ) );
		add_action( 'wp_ajax_nopriv_e20r_sequence_clear', array( $this, 'unprivileged_ajax_error' ) );
		add_action( 'wp_ajax_nopriv_e20r_send_notices', array( $this, 'unprivileged_ajax_error' ) );
		add_action( 'wp_ajax_nopriv_e20r_sequence_save_user_optin', array( $this, 'unprivileged_ajax_error' ) );
		add_action( 'wp_ajax_nopriv_e20r_save_settings', array( $this, 'unprivileged_ajax_error' ) );
		
		// Load shortcodes (instantiate the object(s).
		add_action( 'wp_ready', array( Available_On::get_instance(), 'load_hooks' ) );
		
		// Load licensed modules (if applicable)
		add_action( 'e20r-sequence-load-licensed-modules', array( New_Content_Notice::get_instance(), 'load_hooks' ) );
		add_action( 'e20r-sequence-load-licensed-modules', array( WP_All_Export::get_instance(), 'load_hooks' ) );
		add_action( 'e20r-sequence-load-licensed-modules', array( Google::get_instance(), 'load_hooks' ) );
		
		$utils->log( "Loading licensed functionality: " . Controller::plugin_prefix );
		
		if ( true === Licensing::is_licensed( Controller::plugin_prefix ) ) {
			
			$utils->log( "Trigger module load action" );
			do_action( 'e20r-sequence-load-licensed-modules' );
		} else {
			$utils->log( "Sequences Plus license not active??" );
		}
		
	}
	
	/**
	 * Load licensing specific settings for Sequences Plus license
	 */
	public function register_settings_page() {
		
		$utils = Utilities::get_instance();
		
		$utils->log( "Register Licensing settings for Sequences" );
		
		Licensing::register_settings();
	}
	
	/**
	 * Load options page for E20R Sequences Plus license (if needed)
	 */
	public function load_admin_settings_page() {
		
		$utils = Utilities::get_instance();
		
		$utils->log( "Loading options page for E20R Sequences" );
		
		Licensing::add_options_page();
	}
	
	/**
	 * Filter Handler: Add the 'add bbPress add-on license' settings entry
	 *
	 * @filter e20r-license-add-new-licenses
	 *
	 * @param array $license_settings
	 * @param array $plugin_settings
	 *
	 * @return array
	 */
	public function addNewLicenseInfo( $license_settings, $plugin_settings ) {
		
		global $e20r_sequences;
		
		$utils = Utilities::get_instance();
		
		if ( ! isset( $license_settings['new_licenses'] ) ) {
			$license_settings['new_licenses'] = array();
			$utils->log( "Init array of licenses entry" );
		}
		
		$stub = strtolower( $this->getClassName() );
		$utils->log( "Have " . count( $license_settings['new_licenses'] ) . " new licenses to process already. Adding {$stub}... " );
		
		$license_settings['new_licenses'][ $stub ] = array(
			'label_for'     => $stub,
			'fulltext_name' => $e20r_sequences[ $stub ]['label'],
			'new_product'   => $stub,
			'option_name'   => "e20r_license_settings",
			'name'          => 'license_key',
			'input_type'    => 'password',
			'value'         => null,
			'email_field'   => "license_email",
			'email_value'   => null,
			'placeholder'   => sprintf( __( "Paste %s key here", "e20r-licensing" ), $e20r_sequences[ $stub ]['label'] ),
		);
		
		return $license_settings;
	}
	
	/**
	 * Get the plugin License name
	 *
	 * @return string
	 */
	public function getClassName() {
		
		if ( empty( $this->class_name ) ) {
			$this->class_name = 'e20r_sequences';
		}
		
		return $this->class_name;
	}
	
	/**
	 * Transition post_type for Sequence from pmpro_sequence to e20r_sequence
	 *
	 * @param array  $type_args
	 * @param string $post_type
	 *
	 * @return array
	 */
	public function pmpro_to_e20r( $type_args, $post_type ) {
		
		$utils = Utilities::get_instance();
		
		$utils->log( "Transition from pmpro_sequence to e20r_sequence as the post type? {$post_type}" );
		
		if ( 'pmpro_sequence' == $post_type ) {
			$type_args['rewrite']['slug'] = 'e20r_sequences';
		}
		
		return $type_args;
	}
	
	/**
	 * Validate license before downloading the E20r Sequences kit (and only in that case).
	 *
	 * @param $reply
	 * @param $package
	 * @param $upgrader
	 *
	 * @return mixed
	 */
	public function checkLicense( $reply, $package, $upgrader ) {
		
		if ( false !== strpos( $package, 'e20r-sequences' ) ) {
			$reply = Licensing::is_licensed( Controller::plugin_prefix );
		}
		
		return $reply;
	}
	
	/**
	 * Sort the two post objects (order them) according to the defined sortOrder
	 *
	 * @return int | bool - The usort() return value
	 *
	 * @access private
	 */
	public function sort_by_delay() {
		
		$utils = Utilities::get_instance();
		
		if ( empty( $this->options->sortOrder ) ) {
			
			$utils->log( 'Need sortOrder option to base sorting decision on...' );
			// $sequence = $this->get_sequence_by_id($a->id);
			if ( $this->sequence_id !== null ) {
				
				$utils->log( 'Have valid sequence post ID saved: ' . $this->sequence_id );
				$this->get_options( $this->sequence_id );
			}
		}
		
		switch ( $this->options->sortOrder ) {
			
			case SORT_DESC:
				$utils->log( 'Sorted in Descending order' );
				krsort( $this->posts, SORT_NUMERIC );
				break;
			default:
				$utils->log( 'undefined or ascending sort order' );
				ksort( $this->posts, SORT_NUMERIC );
		}
		
		return false;
	}
	
	/**
	 * Sort the two post objects (order them) according to the defined sortOrder
	 *
	 * @param $a (post object)
	 * @param $b (post object)
	 *
	 * @return int | bool - The usort() return value
	 *
	 * @access private
	 */
	public function sort_posts_by_delay( $a, $b ) {
		
		$utils = Utilities::get_instance();
		/*            if ( empty( $this->options->sortOrder) ) {

            $utils->log('Need sortOrder option to base sorting decision on...');
            // $sequence = $this->get_sequence_by_id($a->id);

            if ( $this->sequence_id !== null) {

                $utils->log('Have valid sequence post ID saved: ' . $this->sequence_id);
                $this->get_options( $this->sequence_id );
            }
        }
*/
		switch ( $this->options->sortOrder ) {
			
			case SORT_ASC:
				// $utils->log('sort_posts_by_delay(): Sorting in Ascending order');
				return $this->sort_ascending( $a, $b );
				break;
			
			case SORT_DESC:
				// $utils->log('sort_posts_by_delay(): Sorting in Descending order');
				return $this->sort_descending( $a, $b );
				break;
			
			default:
				$utils->log( 'sortOrder not defined' );
		}
		
		return false;
	}
	
	/**
	 * Sort the two posts in Ascending order
	 *
	 * @param $a -- Post to compare (including delay variable)
	 * @param $b -- Post to compare against (including delay variable)
	 *
	 * @return int -- Return +1 if the Delay for post $a is greater than the delay for post $b (i.e. delay for b is
	 *                  less than delay for a)
	 *
	 * @access private
	 */
	private function sort_ascending( $a, $b ) {
		
		list( $a_delay, $b_delay ) = $this->normalize_delay_values( $a, $b );
		// $utils->log('sort_ascending() - Delays have been normalized');
		
		// Now sort the data
		if ( $a_delay == $b_delay ) {
			return 0;
		}
		
		// Ascending sort order
		return ( $a_delay > $b_delay ) ? + 1 : - 1;
		
	}
	
	/**
	 * Get the delays (days since membership started) for both post objects
	 *
	 * @param $a -- Post object to compare
	 * @param $b -- Post object to compare against
	 *
	 * @return array -- Array containing delay(s) for the two posts objects (as days since start of membership)
	 *
	 * @access private
	 */
	private function normalize_delay_values( $a, $b ) {
		return array( $this->convert_date_to_days( $a->delay ), $this->convert_date_to_days( $b->delay ) );
	}
	
	/**
	 * Sort the two posts in ascending order
	 *
	 * @param $a -- Post to compare (including delay variable)
	 * @param $b -- Post to compare against (including delay variable)
	 *
	 * @return int -- Return -1 if the Delay for post $a is greater than the delay for post $b
	 *
	 * @access private
	 */
	private function sort_descending( $a, $b ) {
		list( $a_delay, $b_delay ) = $this->normalize_delay_values( $a, $b );
		
		if ( $a_delay == $b_delay ) {
			return 0;
		}
		
		// Descending Sort Order
		return ( $a_delay > $b_delay ) ? - 1 : + 1;
	}
	
	/**
	 * Checks whether the post cache for the active sequence is still valid (timeout based)
	 *
	 * @return bool - True if still valid
	 */
	private function is_cache_valid() {
		
		$utils = Utilities::get_instance();
		
		$model = Model::get_instance();
		$posts = $model->get_posts();
		
		$utils->log( "We have " . count( $posts ) . " posts in the post list" );
		
		$expires = $this->get_cache_expiry( $this->sequence_id );
		$model->set_expires( $expires );
		
		if ( empty( $posts ) || is_null( $expires ) ) {
			
			$utils->log( "Cache is INVALID" );
			
			return false;
		}
		
		$utils->log( "Current refresh value: {$expires} vs " . current_time( 'timestamp', true ) );
		
		if ( ( $expires >= current_time( 'timestamp' ) ) /* && !empty( $posts ) */ ) {
			
			$utils->log( "Cache IS VALID." );
			
			return true;
		}
		
		if ( empty( $posts ) && $expires >= current_time( 'timestamp' ) ) {
			$utils->log( "No data in list, but cache IS VALID." );
			
			return true;
		}
		
		$utils->log( "Cache is INVALID" );
		
		return false;
	}
	
	/**
	 * Find a single post/delay combination in the current sequence
	 *
	 * @param $post_id - The post ID to look for
	 * @param $delay   - The delay value to look for
	 *
	 * @return \WP_Post|bool - The post info
	 */
	private function find_single_post( $post_id, $delay ) {
		
		$model = Model::get_instance();
		
		if ( empty( $this->posts ) ) {
			$model->load_sequence_post();
		}
		
		$utils = Utilities::get_instance();
		$utils->log( "Find post {$post_id}" );
		
		foreach ( $this->posts as $key => $post ) {
			
			if ( ( $post_id == $post->id ) && ( $delay == $post->delay ) ) {
				return $post;
			}
		}
		
		return false;
	}
}
