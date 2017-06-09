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

use E20R\Sequences\Shortcodes\Available_On;
use E20R\Sequences\Shortcodes\Upcoming_Content;
use E20R\Sequences\Shortcodes\Sequence_Links;
use E20R\Sequences\Shortcodes\Sequence_Alert;
use E20R\Tools\DBG;
use E20R\Sequences\Tools\E20R_Error;
use E20R\Sequences\Tools\E20R_Mail;
use E20R\Sequences\Tools\Cron;
use E20R\Sequences\Tools\Cache;
use E20R\Tools\License\E20R_License;
use E20R\Sequences\Tools\Importer;
use E20R\Sequences\Tools\Sequence_Updates;

class Sequence_Controller {
	
	public $options;
	public $sequence_id = 0;
	public $error = null;
	public $e20r_sequence_user_level = null; // List of available posts for user ID
	public $e20r_sequence_user_id = null; // list of future posts for user ID (if the sequence is configured to show hidden posts)
	public $is_cron = false; // WP_POST definition for the sequence
	
	private $id;
	private $posts = array();
	private $cached_for = null;
	private $upcoming = array();
	private $sequence;
	private $managed_types = null;
	private $current_metadata_versions = array();
	
	private static $select2_version = '4.0.3';
	private static $seq_post_type = 'pmpro_sequence';
	
	// private static $transient_option_key = '_transient_timeout_';
	private static $cache_timeout = 5; // In minutes
	private $transient_key = '_';
	private $expires;
	private $refreshed;
	
	// Refers to a single instance of this class
	private static $_this = null;
	
	/**
	 * @var \E20R_Utils $utils Utilities class
	 */
	private $utils = null;
	
	/**
	 * Constructor for the Sequence
	 *
	 * @param null $id -- The ID of the sequence to initialize
	 *
	 * @throws \Exception - If the sequence doesn't exist.
	 */
	function __construct( $id = null ) {
		if ( null !== self::$_this ) {
			$error_message = sprintf( __( "Attempted to load a second instance of a singleton class (%s)", "e20r-sequences" ),
				get_class( $this )
			);
			
			error_log( $error_message );
			wp_die( $error_message );
		}
		
		self::$_this = $this;
		
		// Make sure it's not a dummy construct() call - i.e. for a post that doesn't exist.
		if ( ( $id != null ) && ( $this->sequence_id == 0 ) ) {
			
			$this->sequence_id = $this->get_sequence_by_id( $id ); // Try to load it from the DB
			
			if ( $this->sequence_id == false ) {
				throw new \Exception(
					sprintf(
						__( "A Sequence with the specified ID (%s) does not exist on this system", "e20r-sequences" ),
						$id
					)
				);
			}
		}
		
		$this->managed_types             = apply_filters( "e20r-sequence-managed-post-types", array( "post", "page" ) );
		$this->current_metadata_versions = get_option( "pmpro_sequence_metadata_version", array() );
		
		add_filter( "get_sequence_class_instance", 'E20R\Sequences\Sequence\Sequence_Controller::get_instance' );
		add_action( "init", array( $this, 'load_textdomain' ), 1 );
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
		
		// Does the ID differ from the one this object has stored already?
		if ( ! is_null( $sequence_id ) && ( $this->sequence_id != $sequence_id ) ) {
			
			DBG::log( 'ID defined already but we were given a different sequence ID' );
			$this->sequence_id = $sequence_id;
		} else if ( is_null( $sequence_id ) && $sequence_id != 0 ) {
			
			// This shouldn't be possible... (but never say never!)
			DBG::log( "The defined sequence ID is empty so we'll set it to " . $sequence_id );
			$this->sequence_id = $sequence_id;
		}
		
		$this->refreshed = null;
		
		// Should only do this once, unless the timeout is in the past.
		if ( is_null( $this->expires ) ||
		     ( ! is_null( $this->expires ) && $this->expires < current_time( 'timestamp' ) )
		) {
			
			$this->expires = $this->get_cache_expiry( $this->sequence_id );
		}
		
		// Check that we're being called in context of an actual Sequence 'edit' operation
		DBG::log( 'Loading settings from DB for (' . $this->sequence_id . ') "' . get_the_title( $this->sequence_id ) . '"' );
		
		$settings = get_post_meta( $this->sequence_id, '_pmpro_sequence_settings', true );
		// DBG::log("Settings are now: " . print_r( $settings, true ) );
		
		// Fix: Offset error when creating a brand new sequence for the first time.
		if ( empty( $settings ) ) {
			
			$settings        = $this->default_options();
			$this->refreshed = null;
		}
		
		$loaded_options = $settings;
		$options        = $this->default_options();
		
		foreach ( $loaded_options as $key => $value ) {
			
			$options->{$key} = $value;
		}
		
		// $this->options = (object) array_replace( (array)$default_options, (array)$loaded_options );
		// DBG::log( "For {$this->sequence_id}: Current: " . print_r( $this->options, true ) );
		$options->loaded = true;
		
		return $this->options;
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
	 * @return \WP_Post - Array of posts.
	 */
	static public function post_details( $sequence_id, $post_id ) {
		$seq = apply_filters( 'get_sequence_class_instance', null );
		$seq->get_options( $sequence_id );
		
		return $seq->find_by_id( $post_id );
	}
	
	/**
	 * Static function that returns all sequences in the system that have the specified post status
	 *
	 * @param string $statuses
	 *
	 * @return array of Sequence objects
	 */
	static public function all_sequences( $statuses = 'publish' ) {
		$seq = apply_filters( 'get_sequence_class_instance', null );
		
		return $seq->get_all_sequences( $statuses );
	}
	
	/**
	 * Static function that returns all sequence IDs that a specific post_id belongs to
	 *
	 * @param $post_id - Post ID
	 *
	 * @return mixed -- array of sequence Ids
	 */
	static public function sequences_for_post( $post_id ) {
		$c_sequence = apply_filters( 'get_sequence_class_instance', null );
		
		return $c_sequence->get_sequences_for_post( $post_id );
	}
	
	/**
	 * Singleton pattern - returns sequence object (this) to caller (via filter)
	 * @return Sequence_Controller $this - Current instance of the class
	 * @since 4.0.0
	 */
	public static function get_instance() {
		if ( null == self::$_this ) {
			self::$_this = new self;
		}
		
		return self::$_this;
	}
	
	/**
	 * Check all sequences in the system for whether or not they've been converted to the v3 metadata format then set
	 * warning banner if not.
	 */
	public function check_conversion() {
		DBG::log( "Check whether we need to convert any sequences" );
		$sequences = $this->get_all_sequences();
		
		foreach ( $sequences as $sequence ) {
			
			DBG::log( "Check whether we need to convert sequence # {$sequence->ID}" );
			
			if ( ! $this->is_converted( $sequence->ID ) ) {
				
				$this->set_error_msg( sprintf( __( "Required action: Please de-activate and then activate the E20R Sequences plugin (%d)", "e20r-sequences" ), $sequence->ID ) );
			}
		}
	}
	
	/**
	 * Returns a list of all defined drip-sequences
	 *
	 * @param $statuses string|array - Post statuses to return posts for.
	 *
	 * @return mixed - Array of post objects
	 */
	public function get_all_sequences( $statuses = 'publish' ) {
		
		$query = array(
			'post_type'      => 'pmpro_sequence',
			'post_status'    => $statuses,
			'posts_per_page' => - 1, // BUG: Didn't return more than 5 sequences
		);
		
		/* Fetch all Sequence posts - NOTE: Using \WP_Query and not the sequence specific get_posts() function! */
		$all_posts = get_posts( $query );
		
		wp_reset_query();
		
		return $all_posts;
	}
	
	/**
	 * Check whether a specific sequence ID has been converted to the V3 metadata format
	 *
	 * @param $sequence_id - the ID of the sequnence to check for
	 *
	 * @return bool - True if it's been converted, false otherwise.
	 */
	public function is_converted( $sequence_id ) {
		
		DBG::log( "Check whether sequence ID {$sequence_id} is converted already" );
		
		if ( empty( $this->current_metadata_versions ) ) {
			
			$this->current_metadata_versions = get_option( "pmpro_sequence_metadata_version", array() );
		}
		
		DBG::log( "Sequence metadata map: " );
		DBG::log( $this->current_metadata_versions );
		
		if ( empty( $this->current_metadata_versions ) ) {
			DBG::log( "{$sequence_id} needs to be converted to V3 format" );
			
			return false;
		}
		
		$has_pre_v3 = get_post_meta( $sequence_id, "_sequence_posts", true );
		
		if ( ( false !== $has_pre_v3 ) && ( ! isset( $this->current_metadata_versions[ $sequence_id ] ) || ( 3 != $this->current_metadata_versions[ $sequence_id ] ) ) ) {
			DBG::log( "{$sequence_id} needs to be converted to V3 format" );
			
			return false;
		}
		
		if ( ( false === $has_pre_v3 ) || ( isset( $this->current_metadata_versions[ $sequence_id ] ) && ( 3 == $this->current_metadata_versions[ $sequence_id ] ) ) ) {
			
			DBG::log( "{$sequence_id} is at v3 format" );
			
			return true;
		}
		
		if ( wp_is_post_revision( $sequence_id ) ) {
			return true;
		}
		
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return true;
		}
		
		if ( 'trash' == get_post_status( $sequence_id ) ) {
			return true;
		}
		
		$arguments = array(
			'posts_per_page' => - 1,
			'meta_query'     => array(
				array(
					'key'     => '_pmpro_sequence_post_belongs_to',
					'value'   => $sequence_id,
					'compare' => '=',
				),
			),
		);
		
		$is_converted = new \WP_Query( $arguments );
		
		$options = $this->get_options( $sequence_id );
		
		if ( $is_converted->post_count >= 1 && $options->loaded === true ) {
			
			if ( ! isset( $this->current_metadata_versions[ $sequence_id ] ) ) {
				
				DBG::log( "Sequence # {$sequence_id} is converted already. Updating the settings" );
				$this->current_metadata_versions[ $sequence_id ] = 3;
				update_option( 'pmpro_sequence_metadata_version', $this->current_metadata_versions, true );
			}
			
			return true;
		}
		
		return false;
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
		
		$this->utils = \E20R_Utils::get_instance();
		
		if ( ! empty( $msg ) ) {
			
			DBG::log( "set_error_msg(): {$msg}" );
			
			$this->utils->set_notice( $msg, 'error' );
		}
	}
	
	/**
	 * Access the private $error value
	 *
	 * @return string|null|mixed -- Error message or NULL
	 * @access public
	 */
	public function get_error_msg() {
		
		// $e = apply_filters('get_e20rerror_class_instance', null);
		$this->utils = \E20R_Utils::get_instance();
		
		$this->error = $this->utils->get_error_msg( 'error' );
		
		if ( ! empty( $this->error ) ) {
			
			DBG::log( "Error info found: " . print_r( $this->error, true ) );
			
			return $this->error;
		} else {
			return null;
		}
	}
	
	/**
	 * Display the error message (if it's defined).
	 */
	public function display_error() {
		
		DBG::log( "Display error message(s), if there are any" );
		global $current_screen;
		
		if ( empty( $this->utils ) ) {
			$this->utils = \E20R_Utils::get_instance();
		}
		
		$this->utils->display_notice();
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
		$settings->excerptIntro         = __( 'A summary of the post follows below:', "e20r-sequences" );
		$settings->replyto              = apply_filters( 'e20r-sequence-default-sender-email', $admin->user_email ); // << Update Name
		$settings->fromname             = apply_filters( 'e20r-sequence-default-sender-name', $admin->display_name ); // << Updated Name!
		$settings->subject              = __( 'New Content ', "e20r-sequences" );
		$settings->dateformat           = __( 'm-d-Y', "e20r-sequences" ); // Using American MM-DD-YYYY format. << Updated name!
		$settings->trackGoogleAnalytics = false; // Whether to use Google analytics to track message open operations or not
		$settings->gaTid                = null; // The Google Analytics ID to use (TID)
		
		$this->options = $settings; // Save as options for this sequence
		
		return $settings;
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
	 * Set a Sequence option by name to the specified $value.
	 *
	 * @param $option
	 * @param $value
	 */
	public function set_option_by_name( $option, $value ) {
		$this->options->{$option} = $value;
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
		$c_key = null;
		// $user_id = null;
		
		if ( empty( $user_id ) ) {
			$user_id = $current_user->ID;
		}
		
		if ( empty( $this->e20r_sequence_user_id ) ) {
			$this->e20r_sequence_user_id = $user_id;
		}
		
		DBG::log( "Cache key for user: {$this->e20r_sequence_user_id}" );
		
		if ( ( 0 == $current_user->ID && ! empty( $this->e20r_sequence_user_id ) && true === $this->is_cron ) ||
		     ( is_numeric( $this->e20r_sequence_user_id ) && 0 < $this->e20r_sequence_user_id )
		) {
			
			$user_id = $this->e20r_sequence_user_id;
			$c_key   = "{$user_id}_{$sequence_id}";
		}
		
		DBG::log( "Cache key: " . ( is_null( $c_key ) ? 'NULL' : $c_key ) );
		
		return $c_key;
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
		
		DBG::log( "Loading cache timeout value for {$sequence_id}" );
		
		global $wpdb;
		$expires = null;
		
		$c_key = $this->get_cache_key( $sequence_id );
		$prefix = Cache::CACHE_GROUP;
		
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
		
		DBG::log( "Loaded cache timeout value for {$sequence_id}: " . ( empty( $expires ) ? "NULL" : "{$expires}" ) );
		
		return $expires;
	}
	
	/**
	 * Checks whether the post cache for the active sequence is still valid (timeout based)
	 *
	 * @return bool - True if still valid
	 */
	private function is_cache_valid() {
		
		DBG::log( "We have " . count( $this->posts ) . " posts in the post list" );
		
		$this->expires = $this->get_cache_expiry( $this->sequence_id );
		
		if ( empty( $this->posts ) || is_null( $this->expires ) ) {
			
			DBG::log( "Cache is INVALID" );
			
			return false;
		}
		
		DBG::log( "Current refresh value: {$this->expires} vs " . current_time( 'timestamp', true ) );
		
		if ( ( $this->expires >= current_time( 'timestamp' ) ) /* && !empty( $this->posts ) */ ) {
			
			DBG::log( "Cache IS VALID." );
			
			return true;
		}
		
		if ( empty( $this->posts ) && $this->expires >= current_time( 'timestamp' ) ) {
			DBG::log( "No data in list, but cache IS VALID." );
			
			return true;
		}
		
		DBG::log( "Cache is INVALID" );
		
		return false;
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
		
		if ( empty( $sequence_id ) && ( isset( $_POST['e20r_sequence_id'] ) || isset( $_POST['e20r_sequence_post_nonce'] ) ) ) {
			
			DBG::log( "Attempting to clear cache during AJAX operation" );
			$direct_operation = true;
			
			wp_verify_nonce( "e20r-sequence-post", "e20r_sequence_post_nonce" );
			$sequence_id = isset( $_POST['e20r_sequence_id'] ) ? intval( $_POST['e20r_sequence_id'] ) : null;
			
			if ( is_null( $sequence_id ) ) {
				wp_send_json_error( array( array( 'message' => __( "No sequence ID specified. Can't clear cache!", "e20r-sequences" ) ) ) );
				wp_die();
			}
		}
		
		$c_key = $this->get_cache_key( $sequence_id );
		$prefix = Cache::CACHE_GROUP;
		
		DBG::log( "Removing old/stale cache data for {$sequence_id}: {$prefix}_{$c_key}" );
		$this->expires = null;
		
		if ( ! is_null( "{$prefix}_{$c_key}" ) ) {
			$status = delete_transient( "{$prefix}_{$c_key}" );
		}
		
		if ( ( false === $status ) && ( true === $direct_operation ) &&
		     ( isset( $_POST['e20r_sequence_id'] ) || isset( $_POST['e20r_sequence_post_nonce'] ) )
		) {
			wp_send_json_error( array( array( 'message' => __( "No cache to clear, or unable to clear the cache", "e20r-sequences" ) ) ) );
			wp_die();
		}
		
		if ( ( true === $status ) && ( true === $direct_operation ) &&
		     ( isset( $_POST['e20r_sequence_id'] ) || isset( $_POST['e20r_sequence_post_nonce'] ) )
		) {
			
			wp_send_json_success();
			wp_die();
		}
		
		return $status;
		// return wp_cache_delete( $key, $group);
	}
	
	/**
	 * Configure (load) the post cache for the specific sequence ID
	 *
	 * @param $sequence_posts - array of posts that belong to the sequence
	 * @param $sequence_id    - The ID of the sequence to load the cache for
	 *
	 * @return bool - Whether the cache loaded successfully or not
	 */
	private function set_cache( $sequence_posts, $sequence_id ) {
		
		$success = false;
		
		// $this->delete_cache( $this->transient_key . $sequence_id);
		
		
		$c_key = $this->get_cache_key( $sequence_id );
		DBG::log( "Saving data to cache for {$sequence_id}... using {$c_key}" );
		
		if ( ! empty( $c_key ) ) {
			
			DBG::log( "Saving Cache w/a timeout of: " . self::$cache_timeout );
			$success       = Cache::set( $c_key, $sequence_posts, ( self::$cache_timeout * MINUTE_IN_SECONDS ), Cache::CACHE_GROUP );
			$this->expires = $this->get_cache_expiry( $sequence_id );
			DBG::log( "Cache set to expire: {$this->expires}" );
			
			return true;
		}
		
		DBG::log( "Unable to update the cache for {$sequence_id}!" );
		$this->expires = null;
		
		return false;
		
		// wp_cache_set( $key, $value );
	}
	
	/**
	 * Return the cached post list for the specific cache
	 *
	 * @param $sequence_id - The Sequenece Id
	 *
	 * @return bool|mixed - The cached post list (or false if unable to locate it)
	 */
	private function get_cache( $sequence_id ) {
		
		DBG::log( "Loading from cache for {$sequence_id}..." );
		
		$cache = false;
		
		$this->expires = $this->get_cache_expiry( $sequence_id );
		$c_key         = $this->get_cache_key( $sequence_id );
		
		if ( ! empty( $c_key ) ) {
			$cache = Cache::get( $c_key, Cache::CACHE_GROUP );
		}
		
		return empty( $cache ) ? false : $cache;
		// $cached_value = wp_cache_get( $key, $group, $force, $found );
	}
	
	/**
	 * Loads the post list for a sequence (or the current sequence) from the DB
	 *
	 * @param null   $sequence_id
	 * @param null   $delay
	 * @param null   $post_id
	 * @param string $comparison
	 * @param null   $pagesize
	 * @param bool   $force
	 * @param string $status
	 *
	 * @return array|bool|mixed
	 */
	public function load_sequence_post( $sequence_id = null, $delay = null, $post_id = null, $comparison = '=', $pagesize = null, $force = false, $status = 'default' ) {
		
		global $current_user;
		global $loading_sequence;
		
		$find_by_delay = false;
		$found         = array();
		$data_type     = 'NUMERIC';
		$page_num      = 0;
		
		if ( ! is_null( $this->e20r_sequence_user_id ) && ( $this->e20r_sequence_user_id != $current_user->ID ) ) {
			
			DBG::log( "Using user id from e20r_sequence_user_id: {$this->e20r_sequence_user_id}" );
			$user_id = $this->e20r_sequence_user_id;
		} else {
			DBG::log( "Using user id (from current_user): {$current_user->ID}" );
			$user_id = $current_user->ID;
		}
		
		if ( is_null( $sequence_id ) && ( ! empty( $this->sequence_id ) ) ) {
			DBG::log( "No sequence ID specified in call. Using default value of {$this->sequence_id}" );
			$sequence_id = $this->sequence_id;
		}
		
		if ( empty( $sequence_id ) ) {
			
			DBG::log( "No sequence ID configured. Returning error (null)", E20R_DEBUG_SEQ_WARNING );
			
			return null;
		}
		
		if ( ! empty( $delay ) ) {
			
			if ( $this->options->delayType == 'byDate' ) {
				
				DBG::log( "Expected delay value is a 'date' so need to convert" );
				$startdate = $this->get_user_startdate( $user_id );
				
				$delay     = date( 'Y-m-d', ( $startdate + ( $delay * DAY_IN_SECONDS ) ) );
				$data_type = 'DATE';
			}
			
			DBG::log( "Using delay value: {$delay}" );
			$find_by_delay = true;
		}
		
		DBG::log( "Sequence ID var: " . ( empty( $sequence_id ) ? 'Not defined' : $sequence_id ) );
		DBG::log( "Force var: " . ( ( $force === false ) ? 'False' : 'True' ) );
		DBG::log( "Post ID var: " . ( is_null( $post_id ) ? 'Not defined' : $post_id ) );
		
		if ( ( false === $force ) && empty( $post_id ) && ( false !== ( $found = $this->get_cache( $sequence_id ) ) ) ) {
			
			DBG::log( "Loaded post list for sequence # {$sequence_id} from cache. " . count( $found ) . " entries" );
			$this->posts = $found;
		}
		
		DBG::log( "Delay var: " . ( empty( $delay ) ? 'Not defined' : $delay ) );
		DBG::log( "Comparison var: {$comparison}" );
		DBG::log( "Page size ID var: " . ( empty( $pagesize ) ? 'Not defined' : $pagesize ) );
		DBG::log( "have to refresh data..." );
		
		// $this->refreshed = current_time('timestamp', true);
		$this->refreshed = null;
		$this->expires   = - 1;
		
		/**
		 * Expected format: array( $key_1 => stdClass $post_obj, $key_2 => stdClass $post_obj );
		 * where $post_obj = stdClass  -> id
		 *                   stdClass  -> delay
		 */
		$order_by = $this->options->delayType == 'byDays' ? 'meta_value_num' : 'meta_value';
		$order    = $this->options->sortOrder == SORT_DESC ? 'DESC' : 'ASC';
		
		if ( ( $status == 'default' ) && ( ! is_null( $post_id ) ) ) {
			
			$statuses = apply_filters( 'e20r-sequence-allowed-post-statuses', array(
				'publish',
				'future',
				'draft',
				'private',
			) );
		} else if ( $status == 'default' ) {
			
			$statuses = apply_filters( 'e20r-sequence-allowed-post-statuses', array( 'publish', 'future', 'private' ) );
		} else {
			
			$statuses = apply_filters( 'e20r-sequence-allowed-post-statuses', $status );
		}
		
		DBG::log( "Loading posts with status: " . print_r( $statuses, true ) );
		
		if ( is_null( $post_id ) ) {
			
			DBG::log( "No post ID specified. Loading posts...." );
			
			$seq_args = array(
				'post_type'      => apply_filters( 'e20r-sequence-managed-post-types', array( 'post', 'page' ) ),
				'post_status'    => $statuses,
				'posts_per_page' => - 1,
				'orderby'        => $order_by,
				'order'          => $order,
				'meta_key'       => "_pmpro_sequence_{$sequence_id}_post_delay",
				'meta_query'     => array(
					array(
						'key'     => '_pmpro_sequence_post_belongs_to',
						'value'   => $sequence_id,
						'compare' => '=',
					),
				),
			);
		} else {
			
			DBG::log( "Post ID specified so we'll only search for post #{$post_id}" );
			
			$seq_args = array(
				'post_type'      => apply_filters( 'e20r-sequence-managed-post-types', array( 'post', 'page' ) ),
				'post_status'    => $statuses,
				'posts_per_page' => - 1,
				'order_by'       => $order_by,
				'p'              => $post_id,
				'order'          => $order,
				'meta_key'       => "_pmpro_sequence_{$sequence_id}_post_delay",
				'meta_query'     => array(
					array(
						'key'     => '_pmpro_sequence_post_belongs_to',
						'value'   => $sequence_id,
						'compare' => '=',
					),
				),
			);
		}
		
		if ( ! is_null( $pagesize ) ) {
			
			DBG::log( "Enable paging, grab page #: " . get_query_var( 'page' ) );
			
			$page_num = ( get_query_var( 'page' ) ) ? absint( get_query_var( 'page' ) ) : 1;
		}
		
		if ( $find_by_delay ) {
			DBG::log( "Requested look-up by delay value {$delay} in sequence {$sequence_id}" );
			$seq_args['meta_query'][] = array(
				'key'     => "_pmpro_sequence_{$sequence_id}_post_delay",
				'value'   => $delay,
				'compare' => $comparison,
				'type'    => $data_type,
			);
		}
		
		// DBG::log("Args for \WP_Query(): ");
		// DBG::log($args);
		
        if ( empty( $found ) ) {
		    
		    DBG::log("Having to load from database");
		    
	        $found = array();
	        $posts = new \WP_Query( $seq_args );
	
	        DBG::log( "Loaded {$posts->post_count} posts from wordpress database for sequence {$sequence_id}" );
	
	        /*        if ( ( 0 === $posts->post_count ) && is_null( $pagesize ) && ( is_null( $post_id ) ) && false === $converting_sequence ) {
	
				DBG::log("Didn't find any posts. Checking if we need to convert...?");
	
				if ( !$this->is_converted( $sequence_id ) ) {
	
					DBG::log("Forcing conversion attempt for sequence # {$sequence_id}");
					$this->convert_posts_to_v3( $sequence_id, true );
				}
			}
	*/
	        $is_admin = user_can( $user_id, 'manage_options' );
	
	        $member_days = ( ( $is_admin && $this->show_all_for_admin() ) || ( is_admin() && $this->is_cron == false && $this->show_all_for_admin() ) ) ? 9999 : $this->get_membership_days( $user_id );
	
	        DBG::log( "User {$user_id} has been a member for {$member_days} days. Admin? " . ( $is_admin ? 'Yes' : 'No' ) );
	
	        $post_list = $posts->get_posts();
	
	        wp_reset_postdata();
	
	        foreach ( $post_list as $post_key => $s_post ) {
		
		        DBG::log( "Loading metadata for post #: {$s_post->ID}" );
		
		        $s_post_id = $s_post->ID;
		
		        $tmp_delay = get_post_meta( $s_post_id, "_pmpro_sequence_{$sequence_id}_post_delay" );
		
		        $is_repeat = false;
		
		        // Add posts for all delay values with this post_id
		        foreach ( $tmp_delay as $p_delay ) {
			
			        $new_post = new \stdClass();
			
			        $new_post->id = $s_post_id;
			        // BUG: Doesn't work because you could have multiple post_ids released on same day: $p->order_num = $this->normalize_delay( $p_delay );
			        $new_post->delay        = isset( $s_post->delay ) ? $s_post->delay : $p_delay;
			        $new_post->permalink    = get_permalink( $s_post->ID );
			        $new_post->title        = $s_post->post_title;
			        $new_post->excerpt      = $s_post->post_excerpt;
			        $new_post->closest_post = false;
			        $new_post->current_post = false;
			        $new_post->is_future    = false;
			        $new_post->list_include = true;
			        $new_post->type         = $s_post->post_type;
			
			        // Only add posts to list if the member is supposed to see them
			        if ( $member_days >= $this->normalize_delay( $new_post->delay ) ) {
				
				        DBG::log( "Adding {$new_post->id} ({$new_post->title}) with delay {$new_post->delay} to list of available posts" );
				        $new_post->is_future = false;
				        $found[]             = $new_post;
			        } else {
				
				        // Or if we're not supposed to hide the upcomping posts.
				
				        if ( false === $this->hide_upcoming_posts() ) {
					
					        DBG::log( "Loading {$new_post->id} with delay {$new_post->delay} to list of upcoming posts. User is administrator level? " . ( $is_admin ? 'true' : 'false' ) );
					        $new_post->is_future = true;
					        $found[]             = $new_post;
				        } else {
					
					        DBG::log( "Ignoring post {$new_post->id} with delay {$new_post->delay} to sequence list for {$sequence_id}. User is administrator level? " . ( $is_admin ? 'true' : 'false' ) );
					        if ( ! is_null( $pagesize ) ) {
						
						        $post_list[ $post_key ]->list_include = false;
						        $post_list[ $post_key ]->is_future    = true;
						        //unset( $post_list[ $post_key ] );
					        }
					
				        }
			        }
		        } // End of foreach for delay values
		
		        $is_repeat = false;
	        } // End of foreach for post_list
        }
        
		DBG::log( "Found " . count( $found ) . " posts for sequence {$sequence_id} and user {$user_id}" );
		
		if ( empty( $post_id ) && empty( $delay ) /* && ! empty( $post_list ) */ ) {
			
			DBG::log( "Preparing array of posts to return to calling function" );
			
			$this->posts = $found;
			
			// Default to old _sequence_posts data
			if ( 0 == count( $this->posts ) ) {
				
				DBG::log( "No posts found using the V3 meta format. Need to convert! ", E20R_DEBUG_SEQ_WARNING );

//                $tmp = get_post_meta( $this->sequence_id, "_sequence_posts", true );
//                $this->posts = ( $tmp ? $tmp : array() ); // Fixed issue where empty sequences would generate error messages.
				
				/*                DBG::log("Saving to new V3 format... ", E20R_DEBUG_SEQ_WARNING );
				$this->save_sequence_post();

				DBG::log("Removing old format meta... ", E20R_DEBUG_SEQ_WARNING );
				delete_post_meta( $this->sequence_id, "_sequence_posts" );
*/
			}
			
			DBG::log( "Identify the closest post for {$user_id}" );
			$this->posts = $this->set_closest_post( $this->posts, $user_id );
			
			DBG::log( "Have " . count( $this->posts ) . " posts we're sorting" );
			usort( $this->posts, array( $this, "sort_posts_by_delay" ) );
			/*
			DBG::log("Have " . count( $this->upcoming )  ." upcoming/future posts we need to sort");
			if (!empty( $this->upcoming ) ) {

				usort( $this->upcoming, array( $this, "sort_posts_by_delay" ) );
			}
*/
			
			DBG::log( "Will return " . count( $this->posts ) . " sequence members and refreshing cache for {$sequence_id}" );
			
			if ( is_null( $pagesize ) ) {
				
				DBG::log( "Returning non-paginated list" );
				$this->set_cache( $this->posts, $sequence_id );
				
				return $this->posts;
			} else {
				
				DBG::log( "Preparing paginated list after updating cache for {$sequence_id}" );
				$this->set_cache( $this->posts, $sequence_id );
				
				if ( ! empty( $this->upcoming ) ) {
					
					DBG::log( "Appending the upcoming array to the post array. posts =  " . count( $this->posts ) . " and upcoming = " . count( $this->upcoming ) );
					$this->posts = array_combine( $this->posts, $this->upcoming );
					
					DBG::log( "Joined array contains " . count( $this->posts ) . " total posts" );
				}
				
				$paged_list = $this->paginate_posts( $this->posts, $pagesize, $page_num );
				
				// Special processing since we're paginating.
				// Make sure the $delay value is > first element's delay in $page_list and < last element
				
				list( $minimum, $maximum ) = $this->set_min_max( $pagesize, $page_num, $paged_list );
				
				DBG::log( "Check max / min delay values for paginated set. Max: {$maximum}, Min: {$minimum}" );
				
				$max_pages  = ceil( count( $this->posts ) / $pagesize );
				$post_count = count( $paged_list );
				
				DBG::log( "Returning the \\WP_Query result to process for pagination. Max # of pages: {$max_pages}, total posts {$post_count}" );
				
				return array( $paged_list, $max_pages );
			}
			
		} else {
			DBG::log( "Returning list of posts (size: " . count( $found ) . " ) located by specific post_id: {$post_id}" );
			
			return $found;
		}
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
		
		$days = 0;
		
		if ( empty( $user_id ) ) {
			global $current_user;
			$user_id = $current_user->ID;
		}
		
		$startdate = $this->get_user_startdate( $user_id, $level_id );
		$tz        = get_option( 'timezone_string' );
		
		DBG::log( "Startdate for {$user_id}: {$startdate}" );
		
		//check we received a start date
		if ( empty( $startdate ) ) {
			
			$startdate = strtotime( 'today ' . $tz );
		}
		
		$now  = current_time( "timestamp" );
		$days = $this->datediff( $startdate, $now, $tz );
		
		/*
        if (function_exists('pmpro_getMemberDays')) {
            $days = pmpro_getMemberDays($user_id, $level_id);
        }
*/
		
		return apply_filters( 'e20r-sequence-days-as-member', $days, $user_id, $level_id );
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
	public function pmpro_access_denied_msg( $msg, $post_id, $user_id ) {
		
		if ( ! function_exists( 'pmpro_has_membership_access' ) ||
		     ! function_exists( 'pmpro_getLevel' ) ||
		     ! function_exists( 'pmpro_implodeToEnglish' ) ||
		     ! function_exists( 'pmpro_getOption' )
		) {
			return $msg;
		}
		
		global $current_user;
		
		
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
		
		DBG::log( "Available PMPro Membership Levels to access this post: " );
		DBG::log( $post_membership_levels_names );
		
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
	 * Calculates the difference between two dates (specified in UTC seconds)
	 *
	 * @param $startdate (timestamp) - timestamp value for start date
	 * @param $enddate   (timestamp) - timestamp value for end date
	 *
	 * @return int
	 */
	private function datediff( $startdate, $enddate = null, $tz = 'UTC' ) {
		
		$days = 0;
		
		DBG::log( "Timezone: {$tz}" );
		
		if ( empty( $tz ) ) {
			$tz = 'UTC';
		};
		// use current day as $enddate if nothing is specified
		if ( ( is_null( $enddate ) ) && ( $tz == 'UTC' ) ) {
			
			$enddate = current_time( 'timestamp', true );
		} else if ( is_null( $enddate ) ) {
			
			$enddate = current_time( 'timestamp' );
		}
		
		// Create two DateTime objects
		$dStart = new \DateTime( date( 'Y-m-d', $startdate ), new \DateTimeZone( $tz ) );
		$dEnd   = new \DateTime( date( 'Y-m-d', $enddate ), new \DateTimeZone( $tz ) );
		
		if ( version_compare( PHP_VERSION, E20R_SEQ_REQUIRED_PHP_VERSION, '>=' ) ) {
			
			/* Calculate the difference using 5.3 supported logic */
			$dDiff = $dStart->diff( $dEnd );
			$dDiff->format( '%d' );
			//$dDiff->format('%R%a');
			
			$days = $dDiff->days;
			
			// Invert the value
			if ( $dDiff->invert == 1 ) {
				$days = 0 - $days;
			}
		} else {
			
			// V5.2.x workaround
			$dStartStr = $dStart->format( 'U' );
			$dEndStr   = $dEnd->format( 'U' );
			
			// Difference (in seconds)
			$diff = abs( $dStartStr - $dEndStr );
			
			// Convert to days.
			$days = $diff * 86400; // Won't manage DST correctly, but not sure that's a problem here..?
			
			// Sign flip if needed.
			if ( gmp_sign( $dStartStr - $dEndStr ) == - 1 ) {
				$days = 0 - $days;
			}
		}
		
		return $days + 1;
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
		
		// Return immediately if the value we're given is a # of days (i.e. an integer)
		if ( is_numeric( $date ) ) {
			return $date;
		}
		
		DBG::log( "User {$user_id}'s level ID {$level_id}" );
		
		if ( $this->is_valid_date( $date ) ) {
			// DBG::log("Using {$user_id} and {$level_id} for the credentials");
			$start_date = $this->get_user_startdate( $user_id, $level_id ); /* Needs userID & Level ID ... */
			
			if ( empty( $start_date ) && true === $this->show_all_for_admin() ) {
				
				DBG::log( "No start date specified, but admin should be shown everything" );
				
				$start_date = strtotime( "2013-01-01" );
			} else if ( empty( $start_date ) ) {
				
				DBG::log( "No start date specified, and admin shouldn't be shown everything" );
				$start_date = strtotime( "tomorrow" );
			}
			
			DBG::log( "Given date: {$date} and startdate: {$start_date} for user {$user_id} with level {$level_id}" );
			
			try {
				
				// Use PHP v5.2 and v5.3 compatible function to calculate difference
				$comp_date = strtotime( "{$date} 00:00:00" );
				$days      = $this->datediff( $start_date, $comp_date ); // current_time('timestamp')
				
			} catch ( \Exception $e ) {
				DBG::log( 'Error calculating days: ' . $e->getMessage() );
			}
		}
		
		DBG::log( "Days calculated: {$days} " );
		
		return $days;
	}
	
	/**
	 * Return a membership level object (stdClass/wpdb row) containing minimally an 'id' parameter
	 * Could also simply return false or null if the user doesn't have a level.
	 *
	 * @param int|null $user_id - Id of user (or null)
	 * @param bool     $force   - Whether to force refresh from a (possible) database table
	 *
	 * @return mixed|void - Object containing the level information (including an 'id' parameter.
	 */
	private function get_membership_level_for_user( $user_id = null, $force = false ) {
		
		$level = false;
		
		if ( function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
			$level = pmpro_getMembershipLevelForUser( $user_id, $force );
		}
		
		return apply_filters( 'e20r-sequence-membership-level-for-user', $level, $user_id, $force );
	}
	
	/**
	 * Test whether to show future sequence posts (i.e. not yet available to member)
	 *
	 * @return bool -- True if the admin has requested that unavailable posts not be displayed.
	 *
	 * @access public
	 */
	public function hide_upcoming_posts() {
		// DBG::log('hide_upcoming_posts(): Do we show or hide upcoming posts?');
		return ( $this->options->hideFuture == 1 ? true : false );
	}
	
	/**
	 * Whether to show all current and upcoming posts in a sequence list for users with admin privilege
	 *
	 * @return bool
	 */
	public function show_all_for_admin() {
		return ( isset( $this->options->showAdmin ) && $this->options->showAdmin == 1 ? true : false );
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
	 * Save post specific metadata to indicate sequence & delay value(s) for the post.
	 *
	 * @param null $sequence_id - The sequence to save data for.
	 * @param null $post_id     - The ID of the post to save metadata for
	 * @param null $delay       - The delay value
	 *
	 * @return bool - True/False depending on whether the save operation was a success or not.
	 * @since v3.0
	 *
	 */
	public function save_sequence_post( $sequence_id = null, $post_id = null, $delay = null ) {
		
		if ( is_null( $post_id ) && is_null( $delay ) && is_null( $sequence_id ) ) {
			
			// Save all posts in $this->posts array to new V3 format.
			
			foreach ( $this->posts as $p_obj ) {
				
				if ( ! $this->add_post_to_sequence( $this->sequence_id, $p_obj->id, $p_obj->delay ) ) {
					
					DBG::log( "Unable to add post {$p_obj->id} with delay {$p_obj->delay} to sequence {$this->sequence_id}", E20R_DEBUG_SEQ_WARNING );
					
					return false;
				}
			}
			
			return true;
		}
		
		if ( ! is_null( $post_id ) && ! is_null( $delay ) ) {
			
			if ( empty( $sequence_id ) ) {
				
				$sequence_id = $this->sequence_id;
			}
			
			DBG::log( "Saving post {$post_id} with delay {$delay} to sequence {$sequence_id}" );
			
			return $this->add_post_to_sequence( $sequence_id, $post_id, $delay );
		} else {
			DBG::log( "Need both post ID and delay values to save the post to sequence {$sequence_id}", E20R_DEBUG_SEQ_WARNING );
			
			return false;
		}
	}
	
	/**
	 * Private function to do the heavy lifting for the sequence specific metadata saves (per post)
	 *
	 * @param $sequence_id
	 * @param $post_id
	 * @param $delay
	 *
	 * @return bool
	 */
	private function add_post_to_sequence( $sequence_id, $post_id, $delay ) {
		
		global $current_user;
		
		DBG::log( "Adding post {$post_id} to sequence {$sequence_id} using v3 meta format" );
		
		/**
		 * if ( false === $found_post && ) {
		 *
		 * DBG::log("Post {$post_id} with delay {$delay} is already present in sequence {$sequence_id}");
		 * $this->set_error_msg( __( 'That post and delay combination is already included in this sequence', "e20r-sequences" ) );
		 * return true;
		 * }
		 **/
		
		$found_post = $this->is_present( $post_id, $delay );
		
		$posts = $this->find_by_id( $post_id, $sequence_id, $current_user->ID );
		
		if ( ( count( $posts ) > 0 && false === $this->allow_repetition() ) || true === $found_post ) {
			
			DBG::log( "Post is a duplicate and we're not allowed to add duplicates" );
			$this->set_error_msg( sprintf( __( "Warning: '%s' does not allow multiple delay values for a single post ID", "e20r-sequences" ), get_the_title( $sequence_id ) ) );
			
			foreach ( $posts as $p ) {
				
				DBG::log( "add_post_to_sequence(): Delay is different & we can't have repeat posts. Need to remove existing instances of {$post_id} and clear any notices" );
				$this->remove_post( $p->id, $p->delay, true );
			}
		}
		
		if ( is_admin() ) {
			
			$member_days = - 1;
		} else {
			
			$member_days = $this->get_membership_days( $current_user->ID );
		}
		
		DBG::log( "The post was not found in the current list of posts for {$sequence_id}" );
		
		DBG::log( "Loading post {$post_id} from DB using WP_Query" );
		$tmp = new \WP_Query( array(
				'p'              => $post_id,
				'post_type'      => apply_filters( 'e20r-sequence-managed-post-types', array( 'post', 'page' ) ),
				'posts_per_page' => 1,
				'post_status'    => apply_filters( 'e20r-sequence-allowed-post-statuses', array(
					'publish',
					'future',
					'private',
				) ),
			)
		);
		
		$p = $tmp->get_posts();
		
		DBG::log( "Loaded " . count( $p ) . " posts with WP_Query" );
		
		$new_post     = new \stdClass();
		$new_post->id = $p[0]->ID;
		
		$new_post->delay = $delay;
		// $new_post->order_num = $this->normalize_delay( $delay ); // BUG: Can't handle repeating delay values (ie. two posts with same delay)
		$new_post->permalink    = get_permalink( $new_post->id );
		$new_post->title        = get_the_title( $new_post->id );
		$new_post->is_future    = ( $member_days < $delay ) && ( $this->hide_upcoming_posts() ) ? true : false;
		$new_post->current_post = false;
		$new_post->type         = get_post_type( $new_post->id );
		
		$belongs_to = get_post_meta( $new_post->id, "_pmpro_sequence_post_belongs_to" );
		
		wp_reset_postdata();
		
		DBG::log( "Found the following sequences for post {$new_post->id}: " . ( false === $belongs_to ? 'Not found' : null ) );
		DBG::log( $belongs_to );
		
		if ( ( false === $belongs_to ) || ( is_array( $belongs_to ) && ! in_array( $sequence_id, $belongs_to ) ) ) {
			
			if ( false === add_post_meta( $post_id, "_pmpro_sequence_post_belongs_to", $sequence_id ) ) {
				DBG::log( "Unable to add/update this post {$post_id} for the sequence {$sequence_id}" );
			}
		}
		
		DBG::log( "Attempting to add delay value {$delay} for post {$post_id} to sequence: {$sequence_id}" );
		
		if ( ! $this->allow_repetition() ) {
			// TODO: Need to check if the meta/value combination already exists for the post ID.
			if ( false === add_post_meta( $post_id, "_pmpro_sequence_{$sequence_id}_post_delay", $delay, true ) ) {
				
				DBG::log( "add_post_to_sequenece() - Couldn't add {$post_id} with delay {$delay}. Attempting update operation" );
				
				if ( false === update_post_meta( $post_id, "_pmpro_sequence_{$sequence_id}_post_delay", $delay ) ) {
					DBG::log( "Both add and update operations for {$post_id} in sequence {$sequence_id} with delay {$delay} failed!", E20R_DEBUG_SEQ_WARNING );
				}
			}
			
		} else {
			
			$delays = get_post_meta( $post_id, "_pmpro_sequence_{$sequence_id}_post_delay" );
			
			DBG::log( "Checking whether the '{$delay}' delay value is already recorded for this post: {$post_id}" );
			
			if ( ( false === $delays ) || ( ! in_array( $delay, $delays ) ) ) {
				
				DBG::log( "add_post_to_seuqence() - Not previously added. Now adding delay value meta ({$delay}) to post id {$post_id}" );
				add_post_meta( $post_id, "_pmpro_sequence_{$sequence_id}_post_delay", $delay );
			} else {
				DBG::log( "Post # {$post_id} in sequence {$sequence_id} is already recorded with delay {$delay}" );
			}
		}
		
		if ( false === get_post_meta( $post_id, "_pmpro_sequence_post_belongs_to" ) ) {
			
			DBG::log( "Didn't add {$post_id} to {$sequence_id}", E20R_DEBUG_SEQ_WARNING );
			
			return false;
		}
		
		if ( false === get_post_meta( $post_id, "_pmpro_sequence_{$sequence_id}_post_delay" ) ) {
			
			DBG::log( "Couldn't add post/delay value(s) for {$post_id}/{$delay} to {$sequence_id}", E20R_DEBUG_SEQ_WARNING );
			
			return false;
		}
		
		// If we shoud be allowed to access this post.
		if ( $this->has_post_access( $current_user->ID, $post_id, false, $sequence_id ) ||
		     false === $new_post->is_future ||
		     ( ( true === $new_post->is_future ) && false === $this->hide_upcoming_posts() )
		) {
			
			DBG::log( "Adding post to sequence: {$sequence_id}" );
			$this->posts[] = $new_post;
		} else {
			
			DBG::log( "User doesn't have access to the post so not adding it." );
			$this->upcoming[] = $new_post;
		}
		
		usort( $this->posts, array( $this, 'sort_posts_by_delay' ) );
		
		if ( ! empty( $this->upcoming ) ) {
			
			usort( $this->upcoming, array( $this, 'sort_posts_by_delay' ) );
		}
		
		
		$this->set_cache( $this->posts, $sequence_id );
		
		return true;
	}
	
	public function is_present( $post_id, $delay ) {
		
		DBG::log( "Checking whether post {$post_id} with delay {$delay} is already included in {$this->sequence_id}" );
		
		if ( empty( $this->posts ) ) {
			DBG::log( "No posts in sequence {$this->sequence_id}yet. Post was NOT found" );
			
			return false;
		}
		
		foreach ( $this->posts as $k => $post ) {
			
			if ( ( $post_id == $post->id ) && ( $delay == $post->delay ) ) {
				DBG::log( "Post and delay combination WAS found!" );
				
				return $k;
			}
		}
		
		DBG::log( "Post {$post_id} and delay {$delay} combination was NOT found." );
		
		return false;
	}
	
	public function find_by_id( $post_id, $sequence_id = null, $user_id = null ) {
		
		DBG::log( "Locating post {$post_id} for {$user_id}." );
		global $current_user;
		
		$found = array();
		$posts = array();
		
		if ( is_null( $sequence_id ) && ( ! empty( $this->sequence_id ) ) ) {
			DBG::log( "No sequence ID specified in call. Using default value of {$this->sequence_id}" );
			$sequence_id = $this->sequence_id;
		}
		
		if ( is_null( $user_id ) && is_user_logged_in() ) {
			$user_id = $current_user->ID;
		}
  
		$posts = $this->get_cache( $sequence_id );
		
		if ( empty( $posts ) ) {
			
			DBG::log( "Cache is invalid.  Using load_sequence_post to grab the post(s) by ID: {$post_id}." );
			$posts = $this->load_sequence_post( $sequence_id, null, $post_id );
			
			if ( empty( $posts ) ) {
				
				DBG::log( "Couldn't find post based on post ID of {$post_id}. Now loading all posts in sequence" );
				$posts = $this->load_sequence_post();
			} else {
				DBG::log( "Returned " . count( $posts ) . " posts from load_sequnce_post() function" );
			}
		} else {
			
			DBG::log( "Have valid cache. Using cached post list to locate post with ID {$post_id}" );
			$this->posts = $posts;
		}
		
		if ( empty( $posts ) ) {
			DBG::log( "No posts in sequence. Returning empty list." );
			
			return array();
		}
		
		foreach ( $posts as $p ) {
			
			if ( $p->id == $post_id ) {
				
				DBG::log( "Including post # {$post_id}, delay: {$p->delay}" );
				$found[] = $p;
			}
		}
		
		return $found;
	}
	
	/**
	 *  Check whether to permit a given Post ID to have multiple entries and as a result delay values.
	 *
	 * @return bool - Depends on the setting.
	 * @access private
	 * @since  2.4.11
	 */
	private function allow_repetition() {
		DBG::log( "Returning: " . ( $this->options->allowRepeatPosts ? 'true' : 'false' ) );
		
		return $this->options->allowRepeatPosts;
	}
	
	/**
	 * Removes a post from the list of posts belonging to this sequence
	 *
	 * @param int  $post_id        -- The ID of the post to remove from the sequence
	 * @param int  $delay          - The delay value for the post we'd like to remove from the sequence.
	 * @param bool $remove_alerted - Whether to also remove any 'notified' settings for users
	 *
	 * @return bool - returns TRUE if the post was removed and the metadata for the sequence was updated successfully
	 *
	 * @access public
	 */
	public function remove_post( $post_id, $delay = null, $remove_alerted = true ) {
		
		$is_multi_post = false;
		
		if ( empty( $post_id ) ) {
			
			return false;
		}
		
		$this->load_sequence_post();
		
		if ( empty( $this->posts ) ) {
			
			return true;
		}
		
		foreach ( $this->posts as $i => $post ) {
			
			// Remove this post from the sequence
			if ( ( $post_id == $post->id ) && ( $delay == $post->delay ) ) {
				
				// $this->posts = array_values( $this->posts );
				
				$delays = get_post_meta( $post->id, "_pmpro_sequence_{$this->sequence_id}_post_delay" );
				
				DBG::log( "Delay meta_values: " );
				DBG::log( $delays );
				
				if ( 1 == count( $delays ) ) {
					
					DBG::log( "A single post associated with this post id: {$post_id}" );
					
					if ( false === delete_post_meta( $post_id, "_pmpro_sequence_{$this->sequence_id}_post_delay", $post->delay ) ) {
						
						DBG::log( "Unable to remove the delay meta for {$post_id} / {$post->delay}" );
						
						return false;
					}
					
					if ( false === delete_post_meta( $post_id, "_pmpro_sequence_post_belongs_to", $this->sequence_id ) ) {
						
						DBG::log( "Unable to remove the sequence meta for {$post_id} / {$this->sequence_id}" );
						
						return false;
					}
				} else if ( 1 < count( $delays ) ) {
					
					DBG::log( $delays );
					DBG::log( "Multiple (" . count( $delays ) . ") posts associated with this post id: {$post_id} in sequence {$this->sequence_id}" );
					
					if ( false == delete_post_meta( $post_id, "_pmpro_sequence_{$this->sequence_id}_post_delay", $post->delay ) ) {
						
						DBG::log( "Unable to remove the sequence meta for {$post_id} / {$this->sequence_id}" );
						
						return false;
					};
					
					DBG::log( "Keeping the sequence info for the post_id" );
				} else {
					DBG::log( "ERROR: There are _no_ delay values for post ID {$post_id}????" );
					
					return false;
				}
				
				DBG::log( "Removing entry #{$i} from posts array: " );
				DBG::log( $this->posts[ $i ] );
				
				unset( $this->posts[ $i ] );
			}
			
		}
		
		DBG::log( "Updating cache for sequence {$this->sequence_id}" );
		$this->set_cache( $this->posts, $this->sequence_id );
		
		// Remove the post ($post_id) from all cases where a User has been notified.
		if ( $remove_alerted ) {
			
			$this->remove_post_notified_flag( $post_id, $delay );
		}
		
		if ( 0 >= count( $this->posts ) ) {
			DBG::log( "Nothing left to cache. Cleaning up..." );
			$this->delete_cache( $this->sequence_id );
			$this->expires = null;
		}
		
		return true;
	}
	
	/**
	 * Function will remove the flag indicating that the user has been notified already for this post.
	 * Searches through all active User IDs with the same level as the Sequence requires.
	 *
	 * @param $post_id - The ID of the post to search through the active member list for
	 *
	 * @returns bool|array() - The list of user IDs where the remove operation failed, or true for success.
	 * @access private
	 */
	private function remove_post_notified_flag( $post_id, $delay ) {
		
		global $wpdb;
		
		DBG::log( 'Preparing SQL. Using sequence ID: ' . $this->sequence_id );
		
		$error_users = array();
		
		// Find all users that are active members of this sequence.
		$users = $this->get_users_of_sequence();
		
		foreach ( $users as $user ) {
			
			DBG::log( "Searching for Post ID {$post_id} in notification settings for user with ID: {$user->user_id}" );
			
			// $userSettings = get_user_meta( $user->user_id, $wpdb->prefix .  'pmpro_sequence_notices', true );
			$userSettings = $this->load_user_notice_settings( $user->user_id, $this->sequence_id );
			
			isset( $userSettings->id ) && $userSettings->id == $this->sequence_id ? DBG::log( "Notification settings exist for {$this->sequence_id}" ) : DBG::log( 'No notification settings found' );
			
			$notifiedPosts = isset( $userSettings->posts ) ? $userSettings->posts : array();
			
			if ( is_array( $notifiedPosts ) &&
			     ( $key = array_search( "{$post_id}_{$delay}", $notifiedPosts ) ) !== false
			) {
				
				DBG::log( "Found post # {$post_id} in the notification settings for user_id {$user->user_id} with key: {$key}" );
				DBG::log( "Found in settings: {$userSettings->posts[ $key ]}" );
				unset( $userSettings->posts[ $key ] );
				
				if ( $this->save_user_notice_settings( $user->user_id, $userSettings, $this->sequence_id ) ) {
					
					// update_user_meta( $user->user_id, $wpdb->prefix . 'pmpro_sequence_notices', $userSettings );
					DBG::log( "Deleted post # {$post_id} in the notification settings for user with id {$user->user_id}", E20R_DEBUG_SEQ_INFO );
				} else {
					DBG::log( "Unable to remove post # {$post_id} in the notification settings for user with id {$user->user_id}", E20R_DEBUG_SEQ_WARNING );
					$error_users[] = $user->user_id;
				}
			} else {
				DBG::log( "Could not find the post_id/delay combination: {$post_id}_{$delay} for user {$user->user_id}" );
			}
		}
		
		if ( ! empty( $error_users ) ) {
			return $error_users;
		}
		
		return true;
	}
	
	private function get_users_of_sequence() {
		
		// TODO: Add filter and remove dependency on PMPro for this data.
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
		
		DBG::log( "get_users_of_sequence() - Fetched " . count( $users ) . " user records for {$this->sequence_id}" );
		
		return $users;
	}
	
	/**
	 * Load all email alert settings for the specified user
	 *
	 * @param      $user_id     - User's ID
	 * @param null $sequence_id - The ID of the sequence
	 *
	 * @return mixed|null|stdClass - The settings object
	 */
	public function load_user_notice_settings( $user_id, $sequence_id = null ) {
		
		global $wpdb;
		
		DBG::log( "Attempting to load user settings for user {$user_id} and {$sequence_id}" );
		
		if ( empty( $sequence_id ) && ( empty( $this->sequence_id ) ) ) {
			
			DBG::log( "No sequence id defined. returning null", E20R_DEBUG_SEQ_WARNING );
			
			return null;
		}
		
		$optIn = get_user_meta( $user_id, "pmpro_sequence_id_{$sequence_id}_notices", true );
		
		DBG::log( "V3 user alert settings configured: " . ( isset( $optIn->send_notices ) ? 'Yes' : 'No' ) );
		
		if ( isset( $optIn->send_notices ) && is_array( $optIn->posts ) && in_array( '_', $optIn->posts ) ) {
			
			DBG::log( "Cleaning up post_id/delay combinations" );
			
			foreach ( $optIn->posts as $k => $id ) {
				
				if ( $id == '_' ) {
					
					unset( $optIn->posts[ $k ] );
				}
			}
			
			$clean = array();
			
			foreach ( $optIn->posts as $notified ) {
				$clean[] = $notified;
			}
			
			$optIn->posts = $clean;
			
			DBG::log( "Current (clean?) settings: " );
			DBG::log( $optIn );
		}
		
		if ( empty( $optIn ) || ( ! isset( $optIn->send_notices ) ) ) {
			
			DBG::log( "No settings for user {$user_id} and sequence {$sequence_id} found. Returning defaults.", E20R_DEBUG_SEQ_WARNING );
			$optIn     = $this->create_user_notice_defaults();
			$optIn->id = $sequence_id;
		}
		
		return $optIn;
	}
	
	/**
	 * Generates a stdClass() object containing the default user notice (alert) settings
	 * @return stdClass
	 */
	private function create_user_notice_defaults() {
		
		DBG::log( "Loading default opt-in settings" );
		$defaults = new \stdClass();
		
		$defaults->id               = $this->sequence_id;
		$defaults->send_notices     = ( $this->options->sendNotice == 1 ? true : false );
		$defaults->posts            = array();
		$defaults->optin_at         = ( $this->options->sendNotice == 1 ? current_time( 'timestamp' ) : - 1 );
		$defaults->last_notice_sent = - 1; // Never
		
		return $defaults;
	}
	
	public function save_user_notice_settings( $user_id, $settings, $sequence_id = null ) {
		
		DBG::log( "Attempting to save settings for {$user_id} and sequence {$sequence_id}" );
		// DBG::log( $settings );
		
		if ( is_null( $sequence_id ) && ( empty( $this->sequence_id ) ) ) {
			
			DBG::log( "No sequence ID specified. Exiting!", E20R_DEBUG_SEQ_WARNING );
			
			return false;
		}
		
		if ( is_null( $sequence_id ) && ( $this->sequence_id != 0 ) ) {
			
			DBG::log( "No sequence ID specified. Using {$this->sequence_id} " );
			$sequence_id = $this->sequence_id;
		}
		
		DBG::log( "Save V3 style user notification opt-in settings to usermeta for {$user_id} and sequence {$sequence_id}" );
		
		update_user_meta( $user_id, "pmpro_sequence_id_{$sequence_id}_notices", $settings );
		
		$test = get_user_meta( $user_id, "pmpro_sequence_id_{$sequence_id}_notices", true );
		
		if ( empty( $test ) ) {
			
			DBG::log( "Error saving V3 style user notification settings for ({$sequence_id}) user ID: {$user_id}", E20R_DEBUG_SEQ_WARNING );
			
			return false;
		}
		
		DBG::log( "Saved V3 style user alert settings for {$sequence_id}" );
		
		return true;
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
	 * @throws \Exception
	 */
	public function has_post_access( $user_id, $post_id, $isAlert = false, $sequence_id = null ) {
		
		DBG::log( "Checking access to post {$post_id} for user {$user_id} " );
		
		$existing_sequence_id = $this->sequence_id;
		$is_authorized_ajax   = ( is_admin() || ( false == $this->is_cron ) && ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) && isset( $_POST['in_admin_panel'] ) ) );
		$is_editor            = apply_filters( 'e20r-sequence-has-edit-privileges', $this->user_can_edit( $user_id ) );
		
		if ( true === $is_authorized_ajax && false === $is_editor ) {
			
			DBG::log( "User ({$user_id}) does not have edit permissions: " );
			DBG::log( "Editor: " . ( $is_editor ? 'true' : 'false' ) . " AJAX: " . ( $is_authorized_ajax ? 'true' : 'false' ) );
			// return false;
		}
		
		$p_type = get_post_type( $post_id );
		DBG::log( "Post with ID {$post_id} is of post type '{$p_type}'..." );
		
		$post_access = $this->has_membership_access( $post_id, $user_id );
		
		if ( 'pmpro_sequence' == $p_type && ( ( is_array( $post_access ) && ( false == $post_access[0] ) ) || ( ! is_array( $post_access ) && false == $post_access ) ) ) {
			
			DBG::log( "{$post_id} is a sequence and user {$user_id} does not have access to it!" );
			
			return false;
		}
		
		$retval        = false;
		$sequences     = $this->get_sequences_for_post( $post_id );
		$sequence_list = array_unique( $sequences );
		
		// is the post we're supplied is a sequence?
		if ( count( $sequence_list ) < count( $sequences ) ) {
			
			DBG::log( "Saving the pruned array of sequences" );
			
			$this->set_sequences_for_post( $post_id, $sequence_list );
		}
		
		if ( empty( $sequences ) ) {
			
			return true;
		}
		
		// TODO: Remove dependency on PMPro functions in has_post_access()
		// Does the current user have a membership level giving them access to everything?
		$all_access_levels = apply_filters( "pmproap_all_access_levels", array(), $user_id, $post_id );
		
		if ( ! empty( $all_access_levels ) && $this->has_membership_level( $all_access_levels, $user_id ) ) {
			
			DBG::log( "This user ({$user_id}) has one of the 'all access' membership levels" );
			
			return true; //user has one of the all access levels
		}
		
		if ( $is_authorized_ajax ) {
			DBG::log( "User is in admin panel. Allow access to the post" );
			
			return true;
		}
		
		foreach ( $sequence_list as $sid ) {
			
			if ( ! is_null( $sequence_id ) && ! in_array( $sequence_id, $sequence_list ) ) {
				
				DBG::log( "{$sequence_id} is not one of the sequences managing this ({$post_id}) post: {$sid}" );
				continue;
			}
			
			if ( is_null( $sequence_id ) && $this->sequence_id != $sid ) {
				
				DBG::log( "Loading sequence #{$sid}" );
				$this->get_options( $sid );
				$this->load_sequence_post( $sequence_id, null, $post_id );
			}
			
			$allowed_post_statuses = apply_filters( 'e20r-sequence-allowed-post-statuses', array(
				'publish',
				'future',
				'private',
			) );
			$curr_post_status      = get_post_status( $post_id );
			
			// Only consider granting access to the post if it is in one of the allowed statuses
			if ( ! in_array( $curr_post_status, $allowed_post_statuses ) ) {
				
				DBG::log( "Post {$post_id} with status {$curr_post_status} isn't accessible", E20R_DEBUG_SEQ_WARNING );
				
				return false;
			}
			
			/**
			 * Anticipates a return value of a 3 element array:
			 * array(
			 *          0 => boolean (true/false to indicate whether $user_id has access to $sid (sequence id/post id for sequence definition)
			 *          1 => array( numeric list of membership type/level IDs that have access to this sequence id )
			 *          2 => array( string list of membership level names/human readable identifiers that reflect the order of the numeric array in 1 )
			 * )
			 ***/
			$access = $this->has_membership_access( $sid, $user_id, true );
			
			DBG::log( "Checking sequence access for membership level {$sid}: Access = " . ( $access[0] ? 'true' : 'false' ) );
			DBG::log( $access );
			
			// $usersLevels = pmpro_getMembershipLevelsForUser( $user_id );
			
			if ( true == $access[0] ) {
				
				$s_posts = $this->find_by_id( $post_id, $this->sequence_id, $user_id );
				
				if ( ! empty( $s_posts ) ) {
					
					DBG::log( "Found " . count( $s_posts ) . " post(s) in sequence {$this->sequence_id} with post ID of {$post_id}" );
					
					foreach ( $s_posts as $post ) {
						
						DBG::log( "UserID: {$user_id}, post: {$post->id}, delay: {$post->delay}, Alert: {$isAlert} for sequence: {$sid} " );
						
						if ( $post->id == $post_id ) {
							
							foreach ( $access[1] as $level_id ) {
								
								DBG::log( "Processing for membership level ID {$level_id}" );
								
								if ( $this->options->delayType == 'byDays' ) {
									DBG::log( "Sequence {$this->sequence_id} is configured to store sequence by days since startdate" );
									
									// Don't add 'preview' value if this is for an alert notice.
									if ( ! $isAlert ) {
										
										$membership_duration = $this->get_membership_days( $user_id, $level_id ) + $this->options->previewOffset;
									} else {
										
										$membership_duration = $this->get_membership_days( $user_id, $level_id );
									}
									
									/**
									 * Allow user to add an offset (pos/neg integer) to the 'current day' calculation so they may
									 * offset when this user apparently started their access to the sequence
									 *
									 * @since 2.4.13
									 * @since 4.4.20 - Added 'user_id' so the filter can map user/sequence/offsets.
									 */
									$offset = apply_filters( 'e20r-sequence-add-startdate-offset', __return_zero(), $this->sequence_id, $user_id );
									
									$membership_duration += $offset;
									
									if ( $post->delay <= $membership_duration ) {
										
										// Set users membership Level
										$this->e20r_sequence_user_level = $level_id;
										$retval                         = true;
										break;
									}
								} else if ( $this->options->delayType == 'byDate' ) {
									DBG::log( "Sequence {$this->sequence_id} is configured to store sequence by dates" );
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
									
									$today = date( __( 'Y-m-d', "e20r-sequences" ), $timestamp );
									
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
		
		DBG::log( "NO access granted to post {$post_id} for user {$user_id}" );
		
		if ( $this->sequence_id !== $existing_sequence_id ) {
			DBG::log( "Resetting sequence info for {$existing_sequence_id}" );
			$this->init( $existing_sequence_id );
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
		
		// TODO: Remove dependency of pmpro_hasMembershipLevel out of the sequences class and into own module
		if ( function_exists( 'pmpro_hasMembershipLevel' ) ) {
			$has_level = pmpro_hasMembershipLevel( $levels, $user_id );
		}
		
		return apply_filters( 'e20r-sequence-has-membership-level', $has_level, $levels, $user_id );
	}
	
	/**
	 * Decide whether the user ID should have access to the post_id
	 * Anticipates a return value consisting of a 3 element array:
	 * array(
	 *          0 => boolean (true/false to indicate whether $user_id has access to $sid (sequence id/post id for
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
				'No membership level found',
			);
		} else {
			$access = false;
		}
		
		DBG::log( "Testing access for post # {$post_id} by user {$user_id} via membership function(s)" );
		
		// TODO: Remove pmpro_has_membership_access from e20r-sequences and into own module
		if ( function_exists( 'pmpro_has_membership_access' ) ) {
			
			DBG::log( "Found the PMPro Membership access function" );
			
			remove_filter( "pmpro_has_membership_access_filter", array( &$this, "has_membership_access_filter" ), 9 );
			$access = pmpro_has_membership_access( $post_id, $user_id, $return_membership_levels );
			add_filter( "pmpro_has_membership_access_filter", array( &$this, "has_membership_access_filter" ), 9, 4 );
			
			if ( ( ( ! is_array( $access ) ) && true == $access ) ) {
				DBG::log( "Didn't receive an array for the access info" );
				$user_level = pmpro_getMembershipLevelForUser( $user_id, true );
				$access     = array( true, array( $access ), array( $user_level->name ) );
			}
			
			DBG::log( "User {$user_id} has access? " . ( $access[0] ? "Yes" : "No" ) );
		}
		
		return apply_filters( 'e20r-sequence-membership-access', $access, $post_id, $user_id, $return_membership_levels );
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
		
		DBG::log( "Check whether we've still got old post_sequences data stored. " . $this->who_called_me() );
		
		$post_sequences = get_post_meta( $post_id, "_post_sequences", true );
		
		if ( ! empty( $post_sequences ) ) {
			
			DBG::log( "Need to migrate to V3 sequence list for post ID {$post_id}", E20R_DEBUG_SEQ_WARNING );
			DBG::log( $post_sequences );
			
			/*            foreach ( $post_sequences as $seq_id ) {

                add_post_meta( $post_id, '_pmpro_sequence_post_belongs_to', $seq_id, true ) or
                    update_post_meta( $post_id, '_pmpro_sequence_post_belongs_to', $seq_id );
            }

            DBG::log("Removing old sequence list metadata");
            delete_post_meta( $post_id, '_post_sequences' );
*/
		}
		
		DBG::log( "Attempting to load sequence list for post {$post_id}", E20R_DEBUG_SEQ_INFO );
		$sequence_ids = get_post_meta( $post_id, '_pmpro_sequence_post_belongs_to' );
		
		$sequence_count = array_count_values( $sequence_ids );
		
		foreach ( $sequence_count as $s_id => $count ) {
			
			if ( $count > 1 ) {
				
				if ( delete_post_meta( $post_id, '_pmpro_sequence_post_belongs_to', $s_id ) ) {
					
					if ( ! add_post_meta( $post_id, '_pmpro_sequence_post_belongs_to', $s_id, true ) ) {
						
						DBG::log( "Unable to clean up the sequence list for {$post_id}", E20R_DEBUG_SEQ_WARNING );
					}
				}
			}
		}
		
		$sequence_ids = array_unique( $sequence_ids );
		
		DBG::log( "Loaded " . count( $sequence_ids ) . " sequences that post # {$post_id} belongs to", E20R_DEBUG_SEQ_INFO );
		
		return ( empty( $sequence_ids ) ? array() : $sequence_ids );
	}
	
	/**
	 * Displays the 2nd function in the current stack trace (i.e. the one that called the one that called "me"
	 *
	 * @access private
	 * @since  v2.0
	 */
	private function who_called_me() {
		
		$trace  = debug_backtrace();
		$caller = $trace[2];
		
		$trace = "Called by {$caller['function']}()";
		if ( isset( $caller['class'] ) ) {
			$trace .= " in {$caller['class']}()";
		}
		
		return $trace;
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
		
		DBG::log( "Adding sequence info to post # {$post_id}" );
		
		$retval = true;
		
		$seq = get_post_meta( $post_id, '_pmpro_sequence_post_belongs_to' );
		if ( is_array( $sequence_ids ) ) {
			
			DBG::log( "Received array of sequences to add to post # {$post_id}" );
			DBG::log( $sequence_ids );
			
			$sequence_ids = array_unique( $sequence_ids );
			
			foreach ( $sequence_ids as $id ) {
				
				if ( ( false === $seq ) || ( ! in_array( $id, $seq ) ) ) {
					
					DBG::log( "Not previously added. Now adding sequence ID meta ({$id}) for post # {$post_id}" );
					$retval = $retval && add_post_meta( $post_id, '_pmpro_sequence_post_belongs_to', $id );
				} else {
					DBG::log( "Post # {$post_id} is already included in sequence {$id}" );
				}
			}
		} else {
			
			DBG::log( "Received sequence id ({$sequence_ids} to add for post # {$post_id}" );
			
			if ( ( false === $seq ) || ( ! in_array( $sequence_ids, $seq ) ) ) {
				
				DBG::log( "Not previously added. Now adding sequence ID meta ({$sequence_ids}) for post # {$post_id}" );
				$retval = $retval && add_post_meta( $post_id, '_pmpro_sequence_post_belongs_to', $sequence_ids );
			}
		}
		
		return $retval;
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
		
		DBG::log( "Received posts: " . count( $post_list ) . " and user ID: " . ( is_null( $user_id ) ? 'None' : $user_id ) );
		
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
			
			if ( ( $post->delay == $closest_post->delay ) && ( $post_id == $closest_post->id ) ) {
				
				DBG::log( "Most current post for user {$user_id} found for post id: {$post_id}" );
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
	 * @return bool -- Post ID or FALSE (if error)
	 *
	 * @access public
	 */
	public function find_closest_post( $user_id = null ) {
		
		if ( empty( $user_id ) ) {
			
			DBG::log( "No user ID specified by callee: " . $this->who_called_me() );
			
			global $current_user;
			$user_id = $current_user->ID;
		}
		
		// Get the current day of the membership (as a whole day, not a float)
		$membership_day = $this->get_membership_days( $user_id );
		
		// Load all posts in this sequence
		/*
        if ( false === $this->is_cache_valid() ) {
            $this->load_sequence_post();
        }
		*/
		DBG::log( "Have " . count( $this->posts ) . " posts in sequence." );
		
		// Find the post ID in the postList array that has the delay closest to the $membership_day.
		$closest = $this->find_closest_post_by_delay_val( $membership_day, $user_id );
		
		if ( isset( $closest->id ) ) {
			
			DBG::log( "For user {$user_id} on day {$membership_day}, the closest post is #{$closest->id} (with a delay value of {$closest->delay})" );
			
			return $closest;
		}
		
		return null;
	}
	
	public function find_posts_by_delay( $delay, $user_id = null ) {
		
		$posts = array();
		DBG::log( "Have " . count( $this->posts ) . " to process" );
		
		foreach ( $this->posts as $post ) {
			
			if ( $post->delay <= $delay ) {
				
				$posts[] = $post;
			}
		}
		
		if ( empty( $posts ) ) {
			
			$posts = $this->find_closest_post( $user_id );
		}
		
		DBG::log( "Returning " . count( $posts ) . " with delay value <= {$delay}" );
		
		return $posts;
		
	}
	
	/**
	 * Compares the object to the array of posts in the sequence
	 *
	 * @param $delay_comp -- Delay value to compare to
	 *
	 * @return stdClass -- The post ID of the post with the delay value closest to the $delay_val
	 *
	 * @access private
	 */
	private function find_closest_post_by_delay_val( $delay_comp, $user_id = null ) {
		
		
		if ( null === $user_id ) {
			
			$user_id = $this->e20r_sequence_user_id;
		}
		
		$distances = array();
		
		// DBG::log( $postArr );
		
		foreach ( $this->posts as $key => $post ) {
			
			$n_delay           = $this->normalize_delay( $post->delay );
			$distances[ $key ] = abs( $delay_comp - ( $n_delay /* + 1 */ ) );
		}
		
		// Verify that we have one or more than one element
		if ( count( $distances ) > 1 ) {
			
			$ret_val = $this->posts[ array_search( min( $distances ), $distances ) ];
		} else if ( count( $distances ) == 1 ) {
			$ret_val = $this->posts[ $key ];
		} else {
			$ret_val = null;
		}
		
		return $ret_val;
		
	}
	
	/**
	 * Pagination logic for posts.
	 *
	 * @param $post_list    - Posts to paginate
	 * @param $page_size    - Size of page (# of posts per page).
	 * @param $current_page - The current page number
	 *
	 * @return array - Array of posts to include in the current page.
	 */
	private function paginate_posts( $post_list, $page_size, $current_page ) {
		
		$page = array();
		
		$last_key  = ( $page_size * $current_page ) - 1;
		$first_key = $page_size * ( $current_page - 1 );
		
		foreach ( $post_list as $k => $post ) {
			
			// skip if we've already marked this post for exclusion.
			if ( false === $post->list_include ) {
				
				continue;
			}
			
			if ( ! ( ( $k <= $last_key ) && ( $k >= $first_key ) ) ) {
				DBG::log( "Excluding {$post->id} with delay {$post->delay} from post/page/list" );
				// $page[] = $post;
				$post_list[ $k ]->list_include = false;
			}
		}
		
		return $post_list;
		
	}
	
	/**
	 * Get the first and last post to include on the page during pagination
	 *
	 * @param $pagesize  - The size of the page
	 * @param $page_num  - The order of the page
	 * @param $post_list - The posts to paginate
	 *
	 * @return array - Array consisting of the max and min delay value for the post(s) on the page.
	 */
	private function set_min_max( $pagesize, $page_num, $post_list ) {
		
		/**
		 * Doesn't account for sort order.
		 * @since 4.4.1
		 */
		
		/**
		 * Didn't account for pages < pagesize.
		 * @since 4.4
		 */
		
		if ( $this->options->sortOrder == SORT_DESC ) {
			$min_key = 0;
			$max_key = ( count( $post_list ) >= $pagesize ) ? $pagesize - 1 : count( $post_list ) - 1;
		}
		
		if ( $this->options->sortOrder == SORT_ASC ) {
			$min_key = ( count( $post_list ) >= $pagesize ) ? $pagesize - 1 : count( $post_list ) - 1;
			$max_key = 0;
		}
		
		DBG::log( "Max key: {$max_key} and min key: {$min_key}" );
		$min = $post_list[ $max_key ]->delay;
		$max = $post_list[ $min_key ]->delay;
		
		DBG::log( "Gives min/max values: Min: {$min}, Max: {$max}" );
		
		return array( $min, $max );
		
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
	 * Configure metabox for the normal Post/Page editor
	 */
	public function post_metabox( $object = null, $box = null ) {
		
		DBG::log( "Post metaboxes being configured" );
		global $load_e20r_sequence_admin_script;
		
		$load_e20r_sequence_admin_script = true;
		
		foreach ( $this->managed_types as $type ) {
			
			if ( $type !== 'pmpro_sequence' ) {
				$view = apply_filters( 'get_sequence_views_class_instance', null );
				add_meta_box( 'e20r-seq-post-meta', __( 'Drip Feed Settings', "e20r-sequences" ), array(
					$view,
					'render_post_edit_metabox',
				), $type, 'side', 'high' );
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
		
		do_action( 'e20r_sequences_load_membership_plugin_meta' );
		
		//PMPro box - TODO: Move to special add-on & call via the e20r_sequences_load_membership_plugin_meta hook
		add_meta_box( 'pmpro_page_meta', __( 'Require Membership', "pmpro" ), 'pmpro_page_meta', 'pmpro_sequence', 'side' );
		
		$view = apply_filters( 'get_sequence_views_class_instance', null );
		
		DBG::log( "Loading sequence settings meta box" );
		
		// sequence settings box (for posts & pages)
		add_meta_box( 'e20r-sequence-settings', __( 'Settings for Sequence', "e20r-sequences" ), array(
			$view,
			'settings',
		), 'pmpro_sequence', 'side', 'high' );
		
		DBG::log( "Loading sequence post list meta box" );
		//sequence meta box
		add_meta_box( 'e20r_sequence_meta', __( 'Posts in Sequence', "e20r-sequences" ), array(
			$view,
			"sequence_list_metabox",
		), 'pmpro_sequence', 'normal', 'high' );
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
		
		$args = array(
			'post_status'            => $status,
			'posts_per_page'         => - 1,
			'post_type'              => $post_types,
			'orderby'                => 'modified',
//            'order' => 'DESC',
			'cache_results'          => true,
			'update_post_meta_cache' => true,
		);
		
		$all_posts = new \WP_Query( $args );
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
	 */
	public function display_sequence_content( $content ) {
		
		global $post;
		global $pagenow;
		global $current_user;
		
		if ( ( $pagenow == 'post-new.php' || $pagenow == 'edit.php' || $pagenow == 'post.php' ) ) {
			
			return $content;
		}
		
		if ( is_singular() && is_main_query() && ( 'pmpro_sequence' == $post->post_type ) && $this->has_membership_access( $post->ID, $current_user->ID ) ) {
			
			global $load_e20r_sequence_script;
			$utils = \E20R_Utils::get_instance();
			
			$load_e20r_sequence_script = true;
			
			DBG::log( "E20R Sequence display {$post->ID} - " . get_the_title( $post->ID ) . " : " . $this->who_called_me() . ' and page base: ' . $pagenow );
			
			if ( ! $this->init( $post->ID ) ) {
				return $utils->display_notice() . $content;
			}
			
			// If we're supposed to show the "days of membership" information, adjust the text for type of delay.
			if ( intval( $this->options->lengthVisible ) == 1 ) {
				
				$content .= sprintf( "<p>%s</p>", sprintf( __( "You are on day %s of your membership", "e20r-sequences" ), $this->get_membership_days() ) );
			}
			
			// Add the list of posts in the sequence to the content.
			$content .= $this->get_post_list_as_html();
		}
		
		return $content;
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
		
		if ( ! is_null( $id ) ) {
			
			$this->sequence = get_post( $id );
			DBG::log( 'Loading the "' . get_the_title( $id ) . '" sequence settings' );
			
			// Set options for the sequence
			$this->get_options( $id );
			
			if ( 0 != $current_user->ID || $this->is_cron ) {
				DBG::log( 'init() - Loading the "' . get_the_title( $id ) . '" sequence posts' );
				$this->load_sequence_post();
			}
			
			if ( empty( $this->posts ) && ( ! $this->is_converted( $id ) ) ) {
				
				DBG::log( "Need to convert sequence with ID {$id } to version 3 format!" );
			}
			
			DBG::log( 'init complete' );
			
			return $this->sequence_id;
		}
		
		if ( ( $id == null ) && ( $this->sequence_id == 0 ) ) {
			throw new \Exception( __( 'No sequence ID specified.', "e20r-sequences" ) );
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
	 */
	public function get_post_list_as_html( $echo = false ) {
		
		DBG::log( "Generate HTML list of posts for sequence #: {$this->sequence_id}" );
		
		//global $current_user;
		// $this->load_sequence_post(); // Unnecessary
		
		if ( ! empty( $this->posts ) ) {
			
			$view = apply_filters( 'get_sequence_views_class_instance', false );
			
			// TODO: Have upcoming posts be listed before or after the currently active posts (own section?) - based on sort setting
			$content = $view->create_sequence_list( true, 30, true, null, false );
			
			if ( $echo ) {
				
				echo $content;
			}
			
			return $content;
		}
		
		return false;
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
			// Convert the delay to a date
			
			$memberDays = round( $this->get_membership_days(), 0 );
			
			$delayDiff = ( $delay - $memberDays );
			DBG::log( 'Delay: ' . $delay . ', memberDays: ' . $memberDays . ', delayDiff: ' . $delayDiff );
			
			return date_i18n( get_option( 'date_format' ), strtotime( "+" . $delayDiff . " days" ) );
		}
		
		return $delay; // It's stored as a number, not a date
		
	}
	
	/**
	 * @param $total -- Total number of posts to paginate
	 *
	 * @return string -- Pagination HTML
	 */
	public function post_paging_nav( $total ) {
		
		$html = '';
		
		DBG::log( "Total count: {$total}" );
		
		if ( $total > 1 ) {
			
			if ( ! $current_page = get_query_var( 'page' ) ) {
				$current_page = 1;
			}
			
			DBG::log( "Current Page #: {$current_page}" );
			
			$paged  = ( get_query_var( 'page' ) ) ? absint( get_query_var( 'page' ) ) : 1;
			$base   = @add_query_arg( 'page', '%#%' );
			$format = '?page=%#%';
			
			$prev_arrow = is_rtl() ? '&rarr;' : '&larr;';
			$next_arrow = is_rtl() ? '&larr;' : '&rarr;';
			
			ob_start();
			
			?>
            <nav class="navigation paging-navigation" role="navigation">
                <h4 class="screen-reader-text"><?php _e( 'Navigation', "e20r-sequences" ); ?></h4>
				<?php echo paginate_links( array(
					'base'               => $base,
					'format'             => $format,
					'total'              => $total,
					'current'            => $paged,
					'mid_size'           => 1,
					'prev_text'          => sprintf( __( '%s Previous', "e20r-sequences" ), $prev_arrow ),
					'next_text'          => sprintf( __( 'Next %s', "e20r-sequences" ), $next_arrow ),
					'prev_next'          => true,
					'type'               => 'list',
					'before_page_number' => '<span class="screen-reader-text">' . __( 'Page', "e20r-sequences" ) . ' </span>',
				) ); ?>
            </nav>
			<?php
			$html = ob_get_clean();
		}
		
		return $html;
	}
	
	/**
	 * Filter the message for users to check for sequence info.
	 *
	 * @param $text (string) -- The text to filter
	 *
	 * @return string -- the filtered text
	 */
	public function text_filter( $text ) {
		
		global $current_user, $post, $pagenow;
		
		if ( ( $pagenow == 'post-new.php' || $pagenow == 'edit.php' || $pagenow == 'post.php' ) ) {
			
			return $text;
		}
		
		if ( ! empty( $current_user ) && ( ! empty( $post ) ) ) {
			
			DBG::log( "Current sequence ID: {$this->sequence_id} vs Post ID: {$post->ID}" );
			
			$post_sequences   = $this->get_sequences_for_post( $post->ID );
			$days_since_start = $this->get_membership_days( $current_user->ID );
			$delay = null;
			$post_id = null;
			
			//Update text. The user either will have to wait or sign up.
			$insequence = false;
			$level_info = array();
			
			foreach ( $post_sequences as $ps ) {
				
				DBG::log( "Checking access to {$ps}" );
				
				$access = $this->has_sequence_access( $current_user->ID, $ps );
				
				if ( ! is_array( $access ) && false === $access ) {
					
					$level = array();
					
					foreach ( $this->e20r_sequence_user_level as $level_id ) {
						$level = pmpro_getLevel( $level_id )->name;
					}
					
					DBG::log( "Generating access array entry for {$ps}" );
					$level_info[ $ps ] = array(
						'name'   => $level,
						'link'   => add_query_arg( 'level', $this->e20r_sequence_user_level, pmpro_url( 'checkout' ) ),
						'access' => $access,
					);
					
				} else if ( is_array( $access ) ) {
					
					DBG::log( "Using supplied access array for {$ps}" );
					$level_info[ $ps ] = array(
						'name'   => $access[2][0],
						'link'   => add_query_arg( 'level', $access[1][0], pmpro_url( 'checkout' ) ),
						'access' => $access[0],
					);
				}
                
                DBG::log( "Level info: " . print_r( $level_info, true ) );
				
				if ( ( is_array( $access ) && true == $access[0] ) || ( ! is_array( $access ) && true == $access ) ) {
					
					DBG::log( "It's possible user has access to sequence: {$ps} " );
					$insequence = $ps;
					
					if ( ! $this->init( $ps ) ) {
						return $this->display_error() . $text;
					}
					
					$post_list = $this->find_by_id( $post->ID );
					$r         = array();
					
					foreach ( $post_list as $k => $p ) {
						
						if ( $days_since_start >= $p->delay ) {
							$r[] = $p;
						}
					}
					
					if ( ! empty( $r ) ) {
						
						$delay   = $r[0]->delay;
						$post_id = $r[0]->id;
					} else {
					    DBG::log("Didn't add any delay/post info???");
                    }
				}
				
				if ( false !== $insequence ) {
					
					//user has one of the sequence levels, find out which one and tell him how many days left
					$text = sprintf( "%s<br/>",
                        sprintf(
                                __( "This content is only available to existing members at the specified time or day. <span class=\"e20r-sequences-required-levels\"> Required %s: </span><a href='%s'>%s</a>", "e20r-sequences" ),
                                __( "membership", "e20r-sequences" ),
                                get_permalink( $ps ),
                                get_the_title( $ps )
                        )
                    );
					
					if ( !empty( $delay ) && !empty( $post_id )) {
					 
						switch ( $this->options->delayType ) {
							
							case 'byDays':
								
								switch ( $this->options->showDelayAs ) {
									
									case E20R_SEQ_AS_DAYNO:
										
										$text .= '<span class="e20r-sequence-delay-prompt">' . sprintf(
										        __( 'You will be able to access "%s" on day %s of your %s', "e20r-sequences" ),
                                                get_the_title( $post_id ),
                                                $this->display_proper_delay( $delay ),
                                                __( "membership", "e20r-sequences" )
                                            ) . '</span>';
										break;
									
									case E20R_SEQ_AS_DATE:
										
										$text .= '<span class="e20r-sequence-delay-prompt">' . sprintf(
										        __( 'You will be able to  access "%s" on %s', "e20r-sequences" ),
                                                get_the_title( $post_id ),
                                                $this->display_proper_delay( $delay )
                                            ) . '</span>';
										break;
								}
								
								break;
							
							case 'byDate':
								$text .= '<span class="e20r-sequence-delay-prompt">' . sprintf(
								        __( 'You will be able to access "%s" on %s', "e20r-sequences" ),
                                        get_the_title( $post_id ),
                                        $delay
                                    ) . '</span>';
								break;
							
							default:
							
						}
					}
					
				} else {
					
					DBG::log( "Level info: " . print_r( $level_info, true ) );
					
					// User has to sign up for one of the sequence(s)
					if ( count( $post_sequences ) == 1 ) {
						
						$tmp   = $post_sequences;
						$seqId = array_pop( $tmp );
						
						$text = sprintf( "%s<br/>",
							sprintf(
								__( 'This content is only available to active %s who have logged in. <span class="e20r-sequences-required-levels"> Required %s: </span><a href="%s">%s</a>', "e20r-sequences" ),
								__( 'members', 'e20r-sequences' ),
								__( "membership(s)", "e20r-sequences" ),
								( isset( $level_info[ $seqId ]['link'] ) ? $level_info[ $seqId ]['link'] : pmpro_url( 'levels' ) ),
								( isset( $level_info[ $seqId ]['name'] ) ? $level_info[ $seqId ]['name'] : 'Unknown' )
							)
						);
					} else {
						
						$seq_links = array();
						
						foreach ( $post_sequences as $sequence_id ) {
							// $level =$level_info[$sequence_id];
							
							$seq_links[] = sprintf( '<a href="%s">%s</a>&nbsp;',
								( isset( $level_info[ $sequence_id ]['link'] ) ? $level_info[ $sequence_id ]['link'] : pmpro_url( 'levels' ) ),
								( isset( $level_info[ $sequence_id ]['name'] ) ? $level_info[ $sequence_id ]['name'] : 'Unknown' )
							);
						}
						
						$text = sprintf( '<p>%s</p>',
							sprintf( __( 'This content is only available to active %s who have logged in. <span class="e20r-sequences-required-levels">Required %s: %s</span>', "e20r-sequences" ),
								__( 'members', 'e20r-sequenced' ),
								__( "membership(s)", "e20r-sequences" ),
								implode( sprintf( ', %s ', __( 'or', 'e20r-sequences' ) ), $seq_links )
							) );
					}
				}
			}
		}
		
		return $text;
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
		
		if ( ( ! empty( $sequence_id ) ) && ( 'pmpro_sequence' != get_post_type( $sequence_id ) ) ) {
			
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
		// Get the preview offset (if it's defined). If not, set it to 0
		// for compatibility
		if ( ! isset( $this->options->previewOffset ) ) {
			
			DBG::log( "is_after_delay() - the previewOffset value doesn't exist yet {$this->options->previewOffset}. Fixing now." );
			$this->options->previewOffset = 0;
			$this->save_sequence_meta(); // Save the settings (only the first when this variable is empty)
			
		}
		
		$offset = $this->options->previewOffset;
		// DBG::log('is_after_delay() - Preview enabled and set to: ' . $offset);
		
		if ( $this->is_valid_date( $delay ) ) {
			// Fixed: Now supports DST changes (i.e. no "early or late availability" in DST timezones
			// $now = current_time('timestamp') + ($offset * 86400);
			$now = $this->get_now_and_offset( $offset );
			
			// TODO: Add support for startWhen options (once the plugin supports differentiating on when the drip starts)
			$delayTime = strtotime( $delay . ' 00:00:00.0 ' . get_option( 'timezone_string' ) );
			
			// DBG::log('is_after_delay() - Now = ' . $now . ' and delay time = ' . $delayTime );
			
			return ( $now >= $delayTime ? true : false ); // a date specified as the $delay
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
		
		if ( ( $sequence_id != 0 ) && ( $sequence_id != $this->sequence_id ) ) {
			
			DBG::log( 'save_sequence_meta() - Unknown sequence ID. Need to instantiate the correct sequence first!' );
			
			return false;
		}
		
		try {
			
			// Update the *_postmeta table for this sequence
			update_post_meta( $this->sequence_id, '_pmpro_sequence_settings', $settings );
			
			// Preserve the settings in memory / class context
			DBG::log( 'save_sequence_meta(): Saved Sequence Settings for ' . $this->sequence_id );
		} catch ( \Exception $e ) {
			
			DBG::log( 'save_sequence_meta() - Error saving sequence settings for ' . $this->sequence_id . ' Msg: ' . $e->getMessage() );
			
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
		
		$seconds  = 0;
		$serverTZ = get_option( 'timezone_string' );
		
		$now = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		
		if ( $days > 1 ) {
			$dayStr = "{$days} days";
		} else {
			$dayStr = "{$days} day";
		}
		
		$now->modify( $dayStr );
		
		$now->setTimezone( new \DateTimeZone( $serverTZ ) );
		$seconds = $now->format( 'U' );
		
		DBG::log( "calculateOffsetSecs() - Offset Days: {$days} = When (in seconds): {$seconds}", E20R_DEBUG_SEQ_INFO );
		
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
		
		$post_types   = get_post_types( $cpt_args, $output, $operator );
		$postTypeList = array();
		
		foreach ( $post_types as $post_type ) {
			$postTypeList[] = $post_type;
		}
		
		return array_merge( $defaults, $postTypeList );
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
	 */
	public function has_membership_access_filter( $hasaccess, $post, $user, $levels ) {
		
		//See if the user has access to the specific post
		if ( isset( $post->ID ) && ! $this->is_managed( $post->ID ) ) {
			DBG::log( "Post {$post->ID} is not managed by a sequence (it is one?). Returning original access value: " . ( $hasaccess ? 'true' : 'false' ) );
			
			return $hasaccess;
		}
		
		if ( $hasaccess ) {
			
			if ( isset( $user->ID ) && isset( $post->ID ) && $this->has_post_access( $user->ID, $post->ID ) ) {
				
				$hasaccess = true;
			} else {
				$hasaccess = false;
			}
		}
		
		return apply_filters( 'e20r-sequence-has-access-filter', $hasaccess, $post, $user, $levels );
	}
	
	/**
	 * Check whether a post ($post->ID) is managed by any sequence
	 *
	 * @param $post_id - ID of post to check
	 *
	 * @return bool - True if the post is managed by a sequence
	 */
	public function is_managed( $post_id ) {
		
		DBG::log( "Check whether post ID {$post_id} is managed by a sequence: " . $this->who_called_me() );
		
		$is_sequence = get_post_meta( $post_id, '_pmpro_sequence_post_belongs_to' );
		$retval      = empty( $is_sequence ) ? false : true;
		
		return $retval;
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
		
		if ( $settings->optin_at != - 1 ) {
			
			DBG::log( 'User: ' . $user_id . ' Optin TS: ' . $settings->optin_at .
			          ', Optin Date: ' . date( 'Y-m-d', $settings->optin_at )
			);
			
			$delay_ts = $this->delay_as_timestamp( $post->delay, $user_id );
			DBG::log( "Timestamp for delay value: {$delay_ts}" );
			
			// Compare the Delay to the optin (subtract 24 hours worth of time from the opt-in TS)
			if ( $delay_ts >= ( $settings->last_notice_sent - DAY_IN_SECONDS ) ) {
				
				DBG::log( 'This post SHOULD be allowed to be alerted on' );
				
				return true;
			} else {
				DBG::log( 'This post should NOT be allowed to be alerted on' );
				
				return false;
			}
		} else {
			DBG::log( 'Negative opt-in timestamp value. The user  (' . $user_id . ') does not want to receive alerts' );
			
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
		
		$delayTS = current_time( 'timestamp', true ); // Default is 'now'
		
		$startTS = $this->get_user_startdate( $user_id, $level_id );
		
		switch ( $this->options->delayType ) {
			case 'byDays':
				$delayTS = strtotime( '+' . $delay . ' days', $startTS );
				DBG::log( 'byDays:: delay = ' . $delay . ', delayTS is now: ' . $delayTS . ' = ' . date( 'Y-m-d', $startTS ) . ' vs ' . date( 'Y-m-d', $delayTS ) );
				break;
			
			case 'byDate':
				$delayTS = strtotime( $delay );
				DBG::log( 'byDate:: delay = ' . $delay . ', delayTS is now: ' . $delayTS . ' = ' . date( 'Y-m-d', $startTS ) . ' vs ' . date( 'Y-m-d', $delayTS ) );
				break;
		}
		
		return $delayTS;
	}
	
	/**
	 * Retrieve the Google Analytics Cookie ID
	 *
	 * @return null - Return the Cookie ID for the Google Analytics cookie
	 */
	public function ga_getCid() {
		
		$contents = $this->ga_parseCookie();
		
		return isset( $contents['cid'] ) ? $contents['cid'] : null;
	}
	
	/**
	 * Parse the Google Analytics cookie to locate the Client ID info.
	 *
	 * By: Matt Clarke - https://plus.google.com/110147996971766876369/posts/Mz1ksPoBGHx
	 *
	 * @return array
	 */
	public function ga_parseCookie() {
		
		if ( isset( $_COOKIE["_ga"] ) ) {
			
			list( $version, $domainDepth, $cid1, $cid2 ) = preg_split( '/[\.]/i', $_COOKIE["_ga"], 4 );
			
			return array( 'version' => $version, 'domainDepth' => $domainDepth, 'cid' => $cid1 . '.' . $cid2 );
		}
		
		return array();
	}
	
	/**
	 * Prepare the (soon-to-be) PHPMailer() object to send
	 *
	 * @param \WP_Post $post     - Post Object
	 * @param \WP_User $user     - User Object
	 * @param          $template - Template name (string)
	 *
	 * @return E20R_Mail - Mail object to process
	 */
	private function prepare_mail_obj( $post, $user, $template ) {
		
		DBG::log( "Attempting to load email class..." );
		
		$email = new E20R_Mail();
		
		DBG::log( "Loaded " . get_class( $email ) );
		
		$user_started = ( $this->get_user_startdate( $user->ID ) - DAY_IN_SECONDS ) + ( $this->normalize_delay( $post->delay ) * DAY_IN_SECONDS );
		
		$email->from       = $this->get_option_by_name( 'replyto' );
		$email->template   = $template;
		$email->fromname   = $this->get_option_by_name( 'fromname' );
		$email->to         = $user->user_email;
		$email->subject    = sprintf( '%s: %s (%s)', $this->get_option_by_name( 'subject' ), $post->title, strftime( "%x", $user_started ) );
		$email->dateformat = $this->get_option_by_name( 'dateformat' );
		
		return $email;
		
	}
	
	/**
	 * Send email to userID about access to new post.
	 *
	 * @param $post_ids -- IDs of post(s) to send email about
	 * @param $user_id  -- ID of user to send the email to.
	 * @param $seq_id   -- ID of sequence to process (not used)
	 *
	 * @return bool - True if sent successfully. False otherwise.
	 *
	 * @access public
	 *
	 * TODO: Fix email body to be correct (standards compliant) MIME encoded HTML mail or text mail.
	 */
	public function send_notice( $posts, $user_id, $seq_id ) {
		// Make sure the email class is loaded.
		if ( ! class_exists( 'E20R\Sequences\Tools\E20R_Mail' ) ) {
			DBG::log( 'E20R_Mail class is missing??' );
			
			return false;
		}
		
		if ( ! is_array( $posts ) ) {
			
			$posts = array( $posts );
		}
		
		$user  = get_user_by( 'id', $user_id );
		$templ = preg_split( '/\./', $this->get_option_by_name( 'noticeTemplate' ) ); // Parse the template name
		
		$emails = array();
		
		$post_links  = '';
		$excerpt     = '';
		$ga_tracking = '';
		
		DBG::log( "Preparing to send " . count( $posts ) . " post alerts for user {$user_id} regarding sequence {$seq_id}" );
		DBG::log( $templ );
		
		// Add data/img entry for google analytics.
		$track_with_ga = $this->get_option_by_name( 'trackGoogleAnalytics' );
		
		if ( ! empty( $track_with_ga ) && ( true === $track_with_ga ) ) {
			
			// FIXME: get_google_analytics_client_id() can't work since this isn't being run during a user session!
			$cid      = esc_html( $this->ga_getCid() );
			$tid      = esc_html( $this->options->gaTid );
			$post     = get_post( $this->sequence_id );
			$campaign = esc_html( $post->post_title );
			
			// http://www.google-analytics.com/collect?v=1&tid=UA-12345678-1&cid=CLIENT_ID_NUMBER&t=event&ec=email&ea=open&el=recipient_id&cs=newsletter&cm=email&cn=Campaign_Name
			
			$protocol = ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443 ) ? "https://" : "http://";
			
			if ( ! empty( $cid ) ) {
				
				//https://strongcubedfitness.com/?utm_source=daily_lesson&utm_medium=email&utm_campaign=vpt
				$url = "${protocol}://www.google-analytics.com/collect/v=1&aip=1&ds=lesson&tid={$tid}&cid={$cid}";
				$url = $url . "&t=event&ec=email&ea=open&el=recipient_id&cs=newsletter&cm=email&cn={$campaign}";
				
				$ga_tracking = '<img src="' . $url . '" >';
			}
		}
		
		if ( false === ( $template_content = file_get_contents( $this->email_template_path() ) ) ) {
			
			DBG::log( 'ERROR: Could not read content from template file: ' . $this->get_option_by_name( 'noticeTemplate' ) );
			
			return false;
		}
		
		$user_started = ( $this->get_user_startdate( $user_id ) - DAY_IN_SECONDS );
		
		$send_as = $this->get_option_by_name( 'noticeSendAs' );
		
		if ( empty( $send_as ) ) {
			
			DBG::log( "WARNING: Have to update the noticeSendAs setting!" );
			$this->set_option_by_name( 'noticeSendAs', E20R_SEQ_SEND_AS_SINGLE );
			$this->save_settings( $seq_id );
		}
		
		foreach ( $posts as $post ) {
			
			$as_list = false;
			
			$post_date = date( $this->get_option_by_name( 'dateformat' ), ( $user_started + ( $this->normalize_delay( $post->delay ) * DAY_IN_SECONDS ) ) );
			
			// Send all of the links to new content in a single email message.
			if ( E20R_SEQ_SEND_AS_LIST == $send_as ) {
				
				$idx        = 0;
				$post_links .= '<li><a href="' . wp_login_url( $post->permalink ) . '" title="' . $post->title . '">' . $post->title . '</a></li>\n';
				
				if ( false === $as_list ) {
					
					$as_list              = true;
					$emails[ $idx ]       = $this->prepare_mail_obj( $post, $user, $this->get_option_by_name( 'noticeTemplate' ) );
					$emails[ $idx ]->body = $template_content;
					
					$data = array(
						// Options could be: display_name, first_name, last_name, nickname
						"name"      => apply_filters( 'e20r-sequence-alert-message-name', $user->user_firstname ),
						"sitename"  => apply_filters( 'e20r-sequence-site-name', get_option( "blogname" ) ),
						"today"     => apply_filters( 'e20r-sequence-alert-message-date', $post_date ),
						"excerpt"   => apply_filters( 'e20r-sequence-alert-message-excerpt-intro', $post->excerpt ),
						"post_link" => apply_filters( 'e20r-sequence-alert-message-link-href-element', $post_links ),
						"ptitle"    => apply_filters( 'e20r-sequence-alert-message-title', $post->title ),
					);
					
					if ( isset( $this->options->track_google_analytics ) && ( true == $this->options->track_google_analytics ) ) {
						$data['google_analytics'] = $ga_tracking;
					}
					
					$emails[ $idx ]->data = apply_filters( 'e20r-sequence-email-substitution-fields', $data );
				}
			} else if ( E20R_SEQ_SEND_AS_SINGLE == $send_as ) {
				
				// Send one email message per piece of new content.
				$emails[] = $this->prepare_mail_obj( $post, $user, $templ[0] );
				
				// super defensive programming...
				$idx = ( empty( $emails ) ? 0 : count( $emails ) - 1 );
				
				if ( ! empty( $post->excerpt ) ) {
					
					DBG::log( "Adding the post excerpt to email notice" );
					
					if ( empty( $this->options->excerptIntro ) ) {
						$this->options->excerptIntro = __( 'A summary:', "e20r-sequences" );
					}
					
					$excerpt = '<p>' . $this->options->excerptIntro . '</p><p>' . $post->excerpt . '</p>';
				}
				
				$post_links = '<a href="' . wp_login_url( $post->permalink ) . '" title="' . $post->title . '">' . $post->title . '</a>';
				$post_url   = wp_login_url( $post->permalink );
				
				$emails[ $idx ]->body = $template_content;
				
				if ( isset( $this->options->trackGoogleAnalytics ) && ( true == $this->options->trackGoogleAnalytics ) ) {
					$data['google_analytics'] = $ga_tracking;
				}
				
				$data = array(
					"name"      => apply_filters( 'e20r-sequence-alert-message-name', $user->user_firstname ),
					// Options could be: display_name, first_name, last_name, nickname
					"sitename"  => apply_filters( 'e20r-sequence-site-name', get_option( "blogname" ) ),
					"post_link" => apply_filters( 'e20r-sequence-alert-message-link-href-element', $post_links ),
					'post_url'  => apply_filters( 'e20r-sequence-alert-message-post-permalink', $post_url ),
					"today"     => apply_filters( 'e20r-sequence-alert-message-date', $post_date ),
					"excerpt"   => apply_filters( 'e20r-sequence-alert-message-excerpt-intro', $excerpt ),
					"ptitle"    => apply_filters( 'e20r-sequence-alert-message-title', $post->title ),
				);
				
				$emails[ $idx ]->data = apply_filters( 'e20r-sequence-email-substitution-fields', $data );
			}
			
			
		}
		
		// Append the post_link ul/li element list when asking to send as list.
		if ( E20R_SEQ_SEND_AS_LIST == $this->options->noticeSendAs ) {
			
			DBG::log( 'Set link variable for list of link format' );
			$emails[ ( count( $emails ) - 1 ) ]->post_link = "<ul>\n" . $post_links . "</ul>\n";
		}
		
		$emails[ $idx ]->data = apply_filters( 'e20r-sequence-email-substitution-fields', $data );
		
		DBG::log( "Have prepared " . count( $emails ) . " email notices for user {$user_id}" );
		
		$user = get_user_by( 'id', $user_id );
		
		// Send the configured email messages
		foreach ( $emails as $email ) {
			
			DBG::log( 'Email object: ' . get_class( $email ) );
			if ( false == $email->send() ) {
				
				DBG::log( "ERROR - Failed to send new sequence content email to {$user->user_email}! " );
			}
		}
		
		// wp_reset_postdata();
		// All of the array list names are !!<name>!! escaped values.
		return true;
	}
	
	/**
	 * Check the theme/child-theme/Sequence plugin directory for the specified notice template.
	 *
	 * @return null|string -- Path to the selected template for the email alert notice.
	 */
	private function email_template_path() {
		
		if ( file_exists( get_stylesheet_directory() . "/sequence-email-alerts/" . $this->get_option_by_name( 'noticeTemplate' ) ) ) {
			
			$template_path = get_stylesheet_directory() . "/sequence-email-alerts/" . $this->get_option_by_name( 'noticeTemplate' );
			
		} else if ( file_exists( get_template_directory() . "/sequence-email-alerts/" . $this->get_option_by_name( 'noticeTemplate' ) ) ) {
			
			$template_path = get_template_directory() . "/sequence-email-alerts/" . $this->get_option_by_name( 'noticeTemplate' );
		} else if ( file_exists( E20R_SEQUENCE_PLUGIN_DIR . "email/" . $this->get_option_by_name( 'noticeTemplate' ) ) ) {
			
			$template_path = E20R_SEQUENCE_PLUGIN_DIR . "email/" . $this->get_option_by_name( 'noticeTemplate' );
		} else {
			
			$template_path = null;
		}
		
		DBG::log( "email_template_path() - Using path: {$template_path}" );
		
		return $template_path;
	}
	
	/**
	 * Resets the user-specific alert settings for a specified sequence Id.
	 *
	 * @param $user_id    - User's ID
	 * @param $sequenceId - ID of the sequence we're clearning
	 *
	 * @return mixed - false means the reset didn't work.
	 */
	public function reset_user_alerts( $user_id, $sequenceId ) {
		
		global $wpdb;
		
		DBG::log( "reset_user_alerts() - Attempting to delete old-style user notices for sequence with ID: {$sequenceId}", E20R_DEBUG_SEQ_INFO );
		$old_style = delete_user_meta( $user_id, $wpdb->prefix . 'pmpro_sequence_notices' );
		
		DBG::log( "reset_user_alerts() - Attempting to delete v3 style user notices for sequence with ID: {$sequenceId}", E20R_DEBUG_SEQ_INFO );
		$v3_style = delete_user_meta( $user_id, "pmpro_sequence_id_{$sequenceId}_notices" );
		
		if ( $old_style || $v3_style ) {
			
			DBG::log( "reset_user_alerts() - Successfully delted user notice settings for user {$user_id}" );
			
			return true;
		}
		
		return false;
	}
	
	/**
	 * Changes the content of the following placeholders as described:
	 *
	 * TODO: Simplify and just use a more standardized and simple way of preparing the mail object before wp_mail'ing
	 * it.
	 *
	 *  !!excerpt_intro!! --> The introduction to the excerpt (Configure in "Sequence" editor ("Sequence Settings
	 *  pane")
	 *  !!lesson_title!! --> The title of the lesson/post we're emailing an alert about.
	 *  !!today!! --> Today's date (in the configured format).
	 *
	 * @param $phpmailer -- PMPro Mail object (contains the Body of the message)
	 *
	 * @access private
	 */
	public function email_body( $phpmailer ) {
		
		DBG::log( 'email_body() action: Update body of message if it is sent by PMPro Sequence' );
		
		if ( isset( $phpmailer->excerpt_intro ) ) {
			$phpmailer->Body = apply_filters( 'e20r-sequence-alert-message-excerpt-intro', str_replace( "!!excerpt_intro!!", $phpmailer->excerpt_intro, $phpmailer->Body ) );
		}
		
		if ( isset( $phpmailer->ptitle ) ) {
			$phpmailer->Body = apply_filters( 'e20r-sequence-alert-message-title', str_replace( "!!ptitle!!", $phpmailer->ptitle, $phpmailer->Body ) );
		}
		
	}
	
	/**
	 * For backwards compatibility.
	 *
	 * @param     $msg
	 * @param int $lvl
	 */
	public function dbgOut( $msg, $lvl = E20R_DEBUG_SEQ_INFO ) {
		
		DBG::log( $msg, $lvl );
	}
	
	/**
	 * Callback (hook) for the save_post action.
	 *
	 * If the contributor has added the necessary settings to include the post in a sequence, we'll add it.
	 *
	 * @param $post_id - The ID of the post being saved
	 */
	public function post_save_action( $post_id ) {
		
		global $current_user, $post;
		
		if ( ! isset( $post->post_type ) ) {
			DBG::log( "post_save_action() - No post type defined for {$post_id}", E20R_DEBUG_SEQ_WARNING );
			
			return;
		}
		
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			DBG::log( "Exit during autosave" );
			
			return;
		}
		
		if ( wp_is_post_revision( $post_id ) !== false ) {
			DBG::log( "post_save_action() - Not saving revisions ({$post_id}) to sequence" );
			
			return;
		}
		
		if ( ! in_array( $post->post_type, $this->managed_types ) ) {
			DBG::log( "post_save_action() - Not saving delay info for {$post->post_type}" );
			
			return;
		}
		
		if ( 'trash' == get_post_status( $post_id ) ) {
			return;
		}
		
		DBG::log( "post_save_action() - Sequences & Delays have been configured for page save. " . $this->who_called_me() );
		
		if ( isset( $_POST['e20r_seq-sequences'] ) ) {
			$seq_ids = is_array( $_POST['e20r_seq-sequences'] ) ? array_map( 'esc_attr', $_POST['e20r_seq-sequences'] ) : null;
		} else {
			$seq_ids = array();
		}
		
		if ( isset( $_POST['e20r_seq-delay'] ) ) {
			
			$delays = is_array( $_POST['e20r_seq-delay'] ) ? array_map( 'esc_attr', $_POST['e20r_seq-delay'] ) : array();
		} else {
			$delays = array();
		}
		
		if ( empty( $delays ) && ( ! in_array( 0, $delays ) ) ) {
			
			$this->set_error_msg( __( "Error: No delay value(s) received", "e20r-sequences" ) );
			DBG::log( "post_save_action() - Error: delay not specified! ", E20R_DEBUG_SEQ_CRITICAL );
			
			return;
		}
		
		$errMsg = null;
		
		// $already_in = $this->get_sequences_for_post( $post_id );
		// $already_in = get_post_meta( $post_id, "_post_sequences", true );
		
		DBG::log( "post_save_action() - Saved received variable values..." );
		
		foreach ( $seq_ids as $key => $id ) {
			
			DBG::log( "post_save_action() - Processing for sequence {$id}" );
			
			if ( $id == 0 ) {
				continue;
			}
			
			if ( $id != $this->sequence_id ) {
				
				if ( ! $this->get_options( $id ) ) {
					DBG::log( "post_save_action() - Unable to load settings for sequence with ID: {$id}" );
					
					return;
				}
			}
			
			$user_can = apply_filters( 'e20r-sequence-has-edit-privileges', $this->user_can_edit( $current_user->ID ) );
			
			if ( ! $user_can ) {
				
				$this->set_error_msg( __( 'Incorrect privileges for this operation', "e20r-sequences" ) );
				DBG::log( "post_save_action() - User lacks privileges to edit", E20R_DEBUG_SEQ_WARNING );
				
				return;
			}
			
			if ( $id == 0 ) {
				
				DBG::log( "post_save_action() - No specified sequence or it's set to 'nothing'" );
			} else if ( is_null( $delays[ $key ] ) || ( empty( $delays[ $key ] ) && ! is_numeric( $delays[ $key ] ) ) ) {
				
				DBG::log( "post_save_action() - Not a valid delay value...: " . $delays[ $key ], E20R_DEBUG_SEQ_CRITICAL );
				$this->set_error_msg( sprintf( __( "You must specify a delay value for the '%s' sequence", "e20r-sequences" ), get_the_title( $id ) ) );
			} else {
				
				DBG::log( "post_save_action() - Processing post {$post_id} for sequence {$this->sequence_id} with delay {$delays[$key]}" );
				$this->add_post( $post_id, $delays[ $key ] );
			}
		}
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
		
		DBG::log( "User with ID {$user_id} has permission to update/edit this sequence? " . ( $perm ? 'Yes' : 'No' ) );
		
		return $perm;
	}
	
	/**
	 * Adds the specified post to this sequence
	 *
	 * @param $post_id -- The ID of the post to add to this sequence
	 * @param $delay   -- The delay to apply to the post
	 *
	 * @return bool -- Success or failure
	 *
	 * @access public
	 */
	public function add_post( $post_id, $delay ) {
		DBG::log( "add_post() for sequence {$this->sequence_id}: " . $this->who_called_me() );
		
		/*        if (! $this->is_valid_delay($delay) )
        {
            DBG::log('add_post(): Admin specified an invalid delay value for post: ' . ( empty($post_id) ? 'Unknown' :  $post_id) );
            $this->set_error_msg( sprintf(__('Invalid delay value - %s', "e20r-sequences"), ( empty($delay) ? 'blank' : $delay ) ) );
            return false;
        }
*/
		if ( empty( $post_id ) || ! isset( $delay ) ) {
			$this->set_error_msg( __( "Please enter a value for post and delay", "e20r-sequences" ) );
			DBG::log( 'add_post(): No Post ID or delay specified' );
			
			return false;
		}
		
		DBG::log( 'add_post(): Post ID: ' . $post_id . ' and delay: ' . $delay );
		
		if ( $post = get_post( $post_id ) === null ) {
			
			$this->set_error_msg( __( "A post with that id does not exist", "e20r-sequences" ) );
			DBG::log( 'add_post(): No Post with ' . $post_id . ' found' );
			
			return false;
		}
		
		/*        if ( $this->is_present( $post_id, $delay ) ) {

            DBG::log("add_post() - Post {$post_id} with delay {$delay} is already present in sequence {$this->sequence_id}");
            return true;
        }
*/
		// Refresh the post list for the sequence, ignore cache
		
		if ( current_time( 'timestamp' ) >= $this->expires && ! empty( $this->posts ) ) {
			
			DBG::log( "add_post(): Refreshing post list for sequence #{$this->sequence_id}" );
			$this->load_sequence_post();
		}
		
		// Add this post to the current sequence.
		
		DBG::log( "add_post() - Adding post {$post_id} with delay {$delay} to sequence {$this->sequence_id}" );
		if ( ! $this->add_post_to_sequence( $this->sequence_id, $post_id, $delay ) ) {
			
			DBG::log( "add_post() - ERROR: Unable to add post {$post_id} to sequence {$this->sequence_id} with delay {$delay}", E20R_DEBUG_SEQ_WARNING );
			$this->set_error_msg( sprintf( __( "Error adding %s to %s", "e20r-sequences" ), get_the_title( $post_id ), get_the_title( $this->sequence_id ) ) );
			
			return false;
		}
		
		//sort
		DBG::log( 'add_post(): Sorting the sequence posts by delay value(s)' );
		usort( $this->posts, array( $this, 'sort_posts_by_delay' ) );
		
		// Save the sequence list for this post id
		
		/* $this->set_sequences_for_post( $post_id, $post_in_sequences ); */
		// update_post_meta( $post_id, "_post_sequences", $post_in_sequences );
		
		DBG::log( 'add_post(): Post/Page list updated and saved' );
		
		return true;
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
		DBG::log( "is_valid_delay(): Delay value is: {$delay} for setting: {$this->options->delayType}" );
		
		switch ( $this->options->delayType ) {
			case 'byDays':
				DBG::log( 'is_valid_delay(): Delay configured as "days since membership start"' );
				
				return ( is_numeric( $delay ) ? true : false );
				break;
			
			case 'byDate':
				DBG::log( 'is_valid_delay(): Delay configured as a date value' );
				
				return ( apply_filters( 'e20r-sequence-check-valid-date', $this->is_valid_date( $delay ) ) ? true : false );
				break;
			
			default:
				DBG::log( 'is_valid_delay(): NOT a valid delay value, based on config' );
				DBG::log( "is_valid_delay() - options Array: " . print_r( $this->options, true ) );
				
				return false;
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
		
		// Check that the function was called correctly. If not, just return
		if ( empty( $post_id ) ) {
			
			DBG::log( 'save_post_meta(): No post ID supplied...', E20R_DEBUG_SEQ_WARNING );
			
			return false;
		}
		
		if ( wp_is_post_revision( $post_id ) ) {
			return $post_id;
		}
		
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $post_id;
		}
		
		if ( ! isset( $post->post_type ) || ( 'pmpro_sequence' != $post->post_type ) ) {
			return $post_id;
		}
		
		if ( 'trash' == get_post_status( $post_id ) ) {
			return $post_id;
		}
		
		if ( ! $this->init( $post_id ) ) {
			return;
		}
		
		DBG::log( 'save_post_meta(): Saving settings for sequence ' . $post_id );
		// DBG::log('From Web: ' . print_r($_REQUEST, true));
		
		// OK, we're authenticated: we need to find and save the data
		if ( isset( $_POST['e20r_sequence_settings_noncename'] ) ) {
			
			DBG::log( 'save_post_meta() - Have to load new instance of Sequence class' );
			
			if ( ! $this->options ) {
				$this->options = $this->default_options();
			}
			
			if ( ( $retval = $this->save_settings( $post_id ) ) === true ) {
				
				DBG::log( "save_post_meta(): Saved metadata for sequence #{$post_id} and clearing the cache" );
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
		
		DBG::log( 'Saving settings for Sequence w/ID: ' . $sequence_id );
		// DBG::log($_POST);
		
		// Check that the function was called correctly. If not, just return
		if ( empty( $sequence_id ) ) {
			DBG::log( 'save_settings(): No sequence ID supplied...' );
			$this->set_error_msg( __( 'No sequence provided', "e20r-sequences" ) );
			
			return false;
		}
		
		// Is this an auto save routine? If our form has not been submitted (clicked "save"), we'd probably not want to save anything yet
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			$this->set_error_msg( null );
			
			return $sequence_id;
		}
		
		// Verify that we're allowed to update the sequence data
		if ( ! $this->user_can_edit( $current_user->ID ) ) {
			DBG::log( 'save_settings(): User is not allowed to edit this post type', E20R_DEBUG_SEQ_CRITICAL );
			$this->set_error_msg( __( 'User is not allowed to change settings', "e20r-sequences" ) );
			
			return false;
		}
		
		if ( isset( $_POST['hidden-e20r-sequence_wipesequence'] ) && ( 1 == intval( $_POST['hidden-e20r-sequence_wipesequence'] ) ) ) {
			
			DBG::log( "Admin requested change of delay type configuration. Resetting the sequence!", E20R_DEBUG_SEQ_WARNING );
			
			if ( $sequence_id == $this->sequence_id ) {
				
				if ( ! $this->delete_post_meta_for_sequence( $sequence_id ) ) {
					
					DBG::log( 'Unable to delete the posts in sequence # ' . $sequence_id, E20R_DEBUG_SEQ_CRITICAL );
					$this->set_error_msg( __( 'Unable to wipe existing posts', "e20r-sequences" ) );
				} else {
					DBG::log( 'Reloading sequence info' );
					$this->init( $sequence_id );
				}
			} else {
				DBG::log( "the specified sequence id and the current sequence id were different!", E20R_DEBUG_SEQ_WARNING );
			}
		}
		
		$new_options = $this->get_options();
		
		if ( false === $new_options->loaded ) {
			
			DBG::log( "No settings loaded/set. Using default settings." );
		}
		
		$form_checkboxes = array(
			'hideFuture',
			'showAdmin',
			'includeFeatured',
			'allowRepeatPosts',
			'previewOffset',
			'lengthVisible',
			'sendNotice',
		);
		
		foreach ( $this->options as $field => $value ) {
			if ( $field == 'loaded' ) {
				continue;
			}
			
			$tmp = isset( $_POST["e20r-sequence_{$field}"] ) ? $this->sanitize( $_POST["e20r-sequence_{$field}"] ) : null;
			
			DBG::log( "Being saved: {$field} => {$tmp}" );
			
			if ( empty( $tmp ) ) {
				$tmp = $this->options->{$field};
			}
			
			$this->options->{$field} = $tmp;
			
			if ( 'noticeTime' == $field ) {
				$this->options->noticeTimestamp = $this->calculate_timestamp( $this->options->{$field} );
			}
			
			if ( in_array( $field, $form_checkboxes ) ) {
				$this->options->{$field} = ( isset( $_POST["e20r-sequence_{$field}"] ) ? intval( $_POST["e20r-sequence_{$field}"] ) : 0 );
			}
		}
		
		DBG::log( "Trying to save... : " . print_r( $this->options, true ) );
		
		if ( $this->options->sendNotice == 0 ) {
			
			Cron::stop_sending_user_notices( $this->sequence_id );
		}
		
		/*
        if ( isset($_POST['hidden-e20r-sequence_allowRepeatPosts']) )
        {
            $this->options->allowRepeatPosts = intval( $_POST['hidden_e20r_seq_allowRepeatPosts'] ) == 0 ? false : true;
            DBG::log('save_settings(): POST value for settings->allowRepeatPost: ' . intval($_POST['hidden_e20r_seq_allowRepeatPosts']) );
        }
        elseif (empty($this->options->allowRepeatPosts))
            $this->options->allowRepeatPosts = false;

        if ( isset($_POST['hidden_e20r_seq_future']) )
        {
            $this->options->hideFuture = intval( $_POST['hidden_e20r_seq_future'] ) == 0 ? false : true;
            DBG::log('save_settings(): POST value for settings->hideFuture: ' . intval($_POST['hidden_e20r_seq_future']) );
        }
        elseif (empty($this->options->hideFuture))
            $this->options->hideFuture = false;

       // Checkbox - not included during post/save if unchecked
        if ( isset($_POST['hidden_e20r_seq_future']) )
        {
            $this->options->hideFuture = intval($_POST['e20r_seq_future']);
            DBG::log('save_settings(): POST value for settings->hideFuture: ' . $_POST['hidden_e20r_seq_future'] );
        }
        elseif ( empty($this->options->hideFuture) )
            $this->options->hideFuture = 0;

        // Checkbox - not included during post/save if unchecked
        if (isset($_POST['hidden_e20r_seq_lengthvisible']) )
        {
            $this->options->lengthVisible = intval($_POST['hidden_e20r_seq_lengthvisible']);
            DBG::log('save_settings(): POST value for settings->lengthVisible: ' . $_POST['hidden_e20r_seq_lengthvisible']);
        }
        elseif (empty($this->options->lengthVisible)) {
            DBG::log('Setting lengthVisible to default value (checked)');
            $this->options->lengthVisible = 1;
        }

        if ( isset($_POST['hidden_e20r_seq_sortorder']) )
        {
            $this->options->sortOrder = intval($_POST['hidden_e20r_seq_sortorder']);
            DBG::log('save_settings(): POST value for settings->sortOrder: ' . $_POST['hidden_e20r_seq_sortorder'] );
        }
        elseif (empty($this->options->sortOrder))
            $this->options->sortOrder = SORT_ASC;

        if ( isset($_POST['hidden_e20r_seq_delaytype']) )
        {
            $this->options->delayType = sanitize_text_field($_POST['hidden_e20r_seq_delaytype']);
            DBG::log('save_settings(): POST value for settings->delayType: ' . sanitize_text_field($_POST['hidden_e20r_seq_delaytype']) );
        }
        elseif (empty($this->options->delayType))
            $this->options->delayType = 'byDays';

        // options->showDelayAs
        if ( isset($_POST['hidden_e20r_seq_showdelayas']) )
        {
            $this->options->showDelayAs = sanitize_text_field($_POST['hidden_e20r_seq_showdelayas']);
            DBG::log('save_settings(): POST value for settings->showDelayAs: ' . sanitize_text_field($_POST['hidden_e20r_seq_showdelayas']) );
        }
        elseif (empty($this->options->showDelayAs))
            $this->options->delayType = E20R_SEQ_AS_DAYNO;

        if ( isset($_POST['hidden_e20r_seq_offset']) )
        {
            $this->options->previewOffset = sanitize_text_field($_POST['hidden_e20r_seq_offset']);
            DBG::log('save_settings(): POST value for settings->previewOffset: ' . sanitize_text_field($_POST['hidden_e20r_seq_offset']) );
        }
        elseif (empty($this->options->previewOffset))
            $this->options->previewOffset = 0;

        if ( isset($_POST['hidden_e20r_seq_startwhen']) )
        {
            $this->options->startWhen = sanitize_text_field($_POST['hidden_e20r_seq_startwhen']);
            DBG::log('save_settings(): POST value for settings->startWhen: ' . sanitize_text_field($_POST['hidden_e20r_seq_startwhen']) );
        }
        elseif (empty($this->options->startWhen))
            $this->options->startWhen = 0;

        // Checkbox - not included during post/save if unchecked
        if ( isset($_POST['e20r_sequence_sendnotice']) )
        {
            $this->options->sendNotice = intval($_POST['e20r_sequence_sendnotice']);

            if ( $this->options->sendNotice == 0 ) {

                Cron::stop_sending_user_notices( $this->sequence_id );
            }

            DBG::log('save_settings(): POST value for settings->sendNotice: ' . intval($_POST['e20r_sequence_sendnotice']) );
        }
        elseif (empty($this->options->sendNotice)) {
            $this->options->sendNotice = 1;
        }

        if ( isset($_POST['hidden_e20r_seq_sendas']) )
        {
            $this->options->noticeSendAs = sanitize_text_field($_POST['hidden_e20r_seq_sendas']);
            DBG::log('save_settings(): POST value for settings->noticeSendAs: ' . sanitize_text_field($_POST['hidden_e20r_seq_sendas']) );
        }
        else
            $this->options->noticeSendAs = E20R_SEQ_SEND_AS_SINGLE;

        if ( isset($_POST['hidden_e20r_seq_noticetemplate']) )
        {
            $this->options->noticeTemplate = sanitize_text_field($_POST['hidden_e20r_seq_noticetemplate']);
            DBG::log('save_settings(): POST value for settings->noticeTemplate: ' . sanitize_text_field($_POST['hidden_e20r_seq_noticetemplate']) );
        }
        else
            $this->options->noticeTemplate = 'new_content.html';

        if ( isset($_POST['hidden_e20r_seq_noticetime']) )
        {
            $this->options->noticeTime = sanitize_text_field($_POST['hidden_e20r_seq_noticetime']);
            DBG::log('noticeTime in settings: ' . $this->options->noticeTime);

            // Calculate the timestamp value for the noticeTime specified (noticeTime is in current timezone)
            $this->options->noticeTimestamp = $this->calculate_timestamp($settings->noticeTime);

            DBG::log('save_settings(): POST value for settings->noticeTime: ' . sanitize_text_field($_POST['hidden_e20r_seq_noticetime']) );
            DBG::log('save_settings(): Which translates to a timestamp value of: ' . $this->options->noticeTimestamp );
        }
        else
            $this->options->noticeTime = '00:00';

        if ( isset($_POST['hidden_e20r_seq_excerpt']) )
        {
            $this->options->excerpt_intro = sanitize_text_field($_POST['hidden_e20r_seq_excerpt']);
            DBG::log('save_settings(): POST value for settings->excerpt_intro: ' . sanitize_text_field($_POST['hidden_e20r_seq_excerpt']) );
        }
        else
            $this->options->excerpt_intro = 'A summary of the post follows below:';

        if ( isset($_POST['hidden_e20r_seq_fromname']) )
        {
            $this->options->fromname = sanitize_text_field($_POST['hidden_e20r_seq_fromname']);
            DBG::log('save_settings(): POST value for settings->fromname: ' . sanitize_text_field($_POST['hidden_e20r_seq_fromname']) );
        }
        else
            $this->options->fromname = $this->get_membership_setting( 'from_name' );

        if ( isset($_POST['hidden_e20r_seq_dateformat']) )
        {
            $this->options->dateformat = sanitize_text_field($_POST['hidden_e20r_seq_dateformat']);
            DBG::log('save_settings(): POST value for settings->dateformat: ' . sanitize_text_field($_POST['hidden_e20r_seq_dateformat']) );
        }
        else
            $this->options->dateformat = __('m-d-Y', "e20r-sequences"); // Default is MM-DD-YYYY (if translation supports it)

        if ( isset($_POST['hidden_e20r_seq_replyto']) )
        {
            $this->options->replyto = sanitize_text_field($_POST['hidden_e20r_seq_replyto']);
            DBG::log('save_settings(): POST value for settings->replyto: ' . sanitize_text_field($_POST['hidden_e20r_seq_replyto']) );
        }
        else
            $this->options->replyto = $this->get_membership_setting('from_email');

        if ( isset($_POST['hidden_e20r_seq_subject']) )
        {
            $this->options->subject = sanitize_text_field($_POST['hidden_e20r_seq_subject']);
            DBG::log('save_settings(): POST value for settings->subject: ' . sanitize_text_field($_POST['hidden_e20r_seq_subject']) );
        }
        else
            $this->options->subject = __('New Content ', "e20r-sequences");

*/
		// Schedule cron jobs if the user wants us to send notices.
		if ( $this->options->sendNotice == 1 ) {
			
			DBG::log( 'Updating the cron job for sequence ' . $this->sequence_id );
			
			if ( ! Cron::update_user_notice_cron() ) {
				DBG::log( 'Error configuring cron() system for sequence ' . $this->sequence_id, E20R_DEBUG_SEQ_CRITICAL );
			}
		}
		
		DBG::log( "Flush the cache for {$this->sequence_id}" );
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
			
			if ( isset( $post->ID ) && ! empty( $post->ID ) && 'pmpro_sequence' == $post->post_type ) {
				$sequence_id = $post->ID;
			}
		}
		
		$retval = false;
		
		if ( delete_post_meta_by_key( "_pmpro_sequence_{$sequence_id}_post_delay" ) ) {
			$retval = true;
		}
		
		foreach ( $this->posts as $post ) {
			
			if ( delete_post_meta( $post->id, "_pmpro_sequence_post_belongs_to", $sequence_id ) ) {
				$retval = true;
			}
			
			if ( $retval != true ) {
				
				DBG::log( "ERROR deleting sequence metadata for post {$post->id}: ", E20R_DEBUG_SEQ_CRITICAL );
			}
		}
		
		
		return $retval;
	}
	
	/**
	 * Return the single (specified) option/setting from the membership plugin
	 *
	 * @param string $option_name -- The name of the option to fetch
	 *
	 * @return mixed|void
	 */
	public function get_membership_setting( $option_name ) {
		$val = null;
		
		if ( function_exists( 'pmpro_getOption' ) ) {
			
			$val = pmpro_getOption( $option_name );
		}
		
		return apply_filters( 'e20r-sequence-membership-module-get-membership-setting', $val, $option_name );
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
	 */
	private function calculate_timestamp( $timeString ) {
		
		if ( empty( $timeString ) ) {
			return null;
		}
		
		// Use local time (not UTC) for 'current time' at server location
		// This is what Wordpress apparently uses (at least in v3.9) for wp-cron.
		$timezone = get_option( 'timezone_string' );
		
		$saved_tz = ini_get( 'date.timezone' );
		DBG::log( "PHP Configured timezone: {$saved_tz} vs wordpress: {$timezone}" );
		
		// Verify the timezone to use (the Wordpress timezone)
		if ( $timezone != $saved_tz ) {
			
			if ( ! ini_set( "date.timezone", $timezone ) ) {
				DBG::log( "WARNING: Unable to set the timezone value to: {$timezone}!" );
			}
			
			$saved_tz = ini_get( 'date.timezone' );
		}
		
		$tz = get_option( 'gmt_offset' );
		
		// Now in the Wordpress local timezone
		$now  = current_time( 'timestamp', true );
		$time = "today {$timeString} {$saved_tz}";
		
		DBG::log( "Using time string for strtotime(): {$time}" );
		$req = strtotime( $time );
		
		DBG::log( "Current time: {$now} when using UTC vs {$req} in {$saved_tz}" );
		
		try {
			
			/* current time & date */
			$schedHour = date_i18n( 'H', $req );
			$nowHour   = date_i18n( 'H', $now );
			
			DBG::log( "calculate_timestamp() - Timestring: {$timeString}, scheduled Hour: {$schedHour} and current Hour: {$nowHour}" );
			
			$timestamp = strtotime( "today {$timeString} {$saved_tz}" );
			
			DBG::log( "calculate_timestamp() - {$timeString} will be ({$timestamp}) vs. " . current_time( 'timestamp', true ) );
			
			if ( $timestamp < ( current_time( 'timestamp', true ) + 15 * 60 ) ) {
				$timestamp = strtotime( "+1 day", $timestamp );
			}
			
			
			/*
            $hourDiff = $schedHour - $nowHour;
            $hourDiff += ( ( ($hourDiff == 0) && (($schedMin - $nowMin) <= 0 )) ? 0 : 1);

            if ( $hourDiff >= 1 ) {
                DBG::log('calculate_timestamp() - Assuming current day');
                $when = ''; // Today
            }
            else {
                DBG::log('calculate_timestamp() - Assuming tomorrow');
                $when = 'tomorrow ';
            }

            // Create the string we'll use to generate a timestamp for cron()
            $timeInput = $when . $timeString . ' ' . get_option('timezone_string');
            $timestamp = strtotime($timeInput);
*/
		} catch ( Exception $e ) {
			DBG::log( 'calculate_timestamp() -- Error calculating timestamp: : ' . $e->getMessage() );
		}
		
		return $timestamp;
	}
	
	/**
	 * Callback to remove the all recorded post notifications for the specific post in the specified sequence
	 */
	public function remove_post_alert_callback() {
		
		DBG::log( "remove_post_alert_callback() - Attempting to remove the alerts for a post" );
		check_ajax_referer( 'e20r-sequence-post-meta', 'e20r_sequence_postmeta_nonce' );
		
		// Fetch the ID of the sequence to add the post to
		$sequence_id = isset( $_POST['e20r_sequence_id'] ) && ! empty( $_POST['e20r_sequence_id'] ) ? intval( $_POST['e20r_sequence_id'] ) : null;
		$post_id     = isset( $_POST['e20r_sequence_post'] ) && ! empty( $_POST['e20r_sequence_post'] ) ? intval( $_POST['e20r_sequence_post'] ) : null;
		
		// TODO: Would using the $this->sanitize() function work for this?
		if ( isset( $_POST['e20r_sequence_post_delay'] ) && ! empty( $_POST['e20r_sequence_post_delay'] ) ) {
			
			$date = preg_replace( "([^0-9/])", "", $_POST['e20r_sequence_post_delay'] );
			
			if ( ( $date == $_POST['e20r_sequence_post_delay'] ) || ( is_null( $date ) ) ) {
				
				$delay = intval( $_POST['e20r_sequence_post_delay'] );
				
			} else {
				
				$delay = sanitize_text_field( $_POST['e20r_sequence_post_delay'] );
			}
		}
		
		DBG::log( "remove_post_alert_callback() - We received sequence ID: {$sequence_id} and post ID: {$post_id}" );
		
		if ( ! is_null( $sequence_id ) && ! is_null( $post_id ) && is_admin() ) {
			
			DBG::log( "remove_post_alert_callback() - Loading settings for sequence {$sequence_id} " );
			$this->get_options( $sequence_id );
			
			DBG::log( "remove_post_alert_callback() - Requesting removal of alert notices for post {$post_id} with delay {$delay} in sequence {$sequence_id} " );
			$result = $this->remove_post_notified_flag( $post_id, $delay );
			
			if ( is_array( $result ) ) {
				
				$list = join( ', ', $result );
				wp_send_json_error( $list );
				
			} else {
				
				wp_send_json_success();
			}
		}
		
		wp_send_json_error( 'Missing data in AJAX call' );
	}
	
	
	public function clearBuffers() {
		
		ob_start();
		
		$buffers = ob_get_clean();
		
		return $buffers;
		
	}
	
	/**
	 * Callback for saving the sequence alert optin/optout for the current user
	 */
	public function optin_callback() {
		global $current_user, $wpdb;
		
		$buffers = $this->clearBuffers();
		
		DBG::log( "Buffer content: " . print_r( $buffers ) );
		/** @noinspection PhpUnusedLocalVariableInspection */
		$result = '';
		
		try {
			
			check_ajax_referer( 'e20r-sequence-user-optin', 'e20r_sequence_optin_nonce' );
			
			if ( isset( $_POST['hidden_e20r_seq_id'] ) ) {
				
				$seqId = intval( $_POST['hidden_e20r_seq_id'] );
			} else {
				
				DBG::log( 'No sequence number specified. Ignoring settings for user', E20R_DEBUG_SEQ_WARNING );
				
				wp_send_json_error( __( 'Unable to save your settings', "e20r-sequences" ) );
			}
			
			if ( isset( $_POST['hidden_e20r_seq_uid'] ) ) {
				
				$user_id = intval( $_POST['hidden_e20r_seq_uid'] );
				DBG::log( 'Updating user settings for user #: ' . $user_id );
				
				// Grab the metadata from the database
				// $usrSettings = get_user_meta($user_id, $wpdb->prefix . 'pmpro_sequence' . '_notices', true);
				$usrSettings = $this->load_user_notice_settings( $user_id, $seqId );
				
			} else {
				DBG::log( 'No user ID specified. Ignoring settings!', E20R_DEBUG_SEQ_WARNING );
				
				wp_send_json_error( __( 'Unable to save your settings', "e20r-sequences" ) );
			}
			
			if ( ! $this->init( $seqId ) ) {
				
				wp_send_json_error( $this->get_error_msg() );
			}
			
			DBG::log( 'Updating user settings for sequence #: ' . $this->sequence_id );
			
			if ( isset( $usrSettings->id ) && ( $usrSettings->id !== $this->sequence_id ) ) {
				
				DBG::log( 'No user specific settings found for this sequence. Creating defaults' );
				
				/*
                // Create new opt-in settings for this user
                if ( empty($usrSettings->sequence) )
                    $new = new \stdClass();
                else // Saves existing settings
                    $new = $usrSettings;
*/
				DBG::log( 'Using default setting for user ' . $current_user->ID . ' and sequence ' . $this->sequence_id );
				
				$usrSettings = $this->create_user_notice_defaults();
			}
			
			// $usrSettings->sequence[$seqId]->sendNotice = ( isset( $_POST['hidden_e20r_seq_useroptin'] ) ?
			$usrSettings->send_notices = ( isset( $_POST['hidden_e20r_seq_useroptin'] ) ?
				intval( $_POST['hidden_e20r_seq_useroptin'] ) : $this->options->sendNotice );
			
			// If the user opted in to receiving alerts, set the opt-in timestamp to the current time.
			// If they opted out, set the opt-in timestamp to -1
			
			if ( $usrSettings->send_notices == 1 ) {
				
				// Fix the alert settings so the user doesn't receive old alerts.
				
				$member_days = $this->get_membership_days( $user_id );
				$post_list   = $this->load_sequence_post( null, $member_days, null, '<=', null, true );
				
				$usrSettings = $this->fix_user_alert_settings( $usrSettings, $post_list, $member_days );
				
				// Set the timestamp when the user opted in.
				$usrSettings->last_notice_sent = current_time( 'timestamp' );
				$usrSettings->optin_at         = current_time( 'timestamp' );
				
			} else {
				$usrSettings->last_notice_sent = - 1; // Opted out.
				$usrSettings->optin_at         = - 1;
			}
			
			
			// Add an empty array to store posts that the user has already been notified about
			/*                if ( empty( $usrSettings->posts ) ) {
                $usrSettings->posts = array();
            }
*/
			/* Save the user options we just defined */
			if ( $user_id == $current_user->ID ) {
				
				DBG::log( 'Opt-In Timestamp is: ' . $usrSettings->last_notice_sent );
				DBG::log( 'Saving user_meta for UID ' . $user_id . ' Settings: ' . print_r( $usrSettings, true ) );
				
				$this->save_user_notice_settings( $user_id, $usrSettings, $seqId );
				// update_user_meta( $user_id, $wpdb->prefix . 'pmpro_sequence' . '_notices', $usrSettings );
				$status = true;
				$this->set_error_msg( null );
			} else {
				
				DBG::log( 'Error: Mismatched User IDs -- user_id: ' . $user_id . ' current_user: ' . $current_user->ID, E20R_DEBUG_SEQ_CRITICAL );
				$this->set_error_msg( __( 'Unable to save your settings', "e20r-sequences" ) );
				$status = false;
			}
		} catch ( \Exception $e ) {
			$this->set_error_msg( sprintf( __( 'Error: %s', "e20r-sequences" ), $e->getMessage() ) );
			$status = false;
			DBG::log( 'optin_save() - Exception error: ' . $e->getMessage(), E20R_DEBUG_SEQ_CRITICAL );
		}
		
		if ( $status ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( $this->get_error_msg() );
		}
		
	}
	
	/**
	 * Test whether it's necessary to convert the post notification flags in the DB for the specified v3 based sequence?
	 * In the v3 format, the notifications changed from simple "delay value" to a concatenated post_id + delay value.
	 * This was to support having multiple repeating post IDs in the sequence and still notify the users on the
	 * correct delay value for that instance.
	 *
	 * @param Sequence $v3        - The Sequence to test
	 * @param          $post_list - The list of posts belonging to the sequence
	 * @param          $member_days
	 *
	 * @return mixed
	 */
	private function fix_user_alert_settings( $v3, $post_list, $member_days ) {
		
		DBG::log( "fix_user_alert_settings() - Checking whether to convert the post notification flags for {$v3->id}" );
		
		$need_to_fix = false;
		
		foreach ( $v3->posts as $id ) {
			
			if ( false === strpos( $id, '_' ) ) {
				
				DBG::log( "fix_user_alert_settings() - Should to fix Post/Delay id {$id}" );
				$need_to_fix = true;
			}
		}
		
		if ( count( $v3->posts ) < count( $post_list ) ) {
			
			DBG::log( "fix_user_alert_settings() - Not enough alert IDs (" . count( $v3->posts ) . ") as compared to the posts in the sequence (" . count( $post_list ) . ")" );
			$need_to_fix = true;
		}
		
		if ( true === $need_to_fix ) {
			
			DBG::log( "fix_user_alert_settings() - The number of posts with a delay value less than {$member_days} is: " . count( $post_list ) );
			
			if ( ! empty( $v3->posts ) ) {
				
				foreach ( $post_list as $p ) {
					
					$flag_value = "{$p->id}_" . $this->normalize_delay( $p->delay );
					
					foreach ( $v3->posts as $k => $id ) {
						
						// Do we have a post ID as the identifier (and not a postID_delay flag)
						if ( $p->id == $id ) {
							
							DBG::log( "fix_user_alert_settings() - Replacing: {$p->id} -> {$flag_value}" );
							$v3->posts[ $k ] = $flag_value;
						} else if ( ! in_array( $flag_value, $v3->posts ) ) {
							
							DBG::log( "fix_user_alert_settings() - Should be in array, but isn't. Adding as 'already alerted': {$flag_value}" );
							$v3->posts[] = $flag_value;
						}
					}
				}
			} else if ( empty( $v3->posts ) && ! empty( $post_list ) ) {
				
				foreach ( $post_list as $p ) {
					
					$flag_value = "{$p->id}_" . $this->normalize_delay( $p->delay );
					
					DBG::log( "fix_user_alert_settings() - Should be in array, but isn't. Adding as 'already alerted': {$flag_value}" );
					$v3->posts[] = $flag_value;
				}
			}
			
			$v3->last_notice_sent = current_time( 'timestamp' );
		}
		
		return $v3;
	}
	
	/**
	 * Callback to catch request from admin to send any new Sequence alerts to the users.
	 *
	 * Triggers the cron hook to achieve it.
	 */
	public function sendalert_callback() {
		
		DBG::log( 'Processing the request to send alerts manually' );
		
		check_ajax_referer( 'e20r-sequence-sendalert', 'e20r_sequence_sendalert_nonce' );
		
		DBG::log( 'Nonce is OK' );
		
		if ( isset( $_POST['e20r_sequence_id'] ) ) {
			
			$sequence_id = intval( $_POST['e20r_sequence_id'] );
			DBG::log( 'sendalert() - Will send alerts for sequence #' . $sequence_id );
			
			do_action( 'e20r_sequence_cron_hook', array( $sequence_id ) );
			
			DBG::log( 'Completed action for sequence' );
		}
	}
	
	/**
	 * Catches ajax POSTs from dashboard/edit for CPT (clear existing sequence members (posts) )
	 */
	public function sequence_clear_callback() {
		
		// Validate that the ajax referrer is secure
		check_ajax_referer( 'e20r-sequence-save-settings', 'e20r_sequence_settings_nonce' );
		
		/** @noinspection PhpUnusedLocalVariableInspection */
		$result = '';
		$view   = apply_filters( 'get_sequence_views_class_instance', null );
		
		// Clear the sequence metadata if the sequence type (by date or by day count) changed.
		if ( isset( $_POST['e20r_sequence_clear'] ) ) {
			if ( isset( $_POST['e20r_sequence_id'] ) ) {
				$sequence_id = intval( $_POST['e20r_sequence_id'] );
				
				if ( ! $this->init( $sequence_id ) ) {
					wp_send_json_error( $this->get_error_msg() );
				}
				
				DBG::log( 'sequence_clear_callback() - Deleting all entries in sequence # ' . $sequence_id );
				
				if ( ! $this->delete_post_meta_for_sequence( $sequence_id ) ) {
					DBG::log( 'Unable to delete the posts in sequence # ' . $sequence_id, E20R_DEBUG_SEQ_CRITICAL );
					$this->set_error_msg( __( 'Could not delete posts from this sequence', "e20r-sequences" ) );
					
				} else {
					$result = $view->get_post_list_for_metabox();
				}
				
			} else {
				$this->set_error_msg( __( 'Unable to identify the Sequence', "e20r-sequences" ) );
			}
		} else {
			$this->set_error_msg( __( 'Unknown request', "e20r-sequences" ) );
		}
		
		// Return the status to the calling web page
		if ( $result['success'] ) {
			wp_send_json_success( $result['html'] );
		} else {
			wp_send_json_error( $this->get_error_msg() );
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
		
		DBG::log( "rm_post_callback() - Attempting to remove post from sequence." );
		
		global $current_user;
		
		check_ajax_referer( 'e20r-sequence-post', 'e20r_sequence_post_nonce' );
		
		/** @noinspection PhpUnusedLocalVariableInspection */
		$result = '';
		
		/** @noinspection PhpUnusedLocalVariableInspection */
		$success = false;
		
		$sequence_id = ( isset( $_POST['e20r_sequence_id'] ) && '' != $_POST['e20r_sequence_id'] ? intval( $_POST['e20r_sequence_id'] ) : null );
		$seq_post_id = ( isset( $_POST['e20r_seq_post'] ) && '' != $_POST['e20r_seq_post'] ? intval( $_POST['e20r_seq_post'] ) : null );
		$delay       = ( isset( $_POST['e20r_seq_delay'] ) && '' != $_POST['e20r_seq_delay'] ? intval( $_POST['e20r_seq_delay'] ) : null );
		
		$view = apply_filters( 'get_sequence_views_class_instance', null );
		
		if ( ! $this->init( $sequence_id ) ) {
			
			wp_send_json_error( $this->get_error_msg() );
		}
		
		// Remove the post (if the user is allowed to)
		if ( $this->user_can_edit( $current_user->ID ) && ! is_null( $seq_post_id ) ) {
			
			$this->remove_post( $seq_post_id, $delay );
			$this->set_error_msg( sprintf( __( "'%s' has been removed", "e20r-sequences" ), get_the_title( $seq_post_id ) ) );
			//$result = __('The post has been removed', "e20r-sequences");
			$success = true;
			
		} else {
			
			$success = false;
			$this->set_error_msg( __( 'Incorrect privileges: Did not update this sequence', "e20r-sequences" ) );
		}
		
		// Return the content for the new listbox (sans the deleted item)
		$result = $view->get_post_list_for_metabox( true );
		
		/*
        if ( is_null( $result['message'] ) && is_null( $this->get_error_msg() ) && ($success)) {
            DBG::log('rm_post_callback() - Returning success to calling javascript');
            wp_send_json_success( $result['html'] );
        }
        else
            wp_send_json_success( $result );
*/
		wp_send_json_success( $result );
	}
	
	/**
	 * Removes the sequence from managing this $post_id.
	 * Returns the table of sequences the post_id belongs to back to the post/page editor using JSON.
	 */
	public function rm_sequence_from_post_callback() {
		
		/** @noinspection PhpUnusedLocalVariableInspection */
		$success = false;
		$view    = apply_filters( 'get_sequence_views_class_instance', null );
		
		// DBG::log("In rm_sequence_from_post()");
		check_ajax_referer( 'e20r-sequence-post-meta', 'e20r_sequence_postmeta_nonce' );
		
		DBG::log( "NONCE is OK for e20r_sequence_rm" );
		
		$sequence_id = ( isset( $_POST['e20r_sequence_id'] ) && ( intval( $_POST['e20r_sequence_id'] ) != 0 ) ) ? intval( $_POST['e20r_sequence_id'] ) : null;
		$post_id     = isset( $_POST['e20r_seq_post_id'] ) ? intval( $_POST['e20r_seq_post_id'] ) : null;
		$delay       = isset( $_POST['e20r_seq_delay'] ) ? intval( $_POST['e20r_seq_delay'] ) : null;
		
		if ( ! $this->init( $sequence_id ) ) {
			wp_send_json_error( $this->get_error_msg() );
		}
		
		$this->set_error_msg( null ); // Clear any pending error messages (don't care at this point).
		
		// Remove the post (if the user is allowed to)
		if ( current_user_can( 'edit_posts' ) && ( ! is_null( $post_id ) ) && ( ! is_null( $sequence_id ) ) ) {
			
			DBG::log( "Removing post # {$post_id} with delay {$delay} from sequence {$sequence_id}" );
			$this->remove_post( $post_id, $delay, true );
			//$result = __('The post has been removed', "e20r-sequences");
			$success = true;
		} else {
			
			$success = false;
			$this->set_error_msg( __( 'Incorrect privileges to remove posts from this sequence', "e20r-sequences" ) );
		}
		
		$result = $view->load_sequence_list_meta( $post_id, $sequence_id );
		
		if ( ! empty( $result ) && is_null( $this->get_error_msg() ) && ( $success ) ) {
			
			DBG::log( 'Returning success to caller' );
			wp_send_json_success( $result );
		} else {
			
			wp_send_json_error( ( ! is_null( $this->get_error_msg() ) ? $this->get_error_msg() : 'Error clearing the sequence from this post' ) );
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
	 * @return bool|int -- The delay value for this post (numerical - even when delayType is byDate)
	 *
	 * @access private
	 */
	public function get_delay_for_post( $post_id, $normalize = true ) {
		
		DBG::log( "Loading post# {$post_id}" );
		
		$posts = $this->find_by_id( $post_id );
		
		DBG::log( "Found " . count( $posts ) . " posts." );
		
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
			
			DBG::log( "get_delay_for_post(): Delay for post with id = {$post_id} is {$posts[$k]->delay}" );
		}
		
		return $posts;
	}
	
	/**
	 * Updates the delay for a post in the specified sequence (AJAX)
	 *
	 * @throws Exception
	 */
	public function update_delay_post_meta_callback() {
		
		DBG::log( "Update the delay input for the post/page meta" );
		
		check_ajax_referer( 'e20r-sequence-post-meta', 'e20r_sequence_postmeta_nonce' );
		
		DBG::log( "Nonce Passed for postmeta AJAX call" );
		
		$seq_id  = isset( $_POST['e20r_sequence_id'] ) ? intval( $_POST['e20r_sequence_id'] ) : null;
		$post_id = isset( $_POST['e20r_sequence_post_id'] ) ? intval( $_POST['e20r_sequence_post_id'] ) : null;
		
		DBG::log( "Sequence: {$seq_id}, Post: {$post_id}" );
		
		if ( ! $this->init( $seq_id ) ) {
			wp_send_json_error( $this->get_error_msg() );
		}
		
		$view = apply_filters( 'get_sequence_views_class_instance', null );
		$html = $view->load_sequence_list_meta( $post_id, $seq_id );
		
		wp_send_json_success( $html );
	}
	
	/**
	 * Process AJAX based additions to the sequence list
	 *
	 * Returns 'error' message (or nothing, if success) to calling JavaScript function
	 */
	public function add_post_callback() {
		
		check_ajax_referer( 'e20r-sequence-post', 'e20r_sequence_post_nonce' );
		
		global $current_user;
		
		// Fetch the ID of the sequence to add the post to
		$sequence_id = isset( $_POST['e20r_sequence_id'] ) && ! empty( $_POST['e20r_sequence_id'] ) ? intval( $_POST['e20r_sequence_id'] ) : null;
		$seq_post_id = isset( $_POST['e20r_sequence_post'] ) && ! empty( $_POST['e20r_sequence_post'] ) ? intval( $_REQUEST['e20r_sequence_post'] ) : null;
		
		$view = apply_filters( 'get_sequence_views_class_instance', null );
		
		DBG::log( "add_post_callback() - We received sequence ID: {$sequence_id}" );
		
		if ( ! empty( $sequence_id ) ) {
			
			// Initiate & configure the Sequence class
			if ( ! $this->init( $sequence_id ) ) {
				
				wp_send_json_error( $this->get_error_msg() );
			}
			
			if ( isset( $_POST['e20r_sequence_delay'] ) && ( 'byDate' == $this->options->delayType ) ) {
				
				DBG::log( "add_post_callback() - Could be a date we've been given ({$_POST['e20r_sequence_delay']}), so..." );
				
				if ( ( 'byDate' == $this->options->delayType ) && ( false != strtotime( $_POST['e20r_sequence_delay'] ) ) ) {
					
					DBG::log( "add_post_callback() - Validated that Delay value is a date." );
					$delay_val = isset( $_POST['e20r_sequence_delay'] ) ? sanitize_text_field( $_POST['e20r_sequence_delay'] ) : null;
				}
			} else {
				
				DBG::log( "add_post_callback() - Validated that Delay value is probably a day nunmber." );
				$delay_val = isset( $_POST['e20r_sequence_delay'] ) ? intval( $_POST['e20r_sequence_delay'] ) : null;
			}
			
			DBG::log( 'add_post_callback() - Checking whether delay value is correct' );
			$delay = $this->validate_delay_value( $delay_val );
			
			if ( $this->is_present( $seq_post_id, $delay ) ) {
				
				DBG::log( "add_post_callback() - Post {$seq_post_id} with delay {$delay} is already present in sequence {$sequence_id}" );
				$this->set_error_msg( __( 'Not configured to allow multiple delays for the same post/page', "e20r-sequences" ) );
				
				wp_send_json_error( $this->get_error_msg() );
				
				return;
			}
			
			// Get the Delay to use for the post (depends on type of delay configured)
			if ( $delay !== false ) {
				
				$user_can = apply_filters( 'e20r-sequence-has-edit-privileges', $this->user_can_edit( $current_user->ID ) );
				
				if ( $user_can && ! is_null( $seq_post_id ) ) {
					
					DBG::log( 'add_post_callback() - Adding post ' . $seq_post_id . ' to sequence ' . $this->sequence_id );
					
					if ( $this->add_post( $seq_post_id, $delay ) ) {
						
						$success = true;
						// $this->set_error_msg( null );
					} else {
						$success = false;
						$this->set_error_msg( __( sprintf( "Error adding post with ID: %s and delay value: %s to this sequence", 'e20r-sequences' ), esc_attr( $seq_post_id ), esc_attr( $delay ) ) );
					}
					
				} else {
					$success = false;
					$this->set_error_msg( __( 'Not permitted to modify the sequence', "e20r-sequences" ) );
				}
				
			} else {
				
				DBG::log( 'e20r_sequence_add_post_callback(): Delay value was not specified. Not adding the post: ' . esc_attr( $_POST['e20r_sequencedelay'] ) );
				
				if ( empty( $seq_post_id ) && ( $this->get_error_msg() == null ) ) {
					
					$this->set_error_msg( sprintf( __( 'Did not specify a post/page to add', "e20r-sequences" ) ) );
				} else if ( ( $delay !== 0 ) && empty( $delay ) ) {
					
					$this->set_error_msg( __( 'No delay has been specified', "e20r-sequences" ) );
				}
				
				$delay       = null;
				$seq_post_id = null;
				
				$success = false;
				
			}
			
			if ( empty( $seq_post_id ) && ( $this->get_error_msg() == null ) ) {
				
				$success = false;
				$this->set_error_msg( sprintf( __( 'Did not specify a post/page to add', "e20r-sequences" ) ) );
			} else if ( empty( $sequence_id ) && ( $this->get_error_msg() == null ) ) {
				
				$success = false;
				$this->set_error_msg( sprintf( __( 'This sequence was not found on the server!', "e20r-sequences" ) ) );
			}
			
			$result = $view->get_post_list_for_metabox( true );
			
			// DBG::log("e20r_sequence_add_post_callback() - Data added to sequence. Returning to calling JS script");
			
			if ( $result['success'] && $success ) {
				DBG::log( 'e20r_sequence_add_post_callback() - Returning success to javascript frontend' );
				
				wp_send_json_success( $result );
				
			} else {
				
				DBG::log( 'e20r_sequence_add_post_callback() - Returning error to javascript frontend' );
				wp_send_json_error( $result );
			}
		} else {
			DBG::log( "Sequence ID was 0. That's a 'blank' sequence" );
			wp_send_json_error( array( array( 'message' => __( 'No sequence specified. Did you remember to save this page first?', 'e20r-sequences' ) ) ) );
		}
	}
	
	/**
	 * Check that the delay specified by the user is valid for this plugin
	 *
	 * @param $delay -- The value to test for validity
	 *
	 * @return bool|int|string|void
	 */
	public function validate_delay_value( $delay ) {
		
		$delay = ( is_numeric( $delay ) ? intval( $delay ) : esc_attr( $delay ) );
		
		if ( ( $delay !== 0 ) && ( ! empty( $delay ) ) ) {
			
			// Check that the provided delay format matches the configured value.
			if ( $this->is_valid_delay( $delay ) ) {
				
				DBG::log( 'validate_delay_value(): Delay value is recognizable' );
				
				if ( $this->is_valid_date( $delay ) ) {
					
					DBG::log( 'validate_delay_value(): Delay specified as a valid date format' );
					
				} else {
					
					DBG::log( 'validate_delay_value(): Delay specified as the number of days' );
				}
			} else {
				// Ignore this post & return error message to display for the user/admin
				// NOTE: Format of date is not translatable
				$expectedDelay = ( $this->options->delayType == 'byDate' ) ? __( 'date: YYYY-MM-DD', "e20r-sequences" ) : __( 'number: Days since membership started', "e20r-sequences" );
				
				DBG::log( 'validate_delay_value(): Invalid delay value specified, not adding the post. Delay is: ' . $delay );
				$this->set_error_msg( sprintf( __( 'Invalid delay specified ( %1$s ). Expected format is a %2$s', "e20r-sequences" ), $delay, $expectedDelay ) );
				
				$delay = false;
			}
		} else if ( $delay === 0 ) {
			
			// Special case:
			return $delay;
			
		} else {
			
			DBG::log( 'validate_delay_value(): Delay value was not specified. Not adding the post. Delay is: ' . esc_attr( $delay ) );
			
			if ( empty( $delay ) ) {
				
				$this->set_error_msg( __( 'No delay has been specified', "e20r-sequences" ) );
			}
		}
		
		return $delay;
	}
	
	/**
	 * Deactivate the plugin and clear our stuff.
	 */
	public function deactivation() {
		
		global $e20r_sequence_deactivating, $wpdb;
		$e20r_sequence_deactivating = true;
		
		flush_rewrite_rules();
		
		/*
        $sql = "
            SELECT *
            FROM {$wpdb->posts}
            WHERE post_type = 'pmpro_sequence'
        ";

        $seqs = $wpdb->get_results( $sql );
        */
		
		// Easiest is to iterate through all Sequence IDs and set the setting to 'sendNotice == 0'
		$seqs = new \WP_Query( array( 'post_type' => 'pmpro_sequence' ) );
		
		// Iterate through all sequences and disable any cron jobs causing alerts to be sent to users
		foreach ( $seqs as $s ) {
			
			$this->get_options( $s->ID );
			
			if ( $this->options->sendNotice == 1 ) {
				
				// Set the alert flag to 'off'
				$this->options->sendNotice = 0;
				
				// save meta for the sequence.
				$this->save_sequence_meta();
				
				Cron::stop_sending_user_notices( $s->ID );
				
				DBG::log( 'Deactivated email alert(s) for sequence ' . $s->ID );
			}
		}
		
		/* Unregister the default Cron job for new content alert(s) */
		Cron::stop_sending_user_notices();
	}
	
	/**
	 * Activation hook for the plugin
	 * We need to flush rewrite rules on activation/etc for the CPTs.
	 */
	public function activation() {
		$old_timeout = ini_get( 'max_execution_time' );
		
		DBG::log( "Processing activation event using {$old_timeout} secs as timeout" );
		
		if ( ! function_exists( 'pmpro_getOption' ) ) {
			
			$errorMessage = __( "The Eighty/20 Results Sequence plugin requires the ", "e20r-sequences" );
			$errorMessage .= "<a href='http://www.paidmembershipspro.com/' target='_blank' title='" . __( "Opens in a new window/tab.", "e20r-sequences" ) . "'>";
			$errorMessage .= __( "Paid Memberships Pro</a> membership plugin.<br/><br/>", "e20r-sequences" );
			$errorMessage .= __( "Please install Paid Memberships Pro before attempting to activate this Eighty/20 Results Sequence plugin.<br/><br/>", "e20r-sequences" );
			$errorMessage .= __( "Click the 'Back' button in your browser to return to the Plugin management page.", "e20r-sequences" );
			wp_die( $errorMessage );
		}
		
		Sequence_Controller::create_custom_post_type();
		flush_rewrite_rules();
		
		/* Search for existing pmpro_series posts & import */
		Importer::import_all_series();
		
		/* Convert old metadata format to new (v3) format */
		
		$sequence  = apply_filters( 'get_sequence_class_instance', null );
		$sequences = $sequence->get_all_sequences();
		
		DBG::log( "Found " . count( $sequences ) . " to convert" );
		
		foreach ( $sequences as $seq ) {
			
			DBG::log( "Converting configuration meta to v3 format for {$seq->ID}" );
			
			$sequence->upgrade_sequence( $seq->ID, true );
		}
		
		/* Register the default cron job to send out new content alerts */
		Cron::schedule_default();
		
		// $sequence->convert_user_notifications();
	}
	
	/**
	 * Convert sequence ID to V3 metadata config if it hasn't been converted already.
	 *
	 * @param $seq_id
	 */
	public function upgrade_sequence( $seq_id, $force ) {
		
		DBG::log( "Process {$seq_id} for V3 upgrade?" );
		
		if ( ( version_compare( E20R_SEQUENCE_VERSION, '3.0.0', '<=')) && false === $this->is_converted( $seq_id ) ) {
			
			DBG::log( "Need to convert sequence #{$seq_id} to V3 format" );
			$this->get_options( $seq_id );
			
			if ( $this->convert_posts_to_v3( $seq_id, true ) ) {
				DBG::log( "Converted {$seq_id} to V3 format" );
				$this->convert_user_notifications( $seq_id );
				DBG::log( "Converted {$seq_id} user notifications to V3 format" );
			} else {
				DBG::log( "Error during conversion of {$seq_id} to V3 format" );
			}
		} else if ( version_compare( E20R_SEQUENCE_VERSION, '3.0.0', '>') && false === $this->is_converted( $seq_id ) ) {
			DBG::log( "Sequence id# {$this->sequence_id} doesn't need to be converted to v3 metadata format" );
			$this->current_metadata_versions[ $this->sequence_id ] = 3;
			update_option( "pmpro_sequence_metadata_version", $this->current_metadata_versions );
		}
	}
	
	/**
	 * Converts the posts for a sequence to the V3 metadata format (settings)
	 *
	 * @param null $sequence_id - The ID of the sequence to convert.
	 * @param bool $force       - Whether to force the conversion or not.
	 *
	 * @return bool - Whether the conversion successfully completed or not
	 */
	public function convert_posts_to_v3( $sequence_id = null, $force = false ) {
		
		global $converting_sequence;
		
		$converting_sequence = true;
		
		if ( ! is_null( $sequence_id ) ) {
			
			if ( isset( $this->current_metadata_versions[ $sequence_id ] ) && ( 3 >= $this->current_metadata_versions[ $sequence_id ] ) ) {
				
				DBG::log( "Sequence {$sequence_id} is already converted." );
				
				return;
			}
			
			$old_sequence_id = $this->sequence_id;
			/**
			 * if ( false === $force  ) {
			 *
			 * DBG::log("Loading posts for {$sequence_id}.");
			 * $this->get_options( $sequence_id );
			 * $this->load_sequence_post();
			 * }
			 */
		}
		
		$is_pre_v3 = get_post_meta( $this->sequence_id, "_sequence_posts", true );
		
		DBG::log( "Need to convert from old metadata format to new format for sequence {$this->sequence_id}" );
		$retval = true;
		
		if ( ! empty( $this->sequence_id ) ) {
			
			// $tmp = get_post_meta( $sequence_id, "_sequence_posts", true );
			$posts = ( ! empty( $is_pre_v3 ) ? $is_pre_v3 : array() ); // Fixed issue where empty sequences would generate error messages.
			
			foreach ( $posts as $sp ) {
				
				DBG::log( "Adding post #{$sp->id} with delay {$sp->delay} to sequence {$this->sequence->id} " );
				
				$added_to_sequence = false;
				$s_list            = get_post_meta( $sp->id, "_pmpro_sequence_post_belongs_to" );
				
				if ( false == $s_list || ! in_array( $sequence_id, $s_list ) ) {
					add_post_meta( $sp->id, '_pmpro_sequence_post_belongs_to', $sequence_id );
					$added_to_sequence = true;
				}
				
				if ( true === $this->allow_repetition() && true === $added_to_sequence ) {
					add_post_meta( $sp->id, "_pmpro_sequence_{$sequence_id}_post_delay", $sp->delay );
					
				} else if ( false == $this->allow_repetition() && true === $added_to_sequence ) {
					
					update_post_meta( $sp->id, "_pmpro_sequence_{$sequence_id}_post_delay", $sp->delay );
				}
				
				if ( ( false !== get_post_meta( $sp->id, '_pmpro_sequence_post_belongs_to', true ) ) &&
				     ( false !== get_post_meta( $sp->id, "_pmpro_sequence_{$sequence_id}_post_delay", true ) )
				) {
					DBG::log( "Edited metadata for migrated post {$sp->id} and delay {$sp->delay}" );
					$retval = true;
					
				} else {
					delete_post_meta( $sp->id, '_pmpro_sequence_post_belongs_to', $this->sequence_id );
					delete_post_meta( $sp->id, "_pmpro_sequence_{$this->sequence_id}_post_delay", $sp->delay );
					$retval = false;
				}
				
				// $retval = $retval && $this->add_post_to_sequence( $this->sequence_id, $sp->id, $sp->delay );
			}
			
			// DBG::log("Saving to new V3 format... ", E20R_DEBUG_SEQ_WARNING );
			// $retval = $retval && $this->save_sequence_post();
			
			DBG::log( "(Not) Removing old format meta... ", E20R_DEBUG_SEQ_WARNING );
			// $retval = $retval && delete_post_meta( $this->sequence_id, "_sequence_posts" );
		} else {
			
			$retval = false;
			$this->set_error_msg( __( "Cannot convert to V3 metadata format: No sequences were defined.", "e20r-sequences" ) );
		}
		
		if ( $retval == true ) {
			
			DBG::log( "Converted sequence id# {$this->sequence_id} to v3 metadata format for all sequence member posts" );
			$this->current_metadata_versions[ $this->sequence_id ] = 3;
			update_option( "pmpro_sequence_metadata_version", $this->current_metadata_versions );
			
			// Reset sequence info.
			$this->get_options( $old_sequence_id );
			$this->load_sequence_post( null, null, null, '=', null, true );
			
		} else {
			$this->set_error_msg( sprintf( __( "Unable to upgrade post metadata for sequence (%s)", "e20r-sequences" ), get_the_title( $this->sequence_id ) ) );
		}
		
		$converting_sequence = false;
		
		return $retval;
	}
	
	/**
	 * Registers the Sequence Custom Post Type (CPT)
	 *
	 * @return bool -- True if successful
	 *
	 * @access public
	 *
	 */
	static public function create_custom_post_type() {
		
		// Not going to want to do this when deactivating
		global $e20r_sequence_deactivating;
		
		if ( ! empty( $e20r_sequence_deactivating ) ) {
			return false;
		}
		
		$defaultSlug = get_option( 'e20r_sequence_slug', 'sequence' );
		
		$labels = array(
			'name'               => __( 'Sequences', "e20r-sequences" ),
			'singular_name'      => __( 'Sequence', "e20r-sequences" ),
			'slug'               => 'e20r_sequence',
			'add_new'            => __( 'New Sequence', "e20r-sequences" ),
			'add_new_item'       => __( 'New Sequence', "e20r-sequences" ),
			'edit'               => __( 'Edit Sequence', "e20r-sequences" ),
			'edit_item'          => __( 'Edit Sequence', "e20r-sequences" ),
			'new_item'           => __( 'Add New', "e20r-sequences" ),
			'view'               => __( 'View Sequence', "e20r-sequences" ),
			'view_item'          => __( 'View This Sequence', "e20r-sequences" ),
			'search_items'       => __( 'Search Sequences', "e20r-sequences" ),
			'not_found'          => __( 'No Sequence Found', "e20r-sequences" ),
			'not_found_in_trash' => __( 'No Sequence Found In Trash', "e20r-sequences" ),
		);
		
		$error = register_post_type( 'pmpro_sequence',
			array(
				'labels'             => apply_filters( 'e20r-sequence-cpt-labels', $labels ),
				'public'             => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'publicly_queryable' => true,
				'hierarchical'       => true,
				'supports'           => array( 'title', 'editor', 'thumbnail', 'custom-fields', 'author', 'excerpt' ),
				'can_export'         => true,
				'show_in_nav_menus'  => true,
				'rewrite'            => array(
					'slug'       => apply_filters( 'e20r-sequence-cpt-slug', $defaultSlug ),
					'with_front' => false,
				),
				'has_archive'        => apply_filters( 'e20r-sequence-cpt-archive-slug', 'sequences' ),
			)
		);
		
		if ( ! is_wp_error( $error ) ) {
			return true;
		} else {
			DBG::log( 'Error creating post type: ' . $error->get_error_message(), E20R_DEBUG_SEQ_CRITICAL );
			wp_die( $error->get_error_message() );
			
			return false;
		}
	}
	
	/**
	 * Trigger conversion of the user notification metadata for all users - called as part of activation or upgrade.
	 *
	 * @param int|null $sid - Sequence ID to convert user notification(s) for.
	 */
	public function convert_user_notifications( $sid = null ) {
		
		global $wpdb;
		
		// Load all sequences from the DB
		$query = array(
			'post_type'      => 'pmpro_sequence',
			'post_status'    => apply_filters( 'e20r-sequence-allowed-post-statuses', array(
				'publish',
				'future',
				'private',
			) ),
			'posts_per_page' => - 1,
		);
		
		$sequence_list = new \WP_Query( $query );
		
		DBG::log( "Found " . count( $sequence_list ) . " sequences to process for alert conversion" );
		
		while ( $sequence_list->have_posts() ) {
			
			$sequence_list->the_post();
			$sequence_id = get_the_ID();
			
			if ( is_null( $sid ) || ( $sid == $sequence_id ) ) {
				
				$this->get_options( $sequence_id );
				
				$users = $this->get_users_of_sequence();
				
				foreach ( $users as $user ) {
					
					$this->e20r_sequence_user_id = $user->user_id;
					$userSettings                = get_user_meta( $user->user_id, "pmpro_sequence_id_{$sequence_id}_notices", true );
					
					// No V3 formatted settings found. Will convert from V2 (if available)
					if ( empty( $userSettings ) || ( ! isset( $userSettings->send_notices ) ) ) {
						
						DBG::log( "Converting notification settings for user with ID: {$user->user_id}" );
						DBG::log( "Loading V2 meta: {$wpdb->prefix}pmpro_sequence_notices for user ID: {$user->user_id}" );
						
						$v2 = get_user_meta( $user->user_id, "{$wpdb->prefix}" . "pmpro_sequence_notices", true );
						
						// DBG::log($old_optIn);
						
						if ( ! empty( $v2 ) ) {
							
							DBG::log( "V2 settings found. They are: " );
							DBG::log( $v2 );
							
							DBG::log( "Found old-style notification settings for user {$user->user_id}. Attempting to convert", E20R_DEBUG_SEQ_WARNING );
							
							// Loop through the old-style array of sequence IDs
							$count = 1;
							
							foreach ( $v2->sequence as $sId => $data ) {
								
								DBG::log( "Converting sequence notices for {$sId} - Number {$count} of " . count( $v2->sequence ) );
								$count ++;
								
								$userSettings = $this->convert_alert_setting( $user->user_id, $sId, $data );
								
								if ( isset( $userSettings->send_notices ) ) {
									
									$this->save_user_notice_settings( $user->user_id, $userSettings, $sId );
									DBG::log( " Removing converted opt-in settings from the database" );
									delete_user_meta( $user->user_id, $wpdb->prefix . "pmpro_sequence_notices" );
								}
							}
						}
						
						if ( empty( $v2 ) && empty( $userSettings ) ) {
							
							DBG::log( "convert_user_notification() - No v2 or v3 alert settings found for {$user->user_id}. Skipping this user" );
							continue;
						}
						
						DBG::log( "V3 Alert settings for user {$user->user_id}" );
						DBG::log( $userSettings );
						
						$userSettings->completed = true;
						DBG::log( "Saving new notification settings for user with ID: {$user->user_id}" );
						
						if ( ! $this->save_user_notice_settings( $user->user_id, $userSettings, $this->sequence_id ) ) {
							
							DBG::log( "convert_user_notification() - Unable to save new notification settings for user with ID {$user->user_id}", E20R_DEBUG_SEQ_WARNING );
						}
					} else {
						DBG::log( "convert_user_notification() - No alert settings to convert for {$user->user_id}" );
						DBG::log( "convert_user_notification() - Checking existing V3 settings..." );
						
						$member_days = $this->get_membership_days( $user->user_id );
						
						$old = $this->posts;
						
						$compare      = $this->load_sequence_post( $sequence_id, $member_days, null, '<=', null, true );
						$userSettings = $this->fix_user_alert_settings( $userSettings, $compare, $member_days );
						$this->save_user_notice_settings( $user->user_id, $userSettings, $sequence_id );
						
						$this->posts = $old;
					}
				}
				
				if ( isset($user->user_id) && ! $this->remove_old_user_alert_setting( $user->user_id ) ) {
					
					DBG::log( "Unable to remove old user_alert settings!", E20R_DEBUG_SEQ_WARNING );
				}
			}
		}
		
		wp_reset_postdata();
	}
	
	/**
	 * Updates the alert settings for a given userID
	 *
	 * @param $user_id - The user ID to convert settings for
	 * @param $sId     - The Sequence ID to check/use
	 * @param $v2      - The V2 sequence
	 *
	 * @return \stdClass - The converted notification settings for the $user_id.
	 */
	private function convert_alert_setting( $user_id, $sId, $v2 ) {
		
		$v3 = $this->create_user_notice_defaults();
		
		$v3->id = $sId;
		
		$member_days = $this->get_membership_days( $user_id );
		$this->get_options( $sId );
		
		$compare = $this->load_sequence_post( $sId, $member_days, null, '<=', null, true );
		
		DBG::log( "Converting the sequence ( {$sId} ) post list for user alert settings" );
		
		$when = isset( $v2->optinTS ) ? $v2->optinTS : current_time( 'timestamp' );
		
		$v3->send_notices = $v2->sendNotice;
		$v3->posts        = $v2->notifiedPosts;
		$v3->optin_at     = $v2->last_notice_sent = $when;
		
		DBG::log( "Looping through " . count( $v3->posts ) . " alert entries" );
		foreach ( $v3->posts as $key => $post_id ) {
			
			if ( false === strpos( $post_id, '_' ) ) {
				
				DBG::log( "This entry ({$post_id}) needs to be converted..." );
				$posts = $this->find_by_id( $post_id );
				
				foreach ( $posts as $p ) {
					
					$flag_value = "{$p->id}_" . $this->normalize_delay( $p->delay );
					
					if ( ( $p->id == $post_id ) && ( $this->normalize_delay( $p->delay ) <= $member_days ) ) {
						
						if ( $v3->posts[ $key ] == $post_id ) {
							
							DBG::log( "Converting existing alert entry" );
							$v3->posts[ $key ] = $flag_value;
						} else {
							DBG::log( "Adding alert entry" );
							$v3->posts[] = $flag_value;
						}
					}
				}
			}
		}
		
		$compare = $this->load_sequence_post( null, $member_days, null, '<=', null, true );
		$v3      = $this->fix_user_alert_settings( $v3, $compare, $member_days );
		
		return $v3;
	}
	
	/**
	 * Clear (remove) the usermeta containing the old notification settings for $user_id
	 *
	 * @param $user_id - The ID of the user who's settings we'll remove.
	 *
	 * @return bool -- Whether we were able to remove them or not.
	 */
	private function remove_old_user_alert_setting( $user_id ) {
		
		global $wpdb;
		
		$v2 = get_post_meta( $user_id, $wpdb->prefix . "pmpro_sequence_notices", true );
		
		if ( ! empty( $v2 ) ) {
			
			return delete_user_meta( $user_id, $wpdb->prefix . "pmpro_sequence_notices" );
		} else {
			// No v2 meta found..
			return true;
		}
	}
	
	/**
	 * Configure & display the icon for the Sequence Post type (in the Dashboard)
	 */
	public function post_type_icon() {
		?>
        <style>
            #adminmenu .menu-top.menu-icon-<?php echo self::$seq_post_type;?> div.wp-menu-image:before {
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
		
		DBG::log( "register_user_scripts() - Loading user script(s) & styles" );
		
		$found_links = has_shortcode( $post->post_content, 'sequence_links' );
		$found_optin = has_shortcode( $post->post_content, 'sequence_alert' );
		
		DBG::log( "register_user_scripts() - 'sequence_links' or 'sequence_alert' shortcode present? " . ( $found_links || $found_optin ? 'Yes' : 'No' ) );
		
		if ( ( true === $found_links ) || ( true === $found_optin ) || ( 'pmpro_sequence' == $this->get_post_type() ) || 'pmpro_sequence' == $post->post_type ) {
			
			$load_e20r_sequence_script = true;
			
			DBG::log( "Loading client side javascript and CSS" );
			wp_register_script( 'e20r-sequence-user', E20R_SEQUENCE_PLUGIN_URL . 'js/e20r-sequences.js', array( 'jquery' ), E20R_SEQUENCE_VERSION, true );
			
			$user_styles = apply_filters( 'e20r-sequences-userstyles', null );
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
			
			wp_enqueue_script( 'e20r-sequence-user' );
		} else {
			$load_e20r_sequence_script = false;
			DBG::log( "register_user_scripts() - Didn't find the expected shortcode... Not loading client side javascript and CSS" );
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
	 * Add javascript and CSS for end-users on the front-end of the site.
	 * TODO: Is this a duplicate for register_user_scripts???
	 */
	public function enqueue_user_scripts() {
		
		global $load_e20r_sequence_script;
		global $post;
		
		if ( $load_e20r_sequence_script !== true ) {
			return;
		}
		
		if ( ! isset( $post->post_content ) ) {
			
			return;
		}
		
		$foundShortcode = has_shortcode( $post->post_content, 'sequence_links' );
		
		DBG::log( "enqueue_user_scripts() - 'sequence_links' shortcode present? " . ( $foundShortcode ? 'Yes' : 'No' ) );
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
	
	/**
	 * Load all JS & CSS for Admin page
	 *
	 * @param string $hook
	 *
	 */
	public function register_admin_scripts( $hook ) {
		
		global $post;
		
		if ( $hook != 'post-new.php' && $hook != 'post.php' && $hook != 'edit.php' ) {
			
			DBG::log( "Unexpected Hook: {$hook}" );
			
			return;
		}
		
		$post_types   = apply_filters( "e20r-sequence-managed-post-types", array( "post", "page" ) );
		$post_types[] = 'pmpro_sequence';
		
		if ( isset( $post->ID ) && ! in_array( $post->post_type, $post_types ) ) {
			DBG::log( "Incorrect Post Type: {$post->post_type}" );
			
			return;
		}
		
		DBG::log( "Loading admin scripts & styles for E20R Sequences" );
		
		$delay_config = $this->set_delay_config();
		
		wp_enqueue_style( 'fontawesome', E20R_SEQUENCE_PLUGIN_URL . '/css/font-awesome.min.css', false, '4.5.0' );
		wp_enqueue_style( 'select2', "//cdnjs.cloudflare.com/ajax/libs/select2/" . self::$select2_version . "/css/select2.min.css", false, self::$select2_version );
		
		wp_enqueue_style( 'select2', '//cdnjs.cloudflare.com/ajax/libs/select2/3.5.2/select2.min.css', '', '3.5.2', 'screen' );
		wp_enqueue_style( 'e20r-sequence', E20R_SEQUENCE_PLUGIN_URL . 'css/e20r_sequences.css' );
		
		wp_enqueue_script( 'select2', "//cdnjs.cloudflare.com/ajax/libs/select2/" . self::$select2_version . "/js/select2.min.js", array( 'jquery' ), self::$select2_version );
		
		wp_register_script( 'e20r-sequence-admin', E20R_SEQUENCE_PLUGIN_URL . 'js/e20r-sequences-admin.js', array(
			'jquery',
			'select2',
		), E20R_SEQUENCE_VERSION, true );
		
		/* Localize ajax script */
		wp_localize_script( 'e20r-sequence-admin', 'e20r_sequence',
			array(
				'ajaxurl'      => admin_url( 'admin-ajax.php' ),
				'delay_config' => $delay_config,
				'lang'         => array(
					'alert_not_saved'           => __( "Error: This sequence needs to be saved before you can send alerts", "e20r-sequences" ),
					'save'                      => __( 'Update Sequence', "e20r-sequences" ),
					'saving'                    => __( 'Saving', "e20r-sequences" ),
					'saveSettings'              => __( 'Update Settings', "e20r-sequences" ),
					'delay_change_confirmation' => __( 'Changing the delay type will erase all existing posts or pages in the Sequence list. (Cancel if your are unsure)', "e20r-sequences" ),
					'saving_error_1'            => __( 'Error saving sequence post [1]', "e20r-sequences" ),
					'saving_error_2'            => __( 'Error saving sequence post [2]', "e20r-sequences" ),
					'remove_error_1'            => __( 'Error deleting sequence post [1]', "e20r-sequences" ),
					'remove_error_2'            => __( 'Error deleting sequence post [2]', "e20r-sequences" ),
					'undefined'                 => __( 'Not Defined', "e20r-sequences" ),
					'unknownerrorrm'            => __( 'Unknown error removing post from sequence', "e20r-sequences" ),
					'unknownerroradd'           => __( 'Unknown error adding post to sequence', "e20r-sequences" ),
					'daysLabel'                 => __( 'Delay', "e20r-sequences" ),
					'daysText'                  => __( 'Days to delay', "e20r-sequences" ),
					'dateLabel'                 => __( 'Avail. on', "e20r-sequences" ),
					'dateText'                  => __( 'Release on (YYYY-MM-DD)', "e20r-sequences" ),
				),
			)
		);
		
		wp_enqueue_style( "e20r-sequence" );
		
		wp_enqueue_script( 'e20r-sequence-admin' );
	}
	
	/**
	 * Load array containing the delay type settings for the sequences in the system
	 *
	 * @return array|null - Array of used delay types (days since start / date) for the sequences in the system.
	 */
	public function set_delay_config() {
		
		$sequences = $this->get_all_sequences( array( 'publish', 'pending', 'draft', 'private', 'future' ) );
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
	 * Register any and all widgets for PMPro Sequence
	 */
	public function register_widgets() {
		
		// Add widget to display a summary for the most recent post/page
		// in the sequence for the logged in user.
		register_widget( '\E20R\Sequences\Tools\Widgets\Post_Widget' );
	}
	
	/**
	 * Register any and all shortcodes for PMPro Sequence
	 */
	public function register_shortcodes() {
		
		$sl = new Sequence_Links();
		$sa = new Sequence_Alert();
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
		
		$locale = apply_filters( "plugin_locale", get_locale(), 'e20r-sequences' );
		
		$mofile = "e20r-sequences-{$locale}.mo";
		
		$mofile_local  = dirname( __FILE__ ) . "/../languages/" . $mofile;
		$mofile_global = WP_LANG_DIR . "/e20r-sequences/" . $mofile;
		
		load_textdomain( "e20r-sequences", $mofile_global );
		load_textdomain( "e20r-sequences", $mofile_local );
	}
	
	/**
	 * Return error if an AJAX call is attempted by a user who hasn't logged in.
	 */
	public function unprivileged_ajax_error() {
		
		DBG::log( 'Unprivileged ajax call attempted', E20R_DEBUG_SEQ_CRITICAL );
		
		wp_send_json_error( array(
			'message' => __( 'You must be logged in to edit PMPro Sequences', "e20r-sequences" ),
		) );
	}
	
	/**
	 * Hooks to action for sending user notifications
	 * TODO: Check whether send_user_alert_notices is redundant?
	 */
	public function send_user_alert_notices() {
		
		$sequence_id = intval( $_REQUEST['e20r_sequence_id'] );
		
		DBG::log( 'Will send alerts for sequence #' . $sequence_id );

//            $sequence = apply_filters('get_sequence_class_instance', null);
//            $sequence->sequence_id = $sequence_id;
//            $sequence->get_options( $sequence_id );
		
		do_action( 'e20r_sequence_cron_hook', array( $sequence_id ) );
		
		DBG::log( 'send_user_alert_notices() - Completed action for sequence #' . $sequence_id );
		wp_redirect( '/wp-admin/edit.php?post_type=pmpro_sequence' );
	}
	
	/**
	 * Trigger send of any new content alert messages for a sequence in the Sequence Edit menu
	 *
	 * @param         $actions - Action
	 * @param WP_Post $post    - Post object
	 *
	 * @return array - Array containing the list of actions to list in the menu
	 */
	public function send_alert_notice_from_menu( $actions, $post ) {
		
		global $current_user;
		
		if ( ( 'pmpro_sequence' == $post->post_type ) && $this->user_can_edit( $current_user->ID ) ) {
			
			$options = $this->get_options( $post->ID );
			
			if ( 1 == $options->sendNotice ) {
				
				DBG::log( "send_alert_notice_from_menu() - Adding send action" );
				$actions['send_notices'] = '<a href="admin.php?post=' . $post->ID . '&amp;action=send_user_alert_notices&amp;e20r_sequence_id=' . $post->ID . '" title="' . __( "Send user alerts", "e20rtracker" ) . '" rel="permalink">' . __( "Send Notices", "e20rtracker" ) . '</a>';
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
	
	public function e20r_add_membership_module_signup_hook() {
		add_action( 'pmpro_after_checkout', array( $this, 'e20r_sequence_pmpro_after_checkout' ), 10, 2 );
	}
	
	/**
	 * All published sequences that are protected by the specified PMPro Membership Level
	 *
	 * @param $level_id - the Level ID
	 *
	 * @return array - array of sequences;
	 */
	public function sequences_for_membership_level( $membership_level_id ) {
		global $wpdb;
		global $current_user;
		
		// get all published sequences
		$sequence_list = $this->get_all_sequences( array( 'publish', 'private' ) );
		$in_sequence   = array();
		
		DBG::log( "Found " . count( $sequence_list ) . " sequences have been published on this system" );
		
		// Pull out the ID values (post IDs)
		foreach ( $sequence_list as $s ) {
			
			$in_sequence[] = $s->ID;
		}
		
		// check that there are sequences found
		if ( ! empty( $in_sequence ) ) {
			
			DBG::log( "Search DB for sequences protected by the specified membership ID: {$membership_level_id}" );
			
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
			
			DBG::log( "Found " . count( $sequences ) . " sequences that are protected by level # {$membership_level_id}" );
			
			// list of page IDs that have the level ID configured
			return $sequences;
			
		}
		
		DBG::log( "Found NO sequences protected by level # {$membership_level_id}!" );
		
		// No sequences configured
		return null;
	}
	
	/**
	 * Set the per-sequence startdate whenever the user signs up for a PMPro membership level.
	 * TODO: Add functionality to do the same as part of activation/startup for the Sequence.
	 *
	 * @param              $user_id - the ID of the user
	 * @param \MemberOrder $order   - The PMPro Membership order object
	 */
	public function e20r_sequence_pmpro_after_checkout( $user_id, $order ) {
		
		global $wpdb;
		global $current_user;
		
		$startdate_ts = null;
		$timezone     = null;
		
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
				DBG::log( "Using timezone: {$timezone}" );
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
	 * Returns the per-sequence startdate to use for a user
	 *
	 * @param $user_id -- The user ID to find the startdate for
	 *
	 * @return mixed  - A UNIX timestamp (seconds since start of epoch)
	 */
	public function get_user_startdate( $user_id = null, $level_id = null, $sequence_id = null ) {
		$timezone = get_option( 'timezone_string' );
		
		// TODO: Split into pmpro_getMemberStartdate call & return into own module w/e20r-sequence-user-startdate filter
		if ( function_exists( 'pmpro_getMemberStartdate' ) ) {
			$startdate_ts = pmpro_getMemberStartdate( $user_id, $level_id );
		}
		
		// we didn't get a sequence id to process for so check if it's set by default
		if ( empty( $sequence_id ) && ! empty( $this->sequence_id ) ) {
			$sequence_id = $this->sequence_id;
		}
		
		// neither default nor received sequence id to process.
		if ( empty( $sequence_id ) && empty( $this->sequence_id ) ) {
			
			DBG::log( "No Sequence ID configured. Returning NULL" );
			
			return null;
		}
		
		// if the user doesn't have a membership level...
		if ( empty( $startdate_ts ) ) {
			
			// and there's a valid Timezone setting
			if ( ! empty( $timezone ) ) {
				
				// use 'right now' local time' as their startdate.
				DBG::log( "Using timezone: {$timezone}" );
				$startdate_ts = strtotime( 'today ' . get_option( 'timezone_string' ) );
			} else {
				// or we'll use the registration date for the user.
				$user         = get_user_by( 'id', $user_id );
				$startdate_ts = strtotime( $user->user_registered );
			}
		}
		
		$use_membership_startdate = apply_filters( 'e20r-sequence-use-membership-startdate', false );
		$user_global_startdate    = apply_filters( 'e20r-sequence-use-global-startdate', false );
		
		if ( false === $use_membership_startdate ) {
			
			// filter this so other membership modules can set the startdate too.
			$startdate_ts = apply_filters( 'e20r-sequence-mmodule-user-startdate', $startdate_ts, $user_id, $level_id, $sequence_id );
			
			DBG::log( "Filtered startdate: {$startdate_ts}" );
			
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
		
		DBG::log( "Using startdate value of : {$startdate}" );
		
		// finally, allow the user to filter the startdate to whatever they want it to be.
		return apply_filters( 'e20r-sequence-user-startdate', $startdate, $sequence_id, $user_id, $level_id );
	}
	
	/**
	 * Loads actions & filters for the plugin.
	 */
	public function load_actions() {
		// Register & validate the license for this plugin
		// TODO: Enable licensing
		/*
        \e20rLicense::registerLicense( 'e20r_sequence', __("E20R Drip Feed Sequences for PMPro", "e20r-sequences") );

        add_action('upgrader_pre_download', array( $this, 'checkLicense'), 9, 3 );
        add_action('plugins_loaded', array( $this, 'membership_signup_hooks'));
*/
		
		
		// Configure all needed class instance filters;
		add_filter( "get_sequence_views_class_instance", [ Sequence_Views::get_instance(), 'get_instance' ] );
		add_filter( "get_e20rerror_class_instance", [ E20R_Error::get_instance(), 'get_instance' ] );
		add_filter( "get_cron_class_instance", [ Cron::get_instance(), 'get_instance' ] );
		
		add_filter( "get_sequence_update_class_instance", [ Sequence_Updates::get_instance(), 'get_instance' ] );
		
		add_action( 'plugins_loaded', [ Sequence_Updates::get_instance(), 'init' ] );
		add_action( 'wp_loaded', [ Sequence_Updates::get_instance(), 'update' ], 1 ); // Run early
		add_action( 'e20r_sequence_cron_hook', [ Cron::get_instance(), 'check_for_new_content' ], 10, 1 );
		
		// TODO: Split pmpro filters into own filter management module
		
		// Load filters
		add_filter( "pmpro_after_phpmailer_init", array( &$this, "email_body" ) );
		add_filter( 'pmpro_sequencepost_types', array( &$this, 'included_cpts' ) );
		
		add_filter( "pmpro_has_membership_access_filter", array( &$this, "has_membership_access_filter" ), 9, 4 );
		add_filter( "pmpro_non_member_text_filter", array( &$this, "text_filter" ) );
		add_filter( "pmpro_not_logged_in_text_filter", array( &$this, "text_filter" ) );
		add_action( 'e20r_sequence_load_membership_signup_hook', array(
			$this,
			'e20r_add_membership_module_signup_hook',
		) );
		add_filter( 'e20r-sequence-mmodule-access-denied-msg', array( $this, 'pmpro_access_denied_msg' ), 15, 3 );
		add_filter( "the_content", array( &$this, "display_sequence_content" ) );
		
		// add_filter( "the_posts", array( &$this, "set_delay_values" ), 10, 2 );
		
		// Add Custom Post Type
		add_action( "init", array( &$this, "load_textdomain" ), 9 );
		add_action( "init", array( &$this, "create_custom_post_type" ), 10 );
		add_action( "init", array( &$this, "register_shortcodes" ), 11 );
		
		add_filter( "post_row_actions", array( &$this, 'send_alert_notice_from_menu' ), 10, 2 );
		add_filter( "page_row_actions", array( &$this, 'send_alert_notice_from_menu' ), 10, 2 );
		add_action( "admin_action_send_user_alert_notices", array( &$this, 'send_user_alert_notices' ) );
		
		
		// Add CSS & Javascript
		add_action( "wp_enqueue_scripts", array( &$this, 'register_user_scripts' ) );
		// add_action("wp_footer", array( &$this, 'enqueue_user_scripts') );
		
		add_action( "admin_enqueue_scripts", array( &$this, "register_admin_scripts" ) );
		add_action( 'admin_head', array( &$this, 'post_type_icon' ) );
		
		// Load metaboxes for editor(s)
		add_action( 'add_meta_boxes', array( &$this, 'post_metabox' ) );
		
		// Load add/save actions
		add_action( 'admin_init', array( &$this, 'check_conversion' ) );
		add_action( 'admin_notices', array( &$this, 'display_error' ) );
		// add_action( 'save_post', array( &$this, 'post_save_action' ) );
		add_action( 'post_updated', array( &$this, 'post_save_action' ) );
		
		add_action( 'admin_menu', array( &$this, "define_metaboxes" ) );
		add_action( 'save_post', array( &$this, 'save_post_meta' ), 10, 2 );
		// add_action('deleted_post', array(&$this, 'delete_post_meta_for_sequence'), 10, 1);
		add_action( 'widgets_init', array( &$this, 'register_widgets' ) );
		
		// Add AJAX handlers for logged in users/admins
		add_action( "wp_ajax_e20r_sequence_add_post", array( &$this, "add_post_callback" ) );
		add_action( 'wp_ajax_e20r_sequence_update_post_meta', array( &$this, 'update_delay_post_meta_callback' ) );
		add_action( 'wp_ajax_e20r_rm_sequence_from_post', array( &$this, 'rm_sequence_from_post_callback' ) );
		add_action( "wp_ajax_e20r_sequence_rm_post", array( &$this, "rm_post_callback" ) );
		add_action( "wp_ajax_e20r_remove_alert", array( &$this, "remove_post_alert_callback" ) );
		add_action( 'wp_ajax_e20r_sequence_clear', array( &$this, 'sequence_clear_callback' ) );
		add_action( 'wp_ajax_e20r_send_notices', array( &$this, 'sendalert_callback' ) );
		add_action( 'wp_ajax_e20r_sequence_save_user_optin', array( &$this, 'optin_callback' ) );
		add_action( 'wp_ajax_e20r_save_settings', array( &$this, 'settings_callback' ) );
		add_action( "wp_ajax_e20r_sequence_clear_cache", array( &$this, "delete_cache" ) );
		
		// Add AJAX handlers for unprivileged admin operations.
		add_action( 'wp_ajax_nopriv_e20r_sequence_add_post', array( &$this, 'unprivileged_ajax_error' ) );
		add_action( 'wp_ajax_nopriv_e20r_sequence_update_post_meta', array( &$this, 'unprivileged_ajax_error' ) );
		add_action( 'wp_ajax_nopriv_e20r_rm_sequence_from_post', array( &$this, 'unprivileged_ajax_error' ) );
		add_action( 'wp_ajax_nopriv_e20r_sequence_rm_post', array( &$this, 'unprivileged_ajax_error' ) );
		add_action( 'wp_ajax_nopriv_e20r_sequence_clear', array( &$this, 'unprivileged_ajax_error' ) );
		add_action( 'wp_ajax_nopriv_e20r_send_notices', array( &$this, 'unprivileged_ajax_error' ) );
		add_action( 'wp_ajax_nopriv_e20r_sequence_save_user_optin', array( &$this, 'unprivileged_ajax_error' ) );
		add_action( 'wp_ajax_nopriv_e20r_save_settings', array( &$this, 'unprivileged_ajax_error' ) );
		
		// Load shortcodes (instantiate the object(s).
		$shortcode_availableOn = new Available_On();
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
		
		$lic      = E20R_License::get_instance();
		$licenses = $lic->getAllLicenses();
		
		error_log( "{$reply}" );
		
		if ( false === stripos( $package, 'e20r_sequence' ) ) {
			return $reply;
		} else {
			
			foreach ( $licenses as $key => $s ) {
				
				if ( false !== stripos( $key, 'e20r_sequence' ) ) {
					
					$license_key = $key;
					
					if ( true === E20R_License::isLicenseActive( $license_key, $package, $reply ) ) {
						return $reply;
					}
				}
				
			}
		}
		
		return null;
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
		
		if ( empty( $this->posts ) ) {
			$this->load_sequence_post();
		}
		
		DBG::log( "find_single_post() - Find post {$post_id}" );
		
		foreach ( $this->posts as $key => $post ) {
			
			if ( ( $post_id == $post->id ) && ( $delay == $post->delay ) ) {
				return $post;
			}
		}
		
		return false;
	}
	
	/**
	 * Sort the two post objects (order them) according to the defined sortOrder
	 *
	 * @return int | bool - The usort() return value
	 *
	 * @access private
	 */
	private function sort_by_delay() {
		
		if ( empty( $this->options->sortOrder ) ) {
			
			DBG::log( 'sort_by_delay(): Need sortOrder option to base sorting decision on...' );
			// $sequence = $this->get_sequence_by_id($a->id);
			if ( $this->sequence_id !== null ) {
				
				DBG::log( 'sort_by_delay(): Have valid sequence post ID saved: ' . $this->sequence_id );
				$this->get_options( $this->sequence_id );
			}
		}
		
		switch ( $this->options->sortOrder ) {
			
			case SORT_DESC:
				DBG::log( 'sort_by_delay(): Sorted in Descending order' );
				krsort( $this->posts, SORT_NUMERIC );
				break;
			default:
				DBG::log( 'sort_by_delay(): undefined or ascending sort order' );
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
	private function sort_posts_by_delay( $a, $b ) {
		
		/*            if ( empty( $this->options->sortOrder) ) {

            DBG::log('sort_posts_by_delay(): Need sortOrder option to base sorting decision on...');
            // $sequence = $this->get_sequence_by_id($a->id);

            if ( $this->sequence_id !== null) {

                DBG::log('sort_posts_by_delay(): Have valid sequence post ID saved: ' . $this->sequence_id);
                $this->get_options( $this->sequence_id );
            }
        }
*/
		switch ( $this->options->sortOrder ) {
			
			case SORT_ASC:
				// DBG::log('sort_posts_by_delay(): Sorting in Ascending order');
				return $this->sort_ascending( $a, $b );
				break;
			
			case SORT_DESC:
				// DBG::log('sort_posts_by_delay(): Sorting in Descending order');
				return $this->sort_descending( $a, $b );
				break;
			
			default:
				DBG::log( 'sort_posts_by_delay(): sortOrder not defined' );
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
		
		list( $aDelay, $bDelay ) = $this->normalize_delay_values( $a, $b );
		// DBG::log('sort_ascending() - Delays have been normalized');
		
		// Now sort the data
		if ( $aDelay == $bDelay ) {
			return 0;
		}
		
		// Ascending sort order
		return ( $aDelay > $bDelay ) ? + 1 : - 1;
		
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
		list( $aDelay, $bDelay ) = $this->normalize_delay_values( $a, $b );
		
		if ( $aDelay == $bDelay ) {
			return 0;
		}
		
		// Descending Sort Order
		return ( $aDelay > $bDelay ) ? - 1 : + 1;
	}
	
	/**
	 * Class auto-loader for this plugin
	 *
	 * @param string $class_name Name of the class to auto-load
	 *
	 * @since  1.0
	 * @access public
	 */
	public static function auto_loader( $class_name ) {
		
		if ( false === stripos( $class_name, 'E20R' ) ) {
			return;
		}
		
		$parts     = explode( '\\', $class_name );
		$name      = strtolower( preg_replace( '/_/', '-', $parts[ ( count( $parts ) - 1 ) ] ) );
		$base_path = plugin_dir_path( __FILE__ );
		$filename  = "class.{$name}.php";
		
		if ( file_exists( "{$base_path}/async-notices/{$filename}" ) ) {
			require_once( "{$base_path}/async-notices/{$filename}" );
		}
		
		if ( file_exists( "{$base_path}/license/{$filename}" ) ) {
			require_once( "{$base_path}/license/{$filename}" );
		}
		
		if ( file_exists( "{$base_path}/licensing/{$filename}" ) ) {
			require_once( "{$base_path}/licensing/{$filename}" );
		}
		
		if ( file_exists( "{$base_path}/shortcodes/{$filename}" ) ) {
			require_once( "{$base_path}/shortcodes/{$filename}" );
		}
		
		if ( file_exists( "{$base_path}/tools/{$filename}" ) ) {
			require_once( "{$base_path}/tools/{$filename}" );
		}
		
		if ( file_exists( "{$base_path}/utilities/{$filename}" ) ) {
			require_once( "{$base_path}/utilities/{$filename}" );
		}
		
		if ( file_exists( "{$base_path}/widgets/{$filename}" ) ) {
			require_once( "{$base_path}/widgets/{$filename}" );
		}
		
		if ( file_exists( "{$base_path}/{$filename}" ) ) {
			require_once( "{$base_path}/{$filename}" );
		}
	}
}
