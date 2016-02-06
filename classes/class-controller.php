<?php
namespace E20R\Sequences\Sequence;

use E20R\Sequences as Sequences;
use E20R\Sequences\Main as Main;
use E20R\Sequences\Sequence as Sequence;
use E20R\Sequences\Tools as Tools;
use E20R\Sequences\Shortcodes as Shortcodes;
use E20R\Tools as E20RTools;
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
class Controller
{
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

    private static $select2_version = '4.0.1';
    private static $seq_post_type = 'pmpro_sequence';

    // private static $transient_option_key = '_transient_timeout_';
    private static $cache_timeout = 10; // In minutes
    private $transient_key = '_e20r_seq_';
    private $expires;
    private $refreshed;

    // Refers to a single instance of this class
    private static $_this = null;

    /**
     * Constructor for the Sequence
     *
     * @param null $id -- The ID of the sequence to initialize
     * @throws \Exception - If the sequence doesn't exist.
     */
    function __construct($id = null)
    {
        if ( null !== self::$_this ) {
            $error_message = sprintf(__("Attempted to load a second instance of a singleton class (%s)", "e20rsequence"),
                                    get_class($this)
                            );

            error_log($error_message);
            wp_die( $error_message);
        }

        self::$_this = $this;

        // Make sure it's not a dummy construct() call - i.e. for a post that doesn't exist.
        if ( ( $id != null ) && ( $this->sequence_id == 0 ) ) {

            $this->sequence_id = $this->get_sequence_by_id( $id ); // Try to load it from the DB

            if ( $this->sequence_id == false ) {
                throw new \Exception(
                    sprintf(
                        __("A Sequence with the specified ID (%s) does not exist on this system", "e20rsequence" ),
                        $id
                    )
                );
            }
        }

        $this->managed_types = apply_filters("e20r-sequence-managed-post-types", array("post", "page") );
        $this->current_metadata_versions = get_option( "pmpro_sequence_metadata_version", array() );

        add_filter( "get_sequence_class_instance", [ $this, 'get_instance' ] );
        add_action("init", array($this, 'load_textdomain'), 1);
    }

    /**
     * Fetch any options for this specific sequence from the database (stored as post metadata)
     * Use default options if the sequence ID isn't supplied*
     *
     * @param int $sequence_id - The Sequence ID to fetch options for
     * @return mixed -- Returns array of options if options were successfully fetched & saved.
     */
    public function get_options( $sequence_id = null )
    {

        // Does the ID differ from the one this object has stored already?
        if ( ! is_null($sequence_id) && ( $this->sequence_id != $sequence_id )) {

            E20RTools\DBG::log('ID defined already but we were given a different sequence ID');
            $this->sequence_id = $sequence_id;
        }
        elseif ( is_null( $sequence_id) && $sequence_id != 0) {

            // This shouldn't be possible... (but never say never!)
            E20RTools\DBG::log("The defined sequence ID is empty so we'll set it to " . $sequence_id);
            $this->sequence_id = $sequence_id;
        }

        $this->refreshed = null;

        // Should only do this once, unless the timeout is past.
        if (is_null($this->expires) ||
            ( !is_null($this->expires) && $this->expires < current_time('timestamp') )) {

            $this->expires = $this->get_cache_expiry($this->sequence_id);
        }

        // Check that we're being called in context of an actual Sequence 'edit' operation
        E20RTools\DBG::log('Loading settings from DB for (' . $this->sequence_id . ') "' . get_the_title($this->sequence_id) . '"');

        $settings = get_post_meta($this->sequence_id, '_pmpro_sequence_settings', true);
        // E20RTools\DBG::log("Settings are now: " . print_r( $settings, true ) );

        // Fix: Offset error when creating a brand new sequence for the first time.
        if ( empty( $settings ) ) {

            $settings = $this->default_options();
            $this->refreshed = null;
        }

        $loaded_options = $settings;
        $options = $this->default_options();

        foreach( $loaded_options as $key => $value ) {

            $options->{$key} =  $value;
        }

        // $this->options = (object) array_replace( (array)$default_options, (array)$loaded_options );
        // E20RTools\DBG::log( "For {$this->sequence_id}: Current: " . print_r( $this->options, true ) );
        $options->loaded = true;

        return $this->options;
    }

    /**
     * Generate a key to identify the transient for a user/sequence combination
     *
     * @param $sequence_id - Id of sequence
     * @return string - The transient key being used.
     */
    private function get_cache_key($sequence_id)
    {
        global $current_user;
        $key = null;

        if ((0 == $current_user->ID && $this->is_cron) ||
            (is_numeric($this->e20r_sequence_user_id) && 0 < $this->e20r_sequence_user_id)) {

            $user_id = $this->e20r_sequence_user_id;
            $key = $this->transient_key . "{$user_id}_{$sequence_id}";
        }

        E20RTools\DBG::log("Cache key: " . (is_null($key) ? 'NULL' : $key));
        return $key;
    }

    /**
     * Fetches the post data for this sequence
     *
     * @param $id -- ID of sequence to fetch data for
     * @return bool | int -- The ID of the sequence or false if unsuccessful
     */
    public function get_sequence_by_id($id)
    {
        $this->sequence = get_post($id);

        if( isset($this->sequence->ID) ) {

            $this->sequence_id = $id;
        }
        else {
            $this->sequence_id = false;
        }

        return $this->sequence_id;
    }


    static public function post_details( $sequence_id, $post_id )
    {
        $seq = apply_filters('get_sequence_class_instance', null);
        $seq->get_options( $sequence_id );

        return $seq->find_by_id( $post_id );
    }

    static public function all_sequences( $statuses = 'publish' )
    {
        $seq = apply_filters('get_sequence_class_instance', null);
        return $seq->get_all_sequences( $statuses );
    }

    static public function sequences_for_post( $post_id )
    {
        $cSequence = apply_filters('get_sequence_class_instance', null);

        return $cSequence->get_sequences_for_post( $post_id );
    }

    /**
      * @return Sequence\Controller $this - Current instance of the class
      * @since 4.0.0
      */
    public function get_instance()
    {
        if (null == self::$_this) {
            self::$_this = new self;
        }

        return self::$_this;
    }

    public function check_conversion()
    {
        $sequences = $this->get_all_sequences();
        $flag = false;

        foreach( $sequences as $sequence ) {

            if ( !$this->is_converted( $sequence->ID ) ) {

                $flag = $sequence->ID;
            }
        }

        if ( $flag ) {

            $this->set_error_msg( sprintf( __( "Required action: Please de-activate and then activate the PMPro Sequences plugin (%d)", "e20rsequence" ), $flag ) );
        }
    }

    /**
     * Returns a list of all defined drip-sequences
     *
     * @param $statuses string|array - Post statuses to return posts for.
     * @return mixed - Array of post objects
     */
    public function get_all_sequences( $statuses = 'publish' ) {

        $query = array(
            'post_type' => 'pmpro_sequence',
            'post_status' => $statuses,
        );

        /* Fetch all Sequence posts - NOTE: Using \WP_Query and not the sequence specific get_posts() function! */
        $all_posts = get_posts( $query );

        wp_reset_query();

        return $all_posts;
    }

    public function is_converted( $sequence_id ) {

        if ( empty( $this->current_metadata_versions ) ) {

            $this->current_metadata_versions = get_option( "pmpro_sequence_metadata_version", array() );
        }

        $is_pre_v3 = get_post_meta( $sequence_id, "_sequence_posts", true );

        if ( ( false === $is_pre_v3 ) || ( isset( $this->current_metadata_versions[$sequence_id] ) && ( 3 == $this->current_metadata_versions[$sequence_id] ) ) ) {

            E20RTools\DBG::log("{$sequence_id} is at v3 format");
            return true;
        }

        if ( wp_is_post_revision( $sequence_id ) )
            return true;

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return true;
        }

        if ( 'trash' == get_post_status( $sequence_id ) ) {
            return true;
        }

        $args = array(
            'posts_per_page' => -1,
            'meta_query' => array(
                    array(
                        'key' => '_pmpro_sequence_post_belongs_to',
                        'value' => $sequence_id,
                        'compare' => '=',
                    ),
                )
            );

        $is_converted = new \WP_Query( $args );
        $options = $this->get_options( $sequence_id );

        if ( $is_converted->post_count >= 1 || $options->loaded === true ) {

            if ( !isset( $this->current_metadata_versions[$sequence_id] ) ) {

                E20RTools\DBG::log( "Sequence # {$sequence_id} is converted already. Updating the settings");
                $this->current_metadata_versions[$sequence_id] = 3;
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

        $e = apply_filters('get_e20rerror_class_instance', null);

        if ( $msg !== null ) {

            E20RTools\DBG::log("set_error_msg(): {$msg}");

            $e->set_error( $msg, 'error', null, 'e20r_seq_errors' );
        }
    }

    public function convert_posts_to_v3( $sequence_id = null, $force = false ) {

        if ( !is_null( $sequence_id ) ) {

            if ( isset( $this->current_metadata_versions[$sequence_id] ) && ( 3 == $this->current_metadata_versions[$sequence_id] ) ) {

                E20RTools\DBG::log("Sequence {$sequence_id} is already converted.");
                return;
            }

            $old_sequence_id = $this->sequence_id;

            if ( false === $force  ) {

                $this->get_options( $sequence_id );
                $this->load_sequence_post();
            }
        }

        $is_pre_v3 = get_post_meta( $this->sequence_id, "_sequence_posts", true );

        E20RTools\DBG::log("Need to convert from old metadata format to new format for sequence {$this->sequence_id}");
        $retval = true;

        if ( !empty( $this->sequence_id ) ) {

            // $tmp = get_post_meta( $sequence_id, "_sequence_posts", true );
            $posts = ( !empty( $is_pre_v3 ) ? $is_pre_v3 : array() ); // Fixed issue where empty sequences would generate error messages.

            foreach( $posts as $sp ) {

                E20RTools\DBG::log("Adding post # {$sp->id} with delay {$sp->delay} to sequence {$this->sequence->id} ");
                $retval = $retval && $this->add_post_to_sequence( $this->sequence_id, $sp->id, $sp->delay );
            }

            // E20RTools\DBG::log("Saving to new V3 format... ", E20R_DEBUG_SEQ_WARNING );
            // $retval = $retval && $this->save_sequence_post();

            E20RTools\DBG::log("Removing old format meta... ", E20R_DEBUG_SEQ_WARNING );
            $retval = $retval && delete_post_meta( $this->sequence_id, "_sequence_posts" );
        }
        else {

            $retval = false;
            $this->set_error_msg( __("Cannot convert to V3 metadata format: No sequences were defined.", "e20rsequence" ) );
        }

        if ( $retval == true ) {

            E20RTools\DBG::log("Successfully converted to v3 metadata format for all sequence member posts");
            $this->current_metadata_versions[$this->sequence_id] = 3;
            update_option( "pmpro_sequence_metadata_version", $this->current_metadata_versions );

            // Reset sequence info.
            $this->get_options( $old_sequence_id );
            $this->load_sequence_post();

        }
        else {
            $this->set_error_msg( sprintf( __( "Unable to upgrade post metadata for sequence (%s)", "e20rsequence") , get_the_title( $this->sequence_id ) ) );
        }

        return $retval;
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
     *                          3 = At midnight at least 24 hours after the membership started. I.e. Start at 3am on 12/1,
     *                              Day 1 starts at midnight on 12/3
     *      sendNotice (bool) - Whether to allow alert notices (emails)
     *      noticeTemplate (string) - The filename for the template to use in the message(s)
     *      noticeTime (string) - Text representation (in 24 hour clock format) of when to send the notice
     *      noticeTimestamp (int)   - The timestamp used to schedule the cron job for the notice processing
     *      excerpt_intro (string) - The introductory text used before the message (page/post) excerpt.
     *
     * @return array -- Default options for the sequence
     * @access public
     */
    public function default_options() {

        $settings = new \stdClass();

        $admin = get_user_by('email', get_option('admin_email'));

        if (!isset($admin->user_email)) {

            // Default object to avoid warning notices
            $admin = new \stdClass();
            $admin->user_email = 'nobody@example.com';
            $admin->display_name = 'Not Applicable';
        }

        $settings->loaded = false;
        $settings->hideFuture =  0; // 'hidden' (Show them)
        $settings->lengthVisible = 1; //'lengthVisible'
        $settings->sortOrder = SORT_ASC; // 'sortOrder'
        $settings->delayType = 'byDays'; // 'delayType'
        $settings->allowRepeatPosts = false; // Whether to allow a post to be repeated in the sequence (with different delay values)
        $settings->showDelayAs = E20R_SEQ_AS_DAYNO; // How to display the time until available
        $settings->previewOffset = 0; // How many days into the future the sequence should allow somebody to see.
        $settings->startWhen =  0; // startWhen == immediately (in current_time('timestamp') + n seconds)
        $settings->sendNotice = 1; // sendNotice == Yes
        $settings->noticeTemplate = 'new_content.html'; // Default plugin template
        $settings->noticeSendAs = E20R_SEQ_SEND_AS_SINGLE; // Send the alert notice as one notice per message.
        $settings->noticeTime = '00:00'; // At Midnight (server TZ)
        $settings->noticeTimestamp = current_time('timestamp'); // The current time (in UTC)
        $settings->excerptIntro = __('A summary of the post follows below:', "e20rsequence");
        $settings->replyto = apply_filters('e20r-sequence-default-sender-email', $admin->user_email); // << Update Name
        $settings->fromname = apply_filters('e20r-sequence-default-sender-name', $admin->display_name); // << Updated Name!
        $settings->subject = __('New Content ', "e20rsequence");
        $settings->dateformat = __('m-d-Y', "e20rsequence"); // Using American MM-DD-YYYY format. << Updated name!
        $settings->trackGoogleAnalytics = false; // Whether to use Google analytics to track message open operations or not
        $settings->gaTid = null; // The Google Analytics ID to use (TID)

        $this->options = $settings; // Save as options for this sequence

        return $settings;
    }

    public function get_option_by_name( $option )
    {
        if ( ! isset($this->options->{$option} ) ) {

            return false;
        }

        return $this->options->{$option};
    }

    public function set_option_by_name( $option, $value )
    {
        $this->options->{$option} = $value;
    }


    private function get_cache_expiry($sequence_id) {

        E20RTools\DBG::log("Loading cache timeout value for {$sequence_id}");

        global $wpdb;
        $expires = null;

        $key = $this->get_cache_key($sequence_id);

        if (!is_null($key)) {

            $sql = $wpdb->prepare("
                SELECT option_value
                    FROM {$wpdb->options}
                    WHERE option_name = %s
            ",
            "_transient_timeout_{$key}"
            );

            $expires = $wpdb->get_var($sql);
        }

        E20RTools\DBG::log("Loaded cache timeout value for {$sequence_id}: " .( is_null($expires) ? "NULL" : "{$expires}"));

        return $expires;
    }

    private function is_cache_valid() {

        E20RTools\DBG::log("We have " . count($this->posts) . " posts in the post list" );

        $this->expires = $this->get_cache_expiry($this->sequence_id);

        if ( empty( $this->posts ) || is_null($this->expires)) {

            E20RTools\DBG::log( "Cache is INVALID");
            return false;
        }

        E20RTools\DBG::log("Current refresh value: {$this->expires} vs " . current_time('timestamp', true) );

        if ( ( $this->expires  >= current_time( 'timestamp' ) ) /* && !empty( $this->posts ) */) {

            E20RTools\DBG::log( "Cache IS VALID.");
            return true;
        }

        if ( empty($this->posts) && $this->expires >= current_time('timestamp')) {
            E20RTools\DBG::log( "No data in list, but cache IS VALID.");
            return true;
        }

        E20RTools\DBG::log( "Cache is INVALID");
        return false;
    }

    public function delete_cache( $sequence_id = null ) {

		$direct_operation = false;
        $status = false;

		if ( empty($sequence_id) && (isset( $_POST['e20r_sequence_id']) || isset($_POST['e20r_sequence_post_nonce'])) ) {

			E20RTools\DBG::log("Attempting to clear cache during AJAX operation");
			$direct_operation = true;

			wp_verify_nonce("e20r-sequence-post", "e20r_sequence_post_nonce");
			$sequence_id = isset($_POST['e20r_sequence_id']) ? intval($_POST['e20r_sequence_id']) : null;

			if (is_null($sequence_id)) {
				wp_send_json_error( array( array( 'message' => __("No sequence ID specified. Can't clear cache!", "e20rsequence"))));
				wp_die();
			}
		}

        $key = $this->get_cache_key($sequence_id);

        E20RTools\DBG::log("Removing old/stale cache data for {$sequence_id}");
        $this->expires = null;

        if (!is_null($key)) {
            $status = delete_transient( $key );
        }

        if( (false === $status) && (true === $direct_operation) &&
            ( isset( $_POST['e20r_sequence_id']) || isset($_POST['e20r_sequence_post_nonce']) )) {
            wp_send_json_error( array( array('message' => __("No cache to clear, or unable to clear the cache", "e20rsequence"))));
            wp_die();
        }

        if ((true === $status) && (true === $direct_operation) &&
            (isset( $_POST['e20r_sequence_id']) || isset($_POST['e20r_sequence_post_nonce']) )) {

            wp_send_json_success();
            wp_die();
        }

        return $status;
        // return wp_cache_delete( $key, $group);
    }

    private function set_cache( $sequence_posts, $sequence_id ) {

        $success = false;

        // $this->delete_cache( $this->transient_key . $sequence_id);

        E20RTools\DBG::log("Saving data to cache for {$sequence_id}...");
        $key = $this->get_cache_key($sequence_id);

        if (!is_null($key)) {
            $success = set_transient( $key, $sequence_posts, self::$cache_timeout * MINUTE_IN_SECONDS);
        }

        if (true === $success) {
            $this->expires = $this->get_cache_expiry($sequence_id);
            E20RTools\DBG::log("Cache set to expire: {$this->expires}");
        } else {
            E20RTools\DBG::log("Unable to update the cache for {$sequence_id}!");
            $this->expires = null;
        }

        return $success;
        // wp_cache_set( $key, $value );
    }

    private function get_cache( $sequence_id ) {

        E20RTools\DBG::log("Loading from cache for {$sequence_id}...");

        $cache = false;

        if ( $this->expires > current_time( 'timestamp' ) &&
            ( date('N', current_time('timestamp')) !== date('N', $this->expires)) )
        {
            E20RTools\DBG::log("The timestamps takes us across the local dateline, so clear the cache...");

			// We're crossing over the day threshold, so flush the cache and return false
            $this->expires = -1;
            return false;
        }

		$this->expires = $this->get_cache_expiry($sequence_id);
		$key = $this->get_cache_key($sequence_id);

        if (!is_null($key)) {
            $cache = get_transient( $key );
        }

        return $cache;
        // $cached_value = wp_cache_get( $key, $group, $force, $found );
    }

    /**
      * @param $user_id -- The user ID to find the startdate for
      * @return mixed|void  - A UNIX timestamp (seconds since start of epoch)
      */
    private function get_user_startdate($user_id = null, $level_id = null)
    {
        $startdate = strtotime('today ' . get_option('timezone_string'));

        // TODO: Split into pmpro_getMemberStartdate call & return into own module w/e20r-sequence-user-startdate filter
        if (function_exists('pmpro_getMemberStartdate')) {
            $startdate = pmpro_getMemberStartdate( $user_id, $level_id );
        }

        return apply_filters('e20r-sequence-user-startdate', $startdate, $user_id, $level_id);
    }

    public function load_sequence_post( $sequence_id = null, $delay = null, $post_id = null, $comparison = '=', $pagesize = null, $force = false, $status = 'default' ) {

        global $current_user;
        global $loading_sequence;

        $find_by_delay = false;
        $found = array();

        if ( !is_null( $this->e20r_sequence_user_id )  && ( $this->e20r_sequence_user_id != $current_user->ID ) ) {

            E20RTools\DBG::log("Using user id from e20r_sequence_user_id: {$this->e20r_sequence_user_id}");
            $user_id = $this->e20r_sequence_user_id;
        }
        else {
            E20RTools\DBG::log("Using user id (from current_user): {$current_user->ID}");
            $user_id = $current_user->ID;
        }

        if ( is_null( $sequence_id ) && ( !empty( $this->sequence_id ) ) ) {
            E20RTools\DBG::log("No sequence ID specified in call. Using default value of {$this->sequence_id}");
            $sequence_id = $this->sequence_id;
        }

        if ( empty( $sequence_id ) ) {

            E20RTools\DBG::log( "No sequence ID configured. Returning error (null)", E20R_DEBUG_SEQ_WARNING );
            return null;
        }

        if ( !empty( $delay ) ) {

            $data_type = 'NUMERIC';

            if ( $this->options->delayType == 'byDate' ) {

                E20RTools\DBG::log("Expected delay value is a 'date' so need to convert");
                $startdate = $this->get_user_startdate($user_id);

                $delay = date('Y-m-d', ($startdate + ($delay * DAY_IN_SECONDS)) );
                $data_type = 'DATE';
            }

            E20RTools\DBG::log("Using delay value: {$delay}");
            $find_by_delay = true;
        }

        E20RTools\DBG::log("Sequence ID var: " . ( empty($sequence_id) ? 'Not defined' : $sequence_id ) );
        E20RTools\DBG::log("Force var: " . ( ($force === false ) ? 'Not defined' : 'True' ) );
        E20RTools\DBG::log("Post ID var: " . ( is_null($post_id) ? 'Not defined' : $post_id ) );

        if ( ( false === $force ) && empty($post_id) && (false !== ($found = $this->get_cache( $sequence_id )))) {

            E20RTools\DBG::log("Loaded post list for sequence # {$sequence_id} from cache. " . count($found) . " entries");
            $this->posts = $found;
            return $this->posts;
        }

        E20RTools\DBG::log("Delay var: " . ( empty($delay) ? 'Not defined' : $delay ) );
        E20RTools\DBG::log("Comparison var: {$comparison}" );
        E20RTools\DBG::log("Page size ID var: " . ( empty($pagesize) ? 'Not defined' : $pagesize ) );
		E20RTools\DBG::log("have to refresh data...");

        // $this->refreshed = current_time('timestamp', true);
        $this->refreshed = null;
        $this->expires = -1;

        /**
         * Expected format: array( $key_1 => stdClass $post_obj, $key_2 => stdClass $post_obj );
         * where $post_obj = stdClass  -> id
         *                   stdClass  -> delay
         */
        $order_by = $this->options->delayType == 'byDays' ? 'meta_value_num' : 'meta_value';
        $order = $this->options->sortOrder == SORT_DESC ? 'DESC' : 'ASC';

        if ( ( $status == 'default') && ( !is_null( $post_id ) ) ) {

            $statuses = apply_filters( 'e20r-sequence-allowed-post-statuses', array( 'publish', 'future', 'draft', 'private' ) );
        }
        elseif ( $status == 'default' ) {

            $statuses = apply_filters( 'e20r-sequence-allowed-post-statuses', array( 'publish', 'future', 'private' ) );
        }
        else {

            $statuses = apply_filters( 'e20r-sequence-allowed-post-statuses', $status );
        }

        E20RTools\DBG::log("Loading posts with status: " . print_r($statuses, true));

        if ( is_null( $post_id ) ) {

            E20RTools\DBG::log("No post ID specified. Loading posts....");

            $args = array(
                'post_type' => apply_filters( 'e20r-sequence-managed-post-types', array( 'post', 'page' ) ),
                'post_status' => $statuses,
                'posts_per_page' => -1,
                'orderby' => $order_by,
                'order' => $order,
                'meta_key' => "_pmpro_sequence_{$sequence_id}_post_delay",
                'meta_query' => array(
                    array(
                        'key' => '_pmpro_sequence_post_belongs_to',
                        'value' => $sequence_id,
                        'compare' => '=',
                    ),
                )
            );
        }
        else {

            E20RTools\DBG::log("Post ID specified so we'll only search for post #{$post_id}");

            $args = array(
                'post_type' => apply_filters( 'e20r-sequence-managed-post-types', array( 'post', 'page' ) ),
                'post_status' => $statuses,
                'posts_per_page' => -1,
                'order_by' => $order_by,
                'p' => $post_id,
                'order' => $order,
                'meta_key' => "_pmpro_sequence_{$sequence_id}_post_delay",
                'meta_query' => array(
                    array(
                        'key' => '_pmpro_sequence_post_belongs_to',
                        'value' => $sequence_id,
                        'compare' => '=',
                    ),
                )
            );
        }

        if ( !is_null( $pagesize )  ) {

            E20RTools\DBG::log("Enable paging, grab page #: " . get_query_var( 'page' ) );

            $page_num = ( get_query_var( 'page' ) ) ? absint( get_query_var( 'page' ) ) : 1;
        }

        if ( $find_by_delay ) {
			E20RTools\DBG::log("Requested look-up by delay value {$delay} in sequence {$sequence_id}");
            $args['meta_query'][] = array(
                    'key' => "_pmpro_sequence_{$sequence_id}_post_delay",
                    'value' => $delay,
                    'compare' => $comparison,
                    'type' => $data_type
            );
        }

        // E20RTools\DBG::log("Args for \WP_Query(): ");
        // E20RTools\DBG::log($args);

        $posts = new \WP_Query( $args );

        E20RTools\DBG::log("Loaded {$posts->post_count} posts from wordpress database for sequence {$sequence_id}");

        if ( ( 0 === $posts->post_count ) && is_null( $pagesize ) && ( is_null( $post_id ) ) ) {

            E20RTools\DBG::log("Didn't find any posts. Checking if we need to convert...?");

            if ( !$this->is_converted( $sequence_id ) ) {

                E20RTools\DBG::log("Forcing conversion attempt for sequence # {$sequence_id}");
                $this->convert_posts_to_v3( $sequence_id, true );
            }
        }

        $is_admin = user_can( $user_id, 'manage_options');

        $member_days = ( $is_admin || ( is_admin() && ( $this->is_cron == false ) ) ) ? 9999 : $this->get_membership_days( $user_id );

        E20RTools\DBG::log("User {$user_id} has been a member for {$member_days} days. Admin? " . ( $is_admin ? 'Yes' : 'No') );

        $post_list = $posts->get_posts();

        wp_reset_postdata();

        foreach( $post_list as $k => $sPost ) {

            E20RTools\DBG::log("Loading metadata for post #: {$sPost->ID}");

            $id = $sPost->ID;

            $tmp_delay = get_post_meta( $id, "_pmpro_sequence_{$sequence_id}_post_delay" );

            $is_repeat = false;

            // Add posts for all delay values with this post_id
            foreach( $tmp_delay as $p_delay ) {

                $p = new \stdClass();

                $p->id = $id;
                // BUG: Doesn't work because you could have multiple post_ids released on same day: $p->order_num = $this->normalize_delay( $p_delay );
                $p->delay = isset( $sPost->delay ) ? $sPost->delay : $p_delay;
                $p->permalink = get_permalink( $sPost->ID );
                $p->title = $sPost->post_title;
                $p->excerpt = $sPost->post_excerpt;
                $p->closest_post = false;
                $p->current_post = false;
                $p->is_future = false;
                $p->type = $sPost->post_type;

                // E20RTools\DBG::log("Configured delay value: {$p->delay}. Normalized delay: " . $this->normalize_delay( $p->delay ));
                // Only add posts to list if the member is supposed to see them
                if ( $member_days >= $this->normalize_delay( $p->delay ) ) {

                    // E20RTools\DBG::log("Adding {$p->id} ({$p->title}) with delay {$p->delay} to list of available posts");
                    $p->is_future = false;
                    $found[] = $p;
                }
                else {

                    // Or if we're not supposed to hide the upcomping posts.

                    if ( !$this->hide_upcoming_posts() ) {

                        E20RTools\DBG::log("Loading {$p->id} with delay {$p->delay} to list of upcoming posts");
                        $p->is_future = true;
                        $found[] = $p;
                    }
                    else {
                        E20RTools\DBG::log("Ignoring post {$p->id} with delay {$p->delay} to sequence list for {$sequence_id}");
                        if ( !is_null( $pagesize ) ) {

                            unset( $post_list[ $k ] );
                        }
                    }
                }
            } // End of foreach for delay values

            $is_repeat = false;
        } // End of foreach for post_list

        E20RTools\DBG::log("Found " . count( $found ) . " posts for sequence {$sequence_id} and user {$user_id}");

        if ( is_null( $post_id ) && is_null( $delay ) && !empty( $post_list ) ) {

			E20RTools\DBG::log("Preparing array of posts to return to calling function");

            $this->posts = $found;

            // Default to old _sequence_posts data
            if ( 0 == count( $this->posts ) ) {

                E20RTools\DBG::log("No posts found using the V3 meta format. Reverting... ", E20R_DEBUG_SEQ_WARNING );

                $tmp = get_post_meta( $this->sequence_id, "_sequence_posts", true );
                $this->posts = ( $tmp ? $tmp : array() ); // Fixed issue where empty sequences would generate error messages.

                E20RTools\DBG::log("Saving to new V3 format... ", E20R_DEBUG_SEQ_WARNING );
                $this->save_sequence_post();

                E20RTools\DBG::log("Removing old format meta... ", E20R_DEBUG_SEQ_WARNING );
                delete_post_meta( $this->sequence_id, "_sequence_posts" );
            }

            E20RTools\DBG::log("Identify the closest post for {$user_id}");
            $this->posts = $this->set_closest_post( $this->posts, $user_id );

            E20RTools\DBG::log("Have " . count( $this->posts )  ." posts we're sorting");
            usort( $this->posts, array( $this, "sort_posts_by_delay" ));
/*
            E20RTools\DBG::log("Have " . count( $this->upcoming )  ." upcoming/future posts we need to sort");
            if (!empty( $this->upcoming ) ) {

                usort( $this->upcoming, array( $this, "sort_posts_by_delay" ) );
            }
*/

            E20RTools\DBG::log("Will return " . count( $found ) . " sequence members and refreshing cache for {$sequence_id}");
            $this->set_cache($this->posts, $sequence_id);

            if ( is_null( $pagesize ) ) {

				E20RTools\DBG::log("Returning non-paginated list");
                return $this->posts;
            }
            else {

				E20RTools\DBG::log("Preparing paginated list");
                if ( !empty( $this->upcoming ) ) {

                    E20RTools\DBG::log("Appending the upcoming array to the post array. posts =  " . count( $this->posts ) . " and upcoming = " . count( $this->upcoming ) );
                    $this->posts = array_combine( $this->posts, $this->upcoming );
                    E20RTools\DBG::log("Joined array contains " . count ($this->posts ) . " total posts");
                }

                $paged_list = $this->paginate_posts( $this->posts, $pagesize, $page_num );

                // Special processing since we're paginating.
                // Make sure the $delay value is > first element's delay in $page_list and < last element

                list( $min, $max ) = $this->set_min_max( $pagesize, $page_num, $paged_list );

                E20RTools\DBG::log("Check max / min delay values for paginated set. Max: {$max}, Min: {$min}");

                foreach( $paged_list as $k => $p ) {

                    E20RTools\DBG::log("Checking post key {$k} (post: {$p->id}) with delay {$p->delay}");

                    if ( $p->delay < $min ) {

                        E20RTools\DBG::log("removing post entry {$k} -> ({$p->delay}) because its delay value is less than min for the listing" );

                        unset( $paged_list[$k] );
                    }
                    elseif ( $p->delay > $max ) {

                        E20RTools\DBG::log("removing post entry {$k} -> ({$p->delay}) because its delay value is greater than max for the listing" );
                        unset( $paged_list[$k] );

                    }
                }

                E20RTools\DBG::log("Returning the \\WP_Query result to process for pagination.");
                return array( $paged_list, $posts->max_num_pages );
            }

        }
        else {
            E20RTools\DBG::log("Returning list of posts (size: ". count( $found ) . " ) located by specific post_id: {$post_id}");
            return $found;
        }
    }

    /**
     * Return the number of days since this users membership started
     *
     * @param null|int $user_id -- ID of the user (can be NULL)
     * @param int $level_id -- The ID of the level we're checking gainst.
     *
     * @return int - number of days (decimal, possibly).
     */
    public function get_membership_days( $user_id = NULL, $level_id = 0 ) {

        $days = 0;

        if(empty($user_id))
        {
            global $current_user;
            $user_id = $current_user->ID;
        }

        $startdate = $this->get_user_startdate( $user_id, $level_id );
        $tz = get_option('timezone_string');

        //check we received a start date
        if (empty($startdate)) {

            $stardate = strtotime('today ' . $tz );
        }

        $now = current_time("timestamp");
        $days = $this->datediff( $startdate, $now, $tz );

/*
        if (function_exists('pmpro_getMemberDays')) {
            $days = pmpro_getMemberDays($user_id, $level_id);
        }
*/
        return apply_filters('e20r-sequence-days-as-member', $days, $user_id, $level_id);
    }

    /**
     * Calculates the difference between two dates (specified in UTC seconds)
     *
     * @param $startdate (timestamp) - timestamp value for start date
     * @param $enddate (timestamp) - timestamp value for end date
     * @return int
     */
    private function datediff( $startdate, $enddate = null, $tz = 'UTC' ) {

        $days = 0;

        E20RTools\DBG::log("Timezone: {$tz}");

        // use current day as $enddate if nothing is specified
        if ( ( is_null( $enddate ) ) && ( $tz == 'UTC') ) {

            $enddate = current_time( 'timestamp', true );
        }
        elseif ( is_null( $enddate ) ) {

            $enddate = current_time( 'timestamp' );
        }

        // Create two DateTime objects
        $dStart = new \DateTime( date( 'Y-m-d', $startdate ), new \DateTimeZone( $tz ) );
        $dEnd   = new \DateTime( date( 'Y-m-d', $enddate ), new \DateTimeZone( $tz ) );

        if ( version_compare( PHP_VERSION, E20R_SEQ_REQUIRED_PHP_VERSION, '>=' ) ) {

            /* Calculate the difference using 5.3 supported logic */
            $dDiff  = $dStart->diff( $dEnd );
            $dDiff->format( '%d' );
            //$dDiff->format('%R%a');

            $days = $dDiff->days;

            // Invert the value
            if ( $dDiff->invert == 1 )
                $days = 0 - $days;
        }
        else {

            // V5.2.x workaround
            $dStartStr = $dStart->format('U');
            $dEndStr = $dEnd->format('U');

            // Difference (in seconds)
            $diff = abs($dStartStr - $dEndStr);

            // Convert to days.
            $days = $diff * 86400; // Won't manage DST correctly, but not sure that's a problem here..?

            // Sign flip if needed.
            if ( gmp_sign($dStartStr - $dEndStr) == -1)
                $days = 0 - $days;
        }

        return $days + 1;
    }

    /**
     * Convert any date string to a number of days worth of delay (since membership started for the current user)
     *
     * @param $delay (int | string) -- The delay value (either a # of days or a date YYYY-MM-DD)
     * @return mixed (int) -- The # of days since membership started (for this user)
     *
     * @access public
     */
    public function normalize_delay( $delay ) {

        if ( $this->is_valid_date( $delay ) ) {

            return $this->convert_date_to_days($delay);
        }

        return $delay;
    }

    /**
     * Pattern recognize whether the data is a valid date format for this plugin
     * Expected format: YYYY-MM-DD
     *
     * @param $data -- Data to test
     * @return bool -- true | false
     *
     * @access private
     */
    private function is_valid_date( $data )
    {
        // Fixed: is_valid_date() needs to support all expected date formats...
        if ( false === strtotime( $data ) ) {

            return false;
        }

        return true;
    }

    /********************************* Add/Remove sequence posts *****************************/

    /**
     * Returns a number of days since the users membership started based on the supplied date.
     * This allows us to mix sequences containing days since membership start and fixed dates for content drips
     *
     * @param $date - Take a date in the format YYYY-MM-DD and convert it to a number of days since membership start (for the current member)
     * @param $userId - Optional ID for the user being processed
     * @param $levelId - Optional ID for the level of the user
     * @return mixed -- Return the # of days calculated
     *
     * @access public
     */
    public function convert_date_to_days( $date, $userId = null, $levelId = null ) {

        $days = 0;

        if ( null == $userId ) {

            if ( !empty ( $this->e20r_sequence_user_id ) ) {

                $userId = $this->e20r_sequence_user_id;
            }
            else {

                global $current_user;

                $userId = $current_user->ID;
            }
        }

        if ( null == $levelId ) {

            if ( !empty( $this->e20r_sequence_user_level ) ) {

                $levelId = $this->e20r_sequence_user_level;
            }
            else {

                $level = $this->get_membership_level_for_user($userId);

                if ( is_object( $level )) {
                    $levelId = $level->id;
                } else {
                    $levelId = $level;
                }

            }
        }

        // Return immediately if the value we're given is a # of days (i.e. an integer)
        if ( is_numeric( $date ) ) {
            return $date;
        }

        E20RTools\DBG::log($levelId);

        if ( $this->is_valid_date( $date ) )
        {
            // E20RTools\DBG::log("Using {$userId} and {$levelId} for the credentials");
            $startDate = $this->get_user_startdate( $userId, $levelId ); /* Needs userID & Level ID ... */

            if ( empty( $startDate ) && ( current_user_can('manage_options'))) {

                $startDate = strtotime( "2013-01-01" );
            }
            elseif ( empty( $startDate ) ) {

                $startDate = strtotime( "tomorrow" );
            }

            E20RTools\DBG::log("Given date: {$date} and startdate: {$startDate} for user {$userId} with level {$levelId}");

            try {

                // Use PHP v5.2 and v5.3 compatible function to calculate difference
                $compDate = strtotime( "{$date} 00:00:00" );
                $days = $this->datediff( $startDate, $compDate ); // current_time('timestamp')

            } catch (Exception $e) {
                E20RTools\DBG::log('Error calculating days: ' . $e->getMessage());
            }
        }

        return $days;
    }

    /**
      * Return a membership level object (stdClass/wpdb row) containing minimally an 'id' parameter
      * Could also simply return false or null if the user doesn't have a level.
      *
      * @param int|null $user_id - Id of user (or null)
      * @param bool $force - Whether to force refresh from a (possible) database table
      * @return mixed|void - Object containing the level information (including an 'id' parameter.
      */
    private function get_membership_level_for_user($user_id = null, $force = false) {

        $level = false;

        if (function_exists('pmpro_getMembershipLevelForUser')) {
            $level = pmpro_getMembershipLevelForUser( $user_id, $force );
        }

        return apply_filters('e20r-sequence-membership-level-for-user', $level, $user_id, $force);
    }

    /**
     * Test whether to show future sequence posts (i.e. not yet available to member)
     *
     * @return bool -- True if the admin has requested that unavailable posts not be displayed.
     *
     * @access public
     */
    public function hide_upcoming_posts()
    {
        // E20RTools\DBG::log('hide_upcoming_posts(): Do we show or hide upcoming posts?');
        return ( $this->options->hideFuture == 1 ? true : false );
    }

    /**
      * Save post specific metadata to indicate sequence & delay value(s) for the post.
      *
      * @param null $sequence_id - The sequence to save data for.
      * @param null $post_id - The ID of the post to save metadata for
      * @param null $delay - The delay value
      * @return bool - True/False depending on whether the save operation was a success or not.
      * @since v3.0
      *
      */
    public function save_sequence_post( $sequence_id = null, $post_id = null, $delay = null ) {

        if ( is_null( $post_id ) && is_null( $delay ) && is_null( $sequence_id ) ) {

            // Save all posts in $this->posts array to new V3 format.

            foreach( $this->posts as $p_obj ) {

                if ( !$this->add_post_to_sequence( $this->sequence_id, $p_obj->id, $p_obj->delay ) ) {

                    E20RTools\DBG::log("Unable to add post {$p_obj->id} with delay {$p_obj->delay} to sequence {$this->sequence_id}", E20R_DEBUG_SEQ_WARNING );
                    return false;
                }
            }

            return true;
        }

        if ( !is_null( $post_id ) && !is_null($delay) ) {

            if ( empty( $sequence_id ) ) {

                $sequence_id = $this->sequence_id;
            }

            E20RTools\DBG::log("Saving post {$post_id} with delay {$delay} to sequence {$sequence_id}");
            return $this->add_post_to_sequence( $sequence_id, $post_id, $delay );
        }
        else {
            E20RTools\DBG::log("Need both post ID and delay values to save the post to sequence {$sequence_id}", E20R_DEBUG_SEQ_WARNING );
            return false;
        }
    }

    /**
      * Private function to do the heavy lifting for the sequence specific metadata saves (per post)
      * @param $sequence_id
      * @param $post_id
      * @param $delay
      * @return bool
      */
    private function add_post_to_sequence( $sequence_id, $post_id, $delay ) {

        global $current_user;

        E20RTools\DBG::log("Adding post {$post_id} to sequence {$sequence_id} using v3 meta format");

		$found_post = $this->is_present( $post_id, $delay );
/*
        if ($this->is_present( $post_id, $delay )) {

            E20RTools\DBG::log("Post {$post_id} with delay {$delay} is already present in sequence {$sequence_id}");
            $this->set_error_msg( __( 'That post and delay combination is already included in this sequence', "e20rsequence" ) );
            return true;
        }
*/
		E20RTools\DBG::log("The post was not found in the current list of posts for {$sequence_id}");

        $posts = $this->find_by_id( $post_id, $sequence_id );

        if ( (count($posts) > 0 && false === $this->allow_repetition()) || true === $found_post) {

            E20RTools\DBG::log("Post is a duplicate and we're not allowed to add duplicates");
            $this->set_error_msg( sprintf( __("Warning: '%s' does not allow multiple delay values for a single post ID", "e20rsequence"), get_the_title( $sequence_id ) ) );

            foreach ( $posts as $p ) {

                E20RTools\DBG::log("add_post_to_sequence(): Delay is different & we can't have repeat posts. Need to remove existing instances of {$post_id} and clear any notices");
                $this->remove_post( $p->id, $p->delay, true );
            }
        }

        if ( is_admin() ) {

            $member_days = -1;
        }
        else {

            $member_days = $this->get_membership_days( $current_user->ID );
        }

		E20RTools\DBG::log("Loading post {$post_id} from DB using WP_Query");
        $tmp = new \WP_Query( array(
            'p' => $post_id,
            'post_type' => apply_filters( 'e20r-sequence-managed-post-types', array( 'post', 'page' )),
            'posts_per_page' => 1,
            'post_status' =>  apply_filters( 'e20r-sequence-allowed-post-statuses', array( 'publish', 'future', 'private' ) )
            )
        );

		$p = $tmp->get_posts();

		E20RTools\DBG::log("Loaded " . count($p) . " posts with WP_Query");

        $new_post = new \stdClass();
        $new_post->id = $p[0]->ID;

        $new_post->delay = $delay;
        // $new_post->order_num = $this->normalize_delay( $delay ); // BUG: Can't handle repeating delay values (ie. two posts with same delay)
        $new_post->permalink = get_permalink($new_post->id);
        $new_post->title = get_the_title($new_post->id);
        $new_post->is_future = ( $member_days < $delay ) && ( $this->hide_upcoming_posts() )  ? true : false;
        $new_post->current_post = false;
        $new_post->type = get_post_type($new_post->id);

        $belongs_to = get_post_meta( $new_post->id, "_pmpro_sequence_post_belongs_to" );

        wp_reset_postdata();

        E20RTools\DBG::log("Found the following sequences for post {$new_post->id}: " . (false === $belongs_to ? 'Not found' : null ) );
        E20RTools\DBG::log($belongs_to);

        if ( ( false === $belongs_to) || (is_array($belongs_to) && !in_array( $sequence_id, $belongs_to)) ) {

            if ( false === add_post_meta( $post_id, "_pmpro_sequence_post_belongs_to", $sequence_id ) ) {
                E20RTools\DBG::log("Unable to add/update this post {$post_id} for the sequence {$sequence_id}");
            }
        }

        E20RTools\DBG::log("Attempting to add delay value {$delay} for post {$post_id} to sequence: {$sequence_id}");

        if ( !$this->allow_repetition() ) {
            // TODO: Need to check if the meta/value combination already exists for the post ID.
            if ( false === add_post_meta( $post_id, "_pmpro_sequence_{$sequence_id}_post_delay", $delay, true ) ) {

                E20RTools\DBG::log("add_post_to_sequenece() - Couldn't add {$post_id} with delay {$delay}. Attempting update operation" );

                if ( false === update_post_meta( $post_id, "_pmpro_sequence_{$sequence_id}_post_delay", $delay ) ) {
                    E20RTools\DBG::log("Both add and update operations for {$post_id} in sequence {$sequence_id} with delay {$delay} failed!", E20R_DEBUG_SEQ_WARNING);
                }
            }

        }
        else {

            $delays = get_post_meta( $post_id, "_pmpro_sequence_{$sequence_id}_post_delay" );

            E20RTools\DBG::log("Checking whether the '{$delay}' delay value is already recorded for this post: {$post_id}");

            if ( ( false === $delays ) || ( !in_array( $delay, $delays ) ) ) {

                E20RTools\DBG::log( "add_post_to_seuqence() - Not previously added. Now adding delay value meta ({$delay}) to post id {$post_id}");
                add_post_meta( $post_id, "_pmpro_sequence_{$sequence_id}_post_delay", $delay );
            }
            else {
                E20RTools\DBG::log("Post # {$post_id} in sequence {$sequence_id} is already recorded with delay {$delay}");
            }
        }

        if ( false === get_post_meta( $post_id, "_pmpro_sequence_post_belongs_to" ) ) {

            E20RTools\DBG::log("Didn't add {$post_id} to {$sequence_id}", E20R_DEBUG_SEQ_WARNING );
            return false;
        }

        if ( false === get_post_meta( $post_id, "_pmpro_sequence_{$sequence_id}_post_delay" ) ) {

            E20RTools\DBG::log("Couldn't add post/delay value(s) for {$post_id}/{$delay} to {$sequence_id}", E20R_DEBUG_SEQ_WARNING );
            return false;
        }

		// If we shoud be allowed to access this post.
        if ( $this->has_post_access( $current_user->ID, $post_id, false, $sequence_id ) ||
           false === $new_post->is_future ||
          (( true === $new_post->is_future) && false === $this->hide_upcoming_posts()) ) {

            E20RTools\DBG::log("Adding post to sequence: {$sequence_id}");
            $this->posts[] = $new_post;
        }
        else {

            E20RTools\DBG::log("User doesn't have access to the post so not adding it.");
            $this->upcoming[] = $new_post;
        }

        usort( $this->posts, array( $this, 'sort_posts_by_delay' ) );

        if ( !empty( $this->upcoming ) ) {

            usort( $this->upcoming, array( $this, 'sort_posts_by_delay' ) );
        }

        $this->set_cache($this->posts, $sequence_id);

        return true;
    }

    public function is_present ( $post_id, $delay ) {

        E20RTools\DBG::log("Checking whether post {$post_id} with delay {$delay} is already included in {$this->sequence_id}");

        if ( empty($this->posts ) ) {
            return false;
        }

        foreach( $this->posts as $k => $post ) {

            if ( ( $post_id == $post->id ) && ( $delay == $post->delay ) ) {
                E20RTools\DBG::log("Post and delay combination WAS found!");
                return $k;
            }
        }

		E20RTools\DBG::log("Post {$post_id} and delay {$delay} combination was NOT found.");
        return false;
    }

    public function find_by_id( $post_id, $sequence_id = null ) {

        E20RTools\DBG::log("Locating post {$post_id}.");

        $found = array();
        $posts = array();

        $valid_cache = $this->is_cache_valid();

        if ( false ===  $valid_cache) {

            E20RTools\DBG::log("Cache is invalid. Using load_sequence_post to grab the post(s) by ID: {$post_id}.");
            $posts = $this->load_sequence_post( $sequence_id, null, $post_id );

            if ( empty( $posts ) ) {

                E20RTools\DBG::log("Couldn't find post based on post ID of {$post_id}. Now loading all posts in sequence");
                $posts = $this->load_sequence_post();
            }
            else {
                E20RTools\DBG::log("Returned " . count( $posts ) . " posts from load_sequnce_post() function");
            }
        }
        else {

            E20RTools\DBG::log("Have valid cache. Using cached post list to locate post with ID {$post_id}");
            $posts = $this->posts;
        }

        if ( empty( $posts )) {
            E20RTools\DBG::log("No posts in sequence. Returning empty list.");
            return array();
        }

        foreach( $posts as $p ) {

            if ( $p->id == $post_id ) {

                E20RTools\DBG::log("Including post # {$post_id}, delay: {$p->delay}");
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
      * @since 2.4.11
      */
    private function allow_repetition() {
        E20RTools\DBG::log("Returning: " .($this->options->allowRepeatPosts ? 'true' : 'false'));
        return $this->options->allowRepeatPosts;
    }

    /**
     * Removes a post from the list of posts belonging to this sequence
     *
     * @param int $post_id -- The ID of the post to remove from the sequence
     * @param int $delay - The delay value for the post we'd like to remove from the sequence.
     * @param bool $remove_alerted - Whether to also remove any 'notified' settings for users
     * @return bool - returns TRUE if the post was removed and the metadata for the sequence was updated successfully
     *
     * @access public
     */
    public function remove_post($post_id, $delay = null, $remove_alerted = true) {

        $is_multi_post = false;

        if ( empty( $post_id ) ) {

            return false;
        }

        $this->load_sequence_post();

        if ( empty( $this->posts ) ) {

            return true;
        }

        foreach( $this->posts as $i => $post ) {

            // Remove this post from the sequence
            if ( ( $post_id == $post->id ) && ( $delay == $post->delay ) ) {

                // $this->posts = array_values( $this->posts );

                $delays = get_post_meta( $post->id, "_pmpro_sequence_{$this->sequence_id}_post_delay" );

                E20RTools\DBG::log("Delay meta_values: ");
                E20RTools\DBG::log( $delays );

                if ( 1 == count( $delays ) ) {

                    E20RTools\DBG::log("A single post associated with this post id: {$post_id}");

                    if ( false === delete_post_meta( $post_id, "_pmpro_sequence_{$this->sequence_id}_post_delay", $post->delay ) ) {

                        E20RTools\DBG::log("Unable to remove the delay meta for {$post_id} / {$post->delay}");
                        return false;
                    }

                    if ( false === delete_post_meta( $post_id, "_pmpro_sequence_post_belongs_to", $this->sequence_id ) ) {

                        E20RTools\DBG::log("Unable to remove the sequence meta for {$post_id} / {$this->sequence_id}");
                        return false;
                    }
                }
                elseif ( 1 < count( $delays ) ) {

                    E20RTools\DBG::log($delays);
                    E20RTools\DBG::log("Multiple (" . count( $delays ) . ") posts associated with this post id: {$post_id} in sequence {$this->sequence_id}");

                    if ( false == delete_post_meta( $post_id, "_pmpro_sequence_{$this->sequence_id}_post_delay", $post->delay ) ) {

                        E20RTools\DBG::log("Unable to remove the sequence meta for {$post_id} / {$this->sequence_id}");
                        return false;
                    };

                    E20RTools\DBG::log("Keeping the sequence info for the post_id");
                }
                else {
                    E20RTools\DBG::log("ERROR: There are _no_ delay values for post ID {$post_id}????");
                    return false;
                }

                E20RTools\DBG::log("Removing entry #{$i} from posts array: ");
                E20RTools\DBG::log($this->posts[$i]);

                unset( $this->posts[ $i ] );
            }

        }

        E20RTools\DBG::log("Updating cache for sequence {$this->sequence_id}");
        $this->set_cache($this->posts, $this->sequence_id);

	    // Remove the post ($post_id) from all cases where a User has been notified.
        if ( $remove_alerted ) {

            $this->remove_post_notified_flag( $post_id, $delay );
        }

		if (0 >= count($this->posts)) {
			E20RTools\DBG::log("Nothing left to cache. Cleaning up...");
			$this->delete_cache($this->sequence_id);
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

        E20RTools\DBG::log('Preparing SQL. Using sequence ID: ' . $this->sequence_id);

        $error_users = array();

        // Find all users that are active members of this sequence.
        $users = $this->get_users_of_sequence();

        foreach ( $users as $user ) {

            E20RTools\DBG::log( "Searching for Post ID {$post_id} in notification settings for user with ID: {$user->user_id}" );

            // $userSettings = get_user_meta( $user->user_id, $wpdb->prefix .  'pmpro_sequence_notices', true );
            $userSettings = $this->load_user_notice_settings( $user->user_id, $this->sequence_id );

            isset( $userSettings->id ) && $userSettings->id == $this->sequence_id ? E20RTools\DBG::log("Notification settings exist for {$this->sequence_id}") : E20RTools\DBG::log('No notification settings found');

            $notifiedPosts = isset( $userSettings->posts ) ? $userSettings->posts : array();

            if ( is_array( $notifiedPosts ) &&
                ($key = array_search( "{$post_id}_{$delay}", $notifiedPosts ) ) !== false ) {

                E20RTools\DBG::log( "Found post # {$post_id} in the notification settings for user_id {$user->user_id} with key: {$key}" );
                E20RTools\DBG::log( "Found in settings: {$userSettings->posts[ $key ]}");
                unset( $userSettings->posts[ $key ] );

                if ( $this->save_user_notice_settings( $user->user_id, $userSettings, $this->sequence_id ) ) {

                    // update_user_meta( $user->user_id, $wpdb->prefix . 'pmpro_sequence_notices', $userSettings );
                    E20RTools\DBG::log( "Deleted post # {$post_id} in the notification settings for user with id {$user->user_id}", E20R_DEBUG_SEQ_INFO );
                }
                else {
                    E20RTools\DBG::log( "Unable to remove post # {$post_id} in the notification settings for user with id {$user->user_id}", E20R_DEBUG_SEQ_WARNING );
                    $error_users[] = $user->user_id;
                }
            }
            else {
                E20RTools\DBG::log("Could not find the post_id/delay combination: {$post_id}_{$delay} for user {$user->user_id}");
            }
        }

        if ( !empty( $error_users ) ) {
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

        E20RTools\DBG::log("get_users_of_sequence() - Fetched " . count($users) . " user records for {$this->sequence_id}");
        return $users;
    }

    /**
      * Load all email alert settings for the specified user
      * @param $user_id - User's ID
      * @param null $sequence_id - The ID of the sequence
      * @return mixed|null|stdClass - The settings object
      */
    public function load_user_notice_settings( $user_id, $sequence_id = null ) {

        global $wpdb;

        E20RTools\DBG::log("Attempting to load user settings for user {$user_id} and {$sequence_id}");

        if ( empty( $sequence_id ) && ( empty( $this->sequence_id ) ) ) {

            E20RTools\DBG::log("No sequence id defined. returning null", E20R_DEBUG_SEQ_WARNING);
            return null;
        }

        $optIn = get_user_meta( $user_id,  "pmpro_sequence_id_{$sequence_id}_notices", true);

        E20RTools\DBG::log("V3 user alert settings configured: " . ( isset($optIn->send_notices) ? 'Yes' : 'No') );

        if ( isset( $optIn->send_notices ) && is_array( $optIn->posts ) && in_array( '_', $optIn->posts )) {

            E20RTools\DBG::log("Cleaning up post_id/delay combinations");

            foreach( $optIn->posts as $k => $id ) {

                if ( $id == '_' ) {

                    unset( $optIn->posts[$k] );
                }
            }

            $clean = array();

            foreach ( $optIn->posts as $notified ) {
                $clean[] = $notified;
            }

            $optIn->posts = $clean;

            E20RTools\DBG::log("Current (clean?) settings: ");
            E20RTools\DBG::log( $optIn );
        }

        if ( empty( $optIn ) || ( !isset( $optIn->send_notices ) ) ) {

            E20RTools\DBG::log("No settings for user {$user_id} and sequence {$sequence_id} found. Returning defaults.", E20R_DEBUG_SEQ_WARNING );
            $optIn = $this->create_user_notice_defaults();
            $optIn->id = $sequence_id;
        }

        return $optIn;
    }

    /**
      * Generates a stdClass() object containing the default user notice (alert) settings
      * @return stdClass
      */
    private function create_user_notice_defaults() {

        E20RTools\DBG::log("Loading default opt-in settings" );
        $defaults = new \stdClass();

        $defaults->id = $this->sequence_id;
        $defaults->send_notices = ( $this->options->sendNotice == 1 ? true : false );
        $defaults->posts = array();
        $defaults->optin_at = ( $this->options->sendNotice == 1 ? current_time( 'timestamp' ) : -1 );
        $defaults->last_notice_sent = -1; // Never

        return $defaults;
    }

    public function save_user_notice_settings( $user_id, $settings, $sequence_id = null ) {

        E20RTools\DBG::log("Attempting to save settings for {$user_id} and sequence {$sequence_id}");
        // E20RTools\DBG::log( $settings );

        if ( is_null( $sequence_id ) && ( empty( $this->sequence_id ) ) ) {

            E20RTools\DBG::log("No sequence ID specified. Exiting!", E20R_DEBUG_SEQ_WARNING );
            return false;
        }

        if ( is_null( $sequence_id ) && ( $this->sequence_id != 0 ) ) {

            E20RTools\DBG::log("No sequence ID specified. Using {$this->sequence_id} ");
            $sequence_id = $this->sequence_id;
        }

        E20RTools\DBG::log("Save V3 style user notification opt-in settings to usermeta for {$user_id} and sequence {$sequence_id}");

        update_user_meta( $user_id, "pmpro_sequence_id_{$sequence_id}_notices", $settings );

        $test = get_user_meta( $user_id, "pmpro_sequence_id_{$sequence_id}_notices",  true );

        if ( empty($test) ) {

            E20RTools\DBG::log("Error saving V3 style user notification settings for ({$sequence_id}) user ID: {$user_id}", E20R_DEBUG_SEQ_WARNING );
            return false;
        }

        E20RTools\DBG::log("Saved V3 style user alert settings for {$sequence_id}");
        return true;
    }

    public function has_post_access( $user_id, $post_id, $isAlert = false, $sequence_id = null ) {

		E20RTools\DBG::log("Checking access to post {$post_id} for user {$user_id} ");

		$existing_sequence_id = $this->sequence_id;
		$is_authorized_ajax = (is_admin() || ( false == $this->is_cron ) && ((defined('DOING_AJAX') && DOING_AJAX) && isset($_POST['in_admin_panel'])));
		$is_editor = apply_filters( 'e20r-sequence-has-edit-privileges', $this->user_can_edit( $user_id ) );

		if ( true === $is_authorized_ajax && false === $is_editor ){

			E20RTools\DBG::log("User ({$user_id}) does not have edit permissions: ");
			E20RTools\DBG::log("Editor: " . ($is_editor ? 'true' : 'false') . " AJAX: " . ($is_authorized_ajax ? 'true' : 'false'));
			// return false;
		}

		$retval = false;
        $sequences = $this->get_sequences_for_post( $post_id );

        // if ( !$this->allow_repetition() ) {

        $sequence_list = array_unique( $sequences );

        if ( count( $sequence_list ) < count( $sequences ) ) {

            E20RTools\DBG::log("Saving the pruned array of sequences");

            $this->set_sequences_for_post( $post_id, $sequence_list );
        }

        if ( empty( $sequences ) ) {

            return true;
        }

        // TODO: Remove dependency on PMPro functions in has_post_access()
        // Does the current user have a membership level giving them access to everything?
        $all_access_levels = apply_filters("pmproap_all_access_levels", array(), $user_id, $post_id );

        if ( ! empty( $all_access_levels ) && $this->has_membership_level( $all_access_levels, $user_id ) ) {

            E20RTools\DBG::log("This user ({$user_id}) has one of the 'all access' membership levels");
            return true; //user has one of the all access levels
        }

        if ( $is_authorized_ajax ) {
            E20RTools\DBG::log("User is in admin panel. Allow access to the post");
            return true;
        }

        foreach( $sequence_list as $sid ) {

	        if ( !is_null($sequence_id) && !in_array($sequence_id, $sequence_list)) {

				E20RTools\DBG::log("{$sequence_id} is not one of the sequences managing this ({$post_id}) post: {$sid}");
				continue;
			}

            if ( is_null($sequence_id) && $this->sequence_id != $sid ) {

                E20RTools\DBG::log( "Loading sequence #{$sid}" );
                $this->get_options( $sid );
                $this->load_sequence_post( $sequence_id, null, $post_id );
            }

            $allowed_post_statuses = apply_filters( 'e20r-sequence-allowed-post-statuses', array( 'publish', 'future', 'private' ) );
            $curr_post_status = get_post_status( $post_id );

            // Only consider granting access to the post if it is in one of the allowed statuses
            if ( ! in_array( $curr_post_status, $allowed_post_statuses ) ) {

                E20RTools\DBG::log("Post {$post_id} with status {$curr_post_status} isn't accessible", E20R_DEBUG_SEQ_WARNING );
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
            $access = $this->has_membership_access($sid, $user_id, true);

            E20RTools\DBG::log("Checking sequence access for membership level {$sid}: Access = " .($access[0] ? 'true' : 'false'));
            // E20RTools\DBG::log($access);

            // $usersLevels = pmpro_getMembershipLevelsForUser( $user_id );

            if ( $access[0] ) {

                $s_posts = $this->find_by_id( $post_id );

                if ( !empty( $s_posts ) ) {

                    E20RTools\DBG::log("Found " . count( $s_posts ) . " post(s) in sequence {$this->sequence_id} with post ID of {$post_id}");

                    foreach( $s_posts as $post ) {

                        E20RTools\DBG::log("UserID: {$user_id}, post: {$post->id}, delay: {$post->delay}, Alert: {$isAlert} for sequence: {$sid} - sequence_list: " .print_r( $sequence_list, true));

                        if ( $post->id == $post_id ) {

                            foreach( $access[1] as $level_id ) {

                                E20RTools\DBG::log("Processing for membership level ID {$level_id}");

                                if ( $this->options->delayType == 'byDays' ) {
                                    E20RTools\DBG::log("Sequence {$this->sequence_id} is configured to store sequence by days since startdate");

                                    // Don't add 'preview' value if this is for an alert notice.
                                    if (! $isAlert) {

                                        $durationOfMembership = $this->get_membership_days( $user_id, $level_id ) + $this->options->previewOffset;
                                    }
                                    else {

                                        $durationOfMembership = $this->get_membership_days( $user_id, $level_id );
                                    }

                                    /**
                                     * Allow user to add an offset (pos/neg integer) to the 'current day' calculation so they may
                                     * offset when this user apparently started their access to the sequence
                                     *
                                     * @since 2.4.13
                                     */
                                    $offset = apply_filters( 'e20r-sequence-add-startdate-offset', __return_zero(), $this->sequence_id );

                                    $durationOfMembership += $offset;

                                    if ( $post->delay <= $durationOfMembership ) {

                                        // Set users membership Level
                                        $this->e20r_sequence_user_level = $level_id;
                                        // E20RTools\DBG::log("has_post_access() - using byDays as the delay type, this user is given access to post ID {$post_id}.");
                                        $retval = true;
                                        break;
                                    }
                                }
                                elseif ( $this->options->delayType == 'byDate' ) {
                                    E20RTools\DBG::log("Sequence {$this->sequence_id} is configured to store sequence by dates");
                                    // Don't add 'preview' value if this is for an alert notice.
                                    if (! $isAlert) {
                                        $previewAdd = ((60*60*24) * $this->options->previewOffset);
                                    }
                                    else {
                                        $previewAdd = 0;
                                    }

                                    /**
                                     * Allow user to add an offset (pos/neg integer) to the 'current day' calculation so they may
                                     * offset when this user apparently started their access to the sequence
                                     *
                                     * @since 2.4.13
                                     */
                                    $offset = apply_filters( 'e20r-sequence-add-startdate-offset', __return_zero(), $this->sequence_id );

                                    $timestamp = ( current_time( 'timestamp' ) + $previewAdd + ( $offset * 60*60*24 ) );

                                    $today = date( __( 'Y-m-d', "e20rsequence" ), $timestamp );

                                    if ( $post->delay <= $today ) {

                                        $this->e20r_sequence_user_level = $level_id;
                                        // E20RTools\DBG::log("has_post_access() - using byDate as the delay type, this user is given access to post ID {$post_id}.");
                                        $retval = true;
                                        break;
                                    }
                                } // EndIf for delayType
                            }
                        }
                    }
                }
            }
        }

        E20RTools\DBG::log("NO access granted to post {$post_id} for user {$user_id}");

        if( $this->sequence_id !== $existing_sequence_id ) {
            E20RTools\DBG::log("Resetting sequence info for {$existing_sequence_id}");
            $this->init($existing_sequence_id);
        }

        return $retval;
    }
    /**
      * @param int|array|null $levels - Array or ID of level(s) to check against
      * @param int|null $user_id - Id of user to check levels for
      * @return boolean
      *
      * @since 4.2.6
      */
    private function has_membership_level( $levels = null, $user_id = null) {

        $has_level = false;

        // TODO: Remove dependency of pmpro_hasMembershipLevel out of the sequences class and into own module
        if (function_exists('pmpro_hasMembershipLevel')) {
            $has_level = pmpro_hasMembershipLevel( $levels, $user_id );
        }

        return apply_filters('e20r-sequence-has-membership-level', $has_level, $levels, $user_id);
    }

    /**
      * Decide whether the user ID should have access to the post_id
      * Anticipates a return value consisting of a 3 element array:
      * array(
      *          0 => boolean (true/false to indicate whether $user_id has access to $sid (sequence id/post id for sequence definition)
      *          1 => array( numeric list of membership type/level IDs that have access to this sequence id )
      *          2 => array( string list of membership level names/human readable identifiers that reflect the order of the numeric array in 1 )
      * )
      * @param int|null $post_id - the post ID to check
      * @param int|null $user_id - The user ID to check
      * @param bool $return_membership_levels
      * @return mixed|boolean
      *
      * @since 4.2.6
      */
    private function has_membership_access( $post_id = null, $user_id = null, $return_membership_levels = true) {

        // Default is to deny access if there is no membership module to manage it.
        if ( true === $return_membership_levels ) {
            $access = array(
                false,
                null,
                'No membership level found'
            );
        } else {
            $access = false;
        }

        // TODO: Remove pmpro_has_membership_access from e20r-sequences and into own module
        if (function_exists('pmpro_has_membership_access')) {

            $access = pmpro_has_membership_access($post_id, $user_id, $return_membership_levels);
        }

        return apply_filters('e20r-sequence-membership-access', $access, $post_id, $user_id, $return_membership_levels);
    }

    public function get_sequences_for_post( $post_id ) {

        E20RTools\DBG::log("Check whether we've still got old post_sequences data stored. " . $this->who_called_me() );

        $post_sequences = get_post_meta( $post_id, "_post_sequences", true);

        if ( !empty($post_sequences) ) {

            E20RTools\DBG::log("Need to migrate to V3 sequence list for post ID {$post_id}", E20R_DEBUG_SEQ_WARNING );
            E20RTools\DBG::log($post_sequences);

            foreach ( $post_sequences as $seq_id ) {

                add_post_meta( $post_id, '_pmpro_sequence_post_belongs_to', $seq_id, true ) or
                    update_post_meta( $post_id, '_pmpro_sequence_post_belongs_to', $seq_id );
            }

            E20RTools\DBG::log("Removing old sequence list metadata");
            delete_post_meta( $post_id, '_post_sequences' );
        }

        E20RTools\DBG::log("Attempting to load sequence list for post {$post_id}", E20R_DEBUG_SEQ_INFO );
        $sequence_ids = get_post_meta( $post_id, '_pmpro_sequence_post_belongs_to' );

        $sequence_count = array_count_values( $sequence_ids );

        foreach( $sequence_count as $s_id => $count ) {

            if ( $count > 1 ) {

                if ( delete_post_meta( $post_id, '_pmpro_sequence_post_belongs_to', $s_id ) ) {

                    if ( !add_post_meta( $post_id, '_pmpro_sequence_post_belongs_to', $s_id, true ) ) {

                        E20RTools\DBG::log("Unable to clean up the sequence list for {$post_id}", E20R_DEBUG_SEQ_WARNING );
                    }
                }
            }
        }

        $sequence_ids = array_unique( $sequence_ids );

        E20RTools\DBG::log("Loaded " . count( $sequence_ids ) . " sequences that post # {$post_id} belongs to", E20R_DEBUG_SEQ_INFO );

        return ( empty( $sequence_ids ) ? array() : $sequence_ids );
    }

    /**
     * Displays the 2nd function in the current stack trace (i.e. the one that called the one that called "me"
     *
     * @access private
     * @since v2.0
     */
    private function who_called_me() {

        $trace=debug_backtrace();
        $caller=$trace[2];

        $trace =  "Called by {$caller['function']}()";
        if (isset($caller['class']))
            $trace .= " in {$caller['class']}()";

        return $trace;
    }

    public function set_sequences_for_post( $post_id, $sequence_ids ) {

        E20RTools\DBG::log("Adding sequence info to post # {$post_id}");

        $retval = true;

        $seq = get_post_meta( $post_id, '_pmpro_sequence_post_belongs_to' );
        if ( is_array( $sequence_ids ) ) {

            E20RTools\DBG::log("Received array of sequences to add to post # {$post_id}");
            E20RTools\DBG::log( $sequence_ids );

            $sequence_ids = array_unique( $sequence_ids );

            foreach( $sequence_ids as $id ) {

                if ( ( false === $seq ) || ( !in_array( $id, $seq ) ) ) {

                    E20RTools\DBG::log( "Not previously added. Now adding sequence ID meta ({$id}) for post # {$post_id}");
                    $retval = $retval && add_post_meta( $post_id, '_pmpro_sequence_post_belongs_to', $id );
                }
                else {
                    E20RTools\DBG::log("Post # {$post_id} is already included in sequence {$id}");
                }
            }
        }
        else {

            E20RTools\DBG::log("Received sequence id ({$sequence_ids} to add for post # {$post_id}");

            if ( ( false === $seq ) || ( !in_array( $sequence_ids, $seq ) ) ) {

                E20RTools\DBG::log( "Not previously added. Now adding sequence ID meta ({$sequence_ids}) for post # {$post_id}");
                $retval = $retval && add_post_meta( $post_id, '_pmpro_sequence_post_belongs_to', $sequence_ids );
            }
        }

        return $retval;
    }

    public function set_closest_post( $post_list, $user_id = null ) {

        global $current_user;

        E20RTools\DBG::log("Received posts: " . count( $post_list ) . " and user ID: " . ( is_null($user_id) ? 'None' : $user_id ));

        if ( !is_null( $user_id ) ) {

            $this->e20r_sequence_user_id = $user_id;
        }
        elseif ( empty( $this->e20r_sequence_user_id ) && ( $this->e20r_sequence_user_id != $current_user->ID ) ) {

            $user_id = $this->e20r_sequence_user_id;
        }
        else {

            $user_id = $current_user->ID;
        }

        $closest_post = apply_filters( 'e20r-sequence-found-closest-post', $this->find_closest_post( $user_id ) );

        foreach( $post_list as $key => $post ) {

            if ( isset( $post->id ) ) {
                $post_id = $post->id;
            }

            if ( isset( $post->ID ) ) {
                $post_id = $post->ID;
            }

            if ( ( $post->delay == $closest_post->delay ) && ( $post_id == $closest_post->id ) ) {

                E20RTools\DBG::log( "Most current post for user {$user_id} found for post id: {$post_id}" );
                $post_list[$key]->closest_post = true;
            }
        }

        return $post_list;
    }

    /**
     * Gets and returns the post_id of the post in the sequence with a delay value
     *     closest to the number of days since startdate for the specified user ID.
     *
     * @param null $user_id -- ID of the user
     * @return bool -- Post ID or FALSE (if error)
     *
     * @access public
     */
    public function find_closest_post( $user_id = null ) {

        if ( empty( $user_id ) ) {

            E20RTools\DBG::log("No user ID specified by callee: " . $this->who_called_me());

            global $current_user;
            $user_id = $current_user->ID;
        }

        // Get the current day of the membership (as a whole day, not a float)
        $membership_day =  $this->get_membership_days( $user_id );

        // Load all posts in this sequence
        /*
        if ( false === $this->is_cache_valid() ) {
            $this->load_sequence_post();
        }
		*/
        E20RTools\DBG::log("Have " . count($this->posts) . " posts in sequence.");

        // Find the post ID in the postList array that has the delay closest to the $membership_day.
        $closest = $this->find_closest_post_by_delay_val( $membership_day, $user_id );

        if ( isset( $closest->id ) ) {

            E20RTools\DBG::log("For user {$user_id} on day {$membership_day}, the closest post is #{$closest->id} (with a delay value of {$closest->delay})");
            return $closest;
        }

        return null;
    }

    public function find_posts_by_delay( $delay, $user_id = null ) {

        $posts = array();
        E20RTools\DBG::log("Have " . count($this->posts) . " to process");

        foreach( $this->posts as $post ) {

            if ($post->delay <= $delay ) {

                $posts[] = $post;
            }
        }

        if (empty($posts)) {

            $posts = $this->find_closest_post( $user_id );
        }

        E20RTools\DBG::log("Returning " . count($posts) . " with delay value <= {$delay}");
        return $posts;

    }
    /**
     * Compares the object to the array of posts in the sequence
     * @param $delayComp -- Delay value to compare to
     *
     * @return stdClass -- The post ID of the post with the delay value closest to the $delayVal
     *
     * @access private
     */
    private function find_closest_post_by_delay_val( $delayComp, $user_id = null ) {


        if ( null === $user_id ) {

            $user_id = $this->e20r_sequence_user_id;
        }

        $distances = array();

        // E20RTools\DBG::log( $postArr );

        foreach ( $this->posts as $key => $post ) {

            $nDelay = $this->normalize_delay( $post->delay );
            $distances[ $key ] = abs( $delayComp - ( $nDelay /* + 1 */ ) );
        }

        // Verify that we have one or more than one element
        if ( count( $distances ) > 1 ) {

            $retVal = $this->posts[ array_search( min( $distances ) , $distances ) ];
        }
        elseif ( count( $distances ) == 1 ) {
            $retVal = $this->posts[$key];
        }
        else {
            $retVal = null;
        }

        return $retVal;

    }

    private function paginate_posts( $post_list, $page_size, $current_page ) {

        $page = array();

        $last_key = ($page_size * $current_page) - 1;
        $first_key = $page_size * ( $current_page - 1 );

        foreach( $post_list as $k => $post ) {

            if ( ( $k <= $last_key ) && ( $k >= $first_key ) ) {
                E20RTools\DBG::log("Including {$post->id} with delay {$post->delay} in page");
                $page[] = $post;
            }
        }

        return $page;

    }

    private function set_min_max( $pagesize, $page_num, $post_list ) {

        /**
         * Doesn't account for sort order.
         * @since 4.4.1
         */

        /**
         * Didn't account for pages < pagesize.
         * @since 4.4
         */

        if ( $this->options->sortOrder == SORT_DESC )
        {
            $min_key = 0;
            $max_key = (count($post_list) >= $pagesize) ? $pagesize - 1 : count($post_list) - 1;
        }

        if ( $this->options->sortOrder == SORT_ASC )
        {
            $min_key = (count($post_list) >= $pagesize) ? $pagesize - 1 : count($post_list) - 1;
            $max_key = 0;
        }

        E20RTools\DBG::log("Max key: {$max_key} and min key: {$min_key}");
        $min = $post_list[$max_key]->delay;
        $max = $post_list[$min_key]->delay;

        E20RTools\DBG::log("Gives min/max values: Min: {$min}, Max: {$max}");

        return array( $min, $max );

    }

    /**
     * Test whether a post belongs to a sequence & return a stdClass containing Sequence specific meta for the post ID
     *
     * @param id $post - Post ID to search for.
     * @return stdClass - The sequence specific post data for the specified post_id.
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

        E20RTools\DBG::log("Post metaboxes being configured");
        global $load_e20r_sequence_admin_script;

        $load_e20r_sequence_admin_script = true;

        foreach( $this->managed_types as $type )
        {

            if ( $type !== 'pmpro_sequence' )
            {
                $view = apply_filters('get_sequence_views_class_instance', null);
                add_meta_box( 'e20r-seq-post-meta', __( 'Drip Feed Settings', "e20rsequence" ), array( $view, 'render_post_edit_metabox' ), $type, 'side', 'high' );
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

        //PMPro box
        add_meta_box('pmpro_page_meta', __('Require Membership', "pmpro"), 'pmpro_page_meta', 'pmpro_sequence', 'side');

        $view = apply_filters('get_sequence_views_class_instance', null);

        E20RTools\DBG::log("Loading sequence settings meta box");

        // sequence settings box (for posts & pages)
        add_meta_box('e20r-sequence-settings', __('Settings for Sequence', "e20rsequence"), array( $view, 'settings' ), 'pmpro_sequence', 'side', 'high');

        E20RTools\DBG::log("Loading sequence post list meta box");
        //sequence meta box
        add_meta_box('e20r_sequence_meta', __('Posts in Sequence', "e20rsequence"), array( $view, "sequence_list_metabox" ), 'pmpro_sequence', 'normal', 'high');
    }

    /**
     * Access the private $error value
     *
     * @return string|null -- Error message or NULL
     * @access public
     */
    public function get_error_msg() {

        // $e = apply_filters('get_e20rerror_class_instance', null);
        $e = Tools\E20RError::get_instance();

        $this->error = $e->get_error( 'error' );

        if ( ! empty( $this->error ) ) {

            E20RTools\DBG::log("Error info found: " . print_r( $this->error, true));
            return $this->error;
        }
        else {
            return null;
        }
    }

    /**
     * Get all posts with status 'published', 'draft', 'scheduled', 'pending review' or 'private' from the DB
     *
     * @return array | bool -- All posts of the post_types defined in the e20r_sequencepost_types filter)
     *
     * @access private
     */
    public function get_posts_from_db() {

        $post_types = apply_filters("e20r-sequence-managed-post-types", array("post", "page") );
        $status = apply_filters( "e20r-sequence-can-add-post-status", array('publish', 'future', 'pending', 'private') );

        $args = array(
            'post_status' => $status,
            'posts_per_page' => -1,
            'post_type' => $post_types,
            'orderby' => 'modified',
//            'order' => 'DESC',
            'cache_results' => true,
            'update_post_meta_cache' => true,
        );

        $all_posts = new \WP_Query($args);
        $posts = $all_posts->get_posts();

        if ( !empty($posts) ) {

            return $posts;
        }
        else {

            return false;
        }

    }

	/**
      * Get the sort order variable content
      *
	  * @return int - The sort order for the sequence ( SORT_ASC | SORT_DESC )
      */
	public function get_sort_order() {

		if ( isset( $this->options->sortOrder) ) {

			return $this->options->sortOrder;
		}

		return SORT_ASC;
	}

    /**
     * Show list of sequence posts at the bottom of the specific sequenc post.
     *
     * @param $content -- The content to process as part of the filter action
     * @return string -- The filtered content
     */
    public function display_sequence_content( $content ) {

        global $post;
        global $pagenow;

        if ( ( $pagenow == 'post-new.php' || $pagenow == 'edit.php' || $pagenow == 'post.php' ) ) {

            return $content;
        }

        if ( ( 'pmpro_sequence' == $post->post_type ) && $this->has_membership_access() ) {

            global $load_e20r_sequence_script;

            $load_e20r_sequence_script = true;

            E20RTools\DBG::log( "E20R Sequence display {$post->ID} - " . get_the_title( $post->ID ) . " : " . $this->who_called_me() . ' and page base: ' . $pagenow );

            if ( !$this->init( $post->ID ) ) {
                return $this->display_error() . $content;
            }

            // If we're supposed to show the "days of membership" information, adjust the text for type of delay.
            if ( intval( $this->options->lengthVisible ) == 1 ) {

                $content .= sprintf("<p>%s</p>", sprintf(__("You are on day %s of your membership", "e20rsequence"), $this->get_membership_days()));
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
     * @return bool|int -- ID of sequence if it successfully gets loaded
     * @throws \Exception -- Sequence to load/init wasn't identified (specified).
     */
    public function init( $id = null ) {

        global $current_user;

        if ( !is_null( $id ) ) {

            $this->sequence = get_post( $id );
            E20RTools\DBG::log('init() - Loading the "' . get_the_title($id) . '" sequence settings');

            // Set options for the sequence
            $this->get_options( $id );

            if ( 0 != $current_user->ID || $this->is_cron ) {
                E20RTools\DBG::log('init() - Loading the "' . get_the_title($id) . '" sequence posts');
                $this->load_sequence_post();
            }

            if ( empty( $this->posts ) && ( !$this->is_converted( $id ) ) ) {

                E20RTools\DBG::log("init() - Need to convert sequence with ID {$id } to version 3 format!");
            }

            E20RTools\DBG::log( 'init() -- Done.' );

            return $this->sequence_id;
        }

        if (( $id == null ) && ( $this->sequence_id == 0 ) ) {
            throw new \Exception( __('No sequence ID specified.', "e20rsequence") );
        }

        return false;
    }

    /**
     * Display the error message (if it's defined).
     */
    public function display_error() {

        E20RTools\DBG::log("Display error messages, if there are any");
        global $current_screen;

        $msg = $this->get_error_msg();

        if ( ! empty( $msg ) ){
            E20RTools\DBG::log("Display error for Drip Feed operation(s)");
            ?><div id="e20r-seq-error" class="error"><?php settings_errors('e20r_seq_errors'); ?></div><?php

        }
    }

    /**
     * Fetches the posts associated with this sequence, then generates HTML containing the list.
     *
     * @param bool $echo -- Whether to immediately 'echo' the value or return the HTML to the calling function
     * @return bool|mixed|string -- The HTML containing the list of posts in the sequence
     *
     * @access public
     */
    public function get_post_list_as_html($echo = false) {

        E20RTools\DBG::log("get_post_list_as_html() - Generate HTML list of posts for sequence #: {$this->sequence_id}");

        //global $current_user;
        $this->load_sequence_post();

        if ( ! empty( $this->posts ) ) {

            $view = apply_filters('get_sequence_views_class_instance', false);

            // TODO: Have upcoming posts be listed before or after the currently active posts (own section?) - based on sort setting
            $content = $view->create_sequence_list( true, 30, true, null, false	);

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
     * @return bool|string -- The number
     *
     * @access public
     */
    public function display_proper_delay( $delay ) {

        if ( $this->options->showDelayAs == E20R_SEQ_AS_DATE) {
            // Convert the delay to a date

            $memberDays = round($this->get_membership_days(), 0);

            $delayDiff = ($delay - $memberDays);
            E20RTools\DBG::log('Delay: ' .$delay . ', memberDays: ' . $memberDays . ', delayDiff: ' . $delayDiff);

            return strftime('%x', strtotime("+" . $delayDiff ." days"));
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

        if ($total > 1) {

            if (! $current_page = get_query_var( 'page' ) )
                $current_page = 1;

            $paged = ( get_query_var( 'page' ) ) ? absint( get_query_var( 'page' ) ) : 1;
            $base = @add_query_arg('page','%#%');
            $format = '?page=%#%';

            $prev_arrow = is_rtl() ? '&rarr;' : '&larr;';
            $next_arrow = is_rtl() ? '&larr;' : '&rarr;';

            ob_start();

            ?>
            <nav class="navigation paging-navigation" role="navigation">
                <h4 class="screen-reader-text"><?php _e( 'Navigation', "e20rsequence" ); ?></h4>
                <?php echo paginate_links( array(
                    'base'          => $base,
                    'format'        => $format,
                    'total'         => $total,
                    'current'       => $paged,
                    'mid_size'      => 2,
                    'prev_text'     => sprintf( __( '%s Previous', "e20rsequence"), $prev_arrow),
                    'next_text'     => sprintf( __( 'Next %s', "e20rsequence"), $next_arrow),
                    'prev_next'     => true,
                    'type'          => 'list',
                    'before_page_number' => '<span class="screen-reader-text">' . __('Page', "e20rsequence") . '</span>',
                )); ?>
            </nav>
            <?php
            $html =  ob_get_clean();
        }

        return $html;
    }

    /**
     * Filter the message for users to check for sequence info.
     *
     * @param $text (string) -- The text to filter
     * @return string -- the filtered text
     */
    public function text_filter($text) {

        global $current_user, $post, $pagenow;

        if ( ( $pagenow == 'post-new.php' || $pagenow == 'edit.php' || $pagenow == 'post.php' ) ) {

            return $text;
        }

        if ( !empty( $current_user ) && ( !empty( $post ) ) ) {

            E20RTools\DBG::log("text_filter() - Current sequence ID: {$this->sequence_id} vs Post ID: {$post->ID}" );

            // if ( ! $this->has_access( $current_user->ID, $post->ID ) ) {

                // $post_sequences = get_post_meta($post->ID, "_post_sequences", true);

            $post_sequences = $this->get_sequences_for_post( $post->ID );
            $days_since_start = $this->get_membership_days( $current_user->ID );

            //Update text. The user either will have to wait or sign up.
            $insequence = false;

            foreach ( $post_sequences as $ps ) {

                E20RTools\DBG::log("text_filter() - Checking access to {$ps}");

                if ( $this->has_sequence_access( $current_user->ID, $ps ) ) {

                    E20RTools\DBG::log("text_filter() - It's possible user has access to sequence: {$ps} ");
                    $insequence = $ps;

                    if ( !$this->init( $ps ) ) {
                        return $this->display_error() . $text;
                    }

                    $post_list = $this->find_by_id( $post->ID );
                    $r = array();

                    foreach( $post_list as $k => $p ) {

                        if ( $days_since_start >= $p->delay ) {
                            $r[] = $p;
                        }
                    }

                    if ( !empty( $r ) ) {

                        $delay = $r[0]->delay;
                        $post_id = $r[0]->id;
                    }
                }


                if ( false !== $insequence ) {

                    //user has one of the sequence levels, find out which one and tell him how many days left
                    $text = sprintf("%s<br/>", sprintf( __("This content is only available to existing members at the specified time or day. (Required membership: <a href='%s'>%s</a>", "e20rsequence"), get_permalink($ps), get_the_title($ps)) );

                    switch ( $this->options->delayType ) {

                        case 'byDays':

                            switch ( $this->options->showDelayAs ) {

                                case E20R_SEQ_AS_DAYNO:

                                    $text .= sprintf( __( 'You will be able to access "%s" on day %s of your membership', "e20rsequence" ), get_the_title( $post_id ), $this->display_proper_delay( $delay ) );
                                    break;

                                case E20R_SEQ_AS_DATE:

                                    $text .= sprintf( __( 'You will be able to  access "%s" on %s', "e20rsequence" ), get_the_title( $post_id ), $this->display_proper_delay( $delay ) );
                                    break;
                            }

                            break;

                        case 'byDate':
                            $text .= sprintf( __('You will be able to access "%s" on %s', "e20rsequence"), get_the_title($post_id), $delay );
                            break;

                        default:

                    }

                }
                else {

                    // User has to sign up for one of the sequence(s)
                    if ( count( $post_sequences ) == 1 ) {

                        $tmp = $post_sequences;
                        $seqId = array_pop( $tmp );

                        $text = sprintf("%s<br/>", sprintf( __( "This content is only available to existing members who are already logged in. ( Reqired level: <a href='%s'>%s</a>)", "e20rsequence" ), get_permalink( $seqId ), get_the_title( $seqId ) ) );
                    }
                    else {

                        $text = sprintf( "<p>%s</p>", __( 'This content is only available to existing members who have logged in. ( For levels:  ', "e20rsequence" ) );
                        $seq_links = array();

                        foreach ( $post_sequences as $sequence_id ) {

                            $seq_links[] = "<p><a href='" . get_permalink( $sequence_id ) . "'>" . get_the_title( $sequence_id ) . "</a></p>";
                        }

                        $text .= implode( $seq_links ) . " )";
                    }
                }
            }
        }

        return $text;
    }

    /**
      * @param $user_id
      * @param null $sequence_id
      * @return bool
     **/
    public function has_sequence_access( $user_id, $sequence_id = null ) {

        if (is_null( $sequence_id ) && empty( $this->sequence_id ) ) {
            return true;
        }

        if ( ( !empty( $sequence_id ) ) && ( 'pmpro_sequence' != get_post_type( $sequence_id ) ) ){

            // Not a E20R Sequence CPT post_id
            return true;
        }

        $results = $this->has_membership_access( $sequence_id, $user_id, true );

        if ( $results[0] ) {
            return true;
        }

        return false;
    }

    /**
     * Used to validate whether the delay specified is less than the number of days since the member joined
     *
     * @param $memberFor -- How long the member has been active for (days)
     * @param $delay -- The specified delay to test against
     * @return bool -- True if delay is less than the time the member has been a member for.
     *
     * @access public
     */
    public function is_after_delay( $memberFor, $delay )
    {
        // Get the preview offset (if it's defined). If not, set it to 0
        // for compatibility
        if ( ! isset( $this->options->previewOffset ) ) {

            E20RTools\DBG::log("is_after_delay() - the previewOffset value doesn't exist yet {$this->options->previewOffset}. Fixing now.");
            $this->options->previewOffset = 0;
            $this->save_sequence_meta(); // Save the settings (only the first when this variable is empty)

        }

        $offset = $this->options->previewOffset;
        // E20RTools\DBG::log('is_after_delay() - Preview enabled and set to: ' . $offset);

        if ( $this->is_valid_date( $delay ) ) {
            // Fixed: Now supports DST changes (i.e. no "early or late availability" in DST timezones
            // $now = current_time('timestamp') + ($offset * 86400);
            $now = $this->get_now_and_offset( $offset );

            // TODO: Add support for startWhen options (once the plugin supports differentiating on when the drip starts)
            $delayTime = strtotime( $delay . ' 00:00:00.0 ' . get_option( 'timezone_string' ) );
            // E20RTools\DBG::log('is_after_delay() - Now = ' . $now . ' and delay time = ' . $delayTime );

            return ( $now >= $delayTime ? true : false ); // a date specified as the $delay
        }

        return ( ($memberFor + $offset) >= $delay ? true : false );
    }

    /**
     * Save the settings for the seuqence to the Wordpress DB.
     *
     * @param $settings (array) -- Settings for the Sequence
     * @param $sequence_id (int) -- The ID for the Sequence
     * @return bool - Success or failure for the save operation
     *
     * @access public
     */
    public function save_sequence_meta( $settings = null, $sequence_id = 0)
    {
        // Make sure the settings array isn't empty (no settings defined)
        if ( empty( $settings ) ) {

            $settings = $this->options;
        }

        if (($sequence_id != 0) && ($sequence_id != $this->sequence_id)) {

            E20RTools\DBG::log( 'save_sequence_meta() - Unknown sequence ID. Need to instantiate the correct sequence first!' );
            return false;
        }

        try {

            // Update the *_postmeta table for this sequence
            update_post_meta($this->sequence_id, '_pmpro_sequence_settings', $settings );

            // Preserve the settings in memory / class context
            E20RTools\DBG::log('save_sequence_meta(): Saved Sequence Settings for ' . $this->sequence_id);
        }
        catch (Exception $e) {

            E20RTools\DBG::log('save_sequence_meta() - Error saving sequence settings for ' . $this->sequence_id . ' Msg: ' . $e->getMessage());
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

        $seconds = 0;
        $serverTZ = get_option( 'timezone_string' );

        $now = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );

        if ( $days > 1) {
            $dayStr = "{$days} days";
        }
        else {
            $dayStr = "{$days} day";
        }

        $now->modify( $dayStr );

        $now->setTimezone( new \DateTimeZone( $serverTZ ) );
        $seconds = $now->format( 'U' );

        E20RTools\DBG::log("calculateOffsetSecs() - Offset Days: {$days} = When (in seconds): {$seconds}", E20R_DEBUG_SEQ_INFO );
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
            'public'                => true,
            'exclude_from_search'   => false,
            '_builtin'              => false,
        );

        $output = 'names';
        $operator = 'and';

        $post_types = get_post_types($cpt_args, $output, $operator );
        $postTypeList = array();

        foreach ($post_types as $post_type) {
            $postTypeList[] = $post_type;
        }

        return array_merge( $defaults, $postTypeList);
    }

    /**
     * Filter e20r_has_membership_access based on sequence access.
     *
     * @param $hasaccess (bool) -- Current access status
     * @param $post (WP_Post) -- The post we're processing
     * @param $user (WP_User) -- The user ID we're testing
     * @param $levels (array) -- The membership level(s) we're testing against
     *
     * @return bool -- True if access is granted, false if not
     */
    public function has_membership_access_filter( $hasaccess, $post, $user, $levels) {

        //See if the user has access to the specific post
        if ( !$this->is_managed($post->ID)) {
            E20RTools\DBG::log("has_membership_access_filter() - Post {$post->ID} is not managed by a sequence (it is one?). Returning original access value: " . ($hasaccess ? 'true' : 'false'));
            return $hasaccess;
        }

        if ( $hasaccess ) {

            if ( isset( $user->ID ) && isset( $post->ID ) && $this->has_post_access( $user->ID, $post->ID ) ) {

                $hasaccess = true;
            }
            else {
                $hasaccess = false;
            }
        }

        return apply_filters( 'e20r-sequence-has-access-filter', $hasaccess, $post, $user, $levels );
    }

    public function is_managed( $post_id ) {

        E20RTools\DBG::log("is_managed() - Check whether post ID {$post_id} is managed by a sequence: " . $this->who_called_me());

        $is_sequence = get_post_meta( $post_id, '_pmpro_sequence_post_belongs_to' );
        $retval = empty($is_sequence) ? false : true;

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

        if ( $settings->optin_at != -1) {

            E20RTools\DBG::log( 'User: ' . $user_id . ' Optin TS: ' . $settings->optin_at .
                ', Optin Date: ' . date( 'Y-m-d', $settings->optin_at )
            );

            $delay_ts = $this->delay_as_timestamp( $post->delay, $user_id );
            E20RTools\DBG::log("Timestamp for delay value: {$delay_ts}");

            // Compare the Delay to the optin (subtract 24 hours worth of time from the opt-in TS)
            if ( $delay_ts >= ( $settings->last_notice_sent - DAY_IN_SECONDS ) ) {

                E20RTools\DBG::log('This post SHOULD be allowed to be alerted on');
                return true;
            } else {
                E20RTools\DBG::log('This post should NOT be allowed to be alerted on');
                return false;
            }
        } else {
            E20RTools\DBG::log('Negative opt-in timestamp value. The user  (' . $user_id . ') does not want to receive alerts');
            return false;
        }
    }

    /**
     * Calculate the delay for a post as a 'seconds since UNIX epoch' value
     *
     * @param $delay -- The delay value (can be a YYYY-MM-DD date string or a number)
     * @param null $user_id -- The User ID
     * @param null $level_id -- The User's membership level (if applicable)
     * @return int|string -- Returns the timestamp (seconds since epoch) for when the delay will be available.
     *
     * @access private
     */
    private function delay_as_timestamp($delay, $user_id = null, $level_id = null) {

        $delayTS = current_time('timestamp', true); // Default is 'now'

        $startTS = $this->get_user_startdate($user_id, $level_id);

        switch ($this->options->delayType) {
            case 'byDays':
                $delayTS = strtotime( '+' . $delay . ' days', $startTS);
                E20RTools\DBG::log('delay_as_timestamp() -  byDays:: delay = ' . $delay . ', delayTS is now: ' . $delayTS . ' = ' . date('Y-m-d', $startTS) . ' vs ' . date('Y-m-d', $delayTS));
                break;

            case 'byDate':
                $delayTS = strtotime( $delay );
                E20RTools\DBG::log('delay_as_timestamp() -  byDate:: delay = ' . $delay . ', delayTS is now: ' . $delayTS . ' = ' . date('Y-m-d', $startTS) . ' vs ' . date('Y-m-d', $delayTS));
                break;
        }

        return $delayTS;
    }

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

        if( isset($_COOKIE["_ga"]) ){

            list($version,$domainDepth, $cid1, $cid2) = preg_split('/[\.]/i', $_COOKIE["_ga"],4);
            return array('version' => $version, 'domainDepth' => $domainDepth, 'cid' => $cid1.'.'.$cid2);
        }

        return array();
    }

    private function prepare_mail_obj( $post, $user, $template ) {

        E20RTools\DBG::log("Attempting to load email class...");

        $email = new Tools\e20rMail();

        E20RTools\DBG::log("Loaded " . get_class($email));

        $user_started = ($this->get_user_startdate($user->ID) - DAY_IN_SECONDS) + ($this->normalize_delay($post->delay) * DAY_IN_SECONDS);

        $email->from = $this->get_option_by_name('replyto');
        $email->template = $template;
        $email->fromname = $this->get_option_by_name('fromname');
        $email->to = $user->user_email;
        $email->subject = sprintf('%s: %s (%s)', $this->get_option_by_name('subject'), $post->title, strftime("%x", $user_started));
        $email->dateformat = $this->get_option_by_name('dateformat');

        return $email;

    }
    /**
     * Send email to userID about access to new post.
     *
     * @param $post_ids -- IDs of post(s) to send email about
     * @param $user_id -- ID of user to send the email to.
     * @param $seq_id -- ID of sequence to process (not used)
     * @return bool - True if sent successfully. False otherwise.
     *
     * @access public
     *
     * TODO: Fix email body to be correct (standards compliant) MIME encoded HTML mail or text mail.
     */
    public function send_notice($posts, $user_id, $seq_id)
    {
        // Make sure the email class is loaded.
        if ( ! class_exists( 'E20R\\Sequences\\Tools\\e20rMail' ) ) {
            E20RTools\DBG::log("Tools\\e20rMail class is missing??");
            return;
        }

        if ( ! is_array( $posts ) ) {

            $posts = array( $posts );
        }

        $user = get_user_by('id', $user_id);
        $templ = preg_split('/\./', $this->get_option_by_name('noticeTemplate')); // Parse the template name

        $emails = array();

        $post_links = '';
        $excerpt = '';
        $ga_tracking = '';

        E20RTools\DBG::log("Preparing to send " . count( $posts ) . " post alerts for user {$user_id} regarding sequence {$seq_id}");
        E20RTools\DBG::log($templ);

        // Add data/img entry for google analytics.
        $track_with_ga = $this->get_option_by_name('trackGoogleAnalytics');

        if ( ! empty( $track_with_ga) && ( true === $track_with_ga ) ) {

            // FIXME: get_google_analytics_client_id() can't work since this isn't being run during a user session!
            $cid = esc_html( $this->ga_getCid() );
            $tid = esc_html( $this->options->gaTid );
            $post = get_post( $this->sequence_id );
            $campaign = esc_html( $post->post_title );

            // http://www.google-analytics.com/collect?v=1&tid=UA-12345678-1&cid=CLIENT_ID_NUMBER&t=event&ec=email&ea=open&el=recipient_id&cs=newsletter&cm=email&cn=Campaign_Name

            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";

            if (!empty( $cid ) ) {

                //https://strongcubedfitness.com/?utm_source=daily_lesson&utm_medium=email&utm_campaign=vpt
                $url = "${protocol}://www.google-analytics.com/collect/v=1&aip=1&ds=lesson&tid={$tid}&cid={$cid}";
                $url = $url . "&t=event&ec=email&ea=open&el=recipient_id&cs=newsletter&cm=email&cn={$campaign}";

                $ga_tracking = '<img src="' . $url . '" >';
            }
        }

        if (false === ($template_content = file_get_contents( $this->email_template_path() ) ) ) {

            E20RTools\DBG::log('ERROR: Could not read content from template file: '. $this->get_option_by_name('noticeTemplate'));
            return false;
        }

        $user_started = ($this->get_user_startdate($user_id) - DAY_IN_SECONDS);

        $send_as = $this->get_option_by_name('noticeSendAs');

        if ( empty($send_as) ) {

            E20RTools\DBG::log("WARNING: Have to update the noticeSendAs setting!");
            $this->set_option_by_name('noticeSendAs', E20R_SEQ_SEND_AS_SINGLE );
            $this->save_settings($seq_id);
        }

        foreach( $posts as $post ) {

            $as_list = false;

            $post_date = date($this->get_option_by_name('dateformat'), ($user_started + ($this->normalize_delay($post->delay) * DAY_IN_SECONDS)));

            // Send all of the links to new content in a single email message.
            if ( E20R_SEQ_SEND_AS_LIST == $send_as ) {

                $idx = 0;
				$post_links .= '<li><a href="'. wp_login_url($post->permalink) . '" title="' . $post->title . '">' . $post->title . '</a></li>\n';

                if (false === $as_list) {

                    $as_list = true;
                    $emails[$idx] = $this->prepare_mail_obj( $post, $user, $this->get_option_by_name('noticeTemplate') );
                    $emails[$idx]->body = $template_content;

                    $data = array(
                                        // Options could be: display_name, first_name, last_name, nickname
                        "name"      => apply_filters( 'e20r-sequence-alert-message-name', $user->user_firstname ),
                        "sitename"  => apply_filters('e20r-sequence-site-name', get_option("blogname")),
                        "today"     => apply_filters( 'e20r-sequence-alert-message-date', $post_date ),
                        "excerpt"   => apply_filters( 'e20r-sequence-alert-message-excerpt-intro', $post->excerpt ),
                        "post_link" => apply_filters( 'e20r-sequence-alert-message-link-href-element', $post_links ),
                        "ptitle"    => apply_filters( 'e20r-sequence-alert-message-title', $post->title ),
                    );

                    if ( isset( $this->options->track_google_analytics ) && ( true == $this->options->track_google_analytics) ) {
                       $data['google_analytics'] = $ga_tracking;
                    }

                    $emails[$idx]->data = apply_filters('e20r-sequence-email-substitution-fields', $data);
                }
            }
            elseif ( E20R_SEQ_SEND_AS_SINGLE == $send_as ) {

                // Send one email message per piece of new content.
                $emails[] = $this->prepare_mail_obj($post, $user, $templ[0]);

                // super defensive programming...
                $idx = ( empty( $emails ) ? 0 : count($emails) - 1);

                if ( !empty( $post->excerpt ) ) {

                    E20RTools\DBG::log("Adding the post excerpt to email notice");

                    if ( empty( $this->options->excerptIntro ) ) {
                        $this->options->excerptIntro = __('A summary:', "e20rsequence");
                    }

                    $excerpt = '<p>' . $this->options->excerptIntro . '</p><p>' . $post->excerpt . '</p>';
                }

                $post_links = '<a href="'. wp_login_url($post->permalink) . '" title="' . $post->title . '">' . $post->title . '</a>';
				$post_url = wp_login_url($post->permalink);

                $emails[$idx]->body = $template_content;

                if ( isset( $this->options->trackGoogleAnalytics ) && ( true == $this->options->trackGoogleAnalytics) ) {
                   $data['google_analytics'] = $ga_tracking;
                }

                $data = array(
                    "name"          => apply_filters( 'e20r-sequence-alert-message-name', $user->user_firstname ), // Options could be: display_name, first_name, last_name, nickname
                    "sitename"      => apply_filters( 'e20r-sequence-site-name', get_option("blogname")),
                    "post_link"     => apply_filters( 'e20r-sequence-alert-message-link-href-element', $post_links ),
                    'post_url'      => apply_filters( 'e20r-sequence-alert-message-post-permalink', $post_url ),
                    "today"         => apply_filters( 'e20r-sequence-alert-message-date', $post_date ),
                    "excerpt"       => apply_filters( 'e20r-sequence-alert-message-excerpt-intro', $post->excerpt ),
                    "ptitle" => apply_filters( 'e20r-sequence-alert-message-title', $post->title ),
                );

                $emails[$idx]->data = apply_filters('e20r-sequence-email-substitution-fields', $data);
            }


        }

        // Append the post_link ul/li element list when asking to send as list.
        if ( E20R_SEQ_SEND_AS_LIST == $this->options->noticeSendAs ) {

            E20RTools\DBG::log('Set link variable for list of link format');
            $emails[(count($emails) - 1)]->post_link = "<ul>\n" . $post_links . "</ul>\n";
        }

        $emails[$idx]->data = apply_filters('e20r-sequence-email-substitution-fields', $data);

        E20RTools\DBG::log("Have prepared " . count($emails) . " email notices for user {$user_id}");

        $user = get_user_by( 'id', $user_id );

        // Send the configured email messages
        foreach ( $emails as $email ) {

            E20RTools\DBG::log('Email object: ' . get_class($email));
            if ( false == $email->send() ) {

                E20RTools\DBG::log("ERROR - Failed to send new sequence content email to {$user->user_email}! ");
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

        if ( file_exists( get_stylesheet_directory() . "/sequence-email-alerts/" . $this->get_option_by_name('noticeTemplate') ) ) {

            $template_path = get_stylesheet_directory() . "/sequence-email-alerts/" . $this->get_option_by_name('noticeTemplate');

        }
        elseif ( file_exists( get_template_directory() . "/sequence-email-alerts/" . $this->get_option_by_name('noticeTemplate') ) ) {

            $template_path = get_template_directory() . "/sequence-email-alerts/" . $this->get_option_by_name('noticeTemplate');
        }
        elseif ( file_exists( E20R_SEQUENCE_PLUGIN_DIR . "email/" . $this->get_option_by_name('noticeTemplate') ) ) {

            $template_path = E20R_SEQUENCE_PLUGIN_DIR . "email/" . $this->get_option_by_name('noticeTemplate');
        }
        else {

            $template_path = null;
        }

        E20RTools\DBG::log("email_template_path() - Using path: {$template_path}");
        return $template_path;
    }

    /**
     * Resets the user-specific alert settings for a specified sequence Id.
     *
     * @param $userId - User's ID
     * @param $sequenceId - ID of the sequence we're clearning
     *
     * @return mixed - false means the reset didn't work.
     */
    public function reset_user_alerts( $userId, $sequenceId ) {

        global $wpdb;

        E20RTools\DBG::log("reset_user_alerts() - Attempting to delete old-style user notices for sequence with ID: {$sequenceId}", E20R_DEBUG_SEQ_INFO);
        $old_style = delete_user_meta( $userId, $wpdb->prefix . 'pmpro_sequence' . '_notices' );

        E20RTools\DBG::log("reset_user_alerts() - Attempting to delete v3 style user notices for sequence with ID: {$sequenceId}", E20R_DEBUG_SEQ_INFO);
        $v3_style = delete_user_meta( $userId, "pmpro_sequence_id_{$sequenceId}_notices" );

        if ( $old_style || $v3_style ) {

            E20RTools\DBG::log("reset_user_alerts() - Successfully delted user notice settings for user {$userId}");
            return true;
        }

        // $this->load_notices( $this->sequence_id );
        /*
        if ( isset( $notices->sequences ) ) {

            foreach( $notices->sequences as $seqId => $noticeList ) {

                if ( $seqId == $sequenceId ) {

                    E20RTools\DBG::log("Deleting user notices for sequence with ID: {$sequenceId}", E20R_DEBUG_SEQ_INFO);

                    unset($notices->sequences[$seqId]);
                    //  Use $this->save_user_notice_settings( $userId, $notices, $sequenceId )
                    return update_user_meta( $userId, $wpdb->prefix . 'pmpro_sequence' . '_notices', $notices );
                }
            }
        }
        */
        return false;
    }

    /**
     * Changes the content of the following placeholders as described:
     *
     *  !!excerpt_intro!! --> The introduction to the excerpt (Configure in "Sequence" editor ("Sequence Settings pane")
     *  !!lesson_title!! --> The title of the lesson/post we're emailing an alert about.
     *  !!today!! --> Today's date (in the configured format).
     *
     * @param $phpmailer -- PMPro Mail object (contains the Body of the message)
     *
     * @access private
     */
    public function email_body( $phpmailer ) {

        E20RTools\DBG::log('email_body() action: Update body of message if it is sent by PMPro Sequence');

        if ( isset( $phpmailer->excerpt_intro ) ) {
            $phpmailer->Body = apply_filters( 'e20r-sequence-alert-message-excerpt-intro', str_replace( "!!excerpt_intro!!", $phpmailer->excerpt_intro, $phpmailer->Body ) );
        }

        if ( isset( $phpmailer->ptitle ) ) {
            $phpmailer->Body = apply_filters( 'e20r-sequence-alert-message-title', str_replace( "!!ptitle!!", $phpmailer->ptitle, $phpmailer->Body ) );
        }

    }

    /**
      * For backwards compatibility.
      * @param $msg
      * @param int $lvl
      */
    public function dbgOut( $msg, $lvl = E20R_DEBUG_SEQ_INFO ) {

        E20RTools\DBG::log( $msg, $lvl );
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

        if ( !isset( $post->post_type) ) {
            E20RTools\DBG::log("post_save_action() - No post type defined for {$post_id}", E20R_DEBUG_SEQ_WARNING);
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            E20RTools\DBG::log("Exit during autosave");
            return;
        }

        if ( wp_is_post_revision( $post_id ) !== false ) {
            E20RTools\DBG::log("post_save_action() - Not saving revisions ({$post_id}) to sequence");
            return;
        }

        if ( ! in_array( $post->post_type, $this->managed_types ) ) {
            E20RTools\DBG::log("post_save_action() - Not saving delay info for {$post->post_type}");
            return;
        }

        if ( 'trash' == get_post_status( $post_id ) ) {
            return;
        }

        E20RTools\DBG::log("post_save_action() - Sequences & Delays have been configured for page save. " . $this->who_called_me());

        if ( isset( $_POST['e20r_seq-sequences'] ) ) {
            $seq_ids = is_array( $_POST['e20r_seq-sequences'] ) ? array_map( 'esc_attr', $_POST['e20r_seq-sequences']) : null;
        }
        else {
            $seq_ids = array();
        }

        if ( isset( $_POST['e20r_seq-delay'] ) ) {

            $delays = is_array( $_POST['e20r_seq-delay'] ) ? array_map( 'esc_attr', $_POST['e20r_seq-delay'] )  : array();
        }
        else {
            $delays = array();
        }

        if ( empty( $delays ) && (!in_array(0, $delays)) ) {

            $this->set_error_msg( __( "Error: No delay value(s) received", "e20rsequence") );
            E20RTools\DBG::log( "post_save_action() - Error: delay not specified! ", E20R_DEBUG_SEQ_CRITICAL );
            return;
        }

        $errMsg = null;

        // $already_in = $this->get_sequences_for_post( $post_id );
        // $already_in = get_post_meta( $post_id, "_post_sequences", true );

        E20RTools\DBG::log( "post_save_action() - Saved received variable values...");

        foreach ($seq_ids as $key => $id ) {

            E20RTools\DBG::log("post_save_action() - Processing for sequence {$id}");

            if ( $id == 0 ) {
                continue;
            }

            if ( $id != $this->sequence_id ) {

                if ( !$this->get_options( $id ) ) {
                    E20RTools\DBG::log("post_save_action() - Unable to load settings for sequence with ID: {$id}");
                    return;
                }
            }

            $user_can = apply_filters( 'e20r-sequence-has-edit-privileges', $this->user_can_edit( $current_user->ID ) );

            if (! $user_can ) {

                $this->set_error_msg( __( 'Incorrect privileges for this operation', "e20rsequence" ) );
                E20RTools\DBG::log("post_save_action() - User lacks privileges to edit", E20R_DEBUG_SEQ_WARNING);
                return;
            }

            if ( $id == 0 ) {

                E20RTools\DBG::log("post_save_action() - No specified sequence or it's set to 'nothing'");
            }
            elseif ( is_null($delays[$key]) ||  (empty( $delays[$key] ) && !is_numeric($delays[$key]) ) ) {

                E20RTools\DBG::log("post_save_action() - Not a valid delay value...: " . $delays[$key], E20R_DEBUG_SEQ_CRITICAL);
                $this->set_error_msg( sprintf( __( "You must specify a delay value for the '%s' sequence", "e20rsequence"), get_the_title( $id ) ) );
            }
            else {

                E20RTools\DBG::log( "post_save_action() - Processing post {$post_id} for sequence {$this->sequence_id} with delay {$delays[$key]}" );
                $this->add_post( $post_id, $delays[ $key ] );
            }
        }
    }

    /**
     * Default permission check function.
     * Checks whether the provided user_id is allowed to publish_pages & publish_posts.
     *
     * @param $user_id - ID of user to check permissions for.
     * @return bool -- True if the user is allowed to edi/update
     *
     * @access private
     */
    private function user_can_edit( $user_id ) {

        if ( ( user_can( $user_id, 'publish_pages' ) ) ||
            ( user_can( $user_id, 'publish_posts' ) ) ) {

            E20RTools\DBG::log("user_can_edit() - User with ID {$user_id} has permission to update/edit this sequence");
            return true;
        }
        else {
            return false;
        }
    }

    /**
     * Adds the specified post to this sequence
     *
     * @param $post_id -- The ID of the post to add to this sequence
     * @param $delay -- The delay to apply to the post
     * @return bool -- Success or failure
     *
     * @access public
     */
    public function add_post( $post_id, $delay )
    {
        E20RTools\DBG::log("add_post() for sequence {$this->sequence_id}: " . $this->who_called_me() );

/*        if (! $this->is_valid_delay($delay) )
        {
            E20RTools\DBG::log('add_post(): Admin specified an invalid delay value for post: ' . ( empty($post_id) ? 'Unknown' :  $post_id) );
            $this->set_error_msg( sprintf(__('Invalid delay value - %s', "e20rsequence"), ( empty($delay) ? 'blank' : $delay ) ) );
            return false;
        }
*/
        if(empty($post_id) || !isset($delay))
        {
            $this->set_error_msg( __("Please enter a value for post and delay", "e20rsequence") );
            E20RTools\DBG::log('add_post(): No Post ID or delay specified');
            return false;
        }

        E20RTools\DBG::log('add_post(): Post ID: ' . $post_id . ' and delay: ' . $delay);

        if ( $post = get_post($post_id) === null ) {

            $this->set_error_msg( __("A post with that id does not exist", "e20rsequence") );
            E20RTools\DBG::log('add_post(): No Post with ' . $post_id . ' found');

            return false;
        }

/*        if ( $this->is_present( $post_id, $delay ) ) {

            E20RTools\DBG::log("add_post() - Post {$post_id} with delay {$delay} is already present in sequence {$this->sequence_id}");
            return true;
        }
*/
        // Refresh the post list for the sequence, ignore cache

        if (current_time('timestamp') >= $this->expires && !empty($this->posts)) {

            E20RTools\DBG::log("add_post(): Refreshing post list for sequence #{$this->sequence_id}");
            $this->load_sequence_post();
        }

        // Add this post to the current sequence.

        E20RTools\DBG::log( "add_post() - Adding post {$post_id} with delay {$delay} to sequence {$this->sequence_id}");
        if (! $this->add_post_to_sequence( $this->sequence_id, $post_id, $delay) ) {

            E20RTools\DBG::log("add_post() - ERROR: Unable to add post {$post_id} to sequence {$this->sequence_id} with delay {$delay}", E20R_DEBUG_SEQ_WARNING);
            $this->set_error_msg(sprintf(__("Error adding %s to %s", "e20rsequence"), get_the_title($post_id), get_the_title($this->sequence_id)));
            return false;
        }

        //sort
        E20RTools\DBG::log('add_post(): Sorting the sequence posts by delay value(s)');
        usort( $this->posts, array( $this, 'sort_posts_by_delay' ) );

        // Save the sequence list for this post id

        /* $this->set_sequences_for_post( $post_id, $post_in_sequences ); */
        // update_post_meta( $post_id, "_post_sequences", $post_in_sequences );

        E20RTools\DBG::log('add_post(): Post/Page list updated and saved');

        return true;
    }

    /**
     * Validates that the value received follows a valid "delay" format for the post/page sequence
     *
     * @param $delay (string) - The specified post delay value
     * @return bool - Delay is recognized (parseable).
     *
     * @access private
     */
    private function is_valid_delay( $delay )
    {
        E20RTools\DBG::log( "is_valid_delay(): Delay value is: {$delay} for setting: {$this->options->delayType}" );

        switch ($this->options->delayType)
        {
            case 'byDays':
                E20RTools\DBG::log('is_valid_delay(): Delay configured as "days since membership start"');
                return ( is_numeric( $delay ) ? true : false);
                break;

            case 'byDate':
                E20RTools\DBG::log('is_valid_delay(): Delay configured as a date value');
                return ( apply_filters( 'e20r-sequence-check-valid-date', $this->is_valid_date( $delay ) ) ? true : false);
                break;

            default:
                E20RTools\DBG::log('is_valid_delay(): NOT a valid delay value, based on config');
                E20RTools\DBG::log("is_valid_delay() - options Array: " . print_r( $this->options, true ) );
                return false;
        }
    }

    /**
     * Save the settings as metadata for the sequence
     *
     * @param $post_id -- ID of the sequence these options belong to.
     * @return int | mixed - Either the ID of the Sequence or its content
     *
     * @access public
     */
    public function save_post_meta( $post_id )
    {
        global $post;

        // Check that the function was called correctly. If not, just return
        if ( empty( $post_id ) ) {

            E20RTools\DBG::log('save_post_meta(): No post ID supplied...', E20R_DEBUG_SEQ_WARNING);
            return false;
        }

        if ( wp_is_post_revision( $post_id ) )
            return $post_id;

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return $post_id;
        }

        if ( ! isset( $post->post_type) || ( 'pmpro_sequence' != $post->post_type ) ) {
            return $post_id;
        }

        if ( 'trash' == get_post_status( $post_id ) ) {
            return $post_id;
        }

        if ( ! $this->init( $post_id ) ) {
            return;
        }

        E20RTools\DBG::log('save_post_meta(): Saving settings for sequence ' . $post_id);
        // E20RTools\DBG::log('From Web: ' . print_r($_REQUEST, true));

        // OK, we're authenticated: we need to find and save the data
        if ( isset($_POST['e20r_sequence_settings_noncename']) ) {

            E20RTools\DBG::log( 'save_post_meta() - Have to load new instance of Sequence class' );

            if ( ! $this->options ) {
                $this->options = $this->default_options();
            }

            if ( ($retval = $this->save_settings( $post_id, $this )) === true ) {

                E20RTools\DBG::log( "save_post_meta(): Saved metadata for sequence #{$post_id} and clearing the cache");
                $this->delete_cache($post_id);

                return true;
            }
            else
                return false;

        }

        return false; // Default
    }

	public function sanitize( $field ) {

        if ( ! is_numeric( $field ) ) {

            if ( is_array( $field ) ) {

                foreach( $field as $key => $val ) {
                    $field[$key] = $this->sanitize( $val );
                }
            }

            if ( is_object( $field ) ) {

                foreach( $field as $key => $val ) {
                    $field->{$key} = $this->sanitize( $val );
                }
            }

            if ( (! is_array( $field ) ) && ctype_alpha( $field ) ||
                 ( (! is_array( $field ) ) && strtotime( $field ) ) ||
                 ( (! is_array( $field ) ) && is_string( $field ) ) ) {

                $field = sanitize_text_field( $field ) ;
            }

        }
        else {

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
     * @return bool - Returns true if save is successful
     */
    public function save_settings( $sequence_id )
    {
        $settings = $this->options;
        E20RTools\DBG::log('Saving settings for Sequence w/ID: ' . $sequence_id);
        // E20RTools\DBG::log($_POST);

        // Check that the function was called correctly. If not, just return
        if(empty($sequence_id)) {
            E20RTools\DBG::log('save_settings(): No sequence ID supplied...');
            $this->set_error_msg( __('No sequence provided', "e20rsequence"));
            return false;
        }

        // Is this an auto save routine? If our form has not been submitted (clicked "save"), we'd probably not want to save anything yet
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
            $this->set_error_msg(null);
            return $sequence_id;
        }

        // Verify that we're allowed to update the sequence data
        // TODO: Use "has access" or something similar!
        if ( !current_user_can( 'edit_post', $sequence_id ) ) {
            E20RTools\DBG::log('save_settings(): User is not allowed to edit this post type', E20R_DEBUG_SEQ_CRITICAL);
            $this->set_error_msg( __('User is not allowed to change settings', "e20rsequence"));
            return false;
        }

        if ( isset( $_POST['hidden-e20r-sequence_wipesequence'] ) &&  ( 1 == intval( $_POST['hidden-e20r-sequence_wipesequence'] ) ) ) {

            E20RTools\DBG::log("Admin requested change of delay type configuration. Resetting the sequence!", E20R_DEBUG_SEQ_WARNING );

            if ( $sequence_id == $this->sequence_id ) {

                if ( !$this->delete_post_meta_for_sequence( $sequence_id ) ) {

                    E20RTools\DBG::log( 'Unable to delete the posts in sequence # ' . $sequence_id, E20R_DEBUG_SEQ_CRITICAL );
                    $this->set_error_msg( __('Unable to wipe existing posts', "e20rsequence") );
                }
                else {
                    E20RTools\DBG::log( 'Reloading sequence info');
                    $this->init( $sequence_id );
                }
            }
            else {
                E20RTools\DBG::log("the specified sequence id and the current sequence id were different!", E20R_DEBUG_SEQ_WARNING );
            }
        }

        $new_options = $this->get_options();

        if ( false === $new_options->loaded )  {

            E20RTools\DBG::log("No settings loaded/set. Using default settings.");
        }

        $form_checkboxes = array(
            'hideFuture', 'allowRepeatPosts', 'previewOffset',
            'lengthVisible', 'sendNotice',
        );

		foreach( $this->options as $field => $value )
		{
            if ( $field == 'loaded' ) {
                continue;
            }

			$tmp = isset( $_POST["e20r-sequence_{$field}"] ) ? $this->sanitize( $_POST["e20r-sequence_{$field}"] ) : null;

			E20RTools\DBG::log("Being saved: {$field} => {$tmp}");

			if ( empty( $tmp ) ) {
				$tmp = $this->options->{$field};
			}

			$this->options->{$field} = $tmp;

            if ( 'noticeTime' == $field ) {
                $this->options->noticeTimestamp = $this->calculate_timestamp($this->options->{$field});
            }

            if ( in_array($field, $form_checkboxes ) ) {
                $this->options->{$field} = ( !isset($_POST["e20r-sequence_{$field}"]) ? false : true);
            }
		}

        E20RTools\DBG::log("Trying to save... : " . print_r($this->options, true ));

		if ( $this->options->sendNotice == 0 ) {

                Tools\Cron::stop_sending_user_notices( $this->sequence_id );
        }

/*
        if ( isset($_POST['hidden-e20r-sequence_allowRepeatPosts']) )
        {
            $this->options->allowRepeatPosts = intval( $_POST['hidden_e20r_seq_allowRepeatPosts'] ) == 0 ? false : true;
            E20RTools\DBG::log('save_settings(): POST value for settings->allowRepeatPost: ' . intval($_POST['hidden_e20r_seq_allowRepeatPosts']) );
        }
        elseif (empty($this->options->allowRepeatPosts))
            $this->options->allowRepeatPosts = false;

        if ( isset($_POST['hidden_e20r_seq_future']) )
        {
            $this->options->hideFuture = intval( $_POST['hidden_e20r_seq_future'] ) == 0 ? false : true;
            E20RTools\DBG::log('save_settings(): POST value for settings->hideFuture: ' . intval($_POST['hidden_e20r_seq_future']) );
        }
        elseif (empty($this->options->hideFuture))
            $this->options->hideFuture = false;

       // Checkbox - not included during post/save if unchecked
        if ( isset($_POST['hidden_e20r_seq_future']) )
        {
            $this->options->hideFuture = intval($_POST['e20r_seq_future']);
            E20RTools\DBG::log('save_settings(): POST value for settings->hideFuture: ' . $_POST['hidden_e20r_seq_future'] );
        }
        elseif ( empty($this->options->hideFuture) )
            $this->options->hideFuture = 0;

        // Checkbox - not included during post/save if unchecked
        if (isset($_POST['hidden_e20r_seq_lengthvisible']) )
        {
            $this->options->lengthVisible = intval($_POST['hidden_e20r_seq_lengthvisible']);
            E20RTools\DBG::log('save_settings(): POST value for settings->lengthVisible: ' . $_POST['hidden_e20r_seq_lengthvisible']);
        }
        elseif (empty($this->options->lengthVisible)) {
            E20RTools\DBG::log('Setting lengthVisible to default value (checked)');
            $this->options->lengthVisible = 1;
        }

        if ( isset($_POST['hidden_e20r_seq_sortorder']) )
        {
            $this->options->sortOrder = intval($_POST['hidden_e20r_seq_sortorder']);
            E20RTools\DBG::log('save_settings(): POST value for settings->sortOrder: ' . $_POST['hidden_e20r_seq_sortorder'] );
        }
        elseif (empty($this->options->sortOrder))
            $this->options->sortOrder = SORT_ASC;

        if ( isset($_POST['hidden_e20r_seq_delaytype']) )
        {
            $this->options->delayType = sanitize_text_field($_POST['hidden_e20r_seq_delaytype']);
            E20RTools\DBG::log('save_settings(): POST value for settings->delayType: ' . sanitize_text_field($_POST['hidden_e20r_seq_delaytype']) );
        }
        elseif (empty($this->options->delayType))
            $this->options->delayType = 'byDays';

        // options->showDelayAs
        if ( isset($_POST['hidden_e20r_seq_showdelayas']) )
        {
            $this->options->showDelayAs = sanitize_text_field($_POST['hidden_e20r_seq_showdelayas']);
            E20RTools\DBG::log('save_settings(): POST value for settings->showDelayAs: ' . sanitize_text_field($_POST['hidden_e20r_seq_showdelayas']) );
        }
        elseif (empty($this->options->showDelayAs))
            $this->options->delayType = E20R_SEQ_AS_DAYNO;

        if ( isset($_POST['hidden_e20r_seq_offset']) )
        {
            $this->options->previewOffset = sanitize_text_field($_POST['hidden_e20r_seq_offset']);
            E20RTools\DBG::log('save_settings(): POST value for settings->previewOffset: ' . sanitize_text_field($_POST['hidden_e20r_seq_offset']) );
        }
        elseif (empty($this->options->previewOffset))
            $this->options->previewOffset = 0;

        if ( isset($_POST['hidden_e20r_seq_startwhen']) )
        {
            $this->options->startWhen = sanitize_text_field($_POST['hidden_e20r_seq_startwhen']);
            E20RTools\DBG::log('save_settings(): POST value for settings->startWhen: ' . sanitize_text_field($_POST['hidden_e20r_seq_startwhen']) );
        }
        elseif (empty($this->options->startWhen))
            $this->options->startWhen = 0;

        // Checkbox - not included during post/save if unchecked
        if ( isset($_POST['e20r_sequence_sendnotice']) )
        {
            $this->options->sendNotice = intval($_POST['e20r_sequence_sendnotice']);

            if ( $this->options->sendNotice == 0 ) {

                Tools\Cron::stop_sending_user_notices( $this->sequence_id );
            }

            E20RTools\DBG::log('save_settings(): POST value for settings->sendNotice: ' . intval($_POST['e20r_sequence_sendnotice']) );
        }
        elseif (empty($this->options->sendNotice)) {
            $this->options->sendNotice = 1;
        }

        if ( isset($_POST['hidden_e20r_seq_sendas']) )
        {
            $this->options->noticeSendAs = sanitize_text_field($_POST['hidden_e20r_seq_sendas']);
            E20RTools\DBG::log('save_settings(): POST value for settings->noticeSendAs: ' . sanitize_text_field($_POST['hidden_e20r_seq_sendas']) );
        }
        else
            $this->options->noticeSendAs = E20R_SEQ_SEND_AS_SINGLE;

        if ( isset($_POST['hidden_e20r_seq_noticetemplate']) )
        {
            $this->options->noticeTemplate = sanitize_text_field($_POST['hidden_e20r_seq_noticetemplate']);
            E20RTools\DBG::log('save_settings(): POST value for settings->noticeTemplate: ' . sanitize_text_field($_POST['hidden_e20r_seq_noticetemplate']) );
        }
        else
            $this->options->noticeTemplate = 'new_content.html';

        if ( isset($_POST['hidden_e20r_seq_noticetime']) )
        {
            $this->options->noticeTime = sanitize_text_field($_POST['hidden_e20r_seq_noticetime']);
            E20RTools\DBG::log('noticeTime in settings: ' . $this->options->noticeTime);

            // Calculate the timestamp value for the noticeTime specified (noticeTime is in current timezone)
            $this->options->noticeTimestamp = $this->calculate_timestamp($settings->noticeTime);

            E20RTools\DBG::log('save_settings(): POST value for settings->noticeTime: ' . sanitize_text_field($_POST['hidden_e20r_seq_noticetime']) );
            E20RTools\DBG::log('save_settings(): Which translates to a timestamp value of: ' . $this->options->noticeTimestamp );
        }
        else
            $this->options->noticeTime = '00:00';

        if ( isset($_POST['hidden_e20r_seq_excerpt']) )
        {
            $this->options->excerpt_intro = sanitize_text_field($_POST['hidden_e20r_seq_excerpt']);
            E20RTools\DBG::log('save_settings(): POST value for settings->excerpt_intro: ' . sanitize_text_field($_POST['hidden_e20r_seq_excerpt']) );
        }
        else
            $this->options->excerpt_intro = 'A summary of the post follows below:';

        if ( isset($_POST['hidden_e20r_seq_fromname']) )
        {
            $this->options->fromname = sanitize_text_field($_POST['hidden_e20r_seq_fromname']);
            E20RTools\DBG::log('save_settings(): POST value for settings->fromname: ' . sanitize_text_field($_POST['hidden_e20r_seq_fromname']) );
        }
        else
            $this->options->fromname = $this->get_membership_setting( 'from_name' );

        if ( isset($_POST['hidden_e20r_seq_dateformat']) )
        {
            $this->options->dateformat = sanitize_text_field($_POST['hidden_e20r_seq_dateformat']);
            E20RTools\DBG::log('save_settings(): POST value for settings->dateformat: ' . sanitize_text_field($_POST['hidden_e20r_seq_dateformat']) );
        }
        else
            $this->options->dateformat = __('m-d-Y', "e20rsequence"); // Default is MM-DD-YYYY (if translation supports it)

        if ( isset($_POST['hidden_e20r_seq_replyto']) )
        {
            $this->options->replyto = sanitize_text_field($_POST['hidden_e20r_seq_replyto']);
            E20RTools\DBG::log('save_settings(): POST value for settings->replyto: ' . sanitize_text_field($_POST['hidden_e20r_seq_replyto']) );
        }
        else
            $this->options->replyto = $this->get_membership_setting('from_email');

        if ( isset($_POST['hidden_e20r_seq_subject']) )
        {
            $this->options->subject = sanitize_text_field($_POST['hidden_e20r_seq_subject']);
            E20RTools\DBG::log('save_settings(): POST value for settings->subject: ' . sanitize_text_field($_POST['hidden_e20r_seq_subject']) );
        }
        else
            $this->options->subject = __('New Content ', "e20rsequence");

*/
        // Schedule cron jobs if the user wants us to send notices.
        if ( $this->options->sendNotice == 1 ) {

            E20RTools\DBG::log( 'Updating the cron job for sequence ' . $this->sequence_id );

            if (!Tools\Cron::update_user_notice_cron())
            {
                E20RTools\DBG::log('Error configuring cron() system for sequence ' . $this->sequence_id, E20R_DEBUG_SEQ_CRITICAL);
            }
        }

        E20RTools\DBG::log("Flush the cache for {$this->sequence_id}");
        $this->delete_cache($this->sequence_id);

        // Save settings to WPDB
        return $this->save_sequence_meta($this->options, $sequence_id);
    }

    /**
      * Delete all post meta for sequence (can be used by delete_post action).
      * @param null $sequence_id - Id of sequence to delete metadata for (optional)
      * @return bool - True if successful, false on error
      */
    public function delete_post_meta_for_sequence( $sequence_id = null) {

        if (is_null( $sequence_id))
        {
            global $post;

            if (isset($post->ID) && !empty($post->ID) && 'pmpro_sequence' == $post->post_type )
            {
                $sequence_id = $post->ID;
            }
        }

        $retval = false;

        if ( delete_post_meta_by_key( "_pmpro_sequence_{$sequence_id}_post_delay" ) ) {
            $retval = true;
        }

        foreach( $this->posts as $post ) {

            if ( delete_post_meta( $post->id, "_pmpro_sequence_post_belongs_to", $sequence_id ) ) {
                $retval = true;
            }

            if ( $retval != true ) {

                E20RTools\DBG::log("ERROR deleting sequence metadata for post {$post->id}: ", E20R_DEBUG_SEQ_CRITICAL );
            }
        }


        return $retval;
    }

    public function get_membership_setting( $option_name )
    {
        $val = '';

        if ( function_exists('pmpro_getOption')) {

            $val = pmpro_getOption($option_name);
        }

        return apply_filters( 'e20r-sequence-get-membership-setting', $val, $option_name);
    }

    /**
     * Converts a timeString to a timestamp value (UTC compliant).
     * Will use the supplied timeString to calculate & return the UTC seconds-since-epoch for that clock time tomorrow.
     *
     * @param $timeString (string) -- A clock value ('12:00 AM' for instance)
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
        $timezone = get_option('timezone_string');

        $saved_tz = ini_get('date.timezone');
        E20RTools\DBG::log("PHP Configured timezone: {$saved_tz} vs wordpress: {$timezone}");

        // Verify the timezone to use (the Wordpress timezone)
        if ($timezone != $saved_tz) {

            if (!ini_set("date.timezone", $timezone)) {
                E20RTools\DBG::log("WARNING: Unable to set the timezone value to: {$timezone}!");
            }

            $saved_tz = ini_get('date.timezone');
        }

        $tz =  get_option('gmt_offset');

        // Now in the Wordpress local timezone
        $now = current_time('timestamp', true);
        $time = "today {$timeString} {$saved_tz}";

        E20RTools\DBG::log("Using time string for strtotime(): {$time}");
        $req = strtotime($time);

        E20RTools\DBG::log("Current time: {$now} when using UTC vs {$req} in {$saved_tz}");

        try {

            /* current time & date */
            $schedHour = date_i18n( 'H', $req );
            $nowHour = date_i18n('H', $now);

            E20RTools\DBG::log("calculate_timestamp() - Timestring: {$timeString}, scheduled Hour: {$schedHour} and current Hour: {$nowHour}" );

            $timestamp = strtotime("today {$timeString} {$saved_tz}");

            E20RTools\DBG::log("calculate_timestamp() - {$timeString} will be ({$timestamp}) vs. " . current_time('timestamp', true));

            if ($timestamp < (current_time('timestamp', true) + 15*60)) {
                $timestamp = strtotime("+1 day", $timestamp);
            }



/*
            $hourDiff = $schedHour - $nowHour;
            $hourDiff += ( ( ($hourDiff == 0) && (($schedMin - $nowMin) <= 0 )) ? 0 : 1);

            if ( $hourDiff >= 1 ) {
                E20RTools\DBG::log('calculate_timestamp() - Assuming current day');
                $when = ''; // Today
            }
            else {
                E20RTools\DBG::log('calculate_timestamp() - Assuming tomorrow');
                $when = 'tomorrow ';
            }

            // Create the string we'll use to generate a timestamp for cron()
            $timeInput = $when . $timeString . ' ' . get_option('timezone_string');
            $timestamp = strtotime($timeInput);
*/
        }
        catch (Exception $e)
        {
            E20RTools\DBG::log('calculate_timestamp() -- Error calculating timestamp: : ' . $e->getMessage());
        }

        return $timestamp;
    }

    public function remove_post_alert_callback() {

        E20RTools\DBG::log("remove_post_alert_callback() - Attempting to remove the alerts for a post");
        check_ajax_referer('e20r-sequence-post-meta', 'e20r_sequence_postmeta_nonce');

        // Fetch the ID of the sequence to add the post to
        $sequence_id = isset( $_POST['e20r_sequence_id'] ) && !empty( $_POST['e20r_sequence_id'] ) ? intval($_POST['e20r_sequence_id']) : null;
        $post_id = isset( $_POST['e20r_sequence_post'] ) && !empty( $_POST['e20r_sequence_post'] ) ? intval( $_POST['e20r_sequence_post'] ) : null;

        if ( isset( $_POST['e20r_sequence_post_delay'] ) && !empty( $_POST['e20r_sequence_post_delay'] ) ) {

            $date = preg_replace("([^0-9/])", "", $_POST['e20r_sequence_post_delay']);

            if ( ( $date == $_POST['e20r_sequence_post_delay'] ) || ( is_null($date)) ) {

                $delay = intval( $_POST['e20r_sequence_post_delay'] );

            } else {

                $delay = sanitize_text_field( $_POST['e20r_sequence_post_delay']);
            }
        }

        E20RTools\DBG::log( "remove_post_alert_callback() - We received sequence ID: {$sequence_id} and post ID: {$post_id}");

        if ( !is_null( $sequence_id ) && !is_null($post_id) && is_admin() ) {

            E20RTools\DBG::log("remove_post_alert_callback() - Loading settings for sequence {$sequence_id} ");
            $this->get_options( $sequence_id );

            E20RTools\DBG::log("remove_post_alert_callback() - Requesting removal of alert notices for post {$post_id} with delay {$delay} in sequence {$sequence_id} ");
            $result = $this->remove_post_notified_flag( $post_id, $delay );

            if ( is_array( $result ) ) {

                $list = join(', ', $result);
                wp_send_json_error( $list );

            } else {

                wp_send_json_success();
            }
        }

        wp_send_json_error( 'Missing data in AJAX call' );
    }

    /**
     * Callback for saving the sequence alert optin/optout for the current user
     */
    public function optin_callback()
    {
        global $current_user, $wpdb;

        /** @noinspection PhpUnusedLocalVariableInspection */
        $result = '';

        try {

            check_ajax_referer('e20r-sequence-user-optin', 'e20r_sequence_optin_nonce');

            if ( isset($_POST['hidden_e20r_seq_id'])) {

                $seqId = intval( $_POST['hidden_e20r_seq_id']);
            }
            else {

                E20RTools\DBG::log( 'No sequence number specified. Ignoring settings for user', E20R_DEBUG_SEQ_WARNING );

                wp_send_json_error( __('Unable to save your settings', "e20rsequence") );
            }

            if ( isset($_POST['hidden_e20r_seq_uid'])) {

                $user_id = intval($_POST['hidden_e20r_seq_uid']);
                E20RTools\DBG::log('Updating user settings for user #: ' . $user_id);

                // Grab the metadata from the database
                // $usrSettings = get_user_meta($user_id, $wpdb->prefix . 'pmpro_sequence' . '_notices', true);
                $usrSettings = $this->load_user_notice_settings( $user_id, $seqId );

            }
            else {
                E20RTools\DBG::log( 'No user ID specified. Ignoring settings!', E20R_DEBUG_SEQ_WARNING );

                wp_send_json_error( __('Unable to save your settings', "e20rsequence") );
            }

            if ( !$this->init( $seqId ) ) {

                wp_send_json_error( $this->get_error_msg() );
            }

            E20RTools\DBG::log('Updating user settings for sequence #: ' . $this->sequence_id);

            if ( isset( $usrSettings->id ) && ( $usrSettings->id !== $this->sequence_id ) ) {

                E20RTools\DBG::log('No user specific settings found for this sequence. Creating defaults');

/*
                // Create new opt-in settings for this user
                if ( empty($usrSettings->sequence) )
                    $new = new \stdClass();
                else // Saves existing settings
                    $new = $usrSettings;
*/
                E20RTools\DBG::log('Using default setting for user ' . $current_user->ID . ' and sequence ' . $this->sequence_id);

                $usrSettings = $this->create_user_notice_defaults();
            }

            // $usrSettings->sequence[$seqId]->sendNotice = ( isset( $_POST['hidden_e20r_seq_useroptin'] ) ?
            $usrSettings->send_notices = ( isset( $_POST['hidden_e20r_seq_useroptin'] ) ?
                intval($_POST['hidden_e20r_seq_useroptin']) : $this->options->sendNotice );

            // If the user opted in to receiving alerts, set the opt-in timestamp to the current time.
            // If they opted out, set the opt-in timestamp to -1

            if ($usrSettings->send_notices == 1) {

                // Fix the alert settings so the user doesn't receive old alerts.

                $member_days = $this->get_membership_days( $user_id );
                $post_list =$this->load_sequence_post( null, $member_days, null, '<=', null, true );

                $usrSettings = $this->fix_user_alert_settings( $usrSettings, $post_list, $member_days );

                // Set the timestamp when the user opted in.
                $usrSettings->last_notice_sent = current_time( 'timestamp' );
                $usrSettings->optin_at = current_time( 'timestamp' );

            }
            else {
                $usrSettings->last_notice_sent = -1; // Opted out.
                $usrSettings->optin_at = -1;
            }


            // Add an empty array to store posts that the user has already been notified about
/*                if ( empty( $usrSettings->posts ) ) {
                $usrSettings->posts = array();
            }
*/
            /* Save the user options we just defined */
            if ( $user_id == $current_user->ID ) {

                E20RTools\DBG::log('Opt-In Timestamp is: ' . $usrSettings->last_notice_sent);
                E20RTools\DBG::log('Saving user_meta for UID ' . $user_id . ' Settings: ' . print_r($usrSettings, true));

                $this->save_user_notice_settings( $user_id, $usrSettings, $seqId );
                // update_user_meta( $user_id, $wpdb->prefix . 'pmpro_sequence' . '_notices', $usrSettings );
                $status = true;
                $this->set_error_msg(null);
            }
            else {

                E20RTools\DBG::log('Error: Mismatched User IDs -- user_id: ' . $user_id . ' current_user: ' . $current_user->ID, E20R_DEBUG_SEQ_CRITICAL);
                $this->set_error_msg( __( 'Unable to save your settings', "e20rsequence" ) );
                $status = false;
            }
        }
        catch (Exception $e) {
            $this->set_error_msg( sprintf( __('Error: %s', "e20rsequence" ), $e->getMessage() ) );
            $status = false;
            E20RTools\DBG::log('optin_save() - Exception error: ' . $e->getMessage(), E20R_DEBUG_SEQ_CRITICAL);
        }

        if ($status)
            wp_send_json_success();
        else
            wp_send_json_error( $this->get_error_msg() );

    }

    private function fix_user_alert_settings( $v3, $post_list, $member_days ) {

        E20RTools\DBG::log("fix_user_alert_settings() - Checking whether to convert the post notification flags for {$v3->id}");

        $need_to_fix = false;

        foreach( $v3->posts as $id ) {

            if ( false === strpos( $id, '_' ) ) {

                E20RTools\DBG::log("fix_user_alert_settings() - Should to fix Post/Delay id {$id}");
                $need_to_fix = true;
            }
        }

        if ( count( $v3->posts ) < count( $post_list ) )  {

            E20RTools\DBG::log("fix_user_alert_settings() - Not enough alert IDs (" . count( $v3->posts ) . ") as compared to the posts in the sequence (". count( $post_list ). ")");
            $need_to_fix = true;
        }

        if ( true === $need_to_fix ) {

            E20RTools\DBG::log("fix_user_alert_settings() - The number of posts with a delay value less than {$member_days} is: " . count( $post_list ));

            if ( !empty( $v3->posts ) ) {

                foreach( $post_list as $p ) {

                    $flag_value = "{$p->id}_" . $this->normalize_delay( $p->delay );

                    foreach( $v3->posts as $k => $id ) {

                        // Do we have a post ID as the identifier (and not a postID_delay flag)
                        if ( $p->id == $id ) {

                            E20RTools\DBG::log("fix_user_alert_settings() - Replacing: {$p->id} -> {$flag_value}");
                            $v3->posts[$k] = $flag_value;
                        }
                        elseif ( !in_array( $flag_value, $v3->posts) ) {

                            E20RTools\DBG::log("fix_user_alert_settings() - Should be in array, but isn't. Adding as 'already alerted': {$flag_value}");
                            $v3->posts[] = $flag_value;
                        }
                    }
                }
            }
            elseif ( empty($v3->posts ) && !empty( $post_list ) ) {

                foreach( $post_list as $p ) {

                    $flag_value = "{$p->id}_" . $this->normalize_delay( $p->delay );

                    E20RTools\DBG::log("fix_user_alert_settings() - Should be in array, but isn't. Adding as 'already alerted': {$flag_value}");
                    $v3->posts[] = $flag_value;
                }
            }

            $v3->last_notice_sent = current_time('timestamp');
        }

        return $v3;
    }

    /**
     * Callback to catch request from admin to send any new Sequence alerts to the users.
     *
     * Triggers the cron hook to achieve it.
     */
    public function sendalert_callback() {

        E20RTools\DBG::log('sendalert() - Processing the request to send alerts manually');

        check_ajax_referer('e20r-sequence-sendalert', 'e20r_sequence_sendalert_nonce');

        E20RTools\DBG::log('Nonce is OK');

        if ( isset( $_POST['e20r_sequence_id'] ) ) {

            $sequence_id = intval($_POST['e20r_sequence_id']);
            E20RTools\DBG::log('sendalert() - Will send alerts for sequence #' . $sequence_id);

//                $sequence = apply_filters('get_sequence_class_instance', null);
//                $sequence->sequence_id = $sequence_id;
//                $sequence->get_options( $sequence_id );

            do_action( 'e20r_sequence_cron_hook', array( $sequence_id ));

            E20RTools\DBG::log('sendalert() - Completed action for sequence');
        }
    }

    /**
     * Catches ajax POSTs from dashboard/edit for CPT (clear existing sequence members)
     */
    public function sequence_clear_callback() {

        // Validate that the ajax referrer is secure
        check_ajax_referer('e20r-sequence-save-settings', 'e20r_sequence_settings_nonce');

        /** @noinspection PhpUnusedLocalVariableInspection */
        $result = '';
        $view = apply_filters('get_sequence_views_class_instance', null);

        // Clear the sequence metadata if the sequence type (by date or by day count) changed.
        if (isset($_POST['e20r_sequence_clear']))
        {
            if (isset($_POST['e20r_sequence_id']))
            {
                $sequence_id = intval($_POST['e20r_sequence_id']);

                if (! $this->init( $sequence_id ) ) {
                    wp_send_json_error( $this->get_error_msg() );
                }

                E20RTools\DBG::log('sequence_clear_callback() - Deleting all entries in sequence # ' .$sequence_id);

                if ( !$this->delete_post_meta_for_sequence($sequence_id) )
                {
                    E20RTools\DBG::log('Unable to delete the posts in sequence # ' . $sequence_id, E20R_DEBUG_SEQ_CRITICAL);
                    $this->set_error_msg( __('Could not delete posts from this sequence', "e20rsequence"));

                }
                else {
                    $result = $view->get_post_list_for_metabox();
                }

            }
            else
            {
                $this->set_error_msg( __('Unable to identify the Sequence', "e20rsequence") );
            }
        }
        else {
            $this->set_error_msg( __('Unknown request', "e20rsequence") );
        }

        // Return the status to the calling web page
        if ( $result['success'] )
            wp_send_json_success( $result['html']  );
        else
            wp_send_json_error( $this->get_error_msg() );

    }

    /**
     * Used by the Sequence CPT edit page to remove a post from the sequence being processed
     *
     * Process AJAX based removals of posts from the sequence list
     *
     * Returns 'error' message (or nothing, if success) to calling JavaScript function
     */
    public function rm_post_callback() {

        E20RTools\DBG::log("rm_post_callback() - Attempting to remove post from sequence.");

        global $current_user;

        check_ajax_referer('e20r-sequence-post', 'e20r_sequence_post_nonce');

        /** @noinspection PhpUnusedLocalVariableInspection */
        $result = '';

        /** @noinspection PhpUnusedLocalVariableInspection */
        $success = false;

        $sequence_id = ( isset( $_POST['e20r_sequence_id']) && '' != $_POST['e20r_sequence_id'] ? intval($_POST['e20r_sequence_id']) : null );
        $seq_post_id = ( isset( $_POST['e20r_seq_post']) && '' != $_POST['e20r_seq_post'] ? intval($_POST['e20r_seq_post']) : null );
        $delay = ( isset( $_POST['e20r_seq_delay']) && '' != $_POST['e20r_seq_delay'] ? intval($_POST['e20r_seq_delay']) : null );

        $view = apply_filters('get_sequence_views_class_instance', null);

        if ( ! $this->init( $sequence_id ) ) {

            wp_send_json_error( $this->get_error_msg() );
        }

        // Remove the post (if the user is allowed to)
        if ( $this->user_can_edit( $current_user->ID ) && !is_null($seq_post_id) ) {

            $this->remove_post( $seq_post_id, $delay );
            $this->set_error_msg(sprintf(__("'%s' has been removed","e20rsequence"), get_the_title($seq_post_id)));
            //$result = __('The post has been removed', "e20rsequence");
            $success = true;

        }
        else {

            $success = false;
            $this->set_error_msg( __( 'Incorrect privileges: Did not update this sequence', "e20rsequence"));
        }

        // Return the content for the new listbox (sans the deleted item)
        $result = $view->get_post_list_for_metabox(true);

/*
        if ( is_null( $result['message'] ) && is_null( $this->get_error_msg() ) && ($success)) {
            E20RTools\DBG::log('rm_post_callback() - Returning success to calling javascript');
            wp_send_json_success( $result['html'] );
        }
        else
            wp_send_json_success( $result );
*/
		wp_send_json_success($result);
    }

    /**
     * Removes the sequence from managing this $post_id.
     * Returns the table of sequences the post_id belongs to back to the post/page editor using JSON.
     */
    public function rm_sequence_from_post_callback() {

        /** @noinspection PhpUnusedLocalVariableInspection */
        $success = false;
        $view = apply_filters('get_sequence_views_class_instance', null);

        // E20RTools\DBG::log("In rm_sequence_from_post()");
        check_ajax_referer('e20r-sequence-post-meta', 'e20r_sequence_postmeta_nonce');

        E20RTools\DBG::log("NONCE is OK for e20r_sequence_rm");

        $sequence_id = ( isset( $_POST['e20r_sequence_id'] ) && ( intval( $_POST['e20r_sequence_id'] ) != 0 ) ) ? intval( $_POST['e20r_sequence_id'] ) : null;
        $post_id = isset( $_POST['e20r_seq_post_id'] ) ? intval( $_POST['e20r_seq_post_id'] ) : null;
        $delay = isset( $_POST['e20r_seq_delay'] ) ? intval( $_POST['e20r_seq_delay'] ) : null;

        if ( !$this->init( $sequence_id ) ) {
            wp_send_json_error( $this->get_error_msg() );
        }

        $this->set_error_msg( null ); // Clear any pending error messages (don't care at this point).

        // Remove the post (if the user is allowed to)
        if ( current_user_can( 'edit_posts' ) && ( ! is_null( $post_id ) ) && ( ! is_null( $sequence_id ) ) ) {

            E20RTools\DBG::log("Removing post # {$post_id} with delay {$delay} from sequence {$sequence_id}");
            $this->remove_post( $post_id, $delay, true );
            //$result = __('The post has been removed', "e20rsequence");
            $success = true;
        } else {

            $success = false;
            $this->set_error_msg( __( 'Incorrect privileges to remove posts from this sequence', "e20rsequence" ) );
        }

        $result = $view->load_sequence_list_meta( $post_id, $sequence_id );

        if ( ! empty( $result ) && is_null( $this->get_error_msg() ) && ( $success ) ) {

            E20RTools\DBG::log( 'Returning success to caller' );
            wp_send_json_success( $result );
        } else {

            wp_send_json_error( ( ! is_null( $this->get_error_msg() ) ? $this->get_error_msg() : 'Error clearing the sequence from this post' ) );
        }
    }

    /**
     * Determines if a post, identified by the specified ID, exist
     * within the WordPress database.
     *
     * @param    int    $id    The ID of the post to check
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
     * @return bool|int -- The delay value for this post (numerical - even when delayType is byDate)
     *
     * @access private
     */
    public function get_delay_for_post( $post_id, $normalize = true ) {

        E20RTools\DBG::log("Loading post# {$post_id}");

        $posts = $this->find_by_id( $post_id );

        E20RTools\DBG::log("Found " . count($posts) . " posts.");

        if ( empty( $posts ) ) {
            $posts = array();
        }

        foreach( $posts as $k => $post ) {

            // BUG: Would return "days since membership start" as the delay value, regardless of setting.
            // Fix: Choose whether to normalize (but leave default as "yes" to ensure no unintentional breakage).
            if ( true === $normalize ) {

                $posts[$k]->delay = $this->normalize_delay( $post->delay );
            }

            E20RTools\DBG::log("get_delay_for_post(): Delay for post with id = {$post_id} is {$posts[$k]->delay}");
        }

        return $posts;
    }

    /**
     * Updates the delay for a post in the specified sequence (AJAX)
     *
     * @throws Exception
     */
    public function update_delay_post_meta_callback() {

        E20RTools\DBG::log("Update the delay input for the post/page meta");

        check_ajax_referer('e20r-sequence-post-meta', 'e20r_sequence_postmeta_nonce');

        E20RTools\DBG::log("Nonce Passed for postmeta AJAX call");

        $seq_id = isset( $_POST['e20r_sequence_id'] ) ? intval( $_POST['e20r_sequence_id'] ) : null;
        $post_id = isset( $_POST['e20r_sequence_post_id']) ? intval( $_POST['e20r_sequence_post_id'] ) : null;

        E20RTools\DBG::log("Sequence: {$seq_id}, Post: {$post_id}" );

        if ( ! $this->init( $seq_id ) ) {
            wp_send_json_error( $this->get_error_msg() );
        }

        $view = apply_filters('get_sequence_views_class_instance', null);
        $html = $view->load_sequence_list_meta( $post_id, $seq_id );

        wp_send_json_success( $html );
    }

    /**
     * Process AJAX based additions to the sequence list
     *
     * Returns 'error' message (or nothing, if success) to calling JavaScript function
     */
    public function add_post_callback() {

        check_ajax_referer('e20r-sequence-post', 'e20r_sequence_post_nonce');

        global $current_user;

        // Fetch the ID of the sequence to add the post to
        $sequence_id = isset( $_POST['e20r_sequence_id'] ) && !empty( $_POST['e20r_sequence_id'] ) ? intval($_POST['e20r_sequence_id']) : null;
        $seq_post_id = isset( $_POST['e20r_sequence_post'] ) && !empty( $_POST['e20r_sequence_post'] ) ? intval( $_REQUEST['e20r_sequence_post'] ) : null;

        $view = apply_filters('get_sequence_views_class_instance', null);

        E20RTools\DBG::log( "add_post_callback() - We received sequence ID: {$sequence_id}");

        if ( !empty( $sequence_id ) ) {

            // Initiate & configure the Sequence class
            if ( ! $this->init( $sequence_id ) ) {

                wp_send_json_error( $this->get_error_msg() );
            }

            if ( isset( $_POST['e20r_sequence_delay'] ) && ( 'byDate' == $this->options->delayType ) ) {

                E20RTools\DBG::log("add_post_callback() - Could be a date we've been given ({$_POST['e20r_sequence_delay']}), so...");

                if ( ( 'byDate' == $this->options->delayType ) && ( false != strtotime( $_POST['e20r_sequence_delay']) ) ) {

                    E20RTools\DBG::log("add_post_callback() - Validated that Delay value is a date.");
                    $delayVal = isset( $_POST['e20r_sequence_delay'] ) ? sanitize_text_field( $_POST['e20r_sequence_delay'] ) : null;
                }
            }
            else {

                E20RTools\DBG::log("add_post_callback() - Validated that Delay value is probably a day nunmber.");
                $delayVal = isset( $_POST['e20r_sequence_delay'] ) ? intval( $_POST['e20r_sequence_delay'] ) : null ;
            }

            E20RTools\DBG::log( 'add_post_callback() - Checking whether delay value is correct' );
            $delay = $this->validate_delay_value( $delayVal );

            if ( $this->is_present( $seq_post_id, $delay ) ) {

                E20RTools\DBG::log("add_post_callback() - Post {$seq_post_id} with delay {$delay} is already present in sequence {$sequence_id}");
                $this->set_error_msg( __( 'Not configured to allow multiple delays for the same post/page', "e20rsequence" ) );

                wp_send_json_error( $this->get_error_msg() );
                return;
            }

            // Get the Delay to use for the post (depends on type of delay configured)
            if ( $delay !== false ) {

                $user_can = apply_filters( 'e20r-sequence-has-edit-privileges', $this->user_can_edit( $current_user->ID ) );

                if ( $user_can && ! is_null( $seq_post_id ) ) {

                    E20RTools\DBG::log( 'add_post_callback() - Adding post ' . $seq_post_id . ' to sequence ' . $this->sequence_id );

                    if ( $this->add_post( $seq_post_id, $delay ) ) {

                        $success = true;
                        // $this->set_error_msg( null );
                    }
                    else {
                        $success = false;
                        $this->set_error_msg( __( sprintf( "Error adding post with ID: %s and delay value: %s to this sequence", 'e20rsequence' ), esc_attr( $seq_post_id ), esc_attr($delay) ));
                    }

                } else {
                    $success = false;
                    $this->set_error_msg( __( 'Not permitted to modify the sequence', "e20rsequence" ) );
                }

            } else {

                E20RTools\DBG::log( 'e20r_sequence_add_post_callback(): Delay value was not specified. Not adding the post: ' . esc_attr( $_POST['e20r_sequencedelay'] ) );

                if ( empty( $seq_post_id ) && ( $this->get_error_msg() == null ) ) {

                    $this->set_error_msg( sprintf( __( 'Did not specify a post/page to add', "e20rsequence" ) ) );
                }
                elseif ( ( $delay !== 0 ) && empty( $delay ) ) {

                    $this->set_error_msg( __( 'No delay has been specified', "e20rsequence" ) );
                }

                $delay       = null;
                $seq_post_id = null;

                $success = false;

            }

            if ( empty( $seq_post_id ) && ( $this->get_error_msg() == null ) ) {

                $success = false;
                $this->set_error_msg( sprintf( __( 'Did not specify a post/page to add', "e20rsequence" ) ) );
            }
            elseif ( empty( $sequence_id ) && ( $this->get_error_msg() == null ) ) {

                $success = false;
                $this->set_error_msg( sprintf( __( 'This sequence was not found on the server!', "e20rsequence" ) ) );
            }

            $result = $view->get_post_list_for_metabox(true);

            // E20RTools\DBG::log("e20r_sequence_add_post_callback() - Data added to sequence. Returning to calling JS script");

            if ( $result['success'] && $success ) {
                E20RTools\DBG::log( 'e20r_sequence_add_post_callback() - Returning success to javascript frontend' );

                wp_send_json_success( $result );

            }
            else {

                E20RTools\DBG::log( 'e20r_sequence_add_post_callback() - Returning error to javascript frontend' );
                wp_send_json_error( $result );
            }
        }
        else {
            E20RTools\DBG::log( "Sequence ID was 0. That's a 'blank' sequence" );
            wp_send_json_error( array( array('message' => __('No sequence specified. Did you remember to save this page first?', 'e20rsequence'))) );
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

        if ( ($delay !== 0) && ( ! empty( $delay ) ) ) {

            // Check that the provided delay format matches the configured value.
            if ( $this->is_valid_delay( $delay ) ) {

                E20RTools\DBG::log( 'validate_delay_value(): Delay value is recognizable' );

                if ( $this->is_valid_date( $delay ) ) {

                    E20RTools\DBG::log( 'validate_delay_value(): Delay specified as a valid date format' );

                } else {

                    E20RTools\DBG::log( 'validate_delay_value(): Delay specified as the number of days' );
                }
            } else {
                // Ignore this post & return error message to display for the user/admin
                // NOTE: Format of date is not translatable
                $expectedDelay = ( $this->options->delayType == 'byDate' ) ? __( 'date: YYYY-MM-DD', "e20rsequence" ) : __( 'number: Days since membership started', "e20rsequence" );

                E20RTools\DBG::log( 'validate_delay_value(): Invalid delay value specified, not adding the post. Delay is: ' . $delay );
                $this->set_error_msg( sprintf( __( 'Invalid delay specified ( %1$s ). Expected format is a %2$s', "e20rsequence" ), $delay, $expectedDelay ) );

                $delay = false;
            }
        } elseif ($delay === 0) {

            // Special case:
            return $delay;

        } else {

            E20RTools\DBG::log( 'validate_delay_value(): Delay value was not specified. Not adding the post. Delay is: ' . esc_attr( $delay ) );

            if ( empty( $delay ) ) {

                $this->set_error_msg( __( 'No delay has been specified', "e20rsequence" ) );
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
        $seqs = \WP_Query( array( 'post_type' => 'pmpro_sequence' ) );

        // Iterate through all sequences and disable any cron jobs causing alerts to be sent to users
        foreach($seqs as $s) {

            $this->get_options( $s->ID );

            if ( $this->options->sendNotice == 1 ) {

                // Set the alert flag to 'off'
                $this->options->sendNotice = 0;

                // save meta for the sequence.
                $this->save_sequence_meta();

                Tools\Cron::stop_sending_user_notices( $s->ID );

                E20RTools\DBG::log('Deactivated email alert(s) for sequence ' . $s->ID);
            }
        }

        /* Unregister the default Cron job for new content alert(s) */
        Tools\Cron::stop_sending_user_notices();
    }

    /**
     * Activation hook for the plugin
     * We need to flush rewrite rules on activation/etc for the CPTs.
     */
    public function activation()
    {
        if ( ! function_exists( 'pmpro_getOption' ) ) {

            $errorMessage = __( "The Eighty/20 Results Sequence plugin requires the ", "e20rsequence" );
            $errorMessage .= "<a href='http://www.paidmembershipspro.com/' target='_blank' title='" . __("Opens in a new window/tab.", "e20rsequence" ) . "'>";
            $errorMessage .= __( "Paid Memberships Pro</a> membership plugin.<br/><br/>", "e20rsequence" );
            $errorMessage .= __( "Please install Paid Memberships Pro before attempting to activate this Eighty/20 Results Sequence plugin.<br/><br/>", "e20rsequence");
            $errorMessage .= __( "Click the 'Back' button in your browser to return to the Plugin management page.", "e20rsequence" );
            wp_die($errorMessage);
        }

        Sequence\Controller::create_custom_post_type();
        flush_rewrite_rules();

        /* Search for existing pmpro_series posts & import */
        Main\e20r_sequences_import_all_PMProSeries();
        Main\e20r_sequences_import_all_PMProSequence();

        /* Convert old metadata format to new (v3) format */

        $sequence = apply_filters('get_sequence_class_instance', null);
        $sequences = $sequence->get_all_sequences();

        E20RTools\DBG::log("activation() - Found " . count( $sequences ) . " to convert");

        foreach( $sequences as $seq ) {

            E20RTools\DBG::log("activation() - Converting postmeta to v3 format for {$seq->ID}" );

            if ( !$sequence->is_converted( $seq->ID ) ) {

                $sequence->get_options( $seq->ID );
                $sequence->convert_posts_to_v3( $seq->ID );
            }
        }

        /* Register the default cron job to send out new content alerts */
        Tools\Cron::schedule_default();

        $this->convert_user_notifications();
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

        $labels =  array(
            'name' => __( 'Sequences', "e20rsequence"  ),
            'singular_name' => __( 'Sequence', "e20rsequence" ),
            'slug' => 'e20r_sequence',
            'add_new' => __( 'New Sequence', "e20rsequence" ),
            'add_new_item' => __( 'New Sequence', "e20rsequence" ),
            'edit' => __( 'Edit Sequence', "e20rsequence" ),
            'edit_item' => __( 'Edit Sequence', "e20rsequence"),
            'new_item' => __( 'Add New', "e20rsequence" ),
            'view' => __( 'View Sequence', "e20rsequence" ),
            'view_item' => __( 'View This Sequence', "e20rsequence" ),
            'search_items' => __( 'Search Sequences', "e20rsequence" ),
            'not_found' => __( 'No Sequence Found', "e20rsequence" ),
            'not_found_in_trash' => __( 'No Sequence Found In Trash', "e20rsequence" )
        );

        $error = register_post_type( 'pmpro_sequence',
            array( 'labels' => apply_filters( 'e20r-sequence-cpt-labels', $labels ),
                'public' => true,
                'show_ui' => true,
                'show_in_menu' => true,
                'publicly_queryable' => true,
                'hierarchical' => true,
                'supports' => array('title','editor','thumbnail','custom-fields','author', 'excerpt'),
                'can_export' => true,
                'show_in_nav_menus' => true,
                'rewrite' => array(
                    'slug' => apply_filters('e20r-sequence-cpt-slug', $defaultSlug),
                    'with_front' => false
                ),
                'has_archive' => apply_filters('e20r-sequence-cpt-archive-slug', 'sequences')
            )
        );

        if (! is_wp_error($error) )
            return true;
        else {
            E20RTools\DBG::log('Error creating post type: ' . $error->get_error_message(), E20R_DEBUG_SEQ_CRITICAL);
            wp_die($error->get_error_message());
            return false;
        }
    }

    public function convert_user_notifications() {

        global $wpdb;

        // Load all sequences from the DB
        $query = array(
            'post_type' => 'pmpro_sequence',
            'post_status' => apply_filters( 'e20r-sequence-allowed-post-statuses', array( 'publish', 'future', 'private' ) ),
            'posts_per_page' => -1
        );

        $sequence_list = new \WP_Query( $query );

        E20RTools\DBG::log( "convert_user_notifications() - Found " . count($sequence_list) . " sequences to process for alert conversion" );

        while ( $sequence_list->have_posts() ) {

            $sequence_list->the_post();
            $sequence_id = get_the_ID();

            $this->get_options( $sequence_id );

            $users = $this->get_users_of_sequence();

            foreach ( $users as $user ) {

                $this->e20r_sequence_user_id = $user->user_id;
                $userSettings = get_user_meta( $user->user_id, "pmpro_sequence_id_{$sequence_id}_notices", true);

                // No V3 formatted settings found. Will convert from V2 (if available)
                if ( empty( $userSettings ) || ( !isset( $userSettings->send_notices ) ) ) {

                    E20RTools\DBG::log("convert_user_notifications() - Converting notification settings for user with ID: {$user->user_id}" );
                    E20RTools\DBG::log("convert_user_notifications() - Loading V2 meta: {$wpdb->prefix}pmpro_sequence_notices for user ID: {$user->user_id}");

                    $v2 = get_user_meta( $user->user_id, "{$wpdb->prefix}"  . "pmpro_sequence_notices", true );

                    // E20RTools\DBG::log($old_optIn);

                    if ( !empty( $v2 ) ) {

                        E20RTools\DBG::log("convert_user_notifications() - V2 settings found. They are: ");
                        E20RTools\DBG::log( $v2 );

                        E20RTools\DBG::log("convert_user_notifications() - Found old-style notification settings for user {$user->user_id}. Attempting to convert", E20R_DEBUG_SEQ_WARNING );

                        // Loop through the old-style array of sequence IDs
                        $count = 1;

                        foreach( $v2->sequence as $sId => $data ) {

                            E20RTools\DBG::log("convert_user_notification() - Converting sequence notices for {$sId} - Number {$count} of " . count($v2->sequence));
                            $count++;

                            $userSettings = $this->convert_alert_setting( $user->user_id, $sId, $data );

                            if ( isset( $userSettings->send_notices ) ) {

                                $this->save_user_notice_settings( $user->user_id, $userSettings, $sId );
                                E20RTools\DBG::log("convert_user_notifications() - Removing converted opt-in settings from the database" );
                                delete_user_meta( $user->user_id, $wpdb->prefix  . "pmpro_sequence_notices" );
                            }
                        }
                    }

                    if ( empty( $v2 ) && empty( $userSettings ) ) {

                        E20RTools\DBG::log("convert_user_notification() - No v2 or v3 alert settings found for {$user->user_id}. Skipping this user");
                        continue;
                    }

                    E20RTools\DBG::log("convert_user_notifications() - V3 Alert settings for user {$user->user_id}");
                    E20RTools\DBG::log( $userSettings );

                    $userSettings->completed = true;
                    E20RTools\DBG::log( "convert_user_notification() - Saving new notification settings for user with ID: {$user->user_id}" );

                    if ( !$this->save_user_notice_settings( $user->user_id, $userSettings, $this->sequence_id ) ) {

                        E20RTools\DBG::log("convert_user_notification() - Unable to save new notification settings for user with ID {$user->user_id}", E20R_DEBUG_SEQ_WARNING );
                    }
                }
                else {
                    E20RTools\DBG::log("convert_user_notification() - No alert settings to convert for {$user->user_id}");
                    E20RTools\DBG::log("convert_user_notification() - Checking existing V3 settings...");

                    $member_days = $this->get_membership_days( $user->user_id );

                    $old = $this->posts;

                    $compare = $this->load_sequence_post( $sequence_id, $member_days, null, '<=', null, true );
                    $userSettings = $this->fix_user_alert_settings( $userSettings, $compare, $member_days );
                    $this->save_user_notice_settings( $user->user_id, $userSettings, $sequence_id );

                    $this->posts = $old;
                }
            }

            if (! $this->remove_old_user_alert_setting( $user->user_id ) ) {

                E20RTools\DBG::log("conver_user_notification() - Unable to remove old user_alert settings!", E20R_DEBUG_SEQ_WARNING );
            }
        }

        wp_reset_postdata();
    }

    private function convert_alert_setting( $user_id, $sId, $v2 ) {

        $v3 = $this->create_user_notice_defaults();

        $v3->id = $sId;

        $member_days = $this->get_membership_days( $user_id );
        $this->get_options( $sId );

        $compare = $this->load_sequence_post( $sId, $member_days, null, '<=', null, true );

        E20RTools\DBG::log( "convert_alert_settings() - Converting the sequence ( {$sId} ) post list for user alert settings" );

        $when = isset( $v2->optinTS ) ? $v2->optinTS : current_time('timestamp');

        $v3->send_notices = $v2->sendNotice;
        $v3->posts = $v2->notifiedPosts;
        $v3->optin_at = $v2->last_notice_sent = $when;

        E20RTools\DBG::log("convert_alert_settings() - Looping through " . count($v3->posts) . " alert entries");
        foreach( $v3->posts as $key => $post_id ) {

            if ( false === strpos( $post_id, '_' ) ) {

                E20RTools\DBG::log("convert_alert_settings() - This entry ({$post_id}) needs to be converted...");
                $posts = $this->find_by_id( $post_id );

                foreach ($posts as $p ) {

                    $flag_value = "{$p->id}_" . $this->normalize_delay( $p->delay );

                    if ( ( $p->id == $post_id ) && ( $this->normalize_delay( $p->delay ) <= $member_days ) ) {

                        if ( $v3->posts[$key] == $post_id ) {

                            E20RTools\DBG::log("convert_alert_settings() - Converting existing alert entry");
                            $v3->posts[$key] = $flag_value;
                        }
                        else {
                            E20RTools\DBG::log("convert_alert_settings() - Adding alert entry");
                            $v3->posts[] = $flag_value;
                        }
                    }
                }
            }
        }

        $compare = $this->load_sequence_post( null, $member_days, null, '<=', null, true );
        $v3 = $this->fix_user_alert_settings( $v3, $compare, $member_days );

        return $v3;
    }

    private function remove_old_user_alert_setting( $user_id ) {

        global $wpdb;

        $v2 = get_post_meta( $user_id, $wpdb->prefix . "pmpro_sequence_notices", true );

        if ( !empty( $v2 ) ) {

            return delete_user_meta( $user_id, $wpdb->prefix . "pmpro_sequence_notices" );
        }
        else {
            // No v2 meta found..
            return true;
        }
        return false;
    }

    /**
     * Configure & display the icon for the Sequence Post type (in the Dashboard)
     */
    public function post_type_icon() {
        ?>
        <style>
            #adminmenu .menu-top.menu-icon-<?php echo 'pmpro_sequence';?> div.wp-menu-image:before {
                font-family:  FontAwesome !important;
                content: '\f160';
            }
        </style>
    <?php
    }

    public function register_user_scripts() {

        global $e20r_sequence_editor_page;
        global $load_e20r_sequence_script;
        global $post;

        if ( !isset( $post->post_content ) ) {

            return;
        }

        E20RTools\DBG::log("register_user_scripts() - Loading user script(s) & styles");

        $found_links = has_shortcode( $post->post_content, 'sequence_links');
        $found_optin = has_shortcode( $post->post_content, 'sequence_alert');

        E20RTools\DBG::log("register_user_scripts() - 'sequence_links' or 'sequence_alert' shortcode present? " . ( $found_links || $found_optin ? 'Yes' : 'No') );

        if ( ( true === $found_links ) || ( true === $found_optin ) || ( 'pmpro_sequence' == $this->get_post_type() ) || 'pmpro_sequence' == $post->post_type ) {

            $load_e20r_sequence_script = true;

            E20RTools\DBG::log("Loading client side javascript and CSS");
            wp_register_script('e20r-sequence-user', E20R_SEQUENCE_PLUGIN_URL . 'js/e20r-sequences.js', array('jquery'), E20R_SEQUENCE_VERSION, true);

            wp_register_style( 'e20r-sequence', E20R_SEQUENCE_PLUGIN_URL . 'css/e20r_sequences.css' );
            wp_enqueue_style( "e20r-sequence" );

            wp_localize_script('e20r-sequence-user', 'e20r_sequence',
                array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                )
            );
        }
        else {
            $load_e20r_sequence_script = false;
            E20RTools\DBG::log("register_user_scripts() - Didn't find the expected shortcode... Not loading client side javascript and CSS");
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
        elseif( $typenow ) {

            return $typenow;
        } //check the global $current_screen object - set in sceen.php
        elseif( $current_screen && $current_screen->post_type ) {

            return $current_screen->post_type;
        } //lastly check the post_type querystring
        elseif( isset( $_REQUEST['post_type'] ) ) {

            return sanitize_key( $_REQUEST['post_type'] );
        }

        //we do not know the post type!
        return null;
    }

    /**
     * Add javascript and CSS for end-users.
     */
    public function enqueue_user_scripts() {

        global $load_e20r_sequence_script;
        global $post;

        if ( $load_e20r_sequence_script !== true ) {
            return;
        }

        if ( ! isset($post->post_content) ) {

            return;
        }

        $foundShortcode = has_shortcode( $post->post_content, 'sequence_links');

        E20RTools\DBG::log("enqueue_user_scripts() - 'sequence_links' shortcode present? " . ( $foundShortcode ? 'Yes' : 'No') );
        wp_register_script('e20r-sequence-user', E20R_SEQUENCE_PLUGIN_URL . 'js/e20r-sequences.js', array('jquery'), E20R_SEQUENCE_VERSION, true);

        wp_register_style( 'e20r-sequence', E20R_SEQUENCE_PLUGIN_URL . 'css/e20r_sequences.css' );
        wp_enqueue_style( "e20r-sequence" );

        wp_localize_script('e20r-sequence-user', 'e20r_sequence',
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
            )
        );

        wp_print_scripts( 'e20r-sequence-user' );
    }

    /**
     * Load all JS & CSS for Admin page
     */
    function enqueue_admin_scripts( $hook ) {

        global $post;

        E20RTools\DBG::log("Loading admin scripts & styles for E20R Sequences");
        $this->register_admin_scripts();
    }

    public function register_admin_scripts() {

        E20RTools\DBG::log("Running register_admin_scripts()");

        $delay_config = $this->set_delay_config();

        wp_enqueue_style( 'fontawesome', E20R_SEQUENCE_PLUGIN_URL . '/css/font-awesome.min.css', false, '4.5.0' );
        wp_enqueue_style( 'select2', "//cdnjs.cloudflare.com/ajax/libs/select2/" . self::$select2_version . "/css/select2.min.css", false, self::$select2_version);

        wp_enqueue_script('select2', "//cdnjs.cloudflare.com/ajax/libs/select2/" . self::$select2_version . "/js/select2.min.js", array( 'jquery' ), self::$select2_version );
        wp_enqueue_script('e20r-sequence-admin', E20R_SEQUENCE_PLUGIN_URL . 'js/e20r-sequences-admin.js', array( 'jquery', 'select2' ), E20R_SEQUENCE_VERSION, true);

        wp_enqueue_style( 'select2', '//cdnjs.cloudflare.com/ajax/libs/select2/3.5.2/select2.min.css', '', '3.5.2', 'screen');
        wp_enqueue_style( 'e20r-sequence', E20R_SEQUENCE_PLUGIN_URL . 'css/e20r_sequences.css' );

        /* Localize ajax script */
        wp_localize_script('e20r-sequence-admin', 'e20r_sequence',
            array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'delay_config' => $delay_config,
                'lang' => array(
                    'alert_not_saved' => __("Error: This sequence needs to be saved before you can send alerts", "e20rsequence"),
                    'save' => __('Update Sequence', "e20rsequence"),
                    'saving' => __('Saving', "e20rsequence"),
                    'saveSettings' => __('Update Settings', "e20rsequence"),
                    'delay_change_confirmation' => __('Changing the delay type will erase all existing posts or pages in the Sequence list. (Cancel if your are unsure)', "e20rsequence"),
                    'saving_error_1' => __('Error saving sequence post [1]', "e20rsequence"),
                    'saving_error_2' => __('Error saving sequence post [2]', "e20rsequence"),
                    'remove_error_1' => __('Error deleting sequence post [1]', "e20rsequence"),
                    'remove_error_2' => __('Error deleting sequence post [2]', "e20rsequence"),
                    'undefined' => __('Not Defined', "e20rsequence"),
                    'unknownerrorrm' => __('Unknown error removing post from sequence', "e20rsequence"),
                    'unknownerroradd' => __('Unknown error adding post to sequence', "e20rsequence"),
                    'daysLabel' => __('Delay', "e20rsequence"),
                    'daysText' => __('Days to delay', "e20rsequence"),
                    'dateLabel' => __('Avail. on', "e20rsequence"),
                    'dateText' => __('Release on (YYYY-MM-DD)', "e20rsequence"),
                )
            )
        );

        wp_enqueue_style( "e20r-sequence" );
        wp_enqueue_style( "select2" );

        wp_enqueue_script( array( 'select2', 'e20r-sequence-admin' ) );
    }

    public function set_delay_config() {

        $sequences = $this->get_all_sequences(array( 'publish', 'pending', 'draft', 'private', 'future' ));
        $delays = array();

        //Save state for the current sequence
        $current_sequence = $this->sequence_id;

        foreach( $sequences as $sequence ) {

            if ($sequence->ID == 0) {
                continue;
            }

            $options = $this->get_options( $sequence->ID );
            $delays[$sequence->ID] = $options->delayType;
        }

        // Restore state for the sequence we're processing.
        $this->get_options( $current_sequence );

        return ( !empty( $delays ) ? $delays : null );
    }

    /**
     * Register any and all widgets for PMPro Sequence
     */
    public function register_widgets() {

        // Add widget to display a summary for the most recent post/page
        // in the sequence for the logged in user.
        register_widget( '\\E20R\\Sequences\\Tools\\Widgets\\PostWidget' );
    }

    /**
     * Register any and all shortcodes for PMPro Sequence
     */
    public function register_shortcodes() {

		$sl = new Shortcodes\sequence_links();
		$sa = new Shortcodes\sequence_alert();
		$uc = new Shortcodes\upcoming_content();


        // Generates paginated list of links to sequence members
        add_shortcode( 'sequence_links', array( $sl, 'load_shortcode') );
        add_shortcode( 'sequence_alert', array( $sa, 'load_shortcode') );
        add_shortcode( 'upcoming_content', array( $uc, 'load_shortcode' ) );
    }

    /**
     * Load and use L18N based text (if available)
     */
    public function load_textdomain() {

        $locale = apply_filters( "plugin_locale", get_locale(), 'e20rsequence' );

        $mofile = "e20rsequence-{$locale}.mo";

        $mofile_local = dirname(__FILE__) . "/../languages/" . $mofile;
        $mofile_global = WP_LANG_DIR . "/e20rsequence/" . $mofile;

        load_textdomain( "e20rsequence", $mofile_global );
        load_textdomain( "e20rsequence", $mofile_local );
    }

    /**
     * Return error if an AJAX call is attempted by a user who hasn't logged in.
     */
    public function unprivileged_ajax_error() {

        E20RTools\DBG::log('Unprivileged ajax call attempted', E20R_DEBUG_SEQ_CRITICAL);

        wp_send_json_error( array(
            'message' => __('You must be logged in to edit PMPro Sequences', "e20rsequence")
        ) );
    }

    public function send_user_alert_notices() {

        $sequence_id = intval($_REQUEST['e20r_sequence_id']);

        E20RTools\DBG::log( 'send_user_alert_notices() - Will send alerts for sequence #' . $sequence_id );

//            $sequence = apply_filters('get_sequence_class_instance', null);
//            $sequence->sequence_id = $sequence_id;
//            $sequence->get_options( $sequence_id );

        do_action( 'e20r_sequence_cron_hook', array( $sequence_id ));

        E20RTools\DBG::log( 'send_user_alert_notices() - Completed action for sequence #' . $sequence_id );
        wp_redirect('/wp-admin/edit.php?post_type=pmpro_sequence');
    }

    public function send_alert_notice_from_menu( $actions, $post ) {

        if ( ( 'pmpro_sequence' == $post->post_type ) && current_user_can('edit_posts' ) ) {

            $options = $this->get_options( $post->ID );

            if ( 1 == $options->sendNotice ) {

                E20RTools\DBG::log("send_alert_notice_from_menu() - Adding send action");
                $actions['duplicate'] = '<a href="admin.php?post=' . $post->ID . '&amp;action=send_user_alert_notices&amp;e20r_sequence_id=' . $post->ID .'" title="' .__("Send user alerts", "e20rtracker" ) .'" rel="permalink">' . __("Send Notices", "e20rtracker") . '</a>';
            }
        }

        return $actions;
    }

    /**
     * Loads actions & filters for the plugin.
     */
    public function load_actions()
    {
        // Configure all needed class instance filters;
        add_filter( "get_sequence_views_class_instance", [ Sequence\Views::get_instance(), 'get_instance' ] );
        add_filter( "get_e20rerror_class_instance", [ Tools\E20RError::get_instance(), 'get_instance' ] );
        add_filter( "get_cron_class_instance", [ Tools\Cron::get_instance(), 'get_instance'] );

        $uc_class = Tools\e20rSequenceUpdates::get_instance();
        add_filter( "get_sequence_update_class_instance", [ $uc_class, 'get_instance' ] );

        add_action( 'plugins_loaded', [ $uc_class, 'init' ] );
        add_action( 'wp_loaded', [ $uc_class, 'update' ], 1) ; // Run early

        // Load filters
        add_filter("pmpro_after_phpmailer_init", array(&$this, "email_body"));
        add_filter('pmpro_sequencepost_types', array(&$this, 'included_cpts'));

        // TODO: Split pmpro filters into own filter management module
        add_filter("pmpro_has_membership_access_filter", array(&$this, "has_membership_access_filter"), 9, 4);
        add_filter("pmpro_non_member_text_filter", array(&$this, "text_filter"));
        add_filter("pmpro_not_logged_in_text_filter", array(&$this, "text_filter"));

        add_filter("the_content", array(&$this, "display_sequence_content"));

        // add_filter( "the_posts", array( &$this, "set_delay_values" ), 10, 2 );

        // Add Custom Post Type
        add_action("init", array(&$this, "load_textdomain"), 9);
        add_action("init", array(&$this, "create_custom_post_type"), 10);
        add_action("init", array(&$this, "register_shortcodes"), 11);

        add_filter( "post_row_actions", array( &$this, 'send_alert_notice_from_menu' ), 10, 2);
        add_filter( "page_row_actions", array( &$this, 'send_alert_notice_from_menu' ), 10, 2);
        add_action( "admin_action_send_user_alert_notices", array( &$this, 'send_user_alert_notices') );

//            add_action("init", array(&$this, "register_user_scripts") );
//            add_action("init", array(&$this, "register_admin_scripts") );

        // Add CSS & Javascript
        add_action("wp_enqueue_scripts", array( &$this, 'register_user_scripts' ));
        add_action("wp_footer", array( &$this, 'enqueue_user_scripts') );

        add_action("admin_enqueue_scripts", array(&$this, 'enqueue_admin_scripts'));
        add_action('admin_head', array(&$this, 'post_type_icon'));

        // Load metaboxes for editor(s)
        add_action('add_meta_boxes', array(&$this, 'post_metabox'));

        // Load add/save actions
        add_action('admin_init', array( &$this, 'check_conversion' ) );
        add_action('admin_notices', array(&$this, 'display_error'));
        // add_action( 'save_post', array( &$this, 'post_save_action' ) );
        add_action('post_updated', array(&$this, 'post_save_action'));

        add_action('admin_menu', array(&$this, "define_metaboxes"));
        add_action('save_post', array(&$this, 'save_post_meta'), 10, 2);
        add_action('deleted_post', array(&$this, 'delete_post_meta_for_sequence'), 10, 1);
        add_action('widgets_init', array(&$this, 'register_widgets'));

        // Add AJAX handlers for logged in users/admins
        add_action("wp_ajax_e20r_sequence_add_post", array(&$this, "add_post_callback"));
        add_action('wp_ajax_e20r_sequence_update_post_meta', array(&$this, 'update_delay_post_meta_callback'));
        add_action('wp_ajax_e20r_rm_sequence_from_post', array(&$this, 'rm_sequence_from_post_callback'));
        add_action("wp_ajax_e20r_sequence_rm_post", array(&$this, "rm_post_callback"));
        add_action("wp_ajax_e20r_remove_alert", array(&$this, "remove_post_alert_callback"));
        add_action('wp_ajax_e20r_sequence_clear', array(&$this, 'sequence_clear_callback'));
        add_action('wp_ajax_e20r_send_notices', array(&$this, 'sendalert_callback'));
        add_action('wp_ajax_e20r_sequence_save_user_optin', array(&$this, 'optin_callback'));
        add_action('wp_ajax_e20r_save_settings', array(&$this, 'settings_callback'));
		add_action("wp_ajax_e20r_sequence_clear_cache", array(&$this, "delete_cache"));

        // Add AJAX handlers for unprivileged admin operations.
        add_action('wp_ajax_nopriv_e20r_sequence_add_post', array(&$this, 'unprivileged_ajax_error'));
        add_action('wp_ajax_nopriv_e20r_sequence_update_post_meta', array(&$this, 'unprivileged_ajax_error'));
        add_action('wp_ajax_nopriv_e20r_rm_sequence_from_post', array(&$this, 'unprivileged_ajax_error'));
        add_action('wp_ajax_nopriv_e20r_sequence_rm_post', array(&$this, 'unprivileged_ajax_error'));
        add_action('wp_ajax_nopriv_e20r_sequence_clear', array(&$this, 'unprivileged_ajax_error'));
        add_action('wp_ajax_nopriv_e20r_send_notices', array(&$this, 'unprivileged_ajax_error'));
        add_action('wp_ajax_nopriv_e20r_sequence_save_user_optin', array(&$this, 'unprivileged_ajax_error'));
        add_action('wp_ajax_nopriv_e20r_save_settings', array(&$this, 'unprivileged_ajax_error'));

        // Load shortcodes (instantiate the object(s).
        $shortcode_availableOn = new Shortcodes\available_on();

    }

    /**
      * @param $post_id
      * @param $delay
      * @return bool|\WP_Post
      */
    private function find_single_post( $post_id, $delay ) {

        if ( empty( $this->posts ) ) {
            $this->load_sequence_post();
        }

        E20RTools\DBG::log("find_single_post() - Find post {$post_id}");

        foreach( $this->posts as $key => $post ) {

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

            E20RTools\DBG::log('sort_by_delay(): Need sortOrder option to base sorting decision on...');
            // $sequence = $this->get_sequence_by_id($a->id);
            if ( $this->sequence_id !== null) {

                E20RTools\DBG::log('sort_by_delay(): Have valid sequence post ID saved: ' . $this->sequence_id);
                $this->get_options( $this->sequence_id );
            }
        }

        switch ($this->options->sortOrder) {

            case SORT_DESC:
                E20RTools\DBG::log('sort_by_delay(): Sorted in Descending order');
                krsort( $this->posts, SORT_NUMERIC );
                break;
            default:
                E20RTools\DBG::log('sort_by_delay(): undefined or ascending sort order');
                ksort( $this->posts, SORT_NUMERIC );
        }

        return false;
    }

    /**
     * Sort the two post objects (order them) according to the defined sortOrder
     *
     * @param $a (post object)
     * @param $b (post object)
     * @return int | bool - The usort() return value
     *
     * @access private
     */
    private function sort_posts_by_delay($a, $b) {

/*            if ( empty( $this->options->sortOrder) ) {

            E20RTools\DBG::log('sort_posts_by_delay(): Need sortOrder option to base sorting decision on...');
            // $sequence = $this->get_sequence_by_id($a->id);

            if ( $this->sequence_id !== null) {

                E20RTools\DBG::log('sort_posts_by_delay(): Have valid sequence post ID saved: ' . $this->sequence_id);
                $this->get_options( $this->sequence_id );
            }
        }
*/
        switch ($this->options->sortOrder) {

            case SORT_ASC:
                // E20RTools\DBG::log('sort_posts_by_delay(): Sorting in Ascending order');
                return $this->sort_ascending($a, $b);
                break;

            case SORT_DESC:
                // E20RTools\DBG::log('sort_posts_by_delay(): Sorting in Descending order');
                return $this->sort_descending($a, $b);
                break;

            default:
                E20RTools\DBG::log('sort_posts_by_delay(): sortOrder not defined');
        }

        return false;
    }

    /**
     * Sort the two posts in Ascending order
     *
     * @param $a -- Post to compare (including delay variable)
     * @param $b -- Post to compare against (including delay variable)
     * @return int -- Return +1 if the Delay for post $a is greater than the delay for post $b (i.e. delay for b is
     *                  less than delay for a)
     *
     * @access private
     */
    private function sort_ascending($a, $b) {

        list($aDelay, $bDelay) = $this->normalize_delay_values($a, $b);
        // E20RTools\DBG::log('sort_ascending() - Delays have been normalized');

        // Now sort the data
        if ($aDelay == $bDelay)
            return 0;
        // Ascending sort order
        return ($aDelay > $bDelay) ? +1 : -1;

    }

    /**
     * Get the delays (days since membership started) for both post objects
     *
     * @param $a -- Post object to compare
     * @param $b -- Post object to compare against
     * @return array -- Array containing delay(s) for the two posts objects (as days since start of membership)
     *
     * @access private
     */
    private function normalize_delay_values($a, $b)
    {
        return array( $this->convert_date_to_days( $a->delay ), $this->convert_date_to_days( $b->delay ) );
    }

    /**
     * Sort the two posts in ascending order
     *
     * @param $a -- Post to compare (including delay variable)
     * @param $b -- Post to compare against (including delay variable)
     * @return int -- Return -1 if the Delay for post $a is greater than the delay for post $b
     *
     * @access private
     */
    private function sort_descending( $a, $b )
    {
        list($aDelay, $bDelay) = $this->normalize_delay_values($a, $b);

        if ($aDelay == $bDelay)
            return 0;
        // Descending Sort Order
        return ($aDelay > $bDelay) ? -1 : +1;
    }
}
