<?php
/*
  License:

	Copyright 2014 Thomas Sjolshagen (thomas@eighty20results.com)
	Copyright 2013 Stranger Studios (jason@strangerstudios.com)

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

	class PMProSequence
	{
	    public $options;
	    public $sequence_id = 0;

		private $id;

		private $posts = array(); // List of available posts for user ID
		private $upcoming = array(); // list of future posts for user ID (if the sequence is configured to show hidden posts)

		private $sequence; // WP_POST definition for the sequence

        private $refreshed;

		public $error = null;
        private $managed_types = null;

        public $pmpro_sequence_user_level;
        public $pmpro_sequence_user_id;
        public $is_cron = false;

        /**
         * Constructor for the Sequence
         *
         * @param null $id -- The ID of the sequence to initialize
         * @throws Exception - If the sequence doesn't exist.
         */
        function __construct($id = null) {

            // Make sure it's not a dummy construct() call - i.e. for a post that doesn't exist.
            if ( ( $id != null ) && ( $this->sequence_id == 0 ) ) {

                $this->sequence_id = $this->get_sequence_by_id( $id ); // Try to load it from the DB

                if ( $this->sequence_id == false ) {
                    throw new Exception( __("A Sequence with the specified ID does not exist on this system", "pmprosequence" ) );
                }
            }

            $this->managed_types = apply_filters("pmpro-sequence-managed-post-types", array("post", "page") );
		}

        /**
         * Initialize the sequence and load its posts
         *
         * @param null $id -- (optional) ID of the sequence we'd like to start/init.
         * @return bool|int -- ID of sequence if it successfully gets loaded
         * @throws Exception -- Sequence to load/init wasn't identified (specified).
         */
        public function init( $id = null ) {

            if ( !is_null( $id ) ) {

                $this->sequence = get_post( $id );
                $this->dbg_log('init() - Loading the "' . get_the_title($id) . '" sequence settings');

                // Set options for the sequence
                $this->get_options( $id );
                $this->load_sequence_post();

                $this->dbg_log( 'init() -- Done.' );

                return $this->sequence_id;
            }

            if (( $id == null ) && ( $this->sequence_id == 0 ) ) {
                throw new Exception( __('No sequence ID specified.', 'pmprosequence') );
            }

            return false;
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

            $settings = new stdClass();

            $settings->hidden =  0; // 'hidden' (Show them)
            $settings->lengthVisible = 1; //'lengthVisible'
            $settings->sortOrder = SORT_ASC; // 'sortOrder'
            $settings->delayType = 'byDays'; // 'delayType'
            $settings->allowRepeatPosts = false; // Whether to allow a post to be repeated in the sequence (with different delay values)
            $settings->showDelayAs = PMPRO_SEQ_AS_DAYNO; // How to display the time until available
            $settings->previewOffset = 0; // How many days into the future the sequence should allow somebody to see.
            $settings->startWhen =  0; // startWhen == immediately (in current_time('timestamp') + n seconds)
            $settings->sendNotice = 1; // sendNotice == Yes
            $settings->noticeTemplate = 'new_content.html'; // Default plugin template
            $settings->noticeSendAs = PMPRO_SEQ_SEND_AS_SINGLE; // Send the alert notice as one notice per message.
            $settings->noticeTime = '00:00'; // At Midnight (server TZ)
            $settings->noticeTimestamp = current_time('timestamp'); // The current time (in UTC)
            $settings->excerpt_intro = __('A summary of the post follows below:', 'pmprosequence');
            $settings->replyto = pmpro_getOption("from_email");
            $settings->fromname = pmpro_getOption("from_name");
            $settings->subject = __('New Content ', 'pmprosequence');
            $settings->dateformat = __('m-d-Y', 'pmprosequence'); // Using American MM-DD-YYYY format.
            $settings->track_google_analytics = false; // Whether to use Google analytics to track message open operations or not
            $settings->ga_tid; // The Google Analytics ID to use (TID)

            $this->options = $settings; // Save as options for this sequence

            return $settings;
        }

        /**
	     * Fetch any options for this specific sequence from the database (stored as post metadata)
	     * Use default options if the sequence ID isn't supplied*
	     *
	     * @param int $sequence_id - The Sequence ID to fetch options for
	     * @return mixed -- Returns array of options if options were successfully fetched & saved.
	     */
	    public function get_options( $sequence_id = 0 ) {

            // Does the ID differ from the one this object has stored already?
            if ( ( $this->sequence_id != 0 ) && ( $this->sequence_id != $sequence_id )) {

                $this->dbg_log('get_options() - ID defined already but we were given a different sequence ID');
                $this->sequence_id = $sequence_id;
            }
            elseif ($this->sequence_id == 0) {

                // This shouldn't be possible... (but never say never!)
	            $this->dbg_log("The defined sequence ID is empty so we'll set it to " . $sequence_id);
                $this->sequence_id = $sequence_id;
            }

	        // Check that we're being called in context of an actual Sequence 'edit' operation
	        $this->dbg_log('get_options(): Loading settings from DB for (' . $this->sequence_id . ') "' . get_the_title($this->sequence_id) . '"');

	        $settings = get_post_meta($this->sequence_id, '_pmpro_sequence_settings', true);
            $this->dbg_log("get_options() - Settings are now: " . print_r( $settings, true ) );

            // Fix: Offset error when creating a brand new sequence for the first time.
            if ( empty( $settings ) ) {

                $settings = $this->default_options();
            }

            $loaded_options = $settings;
            $default_options = $this->default_options();

            $this->options = (object) array_replace( (array)$default_options, (array)$loaded_options );

            // $this->dbg_log( "get_options() for {$this->sequence_id}: Current: " . print_r( $this->options, true ) );

	        return $this->options;
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

                $this->dbg_log( 'save_sequence_meta() - Unknown sequence ID. Need to instantiate the correct sequence first!' );
                return false;
            }

            try {

                // Update the *_postmeta table for this sequence
                update_post_meta($this->sequence_id, '_pmpro_sequence_settings', $settings );

                // Preserve the settings in memory / class context
                $this->dbg_log('save_sequence_meta(): Saved Sequence Settings for ' . $this->sequence_id);
            }
            catch (Exception $e) {

                $this->dbg_log('save_sequence_meta() - Error saving sequence settings for ' . $this->sequence_id . ' Msg: ' . $e->getMessage());
                return false;
            }

            return true;
        }

        /********************************* Basic Sequence Functionality *******************/

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

        public function find_by_id( $post_id ) {

            $posts = $this->load_sequence_post( null, null, $post_id );

            return $posts;
        }

        public function load_sequence_post( $sequence_id = null, $delay = null, $post_id = null, $comparison = '=', $pagesize = null, $force = false ) {

            global $current_user;

            global $loading_sequence;

            if ( !is_null( $this->pmpro_sequence_user_id )  && ( $this->pmpro_sequence_user_id != $current_user->ID ) ) {
                $user_id = $this->pmpro_sequence_user_id;
            }
            else {
                $user_id = $current_user->ID;
            }

            $find_by_delay = true;
            $found = array();

            if ( is_null( $sequence_id ) && ( !empty( $this->sequence_id ) ) ) {
                $this->dbg_log("load_sequence_post() - No sequence ID specified in call. Using default value of {$this->sequence_id}");
                $sequence_id = $this->sequence_id;
            }

            if ( empty( $sequence_id ) ) {

                $this->dbg_log( "load_sequence_post() - No sequence ID configured. Returning error (null)", DEBUG_SEQ_WARNING );
                return null;
            }

            if ( empty( $delay ) ) {
                $find_by_delay = false;
            }

            if ( ( false == $force ) && is_null( $post_id ) && !empty( $this->posts ) &&
                ( ( $this->refreshed + 60 )  > current_time( 'timestamp', true ) ) ) {

                $this->dbg_log("load_sequence_post() - No need to refresh post list for sequence # {$this->sequence_id}");
                return $this->posts;
            }
            else {
                $this->dbg_log("load_sequence_post() - Setting refresh timestamp...");
                $this->refreshed = current_time('timestamp', true);
            }

            /**
             * Expected format: array( $key_1 => stdClass $post_obj, $key_2 => stdClass $post_obj );
             * where $post_obj = stdClass  -> id
             *                   stdClass  -> delay
             */
            $order_by = $this->options->delayType == 'byDays' ? 'meta_value_num' : 'meta_value';
            $order = $this->options->sortOrder == SORT_DESC ? 'DESC' : 'ASC';

            if ( is_null( $post_id ) ) {
                $this->dbg_log("load_sequence_post() - No post ID specified so we'll load all posts");
                $args = array(
                    'post_type' => apply_filters( 'pmpro_sequencepost_types', array( 'post', 'page' ) ),
                    'post_status' => apply_filters( 'pmpro-sequence-allowed-post-statuses', array( 'publish', 'future', 'private' ) ),
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

                $this->dbg_log("load_sequence_post() - Post ID specified so we'll only load for post {$post_id}");
                $args = array(
                    'post_type' => apply_filters( 'pmpro_sequencepost_types', array( 'post', 'page' ) ),
                    'post_status' => apply_filters( 'pmpro-sequence-allowed-post-statuses', array( 'publish', 'future', 'private' ) ),
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

                $this->dbg_log("load_sequence_post() - Enable paging, grab page #: " . get_query_var( 'page' ) );

                $page_num = ( get_query_var( 'page' ) ) ? absint( get_query_var( 'page' ) ) : 1;
                // $args['paged'] = $page_num;
                // $args['posts_per_page'] = $pagesize;
            }

            if ( $find_by_delay ) {

                $args['meta_query'][] = array(
                    'key' => "_pmpro_sequence_{$sequence_id}_post_delay",
                    'value' => $delay,
                    'compare' => $comparison,
                );
            }

            $this->dbg_log("load_sequence_post() - Args for WP_Query(): ");
            $this->dbg_log($args);

            // $loading_sequence = $sequence_id;

            $posts = new WP_Query( $args );

            // $loading_sequence = null;

            // $order_num = 0;

            $this->dbg_log("load_sequence_post() - Loaded {$posts->post_count} posts from wordpress database for sequence {$sequence_id}");
            // $this->dbg_log( $posts );

            $member_days = is_admin() && ( $this->is_cron == false ) ? 65535 : $this->get_membership_days( $user_id );

            $this->dbg_log("load_sequence_post() - User {$user_id} has been a member for {$member_days} days");

            $post_list = $posts->get_posts();

            wp_reset_postdata();

            foreach( $post_list as $k => $sPost ) {

                $id = $sPost->ID;

                $tmp_delay = get_post_meta( $id, "_pmpro_sequence_{$sequence_id}_post_delay" );

                $is_repeat = false;

                // Add posts for all delay values with this post_id
                foreach( $tmp_delay as $p_delay ) {

                    $p = new stdClass();

                    $p->id = $id;
                    // BUG: Doesn't work because you could have multiple post_ids released on same day. $p->order_num = $this->normalize_delay( $p_delay );
                    $p->delay = isset( $sPost->delay ) ? $sPost->delay : $p_delay;
                    $p->permalink = get_permalink( $sPost->ID );
                    $p->title = $sPost->post_title;
                    $p->closest_post = false;
                    $p->current_post = false;
                    $p->type = $sPost->post_type;

                    // Only add posts to list if the member is supposed to see them
                    if ( $member_days >= $p->delay ) {

                        $this->dbg_log("load_sequence_post() - Adding {$p->id} ({$p->title}) with delay {$p->delay} to list of available posts");
                        $p->is_future = false;
                        $found[] = $p;
                    }
                    else {

                        // Or if we're not supposed to hide the upcomping posts.

                        if ( !$this->hide_upcoming_posts() ) {

                            $this->dbg_log("load_sequence_post() - Loading {$p->id} with delay {$p->delay} to list of upcoming posts");
                            $p->is_future = true;
                            $found[] = $p;
                        }
                        else {
                            $this->dbg_log("load_sequence_post() - Not adding post {$p->id} with delay {$p->delay} to sequence list for {$sequence_id}");
                            if ( !is_null( $pagesize ) ) {

                                unset( $post_list[ $k ] );
                            }
                        }
                    }
                } // End of foreach for delay values

                $is_repeat = false;
            } // End of foreach for post_list

            $this->dbg_log("load_sequence_post() - Found and sorted " . count( $found ) . " posts for sequence {$sequence_id} and user {$user_id}");

            if ( is_null( $post_id ) ) {

                $this->posts = $found;

                $this->posts = $this->set_closest_post( $found );

                if ( !is_null( $pagesize ) && ( $page_num == 1 ) ) {

                    $post_list = $this->set_closest_post( $post_list );
                }

                // Default to old _sequence_posts data
                if ( 0 == count( $this->posts ) ) {

                    $this->dbg_log("load_sequence_post() - No posts found using the V3 meta format. Reverting... ", DEBUG_SEQ_WARNING );

                    $tmp = get_post_meta( $this->sequence_id, "_sequence_posts", true );
                    $this->posts = ( $tmp ? $tmp : array() ); // Fixed issue where empty sequences would generate error messages.

                    $this->dbg_log("load_sequence_post() - Saving to new V3 format... ", DEBUG_SEQ_WARNING );
                    $this->save_sequence_post();

                    $this->dbg_log("load_sequence_post() - Removing old format meta... ", DEBUG_SEQ_WARNING );
                    delete_post_meta( $this->sequence_id, "_sequence_posts" );
                }

                usort( $this->posts, array( $this, "sort_posts_by_delay" ));

                if (!empty( $this->upcoming ) ) {

                    usort( $this->upcoming, array( $this, "sort_posts_by_delay" ) );
                }

                $this->dbg_log("load_sequence_post() - Returning " . count($this->posts) . " sequence members");

                if ( is_null( $pagesize ) ) {

                    return $this->posts;
                }
                else {

                    if ( !empty( $this->upcoming ) ) {
                        $this->dbg_log("load_sequence_posts() - Appending the upcoming array to the post array. posts =  " . count( $this->posts ) . " and upcoming = " . count( $this->upcoming ) );
                        $this->posts = array_combine( $this->posts, $this->upcoming );
                        $this->dbg_log("load_sequence_posts() - Joined array contains " . count ($this->posts ) . " total posts");
                    }

                    $paged_list = $this->paginate_posts( $this->posts, $pagesize, $page_num );

                    // Special processing since we're paginating.
                    // Make sure the $delay value is > first element's delay in $page_list and < last element

                    list( $min, $max ) = $this->set_min_max( $pagesize, $page_num, $paged_list );

                    $this->dbg_log("load_sequence_post() - Check max / min delay values for paginated set. Max: {$max}, Min: {$min}");

                    foreach( $paged_list as $k => $p ) {

                        $this->dbg_log("load_sequence_post() - Checking post key {$k} (post: {$p->id}) with delay {$p->delay}");

                        if ( $p->delay < $min ) {

                            $this->dbg_log("load_sequence_post() - removing post entry {$k} -> ({$p->delay}) because its delay value is less than min for the listing" );

                            unset( $paged_list[$k] );
                        }
                        elseif ( $p->delay > $max ) {

                            $this->dbg_log("load_sequence_post() - removing post entry {$k} -> ({$p->delay}) because its delay value is greater than max for the listing" );
                            unset( $paged_list[$k] );

                        }
                    }

                    $this->dbg_log("load_sequence_post() - Returning the WP_Query result to process for pagination.");
                    return array( $paged_list, $posts->max_num_pages );
                }

            }
            else {
                $this->dbg_log("load_sequence_post() - Returning array of posts located by specific post_id");
                return $found;
            }
        }

        private function paginate_posts( $post_list, $page_size, $current_page ) {

            $page = array();

            $last_key = ($page_size * $current_page) - 1;
            $first_key = $page_size * ( $current_page - 1 );

            foreach( $post_list as $k => $post ) {

                if ( ( $k <= $last_key ) && ( $k >= $first_key ) ) {
                    $this->dbg_log("paginate_posts() - Including {$post->id} with delay {$post->delay} in page");
                    $page[] = $post;
                }
            }

            return $page;

        }

/*        public function set_delay_values( $post_list, $wp_obj ) {

            global $current_user;
            global $loading_sequence;

            $is_repeat = false;
            $member_days = $this->get_membership_days( $current_user->ID );

            if ( $loading_sequence ) {

                $this->dbg_log("set_delay_values() - Loading delay value(s) for " . count( $post_list ) . " posts" );

                foreach( $post_list as $k => $post ) {

                    $tmp_delay = get_post_meta( $post->ID, "_pmpro_sequence_{$this->sequence_id}_post_delay" );
                    $is_repeat = false;

                    // Add posts for all delay values with this post_id
                    foreach( $tmp_delay as $p_delay ) {

                        // $post_list[$k]->delay = $p_delay;
                        if ( !$is_repeat ) {

                            $this->dbg_log("set_delay_values() - Setting delay value in post_list[k] to {$p_delay}");
                            $post_list[$k]->delay = $p_delay;
                            $this->dbg_log("set_delay_values() - First time through: repeating posts for pagination... Delay value: {$p_delay}");
                            $is_repeat = true;

                        }
                        else {
                            $new = clone $post;

                            $this->dbg_log("set_delay_values() - Handle repeating posts for pagination... Delay value: {$p_delay}");
                            $new->delay = $p_delay;

                            $this->dbg_log("set_delay_values() - Appending new post object with delay value {$p_delay} to post_list[]");
                            $post_list[] = $new;
                            $new = null;
                        }

                    } // End of foreach for delay values

                    $is_repeat = false;
                } // End of foreach for post_list

            }

            // usort( $post_list, array( $this, 'sort_posts_by_delay' ) );

            $this->dbg_log("set_delay_values() - Sorted post_array according to sortOrder setting.");

            return $post_list;
        }
*/
        private function set_min_max( $pagesize, $page_num, $post_list ) {

            $min_key = 0;
            $max_key = $pagesize - 1;

            $this->dbg_log("set_min_max() - Max key: {$max_key} and min key: {$min_key}");
            $min = $post_list[$max_key]->delay;
            $max = $post_list[$min_key]->delay;

            $this->dbg_log("set_min_max() - Gives min/max values: Min: {$min}, Max: {$max}");

            return array( $min, $max );

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

                        $this->dbg_log("save_sequence_post() - Unable to add post {$p_obj->id} with delay {$p_obj->delay} to sequence {$this->sequence_id}", DEBUG_SEQ_WARNING );
                        return false;
                    }
                }

                return true;
            }

            if ( !is_null( $post_id ) && !is_null($delay) ) {

                if ( empty( $sequence_id ) ) {

                    $sequence_id = $this->sequence_id;
                }

                $this->dbg_log("save_sequence_post() - Saving post {$post_id} with delay {$delay} to sequence {$sequence_id}");
                return $this->add_post_to_sequence( $sequence_id, $post_id, $delay );
            }
            else {
                $this->dbg_log("save_sequence_post() - Need both post ID and delay values to save the post to sequence {$sequence_id}", DEBUG_SEQ_WARNING );
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

            $this->dbg_log("add_post_to_sequence() - Adding post {$post_id} sequence meta (v3 format)");

            $posts = $this->find_by_id( $post_id );

            if ( !empty( $posts ) && ( !$this->allow_repetition() ) ) {

                $this->dbg_log("add_post_to_sequence() - Post is a duplicate and we're not allowed to add duplicates");

                foreach ( $posts as $p ) {

                    $this->dbg_log("add_post_to_sequence(): Delay is different & we can't have repeat posts. Need to remove existing instances of {$post_id} and clear any notices");
                    $this->remove_post( $p->id, $p->delay, true );
                }
            }

            if ( is_admin() ) {
                $member_days = -1;
            }
            else {
                $member_days = $this->get_membership_days( $current_user->ID );
            }

            $p = get_post( $post_id );
            $new_post = new stdClass();

            $new_post->id = $post_id;
            $new_post->delay = $delay;
            // $new_post->order_num = $this->normalize_delay( $delay ); // BUG: Can't handle repeating delay values (ie. two posts with same delay)
            $new_post->permalink = get_the_permalink( $post_id );
            $new_post->title = get_the_title( $post_id );
            $new_post->is_future = ( $member_days < $delay ) && ( $this->hide_upcoming_posts() )  ? true : false;
            $new_post->current_post = false;
            $new_post->type = get_post_type( $p );

            if ( false === get_post_meta( $post_id, "_pmpro_sequence_post_belongs_to", $sequence_id ) ) {

                $this->dbg_log("add_post_to_sequence() - Adding this post {$post_id} to the sequence {$sequence_id} for the first time");
                add_post_meta( $post_id, "_pmpro_sequence_post_belongs_to", $sequence_id, true );
            }
            else {

                $this->dbg_log("add_post_to_sequence() - Post {$post_id} is already linked to sequence {$sequence_id}");
            }

            $this->dbg_log("add_post_to_sequence() - Attempting to add delay value {$delay} for post {$post_id} to sequence: {$sequence_id}");

            if ( !$this->allow_repetition() ) {

                if ( !add_post_meta( $post_id, "_pmpro_sequence_{$sequence_id}_post_delay", $delay, true ) ) {

                    $this->dbg_log("add_post_to_sequenece() - Couldn't add {$post_id} with delay {$delay}. Attempting update operation" );
                    update_post_meta( $post_id, "_pmpro_sequence_{$sequence_id}_post_delay", $delay );
                }
            }
            else {

                $delays = get_post_meta( $post_id, "_pmpro_sequence_{$sequence_id}_post_delay" );

                $this->dbg_log("add_post_to_sequence() - Checking whether the '{$delay}' delay value is already recorded for this post: {$post_id}");

                if ( ( false == $delays ) || ( !in_array( $delay, $delays ) ) ) {

                    $this->dbg_log( "add_post_to_seuqence() - Not previously added. Now adding delay value meta ({$delay}) to post id {$post_id}");
                    add_post_meta( $post_id, "_pmpro_sequence_{$sequence_id}_post_delay", $delay );
                }
                else {
                    $this->dbg_log("add_post_to_sequence() - Post # {$post_id} in sequence {$sequence_id} is already recorded with delay {$delay}");
                }

            }

            if ( false === get_post_meta( $post_id, "_pmpro_sequence_post_belongs_to" ) ) {

                $this->dbg_log("add_post_to_sequence() - Didn't add {$post_id} to {$sequence_id}", DEBUG_SEQ_WARNING );
                return false;
            }

            if ( false === get_post_meta( $post_id, "_pmpro_sequence_{$sequence_id}_post_delay" ) ) {

                $this->dbg_log("add_post_to_sequence() - Couldn't add post/delay value(s) for {$post_id}/{$delay} to {$sequence_id}", DEBUG_SEQ_WARNING );
                return false;
            }

            if ( ( $this->has_post_access( $current_user->ID, $post_id, false ) ) || ( ( true === $new_post->is_future ) && !$this->hide_upcoming_posts() ) )  {

                $this->dbg_log("add_post_to_sequence() - Adding post to sequence: {$sequence_id}");
                $this->posts[] = $new_post;
            }
            else {

                $this->dbg_log("add_post_to_sequence() - User doesn't have access to the post so not adding it.");
                $this->upcoming[] = $new_post;
            }

            usort( $this->posts, array( $this, 'sort_posts_by_delay' ) );

            if ( !empty( $this->upcoming ) ) {
                usort( $this->upcoming, array( $this, 'sort_posts_by_delay' ) );
            }

            return true;
        }

       /**
         * Load the private class variable $posts with the list of posts belonging to this sequence (semi-cached)
         *
         * @param bool $force -- Ignore the cache and force fetch from the DB
         * @return mixed -- Returns the aray of posts belonging to this sequence
         *
         * @access public
         */
/*
        public function get_posts( $force = false ) {

            if ( ( $force === true ) || empty( $this->posts ) ||
                ( ( $this->refreshed + 5*60 )  <= current_time( 'timestamp', true )  ) ) {

                $this->posts = array();
                $this->dbg_log("get_posts() - Refreshing post list for sequence # {$this->sequence_id}");

                $this->refreshed = current_time('timestamp', true);

                $this->posts = $this->load_sequence_post();
                $this->dbg_log("get_posts() - Loaded " . count( $this->posts ) . " posts for this sequence  using V3 meta rules");

            }

            $this->dbg_log("get_posts() - There are " . count( $this->posts ) . " posts in this sequence ");
            // $this->dbg_log( $this->posts );

            return $this->posts;
        }
*/
        /**
         * Find the post in the sequence and return its key
         *
         * @param $post_id -- The ID of the post
         * @return array -- The key(s) for the post(s) we've found
         *
         * @access public
         */
         /*
        public function getPostKey($post_id, $delay = null ) {

            $this->load_sequence_post();
            $retval = array();

            if ( empty( $this->posts ) ) {

                $this->dbg_log("getPostKey() - No posts found in sequence: {$this->sequence_id}");
                return $retval;
            }

            if ( !empty( $delay ) ) {

                foreach( $this->posts as $key => $post ) {

                    if ( ( $post_id == $post->id ) && ( $delay == $post->delay ) ) {
                        return $retval[] = $key;
                    }
                }
            }
            else {
                $this->dbg_log("getPostKey() - Delay value specified so looking for specific post/delay combination");

                foreach( $this->posts as $key => $post ) {

                    // Haven't configured sequence to allow repetition so only returning a single key.
                    if ( ( !$this->allow_repetition() ) && ( $post->id == $post_id ) ) {

                        $retval[] = $key;
                        break;
                    }

                    if ( $this->allow_repetition() && ( $post_id == $post->id ) ) {
                        //Return all keys with the postId
                        $retval[] = $key;
                    }
                }
            }

            return $retval;
        }
        */
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

        /********************************* Add/Remove sequence posts *****************************/

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
            $this->dbg_log("add_post() for sequence {$this->sequence_id}: " . $this->who_called_me() );

	        if (! $this->is_valid_delay($delay) )
	        {
	            $this->dbg_log('add_post(): Admin specified an invalid delay value for post: ' . ( empty($post_id) ? 'Unknown' :  $post_id) );
	            $this->set_error_msg( sprintf(__('Invalid delay value - %s', 'pmprosequence'), ( empty($delay) ? 'blank' : $delay ) ) );
	            return false;
	        }

			if(empty($post_id) || !isset($delay))
			{
				$this->set_error_msg( __("Please enter a value for post and delay", 'pmprosequence') );
	            $this->dbg_log('add_post(): No Post ID or delay specified');
				return false;
			}

	        $this->dbg_log('add_post(): Post ID: ' . $post_id . ' and delay: ' . $delay);

			if ( $post = get_post($post_id) === null ) {

                $this->set_error_msg( __("A post with that id does not exist", 'pmprosequence') );
                $this->dbg_log('add_post(): No Post with ' . $post_id . ' found');

                return false;
            }

            // Refresh the post list for the sequence, ignore cache
            $this->dbg_log("add_post(): Refreshing post list for sequence #{$this->sequence_id}");
            $this->load_sequence_post();

			// Add this post to the current sequence.

            $this->dbg_log( "add_post() - Adding post {$post_id} with delay {$delay} to sequence {$this->sequence_id}");
            if (! $this->add_post_to_sequence( $this->sequence_id, $post_id, $delay) ) {

                $this->dbg_log("add_post() - ERROR: Unable to add post {$post_id} to sequence {$this->sequence_id} with delay {$delay}", DEBUG_SEQ_WARNING);
                return false;
            }

            // Fetch the list of sequences for this post, clean it up and save it (if needed)
            $post_in_sequences = $this->get_sequences_for_post( $post_id );

            // $post_in_sequences =  get_post_meta($post_id, "_post_sequences", true);
            // get_post_meta( $post_id, '_pmpro_sequence_id' );

            // Post is new in this sequence so saving sequence metadata for it.
            if ( empty( $post_in_sequences ) ) {

                // Is there any previously saved sequence ID found for the post/page?
                $this->dbg_log('add_post(): No previously defined sequence(s) found for this post (ID: ' . $post_id . ')');
                $post_in_sequences = array( $this->sequence_id );
            }

            $this->dbg_log( 'add_post(): Post/Page w/id ' . $post_id . ' belongs to one or more sequences already: ' . count( $post_in_sequences ) );

            if ( ! is_array( $post_in_sequences ) ) {

                $this->dbg_log( 'add_post(): Not (yet) an array of posts. Adding the single new post to a new array' );
                $post_in_sequences = array( $this->sequence_id );
            }

            // Bug Fix: Never checked if the Post/Page ID was already listed in the sequence.
            $tmp = array_count_values( $post_in_sequences );

            // Bug Fix: Off by one error
            // $cnt = $tmp[ ($this->sequence_id - 1) ];
            $cnt = isset( $tmp[ $this->sequence_id ] ) ? $tmp[ $this->sequence_id ] : 0;

            if ( 0 == $cnt ) {

                // This is the first sequence this post is added to
                $post_in_sequences[] = $this->sequence_id;
                $this->dbg_log( 'add_post(): Appended post (ID: ' . $post_id . ') to sequence ' . $this->sequence_id );
            }
            elseif ( $cnt > 1 ) {

                // There are so get rid of the extras (this is a backward compatibility feature due to a previous bug.)
                $this->dbg_log( 'add_post() - More than one entry in the array. Clean it up!' );

                $clean = array_unique( $post_in_sequences );

                $this->dbg_log( 'add_post() - Cleaned array: ' . print_r( $clean, true ) );
                $post_in_sequences = $clean;
            }

            //save
            // $this->save_sequence_post( $this->sequence_id, $post_id, $delay );

            //sort
            $this->dbg_log('add_post(): Sorting the sequence posts by delay value(s)');
            usort( $this->posts, array( $this, 'sort_posts_by_delay' ) );

            // Save the sequence list for this post id

            $this->set_sequences_for_post( $post_id, $post_in_sequences );
            // update_post_meta( $post_id, "_post_sequences", $post_in_sequences );

            $this->dbg_log('add_post(): Post/Page list updated and saved');

			return true;
	    }

/*
        public function updatePost( $post_id, $delay ) {

            $this->dbg_log("updatePost() for sequence {$this->sequence_id}: " . $this->who_called_me() );

	        if (! $this->is_valid_delay($delay) )
	        {
	            $this->dbg_log('updatePost(): Admin specified an invalid delay value for post: ' . ( empty($post_id) ? 'Unknown' :  $post_id) );
	            $this->set_error_msg( sprintf(__('Invalid delay value - %s', 'pmprosequence'), ( empty($delay) ? 'blank' : $delay ) ) );
	            return false;
	        }

			if(empty($post_id) || !isset($delay))
			{
				$this->set_error_msg( __("Please enter a value for post and delay", 'pmprosequence') );
	            $this->dbg_log('updatePost(): No Post ID or delay specified');
				return false;
			}

	        $this->dbg_log('updatePost(): Post ID: ' . $post_id . ' and delay: ' . $delay);

			if ( $post = get_post($post_id) === null ) {

                $this->set_error_msg( __("A post with that id does not exist", 'pmprosequence') );
                $this->dbg_log('updatePost(): No Post with ' . $post_id . ' found');

                return false;
            }

            // Refresh the post list for the sequence, ignore cache
            $this->dbg_log("updatePost(): Force refresh of post list for sequence");
            $this->load_sequence_post( null, null, null, '=', null, true );

			// Add post
			$temp = new stdClass();
			$temp->id = $post_id;
			$temp->delay = $delay;

            $key = $this->hasPost( $post_id );

			// Only update the post if it's already present.
			if ( $key !== false ) {

                $this->dbg_log( "updatePost() - Post already in sequence. Check if we need to update it. Post: {$this->posts[$key]->id} with delay {$this->posts[$key]->delay} versus {$delay}");

                switch ($this->options->delayType) {

                    case 'byDays':

                        if  ( intval($this->posts[$key]->delay) != intval($delay) ) {

                            $this->dbg_log("updatePost(): Delay is different. Need to update everything and clear the notices");
                            $this->remove_post( $post_id, true );
                            $this->posts[] = $temp;
                            $key = false;
                        }
                        break;

                    case 'byDate':

                        if ( $this->posts[$key]->delay != $delay ) {

                            $this->dbg_log("updatePost(): Delay is different. Need to update everything and clear the notices");
                            $this->remove_post( $post_id, true );
                            $this->posts[] = $temp;
                            $key = false;
                        }
                        break;
                }

                // Save the sequence list for this post id
                update_post_meta( $post_id, "_post_sequences", $post_sequence );

                $this->dbg_log('updatePost(): Post/Page list updated and saved');

            }

            return false;
        }
*/
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

                    $this->dbg_log("remove_post() - Delay metav_alues: ");
                    $this->dbg_log( $delays );

					if ( 1 == count( $delays ) ) {

                        $this->dbg_log("remove_post() - A single post associated with this post id: {$post_id}");

                        if ( false === delete_post_meta( $post_id, "_pmpro_sequence_{$this->sequence_id}_post_delay", $post->delay ) ) {

                            $this->dbg_log("remove_post() - Unable to remove the delay meta for {$post_id} / {$post->delay}");
                            return false;
                        }

                        if ( false === delete_post_meta( $post_id, "_pmpro_sequence_post_belongs_to", $this->sequence_id ) ) {

                            $this->dbg_log("remove_post() - Unable to remove the sequence meta for {$post_id} / {$this->sequence_id}");
                            return false;
                        }
					}
					elseif ( 1 < count( $delays ) ) {

                        $this->dbg_log($delays);
                        $this->dbg_log("remove_post() - Multiple (" . count( $delays ) . ") posts associated with this post id: {$post_id} in sequence {$this->sequence_id}");

                        if ( false == delete_post_meta( $post_id, "_pmpro_sequence_{$this->sequence_id}_post_delay", $post->delay ) ) {

                            $this->dbg_log("remove_post() - Unable to remove the sequence meta for {$post_id} / {$this->sequence_id}");
                            return false;
                        };

                        $this->dbg_log("remove_post() - Keeping the sequence info for the post_id");
					}
					else {
					    $this->dbg_log("remove_post() - ERROR: There are _no_ delay values for post ID {$post_id}????");
					    return false;
					}

                    $this->dbg_log("remove_post() - Removing entry #{$i} from posts array");
					unset( $this->posts[ $i ] );
				}
				/* elseif ( ( $post_id == $post->id ) && ( $delay != $post->delay ) ) {

				    $this->dbg_log("remove_post() - Found the correct post_id but has different delay value. Multiple instances of same post in list!");
				    $is_multi_post = true;
				} */
			}

			// Remove the post ($post_id) from all cases where a User has been notified.
            if ( $remove_alerted ) {

                $this->remove_post_notified_flag( $post_id, $delay );
            }

            /*
			// Remove the sequence id from the post's metadata
			$post_sequence = $this->get_sequences_for_post( $post_id );

			if ( is_array( $post_sequence ) && ( $key = array_search( $this->sequence_id, $post_sequence ) ) !== false ) {

                if ( !$is_multi_post ) {

                    unset( $post_sequence[$key] );

                    $this->set_sequences_for_post( $post_id, $post_sequence );
                    // update_post_meta( $post_id, "_post_sequences", $post_sequence );
                    $this->dbg_log( "removePost(): Post/Page list updated and saved" );
                    $this->dbg_log( "removePost(): Post/Page list is now: " . print_r( $post_sequence, true ) );
                }
	        }
            */
			return true;
		}

        /********************************************** Metaboxes **********************************************/

        /**
         * Configure metabox for the normal Post/Page editor
         */
        public function post_metabox( $object = null, $box = null ) {

            $this->dbg_log("post_metabox() Post metaboxes being configured");
            global $load_pmpro_sequence_admin_script;

            $load_pmpro_sequence_admin_script = true;

            foreach( $this->managed_types as $type ) {

                if ( $type !== 'pmpro_sequence' ) {
                    add_meta_box( 'pmpro-seq-post-meta', __( 'Drip Feed Settings', 'pmprosequence' ), array( &$this, 'render_post_edit_metabox' ), $type, 'side', 'high' );
                }
            }
        }

        /**
         * Initial load of the metabox for the editor sidebar
         */
        public function render_post_edit_metabox() {

            $metabox = '';

            global $post;

            $seq = new PMProSequence();

            $this->dbg_log("Page Metabox being loaded");

            ob_start();
            ?>
            <div class="submitbox" id="pmpro-seq-postmeta">
                <div id="minor-publishing">
                    <div id="pmpro_seq-configure-sequence">
                        <?php echo $seq->load_sequence_meta( $post->ID ) ?>
                    </div>
                </div>
            </div>
            <?php

            $metabox = ob_get_clean();

            echo $metabox;
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

            wp_reset_query();

            /* Fetch all Sequence posts - NOTE: Using WP_Query and not the sequence specific get_posts() function! */
            return get_posts( $query );
        }

        /**
         * Loads metabox content for the editor metabox (sidebar)
         *
         * @param int|null $post_id -- ID of Post being edited
         * @param int $seq_id -- ID of the sequence being added/edited.
         *
         * @return string - HTML of metabox content
         */
        public function load_sequence_meta( $post_id = null, $seq_id = 0) {

            $this->dbg_log("Parameters for load_sequence_meta() {$post_id} and {$seq_id}.");
            $belongs_to = array();

            /* Fetch all Sequence posts */
            $sequence_list = $this->get_all_sequences( 'any' );

            $this->dbg_log("Loading Sequences (count: " . count($sequence_list) . ")");

            // Post ID specified so we need to look for any sequence related metadata for this post

            if ( empty( $post_id ) ) {

                global $post;
                $post_id = $post->ID;
            }

            $this->dbg_log("Loading sequence ID(s) from DB");

            $belongs_to = $this->get_sequences_for_post( $post_id );
            // $belongs_to = get_post_meta( $post_id, "_post_sequences", true );

	        // Check that all of the sequences listed for the post actually exist.
	        // If not, clean up the $belongs_to array.
	        if ( !empty( $belongs_to ) ) {

		        $this->dbg_log("Belongs to: " .print_r( $belongs_to, true));

		        foreach ( $belongs_to as $cId ) {

			        if ( ! $this->sequence_exists( $cId ) ) {

				        $this->dbg_log( "Sequence {$cId} does not exist. Remove it (post id: {$post_id})." );

				        if ( ( $key = array_search( $cId, $belongs_to ) ) !== false ) {

					        $this->dbg_log( "Sequence ID {$cId} being removed", DEBUG_SEQ_INFO );
					        unset( $belongs_to[ $key ] );
				        }
			        }
		        }
	        }

            if ( !empty( $belongs_to ) ) { // get_post_meta( $post_id, "_post_sequences", true ) ) {

                if ( is_array( $belongs_to ) && ( $seq_id != 0 ) && ( ! in_array( $seq_id, $belongs_to ) ) ) {

                    $this->dbg_log("Adding the new sequence ID to the existing array of sequences");
                    array_push( $belongs_to, $seq_id );
                }
            }
            elseif ( $seq_id != 0 ) {

                $this->dbg_log("This post has never belonged to a sequence. Adding it to one now");
                $belongs_to = array( $seq_id );
            }
            else {
                // Empty array
                $belongs_to = array();
            }

            // Make sure there's at least one row in the Metabox.
            $this->dbg_log(" Ensure there's at least one entry in the table. Sequence ID: {$seq_id}");
            array_push( $belongs_to, 0 );

            // $this->dbg_log("Post belongs to # of sequence(s): " . count( $belongs_to ) . ", content: " . print_r( $belongs_to, true ) );
            ob_start();
            ?>
            <?php wp_nonce_field('pmpro-sequence-post-meta', 'pmpro_sequence_postmeta_nonce');?>
            <div class="seq_spinner vt-alignright"></div>
            <table style="width: 100%;" id="pmpro-seq-metatable">
                <tbody><?php

                foreach( $belongs_to as $active_id ) {

                    // Figure out the correct delay type and load the value for this post if it exists.
                    if ( $active_id != 0 ) {
                        $this->dbg_log("Loading options for {$active_id}");
                        $this->get_options( $active_id );
                    }
                    else {
                        $this->sequence_id = 0;
                        $this->options = $this->default_options();
                    }

                    $this->dbg_log("Loading all posts for {$active_id}");
                    $delays = null;

                    if ( $this->sequence_id != 0 ) {

                        // Force reload of the posts in this sequence
                        $this->load_sequence_post(null, null, null, '=', null, true );

                        $delays = $this->get_delay_for_post( $post_id, false );

                        foreach( $delays as $p ) {

                            $this->dbg_log( "Delay Value: {$p->delay}" );
                            $delayVal = " value='{$p->delay}' ";

                            list( $label, $inputHTML ) = $this->set_delay_input( $delayVal, $active_id );
                            echo $this->print_sequence_header( $active_id );
                            echo $this->print_sequence_entry( $sequence_list, $active_id, $inputHTML, $label );
                        }
                    }

                    if ( empty( $delays ) ) {

                        $delayVal = "value=''";
                        list( $label, $inputHTML ) = $this->set_delay_input( $delayVal, $active_id );
                        echo $this->print_sequence_header( $active_id );
                        echo $this->print_sequence_entry( $sequence_list, $active_id, $inputHTML, $label );
                    }

                    // $this->dbg_log(" Label: " . print_r( $label, true ) );
                } // Foreach ?>
                </tbody>
            </table>
            <div id="pmpro-seq-new">
                <hr class="pmpro-seq-hr" />
                <a href="#" id="pmpro-seq-new-meta" class="button-primary"><?php _e( "Add", "pmprosequence" ); ?></a>
                <a href="#" id="pmpro-seq-new-meta-reset" class="button"><?php _e( "Reset", "pmprosequence" ); ?></a>
            </div>
            <?php

            $html = ob_get_clean();

            return $html;
        }

        private function set_delay_input( $delayVal, $active_id ) {

            switch ( $this->options->delayType ) {

                case 'byDate':

                    $this->dbg_log("Configured to track delays by Date");
                    $delayFormat = __( 'Date', "pmprosequence" );
                    $starts = date_i18n( "Y-m-d", current_time('timestamp') );

                    if ( empty( $delayVal ) ) {
                        $inputHTML = "<input class='pmpro-seq-delay-info pmpro-seq-date' type='date' min='{$starts}' name='pmpro_seq-delay[]' id='pmpro_seq-delay_{$active_id}'>";
                    }
                    else {
                        $inputHTML = "<input class='pmpro-seq-delay-info pmpro-seq-date' type='date' name='pmpro_seq-delay[]' id='pmpro_seq-delay_{$active_id}'{$delayVal}>";
                    }

                    break;

                default:

                    $this->dbg_log("Configured to track delays by Day count");
                    $delayFormat = __('Day count', "pmprosequence");
                    $inputHTML = "<input class='pmpro-seq-delay-info pmpro-seq-days' type='text' id='pmpro_seq-delay_{$active_id}' name='pmpro_seq-delay[]'{$delayVal}>";

            }

            $label = sprintf( __("Delay (Format: %s)", "pmprosequence"), $delayFormat );

            return array( $label, $inputHTML );
        }

        private function print_sequence_entry( $sequence_list, $active_id, $inputHTML, $label ) {
            ob_start(); ?>
            <tr class="select-row-input<?php echo ( $active_id == 0 ? ' new-sequence-select' : ' sequence-select' ); ?>">
                <td class="sequence-list-dropdown">
                    <select class="<?php echo ( $active_id == 0 ? 'new-sequence-select' : 'pmpro_seq-memberof-sequences'); ?>" name="pmpro_seq-sequences[]">
                        <option value="0" <?php echo ( ( empty( $belongs_to ) || $active_id == 0) ? 'selected' : '' ); ?>><?php _e("Not managed", "pmprosequence"); ?></option><?php
                        // Loop through all of the sequences & create an option list
                        foreach ( $sequence_list as $sequence ) {

                        ?><option value="<?php echo $sequence->ID; ?>" <?php echo selected( $sequence->ID, $active_id ); ?>><?php echo $sequence->post_title; ?></option><?php
                        } ?>
                    </select>
                </td>
            </tr>
            <tr class="delay-row-label<?php echo ( $active_id == 0 ? ' new-sequence-delay-label' : ' sequence-delay-label' ); ?>">
                <td>
                    <label for="pmpro_seq-delay_<?php echo $active_id; ?>"> <?php echo $label; ?> </label>
                </td>
            </tr>
            <tr class="delay-row-input<?php echo ( $active_id == 0 ? ' new-sequence-delay' : ' sequence-delay' ); ?>">
                <td>
                    <?php echo $inputHTML; ?>
                    <label for="remove-sequence_<?php echo $active_id; ?>" ><?php _e('Remove: ', 'pmprosequence'); ?></label>
                    <input type="checkbox" name="remove-sequence" class="pmpro_seq-remove-seq" value="<?php echo $active_id; ?>">
                </td>
            </tr>
            </fieldset>
            <?php
            $html = ob_get_clean();
            return $html;
        }

        private function print_sequence_header( $active_id ) {

            ob_start(); ?>
            <fieldset>
                    <tr class="select-row-label<?php echo ( $active_id == 0 ? ' new-sequence-select-label' : ' sequence-select-label' ); ?>">
                        <td>
                            <label for="pmpro_seq-memberof-sequences"><?php _e("Managed by (drip content feed)", "pmprosequence"); ?></label>
                        </td>
                    </tr>
            <?php
            $html = ob_get_clean();
            return $html;
        }

	    /**
	     * Add the actual meta box definitions as add_meta_box() functions (3 meta boxes; One for the page meta,
	     * one for the Settings & one for the sequence posts/page definitions.
         *
         * @access public
	     */
	    public function define_metaboxes() {

			//PMPro box
			add_meta_box('pmpro_page_meta', __('Require Membership', 'pmprosequence'), 'pmpro_page_meta', 'pmpro_sequence', 'side');

            $this->dbg_log("Loading post meta boxes");

			// sequence settings box (for posts & pages)
	        add_meta_box('pmpros-sequence-settings', __('Settings for this Sequence', 'pmprosequence'), array( &$this, 'settings_meta_box'), 'pmpro_sequence', 'side', 'high');

			//sequence meta box
			add_meta_box('pmpro_sequence_meta', __('Posts in this Sequence', 'pmprosequence'), array(&$this, "sequence_settings_metabox"), 'pmpro_sequence', 'normal', 'high');
	    }

        /**
         * Defines the Admin UI interface for adding posts to the sequence
         *
         * @access public
         */
        public function sequence_settings_metabox() {

			global $post;

			if ( !isset( $this->sequence_id ) /* || ( $this->sequence_id != $post->ID )  */ ) {
                $this->dbg_log("sequence_settings_metabox() - Loading the sequence metabox for {$post->ID} and not {$this->sequence_id}");
                $this->init( $post->ID );
            }

	        $this->dbg_log('sequence_settings_metabox(): Load the post list meta box');

	        // Instantiate the settings & grab any existing settings if they exist.
	     ?>
			<div id="pmpro_sequence_posts">
			<?php
				$box = $this->get_post_list_for_metabox();
				echo $box['html'];
			?>
			</div>
			<?php
		}

		/**
         * Refreshes the Post list for the sequence
         *
         * @access public
		 */
		public function get_post_list_for_metabox() {
			// global $wpdb;

			//show posts
			$this->load_sequence_post();
            // $this->sort_by_delay();

	        $this->dbg_log('Displaying the back-end meta box content');

			ob_start();
			?>

			<?php // if(!empty($this->get_error_msg() )) { ?>
				<?php // $this->display_error(); ?>
			<?php //} ?>
			<table id="pmpro_sequencetable" class="pmpro_sequence_postscroll wp-list-table widefat fixed">
			<thead>
				<th><?php _e('Order', 'pmprosequence' ); ?></label></th>
				<th width="50%"><?php _e('Title', 'pmprosequence'); ?></th>
				<?php if ($this->options->delayType == 'byDays'): ?>
	                <th id="pmpro_sequence_delaylabel"><?php _e('Delay', 'pmprosequence'); ?></th>
	            <?php elseif ( $this->options->delayType == 'byDate'): ?>
	                <th id="pmpro_sequence_delaylabel"><?php _e('Avail. On', 'pmprosequence'); ?></th>
	            <?php else: ?>
	                <th id="pmpro_sequence_delaylabel"><?php _e('Not Defined', 'pmprosequence'); ?></th>
	            <?php endif; ?>
				<th></th>
				<th></th>
				<th></th>
			</thead>
			<tbody>
			<?php
			$count = 1;

			if ( empty($this->posts ) ) {
	            $this->dbg_log('No Posts found?');

				$this->set_error_msg( __('No posts/pages found for this sequence', 'pmprosequence') );
			?>
			<?php
			}
			else {
				foreach( $this->posts as $post ) {
				?>
					<tr>
						<td class="pmpro_sequence_tblNumber"><?php echo $count; ?>.</td>
						<td class="pmpro_sequence_tblPostname"><?php echo get_the_title($post->id) . " (ID: {$post->id})"; ?></td>
						<td class="pmpro_sequence_tblNumber"><?php echo $post->delay; ?></td>
						<td><?php
                            if ( true == $this->options->allowRepeatPosts ) { ?>
                            <a href="javascript:pmpro_sequence_editPost( <?php echo "{$post->id}, {$post->delay}"; ?> ); void(0); "><?php _e('Edit','pmprosequence'); ?></a><?php
                            }
                            else { ?>
                            <a href="javascript:pmpro_sequence_editPost( <?php echo "{$post->id}, {$post->delay}"; ?> ); void(0); "><?php _e('Post','pmprosequence'); ?></a><?php
                            } ?>
                        </td>
						<td><?php
                            if ( false == $this->opgettions->allowRepeatPosts ) { ?>
							<a href="javascript:pmpro_sequence_editEntry( <?php echo "{$post->id}, {$post->delay}" ;?> ); void(0);"><?php _e('Edit', 'pmprosequence'); ?></a><?php
                            } ?>
						</td>
						<td>
							<a href="javascript:pmpro_sequence_removeEntry( <?php echo "{$post->id}, {$post->delay}" ?> ); void(0);"><?php _e('Remove', 'pmprosequence'); ?></a>
						</td>
					</tr>
				<?php
					$count++;
				}
			}
			?>
			</tbody>
			</table>

			<div id="postcustomstuff">
				<p><strong><?php _e('Add/Edit Posts:', 'pmprosequence'); ?></strong></p>
				<table id="newmeta">
					<thead>
						<tr>
							<th><?php _e('Post/Page', 'pmprosequence'); ?></th>
	                        <?php if ($this->options->delayType == 'byDays'): ?>
	                            <th id="pmpro_sequence_delayentrylabel"><label for="pmpro_sequencedelay"><?php _e('Days to delay', 'pmprosequence'); ?></label></th>
	                        <?php elseif ( $this->options->delayType == 'byDate'): ?>
	                            <th id="pmpro_sequence_delayentrylabel"><label for="pmpro_sequencedelay"><?php _e("Release on (YYYY-MM-DDD)", 'pmprosequence'); ?></label></th>
	                        <?php else: ?>
	                            <th id="pmpro_sequence_delayentrylabel"><label for="pmpro_sequencedelay"><?php _e('Not Defined', 'pmprosequence'); ?></label></th>
	                        <?php endif; ?>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td>
							<select id="pmpro_sequencepost" name="pmpro_sequencepost">
								<option value=""></option>
							<?php
								if ( ($all_posts = $this->get_posts_from_db()) !== FALSE)
									foreach($all_posts as $p)
									{
									?>
									<option value="<?php echo $p->ID;?>"><?php echo esc_textarea($p->post_title);?> (#<?php echo $p->ID;?><?php echo $this->set_post_status( $p->post_status );?>)</option>
									<?php
									}
								else {
									$this->set_error_msg( __( 'No posts found in the database!', 'pmprosequence' ) );
									$this->dbg_log('Error during database search for relevant posts');
								}
							?>
							</select>
							<style> .select2-container {width: 100%;} </style>
                            <!-- <script type="text/javascript"> jQuery('#pmpro_sequencepost').select2();</script> -->
							</td>
							<td>
								<input id="pmpro_sequencedelay" name="pmpro_sequencedelay" type="text" value="" size="7" />
								<input id="pmpro_sequence_id" name="pmpro_sequence_id" type="hidden" value="<?php echo $this->sequence_id; ?>" size="7" />
								<?php wp_nonce_field('pmpro-sequence-add-post', 'pmpro_sequence_addpost_nonce'); ?>
								<?php wp_nonce_field('pmpro-sequence-rm-post', 'pmpro_sequence_rmpost_nonce'); ?>
							</td>
							<td><a class="button" id="pmpro_sequencesave" onclick="javascript:pmpro_sequence_addEntry(); return false;"><?php _e('Update Sequence', 'pmprosequence'); ?></a></td>
						</tr>
					</tbody>
				</table>
			</div>
			<?php

			$html = ob_get_clean();

			is_null( $this->get_error_msg() ) ?
				$this->dbg_log( "get_post_list_for_metabox() - No error found, should return success" ) :
				$this->dbg_log( "get_post_list_for_metabox() - Errors:" . $this->display_error() );

			return array(
				'success' => ( is_null( $this->get_error_msg() ) ? true : false ),
				'message' => ( is_null( $this->get_error_msg() ) ? null : ( is_array( $this->get_error_msg() ) ? join( ', ', $this->get_error_msg() ) : $this->get_error_msg() ) ),
				'html' => $html,
			);
		}

	    /**
	     * Defines the metabox for the Sequence Settings (per sequence page/list) on the Admin page
	     *
	     * @param $object -- The class object (sequence class)
	     * @param $box -- The metabox object
         *
         * @access public
	     *
	     */
	    public function settings_meta_box( $object, $box ) {

	        global $post;
            global $current_screen;

            $new_post = false;

            $this->dbg_log("settings_meta_box() - Post ID: {$post->ID} and Sequence ID: {$this->sequence_id}");

		    if ( ( ! isset( $this->sequence_id )  ) || ( $this->sequence_id != $post->ID ) ) {
                $this->dbg_log("settings_meta_box() - Loading sequence ID {$post->ID} in place of {$this->sequence_id}");
                $this->init( $post->ID );
            }

	        if ( $this->sequence_id != 0)
	        {
		        $this->dbg_log( "Loading sequence {$this->sequence_id} settings for Meta Box");
		        $this->get_options($this->sequence_id);
	            // $settings = $this->get_options($sequence_id);
	            // $this->dbg_log('Returned settings: ' . print_r($sequence->options, true));
	        }
	        else
	        {
	            $this->dbg_log('Not a valid Sequence ID, cannot load options');
                $this->set_error_msg( __('Invalid drip-feed sequence specified', 'pmprosequence') );
	            return;
	        }
	        // $this->dbg_log('settings_meta_box() - Loaded settings: ' . print_r($settings, true));


            if( ( 'pmpro_sequence' == $current_screen->post_type ) && ( $current_screen->action == 'add' )) {
                $this->dbg_log("Adding a new post so hiding the 'Send' for notification alerts");
                $new_post = true;
            }
		    // Buffer the HTML so we can pick it up in a variable.
		    ob_start();

	        ?>
	        <div class="submitbox" id="pmpro_sequence_meta">
	            <div id="minor-publishing">
                    <input type="hidden" name="pmpro_sequence_settings_noncename" id="pmpro_sequence_settings_noncename" value="<?php echo wp_create_nonce( plugin_basename(__FILE__) )?>" />
                    <input type="hidden" name="pmpro_sequence_settings_hidden_delay" id="pmpro_sequence_settings_hidden_delay" value="<?php echo esc_attr($this->options->delayType); ?>"/>
                    <input type="hidden" name="hidden_pmpro_seq_wipesequence" id="hidden_pmpro_seq_wipesequence" value="0"/>
                    <div id="pmpro-sequences-settings-metabox" class="pmpro-sequences-settings-table">
                        <!-- Checkbox rows: Hide, preview & membership length -->
                         <div class="pmpro-sequence-settings-display clear-after">
                            <div class="pmpro-sequences-settings-row pmpro-sequence-settings clear-after">
                                <div class="pmpro-sequence-setting-col-1">
                                    <input type="checkbox" value="1" id="pmpro_sequence_hidden" name="pmpro_sequence_hidden" title="<?php _e('Hide unpublished / future posts for this sequence', 'pmprosequence'); ?>" <?php checked( $this->options->hidden, 1); ?> />
                                    <input type="hidden" name="hidden_pmpro_seq_future" id="hidden_pmpro_seq_future" value="<?php echo esc_attr($this->options->hidden); ?>" >
                                </div>
                                <div class="pmpro-sequence-setting-col-2">
                                    <label class="selectit pmpro-sequence-setting-col-2"><?php _e('Hide all future posts', 'pmprosequence'); ?></label>
                                </div>
                                <div class="pmpro-sequence-setting-col-3"></div>
                            </div>
                        </div>
                        <div class="pmpro-sequence-settings-display clear-after">
                            <div class="pmpro-sequences-settings-row pmpro-sequence-settings clear-after">
                                <div class="pmpro-sequence-setting-col-1">
                                    <input type="checkbox" value="1" id="pmpro_sequence_allowRepeatPosts" name="pmpro_sequence_allowRepeatPosts" title="<?php _e('Allow the admin to repeat the same post/page with different delay values', 'pmprosequence'); ?>" <?php checked( $this->options->allowRepeatPosts, 1); ?> />
                                    <input type="hidden" name="hidden_pmpro_seq_allowRepeatPosts" id="hidden_pmpro_seq_allowRepeatPosts" value="<?php echo esc_attr($this->options->allowRepeatPosts); ?>" >
                                </div>
                                <div class="pmpro-sequence-setting-col-2">
                                    <label class="selectit"><?php _e('Allow repeat posts/pages', 'pmprosequence'); ?></label>
                                </div>
                                <div class="pmpro-sequence-setting-col-3"></div>
                            </div>
                        </div>
                        <div class="pmpro-sequence-settings-display clear-after">
                            <div class="pmpro-sequences-settings-row pmpro-sequence-settings clear-after">
                                <div class="pmpro-sequence-setting-col-1">
                                    <input type="checkbox" value="1" id="pmpro_sequence_offsetchk" name="pmpro_sequence_offsetchk" title="<?php _e('Let the user see a number of days worth of technically unavailable posts as a form of &quot;sneak-preview&quot;', 'pmprosequence'); ?>" <?php echo ( $this->options->previewOffset != 0 ? ' checked="checked"' : ''); ?> />
                                </div>
                                <div class="pmpro-sequence-setting-col-2">
                                    <label class="selectit"><?php _e('Allow "preview" of sequence', 'pmprosequence'); ?></label>
                                </div>
                                <div class="pmpro-sequence-setting-col-3"></div>
                            </div>
                        </div>
                        <div class="pmpro-sequence-offset pmpro-sequence-hidden pmpro-sequence-settings-display clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-settings pmpro-sequence-offset">
                                <div class="pmpro-sequence-setting-col-1">
                                    <label class="pmpro-sequence-label" for="pmpro-seq-offset"><?php _e('Days of preview:', 'pmprosequence'); ?> </label>
                                </div>
                                <div class="pmpro-sequence-setting-col-2">
                                    <span id="pmpro-seq-offset-status" class="pmpro-sequence-status"><?php echo ( $this->options->previewOffset == 0 ? 'None' : $this->options->previewOffset ); ?></span>
                                </div>
                                <div class="pmpro-sequence-setting-col-3">
                                    <a href="#" id="pmpro-seq-edit-offset" class="pmpro-seq-edit">
                                        <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
                                        <span class="screen-reader-text"><?php _e('Change the number of days to preview', 'pmprosequence'); ?></span>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="pmpro-sequence-offset pmpro-sequence-settings-input pmpro-sequence-hidden clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-settings pmpro-sequence-offset pmpro-sequence-full-row">
                                <div id="pmpro-seq-offset-select">
                                    <input type="hidden" name="hidden_pmpro_seq_offset" id="hidden_pmpro_seq_offset" value="<?php echo esc_attr($this->options->previewOffset); ?>" >
                                    <label for="pmpro_sequence_offset"></label>
                                    <select name="pmpro_sequence_offset" id="pmpro_sequence_offset">
                                    <option value="0">None</option>
                                    <?php foreach (range(1, 5) as $previewOffset) { ?>
                                        <option value="<?php echo esc_attr($previewOffset); ?>" <?php selected( intval($this->options->previewOffset), $previewOffset); ?> ><?php echo $previewOffset; ?></option>
                                    <?php } ?>
                                </select>
                                </div>
                            </div>
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-settings pmpro-sequence-offset pmpro-sequence-full-row">
                                <p class="pmpro-seq-offset">
                                    <a href="#" id="ok-pmpro-seq-offset" class="save-pmproseq-offset button"><?php _e('OK', 'pmprosequence'); ?></a>
                                    <a href="#" id="cancel-pmpro-seq-offset" class="cancel-pmproseq-offset button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
                                </p>
                            </div>
                        </div>
                        <div class="pmpro-sequence-settings-display clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-settings">
                                <div class="pmpro-sequence-setting-col-1">
                                    <input type="checkbox"  value="1" id="pmpro_sequence_lengthvisible" name="pmpro_sequence_lengthvisible" title="<?php _e('Whether to show the &quot;You are on day NNN of your membership&quot; text', 'pmprosequence'); ?>" <?php checked( $this->options->lengthVisible, 1); ?> />
                                    <input type="hidden" name="hidden_pmpro_seq_lengthvisible" id="hidden_pmpro_seq_lengthvisible" value="<?php echo esc_attr($this->options->lengthVisible); ?>" >
                                </div>
                                <div class="pmpro-sequence-setting-col-2">
                                    <label class="selectit"><?php _e("Show user membership length", 'pmprosequence'); ?></label>
                                </div>
                                <div class="pmpro-sequence-setting-col-3"></div>
                            </div>
                        </div>
                        <div class="pmpro-sequences-settings-row pmpro-sequence-full-row">
                            <hr style="width: 100%;"/>
                        </div>
                        <!-- Sort order, Delay type & Availability -->
                        <div class="pmpro-sequence-settings-display clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-sortorder pmpro-sequence-settings">
                                <div class="pmpro-sequence-setting-col-1">
                                    <label class="pmpro-sequence-label" for="pmpro-seq-sort"><?php _e('Sort order:', 'pmprosequence'); ?></label>
                                </div>
                                <div class="pmpro-sequence-setting-col-2">
                                    <span id="pmpro-seq-sort-status" class="pmpro-sequence-status"><?php echo ( $this->options->sortOrder == SORT_ASC ? __('Ascending', 'pmprosequence') : __('Descending', 'pmprosequence') ); ?></span>
                                </div>
                                <div class="pmpro-sequence-setting-col-3">
                                    <a href="#" id="pmpro-seq-edit-sort" class="pmpro-seq-edit pmpro-sequence-setting-col-3">
                                        <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
                                        <span class="screen-reader-text"><?php _e('Edit the list sort order', 'pmprosequence'); ?></span>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="pmpro-sequence-settings-input pmpro-sequence-hidden clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-settings pmpro-sequence-sortorder pmpro-sequence-full-row">
                                <div id="pmpro-seq-sort-select">
                                    <input type="hidden" name="hidden_pmpro_seq_sortorder" id="hidden_pmpro_seq_sortorder" value="<?php echo ($this->options->sortOrder == SORT_ASC ? SORT_ASC : SORT_DESC); ?>" >
                                    <label for="pmpro_sequence_sortorder"></label>
                                    <select name="pmpro_sequence_sortorder" id="pmpro_sequence_sortorder">
                                        <option value="<?php echo esc_attr(SORT_ASC); ?>" <?php selected( intval($this->options->sortOrder), SORT_ASC); ?> > <?php _e('Ascending', 'pmprosequence'); ?></option>
                                        <option value="<?php echo esc_attr(SORT_DESC); ?>" <?php selected( intval($this->options->sortOrder), SORT_DESC); ?> ><?php _e('Descending', 'pmprosequence'); ?></option>
                                    </select>
                                </div>
                            </div>
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-settings pmpro-sequence-sortorder pmpro-sequence-full-row">
                                <p class="pmpro-seq-btns">
                                    <a href="#" id="ok-pmpro-seq-sort" class="save-pmproseq-sortorder button"><?php _e('OK', 'pmprosequence'); ?></a>
                                    <a href="#" id="cancel-pmpro-seq-sort" class="cancel-pmproseq-sortorder button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
                                </p>
                            </div><!-- end of row -->
                        </div>
                        <div class="pmpro-sequence-settings-display clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-delaytype pmpro-sequence-settings">
                                <div class="pmpro-sequence-setting-col-1">
                                    <label class="pmpro-sequence-label" for="pmpro-seq-delay"><?php _e('Delay type:', 'pmprosequence'); ?></label>
                                </div>
                                <div class="pmpro-sequence-setting-col-2">
                                    <span id="pmpro-seq-delay-status" class="pmpro-sequence-status"><?php echo ($this->options->delayType == 'byDate' ? __('A date', 'pmprosequence') : __('Days after sign-up', 'pmprosequence') ); ?></span>
                                </div>
                                <div class="pmpro-sequence-setting-col-3">
                                    <a href="#" id="pmpro-seq-edit-delay" class="pmpro-seq-edit">
                                        <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
                                        <span class="screen-reader-text"><?php _e('Edit the delay type for this sequence', 'pmprosequence'); ?></span>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="pmpro-sequence-settings-input pmpro-sequence-hidden clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-delaytype pmpro-sequence-settings pmpro-sequence-full-row">
                                <div id="pmpro-seq-delay-select">
                                    <input type="hidden" name="hidden_pmpro_seq_delaytype" id="hidden_pmpro_seq_delaytype" value="<?php echo ($this->options->delayType != '' ? esc_attr($this->options->delayType): 'byDays'); ?>" >
                                    <label for="pmpro_sequence_delaytype"></label>
                                    <!-- onchange="pmpro_sequence_delayTypeChange(<?php echo esc_attr( $this->sequence_id ); ?>); return false;" -->
                                    <select name="pmpro_sequence_delaytype" id="pmpro_sequence_delaytype">
                                        <option value="byDays" <?php selected( $this->options->delayType, 'byDays'); ?> ><?php _e('Days after sign-up', 'pmprosequence'); ?></option>
                                        <option value="byDate" <?php selected( $this->options->delayType, 'byDate'); ?> ><?php _e('A date', 'pmprosequence'); ?></option>
                                    </select>
                                </div>
                            </div>
                            <div class="pmpro-sequences-settings-row clear-after pmpro-seq-delaytype pmpro-sequence-settings pmpro-sequence-full-row">
                                <div id="pmpro-seq-delay-btns">
                                    <p class="pmpro-seq-btns">
                                        <a href="#" id="ok-pmpro-seq-delay" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
                                        <a href="#" id="cancel-pmpro-seq-delay" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="pmpro-sequence-settings-display clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-seq-showdelayas pmpro-sequence-settings">
                            <div class="pmpro-sequence-setting-col-1">
                                <label class="pmpro-sequence-label" for="pmpro-seq-showdelayas"><?php _e("Show availability as:", 'pmprosequence'); ?></label>
                            </div>
                            <div class="pmpro-sequence-setting-col-2">
                                <span id="pmpro-seq-showdelayas-status" class="pmpro-sequence-status"><?php echo ($this->options->showDelayAs == PMPRO_SEQ_AS_DATE ? __('Calendar date', 'pmprosequence') : __('Day of membership', 'pmprosequence') ); ?></span>
                            </div>
                            <div class="pmpro-sequence-setting-col-3">
                                <a href="#" id="pmpro-seq-edit-showdelayas" class="pmpro-seq-edit pmpro-sequence-setting-col-3">
                                    <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
                                    <span class="screen-reader-text"><?php _e('How to indicate when the post will be available to the user. Select either "Calendar date" or "day of membership")', 'pmprosequence'); ?></span>
                                </a>
                            </div>
                        </div>
                        </div>
                        <div class="pmpro-sequence-settings-input pmpro-sequence-hidden clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-seq-showdelayas pmpro-sequence-settings pmpro-sequence-full-row">
                                <!-- Only show this if 'hidden_pmpro_seq_delaytype' == 'byDays' -->
                                <input type="hidden" name="hidden_pmpro_seq_showdelayas" id="hidden_pmpro_seq_showdelayas" value="<?php echo ($this->options->showDelayAs == PMPRO_SEQ_AS_DATE ? PMPRO_SEQ_AS_DATE : PMPRO_SEQ_AS_DAYNO ); ?>" >
                                <label for="pmpro_sequence_showdelayas"></label>
                                <select name="pmpro_sequence_showdelayas" id="pmpro_sequence_showdelayas">
                                    <option value="<?php echo PMPRO_SEQ_AS_DAYNO; ?>" <?php selected( $this->options->showDelayAs, PMPRO_SEQ_AS_DAYNO); ?> ><?php _e('Day of membership', 'pmprosequence'); ?></option>
                                    <option value="<?php echo PMPRO_SEQ_AS_DATE; ?>" <?php selected( $this->options->showDelayAs, PMPRO_SEQ_AS_DATE); ?> ><?php _e('Calendar date', 'pmprosequence'); ?></option>
                                </select>
                            </div>
                            <div class="pmpro-sequences-settings-row clear-after pmpro-seq-showdelayas pmpro-sequence-settings pmpro-sequence-full-row">
                                <div id="pmpro-seq-delay-btns">
                                    <p class="pmpro-seq-btns">
                                        <a href="#" id="ok-pmpro-seq-delay" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
                                        <a href="#" id="cancel-pmpro-seq-delay" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-full-row">
                            <div class="pmpro-seq-alert-hl"><?php _e('New content alerts', 'pmprosequence'); ?></div>
                            <hr style="width: 100%;" />
                        </div><!-- end of row -->
                        <!--Email alerts -->
                        <div class="pmpro-sequence-settings-display clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-alerts pmpro-sequence-settings">
                                <div class="pmpro-sequence-setting-col-1">
                                    <input type="checkbox" value="1" title="<?php _e('Whether to send an alert/notice to members when new content for this sequence is available to them', 'pmprosequence'); ?>" id="pmpro_sequence_sendnotice" name="pmpro_sequence_sendnotice" <?php checked($this->options->sendNotice, 1); ?> />
                                    <input type="hidden" name="hidden_pmpro_seq_sendnotice" id="hidden_pmpro_seq_sendnotice" value="<?php echo esc_attr($this->options->sendNotice); ?>" >
                                </div>
                                <div class="pmpro-sequence-setting-col-2">
                                    <label class="selectit" for="pmpro_sequence_sendnotice"><?php _e('Send email alerts', 'pmprosequence'); ?></label>
                                </div>
                                <div class="pmpro-sequence-setting-col-3">&nbsp;</div>
                            </div>
                        </div> <!-- end of row -->
                        <!-- Send now -->
                        <div class="pmpro-sequence-settings-display pmpro-sequence-email clear-after <?php echo ( $new_post ? 'pmpro-sequence-hidden' : null ); ?>">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-sendnowbtn pmpro-sequence-settings">
                                <div class="pmpro-sequence-setting-col-1"><label for="pmpro_seq_send"><?php _e('Send alerts now', 'pmprosequence'); ?></label></div>
                                <div class="pmpro-sequence-setting-col-2">
                                    <?php wp_nonce_field('pmpro-sequence-sendalert', 'pmpro_sequence_sendalert_nonce'); ?>
                                </div>
                                <div class="pmpro-sequence-setting-col-3">
                                    <a href="#" class="pmpro-seq-settings-send pmpro-seq-edit" id="pmpro_seq_send">
                                        <span aria-hidden="true"><?php _e('Send', 'pmprosequence'); ?></span>
                                        <span class="screen-reader-text"><?php echo sprintf( __( 'Manually trigger sending of alert notices for the %s sequence', 'pmprosequence'), get_the_title( $this->sequence_id) ); ?></span>
                                    </a>
                                </div>
                            </div><!-- end of row -->
                        </div>
                        <div class="pmpro-sequence-settings-display pmpro-sequence-email clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-full-row">
                                <p class="pmpro-seq-email-hl"><?php _e("Alert settings:", 'pmprosequence'); ?></p>
                            </div>
                        </div>
                        <div class="pmpro-sequence-settings-display pmpro-sequence-email clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-replyto pmpro-sequence-settings">
                                <div class="pmpro-sequence-setting-col-1">
                                    <label class="pmpro-sequence-label" for="pmpro-seq-replyto"><?php _e('Email:', 'pmprosequence'); ?> </label>
                                </div>
                                <div class="pmpro-sequence-setting-col-2">
                                    <span id="pmpro-seq-replyto-status" class="pmpro-sequence-status"><?php echo ( $this->options->replyto != '' ? esc_attr($this->options->replyto) : pmpro_getOption("from_email") ); ?></span>
                                </div>
                                <div class="pmpro-sequence-setting-col-3">
                                    <a href="#" id="pmpro-seq-edit-replyto" class="pmpro-seq-edit">
                                        <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
                                        <span class="screen-reader-text"><?php _e('Enter the email address to use as the sender of the alert', 'pmprosequence'); ?></span>
                                    </a>
                                </div>
                            </div>
                        </div><!-- end of row -->
                        <div class="pmpro-sequence-settings-input pmpro-sequence-hidden pmpro-sequence-email clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-email pmpro-sequence-replyto pmpro-sequence-full-row">
                                <div id="pmpro-seq-email-input">
                                    <input type="hidden" name="hidden_pmpro_seq_replyto" id="hidden_pmpro_seq_replyto" value="<?php echo ($this->options->replyto != '' ? esc_attr($this->options->replyto) : pmpro_getOption("from_email") ); ?>" />
                                    <label for="pmpro_sequence_replyto"></label>
                                    <input type="text" name="pmpro_sequence_replyto" id="pmpro_sequence_replyto" value="<?php echo ($this->options->replyto != '' ? esc_attr($this->options->replyto) : pmpro_getOption("from_email")); ?>"/>
                                </div>
                            </div>
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-email pmpro-sequence-settings pmpro-sequence-full-row">
                                <p class="pmpro-seq-btns">
                                    <a href="#" id="ok-pmpro-seq-email" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
                                    <a href="#" id="cancel-pmpro-seq-email" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
                                </p>
                            </div><!-- end of row -->
                        </div>
                        <div class="pmpro-sequence-settings-display pmpro-sequence-email clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-fromname pmpro-sequence-settings">
                                <div class="pmpro-sequence-setting-col-1">
                                    <label class="pmpro-sequence-label" for="pmpro-seq-fromname"><?php _e('Name:', 'pmprosequence'); ?> </label>
                                </div>
                                <div class="pmpro-sequence-setting-col-2">
                                    <span id="pmpro-seq-fromname-status" class="pmpro-sequence-status"><?php echo ($this->options->fromname != '' ? esc_attr($this->options->fromname) : pmpro_getOption("from_name") ); ?></span>
                                </div>
                                <div class="pmpro-sequence-setting-col-3">
                                    <a href="#" id="pmpro-seq-edit-fromname" class="pmpro-seq-edit pmpro-sequence-setting-col-3">
                                        <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
                                        <span class="screen-reader-text"><?php _e('Enter the name to use for the sender of the alert', 'pmprosequence'); ?></span>
                                    </a>
                                </div>
                            </div>
                        </div><!-- end of row -->
                        <div class="pmpro-sequence-settings-input pmpro-sequence-hidden pmpro-sequence-email clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-replyto pmpro-sequence-full-row">
                                <div id="pmpro-seq-email-input">
                                    <label for="pmpro_sequence_fromname"></label>
                                    <input type="text" name="pmpro_sequence_fromname" id="pmpro_sequence_fromname" value="<?php echo ($this->options->fromname != '' ? esc_attr($this->options->fromname) : pmpro_getOption("from_name") ); ?>"/>
                                    <input type="hidden" name="hidden_pmpro_seq_fromname" id="hidden_pmpro_seq_fromname" value="<?php echo ($this->options->fromname != '' ? esc_attr($this->options->fromname) : pmpro_getOption("from_name")); ?>" />
                                </div>
                            </div>
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-settings pmpro-sequence-full-row">
                                <p class="pmpro-seq-btns">
                                    <a href="#" id="ok-pmpro-seq-email" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
                                    <a href="#" id="cancel-pmpro-seq-email" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
                                </p>
                            </div><!-- end of row -->
                        </div>
                        <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-full-row pmpro-sequence-email clear-after">
                            <hr width="80%"/>
                        </div><!-- end of row -->
                        <div class="pmpro-sequence-settings-display pmpro-sequence-email clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-sendas pmpro-sequence-settings">
                                <div class="pmpro-sequence-setting-col-1">
                                    <label class="pmpro-sequence-label" for="pmpro-seq-sendas"><?php _e('Transmit:', 'pmprosequence'); ?> </label>
                                </div>
                                <div class="pmpro-sequence-setting-col-2">
                                    <span id="pmpro-seq-sendas-status" class="pmpro-sequence-status pmpro-sequence-setting-col-2"><?php echo ( $this->options->noticeSendAs = 10 ? _e('One alert per post', 'pmprosequence') : _e('Digest of posts', 'pmprosequence')); ?></span>
                                </div>
                                <div class="pmpro-sequence-setting-col-3">
                                    <a href="#" id="pmpro-seq-edit-sendas" class="pmpro-seq-edit pmpro-sequence-setting-col-3">
                                        <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
                                        <span class="screen-reader-text"><?php _e('Select the format of the alert notice when posting new content for this sequence', 'pmprosequence'); ?></span>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="pmpro-sequence-settings-input pmpro-sequence-hidden pmpro-sequence-email clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-sendas pmpro-sequence-full-row">
                                <div id="pmpro-seq-sendas-select">
                                    <input type="hidden" name="hidden_pmpro_seq_sendas" id="hidden_pmpro_seq_sendas" value="<?php echo esc_attr($this->options->noticeSendAs); ?>" >
                                    <label for="pmpro_sequence_sendas"></label>
                                    <select name="pmpro_sequence_sendas" id="pmpro_sequence_sendas">
                                        <option value="<?php echo PMPRO_SEQ_SEND_AS_SINGLE; ?>" <?php selected( $this->options->noticeSendAs, PMPRO_SEQ_SEND_AS_SINGLE ); ?> ><?php _e('One alert per post', 'pmprosequence'); ?></option>
                                        <option value="<?php echo PMPRO_SEQ_SEND_AS_LIST; ?>" <?php selected( $this->options->noticeSendAs, PMPRO_SEQ_SEND_AS_LIST ); ?> ><?php _e('Digest of post links', 'pmprosequence'); ?></option>
                                    </select>
                                    <p class="pmpro-seq-btns">
                                        <a href="#" id="ok-pmpro-seq-sendas" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
                                        <a href="#" id="cancel-pmpro-seq-sendas" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
                                    </p>
                                </div>
                            </div>
                        </div><!-- end of row -->
                        <div class="pmpro-sequence-settings-display pmpro-sequence-email clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-template pmpro-sequence-settings">
                                <div class="pmpro-sequence-setting-col-1">
                                    <label class="pmpro-sequence-label" for="pmpro-seq-template"><?php _e('Template:', 'pmprosequence'); ?> </label>
                                </div>
                                <div class="pmpro-sequence-setting-col-2">
                                    <span id="pmpro-seq-template-status" class="pmpro-sequence-status"><?php echo esc_attr( $this->options->noticeTemplate ); ?></span>
                                </div>
                                <div class="pmpro-sequence-setting-col-3">
                                    <a href="#" id="pmpro-seq-edit-template" class="pmpro-seq-edit">
                                        <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
                                        <span class="screen-reader-text"><?php _e('Select the template to use when posting new content in this sequence', 'pmprosequence'); ?></span>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="pmpro-sequence-settings-input pmpro-sequence-hidden pmpro-sequence-email clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro_sequence_fromname pmpro-sequence-settings pmpro-sequence-full-row">
                                <div id="pmpro-seq-template-select">
                                    <input type="hidden" name="hidden_pmpro_seq_noticetemplate" id="hidden_pmpro_seq_noticetemplate" value="<?php echo esc_attr($this->options->noticeTemplate); ?>" >
                                    <label for="pmpro_sequence_template"></label>
                                    <select name="pmpro_sequence_template" id="pmpro_sequence_template">
                                        <?php echo $this->get_email_templates(); ?>
                                    </select>
                                </div>
                            </div>
                            <div class="pmpro-sequences-settings-row clear-after pmpro_sequence_fromname pmpro-sequence-settings pmpro-sequence-full-row">
                                <p class="pmpro-seq-btns">
                                    <a href="#" id="ok-pmpro-seq-template" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
                                    <a href="#" id="cancel-pmpro-seq-template" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
                                </p>
                            </div> <!-- end of row -->
                        </div>
                        <div class="pmpro-sequence-settings-display pmpro-sequence-email clear-after">
                            <div class="pmpro-sequences-settings-row pmpro-sequence-settings clear-after pmpro-sequence-noticetime pmpro-sequence-email">
                                <div class="pmpro-sequence-setting-col-1">
                                    <label class="pmpro-sequence-label" for="pmpro-seq-noticetime"><?php _e('When:', 'pmprosequence'); ?> </label>
                                </div>
                                <div class="pmpro-sequence-setting-col-2">
                                    <span id="pmpro-seq-noticetime-status" class="pmpro-sequence-status"><?php echo esc_attr($this->options->noticeTime); ?></span>
                                </div>
                                <div class="pmpro-sequence-setting-col-3">
                                    <a href="#" id="pmpro-seq-edit-noticetime" class="pmpro-seq-edit">
                                        <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
                                        <span class="screen-reader-text"><?php _e('Select when (tomorrow) to send new content posted alerts for this sequence', 'pmprosequence'); ?></span>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="pmpro-sequence-settings-input pmpro-sequence-hidden pmpro-sequence-email clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-noticetime pmpro-sequence-settings pmpro-sequence-full-row">
                                <div id="pmpro-seq-noticetime-select">
                                    <input type="hidden" name="hidden_pmpro_seq_noticetime" id="hidden_pmpro_seq_noticetime" value="<?php echo esc_attr($this->options->noticeTime); ?>" >
                                    <label for="pmpro_sequence_noticetime"></label>
                                    <select name="pmpro_sequence_noticetime" id="pmpro_sequence_noticetime">
                                        <?php echo $this->load_time_options(); ?>
                                    </select>
                                </div>
                            </div>
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-noticetime pmpro-sequence-settings pmpro-sequence-full-row">
                                <p class="pmpro-seq-btns">
                                    <a href="#" id="ok-pmpro-seq-noticetime" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
                                    <a href="#" id="cancel-pmpro-seq-noticetime" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
                                </p>
                            </div>
                        </div> <!-- end of setting -->
                        <div class="pmpro-sequence-settings-display pmpro-sequence-email clear-after">
                            <div class="pmpro-sequences-settings-row pmpro-sequence-settings clear-after pmpro-sequence-timezone-setting">
                                <div class="pmpro-sequence-setting-col-1">
                                    <label class="pmpro-sequence-label" for="pmpro-seq-noticetime"><?php _e('Timezone:', 'pmprosequence'); ?> </label>
                                </div>
                                <div class="pmpro-sequence-setting-col-2">
                                    <span class="pmpro-sequence-status" id="pmpro-seq-noticetimetz-status"><?php echo get_option('timezone_string'); ?></span>
                                </div>
                            </div>
                        </div><!-- end of setting -->
                        <div class="pmpro-sequence-settings-display pmpro-sequence-email clear-after">
                            <div class="pmpro-sequences-settings-row pmpro-sequence-settings clear-after pmpro-sequence-subject">
                                <div class="pmpro-sequence-setting-col-1">
                                    <label class="pmpro-sequence-label" for="pmpro-seq-subject"><?php _e("Subject", "pmprosequence"); ?></label>
                                </div>
                                <div class="pmpro-sequence-setting-col-2">
                                    <span id="pmpro-seq-subject-status" class="pmpro-sequence-status"><?php echo ( $this->options->subject != '' ? esc_attr($this->options->subject) : __('New Content', 'pmprosequence') ); ?></span>
                                </div>
                                <div class="pmpro-sequence-setting-col-3">
                                    <a href="#" id="pmpro-seq-edit-subject" class="pmpro-seq-edit">
                                        <span aria-hidden="true"><?php _e("Edit", "pmprosequence"); ?></span>
                                        <span class="screen-reader-text"><?php _e("Update/Edit the Prefix for the subject of the new content alert", "pmprosequence"); ?></span>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="pmpro-sequence-settings-input pmpro-sequence-hidden pmpro-sequence-email clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-subject pmpro-sequence-settings pmpro-sequence-full-row">
                                <div id="pmpro-seq-subject-input">
                                    <input type="hidden" name="hidden_pmpro_seq_subject" id="hidden_pmpro_seq_subject" value="<?php echo ( $this->options->subject != '' ? esc_attr($this->options->subject) : __('New Content', 'pmprosequence') ); ?>" />
                                    <label for="pmpro_sequence_subject"></label>
                                    <input type="text" name="pmpro_sequence_subject" id="pmpro_sequence_subject" value="<?php echo ( $this->options->subject != '' ? esc_attr($this->options->subject) : __('New Content', 'pmprosequence') ); ?>"/>
                                </div>
                            </div>
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-subject pmpro-sequence-settings pmpro-sequence-full-row">
                                <p class="pmpro-seq-btns">
                                    <a href="#" id="ok-pmpro-seq-subject" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
                                    <a href="#" id="cancel-pmpro-seq-subject" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
                                </p>
                            </div>
                        </div><!-- end of setting -->
                        <div class="pmpro-sequence-settings-display pmpro-sequence-email clear-after">
                            <div class="pmpro-sequences-settings-row pmpro-sequence-settings pmpro-sequence-excerpt">
                                <div class="pmpro-sequence-setting-col-1">
                                    <label class="pmpro-sequence-label" for="pmpro-seq-excerpt"><?php _e('Intro:', 'pmprosequence'); ?> </label>
                                </div>
                                <div class="pmpro-sequence-setting-col-2">
                                    <span id="pmpro-seq-excerpt-status" class="pmpro-sequence-status">"<?php echo ( $this->options->excerpt_intro != '' ? esc_attr($this->options->excerpt_intro) : __('A summary for the new content follows:', 'pmprosequence') ); ?>"</span>
                                </div>
                                <div class="pmpro-sequence-setting-col-3">
                                    <a href="#" id="pmpro-seq-edit-excerpt" class="pmpro-seq-edit">
                                        <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
                                        <span class="screen-reader-text"><?php _e('Update/Edit the introductory paragraph for the new content excerpt', 'pmprosequence'); ?></span>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="pmpro-sequence-settings-input pmpro-sequence-hidden pmpro-sequence-email clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-excerpt pmpro-sequence-settings pmpro-sequence-full-row">
                                <div id="pmpro-seq-excerpt-input">
                                    <input type="hidden" name="hidden_pmpro_seq_excerpt" id="hidden_pmpro_seq_excerpt" value="<?php echo ($this->options->excerpt_intro != '' ? esc_attr($this->options->excerpt_intro) : __('A summary for the new content follows:', 'pmprosequence') ); ?>" />
                                    <label for="pmpro_sequence_excerpt"></label>
                                    <input type="text" name="pmpro_sequence_excerpt" id="pmpro_sequence_excerpt" value="<?php echo ($this->options->excerpt_intro != '' ? esc_attr($this->options->excerpt_intro) : __('A summary for the new content follows:', 'pmprosequence') ); ?>"/>
                                </div>
                            </div>
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-excerpt pmpro-sequence-settings pmpro-sequence-full-row">
                                <p class="pmpro-seq-btns">
                                    <a href="#" id="ok-pmpro-seq-excerpt" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
                                    <a href="#" id="cancel-pmpro-seq-excerpt" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
                                </p>
                            </div>
                        </div> <!-- end of setting -->
                        <div class="pmpro-sequence-settings-display pmpro-sequence-email clear-after">
                            <div class="pmpro-sequences-settings-row pmpro-sequence-settings pmpro-sequence-dateformat">
                                <div class="pmpro-sequence-setting-col-1">
                                    <label class="pmpro-sequence-label" for="pmpro-seq-dateformat"><?php _e('Date type:', 'pmprosequence'); ?> </label>
                                </div>
                                <div class="pmpro-sequence-setting-col-2">
                                    <span id="pmpro-seq-dateformat-status" class="pmpro-sequence-status">"<?php echo ( trim($this->options->dateformat) == false ? __('m-d-Y', 'pmprosequence') : esc_attr($this->options->dateformat) ); ?>"</span>
                                </div>
                                <div class="pmpro-sequence-setting-col-3">
                                    <a href="#" id="pmpro-seq-edit-dateformat" class="pmpro-seq-edit">
                                        <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
                                        <span class="screen-reader-text"><?php _e('Update/Edit the format of the !!today!! placeholder (a valid PHP date() format)', 'pmprosequence'); ?></span>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="pmpro-sequence-settings-input pmpro-sequence-hidden pmpro-sequence-email clear-after">
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-dateformat pmpro-sequence-settings pmpro-sequence-full-row">
                                <div id="pmpro-seq-dateformat-select">
                                    <input type="hidden" name="hidden_pmpro_seq_dateformat" id="hidden_pmpro_seq_dateformat" value="<?php echo ( trim($this->options->dateformat) == false ? __('m-d-Y', 'pmprosequence') : esc_attr($this->options->dateformat) ); ?>" />
                                    <label for="pmpro_pmpro_sequence_dateformat"></label>
                                    <select name="pmpro_sequence_dateformat" id="pmpro_sequence_dateformat">
                                        <?php echo $this->list_date_formats(); ?>
                                    </select>
                                </div>
                            </div>
                            <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-dateformat pmpro-sequence-settings pmpro-sequence-full-row">
                                <p class="pmpro-seq-btns">
                                    <a href="#" id="ok-pmpro-seq-dateformat" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
                                    <a href="#" id="cancel-pmpro-seq-dateformat" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
                                </p>
                            </div>
                        </div> <!-- end of setting -->
<!--                        <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-full-row">
                            <hr style="width: 100%;" />
                        </div> --><!-- end of row -->
<!--                         <div class="pmpro-sequences-settings-row clear-after pmpro-sequence-full-row">
                            <a class="button button-primary button-large" class="pmpro-seq-settings-save" id="pmpro_settings_save" onclick="pmpro_sequence_saveSettings(<?php echo $this->sequence_id;?>) ; return false;"><?php _e('Update Settings', 'pmprosequence'); ?></a>
                            <?php wp_nonce_field('pmpro-sequence-save-settings', 'pmpro_sequence_settings_nonce'); ?>
                            <div class="seq_spinner"></div>
                        </div>--><!-- end of row -->

                    </div><!-- End of sequences settings table -->
                <!-- TODO: Enable and implement
	                <tr id="pmpro_sequenceseq_start_0" style="display: none;">
	                    <td>
	                        <input id='pmpro_sequence_enablestartwhen' type="checkbox" value="1" title="<?php _e('Configure start parameters for sequence drip. The default is to start day 1 exactly 24 hours after membership started, using the servers timezone and recorded timestamp for the membership check-out.', 'pmprosequence'); ?>" name="pmpro_sequence_enablestartwhen" <?php echo ($this->options->startWhen != 0) ? 'checked="checked"' : ''; ?> />
	                    </td>
	                    <td><label class="selectit"><?php _e('Sequence starts', 'pmprosequence'); ?></label></td>
	                </tr>
	                <tr id="pmpro_sequence_seq_start_1" style="display: none; height: 1px;">
	                    <td colspan="2">
	                        <label class="screen-reader-text" for="pmpro_sequence_startwhen">Day 1 Starts</label>
	                    </td>
	                </tr>
	                <tr id="pmpro_sequence_seq_start_2" style="display: none;" id="pmpro_sequence_selectWhen">
	                    <td colspan="2">
	                        <select name="pmpro_sequence_startwhen" id="pmpro_sequence_startwhen">
	                            <option value="0" <?php selected( intval($this->options->startWhen), '0'); ?> >Immediately</option>
	                            <option value="1" <?php selected( intval($this->options->startWhen), '1'); ?> >24 hours after membership started</option>
	                            <option value="2" <?php selected( intval($this->options->startWhen), '2'); ?> >At midnight, immediately after membership started</option>
	                            <option value="3" <?php selected( intval($this->options->startWhen), '3'); ?> >At midnight, 24+ hours after membership started</option>
	                        </select>
	                    </td>
	                </tr>

	            </table> -->
	            </div> <!-- end of minor-publishing div -->
	        </div> <!-- end of pmpro_sequence_meta -->
		<?php
		    $metabox = ob_get_clean();

		    $this->dbg_log('settings_meta_box() - Display the settings meta.');
		    // Display the metabox (print it)
		    echo $metabox;
	    }

        /********************************* Plugin Display Functionality *******************/

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

            if ( ( "pmpro_sequence" == $post->post_type ) && pmpro_has_membership_access() ) {

                global $load_pmpro_sequence_script;

                $load_pmpro_sequence_script = true;

                $this->dbg_log( "display_sequence_content() - PMPRO Sequence display {$post->ID} - " . get_the_title( $post->ID ) . " : " . $this->who_called_me() . ' and page base: ' . $pagenow );

                $this->init( $post->ID );

                // If we're supposed to show the "days of membership" information, adjust the text for type of delay.
                if ( intval( $this->options->lengthVisible ) == 1 ) {

                    $content .= sprintf("<p>%s</p>", sprintf(__("You are on day %s of your membership", "pmprosequence"), $this->get_membership_days()));
                }

                // Add the list of posts in the sequence to the content.
                $content .= $this->get_post_list_as_html();
            }

            return $content;
        }

        public function get_sequences_for_post( $post_id ) {

            $this->dbg_log("get_sequences_for_post() - Check whether we've still got old post_sequences data stored. " . $this->who_called_me() );
            $this->dbg_log( $post_id );

            if ( ( false !== ( $post_sequences = get_post_meta( $post_id, "_post_sequences", true) ) && ( !empty($post_sequences)) )) {

                $this->dbg_log("get_sequences_for_post() - Need to migrate to V3 sequence list for post ID {$post_id}", DEBUG_SEQ_WARNING );
                $this->dbg_log($post_sequences);

                if ( !empty( $post_sequences ) ) {

                    foreach ( $post_sequences as $seq_id ) {

                        add_post_meta( $post_id, '_pmpro_sequence_post_belongs_to', $seq_id, true ) or
                            update_post_meta( $post_id, '_pmpro_sequence_post_belongs_to', $seq_id );
                    }
                }

                $this->dbg_log("get_sequences_for_post() - Removing old sequence list metadata");
                delete_post_meta( $post_id, '_post_sequences' );
            }

            $this->dbg_log("get_sequences_for_post() - Attempting to load sequence list for post {$post_id}", DEBUG_SEQ_INFO );
            $sequence_ids = get_post_meta( $post_id, '_pmpro_sequence_post_belongs_to' );

            $this->dbg_log("get_sequences_for_post() - Loaded " . count( $sequence_ids ) . " sequences that post # {$post_id} belongs to", DEBUG_SEQ_INFO );
            // $this->dbg_log($sequence_ids);

            return ( empty( $sequence_ids ) ? array() : $sequence_ids );
        }

        public function set_sequences_for_post( $post_id, $sequence_ids ) {

            $this->dbg_log("set_sequences_for_post() - Adding sequence info to post # {$post_id}");
            if ( is_array( $sequence_ids ) ) {

                $this->dbg_log("set_sequences_for_post() - Received array of sequences to add to post # {$post_id}");
                foreach( $sequence_ids as $id ) {

                    return (add_post_meta( $post_id, '_pmpro_sequence_post_belongs_to', $id, true ) or
                        update_post_meta( $post_id, '_pmpro_sequence_post_belongs_to', $id ) );
                }
            }
            else {

                $this->dbg_log("set_sequences_for_post() - Received sequence id ({$sequence_ids} to add for post # {$post_id}");
                return ( add_post_meta( $post_id, '_pmpro_sequence_post_belongs_to', $sequence_ids, true ) or
                    update_post_meta( $post_id, '_pmpro_sequence_post_belongs_to', $sequence_ids ) );
            }

            return false;

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

                $this->dbg_log("text_filter() - Current sequence ID: {$this->sequence_id} vs Post ID: {$post->ID}" );

                // if ( ! $this->has_access( $current_user->ID, $post->ID ) ) {

                    // $post_sequences = get_post_meta($post->ID, "_post_sequences", true);

                $post_sequences = $this->get_sequences_for_post( $post->ID );
                $days_since_start = $this->get_membership_days( $current_user->ID );

                //Update text. The user either will have to wait or sign up.
                $insequence = false;

                foreach ( $post_sequences as $ps ) {

                    $this->dbg_log("text_filter() - Checking access to {$ps}");

                    if ( $this->has_sequence_access( $current_user->ID, $ps ) ) {

                        $this->dbg_log("text_filter() - It's possible user has access to sequence: {$ps} ");
                        $insequence = $ps;
                        $this->init( $ps );

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
                        $text = sprintf("%s<br/>", sprintf( __("This content is only available to existing members at the specified time or day. (Required membership: <a href='%s'>%s</a>", 'pmprosequence'), get_permalink($ps), get_the_title($ps)) );

                        switch ( $this->options->delayType ) {

                            case 'byDays':

                                switch ( $this->options->showDelayAs ) {

                                    case PMPRO_SEQ_AS_DAYNO:

                                        $text .= sprintf( __( 'You will be able to access "%s" on day %s of your membership', 'pmprosequence' ), get_the_title( $post_id ), $this->display_proper_delay( $delay ) );
                                        break;

                                    case PMPRO_SEQ_AS_DATE:

                                        $text .= sprintf( __( 'You will be able to  access "%s" on %s', 'pmprosequence' ), get_the_title( $post_id ), $this->display_proper_delay( $delay ) );
                                        break;
                                }

                                break;

                            case 'byDate':
                                $text .= sprintf( __('You will be able to access "%s" on %s', 'pmprosequence'), get_the_title($post_id), $delay );
                                break;

                            default:

                        }

                    }
                    else {

                        // User has to sign up for one of the sequence(s)
                        if ( count( $post_sequences ) == 1 ) {

	                        $tmp = $post_sequences;
	                        $seqId = array_pop( $tmp );

                            $text = sprintf("%s<br/>", sprintf( __( "This content is only available to existing members who are already logged in. ( Reqired level: <a href='%s'>%s</a>)", 'pmprosequence' ), get_permalink( $seqId ), get_the_title( $seqId ) ) );
                        }
                        else {

                            $text = sprintf( "<p>%s</p>", __( 'This content is only available to existing members who have logged in. ( For levels:  ', 'pmprosequence' ) );
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
         * Fetches the posts associated with this sequence, then generates HTML containing the list.
         *
         * @param bool $echo -- Whether to immediately 'echo' the value or return the HTML to the calling function
         * @return bool|mixed|string -- The HTML containing the list of posts in the sequence
         *
         * @access public
         */
        public function get_post_list_as_html($echo = false) {

            $this->dbg_log("get_post_list_as_html() - Generate HTML list of posts for sequence #: {$this->sequence_id}");

            //global $current_user;
            $this->load_sequence_post();

            if ( ! empty( $this->posts ) ) {

                // TODO: Have upcoming posts be listed before or after the currently active posts (own section?) - based on sort setting

                $content = $this->create_sequence_list( true, 30, true, null, false	);

                if ( $echo ) {

                    echo $content;
                }

                return $content;
            }

            return false;
        }

        /**
         * Create a list of posts/pages/cpts that are included in the specified sequence (or all sequences, if needed)
         *
         * @param bool $highlight -- Whether to highlight the Post that is the closest to the users current membership day
         * @param int $pagesize -- The size of each page (number of posts per page)
         * @param bool $button -- Whether to display a "Available Now" button or not.
         * @param string $title -- The title of the sequence list. Default is the title of the sequence.
         * @return string -- The HTML we generated.
         */
        public function create_sequence_list( $highlight = false, $pagesize = 0, $button = false, $title = null, $scrollbox = false ) {

            global $wpdb, $current_user, $id, $post;
            $html = '';

            $savePost = $post;

            // Set a default page size.
            if ($pagesize == 0) {
                $pagesize = 30;
            }

            $this->dbg_log( "create_sequence_list() - Loading posts with pagination enabled. Expecint WP_Query result" );
            list( $seqList, $max_num_pages ) = $this->load_sequence_post( null, null, null, '=', $pagesize, true );

            // $sequence_posts = $this->posts;
            $memberDayCount = $this->get_membership_days();

            $this->dbg_log( "Sequence {$this->sequence_id} has " . count( $this->posts ) . " posts. Current user has been a member for {$memberDayCount} days" );

            if ( ! $this->has_post_access( $current_user->ID, $this->sequence_id ) ) {
                $this->dbg_log( 'No access to sequence ' . $this->sequence_id . ' for user ' . $current_user->ID );
                return '';
            }

            /* Get the ID of the post in the sequence who's delay is the closest
             *  to the members 'days since start of membership'
             */
            $closestPost = apply_filters( 'pmpro-sequence-found-closest-post', $this->find_closest_post( $current_user->ID ) );

            // Image to bring attention to the closest post item
            $closestPostImg = '<img src="' . plugins_url( '/../images/most-recent.png', __FILE__ ) . '" >';

            $listed_postCnt   = 0;

            $this->dbg_log( "create_sequence_list() - Loading posts for the sequence_list shortcode...");
            ob_start();
            ?>

            <!-- Preface the table of links with the title of the sequence -->
            <div id="pmpro_sequence-<?php echo $this->sequence_id; ?>" class="pmpro_sequence_list">

            <?php echo apply_filters( 'pmpro-sequence-list-title',  $this->set_title_in_shortcode( $title ) ); ?>

            <!-- Add opt-in to the top of the shortcode display. -->
            <?php echo $this->view_user_notice_opt_in(); ?>

            <!-- List of sequence entries (paginated as needed) -->
            <?php

            if ( count( $seqList ) == 0 ) {
            // if ( 0 == count( $this->posts ) ) {
                echo '<span style="text-align: center;">' . __( "There is <em>no content available</em> for you at this time. Please check back later.", "pmprosequence" ) . "</span>";

            } else {
                if ( $scrollbox ) { ?>
                    <div id="pmpro-seq-post-list">
                    <table class="pmpro_sequence_postscroll pmpro_seq_linklist">
                <?php } else { ?>
                    <div>
                    <table class="pmpro_seq_linklist">
                <?php };

                // Loop through all of the posts in the sequence

                // $posts = $seqList->get_posts();

                foreach( $seqList as $p ) {

                    if ( ( false === $p->is_future ) ) {
                        $this->dbg_log("create_sequence_list() - Adding post {$p->id} with delay {$p->delay}");
                        $listed_postCnt++;

                        if ( ( true === $p->closest_post ) && ( $highlight ) ) {

                            $this->dbg_log( 'create_sequence_list() - The most recently available post for user #' . $current_user->ID . ' is post #' . $p->id );

                            // Show the highlighted post info
                            ?>
                            <tr id="pmpro-seq-selected-post">
                                <td class="pmpro-seq-post-img"><?php echo apply_filters( 'pmpro-sequence-closest-post-indicator-image', $closestPostImg ); ?></td>
                                <td class="pmpro-seq-post-hl">
                                    <a href="<?php echo $p->permalink; ?>" title="<?php echo $p->title; ?>"><strong><?php echo $p->title; ?></strong>&nbsp;&nbsp;<em>(Current)</em></a>
                                </td>
                                <td <?php echo( $button ? 'class="pmpro-seq-availnow-btn"' : '' ); ?>><?php

                                    if ( $button ) {
                                        ?>
                                    <a class="pmpro_btn pmpro_btn-primary" href="<?php echo $p->permalink; ?>"> <?php _e( "Available Now", 'pmprosequence' ); ?></a><?php
                                    } ?>
                                </td>
                            </tr> <?php
                        } else {
                            ?>
                            <tr id="pmpro-seq-post">
                                <td class="pmpro-seq-post-img">&nbsp;</td>
                                <td class="pmpro-seq-post-fade">
                                    <a href="<?php echo $p->permalink; ?>" title="<?php echo $p->title; ?>"><?php echo $p->title; ?></a>
                                </td>
                                <td<?php echo( $button ? ' class="pmpro-seq-availnow-btn">' : '>' );
                                if ( $button ) {
                                    ?>
                                <a class="pmpro_btn pmpro_btn-primary" href="<?php echo $p->permalink; ?>"> <?php _e( "Available Now", 'pmprosequence' ); ?></a><?php
                                } ?>
                                </td>
                            </tr>
                        <?php
                        }
                    } elseif ( ( true == $p->is_future ) /* &&
                        ( false === $this->hide_upcoming_posts() ) */ ) {

                        $listed_postCnt++;

                        // Do we need to highlight the (not yet available) post?
                        // if ( ( $p->ID == $closestPost->id ) && ( $p->delay == $closestPost->delay ) && $highlight ) {
                        if ( ( true === $p->closest_post ) && ( $highlight ) ) {
                            ?>

                            <tr id="pmpro-seq-post">
                                <td class="pmpro-seq-post-img">&nbsp;</td>
                                <td id="pmpro-seq-post-future-hl">
                                    <?php $this->dbg_log( "Highlight post #: {$p->id} with future availability" ); ?>
                                    <span class="pmpro_sequence_item-title">
                                            <?php echo $p->title; ?>
                                        </span>
                                        <span class="pmpro_sequence_item-unavailable">
                                            <?php echo sprintf( __( 'available on %s', 'pmprosequence' ),
                                                ( $this->options->delayType == 'byDays' &&
                                                    $this->options->showDelayAs == PMPRO_SEQ_AS_DAYNO ) ?
                                                    __( 'day', 'pmprosequence' ) : '' ); ?>
                                            <?php echo $this->display_proper_delay( $p->delay ); ?>
                                        </span>
                                </td>
                                <td></td>
                            </tr>
                        <?php
                        } else {
                            ?>
                            <tr id="pmpro-seq-post">
                                <td class="pmpro-seq-post-img">&nbsp;</td>
                                <td>
                                    <span class="pmpro_sequence_item-title"><?php echo $p->post_title; ?></span>
                                        <span class="pmpro_sequence_item-unavailable">
                                            <?php echo sprintf( __( 'available on %s', 'pmprosequence' ),
                                                ( $this->options->delayType == 'byDays' &&
                                                    $this->options->showDelayAs == PMPRO_SEQ_AS_DAYNO ) ?
                                                    __( 'day', 'pmprosequence' ) : '' ); ?>
                                            <?php echo $this->display_proper_delay( $p->delay ); ?>
                                        </span>
                                </td>
                                <td></td>
                            </tr> <?php
                        }
                    } else {
                        if ( ( count( $seqList ) > 0 ) && ( $listed_postCnt > 0 ) ) {
                            ?>
                            <tr id="pmpro-seq-post">
                                <td>
                                    <span style="text-align: center;">There is <em>no content available</em> for you at this time. Please check back later.</span>
                                </td>
                            </tr><?php
                        }
                    }
                }

                ?></table>
                </div>
                <div class="clear"></div>
                <?php


                echo apply_filters( 'pmpro-sequence-list-pagination-code', $this->post_paging_nav( ceil( count( $this->posts ) / $pagesize ) ) );
                // echo apply_filters( 'pmpro-sequence-list-pagination-code', $this->post_paging_nav( $max_num_pages ) );
               // wp_reset_postdata();
            }
            ?>
            </div><?php

            $post = $savePost;

            $html .= ob_get_contents();
            ob_end_clean();

            $this->dbg_log("create_sequence_list() - Returning the - possibly filtered - HTML for the sequence_list shortcode");

            return apply_filters( 'pmpro-sequence-list-html', $html );

        }

        public function set_closest_post( $post_list ) {

            global $current_user;

            if ( !is_null( $this->pmpro_sequence_user_id )  && ( $this->pmpro_sequence_user_id != $current_user->ID ) ) {
                $user_id = $this->pmpro_sequence_user_id;
            }
            else {
                $user_id = $current_user->ID;
            }

            $closest_post = apply_filters( 'pmpro-sequence-found-closest-post', $this->find_closest_post( $user_id ) );

            foreach( $post_list as $key => $post ) {

                if ( isset( $post->id ) ) {
                    $post_id = $post->id;
                }

                if ( isset( $post->ID ) ) {
                    $post_id = $post->ID;
                }

                if ( ( $post->delay == $closest_post->delay ) && ( $post_id == $closest_post->id ) ) {

                    $this->dbg_log( "set_closest_post() - Most current post for user {$user_id} found for post id: {$post_id}" );
                    $post_list[$key]->closest_post = true;
                }
            }

            return $post_list;
        }

        public function save_user_notice_settings( $user_id, $settings, $sequence_id = null ) {

            if ( is_array( $settings->sequence ) && isset( $settings->sequence[$sequence_id] ) ) {

                $this->dbg_log("save_user_notice_settings() - Using old format for user notification settings. Need to update for v3.0", DEBUG_SEQ_INFO);

                foreach( $settings->sequence as $sId => $data ) {

                    $optIn = $this->create_user_notice_defaults();
                    $optIn->id = $sId;

                    $member_days = $this->get_membership_days( $user_id );

                    $optIn->posts = $data->notifiedPosts;

                    $this->dbg_log( "save_user_notice_settings() - Converting the sequence ( {$sId} ) post list for user notice settings" );
                    $this->load_sequence_post();

                    $new_list = array();

                    foreach( $optIn->posts as $key => $post_id ) {

                        foreach( $this->posts as $p ) {

                            if ( ( $p->id == $post_id ) && ( $p->delay <= $member_days ) ) {

                                if ( $optIn->posts[$key] == $post_id ) {
                                    $new_list[$key] = "{$post_id}_{$p->delay}";
                                }
                                else {
                                    $new_list[] = "{$post_id}_{$p->delay}";
                                }
                            }
                        }

                    }
                    $optIn->posts = $new_list;
                    $optIn->send_notices = $data->sendNotice;
                    $optIn->optin_at = $data->optinTS;
                    $optIn->last_notice_sent = $data->optinTS;

                    $this->dbg_log("save_user_notice_settings() - Saving V3 user notification opt-in settings for user {$user_id} and sequence {$sId}");
                    $this->save_user_notice_settings( $user_id, $optIn, $sId );
                }

                return true;
            }
            $this->dbg_log("$sequence_id");
            $this->dbg_log("save_user_notice_settings() - Save V3 style user notification opt-in settings to usermeta for {$user_id} and sequence {$sequence_id}");

            if ( !update_user_meta( $user_id, "pmpro_sequence_id_{$sequence_id}_notices",  $settings ) ) {

                $this->dbg_log("save_user_notice_settings() - Error saving V3 style user notification settings for user with ID {$user_id}", DEBUG_SEQ_WARNING );
                return false;
            }

            return true;
        }

        public function load_user_notice_settings( $user_id, $sequence_id = null ) {

            global $wpdb;

            if ( empty( $sequence_id ) && ( empty( $this->sequence_id ) ) ) {

                $this->dbg_log("load_user_notice_settings() - No sequence id defined. returning null", DEBUG_SEQ_WARNING);
                return null;
            }

            if ( false ==
                ( $optIn = get_user_meta( $user_id, "pmpro_sequence_id_{$sequence_id}_notices", true ) ) ) {

                $this->dbg_log("load_user_notice_settings() - No V3 settings found. Attempting to load old setting style & then convert it.", DEBUG_SEQ_WARNING );
                $optIn = get_user_meta( $user_id, $wpdb->prefix . "pmpro_sequence_notices", true );

                if ( false !== $optIn ) {

                    $this->dbg_log("load_user_notice_settings() - Found old-style notification settings for user {$user_id}. Attempting to convert", DEBUG_SEQ_WARNING );

                    if ( $this->save_user_notice_settings( $user_id, $optIn, $sequence_id ) ) {

                        $this->dbg_log("load_user_notice_settings() - Recursively loading the settings we need." );
                        $optIn = $this->load_user_notice_settings( $user_id, $sequence_id );

                        $this->dbg_log("load_user_notice_settings() - Removing converted opt-in settings from the database" );
                        delete_user_meta( $user_id, $wpdb->prefix . "pmpro_sequence_notices" );
                    }
                    else {

                        $this->dbg_log("load_user_notice_settings() - Could not convert old-style settings for user {$user_id} and sequence {$sequence_id}", DEBUG_SEQ_WARNING );
                        $optIn = $this->create_user_notice_defaults();
                        $optIn->id = $sequence_id;
                    }
                }
            }

            return $optIn;
        }

        private function create_user_notice_defaults() {

            $this->dbg_log("create_user_notice_defaults() - Loading default opt-in settings" );
            $defaults = new stdClass();

            $defaults->id = $this->sequence_id;
            $defaults->send_notices = ( $this->options->sendNotice == 1 ? true : false );
            $defaults->posts = array();
            $defaults->optin_at = current_time( 'timestamp' );
            $defaults->last_notice_sent = -1; // Never

            return $defaults;
        }

        /**
         * Adds notification opt-in to list of posts/pages in sequence.
         *
         * @return string -- The HTML containing a form (if the sequence is configured to let users receive notices)
         *
         * @access public
         */
        public function view_user_notice_opt_in( ) {

            $optinForm = '';

            global $current_user;

            // $meta_key = $wpdb->prefix . "pmpro_sequence_notices";

            $this->dbg_log('view_user_notice_opt_in() - User specific opt-in to sequence display for new content notices for user ' . $current_user->ID);

            if ($this->options->sendNotice == 1) {

                $optIn = $this->load_user_notice_settings( $current_user->ID, $this->sequence_id );
                // $optIn = get_user_meta( $current_user->ID, $meta_key, true );

                $this->dbg_log('view_user_notice_opt_in() - Fetched Meta: ' . print_r( $optIn, true));

                /* Determine the state of the users opt-in for new content notices */
                if ( !isset($optIn->id ) || ( $optIn->id !== $this->sequence_id ) ) {

                    $this->dbg_log('view_user_notice_opt_in() - No user specific settings found in general or for this sequence. Creating defaults');

                    // Create new opt-in settings for this user
                    $new = $this->create_user_notice_defaults();

	                // $new->sequence[$this->sequence_id]->sendNotice = $this->options->sendNotice;

                    $this->dbg_log('view_user_notice_opt_in() - Using default setting for user ' . $current_user->ID . ' and sequence ' . $this->sequence_id);

                    $optIn = $new;
                }

                if ( !is_array( $optIn->posts ) ) {

                    $optIn->posts = array();
                }

                $this->save_user_notice_settings( $current_user->ID, $optIn, $this->sequence_id );

                // update_user_meta($current_user->ID, $meta_key, $optIn);

                $noticeVal = isset( $optIn->send_notice ) ? $optIn->send_notice : 0;

                /* Add form information */
                ob_start();
                ?>
                <div class="pmpro-seq-centered">
                    <div class="pmpro-sequence-hidden pmpro_sequence_useroptin">
                        <div class="seq_spinner"></div>
                        <form class="pmpro-sequence" action="<?php echo admin_url('admin-ajax.php'); ?>" method="post">
                            <input type="hidden" name="hidden_pmpro_seq_useroptin" id="hidden_pmpro_seq_useroptin" value="<?php echo $noticeVal; ?>" >
                            <input type="hidden" name="hidden_pmpro_seq_id" id="hidden_pmpro_seq_id" value="<?php echo $this->sequence_id; ?>" >
                            <input type="hidden" name="hidden_pmpro_seq_uid" id="hidden_pmpro_seq_uid" value="<?php echo $current_user->ID; ?>" >
                            <?php wp_nonce_field('pmpro-sequence-user-optin', 'pmpro_sequence_optin_nonce'); ?>
                            <span>
                                <input type="checkbox" value="1" id="pmpro_sequence_useroptin" name="pmpro_sequence_useroptin" onclick="javascript:pmpro_sequence_optinSelect(); return false;" title="<?php _e('Please email me an alert when any new content in this sequence becomes available', 'pmprosequence'); ?>" <?php echo ($noticeVal == 1 ? ' checked="checked"' : ''); ?> " />
                                <label for="pmpro-seq-useroptin"><?php _e('Yes, please send me email alerts!', 'pmprosequence'); ?></label>
                            </span>
                        </form>
                    </div>
                </div>

                <?php
                $optinForm .= ob_get_clean();
            }

            return $optinForm;
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
            // $this->dbg_log('hide_upcoming_posts(): Do we show or hide upcoming posts?');
            return ( $this->options->hidden == 1 ? true : false );
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

            if ( $msg !== null ) {

                $this->dbg_log("set_error_msg(): {$msg}");
                add_settings_error( 'pmpro_seq_errors', '', $msg, 'error' );
            }
            else{

            }
        }

        /**
         * Display the error message (if it's defined).
         */
        public function display_error() {

            $this->dbg_log("Display error messages, if there are any");
            global $current_screen;

            $msg = $this->get_error_msg();

            if ( ! empty( $msg ) ){
                $this->dbg_log("Display error for Drip Feed operation(s)");
                ?><div id="pmpro-seq-error" class="error"><?php settings_errors('pmpro_seq_errors'); ?></div><?php
            }
        }

        /************************************ Private UI Functions ***********************************************/

        /**
         * List the available date formats to select from.
         *
         * key = valid dateformat
         * value = dateformat example.
         *
         * @param $settings -- Settings for the sequence
         * @return bool| mixed - HTML containing the Option list
         *
         * @access private
         */
        private function list_date_formats() {

            ob_start();

            $formats = array(
                "l, F jS, Y" => "Sunday January 25th, 2014",
                "l, F jS," => "Sunday, January 25th,",
                "l \\t\\h\\e jS" => "Sunday the 25th",
                "M. js, " => "Jan. 24th",
                "M. js, Y" => "Jan. 24th, 2014",
                "M. js, 'y" => "Jan. 24th, '14",
                "m-d-Y" => "01-25-2014",
                "m/d/Y" => "01/25/2014",
                "m-d-y" => "01-25-14",
                "m/d/y" => "01/25/14",
                "d-m-Y" => "25-01-2014",
                "d/m/Y" => "25/01/2014",
                "d-m-y" => "25-01-14",
                "d/m/y" => "25/01/14",
            );

            foreach ( $formats as $key => $val)
            {
                echo('<option value="' . esc_attr($key) . '" ' . selected( esc_attr( $this->options->dateformat), esc_attr($key) ) . ' >' . esc_attr($val) .'</option>');
            }

            $selectList = ob_get_clean();

            return $selectList;
        }

        /**
         * Create list of options for time.
         *
         * @param $settings -- (array) Sequence specific settings
         * @return bool| mixed - HTML containing the Option list
         *
         * @access private
         */
        private function load_time_options( )
        {

            $prepend    = array('00','01','02','03','04','05','06','07','08','09');
            $hours      = array_merge($prepend,range(10, 23));
            $minutes     = array('00', '30');

            // $prepend_mins    = array('00','30');
            // $minutes    = array_merge($prepend_mins, range(10, 55, 5)); // For debug
            // $selTime = preg_split('/\:/', $settings->noticeTime);

            ob_start();

            foreach ($hours as $hour) {
                foreach ($minutes as $minute) {
                    ?>
                    <option value="<?php echo( $hour . ':' . $minute ); ?>"<?php selected( $this->options->noticeTime, $hour . ':' . $minute ); ?> ><?php echo( $hour . ':' . $minute ); ?></option>
                <?php
                }
            }

            $selectList = ob_get_clean();

            return $selectList;
        }

        /**
         * List all template files in email directory for this plugin.
         *
         * @param $settings (stdClass) - The settings for the sequence.
         * @return bool| mixed - HTML containing the Option list
         *
         * @access private
         */
        private function get_email_templates()
        {
            ob_start();

            ?>
            <!-- Default template (blank) -->
            <option value=""></option>
            <?php

            // $this->dbg_log('Directory containing templates: ' . PMPRO_SEQUENCE_PLUGIN_DIR . "/email/" );

            $templ_dir = apply_filters( 'pmpro-sequence-email-alert-template-path', PMPRO_SEQUENCE_PLUGIN_DIR . "/email/" );

            $this->dbg_log( "Directory containing templates: {$templ_dir}");

            chdir($templ_dir);

            foreach ( glob('*.html') as $file) {

                echo('<option value="' . sanitize_file_name($file) . '" ' . selected( esc_attr( $this->options->noticeTemplate), sanitize_file_name($file) ) . ' >' . sanitize_file_name($file) .'</option>');
            }

            $selectList = ob_get_clean();

            return $selectList;
        }

        /**
         * Used to label the post list in the metabox
         *
         * @param $post_state -- The current post state (Draft, Scheduled, Under Review, Private, other)
         * @return null|string -- Return the correct postfix for the post
         *
         * @access private
         */
        private function set_post_status( $post_state )
        {
            $txtState = null;

            switch ($post_state)
            {
                case 'draft':
                    $txtState = __('-DRAFT', 'pmprosequence');
                    break;

                case 'future':
                    $txtState = __('-SCHED', 'pmprosequence');
                    break;

                case 'pending':
                    $txtState = __('-REVIEW', 'pmprosequence');
                    break;

                case 'private':
                    $txtState = __('-PRIVT', 'pmprosequence');
                    break;

                default:
                    $txtState = '';
            }

            return $txtState;
        }

        /**
         * @param $total -- Total number of posts to paginate
         *
         * @return string -- Pagination HTML
         */
        private function post_paging_nav( $total ) {

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
                    <h4 class="screen-reader-text"><?php _e( 'Navigation', 'pmprosequence' ); ?></h4>
                    <?php echo paginate_links( array(
                        'base'          => $base,
                        'format'        => $format,
                        'total'         => $total,
                        'current'       => $paged,
                        'mid_size'      => 2,
                        'prev_text'     => sprintf( __( '%s Previous', 'pmprosequence'), $prev_arrow),
                        'next_text'     => sprintf( __( 'Next %s', 'pmprosequence'), $next_arrow),
                        'prev_next'     => true,
                        'type'          => 'list',
                        'before_page_number' => '<span class="screen-reader-text">' . __('Page', 'pmprosequence') . '</span>',
                    )); ?>
                </nav>
                <?php
                $html =  ob_get_clean();
            }

            return $html;
        }

        /**
         * Formats the title (unless its empty, then we set it to the post title for the current sequence)
         *
         * @param string|null $title -- A string (title) to apply formatting to & return
         *
         * @return null|string - The title string
         */
        private function set_title_in_shortcode( $title = null ) {

            // Process the title attribute (default values, can apply filter if needed/wanted)
            if ( ( $title == '' ) && ( $this->sequence_id != 0 ) ) {

                $title = '<h3>' . get_the_title( $this->sequence_id ) . '</h3>';
            }
            elseif ( ( $this->sequence_id == 0 ) && ( $title == '' ) ) {

                $title = "<h3>" . _e("Available posts", "pmprosequence") . "</h3>";
            }
            elseif ( $title == '' ) {

                $title = '';
            }
            else {

                $title = "<h3>{$title}</h3>";
            }

            return $title;
        }

        /********************************* Sequence functionality ****************************/

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

			    $this->dbg_log("is_after_delay() - the previewOffset value doesn't exist yet {$this->options->previewOffset}. Fixing now.");
			    $this->options->previewOffset = 0;
			    $this->save_sequence_meta(); // Save the settings (only the first when this variable is empty)

		    }

	        $offset = $this->options->previewOffset;
		    // $this->dbg_log('is_after_delay() - Preview enabled and set to: ' . $offset);

		    if ( $this->is_valid_date( $delay ) ) {
                // Fixed: Now supports DST changes (i.e. no "early or late availability" in DST timezones
	            // $now = current_time('timestamp') + ($offset * 86400);
                $now = $this->get_now_and_offset( $offset );

	            // TODO: Add support for startWhen options (once the plugin supports differentiating on when the drip starts)
	            $delayTime = strtotime( $delay . ' 00:00:00.0 ' . get_option( 'timezone_string' ) );
	            // $this->dbg_log('is_after_delay() - Now = ' . $now . ' and delay time = ' . $delayTime );

	            return ( $now >= $delayTime ? true : false ); // a date specified as the $delay
	        }

	        return ( ($memberFor + $offset) >= $delay ? true : false );
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

                    $this->dbg_log( 'validate_delay_value(): Delay value is recognizable' );

                    if ( $this->is_valid_date( $delay ) ) {

                        $this->dbg_log( 'validate_delay_value(): Delay specified as a valid date format' );

                    } else {

                        $this->dbg_log( 'validate_delay_value(): Delay specified as the number of days' );
                    }
                } else {
                    // Ignore this post & return error message to display for the user/admin
                    // NOTE: Format of date is not translatable
                    $expectedDelay = ( $this->options->delayType == 'byDate' ) ? __( 'date: YYYY-MM-DD', 'pmprosequence' ) : __( 'number: Days since membership started', 'pmprosequence' );

                    $this->dbg_log( 'validate_delay_value(): Invalid delay value specified, not adding the post. Delay is: ' . $delay );
                    $this->set_error_msg( sprintf( __( 'Invalid delay specified ( %1$s ). Expected format is a %2$s', 'pmprosequence' ), $delay, $expectedDelay ) );

                    $delay = false;
                }
            } elseif ($delay === 0) {

                // Special case:
                return $delay;

            } else {

                $this->dbg_log( 'validate_delay_value(): Delay value was not specified. Not adding the post. Delay is: ' . esc_attr( $delay ) );

                if ( empty( $delay ) ) {

                    $this->set_error_msg( __( 'No delay has been specified', 'pmprosequence' ) );
                }
            }

            return $delay;
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

            if ( $this->options->showDelayAs == PMPRO_SEQ_AS_DATE) {
                // Convert the delay to a date

                $memberDays = round(pmpro_getMemberDays(), 0);

                $delayDiff = ($delay - $memberDays);
	            $this->dbg_log('display_proper_delay() - Delay: ' .$delay . ', memberDays: ' . $memberDays . ', delayDiff: ' . $delayDiff);

                return strftime('%x', strtotime("+" . $delayDiff ." days"));
            }

            return $delay; // It's stored as a number, not a date

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

	        // Get the current day of the membership (as a whole day, not a float)
            $membership_day =  $this->get_membership_days( $user_id );

            // Load all posts in this sequence
            $this->load_sequence_post();

            $this->dbg_log("find_closest_post() - Found " . count($this->posts) . " posts in sequence.");

            // Find the post ID in the postList array that has the delay closest to the $membership_day.
            $closest = $this->find_closest_post_by_delay_val( $membership_day, $user_id );

            if ( isset( $closest->id ) ) {

                $this->dbg_log("find_closest_post() - For user {$user_id} on day {$membership_day}, the closest post is #{$closest->id} (with a delay value of {$closest->delay})");
                return $closest;
            }

			return null;
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
        public function get_delay_for_post($post_id, $normalize = true ) {

			$posts = $this->find_by_id( $post_id );

            foreach( $posts as $k => $post ) {

                // BUG: Would return "days since membership start" as the delay value, regardless of setting.
                // Fix: Choose whether to normalize (but leave default as "yes" to ensure no unintentional breakage).
                if ( true === $normalize ) {

                    $posts[$k]->delay = $this->normalize_delay( $post->delay );
                }

                $this->dbg_log("get_delay_for_post(): Delay for post with id = {$post_id} is {$posts[$k]->delay}");
            }

            return ( empty( $posts ) ? array() : $posts );
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

            if ( $this->is_valid_date($delay) ) {

                return $this->convert_date_to_days($delay);
            }

            return $delay;
        }

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

            if ( null === $userId ) {

                $userId = $this->pmpro_sequence_user_id;
            }

            if ( null === $levelId ) {

                $levelId = $this->pmpro_sequence_user_level;
            }

            // Return immediately if the value we're given is a # of days (i.e. an integer)
            if ( is_numeric( $date ) ) {
                $days = $date;
            }

            if ( $this->is_valid_date( $date ) )
            {
                $startDate = pmpro_getMemberStartdate( $userId, $levelId); /* Needs userID & Level ID ... */
                // $this->dbg_log("convert_date_to_days() - Given date: {$date} and startdate: {$startDate} for user {$userId} for level {$levelId}");

                if (empty($startDate)) {
                    $startDate = 0;
                }

                try {

                    // Use PHP v5.2 and v5.3 compatible function to calculate difference
                    $compDate = strtotime( $date );
                    $days = $this->seq_datediff( $startDate, $compDate ); // current_time('timestamp')

                } catch (Exception $e) {
                    $this->dbg_log('convert_date_to_days() - Error calculating days: ' . $e->getMessage());
                }
            }

            return $days;
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

            if(empty($user_id))
            {
                global $current_user;
                $user_id = $current_user->ID;
            }

            global $pmpro_member_days;

            if ( empty( $pmpro_member_days[$user_id][$level_id] ) ) {

                $startdate = pmpro_getMemberStartdate( $user_id, $level_id );

                //check that there was a startdate at all
                if( empty( $startdate ) ) {

                    $pmpro_member_days[$user_id][$level_id] = 0;
                }
                else {

                    $now = current_time("timestamp");

                    // $days = round( abs( $now - $startdate ) / ( 60*60*24 ) ) + 1;
                    $days = $this->seq_datediff( $startdate, $now );

                    $pmpro_member_days[$user_id][$level_id] = $days;
                }
            }

            return $pmpro_member_days[$user_id][$level_id];
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

            $now = new DateTime( 'now', new DateTimeZone( 'UTC' ) );

            if ( $days > 1) {
                $dayStr = "{$days} days";
            }
            else {
                $dayStr = "{$days} day";
            }

            $now->modify( $dayStr );

            $now->setTimezone( new DateTimeZone( $serverTZ ) );
            $seconds = $now->format( 'U' );

            $this->dbg_log("calculateOffsetSecs() - Offset Days: {$days} = When (in seconds): {$seconds}", DEBUG_SEQ_INFO );
            return $seconds;
        }

        /**
         * Calculates the difference between two dates (specified in UTC seconds)
         *
         * @param $startdate (timestamp) - timestamp value for start date
         * @param $enddate (timestamp) - timestamp value for end date
         * @return int
         */
        private function seq_datediff( $startdate, $enddate = null, $tz = 'UTC' ) {

            $days = 0;

            $this->dbg_log("seq_datediff() - Timezone: {$tz}");

            // use current day as $enddate if nothing is specified
            if ( ( is_null( $enddate ) ) && ( $tz == 'UTC') ) {

                $enddate = current_time( 'timestamp', true );
            }
            elseif ( is_null( $enddate ) ) {

                $enddate = current_time( 'timestamp' );
            }

            // Create two DateTime objects
            $dStart = new DateTime( date( 'Y-m-d', $startdate ), new DateTimeZone( $tz ) );
            $dEnd   = new DateTime( date( 'Y-m-d', $enddate ), new DateTimeZone( $tz ) );

            if ( version_compare( PHP_VERSION, PMPRO_SEQ_REQUIRED_PHP_VERSION, '>=' ) ) {

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
         * Filter pmpro_has_membership_access based on sequence access.
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

            if ( $hasaccess ) {

                if ( isset( $user->ID ) && isset( $post->ID ) && $this->has_post_access( $user->ID, $post->ID ) ) {

                    $hasaccess = true;
                }
                else {
                    $hasaccess = false;
                }
            }

            return apply_filters( 'pmpro-sequence-has-access-filter', $hasaccess, $post, $user, $levels );
        }

        public function has_sequence_access( $user_id, $sequence_id = null ) {

            if (is_null( $sequence_id ) && empty( $this->sequence_id ) ) {
                return true;
            }

            if ( ( !empty( $sequecne_id ) ) && ( 'pmpro_sequence' != get_post_type( $sequence_id ) ) ){

                // Not a PMPRO Sequence CPT post_id
                return true;
            }

            $results = pmpro_has_membership_access( $sequence_id, $user_id, true );

            if ( $results[0] ) {
                return true;
            }

            return false;
        }

        /**
         * Check the whether the User ID has access to the post ID
         * Make sure people can't view content they don't have access to.
         *
         * @param $user_id (int) -- The users ID to check access for
         * @param $post (int|stdClass) -- The ID of the post we're checking access for
         * @param $isAlert (bool) - If true, ignore any preview value settings when calculating access
         *
         * @return bool -- true | false -- Indicates user ID's access privileges to the post/sequence
         */
/*        public function old_has_post_access( $user_id, $post, $isAlert = false, $sequence_id = null ) {

            $hasaccess = false;

            if ( isset( $post->delay ) ) {

                $post_id = $post->id;
            }
            elseif ( is_numeric( $post ) ) {

                $post_id = $post;
                $posts = $this->load_sequence_post( null, null, $post_id );
                $this->dbg_log($posts);
                return false;
            }

            // Does the current user have a membership level giving them access to everything?
            $all_access_levels = apply_filters("pmproap_all_access_levels", array(), $user_id, $post_id );

            if ( ! empty( $all_access_levels ) && pmpro_hasMembershipLevel( $all_access_levels, $user_id ) ) {

                $this->dbg_log("has_post_access() - This user ({$user_id}) has one of the 'all access' membership levels");
                return true; //user has one of the all access levels
            }

            if ( $user_id !== $this->pmpro_sequence_user_id ) {
                $this->pmpro_sequence_user_id = $user_id;
            }

            if ( is_null( $sequence_id ) ) {
                $post_sequences = $this->get_sequences_for_post( $post_id );

                $this->dbg_log("has_post_access() - Found " . count( $post_sequences ) . " sequences where post {$post_id} is a member");

            // $post_sequence = get_post_meta( $post_id, "_post_sequences", true );

                if ( empty( $post_sequences ) ) {
                    // $this->dbg_log( "has_post_access() with empty post_sequence: " . $this->who_called_me() );
                    $this->dbg_log( "has_post_access() - No sequences manage this post {$post_id} for user {$user_id} so granting access (seq: {$this->sequence_id}): " . print_r( $post_sequences, true ) );
                    return true;
                }
            }

            $this->dbg_log("has_post_access() - The post has sequences that manage it. Continue to see if we have access. Current sequence_id setting: {$this->sequence_id}");


            $this->dbg_log( "has_post_access(): Sequence ID was NOT set by calling function: " . $this->who_called_me() );

            foreach ( $post_sequences as $seqId ) {

                if ( ( get_post_type( $seqId ) != 'pmpro_sequence') ||
                     ( FALSE === get_post_status( $seqId ) ) ) {

                    return true;
                }

                $this->dbg_log( "has_post_access(): Loading sequence #{$seqId}" );
                $this->init( $seqId );
                $hasaccess = ( $this->has_sequence_access( $user_id, $seqId ) && $this->has_access( $post, $user_id, $post_sequences, $isAlert ) );
            }

            return $hasaccess;
        } // End of function
*/

        public function has_post_access( $user_id, $post_id, $isAlert = false ) {

            $sequences = $this->get_sequences_for_post( $post_id );

            $sequence_list = array_unique( $sequences );

            if ( count( $sequence_list ) < count( $sequences ) ) {

            $this->dbg_log("has_post_access() - Saving the pruned array of sequences");

                $this->set_sequences_for_post( $post_id, $sequence_list );
            }

            if ( empty( $sequences ) ) {

                return true;
            }

            // Does the current user have a membership level giving them access to everything?
            $all_access_levels = apply_filters("pmproap_all_access_levels", array(), $user_id, $post_id );

            if ( ! empty( $all_access_levels ) && pmpro_hasMembershipLevel( $all_access_levels, $user_id ) ) {

                $this->dbg_log("has_post_access() - This user ({$user_id}) has one of the 'all access' membership levels");
                return true; //user has one of the all access levels
            }

            if ( is_admin() && ( false == $this->is_cron ) ) {
                $this->dbg_log("has_post_access() - User is in admin panel. Allow access to the post");
                return true;
            }

            foreach( $sequence_list as $sequence_id ) {

                if ( $this->sequence_id != $sequence_id ) {

                    $this->dbg_log( "has_post_access(): Loading sequence #{$sequence_id}" );
                    $this->get_options( $sequence_id );
                    // $this->load_sequence_post( $sequence_id, null, $post_id );
                }

                $allowed_post_statuses = apply_filters( 'pmpro-sequence-allowed-post-statuses', array( 'publish', 'future', 'private' ) );
                $curr_post_status = get_post_status( $post_id );

                // Only consider granting access to the post if it is in one of the allowed statuses
                if ( ! in_array( $curr_post_status, $allowed_post_statuses ) ) {

                    $this->dbg_log("has_post_access() - Post {$post_id} with status {$curr_post_status} isn't accessible", DEBUG_SEQ_WARNING );
                    return false;
                }

                $access = pmpro_has_membership_access( $this->sequence_id, $user_id, true );
                $this->dbg_log("has_post_access() - Checking sequence access for membership level {$this->sequence_id}");
                $this->dbg_log($access);

                // $usersLevels = pmpro_getMembershipLevelsForUser( $user_id );

                if ( $access[0] ) {

                    $s_posts = $this->find_by_id( $post_id );

                    if ( !empty( $s_posts ) ) {

                        $this->dbg_log("has_post_access() - Found " . count( $s_posts ) . " post(s) in sequence {$this->sequence_id} with post ID of {$post_id}");

                        foreach( $s_posts as $post ) {

                            $this->dbg_log("has_post_access() - UserID: {$user_id}, post: {$post->id}, delay: {$post->delay}, Alert: {$isAlert} for sequence: {$this->sequence_id} - sequence_list: " .print_r( $sequence_list, true));

                            if ( $post->id == $post_id ) {

                                foreach( $access[1] as $level_id ) {

                                    $this->dbg_log("has_post_access() - Processing for membership level ID {$level_id}");

                                    if ( $this->options->delayType == 'byDays' ) {
                                        $this->dbg_log("has_post_access() - Sequence {$this->sequence_id} is configured to store sequence by days since startdate");

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
                                        $offset = apply_filters( 'pmpro-sequence-add-startdate-offset', __return_zero(), $this->sequence_id );

                                        $durationOfMembership += $offset;

                                        if ( $post->delay <= $durationOfMembership ) {

                                            // Set users membership Level
                                            $this->pmpro_sequence_user_level = $level_id;
                                            // $this->dbg_log("has_post_access() - using byDays as the delay type, this user is given access to post ID {$post_id}.");
                                            return true;
                                        }
                                    }
                                    elseif ( $this->options->delayType == 'byDate' ) {
                                        $this->dbg_log("has_post_access() - Sequence {$this->sequence_id} is configured to store sequence by dates");
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
                                        $offset = apply_filters( 'pmpro-sequence-add-startdate-offset', __return_zero(), $this->sequence_id );

                                        $timestamp = ( current_time( 'timestamp' ) + $previewAdd + ( $offset * 60*60*24 ) );

                                        $today = date( __( 'Y-m-d', 'pmprosequence' ), $timestamp );

                                        if ( $post->delay <= $today ) {

                                            $this->pmpro_sequence_user_level = $level_id;
                                            // $this->dbg_log("has_post_access() - using byDate as the delay type, this user is given access to post ID {$post_id}.");
                                            return true;
                                        }
                                    } // EndIf for delayType
                                }
                            }
                        }
                    }
                }
            }

            $this->dbg_log("has_post_access() - NO access granted to post {$post_id} for user {$user_id}");
            return false;
        }
        /**
          *  Check whether to permit a given Post ID to have multiple entries and as a result delay values.
          *
          * @return bool - Depends on the setting.
          * @access private
          * @since 2.4.11
          */
        private function allow_repetition() {

            return $this->options->allowRepeatPosts;
        }

        /************************************ Private Sequence Functions ***********************************************/

        private function find_single_post( $post_id, $delay ) {

            if ( empty( $this->posts ) ) {
                $this->load_sequence_post();
            }

            $this->dbg_log("find_single_post() - Find post {$post_id}");

            foreach( $this->posts as $key => $post ) {

                if ( ( $post_id == $post->id ) && ( $delay == $post->delay ) ) {
                    return $post;
                }
            }

            return false;
        }

        /**
         * Returns key (array key) of the post if it's included in this sequence.
         *
         * @param $post_id (int) -- Page/post ID to check for inclusion in this sequence.
         * @param $delay (int | null) - delay value to search for.
         *
         * @return bool|int -- Key of post in $this->posts array if the post is already included in the sequence. False otherwise
         *
         * @access private
         */
         /*
        private function hasPost( $post_id, $delay = null ) {

            $this->load_sequence_post();
            $this->dbg_log("hasPost() - Locate post {$post_id} " . ( is_null($delay) ? "with no delay given" : " with delay of {$delay}") );

            $retval = false;

            $posts = $this->find_single_post( $post_id, $delay );

            switch( count( $posts ) ) {

                case 0:
                    $this->dbg_log("hasPost() - No posts found with post_id ({$post_id}) and delay value ({$delay}", DEBUG_SEQ_INFO );
                    $retval = false;
                    break;

                case 1:
                    $this->dbg_log("hasPost() - Found a single post ( {$posts->id} )");
                    $this->dbg_log($posts);
                    $retval = $posts;
                    break;

                default:

                    $this->dbg_log("hasPost() - Found multiple posts in sequence... Looking for the one with the same delay value");
                    foreach ( $posts as $post ) {

                        if ( !is_null( $delay ) && ( $delay == $post->delay ) ) {
                            $retval = $post;
                        }
                        elseif (is_null( $delay ) ) {

                            $retval = $posts;
                            break;
                        }
                    }
            }

            return $retval;
        }
        */
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
            $this->dbg_log( "is_valid_delay(): Delay value is: {$delay} for setting: {$this->options->delayType}" );

            switch ($this->options->delayType)
            {
                case 'byDays':
                    $this->dbg_log('is_valid_delay(): Delay configured as "days since membership start"');
                    return ( is_numeric( $delay ) ? true : false);
                    break;

                case 'byDate':
                    $this->dbg_log('is_valid_delay(): Delay configured as a date value');
                    return ( apply_filters( 'pmpro-sequence-check-valid-date', $this->is_valid_date( $delay ) ) ? true : false);
                    break;

                default:
                    $this->dbg_log('is_valid_delay(): NOT a valid delay value, based on config');
                    $this->dbg_log("is_valid_delay() - options Array: " . print_r( $this->options, true ) );
                    return false;
            }
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

/**
         * Sort the two post objects (order them) according to the defined sortOrder
         *
         * @return int | bool - The usort() return value
         *
         * @access private
         */
        private function sort_by_delay() {

            if ( empty( $this->options->sortOrder ) ) {

                $this->dbg_log('sort_by_delay(): Need sortOrder option to base sorting decision on...');
                // $sequence = $this->get_sequence_by_id($a->id);
                if ( $this->sequence_id !== null) {

                    $this->dbg_log('sort_by_delay(): Have valid sequence post ID saved: ' . $this->sequence_id);
                    $this->get_options( $this->sequence_id );
                }
            }

            switch ($this->options->sortOrder) {

                case SORT_DESC:
                    $this->dbg_log('sort_by_delay(): Sorted in Descending order');
                    krsort( $this->posts, SORT_NUMERIC );
                    break;
                default:
                    $this->dbg_log('sort_by_delay(): undefined or ascending sort order');
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

            if (empty($this->options->sortOrder)) {

                $this->dbg_log('sortByDelay(): Need sortOrder option to base sorting decision on...');
                // $sequence = $this->get_sequence_by_id($a->id);

                if ( $this->sequence_id !== null) {

                    $this->dbg_log('sortByDelay(): Have valid sequence post ID saved: ' . $this->sequence_id);
                    $this->get_options( $this->sequence_id );
                }
            }

            switch ($this->options->sortOrder) {

                case SORT_ASC:
                    //$this->dbg_log('sortByDelay(): Sorted in Ascending order');
                    return $this->sort_ascending($a, $b);
                    break;

                case SORT_DESC:
                    //$this->dbg_log('sortByDelay(): Sorted in Descending order');
                    return $this->sort_descending($a, $b);
                    break;

                default:
                    $this->dbg_log('sort_by_delay(): sortOrder not defined');
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
        private function sort_ascending($a, $b)
        {
            list($aDelay, $bDelay) = $this->normalize_delay_values($a, $b);
            // $this->dbg_log('sort_ascending() - Delays have been normalized');

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

        /**
         * Get all posts with status 'published', 'draft', 'scheduled', 'pending review' or 'private' from the DB
         *
         * @return array | bool -- All posts of the post_types defined in the pmpro_sequencepost_types filter)
         *
         * @access private
         */
        private function get_posts_from_db() {

            global $wpdb;

            $post_types = apply_filters("pmpro-sequence-managed-post-types", array("post", "page") );
            $status = apply_filters( "pmpro-sequence-can-add-post-status", array('publish', 'draft', 'future', 'pending', 'private') );

            $sql = "
					SELECT ID, post_title, post_status
					FROM {$wpdb->posts}
					WHERE post_status IN ('" .implode( "', '", $status ). "')
					AND post_type IN ('" .implode( "', '", $post_types ). "')
					AND post_title <> ''
					ORDER BY post_title
				";

            if ( NULL !== ($all_posts = $wpdb->get_results($sql)) )
                return $all_posts;
            else
                return false;
        }

        private function get_users_of_sequence() {

            global $wpdb;

            // Find all users that are active members of this sequence.
            $sql = $wpdb->prepare(
                "
				    SELECT *
					FROM {$wpdb->pmpro_memberships_pages} AS pages
						INNER JOIN {$wpdb->pmpro_memberships_users} AS users
						ON (users.membership_id = pages.membership_id)
					WHERE page_id = %d AND status = %s
				",
                $this->sequence_id,
                "active"
            );

            $users = $wpdb->get_results( $sql );

            $this->dbg_log("get_users_of_sequence() - Fetched " . count($users) . " user records for {$this->sequence_id}");
            return $users;
        }

        public function convert_user_notifications() {

            global $wpdb;

            // Load all sequences from the DB
            $query = array(
                'post_type' => 'pmpro_sequence',
                'posts_per_page' => -1
            );

            $sequence_list = new WP_Query( $query );

            $this->dbg_log( "convert_user_notification() - Found " . count($sequence_list) . " sequences to process for alert conversion" );

            while ( $sequence_list->have_posts() ) {

                $sequence_list->the_post();
                $this->get_options( get_the_ID() );

                $users = $this->get_users_of_sequence();

                foreach ( $users as $user ) {

                    $this->dbg_log( "convert_user_notification() - Converting notification settings for user with ID: {$user->user_id}" );

                    // $userSettings = get_user_meta( $user->user_id, $wpdb->prefix . 'pmpro_sequence_notices', true );
                    $userSettings = $this->load_user_notice_settings( $user->user_id, $this->sequence_id );

                    if ( ( count( $userSettings->posts ) != 0 ) && ( !isset( $userSettings->completed ) ) ) {

                        $this->dbg_log("convert_user_notification() - Notification settings exist for user {$user->user_id} and sequence {$this->sequence_id} has not been converted to new format");

                        $notified = $userSettings->posts;

                        foreach( $notified as $key => $post_id ) {

                            $this->dbg_log("convert_user_notification() - Load post data for {$key}/{$post_id} in sequence {$this->sequence_id}");

                            $post = $this->find_by_id( $post_id );

                            if ( false !== $post ) {

                                // $data = $this->posts[$pKey];
                                $this->dbg_log("convert_user_notification() - Changing notifiedPosts data from {$post_id} to '{$post->id}_{$post->delay}'");
                                $userSettings->posts[$key] = "{$post->id}_{$post->delay}";
                            }
                            else {
                                $this->dbg_log("convert_user_notification() - Couldn't find sequence details for post {$post->id}");
                            }
                        }

                        $userSettings->completed = true;
                        $this->dbg_log( "convert_user_notification() - Saving new notification settings for user with ID: {$user->user_id}" );

                        if ( !$this->save_user_notice_settings( $user->user_id, $userSettings, $this->sequence_id ) ) {

                            $this->dbg_log("convertNotification() - Unable to save new notification settings for user with ID {$user->user_id}", DEBUG_SEQ_WARNING );
                        }
                        // update_user_meta( $user->user_id, $wpdb->prefix . 'pmpro_sequence_notices', $userSettings );
                    }
                }
			}

            wp_reset_postdata();
        }

        /**
         * Function will remove the flag indicating that the user has been notified already for this post.
         * Searches through all active User IDs with the same level as the Sequence requires.
         *
         * @param $post_id - The ID of the post to search through the active member list for
         *
         * @access private
         */
        private function remove_post_notified_flag( $post_id, $delay ) {

            global $wpdb;

            $this->dbg_log('remove_post_notified_flag() - Preparing SQL. Using sequence ID: ' . $this->sequence_id);

            // Find all users that are active members of this sequence.
            $users = $this->get_users_of_sequence();

            foreach ( $users as $user ) {

                $this->dbg_log( "remove_post_notified_flag() - Searching for Post ID {$post_id} in notification settings for user with ID: {$user->user_id}" );

                // $userSettings = get_user_meta( $user->user_id, $wpdb->prefix . 'pmpro_sequence_notices', true );
                $userSettings = $this->load_user_notice_settings( $user->user_id, $this->sequence_id );

                isset( $userSettings->id ) && $userSettings->id == $this->sequence_id ? $this->dbg_log("Notification settings exist for {$this->sequence_id}") : $this->dbg_log('No notification settings found');

                $notifiedPosts = isset( $userSettings->posts ) ? $userSettings->posts : array();

                if ( is_array( $notifiedPosts ) &&
                    ($key = array_search( "{$post_id}_{$delay}", $notifiedPosts ) ) !== false ) {

                    $this->dbg_log( "remove_post_notified_flag() - Found post # {$post_id} in the notification settings for user_id {$user->user_id} with key: {$key}" );
                    $this->dbg_log( "remove_post_notified_flag() - Found in settings: {$userSettings->posts[ $key ]}");
                    unset( $userSettings->posts[ $key ] );

                    if ( $this->save_user_notice_settings( $user->user_id, $userSettings, $this->sequence_id ) ) {

                        // update_user_meta( $user->user_id, $wpdb->prefix . 'pmpro_sequence_notices', $userSettings );
                        $this->dbg_log( "remove_post_notified_flag() - Deleted post # {$post_id} in the notification settings for user with id {$user->user_id}", DEBUG_SEQ_INFO );
                    }
                    else {
                        $this->dbg_log( "remove_post_notified_flag() - Unable to remove post # {$post_id} in the notification settings for user with id {$user->user_id}", DEBUG_SEQ_WARNING );
                    }
                }
            }
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

                $user_id = $this->pmpro_sequence_user_id;
            }

            $distances = array();

            // $this->dbg_log( $postArr );

            foreach ( $this->posts as $key => $post ) {

                // Only interested in posts we actually have access to.

//                 if ( $this->has_post_access( $user_id, $post, true ) ) {

                    $nDelay = $this->normalize_delay( $post->delay );
                    // $this->dbg_log("find_closest_post_by_delay_val() - Normalized delay value: {$nDelay}");

                    $distances[ $key ] = abs( $delayComp - ( $nDelay /* + 1 */ ) );
//                }
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

            $startTS = pmpro_getMemberStartdate($user_id, $level_id);

            switch ($this->options->delayType) {
                case 'byDays':
                    $delayTS = strtotime( '+' . $delay . ' days', $startTS);
                    $this->dbg_log('delay_as_timestamp() -  byDays:: delay = ' . $delay . ', delayTS is now: ' . $delayTS . ' = ' . date('Y-m-d', $startTS) . ' vs ' . date('Y-m-d', $delayTS));
                    break;

                case 'byDate':
                    $delayTS = strtotime( $delay );
                    $this->dbg_log('delay_as_timestamp() -  byDate:: delay = ' . $delay . ', delayTS is now: ' . $delayTS . ' = ' . date('Y-m-d', $startTS) . ' vs ' . date('Y-m-d', $delayTS));
                    break;
            }

            return $delayTS;
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
            $timestamp = current_time('timestamp');

            try {
                /* current time & date */
                $schedHour = date_i18n( 'H', strtotime($timeString));
                $schedMin = date_i18n('i', strtotime($timeString));

                $nowHour = date_i18n('H', $timestamp);
                $nowMin = date_i18n('i', $timestamp);

                $this->dbg_log('calculate_timestamp() - Timestring: ' . $timeString . ', scheduled Hour: ' . $schedHour . ' and current Hour: ' .$nowHour );

                /*
                 *  Using these to decide whether or not to assume 'today' or 'tomorrow' for initial schedule for
                 * this cron() job.
                 *
                 * If the admin attempts to schedule a job that's less than 30 minutes away, we'll schedule it for tomorrow.
                 */
                $hourDiff = $schedHour - $nowHour;
                $hourDiff += ( ( ($hourDiff == 0) && (($schedMin - $nowMin) <= 0 )) ? 0 : 1);

                if ( $hourDiff >= 1 ) {
                    $this->dbg_log('calculate_timestamp() - Assuming current day');
                    $when = ''; // Today
                }
                else {
                    $this->dbg_log('calculate_timestamp() - Assuming tomorrow');
                    $when = 'tomorrow ';
                }
                /* Create the string we'll use to generate a timestamp for cron() */
                $timeInput = $when . $timeString . ' ' . get_option('timezone_string');
                $timestamp = strtotime($timeInput);
            }
            catch (Exception $e)
            {
                $this->dbg_log('calculate_timestamp() -- Error calculating timestamp: : ' . $e->getMessage());
            }

            return $timestamp;
        }

        /**
         * Manage and clean up (if needed) the post meta for the $post_id
         *
         * @param int $post_id - The ID of the post
         *
         * @return array|mixed -- Array of sequence IDs that manage this post_id
         */
        private function update_postSeqList( $post_id, $doSave = false ) {

            $seq_list = $this->get_sequences_for_post( $post_id );
            // $seq_list = get_post_meta( $post_id, "_post_sequences", true );
            /*
                        if ( empty( $seq_list ) && ( $post_id == $this->sequence_id ) ) {
                            return array( $this->sequence_id );
                        }
            */
            if ( ! empty( $seq_list ) && is_array( $seq_list )) {

                $this->dbg_log("Cleaning up the list of sequences ");
                $list = array_unique( $seq_list, SORT_NUMERIC );

                if ( ! empty( $list ) ) {
                    $seq_list = $list;
                }
            }
            elseif ( empty( $seq_list ) && ( $post_id == $this->sequence_id ) ) {

                $seq_list = array( $this->sequence_id );
            }

            if ( $doSave === true ) {

                $this->set_sequences_for_post( $post_id, $seq_list );
                // update_post_meta( $post_id, '_post_sequences', $seq_list );
            }

            return $seq_list;
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
        public function is_after_opt_in( $user_id, $optin_ts, $post ) {

            // = $user_settings->sequence[ $this->sequence_id ]->optinTS;

            if ($optin_ts != -1) {

                $this->dbg_log( 'is_after_opt_in() -- User: ' . $user_id . ' Optin TS: ' . $optin_ts .
                    ', Optin Date: ' . date( 'Y-m-d', $optin_ts )
                );

                $delay_ts = $this->delay_as_timestamp( $post->delay, $user_id );

                // Compare the Delay to the optin (subtract 24 hours worth of time from the opt-in TS)
                if ( $delay_ts >= ($optin_ts - (3600 * 24)) ) {

                    $this->dbg_log('is_after_opt_in() - This post SHOULD be allowed to be alerted on');
                    return true;
                } else {
                    $this->dbg_log('is_after_opt_in() - This post should NOT be allowed to be alerted on');
                    return false;
                }
            } else {
                $this->dbg_log('is_after_opt_in() - Negative opt-in timestamp value. The user  (' . $user_id . ') does not want to receive alerts');
                return false;
            }
        }

        /**
         * Update the when we're supposed to run the New Content Notice cron job for this sequence.
         *
         * @access public
         */
        private function update_user_notice_cron() {

            /* TODO: Does not support Daylight Savings Time (DST) transitions well! - Update check hook in init? */
            $prevScheduled = false;
            try {

                // Check if the job is previously scheduled. If not, we're using the default cron schedule.
                if (false !== ($timestamp = wp_next_scheduled( 'pmpro_sequence_cron_hook', array($this->sequence_id) ) )) {

                    // Clear old cronjob for this sequence
                    $this->dbg_log('Current cron job for sequence # ' . $this->sequence_id . ' scheduled for ' . $timestamp);
                    $prevScheduled = true;

                    // wp_clear_scheduled_hook($timestamp, 'pmpro_sequence_cron_hook', array( $this->sequence_id ));
                }

                $this->dbg_log('update_user_notice_cron() - Next scheduled at (timestamp): ' . print_r(wp_next_scheduled('pmpro_sequence_cron_hook', array($this->sequence_id)), true));

                // Set time (what time) to run this cron job the first time.
                $this->dbg_log('update_user_notice_cron() - Alerts for sequence #' . $this->sequence_id . ' at ' . date('Y-m-d H:i:s', $this->options->noticeTimestamp) . ' UTC');

                if  ( ($prevScheduled) &&
                    ($this->options->noticeTimestamp != $timestamp) ) {

                    $this->dbg_log('update_user_notice_cron() - Admin changed when the job is supposed to run. Deleting old cron job for sequence w/ID: ' . $this->sequence_id);
                    wp_clear_scheduled_hook( 'pmpro_sequence_cron_hook', array($this->sequence_id) );

                    // Schedule a new event for the specified time
                    if ( false === wp_schedule_event(
                            $this->options->noticeTimestamp,
                            'daily',
                            'pmpro_sequence_cron_hook',
                            array( $this->sequence_id )
                        )) {

                        $this->set_error_msg( printf( __('Could not schedule new content alert for %s', 'pmprosequence'), $this->options->noticeTime) );
                        $this->dbg_log("update_user_notice_cron() - Did not schedule the new cron job at ". $this->options->noticeTime . " for this sequence (# " . $this->sequence_id . ')');
                        return false;
                    }
                }
                elseif (! $prevScheduled)
                    wp_schedule_event($this->options->noticeTimestamp, 'daily', 'pmpro_sequence_cron_hook', array($this->sequence_id));
                else
                    $this->dbg_log("update_user_notice_cron() - Timestamp didn't change so leave the schedule as-is");

                // Validate that the event was scheduled as expected.
                $ts = wp_next_scheduled( 'pmpro_sequence_cron_hook', array($this->sequence_id) );

                $this->dbg_log('update_user_notice_cron() - According to WP, the job is scheduled for: ' . date('d-m-Y H:i:s', $ts) . ' UTC and we asked for ' . date('d-m-Y H:i:s', $this->options->noticeTimestamp) . ' UTC');

                if ($ts != $this->options->noticeTimestamp)
                    $this->dbg_log("update_user_notice_cron() - Timestamp for actual cron entry doesn't match the one in the options...");
            }
            catch (Exception $e) {
                // echo 'Error: ' . $e->getMessage();
                $this->dbg_log('Error updating cron job(s): ' . $e->getMessage());

                if ( is_null($this->get_error_msg()) )
                    $this->set_error_msg("Exception in update_user_notice_cron(): " . $e->getMessage());

                return false;
            }

            return true;
        }
/*
        public function loadListTemplate() {

            return null;
        }
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

            if( isset($_COOKIE["_ga"]) ){

                list($version,$domainDepth, $cid1, $cid2) = split('[\.]', $_COOKIE["_ga"],4);
                return array('version' => $version, 'domainDepth' => $domainDepth, 'cid' => $cid1.'.'.$cid2);
            }

            return array();
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
        public function send_notice($post_ids, $user_id, $seq_id) {

            // Make sure the email class is loaded.
            if ( ! class_exists( 'PMProEmail' ) ) {
                return;
            }

            if ( !is_array( $post_ids ) ) {

                $post_ids = array( $post_ids );
            }

            $user = get_user_by('id', $user_id);
            $templ = preg_split('/\./', $this->options->noticeTemplate); // Parse the template name

            $emails = array();
            $post_links = '';
            $excerpt = '';


            // Add data/img entry for google analytics.
            if ( isset( $this->options->track_google_analytics ) &&
                ( true === $this->options->track_google_analytics ) ) {

                // FIXME: get_google_analytics_client_id() can't work since this isn't being run during a user session!
                $cid = esc_html( $this->ga_getCid() );
                $tid = esc_html( $this->options->ga_tid );
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

            if ( PMPRO_SEQ_SEND_AS_LIST == $this->options->noticeSendAs ) {

                $post_link_prefix = "<ul>\n";
                $post_link_postfix = "</ul>\n";
            }
            else {
                $post_link_prefix = '';
                $post_link_postfix = '';
            }

            foreach( $post_ids as $post_id ) {

                $post = get_post($post_id);
                $email = new PMProEmail();

                if ( PMPRO_SEQ_SEND_AS_LIST == $this->options->noticeSendAs ) {

                    $post_links .= '<li><a href="' . get_permalink($post->ID) . '" title="' . $post->post_title . '">' . $post->post_title . '</a></li>';

                }
                else {
                    $emails[] = $email;

                    $idx = count( $emails ) - 1;

                    $emails[$idx]->from = $this->options->replyto; // = pmpro_getOption('from_email');
                    $emails[$idx]->template = $templ[0];
                    $emails[$idx]->fromname = $this->options->fromname; // = pmpro_getOption('from_name');
                    $emails[$idx]->email = $user->user_email;
                    $emails[$idx]->subject = sprintf('%s: %s (%s)', $this->options->subject, $post->post_title, strftime("%x", current_time('timestamp') ));
                    $emails[$idx]->dateformat = $this->options->dateformat;

                    if ( !empty( $post->post_excerpt ) ) {

                        $this->dbg_log("Adding the post excerpt to email notice");

                        if ( empty( $this->options->excerpt_intro ) ) {
                            $this->options->excerpt_intro = __('A summary of the post:', 'pmprosequence');
                        }

                        $excerpt = '<p>' . $this->options->excerpt_intro . '</p><p>' . $post->post_excerpt . '</p>';
                    }
                    else {
                        $excerpt = '';
                    }

                    if (false === ($template_content = file_get_contents( $this->email_template_path() ) ) ) {

                        $this->dbg_log('ERROR: Could not read content from template file: '. $this->options->noticeTemplate);
                        return false;
                    }

                    $emails[$idx]->body = $template_content;
                    $post_links .= '<a href="' . get_permalink($post->ID) . '" title="' . $post->post_title . '">' . $post->post_title . '</a>';

                    $emails[$idx]->data = array(
                        "name" => $user->first_name, // Options are: display_name, first_name, last_name, nickname
                        "sitename" => get_option("blogname"),
                        "post_link" => $post_link_prefix . $post_links . $post_link_postfix,
                        "today" => date($this->options->dateformat, current_time('timestamp')),
                        "excerpt" => $excerpt,
                        "ptitle" => $post->post_title
                    );

                    if ( isset( $this->options->track_google_analytics ) && ( true == $this->options->track_google_analytics) ) {
                        $emails[$idx]->data['google_analytics'] = $ga_tracking;
                    }
                }
            }

            if ( empty($emails) ) {

                $email->from = $this->options->replyto; // = pmpro_getOption('from_email');
                $email->template = $templ[0];
                $email->fromname = $this->options->fromname; // = pmpro_getOption('from_name');
                $email->email = $user->user_email;
                $email->subject = sprintf('%s: %s (%s)', $this->options->subject, $post->post_title, strftime("%x", current_time('timestamp') ));
                $email->dateformat = $this->options->dateformat;

                if ( !empty( $post->post_excerpt ) ) {

                    $this->dbg_log("Adding the post excerpt to email notice");

                    if ( empty( $this->options->excerpt_intro ) ) {
                        $this->options->excerpt_intro = __('A summary of the post(s):', 'pmprosequence');
                    }

                    $excerpt = '<p>' . $this->options->excerpt_intro . '</p><p>' . $post->post_excerpt . '</p>';
                }
                else {
                    $excerpt = '';
                }

                if (false === ($template_content = file_get_contents( $this->email_template_path() ) ) ) {

                    $this->dbg_log('ERROR: Could not read content from template file: '. $this->options->noticeTemplate);
                    return false;
                }

                $email->body = $template_content;
                $email->data = array(
                    "name" => $user->first_name, // Options are: display_name, first_name, last_name, nickname
                    "sitename" => get_option("blogname"),
                    "post_link" => $post_link_prefix . $post_links . $post_link_postfix,
                    "today" => date($this->options->dateformat, current_time('timestamp')),
                    "excerpt" => $excerpt,
                    "ptitle" => $post->post_title
                );

                if ( isset( $this->options->track_google_analytics ) && ( true == $this->options->track_google_analytics) ) {
                    $email->data['google_analytics'] = $ga_tracking;
                }

                $email->sendEmail();
            }
            else {

                // Send everything as individual email messages.
                foreach ( $emails as $email ) {

                    // $this->dbg_log('dEmail() - Array contains: ' . print_r($email->data, true));
                    $email->sendEmail();
                }
            }

            wp_reset_postdata();
            // All of the array list names are !!<name>!! escaped values.
            return true;
        }

        /**
         * Check the theme/child-theme/PMPro Sequence plugin directory for the specified notice template.
         *
         * @return null|string -- Path to the selected template for the email alert notice.
         */
        private function email_template_path() {

            if ( file_exists( get_stylesheet_directory() . "/sequence-email-alerts/{$this->options->noticeTemplate}")) {

                $template_path = get_stylesheet_directory() . "/sequence-email-alerts/{$this->options->noticeTemplate}";

            }
            elseif ( file_exists( get_template_directory() . "/sequence-email-alerts/{$this->options->noticeTemplate}" ) ) {

                $template_path = get_template_directory() . "/sequence-email-alerts/{$this->options->noticeTemplate}";
            }
            elseif ( file_exists( PMPRO_SEQUENCE_PLUGIN_DIR . "/email/{$this->options->noticeTemplate}" ) ) {

                $template_path = PMPRO_SEQUENCE_PLUGIN_DIR . "/email/{$this->options->noticeTemplate}";
            }
            else {

                $template_path = null;
            }

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

            $this->dbg_log("reset_user_alerts() - Attempting to delete old-style user notices for sequence with ID: {$sequenceId}", DEBUG_SEQ_INFO);
            $old_style = delete_user_meta( $userId, $wpdb->prefix . 'pmpro_sequence_notices' );

            $this->dbg_log("reset_user_alerts() - Attempting to delete v3 style user notices for sequence with ID: {$sequenceId}", DEBUG_SEQ_INFO);
            $v3_style = delete_user_meta( $userId, "pmpro_sequence_id_{$sequenceId}_notices" );

            if ( $old_style || $v3_style ) {

                $this->dbg_log("reset_user_alerts() - Successfully delted user notice settings for user {$userId}");
                return true;
            }

            // $this->load_notices( $this->sequence_id );
            /*
            if ( isset( $notices->sequences ) ) {

                foreach( $notices->sequences as $seqId => $noticeList ) {

                    if ( $seqId == $sequenceId ) {

                        $this->dbg_log("Deleting user notices for sequence with ID: {$sequenceId}", DEBUG_SEQ_INFO);

                        unset($notices->sequences[$seqId]);
                        //  Use $this->save_user_notice_settings( $userId, $notices, $sequenceId )
                        return update_user_meta( $userId, $wpdb->prefix . 'pmpro_sequence_notices', $notices );
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

            $this->dbg_log('email_body() action: Update body of message if it is sent by PMPro Sequence');

            if ( isset( $phpmailer->excerpt_intro ) ) {
                $phpmailer->Body = apply_filters( 'pmpro-sequence-alert-message-excerpt-intro', str_replace( "!!excerpt_intro!!", $phpmailer->excerpt_intro, $phpmailer->Body ) );
            }

            if ( isset( $phpmailer->ptitle ) ) {
                $phpmailer->Body = apply_filters( 'pmpro-sequence-alert-message-title', str_replace( "!!ptitle!!", $phpmailer->ptitle, $phpmailer->Body ) );
            }
        }

        /**
         * Does the heavy lifting in checking access the sequence post for the user_id
         *
         * @since 2.0
         *
         * @param $post (stdClass) - ID of the post to check access for
         * @param $user_id (int) - User ID for the user to check access for
         * @param $post_sequences (array) - Array of sequences that $post_id claims to belong to.
         * @param $isAlert -- Whether or not we're checking access as part of alert processing or not.
         *
         * @return bool -- True if the user has a membership level and the post's delay value is <= the # of days the user has been a member.
         *
         * @access private
         */
        /*
        private function has_access( $post, $user_id, $post_sequences, $isAlert ) {

            if ( in_array( $this->sequence_id, $post_sequences ) ) {

                if ( user_can( $user_id, 'publish_posts' ) && ( is_preview() ) ) {
                    $this->dbg_log("Post #{$post->id} is a preview for {$user_id}");
                    return true;
                }

                $allowed_post_statuses = apply_filters( 'pmpro-sequence-allowed-post-statuses', array( 'publish', 'future', 'private' ) );
                $curr_post_status = get_post_status( $post->id );

                // Only consider granting access to the post if it is in one of the allowed statuses
                if ( ! in_array( $curr_post_status, $allowed_post_statuses ) ) {

                    $this->dbg_log("has_access() - Post {$post->id} with status {$curr_post_status} isn't accessible", DEBUG_SEQ_WARNING );
                    return false;
                }

                // $this->dbg_log( "has_access() for post {$post_id} is managed by PMProSequence: " . $this->who_called_me() );

                // Bugfix: It's possible there are duplicate values in the list of sequences for this post.
                $sequence_list = array_unique( $post_sequences );

                if ( count( $sequence_list ) < count( $post_sequences ) ) {

                    $this->dbg_log("has_access() - Saving the pruned array of sequences");

                    $this->set_sequences_for_post( $post->id, $sequence_list );
                    // update_post_meta( $post_id, '_post_sequences', $sequence_list );
                }

                $this->dbg_log("has_access() - UserID: {$user_id}, post: {$post->id}, Alert: {$isAlert} for sequence: {$this->sequence_id} - sequence_list: " .print_r( $sequence_list, true));

                $results = pmpro_has_membership_access( $this->sequence_id, $user_id, true ); //Using true to return all level IDs that have access to the sequence

                $this->dbg_log("has_access() - True is: " . true . " and PMPRO function returns: " . print_r( $results, true )  );

                if ( true != $results[0] ) { // First item in results array == true if user has access

                    $this->dbg_log( "has_access() - User {$user_id} does NOT have access to this sequence ({$this->sequence_id})", DEBUG_SEQ_WARNING );
                    return false;
                }

                $usersLevels = pmpro_getMembershipLevelsForUser( $user_id );

                // Verify for all levels given access to this post
                foreach ( $results[1] as $level_id ) {

                    if ( ! in_object_r( 'id', $level_id, $usersLevels ) ) {
                        // $level_id (i.e. membership_id) isn't in the array of levels this $user_id also belongs to...
                        // $this->dbg_log("has_access() - Users membership level list does not include {$level_id} - skipping");
                        continue;
                    }

                    if ( $this->options->delayType == 'byDays' ) {

                        // Don't add 'preview' value if this is for an alert notice.
                        if (! $isAlert) {

                            $durationOfMembership = $this->get_membership_days( $user_id, $level_id ) + $this->options->previewOffset;
                        }
                        else {

                            $durationOfMembership = $this->get_membership_days( $user_id, $level_id );
                        }
*/
                        /**
                         * Allow user to add an offset (pos/neg integer) to the 'current day' calculation so they may
                         * offset when this user apparently started their access to the sequence
                         *
                         * @since 2.4.13
                         */
/*
                        $offset = apply_filters( 'pmpro-sequence-add-startdate-offset', __return_zero(), $this->sequence_id );

                        $durationOfMembership += $offset;

                        // $this->dbg_log( sprintf('has_access() - Member %d has been active at level %d for %f days. The post has a delay of: %d', $user_id, $level_id, $durationOfMembership, $sp->delay) );

                        // foreach( $delay_arr as $delay ) {

                            if ( $post->delay <= $durationOfMembership ) {

                                // Set users membership Level
                                $this->pmpro_sequence_user_level = $level_id;
                                // $this->dbg_log("has_access() - using byDays as the delay type, this user is given access to post ID {$post_id}.");
                                return true;
                            }
                        // }
                    }
                    elseif ( $this->options->delayType == 'byDate' ) {

                        // Don't add 'preview' value if this is for an alert notice.
                        if (! $isAlert)
                            $previewAdd = ((60*60*24) * $this->options->previewOffset);
                        else
                            $previewAdd = 0;
*/
                        /**
                         * Allow user to add an offset (pos/neg integer) to the 'current day' calculation so they may
                         * offset when this user apparently started their access to the sequence
                         *
                         * @since 2.4.13
                         */
/*
                        $offset = apply_filters( 'pmpro-sequence-add-startdate-offset', __return_zero(), $this->sequence_id );

                        $timestamp = ( current_time( 'timestamp' ) + $previewAdd + ( $offset * 60*60*24 ) );

                        $today = date( __( 'Y-m-d', 'pmprosequence' ), $timestamp );

                        // foreach( $delay_arr as $delay ) {

                            if ( $post->delay <= $today ) {

                                $this->pmpro_sequence_user_level = $level_id;
                                // $this->dbg_log("has_access() - using byDate as the delay type, this user is given access to post ID {$post_id}.");
                                return true;
                            }
                        // }
                    } // EndIf for delayType
                } // End of foreach -> $level_id


            } // End of if

            $this->dbg_log("has_access() - User {$user_id} does NOT have access to post {$post->id} in sequence {$this->sequence_id}" );
            // Haven't found anything yet, so must not have access.
            return false;
        }
*/
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

                $this->dbg_log("User with ID {$user_id} has permission to update/edit this sequence");
                return true;
            }
            else {
                return false;
            }
        }

        /************************************ Private Debug Functionality ***********************************************/

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

        /**
          * For backwards compatibility.
          * @param $msg
          * @param int $lvl
          */
        public function dbgOut( $msg, $lvl = DEBUG_SEQ_INFO ) {

            $this->dbg_log( $msg, $lvl );
        }

        /**
         * Debug function (if executes if DEBUG is defined)
         *
         * @param $msg -- Debug message to print to debug log.
         *
         * @access public
         * @since v2.1
         */
        public function dbg_log( $msg, $lvl = DEBUG_SEQ_INFO ) {

            $uplDir = wp_upload_dir();
            $plugin = "/pmpro-sequences/";

            $dbgRoot = $uplDir['basedir'] . "${plugin}";
            // $dbgRoot = "${plugin}/";
            $dbgPath = "${dbgRoot}";

            // $dbgPath = PMPRO_SEQUENCE_PLUGIN_DIR . 'debug';

            if ( ( WP_DEBUG === true ) && ( ( $lvl >= DEBUG_SEQ_LOG_LEVEL ) || ( $lvl == DEBUG_SEQ_INFO ) ) ) {

                if ( !file_exists( $dbgRoot ) ) {

                    mkdir($dbgRoot, 0750);

                    if (!is_writable($dbgRoot)) {
                        error_log("PMPro Sequence: Debug log directory {$dbgRoot} is not writable. exiting.");
                        return;
                    }
                }

                if (!file_exists($dbgPath)) {

                    // Create the debug logging directory
                    mkdir($dbgPath, 0750);

                    if (!is_writable($dbgPath)) {
                        error_log("PMPro Sequence: Debug log directory {$dbgPath} is not writable. exiting.");
                        return;
                    }
                }

                // $dbgFile = $dbgPath . DIRECTORY_SEPARATOR . 'sequence_debug_log-' . date('Y-m-d', current_time("timestamp") ) . '.txt';
                $dbgFile = $dbgPath . DIRECTORY_SEPARATOR . 'pmpro_seq_debug_log.txt';

                $tid = sprintf("%08x", abs(crc32($_SERVER['REMOTE_ADDR'] . $_SERVER['REQUEST_TIME'] . $_SERVER['REMOTE_PORT'])));

                $dbgMsg = '(' . date('d-m-y H:i:s', current_time('timestamp')) . "-{$tid}) -- " .
                    ((is_array($msg) || (is_object($msg))) ? print_r($msg, true) : $msg) . "\n";

                $this->add_log_text($dbgMsg, $dbgFile);
            }
        }

        private function add_log_text($text, $filename) {

            if ( !file_exists($filename) ) {

                touch( $filename );
                chmod( $filename, 0640 );
            }

            if ( filesize( $filename ) > MAX_LOG_SIZE ) {

                $filename2 = "$filename.old";

                if ( file_exists( $filename2 ) ) {

                    unlink($filename2);
                }

                rename($filename, $filename2);
                touch($filename);
                chmod($filename,0640);
            }

            if ( !is_writable( $filename ) ) {

                error_log( "Unable to open debug log file ($filename)" );
            }

            if ( !$handle = fopen( $filename, 'a' ) ) {

                error_log("Unable to open debug log file ($filename)");
            }

            if ( fwrite( $handle, $text ) === FALSE ) {

                error_log("Unable to write to debug log file ($filename)");
            }

            fclose($handle);
        }
        /**
         * Access the private $error value
         *
         * @return string|null -- Error message or NULL
         * @access public
         */
        public function get_error_msg() {

            if ( empty( $this->error ) ) {

                $this->dbg_log("Attempt to load error info");

                // Check if the settings_error string is set:
                $this->error = get_settings_errors( 'pmpro_seq_errors' );
            }

            if ( ! empty( $this->error ) ) {

                $this->dbg_log("Error info found: " . print_r( $this->error, true));
                return $this->error;
            }
            else {
                return null;
            }
        }


        /********************************* Plugin AJAX Callback Functionality *******************/

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
		        $this->dbg_log("post_save_action() - No post type defined for {$post_id}", DEBUG_SEQ_WARNING);
		        return;
	        }

            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                $this->dbg_log("Exit during autosave");
                return;
            }

            if ( wp_is_post_revision( $post_id ) !== false ) {
                $this->dbg_log("post_save_action() - Not saving revisions ({$post_id}) to sequence");
                return;
            }

            if ( ! in_array( $post->post_type, $this->managed_types ) ) {
                $this->dbg_log("post_save_action() - Not saving delay info for {$post->post_type}");
                return;
            }

	        if ( 'trash' == get_post_status( $post_id ) ) {
		        return;
	        }

            $this->dbg_log("post_save_action() - Sequences & Delays have been configured for page save. " . $this->who_called_me());

            if ( isset( $_POST['pmpro_seq-sequences'] ) ) {
                $seq_ids = is_array( $_POST['pmpro_seq-sequences'] ) ? array_map( 'esc_attr', $_POST['pmpro_seq-sequences']) : null;
            }
            else {
                $seq_ids = array();
            }

            if ( isset( $_POST['pmpro_seq-delay'] ) ) {

                $delays = is_array( $_POST['pmpro_seq-delay'] ) ? array_map( 'esc_attr', $_POST['pmpro_seq-delay'] )  : array();
            }
            else {
                $delays = array();
            }

            if ( empty( $delays ) ) {

                $this->set_error_msg( __( "Error: No delay value(s) received", "pmprosequence") );
                $this->dbg_log( "post_save_action() - Error: delay not specified! ", DEBUG_SEQ_CRITICAL );
                return;
            }

            $errMsg = null;

            $already_in = $this->get_sequences_for_post( $post_id );
            // $already_in = get_post_meta( $post_id, "_post_sequences", true );

            $this->dbg_log( "post_save_action() - Saved received variable values...");

            foreach ($seq_ids as $key => $id ) {

                $this->dbg_log("post_save_action() - Processing for sequence {$id}");

                if ( $id == 0 ) {
                    continue;
                }

                $this->init( $id );

                $user_can = apply_filters( 'pmpro-sequence-has-edit-privileges', $this->user_can_edit( $current_user->ID ) );

                if (! $user_can ) {

                    $this->set_error_msg( __( 'Incorrect privileges for this operation', 'pmprosequence' ) );
                    $this->dbg_log("post_save_action() - User lacks privileges to edit", DEBUG_SEQ_WARNING);
                    return;
                }

                if ( $id == 0 ) {

                    $this->dbg_log("post_save_action() - No specified sequence or it's set to 'nothing'");

                }
                elseif ( empty( $delays[$key] ) ) {

                    $this->dbg_log("post_save_action() - Not a valid delay value...: " . $delays[$key], DEBUG_SEQ_CRITICAL);
                    $this->set_error_msg( sprintf( __( "You must specify a delay value for the '%s' sequence", 'pmprosequence'), get_the_title( $id ) ) );
                }
                else {

                    $this->dbg_log( "post_save_action() - Processing post {$post_id} for sequence {$this->sequence_id} with delay {$delays[$key]}" );
                    $this->add_post( $post_id, $delays[ $key ] );
                }
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

                $this->dbg_log('save_post_meta(): No post ID supplied...', DEBUG_SEQ_WARNING);
                return false;
            }

            if ( wp_is_post_revision( $post_id ) )
                return $post_id;

            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                return $post_id;
            }

            if ( ! isset( $post->post_type) || ( $post->post_type != 'pmpro_sequence' ) ) {
                return $post_id;
            }

	        if ( 'trash' == get_post_status( $post_id ) ) {
		        return $post_id;
	        }

            $this->init( $post_id );

            $this->dbg_log('save_post_meta(): Saving settings for sequence ' . $post_id);
            // $this->dbg_log('From Web: ' . print_r($_REQUEST, true));

            // OK, we're authenticated: we need to find and save the data
            if ( isset($_POST['pmpro_sequence_settings_noncename']) ) {

                $this->dbg_log( 'save_post_meta() - Have to load new instance of Sequence class' );

                if ( ! $this->options ) {
                    $this->options = $this->default_options();
                }

                if ( ($retval = $this->save_settings( $post_id, $this )) === true ) {

                    $this->dbg_log( 'save_post_meta(): Saved metadata for sequence #' . $post_id );

                    return true;
                }
                else
                    return false;

            }

            return false; // Default
        }

        /**
         * Function to process Sequence Settings AJAX POST call (save operation)
         *
         * Returns 'success' or 'error' message to calling JavaScript function
         */
/*
        function settings_callback()
        {
            // Validate that the ajax referrer is secure
            check_ajax_referer('pmpro-sequence-save-settings', 'pmpro_sequence_settings_nonce');

            // @noinspection PhpUnusedLocalVariableInspection
            $status = false;

            // @noinspection PhpUnusedLocalVariableInspection
            $response = '';

            try {

                if ( isset($_POST['pmpro_sequence_id']) ) {

                    $sequence_id = intval($_POST['pmpro_sequence_id']);
                    $this->init( $sequence_id );

                    $this->dbg_log('settings_callback() - Saving settings for ' . $this->sequence_id);

                    if ( ($status = $this->save_settings( $sequence_id ) ) === true) {

                        if ( isset($_POST['hidden_pmpro_seq_wipesequence'])) {

                            if (intval($_POST['hidden_pmpro_seq_wipesequence']) == 1) {

                                // FIXME: Need to wipe (delete) the sequence data from all posts (including delays).
                                // Wipe the list of posts in the sequence.
                                $sposts = get_post_meta( $sequence_id, '_sequence_posts' );

                                if ( count($sposts) > 0) {

                                    if ( !$this->delete_post_meta_for_sequence( $sequence_id ) ) {

                                        $this->dbg_log( 'settings_callback() - Unable to delete the posts in sequence # ' . $sequence_id, DEBUG_SEQ_CRITICAL );
                                        $this->set_error_msg( __('Unable to wipe existing posts', 'pmprosequence') );
                                        $status = false;
                                    }
                                    else
                                        $status = true;
                                }

                                $this->dbg_log( 'settings_callback() - Deleted all posts in the sequence' );
                            }
                        }
                    }
                    else {
                        $this->set_error_msg( printf( __('Save status returned was: %s', 'pmprosequence'), $status ) );
                    }

                    $response = $this->get_post_list_for_metabox();
                }
                else {
                    $this->set_error_msg( __( 'No sequence ID found/specified', 'pmprosequence' ) );
                    $status = false;
                }

            } catch (Exception $e) {

                $status = false;
                $this->set_error_msg( printf( __('(exception) %s', 'pmprosequence'), $e->getMessage()) );
                $this->dbg_log(print_r($this->get_error_msg(), true), DEBUG_SEQ_CRITICAL);
            }


            if ($status)
                wp_send_json_success( $response['html'] );
            else
                wp_send_json_error( $this->get_error_msg() );

        }
*/
        private function delete_post_meta_for_sequence( $sequence_id ) {

            $retval = false;

            if ( delete_post_meta_by_key( "_pmpro_sequence_{$sequence_id}_post_delay" ) ) {
                $retval = true;
            }

            foreach( $this->posts as $post ) {

                if ( delete_post_meta( $post->id, "_pmpro_sequence_post_belongs_to", $sequence_id ) ) {
                    $retval = true;
                }

                if ( $retval != true ) {

                    $this->dbg_log("delete_post_meta_for_sequence() - ERROR deleting sequence metadata for post {$post->id}: ", DEBUG_SEQ_CRITICAL );
                }
            }


            return $retval;
        }

        /**
         * Callback for saving the sequence alert optin/optout for the current user
         */
        function optin_callback()
        {
            global $current_user, $wpdb;

            /** @noinspection PhpUnusedLocalVariableInspection */
            $result = '';

            try {

                check_ajax_referer('pmpro-sequence-user-optin', 'pmpro_sequence_optin_nonce');

                if ( isset($_POST['hidden_pmpro_seq_id'])) {

                    $seqId = intval( $_POST['hidden_pmpro_seq_id']);
                }
                else {

                    $this->dbg_log( 'No sequence number specified. Ignoring settings for user', DEBUG_SEQ_WARNING );

                    wp_send_json_error( __('Unable to save your settings', 'pmprosequence') );
                }

                if ( isset($_POST['hidden_pmpro_seq_uid'])) {

                    $user_id = intval($_POST['hidden_pmpro_seq_uid']);
                    $this->dbg_log('Updating user settings for user #: ' . $user_id);

                    // Grab the metadata from the database
                    // $usrSettings = get_user_meta($user_id, $wpdb->prefix . 'pmpro_sequence_notices', true);
                    $usrSettings = $this->load_user_notice_settings( $user_id, $seqId );

                }
                else {
                    $this->dbg_log( 'No user ID specified. Ignoring settings!', DEBUG_SEQ_WARNING );

                    wp_send_json_error( __('Unable to save your settings', 'pmprosequence') );
                }

                $this->init( $seqId );
                $this->dbg_log('Updating user settings for sequence #: ' . $this->sequence_id);

                if ( isset( $usrSettings->id ) && ( $usrSettings->id !== $this->sequence_id ) ) {

                    $this->dbg_log('No user specific settings found for this sequence. Creating defaults');

/*
                    // Create new opt-in settings for this user
                    if ( empty($usrSettings->sequence) )
                        $new = new stdClass();
                    else // Saves existing settings
                        $new = $usrSettings;
*/
                    $this->dbg_log('Using default setting for user ' . $current_user->ID . ' and sequence ' . $this->sequence_id);

                    $usrSettings = $this->create_user_notice_defaults();
                }

                // $usrSettings->sequence[$seqId]->sendNotice = ( isset( $_POST['hidden_pmpro_seq_useroptin'] ) ?
                $usrSettings->send_notice = ( isset( $_POST['hidden_pmpro_seq_useroptin'] ) ?
                    intval($_POST['hidden_pmpro_seq_useroptin']) : $this->options->sendNotice );

                // If the user opted in to receiving alerts, set the opt-in timestamp to the current time.
                // If they opted out, set the opt-in timestamp to -1

                if ($usrSettings->send_notice == 1) {
                    // Set the timestamp when the user opted in.
                    $usrSettings->last_notice_sent = current_time( 'timestamp' );
                }
                else {
                    $usrSettings->last_notice_sent = -1; // Opted out.
                }


                // Add an empty array to store posts that the user has already been notified about
                if ( empty( $usrSettings->posts ) ) {
                    $usrSettings->posts = array();
                    }

                /* Save the user options we just defined */
                if ( $user_id == $current_user->ID ) {

                    $this->dbg_log('Opt-In Timestamp is: ' . $usrSettings->last_notice_sent);
                    $this->dbg_log('Saving user_meta for UID ' . $user_id . ' Settings: ' . print_r($usrSettings, true));

                    $this->save_user_notice_settings( $user_id, $usrSettings, $seqId );
                    // update_user_meta( $user_id, $wpdb->prefix . 'pmpro_sequence_notices', $usrSettings );
                    $status = true;
                    $this->set_error_msg(null);
                }
                else {

                    $this->dbg_log('Error: Mismatched User IDs -- user_id: ' . $user_id . ' current_user: ' . $current_user->ID, DEBUG_SEQ_CRITICAL);
                    $this->set_error_msg( __( 'Unable to save your settings', 'pmprosequence' ) );
                    $status = false;
                }
            }
            catch (Exception $e) {
                $this->set_error_msg( sprintf( __('Error: %s', 'pmprosequence' ), $e->getMessage() ) );
                $status = false;
                $this->dbg_log('optin_save() - Exception error: ' . $e->getMessage(), DEBUG_SEQ_CRITICAL);
            }

            if ($status)
                wp_send_json_success();
            else
                wp_send_json_error( $this->get_error_msg() );

        }

        /**
         * Callback to catch request from admin to send any new Sequence alerts to the users.
         *
         * Triggers the cron hook to achieve it.
         */
        function sendalert_callback() {

            $this->dbg_log('sendalert() - Processing the request to send alerts manually');

            check_ajax_referer('pmpro-sequence-sendalert', 'pmpro_sequence_sendalert_nonce');

            $this->dbg_log('Nonce is OK');

            if ( isset( $_POST['pmpro_sequence_id'] ) ) {

                $sequence_id = intval($_POST['pmpro_sequence_id']);
                $this->dbg_log('sendalert() - Will send alerts for sequence #' . $sequence_id);

                do_action( 'pmpro_sequence_cron_hook', $sequence_id);

                $this->dbg_log('sendalert() - Completed action for sequence');
            }
        }


        /**
         * Catches ajax POSTs from dashboard/edit for CPT (clear existing sequence members)
         */
        function sequence_clear_callback() {

            // Validate that the ajax referrer is secure
            check_ajax_referer('pmpro-sequence-save-settings', 'pmpro_sequence_settings_nonce');

            /** @noinspection PhpUnusedLocalVariableInspection */
            $result = '';

            // Clear the sequence metadata if the sequence type (by date or by day count) changed.
            if (isset($_POST['pmpro_sequence_clear']))
            {
                if (isset($_POST['pmpro_sequence_id']))
                {
                    $sequence_id = intval($_POST['pmpro_sequence_id']);
                    $this->init( $sequence_id );

                    $this->dbg_log('sequence_clear_callback() - Deleting all entries in sequence # ' .$sequence_id);

                    if ( !$this->delete_post_meta_for_sequence($sequence_id) )
                    {
                        $this->dbg_log('Unable to delete the posts in sequence # ' . $sequence_id, DEBUG_SEQ_CRITICAL);
                        $this->set_error_msg( __('Could not delete posts from this sequence', 'pmprosequence'));

                    }
                    else {
                        $result = $this->get_post_list_for_metabox();
                    }

                }
                else
                {
                    $this->set_error_msg( __('Unable to identify the Sequence', 'pmprosequence') );
                }
            }
            else {
                $this->set_error_msg( __('Unknown request', 'pmprosequence') );
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
        function rm_post_callback() {

            check_ajax_referer('pmpro-sequence-rm-post', 'pmpro_sequence_rmpost_nonce');

            /** @noinspection PhpUnusedLocalVariableInspection */
            $result = '';

            /** @noinspection PhpUnusedLocalVariableInspection */
            $success = false;

            $sequence_id = ( isset( $_POST['pmpro_sequence_id']) && '' != $_POST['pmpro_sequence_id'] ? intval($_POST['pmpro_sequence_id']) : null );
            $seq_post_id = ( isset( $_POST['pmpro_seq_post']) && '' != $_POST['pmpro_seq_post'] ? intval($_POST['pmpro_seq_post']) : null );
            $delay = ( isset( $_POST['pmpro_seq_delay']) && '' != $_POST['pmpro_seq_delay'] ? intval($_POST['pmpro_seq_delay']) : null );

            $this->init( $sequence_id );

            // Remove the post (if the user is allowed to)
            if ( current_user_can( 'edit_posts' ) && !is_null($seq_post_id) ) {

                $this->remove_post( $seq_post_id, $delay );

                //$result = __('The post has been removed', 'pmprosequence');
                $success = true;

            }
            else {

                $success = false;
                $this->set_error_msg( __( 'Incorrect privileges to remove posts from this sequence', 'pmprosequence'));
            }

            // Return the content for the new listbox (sans the deleted item)
            $result = $this->get_post_list_for_metabox();

            if ( is_null( $result['message'] ) && is_null( $this->get_error_msg() ) && ($success)) {
                $this->dbg_log('rm_post_callback() - Returning success to calling javascript');
                wp_send_json_success( $result['html'] );
            }
            else
                wp_send_json_error( ( ! is_null( $this->get_error_msg() ) ? $this->get_error_msg() : $result['message']) );

        }

        /**
         * Removes the sequence from managing this $post_id.
         * Returns the table of sequences the post_id belongs to back to the post/page editor using JSON.
         */
        function rm_sequence_from_post_callback() {

            /** @noinspection PhpUnusedLocalVariableInspection */
            $success = false;

            // $this->dbg_log("In rm_sequence_from_post()");
            check_ajax_referer('pmpro-sequence-post-meta', 'pmpro_sequence_postmeta_nonce');

            $this->dbg_log("rm_sequence_from_post_callback() - NONCE is OK for pmpro_sequence_rm");

            $sequence_id = ( isset( $_POST['pmpro_sequence_id'] ) && ( intval( $_POST['pmpro_sequence_id'] ) != 0 ) ) ? intval( $_POST['pmpro_sequence_id'] ) : null;
            $post_id = isset( $_POST['pmpro_seq_post_id'] ) ? intval( $_POST['pmpro_seq_post_id'] ) : null;
            $delay = isset( $_POST['pmpro_seq_delay'] ) ? intval( $_POST['pmpro_seq_delay'] ) : null;

            $this->init( $sequence_id );
            $this->set_error_msg( null ); // Clear any pending error messages (don't care at this point).

            // Remove the post (if the user is allowed to)
            if ( current_user_can( 'edit_posts' ) && ( ! is_null( $post_id ) ) && ( ! is_null( $sequence_id ) ) ) {

                $this->dbg_log("Removing post # {$post_id} from sequence {$sequence_id}");
                $this->remove_post( $post_id, $delay, true );
                //$result = __('The post has been removed', 'pmprosequence');
                $success = true;
            } else {

                $success = false;
                $this->set_error_msg( __( 'Incorrect privileges to remove posts from this sequence', 'pmprosequence' ) );
            }

            $result = $this->load_sequence_meta( $post_id );

            if ( ! empty( $result ) && is_null( $this->get_error_msg() ) && ( $success ) ) {

                $this->dbg_log( 'Returning success to caller' );
                wp_send_json_success( $result );
            } else {

                wp_send_json_error( ( ! is_null( $this->get_error_msg() ) ? $this->get_error_msg() : 'Error clearing the sequence from this post' ) );
            }
        }

        /**
         * Updates the delay for a post in the specified sequence (AJAX)
         *
         * @throws Exception
         */
        function update_delay_post_meta_callback() {

            $this->dbg_log("Update the delay input for the post/page meta");

            check_ajax_referer('pmpro-sequence-post-meta', 'pmpro_sequence_postmeta_nonce');

            $this->dbg_log("Nonce Passed for postmeta AJAX call");

            $seq_id = isset( $_POST['pmpro_sequence_id'] ) ? intval( $_POST['pmpro_sequence_id'] ) : null;
            $post_id = isset( $_POST['pmpro_sequence_post_id']) ? intval( $_POST['pmpro_sequence_post_id'] ) : null;

            $this->dbg_log("Sequence: {$seq_id}, Post: {$post_id}" );

            $this->init( $seq_id );

            $html = $this->load_sequence_meta( $post_id, $seq_id );


            wp_send_json_success( $html );
        }

        /**
         * Process AJAX based additions to the sequence list
         *
         * Returns 'error' message (or nothing, if success) to calling JavaScript function
         */
        function add_post_callback()
        {
            check_ajax_referer('pmpro-sequence-add-post', 'pmpro_sequence_addpost_nonce');

            global $current_user;

            // Fetch the ID of the sequence to add the post to
            $sequence_id = isset( $_POST['pmpro_sequence_id'] ) && '' != $_POST['pmpro_sequence_id'] ? intval($_POST['pmpro_sequence_id']) : null;
            $seq_post_id = isset( $_POST['pmpro_sequencepost'] ) && '' != $_POST['pmpro_sequencepost'] ? intval( $_REQUEST['pmpro_sequencepost'] ) : null;
            $delayVal = isset( $_POST['pmpro_sequencedelay'] ) ? intval( $_POST['pmpro_sequencedelay'] ) : null ;

            if ( $sequence_id != 0 ) {

                // Initiate & configure the Sequence class
                $this->init( $sequence_id );

                $this->dbg_log( 'add_post_callback() - Checking whether delay value is correct' );
                $delay = $this->validate_delay_value( $delayVal );

                // Get the Delay to use for the post (depends on type of delay configured)
                if ( $delay !== false ) {

                    $user_can = apply_filters( 'pmpro-sequence-has-edit-privileges', $this->user_can_edit( $current_user->ID ) );

                    if ( $user_can && ! is_null( $seq_post_id ) ) {

                        $this->dbg_log( 'pmpro_sequence_add_post_callback() - Adding post ' . $seq_post_id . ' to sequence ' . $this->sequence_id );

                        if ( $this->add_post( $seq_post_id, $delay ) ) {

                            $success = true;
                            $this->set_error_msg( null );
                        }
                        else {
                            $success = false;
                            $this->set_error_msg( __( "Error adding post with ID: " . esc_attr( $seq_post_id ) . " and delay value: " . esc_attr($delay) . " to this sequence", pmprosequence ) );
                        }

                    } else {
                        $success = false;
                        $this->set_error_msg( __( 'Not permitted to modify the sequence', 'pmprosequence' ) );
                    }

                } else {

                    $this->dbg_log( 'pmpro_sequence_add_post_callback(): Delay value was not specified. Not adding the post: ' . esc_attr( $_POST['pmpro_sequencedelay'] ) );

                    if ( empty( $seq_post_id ) && ( $this->get_error_msg() == null ) ) {

                        $this->set_error_msg( sprintf( __( 'Did not specify a post/page to add', 'pmprosequence' ) ) );
                    }
                    elseif ( ( $delay !== 0 ) && empty( $delay ) ) {

                        $this->set_error_msg( __( 'No delay has been specified', 'pmprosequence' ) );
                    }

                    $delay       = null;
                    $seq_post_id = null;

                    $success = false;

                }

                if ( empty( $seq_post_id ) && ( $this->get_error_msg() == null ) ) {

                    $success = false;
                    $this->set_error_msg( sprintf( __( 'Did not specify a post/page to add', 'pmprosequence' ) ) );
                }
                elseif ( empty( $sequence_id ) && ( $this->get_error_msg() == null ) ) {

                    $success = false;
                    $this->set_error_msg( sprintf( __( 'This sequence was not found on the server!', 'pmprosequence' ) ) );
                }

                $result = $this->get_post_list_for_metabox();

                // $this->dbg_log("pmpro_sequence_add_post_callback() - Data added to sequence. Returning status to calling JS script: " . print_r($result, true));

                if ( $result['success'] && $success ) {
                    $this->dbg_log( 'pmpro_sequence_add_post_callback() - Returning success to javascript frontend' );

                    wp_send_json_success( $result['html'] );
                } else {
                    $this->dbg_log( 'pmpro_sequence_add_post_callback() - Returning error to javascript frontend' );
                    wp_send_json_error( $this->get_error_msg() );
                }
            }
            else {
                $this->dbg_log( "Sequence ID was 0. That's a 'blank' sequence" );
                wp_send_json_error( 'No sequence specified on save.' );
            }
        }

        /**
         * Define default settings for sending sequence notifications to a new user.
         *
         * @param int $sequence_id - The ID of the sequence.
         * @return stdClass -- Returns a $noticeSettings object
         *
         */
/*        public function default_notice_settings( $sequence_id = 0 ) {

            $starting = date('Y-m-d H:i:s', current_time( 'timestamp' ) );

            $this->dbg_log("Start time for default Notice settings will be today at midnight: {$starting}");
            $noticeSettings = new stdClass();
            $noticeSettings->sequence = array();

            $noticeSettings->sequence[ $sequence_id ] = new stdClass();
            $noticeSettings->sequence[ $sequence_id ]->sendNotice = 1;
            $noticeSettings->sequence[ $sequence_id ]->optinTS = strtotime( $starting );
            $noticeSettings->sequence[ $sequence_id ]->notifiedPosts = array();
            $noticeSettings->sequence[ $sequence_id ]->converted = true;

            return $noticeSettings;
        }
*/
        /**
         * Save the settings for a sequence ID as post_meta for that Sequence CPT
         *
         * @param $sequence_id -- ID of the sequence to save options for
         * @return bool - Returns true if save is successful
         */

        public function save_settings( $sequence_id )
        {

            $settings = $this->options;
            $this->dbg_log('Saving settings for Sequence w/ID: ' . $sequence_id);
            $this->dbg_log($_POST);

            // Check that the function was called correctly. If not, just return
            if(empty($sequence_id)) {
                $this->dbg_log('save_settings(): No sequence ID supplied...');
                $this->set_error_msg( __('No sequence provided', 'pmprosequence'));
                return false;
            }

            // Is this an auto save routine? If our form has not been submitted (clicked "save"), we'd probably not want to save anything yet
            if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
                $this->set_error_msg(null);
                return $sequence_id;
            }

            // Verify that we're allowed to update the sequence data
            if ( !current_user_can( 'edit_post', $sequence_id ) ) {
                $this->dbg_log('save_settings(): User is not allowed to edit this post type', DEBUG_SEQ_CRITICAL);
                $this->set_error_msg( __('User is not allowed to change settings', 'pmprosequence'));
                return false;
            }

            if (!$this->options) {
                $this->options = $this->default_options();
            }

            if ( isset($_POST['pmpro_sequence_allowRepeatPosts']) )
            {
                $this->options->allowRepeatPosts = intval( $_POST['pmpro_sequence_allowRepeatPosts'] ) == 0 ? false : true;
                $this->dbg_log('save_settings(): POST value for settings->allowRepeatPost: ' . intval($_POST['pmpro_sequence_allowRepeatPosts']) );
            }
            elseif (empty($this->options->allowRepeatPosts))
                $this->options->allowRepeatPosts = false;

            if ( isset($_POST['pmpro_sequence_hidden']) )
            {
                $this->options->hidden = intval( $_POST['pmpro_sequence_hidden'] ) == 0 ? false : true;
                $this->dbg_log('save_settings(): POST value for settings->allowRepeatPost: ' . intval($_POST['pmpro_sequence_hidden']) );
            }
            elseif (empty($this->options->hidden))
                $this->options->hidden = false;

            // Checkbox - not included during post/save if unchecked
            if ( isset($_POST['pmpro_seq_future']) )
            {
                $this->options->hidden = intval($_POST['pmpro_seq_future']);
                $this->dbg_log('save_settings(): POST value for settings->hidden: ' . $_POST['pmpro_seq_future'] );
            }
            elseif ( empty($this->options->hidden) )
                $this->options->hidden = 0;

            // Checkbox - not included during post/save if unchecked
            if (isset($_POST['hidden_pmpro_seq_lengthvisible']) )
            {
                $this->options->lengthVisible = intval($_POST['hidden_pmpro_seq_lengthvisible']);
                $this->dbg_log('save_settings(): POST value for settings->lengthVisible: ' . $_POST['hidden_pmpro_seq_lengthvisible']);
            }
            elseif (empty($this->options->lengthVisible)) {
                $this->dbg_log('Setting lengthVisible to default value (checked)');
                $this->options->lengthVisible = 1;
            }

            if ( isset($_POST['hidden_pmpro_seq_sortorder']) )
            {
                $this->options->sortOrder = intval($_POST['hidden_pmpro_seq_sortorder']);
                $this->dbg_log('save_settings(): POST value for settings->sortOrder: ' . $_POST['hidden_pmpro_seq_sortorder'] );
            }
            elseif (empty($this->options->sortOrder))
                $this->options->sortOrder = SORT_ASC;

            if ( isset($_POST['hidden_pmpro_seq_delaytype']) )
            {
                $this->options->delayType = esc_attr($_POST['hidden_pmpro_seq_delaytype']);
                $this->dbg_log('save_settings(): POST value for settings->delayType: ' . esc_attr($_POST['hidden_pmpro_seq_delaytype']) );
            }
            elseif (empty($this->options->delayType))
                $this->options->delayType = 'byDays';

            // options->showDelayAs
            if ( isset($_POST['hidden_pmpro_seq_showdelayas']) )
            {
                $this->options->showDelayAs = esc_attr($_POST['hidden_pmpro_seq_showdelayas']);
                $this->dbg_log('save_settings(): POST value for settings->showDelayAs: ' . esc_attr($_POST['hidden_pmpro_seq_showdelayas']) );
            }
            elseif (empty($this->options->showDelayAs))
                $this->options->delayType = PMPRO_SEQ_AS_DAYNO;

            if ( isset($_POST['hidden_pmpro_seq_offset']) )
            {
                $this->options->previewOffset = esc_attr($_POST['hidden_pmpro_seq_offset']);
                $this->dbg_log('save_settings(): POST value for settings->previewOffset: ' . esc_attr($_POST['hidden_pmpro_seq_offset']) );
            }
            elseif (empty($this->options->previewOffset))
                $this->options->previewOffset = 0;

            if ( isset($_POST['hidden_pmpro_seq_startwhen']) )
            {
                $this->options->startWhen = esc_attr($_POST['hidden_pmpro_seq_startwhen']);
                $this->dbg_log('save_settings(): POST value for settings->startWhen: ' . esc_attr($_POST['hidden_pmpro_seq_startwhen']) );
            }
            elseif (empty($this->options->startWhen))
                $this->options->startWhen = 0;

            // Checkbox - not included during post/save if unchecked
            if ( isset($_POST['pmpro_seq_sendnotice']) )
            {
                $this->options->sendNotice = intval($_POST['pmpro_seq_sendnotice']);

                if ( $this->options->sendNotice == 0 ) {

                    $this->stop_sending_user_notices();
                }

                $this->dbg_log('save_settings(): POST value for settings->sendNotice: ' . intval($_POST['pmpro_seq_sendnotice']) );
            }
            elseif (empty($this->options->sendNotice)) {
                $this->options->sendNotice = 1;
            }

            if ( isset($_POST['hidden_pmpro_seq_sendas']) )
            {
                $this->options->noticeSendAs = esc_attr($_POST['hidden_pmpro_seq_sendas']);
                $this->dbg_log('save_settings(): POST value for settings->noticeSendAs: ' . esc_attr($_POST['hidden_pmpro_seq_sendas']) );
            }
            else
                $this->options->noticeSendAs = PMPRO_SEQ_SEND_AS_SINGLE;

            if ( isset($_POST['hidden_pmpro_seq_noticetemplate']) )
            {
                $this->options->noticeTemplate = esc_attr($_POST['hidden_pmpro_seq_noticetemplate']);
                $this->dbg_log('save_settings(): POST value for settings->noticeTemplate: ' . esc_attr($_POST['hidden_pmpro_seq_noticetemplate']) );
            }
            else
                $this->options->noticeTemplate = 'new_content.html';

            if ( isset($_POST['hidden_pmpro_seq_noticetime']) )
            {
                $this->options->noticeTime = esc_attr($_POST['hidden_pmpro_seq_noticetime']);
                $this->dbg_log('save_settings() - noticeTime in settings: ' . $this->options->noticeTime);

                /* Calculate the timestamp value for the noticeTime specified (noticeTime is in current timezone) */
                $this->options->noticeTimestamp = $this->calculate_timestamp($settings->noticeTime);

                $this->dbg_log('save_settings(): POST value for settings->noticeTime: ' . esc_attr($_POST['hidden_pmpro_seq_noticetime']) );
            }
            else
                $this->options->noticeTime = '00:00';

            if ( isset($_POST['hidden_pmpro_seq_excerpt']) )
            {
                $this->options->excerpt_intro = esc_attr($_POST['hidden_pmpro_seq_excerpt']);
                $this->dbg_log('save_settings(): POST value for settings->excerpt_intro: ' . esc_attr($_POST['hidden_pmpro_seq_excerpt']) );
            }
            else
                $this->options->excerpt_intro = 'A summary of the post follows below:';

            if ( isset($_POST['hidden_pmpro_seq_fromname']) )
            {
                $this->options->fromname = esc_attr($_POST['hidden_pmpro_seq_fromname']);
                $this->dbg_log('save_settings(): POST value for settings->fromname: ' . esc_attr($_POST['hidden_pmpro_seq_fromname']) );
            }
            else
                $this->options->fromname = pmpro_getOption('from_name');

            if ( isset($_POST['hidden_pmpro_seq_dateformat']) )
            {
                $this->options->dateformat = esc_attr($_POST['hidden_pmpro_seq_dateformat']);
                $this->dbg_log('save_settings(): POST value for settings->dateformat: ' . esc_attr($_POST['hidden_pmpro_seq_dateformat']) );
            }
            else
                $this->options->dateformat = __('m-d-Y', 'pmprosequence'); // Default is MM-DD-YYYY (if translation supports it)

            if ( isset($_POST['hidden_pmpro_seq_replyto']) )
            {
                $this->options->replyto = esc_attr($_POST['hidden_pmpro_seq_replyto']);
                $this->dbg_log('save_settings(): POST value for settings->replyto: ' . esc_attr($_POST['hidden_pmpro_seq_replyto']) );
            }
            else
                $this->options->replyto = pmpro_getOption('from_email');

            if ( isset($_POST['hidden_pmpro_seq_subject']) )
            {
                $this->options->subject = esc_attr($_POST['hidden_pmpro_seq_subject']);
                $this->dbg_log('save_settings(): POST value for settings->subject: ' . esc_attr($_POST['hidden_pmpro_seq_subject']) );
            }
            else
                $this->options->subject = __('New Content ', 'pmprosequence');

            // $sequence->options = $settings;
            if ( $this->options->sendNotice == 1 ) {

                $this->dbg_log( 'save_settings(): Updating the cron job for sequence ' . $this->sequence_id );

                if (! $this->update_user_notice_cron() )
                    $this->dbg_log('save_settings() - Error configuring cron() system for sequence ' . $this->sequence_id, DEBUG_SEQ_CRITICAL);
            }

            // $this->dbg_log('save_settings() - Settings are now: ' . print_r($settings, true));

            // Save settings to WPDB
            return $this->save_sequence_meta($this->options, $sequence_id);
        }

        /********************************* Plugin Activation/Deactivation Functionality *******************/


        /**
         * Disable the WPcron job for the current sequence
         */
        public function stop_sending_user_notices() {

            $this->dbg_log("stop_sending_user_notices() - Removing alert notice hook for sequence # " . $this->sequence_id );

            wp_clear_scheduled_hook( 'pmpro_sequence_cron_hook', array( $this->sequence_id ) );
        }

        /**
         * Deactivate the plugin and clear our stuff.
         */
        public function deactivation() {

            global $pmpro_sequence_deactivating, $wpdb;
            $pmpro_sequence_deactivating = true;

            flush_rewrite_rules();

            // Easiest is to iterate through all Sequence IDs and set the setting to 'sendNotice == 0'

            $sql = "
		        SELECT *
		        FROM {$wpdb->posts}
		        WHERE post_type = 'pmpro_sequence'
	    	";

            $seqs = $wpdb->get_results( $sql );

            // Iterate through all sequences and disable any cron jobs causing alerts to be sent to users
            foreach($seqs as $s) {

                $this->init( $s->ID );

                if ( $this->options->sendNotice == 1 ) {

                    // Set the alert flag to 'off'
                    $this->options->sendNotice = 0;

                    // save meta for the sequence.
                    $this->save_sequence_meta();

                    wp_clear_scheduled_hook( 'pmpro_sequence_cron_hook', array( $s->ID ) );
                    $this->dbg_log('Deactivated email alert(s) for sequence ' . $s->ID);
                }
            }

            /* Unregister the default Cron job for new content alert(s) */
            wp_clear_scheduled_hook( 'pmpro_sequence_cron_hook' );
        }

        /**
         * Activation hook for the plugin
         * We need to flush rewrite rules on activation/etc for the CPTs.
         */
        public function activation()
        {
            if ( ! function_exists( 'pmpro_getOption' ) ) {

                $errorMessage = __( "The PMPro Sequence plugin requires the ", "pmprosequence" );
                $errorMessage .= "<a href='http://www.paidmembershipspro.com/' target='_blank' title='" . __("Opens in a new window/tab.", "pmprosequence" ) . "'>";
                $errorMessage .= __( "Paid Memberships Pro</a> membership plugin.<br/><br/>", "pmprosequence" );
                $errorMessage .= __( "Please install Paid Memberships Pro before attempting to activate this PMPro Sequence plugin.<br/><br/>", "pmprosequence");
                $errorMessage .= __( "Click the 'Back' button in your browser to return to the Plugin management page.", "pmprosequence" );
                wp_die($errorMessage);
            }

            PMProSequence::create_custom_post_type();
            flush_rewrite_rules();

            /* Search for existing pmpro_series posts & import */
            pmpro_sequence_import_all_PMProSeries();

            /* Register the default cron job to send out new content alerts */
            wp_schedule_event( current_time( 'timestamp' ), 'daily', 'pmpro_sequence_cron_hook' );

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
            global $pmpro_sequence_deactivating;

            if ( ! empty( $pmpro_sequence_deactivating ) ) {
                return false;
            }

            $defaultSlug = get_option( 'pmpro_sequence_slug', 'sequence' );

            $labels =  array(
                'name' => __( 'Sequences', 'pmprosequence'  ),
                'singular_name' => __( 'Sequence', 'pmprosequence' ),
                'slug' => 'pmpro_sequence',
                'add_new' => __( 'New Sequence', 'pmprosequence' ),
                'add_new_item' => __( 'New Sequence', 'pmprosequence' ),
                'edit' => __( 'Edit Sequence', 'pmprosequence' ),
                'edit_item' => __( 'Edit Sequence', 'pmprosequence'),
                'new_item' => __( 'Add New', 'pmprosequence' ),
                'view' => __( 'View Sequence', 'pmprosequence' ),
                'view_item' => __( 'View This Sequence', 'pmprosequence' ),
                'search_items' => __( 'Search Sequences', 'pmprosequence' ),
                'not_found' => __( 'No Sequence Found', 'pmprosequence' ),
                'not_found_in_trash' => __( 'No Sequence Found In Trash', 'pmprosequence' )
            );

            $error = register_post_type('pmpro_sequence',
                array( 'labels' => apply_filters( 'pmpro-sequence-cpt-labels', $labels ),
                    'public' => true,
                    'show_ui' => true,
                    'show_in_menu' => true,
                    'publicly_queryable' => true,
                    'hierarchical' => true,
                    'supports' => array('title','editor','thumbnail','custom-fields','author'),
                    'can_export' => true,
                    'show_in_nav_menus' => true,
                    'rewrite' => array(
                        'slug' => apply_filters('pmpro-sequence-cpt-slug', $defaultSlug),
                        'with_front' => false
                    ),
                    'has_archive' => apply_filters('pmpro-sequence-cpt-archive-slug', 'sequences')
                )
            );

            if (! is_wp_error($error) )
                return true;
            else {
                PMProSequence::dbg_log('Error creating post type: ' . $error->get_error_message(), DEBUG_SEQ_CRITICAL);
                wp_die($error->get_error_message());
                return false;
            }
        }

        /**
         * Configure & display the icon for the Sequence Post type (in the Dashboard)
         */
        function post_type_icon() {
            ?>
            <style>
                @font-face {
                    font-family: FontAwesome;
                    src: url(https://maxcdn.bootstrapcdn.com/font-awesome/4.4.0/css/font-awesome.min.css);
                }

                #menu-posts-pmpro_sequence .menu-top  div.wp-menu-image:before {
                    font-family:  FontAwesome !important;
                    content: '\f160';
                }
            </style>
        <?php
        }

        public function register_user_scripts() {

            global $e20r_sequence_editor_page;
	        global $load_pmpro_sequence_script;
            global $post;

            if ( ! isset( $post->content ) ) {

                return;
            }

            $this->dbg_log("Running register_user_scripts()");

            $foundShortcode = has_shortcode( $post->post_content, 'sequence_links');

            $this->dbg_log("'sequence_links' shortcode present? " . ( $foundShortcode ? 'Yes' : 'No') );

            if ( ( true == $foundShortcode ) || ( $this->get_post_type() == 'pmpro_sequence' ) ) {

	            $load_pmpro_sequence_script = true;

                $this->dbg_log("Loading client side javascript and CSS");
                wp_register_script('pmpro-sequence-user', PMPRO_SEQUENCE_PLUGIN_URL . 'js/pmpro-sequences.js', array('jquery'), '1.0', true);

                wp_register_style( 'pmpro-sequence', PMPRO_SEQUENCE_PLUGIN_URL . 'css/pmpro_sequences.css' );
                wp_enqueue_style( "pmpro-sequence" );

                wp_localize_script('pmpro-sequence-user', 'pmpro_sequence',
                    array(
                        'ajaxurl' => admin_url('admin-ajax.php'),
                    )
                );
            }
	        else {
                $load_pmpro_sequence_script = false;
                $this->dbg_log("Didn't find the expected shortcode... Not loading client side javascript and CSS");
            }

        }

        public function register_admin_scripts() {

            $this->dbg_log("Running register_admin_scripts()");

            wp_register_script('select2', '//cdnjs.cloudflare.com/ajax/libs/select2/3.5.2/select2.min.js', array( 'jquery' ), '3.5.2' );
            wp_register_script('pmpro-sequence-admin', PMPRO_SEQUENCE_PLUGIN_URL . 'js/pmpro-sequences-admin.js', array( 'jquery', 'select2' ), null, true);

            wp_register_style( 'select2', '//cdnjs.cloudflare.com/ajax/libs/select2/3.5.2/select2.min.css', '', '3.5.2', 'screen');
            wp_register_style( 'pmpro-sequence', PMPRO_SEQUENCE_PLUGIN_URL . 'css/pmpro_sequences.css' );

            /* Localize ajax script */
            wp_localize_script('pmpro-sequence-admin', 'pmpro_sequence',
                array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'lang' => array(
                        'alert_not_saved' => __("Error: This sequence needs to be saved before you can send alerts", "pmprosequence"),
                        'save' => __('Update Sequence', 'pmprosequence'),
                        'saving' => __('Saving', 'pmprosequence'),
                        'saveSettings' => __('Update Settings', 'pmprosequence'),
                        'delay_change_confirmation' => __('Changing the delay type will erase all existing posts or pages in the Sequence list. (Cancel if your are unsure)', 'pmprosequence'),
                        'saving_error_1' => __('Error saving sequence post [1]', 'pmprosequence'),
                        'saving_error_2' => __('Error saving sequence post [2]', 'pmprosequence'),
                        'remove_error_1' => __('Error deleting sequence post [1]', 'pmprosequence'),
                        'remove_error_2' => __('Error deleting sequence post [2]', 'pmprosequence'),
                        'undefined' => __('Not Defined', 'pmprosequence'),
                        'unknownerrorrm' => __('Unknown error removing post from sequence', 'pmprosequence'),
                        'unknownerroradd' => __('Unknown error adding post to sequence', 'pmprosequence'),
                        'daysLabel' => __('Delay', 'pmprosequence'),
                        'daysText' => __('Days to delay', 'pmprosequence'),
                        'dateLabel' => __('Avail. on', 'pmprosequence'),
                        'dateText' => __('Release on (YYYY-MM-DD)', 'pmprosequence'),
                    )
                )
            );

            wp_enqueue_style( "pmpro-sequence" );
            wp_enqueue_style( "select2" );

            wp_enqueue_script( 'select2' );
            wp_enqueue_script( 'pmpro-sequence-admin' );
        }

        /**
         * Add javascript and CSS for end-users.
         */
        public function enqueue_user_scripts() {

            global $load_pmpro_sequence_script;
	        global $post;

            if ( $load_pmpro_sequence_script !== true ) {
                return;
            }

            if ( ! isset($post->post_content) ) {

                return;
            }

	        $foundShortcode = has_shortcode( $post->post_content, 'sequence_links');

	        $this->dbg_log("enqueue_user_scripts() - 'sequence_links' shortcode present? " . ( $foundShortcode ? 'Yes' : 'No') );
            wp_register_script('pmpro-sequence-user', PMPRO_SEQUENCE_PLUGIN_URL . 'js/pmpro-sequences.js', array('jquery'), '1.0', true);

            wp_register_style( 'pmpro-sequence', PMPRO_SEQUENCE_PLUGIN_URL . 'css/pmpro_sequences.css' );
            wp_enqueue_style( "pmpro-sequence" );

            wp_localize_script('pmpro-sequence-user', 'pmpro_sequence',
                array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                )
            );

            wp_print_scripts( 'pmpro-sequence-user' );
        }

        /**
         * Load all JS & CSS for Admin page
         */
        function enqueue_admin_scripts( $hook ) {

	        global $post;

	        if ( ! isset( $post->post_type ) )  {

		        return;
	        }

            if ( ($post->post_type == 'pmpro_sequence') ||
                 ( $hook == 'edit.php' || $hook == 'post.php' || $hook == 'post-new.php' ) ) {

                $this->dbg_log("Loading admin scripts & styles for PMPro Sequence");
                $this->register_admin_scripts();
            }

            $this->dbg_log("End of loading admin scripts & styles");
        }

        /**
         * Register any and all widgets for PMPro Sequence
         */
        public function register_widgets() {

            // Add widget to display a summary for the most recent post/page
            // in the sequence for the logged in user.
            register_widget( 'SeqRecentPostWidget' );
        }

        /**
         * Register any and all shortcodes for PMPro Sequence
         */
        public function register_shortcodes() {

            // Generates paginated list of links to sequence members
            add_shortcode( 'sequence_links', array( &$this, 'sequence_links_shortcode' ) );
            add_shortcode( 'sequence_opt_in', array( &$this, 'sequence_optin_shortcode' ) );
        }

      /**
        * Shortcode to display notification opt-in checkbox
        * @param string $attributes - Shortcode attributes (required attribute is 'sequence=<sequence_id>')
        *
        * @return string - HTML of the opt-in
        */
        public function sequence_optin_shortcode( $attributes ) {

            $sequence = null;

            extract( shortcode_atts( array(
                'sequence' => 0,
            ), $attributes ) );

            $this->init( $sequence );
            return $this->view_user_notice_opt_in();
        }

        /**
         * Generates a formatted list of posts in the specified sequence.
         *
         * @param $attributes -- Shortcode attributes
         *
         * @return string -- HTML output containing the list of posts for the specified sequence(s)
         */
        public function sequence_links_shortcode( $attributes ) {

            global $current_user, $load_pmpro_sequence_script;

            $load_pmpro_sequence_script = true;

            // To avoid errors in development tool
            $highlight = false;
            $button = false;
            $scrollbox = false;
            $pagesize = 30;
            $id = 0;
            $title = null;

            extract( shortcode_atts( array(
                'id' => 0,
                'pagesize' => 30,
                'title' => '',
                'button' => false,
                'highlight' => false,
                'scrollbox' => false,
            ), $attributes ) );

            if ( $pagesize == 0 ) {

                $pagesize = 30; // Default
            }

            if ( ( $id == 0 ) && ( $this->sequence_id == 0 ) ) {

                global $wp_query;

                // Try using the current WP post ID
                if (! empty( $wp_query->post->ID ) ) {

                    $id = $wp_query->post->ID;
                }
                else {

                    return ''; // No post given so returning no info.
                }
            }
            $this->dbg_log("We're given the ID of: {$id} ");

	        // Make sure the sequence exists.
	        if ( ! $this->sequence_exists( $id ) ) {

		        $this->dbg_log("shortcode() - The requested sequence (id: {$id}) does not exist", DEBUG_SEQ_WARNING );
		        $errorMsg = '<p class="error" style="text-align: center;">The specified PMPro Sequence was not found. <br/>Please report this error to the webmaster.</p>';

		        return apply_filters( 'pmpro-sequence-not-found-msg', $errorMsg );
	        }

            $this->init( $id );

            $this->dbg_log("shortcode() - Ready to build link list for sequence with ID of: " . $id);

            if ( $this->has_post_access( $current_user->ID, $id, false ) ) {

                return $this->create_sequence_list( $highlight, $pagesize, $button, $title, $scrollbox );
            }
            else {

                return '';
            }
        }

        /**
         * Load and use L18N based text (if available)
         */
        public function load_textdomain() {

            $domain = "pmprosequence";

            $locale = apply_filters( "plugin_locale", get_locale(), $domain );

            $mofile = "{$domain}-{$locale}.mo";

            $mofile_local = plugin_basename(__FILE__) . "/../languages/";
            $mofile_global = WP_LANG_DIR . "/pmpro-sequence/" . $mofile;

            load_textdomain( $domain, $mofile_global );
            load_plugin_textdomain( $domain, FALSE, $mofile_local );
        }

        /**
         * Return error if an AJAX call is attempted by a user who hasn't logged in.
         */
        public function unprivileged_ajax_error() {

            $this->dbg_log('Unprivileged ajax call attempted', DEBUG_SEQ_CRITICAL);

            wp_send_json_error( array(
                'message' => __('You must be logged in to edit PMPro Sequences', 'pmprosequence')
            ) );
        }

        public function send_user_alert_notices() {

            $sequence_id = intval($_REQUEST['post']);

            $this->dbg_log( 'send_user_alert_notices() - Will send alerts for sequence #' . $sequence_id );

            do_action( 'pmpro_sequence_cron_hook', $sequence_id );

            $this->dbg_log( 'send_user_alert_notices() - Completed action for sequence #' . $sequence_id );
            wp_redirect('/wp-admin/edit.php?post_type=pmpro_sequence');
        }

        public function send_alert_notice_from_menu( $actions, $post ) {

            if ( ( 'pmpro_sequence' == $post->post_type ) && current_user_can('edit_posts' ) ) {

                $options = $this->get_options( $post->ID );

                if ( 1 == $options->sendNotice ) {

                    $this->dbg_log("send_alert_notice_from_menu() - Adding send action");
                    $actions['duplicate'] = '<a href="admin.php?post=' . $post->ID . '&amp;action=send_user_alert_notices&amp;pmpro_sequence_id=' . $post->ID .'" title="' .__("Send user alerts", "e20rtracker" ) .'" rel="permalink">' . __("Send Notices", "e20rtracker") . '</a>';
                }
            }

            return $actions;
        }

        /**
         * Loads actions & filters for the plugin.
         */
        public function load_actions()
        {

            // Load filters
            // add_filter("pmpro_after_phpmailer_init", array(&$this, "email_body"));
            add_filter('pmpro_sequencepost_types', array(&$this, 'included_cpts'));

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
            add_action("wp_enqueue_scripts", array(&$this, 'register_user_scripts'));
            add_action("wp_footer", array( &$this, 'enqueue_user_scripts') );

            add_action("admin_enqueue_scripts", array(&$this, 'enqueue_admin_scripts'));
            add_action('admin_head', array(&$this, 'post_type_icon'));

            // Load metaboxes for editor(s)
            add_action('add_meta_boxes', array(&$this, 'post_metabox'));

            // Load add/save actions
            add_action('admin_notices', array(&$this, 'display_error'));
            // add_action( 'save_post', array( &$this, 'post_save_action' ) );
            add_action('post_updated', array(&$this, 'post_save_action'));

            add_action('admin_menu', array(&$this, "define_metaboxes"));
            add_action('save_post', array(&$this, 'save_post_meta'), 10, 2);

            add_action('widgets_init', array(&$this, 'register_widgets'));

            // Add AJAX handlers for logged in users/admins
            add_action("wp_ajax_pmpro_sequence_add_post", array(&$this, "add_post_callback"));
            add_action('wp_ajax_pmpro_sequence_update_post_meta', array(&$this, 'update_delay_post_meta_callback'));
            add_action('wp_ajax_pmpro_rm_sequence_from_post', array(&$this, 'rm_sequence_from_post_callback'));
            add_action("wp_ajax_pmpro_sequence_rm_post", array(&$this, "rm_post_callback"));
            add_action('wp_ajax_pmpro_sequence_clear', array(&$this, 'sequence_clear_callback'));
            add_action('wp_ajax_pmpro_send_notices', array(&$this, 'sendalert_callback'));
            add_action('wp_ajax_pmpro_sequence_save_user_optin', array(&$this, 'optin_callback'));
            add_action('wp_ajax_pmpro_save_settings', array(&$this, 'settings_callback'));

            // Add AJAX handlers for unprivileged admin operations.
            add_action('wp_ajax_nopriv_pmpro_sequence_add_post', array(&$this, 'unprivileged_ajax_error'));
            add_action('wp_ajax_nopriv_pmpro_sequence_update_post_meta', array(&$this, 'unprivileged_ajax_error'));
            add_action('wp_ajax_nopriv_pmpro_rm_sequence_from_post', array(&$this, 'unprivileged_ajax_error'));
            add_action('wp_ajax_nopriv_pmpro_sequence_rm_post', array(&$this, 'unprivileged_ajax_error'));
            add_action('wp_ajax_nopriv_pmpro_sequence_clear', array(&$this, 'unprivileged_ajax_error'));
            add_action('wp_ajax_nopriv_pmpro_send_notices', array(&$this, 'unprivileged_ajax_error'));
            add_action('wp_ajax_nopriv_pmpro_sequence_save_user_optin', array(&$this, 'unprivileged_ajax_error'));
            add_action('wp_ajax_nopriv_pmpro_save_settings', array(&$this, 'unprivileged_ajax_error'));

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
		 * Determines if a post, identified by the specified ID, exist
		 * within the WordPress database.
		 *
		 * @param    int    $id    The ID of the post to check
		 * @return   bool          True if the post exists; otherwise, false.
		 * @since    1.0.0
		 */
		private function sequence_exists( $id ) {

			return is_string( get_post_status( $id ) );
		}
    }
