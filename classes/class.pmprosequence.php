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
		private $posts; // List of posts
		private $post; // Individual post
		private $error = null;

        /**
         * Constructor for the PMProSequence class
         *
         * @param null $id -- The ID of the sequence to load/construct
         * @return object -- ID of the sequence loaded/constructed.
         *
         * @access public
         */
        function __construct($id = null)
		{
            if ( is_null( $id ) && ( $this->sequence_id == 0 ) ) {
                // Have no sequence ID to play off of..
                dbgOut('No sequence ID or options defined! Checking against global variables');
                global $wp_query;

                if ( $wp_query->post->ID ) {

                    dbgOut( 'Found Post ID and loading options if not already loaded ' . $wp_query->post->ID );
                    $this->sequence_id = $wp_query->post->ID;
                } else
                    return false; // ERROR. No sequence ID provided.
            }
            elseif ( ! is_null( $id ) )
                $this->sequence_id = $this->getSequenceByID($id);
			else
                $this->sequence_id = $id;

            dbgOut('__construct() - Loading the "' . get_the_title($this->sequence_id) . '" sequence');

            // Set options for the sequence
            $this->options = $this->fetchOptions($this->sequence_id);

			return $this->sequence_id;
		}

        /**
         * Fetches the post data for this sequence
         *
         * @param $id -- ID of sequence to fetch data for
         * @return bool | int -- The ID of the sequence or false if unsuccessful
         */
        public function getSequenceByID($id)
		{
			$this->post = get_post($id);

			if(!empty($this->post->ID))
	        {
				$this->id = $id;
	        }
	        else
				$this->id = false;

			return $this->id;
		}

	    /**
	     * Fetch any options for this specific sequence from the database (stored as post metadata)
	     * Use default options if the sequence ID isn't supplied*
	     *
	     * @param int $sequence_id - The Sequence ID to fetch options for
	     * @return mixed -- Returns array of options if options were successfully fetched & saved.
	     */
	    public function fetchOptions( $sequence_id = 0 )
	    {
            // Does the ID differ from the one this object has stored already?
            if ( ( $this->sequence_id != 0 ) && ( $this->sequence_id != $sequence_id ))
            {
                dbgOut('fetchOptions() - ID defined already but we were given a different sequence ID');
                $this->sequence_id = $sequence_id;
            }
            elseif ($this->sequence_id == 0)
            {
                // This shouldn't be possible... (but never say never!)
	            dbgOut("The defined sequence ID is 0 so we'll set it to " . $sequence_id);
                $this->sequence_id = $sequence_id;
            }

	        // Check that we're being called in context of an actual Sequence 'edit' operation
	        dbgOut('fetchOptions(): Loading settings from DB for (' . $this->sequence_id . ') "' . get_the_title($this->sequence_id) . '"');

	        $settings = get_post_meta($this->sequence_id, '_pmpro_sequence_settings', false);
	        $options = $settings[0];

	        // Check whether we need to set any default variables for the settings
	        if ( empty($options) ) {

	            dbgOut('fetchOptions(): No settings found. Using defaults');
	            $options = $this->defaultOptions();
	        }

            $this->options = $options;

	        return $options;
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
	    public function defaultOptions()
	    {
	        $settings = new stdClass();

	        $settings->hidden =  0; // 'hidden' (Show them)
	        $settings->lengthVisible = 1; //'lengthVisible'
	        $settings->sortOrder = SORT_ASC; // 'sortOrder'
	        $settings->delayType = 'byDays'; // 'delayType'
            $settings->showDelayAs = PMPRO_SEQ_AS_DAYNO; // How to display the time until available
            $settings->previewOffset = 0; // How many days into the future the sequence should allow somebody to see.
	        $settings->startWhen =  0; // startWhen == immediately (in current_time('timestamp') + n seconds)
		    $settings->sendNotice = 1; // sendNotice == Yes
		    $settings->noticeTemplate = 'new_content.html'; // Default plugin template
		    $settings->noticeTime = '00:00'; // At Midnight (server TZ)
	        $settings->noticeTimestamp = current_time('timestamp'); // The current time (in UTC)
	        $settings->excerpt_intro = __('A summary of the post follows below:', 'pmprosequence');
		    $settings->replyto = pmpro_getOption("from_email");
		    $settings->fromname = pmpro_getOption("from_name");
		    $settings->subject = __('New: ', 'pmprosequence');
            $settings->dateformat = __('m-d-Y', 'pmprosequence'); // Using American MM-DD-YYYY format.

	        $this->options = $settings; // Save as options for this sequence

	        return $settings;
	    }

	    /**
	     * Save the settings as metadata for the sequence
	     *
	     * @param $post_id -- ID of the sequence these options belong to.
	     * @return int | mixed - Either the ID of the Sequence or its content
         *
         * @access public
	     */
	    public function pmpro_sequence_meta_save( $post_id )
	    {
		    global $post;

	        // Check that the function was called correctly. If not, just return
	        if(empty($post_id)) {
		        dbgOut('pmpro_sequence_meta_save(): No post ID supplied...');
		        return false;
	        }

		    dbgOut('Attempting to save post meta');

		    if ( wp_is_post_revision( $post_id ) )
			    return $post_id;

		    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
			    return $post_id;
		    }

		    if ( $post->post_type !== 'pmpro_sequence' )
			    return $post_id;

		    $sequence = new PMProSequence($post_id);

	        dbgOut('pmpro_sequence_meta_save(): Saving settings for sequence ' . $post_id);
	        // dbgOut('From Web: ' . print_r($_REQUEST, true));

	        // OK, we're authenticated: we need to find and save the data
	        if ( isset($_POST['pmpro_sequence_settings_noncename']) ) {

		        dbgOut( 'Have to load new instance of Sequence class' );

		        if ( ! $sequence->options ) {
			        $sequence->options = $this->defaultOptions();
		        }

		        if ( ($retval = pmpro_sequence_settings_save( $post_id, $sequence )) === true ) {

			        dbgOut( 'pmpro_sequence_meta_save(): Saved metadata for sequence #' . $post_id );

			        return true;
		        }
		        else
			        return false;

	        }

		    return false; // Default
	    }

		/**
		 * Update the when we're supposed to run the New Content Notice cron job for this sequence.
	     *
         * @access public
		 */
		public function updateNoticeCron()
		{
			$prevScheduled = false;
			try {

	            // Check if the job is previously scheduled. If not, we're using the default cron schedule.
	            if (false !== ($timestamp = wp_next_scheduled( 'pmpro_sequence_cron_hook', array($this->sequence_id) ) )) {

				    // Clear old cronjob for this sequence
		            dbgOut('Current cron job for sequence # ' . $this->sequence_id . ' scheduled for ' . $timestamp);
		            $prevScheduled = true;

				    // wp_clear_scheduled_hook($timestamp, 'pmpro_sequence_cron_hook', array( $this->sequence_id ));
	            }

				dbgOut('updateNoticeCron() - Next scheduled at (timestamp): ' . print_r(wp_next_scheduled('pmpro_sequence_cron_hook', array($this->sequence_id)), true));

				// Set time (what time) to run this cron job the first time.
				dbgOut('updateNoticeCron() - Alerts for sequence #' . $this->sequence_id . ' at ' . date('Y-m-d H:i:s', $this->options->noticeTimestamp) . ' UTC');

				if  ( ($prevScheduled) &&
				      ($this->options->noticeTimestamp != $timestamp) ) {

					dbgOut('updateNoticeCron() - Admin changed when the job is supposed to run. Deleting old cron job for sequence w/ID: ' . $this->sequence_id);
					wp_clear_scheduled_hook( 'pmpro_sequence_cron_hook', array($this->sequence_id) );

					// Schedule a new event for the specified time
					if ( false === wp_schedule_event(
						                $this->options->noticeTimestamp,
						                'daily',
						                'pmpro_sequence_cron_hook',
						                array( $this->sequence_id )
									)) {

						$this->setError( printf( __('Could not schedule new content alert for %s', 'pmprosequence'), $this->options->noticeTime) );
				        dbgOut("updateNoticeCron() - Did not schedule the new cron job at ". $this->options->noticeTime . " for this sequence (# " . $this->sequence_id . ')');
						return false;
				     }
				}
				elseif (! $prevScheduled)
					wp_schedule_event($this->options->noticeTimestamp, 'daily', 'pmpro_sequence_cron_hook', array($this->sequence_id));
				else
					dbgOut("updateNoticeCron() - Timestamp didn't change so leave the schedule as-is");

				// Validate that the event was scheduled as expected.
				$ts = wp_next_scheduled( 'pmpro_sequence_cron_hook', array($this->sequence_id) );

				dbgOut('updateNoticeCron() - According to WP, the job is scheduled for: ' . date('d-m-Y H:i:s', $ts) . ' UTC and we asked for ' . date('d-m-Y H:i:s', $this->options->noticeTimestamp) . ' UTC');

				if ($ts != $this->options->noticeTimestamp)
					dbgOut("updateNoticeCron() - Timestamp for actual cron entry doesn't match the one in the options...");
			}
			catch (Exception $e) {
				// echo 'Error: ' . $e->getMessage();
				dbgOut('Error updating cron job(s): ' . $e->getMessage());

				if ( is_null($this->getError()) )
					$this->setError("Exception in updateNoticeCron(): " . $e->getMessage());

				return false;
			}

			return true;
		}

        /**
         * Access the private $error value
         *
         * @return string|null -- Error message or NULL
         * @access public
         */
        public function getError() {
			return $this->error;
		}

        /**
         * Set the private $error value
         *
         * @param $msg -- The error message to set
         *
         * @access public
         */
        public function setError( $msg ) {
			$this->error = $msg;
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
		 */
		public function isAfterOptIn( $user_id, $optinTS, $post ) {

			 // = $user_settings->sequence[ $this->sequence_id ]->optinTS;

			if ($optinTS != -1) {

				dbgOut( 'isAfterOptIn() -- User: ' . $user_id . ' Optin TS: ' . $optinTS .
				        ', Optin Date: ' . date( 'Y-m-d', $optinTS )
				);

                $delayTS = $this->postDelayAsTS( $post->delay, $user_id );

				// Compare the Delay to the optin (subtract 24 hours worth of time from the opt-in TS)
				if ( $delayTS >= ($optinTS - (3600 * 24)) ) {

					dbgOut('isAfterOptIn() - This post SHOULD be allowed to be alerted on');
					return true;
				} else {
					dbgOut('isAfterOptIn() - This post should NOT be allowed to be alerted on');
					return false;
				}
			} else {
				dbgOut('isAfterOptIn() - Negative opt-in timestamp value. The user  (' . $user_id . ') does not want to receive alerts');
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
         * @access public
         */
        public function postDelayAsTS($delay, $user_id = null, $level_id = null) {

			$delayTS = current_time('timestamp', true); // Default is 'now'

			$startTS = pmpro_getMemberStartdate($user_id, $level_id);

			switch ($this->options->delayType) {
				case 'byDays':
					$delayTS = strtotime( '+' . $delay . ' days', $startTS);
					dbgOut('postDelayAsTS() -  byDays:: delay = ' . $delay . ', delayTS is now: ' . $delayTS . ' = ' . date('Y-m-d', $startTS) . ' vs ' . date('Y-m-d', $delayTS));
					break;

				case 'byDate':
                    $delayTS = strtotime( $delay );
					dbgOut('postDelayAsTS() -  byDate:: delay = ' . $delay . ', delayTS is now: ' . $delayTS . ' = ' . date('Y-m-d', $startTS) . ' vs ' . date('Y-m-d', $delayTS));
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
	    public function calculateTimestamp( $timeString )
	    {
		    // Use local time (not UTC) for 'current time' at server location
		    // This is what Wordpress apparently uses (at least in v3.9) for wp-cron.
		    $timestamp = current_time('timestamp');

		    try {
			    /* current time & date */
	            $schedHour = date( 'H', strtotime($timeString));
			    $schedMin = date('i', strtotime($timeString));

	            $nowHour = date('H', $timestamp);
			    $nowMin = date('i', $timestamp);

			    dbgOut('calculateTimestamp() - Timestring: ' . $timeString . ', scheduled Hour: ' . $schedHour . ' and current Hour: ' .$nowHour );

	            /*
	             *  Using these to decide whether or not to assume 'today' or 'tomorrow' for initial schedule for
			     * this cron() job.
	             *
			     * If the admin attempts to schedule a job that's less than 30 minutes away, we'll schedule it for tomorrow.
	             */
	            $hourDiff = $schedHour - $nowHour;
			    $hourDiff += ( ( ($hourDiff == 0) && (($schedMin - $nowMin) <= 0 )) ? 0 : 1);

	            if ( $hourDiff >= 1 ) {
	                dbgOut('calculateTimestamp() - Assuming current day');
	                $when = ''; // Today
	            }
	            else {
		            dbgOut('calculateTimestamp() - Assuming tomorrow');
	                $when = 'tomorrow ';
	            }
			    /* Create the string we'll use to generate a timestamp for cron() */
			    $timeInput = $when . $timeString . ' ' . get_option('timezone_string');
			    $timestamp = strtotime($timeInput);
		    }
		    catch (Exception $e)
		    {
			    dbgOut('calculateTimestamp() -- Error calculating timestamp: : ' . $e->getMessage());
		    }

	        return $timestamp;
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
        public function addPost($post_id, $delay)
		{

	        if (! $this->isValidDelay($delay) )
	        {
	            dbgOut('addPost(): Admin specified an invalid delay value for post: ' . ( empty($post_id) ? 'Unknown' :  $post_id) );
	            $this->error = printf(__('Invalid delay value - %s', 'pmprosequence'), $delay);
	            return false;
	        }

			if(empty($post_id) || !isset($delay))
			{
				$this->error = __("Please enter a value for post and delay", 'pmprosequence');
	            dbgOut('addPost(): No Post ID or delay specified');
				return false;
			}

	        dbgOut('addPost(): Post ID: ' . $post_id . ' and delay: ' . $delay);

			$post = get_post($post_id);

			if(empty($post->ID))
			{
				$this->error = __("A post with that id does not exist", 'pmprosequence');
	            dbgOut('addPost(): No Post with ' . $post_id . ' found');
				return false;
			}

			$this->getPosts();

			//remove any old post with this id
			if($this->hasPost($post_id))
				$this->removePost($post_id);

			// Add post
			$temp = new stdClass();
			$temp->id = $post_id;
			$temp->delay = $delay;

			// TODO: Add _sequence_post_{post_id} = {$delay} meta option for this post.
			// Or we could go with loading the posts (correctly) when needed (to allow for pagination)
			/* Only add the post if it's not already present. */
			if (! in_array($temp->id, $this->posts))
				$this->posts[] = $temp;

			//sort
	        dbgOut('addPost(): Sorting the Sequence by delay');
			usort($this->posts, array("PMProSequence", "sortByDelay"));

			//save
			update_post_meta($this->sequence_id, "_sequence_posts", $this->posts);

			//Get any previously existing sequences this post/page is linked to
			$post_sequence = get_post_meta($post_id, "_post_sequences", true);

	        // Is there any previously saved sequence ID found for the post/page?
			if(empty($post_sequence))
	        {
	            dbgOut('addPost(): Not previously defined sequence(s) found for this post (ID: ' . $post_id . ')');
	            $post_sequence = array($this->id);
	        }
	        else
	        {
	            dbgOut('addPost(): Post/Page w/id ' . $post_id . ' belongs to more than one sequence already: ' . print_r($post_sequence, true));

	            if ( !is_array($post_sequence) ) {

	                // dbgOut('AddPost(): Previously defined sequence(s) found for this post (ID: ' . $post_id . '). Sequence data: ' . print_r($post_sequence, true));
	                dbgOut('addPost(): Not (yet) an array of posts. Adding the single new post to a new array');
	                $post_sequence = array($this->id);
	            }
	            else {

	                // Bug Fix: Never checked if the Post/Page ID was already listed in the sequence.
		            $tmp = array_count_values($post_sequence);
		            $cnt = $tmp[$this->id];

	                if ( $cnt == 0 ) {

	                    // This is the first sequence this post is added to
	                    $post_sequence[] = $this->id;
	                    dbgOut('addPost(): Appended post (ID: ' . $temp->id . ') to sequence ' . $this->id);

	                }
	                else {

		                // Check whether there are repeat entries for the current sequence
		                if ($cnt > 1) {

			                // There are so get rid of the extras (this is a backward compatibility feature due to a previous bug.)
			                dbgOut('addPost() - More than one entry in the array. Clean it up!');

			                $clean = array_unique( $post_sequence );

			                dbgOut('addPost() - Cleaned array: ' . print_r($clean, true));
			                $post_sequence = $clean;
		                }
	                }
	            }

	        }

			//save
			update_post_meta($post_id, "_post_sequences", $post_sequence);
	        dbgOut('addPost(): Post/Page list updated and saved');

			return true;
	    }

	    /**
	     * Validates that the value received follows a valid "delay" format for the post/page sequence
	     *
	     * @param $delay (string) - The specified post delay value
	     * @return bool - Delay is recognized (parseable).
         *
         * @access public
	     */
	    public function isValidDelay( $delay )
	    {
	        dbgOut('isValidDelay(): Delay value is: ' . $delay);

	        switch ($this->options->delayType)
	        {
	            case 'byDays':
	                dbgOut('isValidDelay(): Delay configured as "days since membership start"');
	                return ( is_numeric( $delay ) ? true : false);
	                break;

	            case 'byDate':
	                dbgOut('isValidDelay(): Delay configured as a date value');
	                return ( $this->isValidDate( $delay ) ? true : false);
	                break;

	            default:
	                dbgOut('isValidDelay(): Not a valid delay value, based on config');
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
         * @access public
	     */
	    public function isValidDate( $data )
	    {
		    // Fixed: isValidDate() needs to support all expected date formats...
            if ( false === strtotime( $data ) ) {

                return false;
            }

            return true;

            /*
                        if ( preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $data) ) {
                            return true;
                        }
            */
	    }

        /**
         * Load the private class variable $posts with the list of posts belonging to this sequence
         *
         * @param bool $force -- Ignore the cache and force fetch from the DB
         * @return mixed -- Returns the aray of posts belonging to this sequence
         *
         * @access public
         */
        public function getPosts($force = false)
		{
			if(!isset($this->posts) || $force) {
				// The list of posts (post IDs & delays from the post meta )
				$this->posts = get_post_meta( $this->sequence_id, "_sequence_posts", true );
				//TODO: Convert the postmeta for _sequence_posts into something that can be read directly by $wpdb
				/*
				 * Idea: create sequence_post_{id} = {delay}
				 */
				// TODO: Convert post meta key '_sequence_post_delay_{$post_id}' = {$delay} into an array() of stdObjects

			}


			return $this->posts;
		}

		/**
         * Returns true if the post is found
         *
		 * @param $post_id (int) -- Page/post ID to check for inclusion in this sequence.
		 *
		 * @return bool -- True if the post is already included in the sequence. False otherwise
         *
         * @access private
		 */
		private function hasPost($post_id)
		{
			$this->getPosts();

			// TODO: Add search for {sequence_id}_sequence_post_{post_id} = {$delay} meta option

			if(empty($this->posts))
				return false;

			foreach($this->posts as $key => $post)
			{
				if($post->id == $post_id) {
					dbgOut('Post # ' . $post_id . ' is found in this sequence.');
					return true;
				}
			}

			return false;
		}

        /**
         * Test whether a post belongs to a sequence & return the Post ID if so
         * @param $post_id
         * @return null
         */
        public function get_postDetails( $post_id ) {

            if ( $this->hasPost($post_id) !== false ) {

                foreach($this->posts as $key => $post)
                {
                    if($post->id == $post_id) {
	                    dbgOut("get_postDetails() - Found the post we're looking for");
                        return $post;
                    }
                }
            }
            else
                return null;
        }

        /**
         * Removes a post from the list of posts belonging to this sequence
         *
         * @param $post_id -- The ID of the post to remove from the sequence
         * @return bool - returns TRUE if the post was removed and the metadata for the sequence was updated successfully
         *
         * @access public
         */
        public function removePost($post_id)
		{
			if(empty($post_id))
				return false;

			$this->getPosts();

			if(empty($this->posts))
				return true;

			//remove this post from the sequence
			foreach($this->posts as $i => $post)
			{
				if($post->id == $post_id)
				{
					unset($this->posts[$i]);
					$this->posts = array_values($this->posts);
					update_post_meta($this->id, "_sequence_posts", $this->posts);
					break;	//assume there is only one
				}
			}

			// TODO: Remove {sequence_id}_sequence_post_{post_id} = {$delay} meta for this post.

			// Remove post from user settings...
			// Remove the post ($post_id) from all cases where a User has been notified.
			$this->removeNotifiedFlagForPost($post_id);

			// Remove the sequence id from the post's metadata
			$post_sequence = get_post_meta($post_id, "_post_sequences", true);

			if(is_array($post_sequence) && ($key = array_search($this->id, $post_sequence)) !== false)
			{
				unset($post_sequence[$key]);
				update_post_meta($post_id, "_post_sequences", $post_sequence);

	            dbgOut('removePost(): Post/Page list updated and saved');

	        }

			return true;
		}

		/**
		 * Function will remove the flag indicating that the user has been notified already for this post.
		 * Searches through all active User IDs with the same level as the Sequence requires.
		 *
		 * @param $post_id - The ID of the post to search through the active member list for
		 *
		 * @access private
		 */
		private function removeNotifiedFlagForPost( $post_id ) {

			global $wpdb;

			dbgOut('Preparing SQL. Using sequence ID: ' . $this->sequence_id);

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

			$users = $wpdb->get_results($sql);

			foreach ($users as $user) {

				dbgOut('removeNotifiedFlagForPost() - Searching for Post ID ' . $post_id .' in notification settings for user with ID: ' . $user->user_id);
				$userSettings = get_user_meta( $user->user_id, $wpdb->prefix . 'pmpro_sequence_notices', true );

				$notifiedPosts = $userSettings->sequence[ $this->sequence_id ]->notifiedPosts;

				if ( is_array($notifiedPosts) &&
				     ($key = array_search( $post_id, $notifiedPosts) ) !== false ) {

					dbgOut('Found post # ' . $post_id . ' in the notification settings for user_id ' . $user->user_id . ' with key: ' . $key);
					dbgOut('Found in settings: ' . $userSettings->sequence[ $this->sequence_id ]->notifiedPosts[ $key ]);
					unset( $userSettings->sequence[ $this->sequence_id ]->notifiedPosts[ $key ] );

					update_user_meta( $user->user_id, $wpdb->prefix . 'pmpro_sequence_notices', $userSettings );
					dbgOut('Deleted post # ' . $post_id . ' in the notification settings for user with id ' . $user->user_id);
				}
			}
		}

		/**
		 * Send email to userID about access to new post.
		 *
		 * @param $post_id -- ID of post to send email about
		 * @param $user_id -- ID of user to send the email to.
		 * @param $seq_id -- ID of sequence to process (not used)
		 * @return bool - True if sent successfully. False otherwise.
		 *
         * @access public
		 *
		 * TODO? Figure out how to use filters to set the From & From Name info as defined in $this->options
		 */
		public function sendEmail($post_id, $user_id, $seq_id)
		{
			// Make sure the email class is loaded.
            if ( ! class_exists( 'PMProEmail' ) ) {
                return;
            }

            $email = new PMProEmail();
	        // $sequence = new PMProSequence($seq_id);
	        $settings = $this->options;

			$user = get_user_by('id', $user_id);
			$post = get_post($post_id);

			$templ = preg_split('/\./', $settings->noticeTemplate); // Parse the template name

			dbgOut('sendEmail() - Setting sender information');

			$email->from = $settings->replyto; // = pmpro_getOption('from_email');
			$email->fromname = $settings->fromname; // = pmpro_getOption('from_name');

			$email->email = $user->user_email;
			$email->ptitle = $post->post_title;

			$seqPost = $this->get_postDetails($post->ID);
			dbgOut("sendEmail() Subject information: {$seqPost->delay} for {$post->ID}");

			$email->subject = sprintf('%s: %s (%s)', $settings->subject, $post->post_title, strftime("%x", current_time('timestamp', true) ));
			// $email->subject = sprintf(__("New information/post(s) available at %s", "pmpro"), get_option("blogname"));

			$email->template = $templ[0];

			$template_file_path = PMPRO_SEQUENCE_PLUGIN_DIR . DIRECTORY_SEPARATOR . 'email' . DIRECTORY_SEPARATOR . $settings->noticeTemplate;

			dbgOut('sendEmail() - Loading template for email message');
			if (false === ($template_content = file_get_contents( $template_file_path) ) ) {
				dbgOut('sendEmail() - Could not read content from template file: '. $settings->noticeTemplate);
				return false;
			}

			$email->dateformat = $this->options->dateformat;

			$email->body = $template_content;

			// All of the array list names are !!<name>!! escaped values.

			$email->data = array(
				"name" => $user->first_name, // Options are: display_name, first_name, last_name, nickname
				"sitename" => get_option("blogname"),
				"post_link" => '<a href="' . get_permalink($post->ID) . '" title="' . $post->post_title . '">' . $post->post_title . '</a>',
				"today" => date($settings->dateformat, current_time('timestamp')),
			);

			// dbgOut('sendEmail() - Array contains: ' . print_r($email->data, true));

			if(!empty($post->post_excerpt)) {

				dbgOut("Adding the post excerpt to email notice");

	            if ( empty( $settings->excerpt_intro ) )
	                $settings->excerpt_intro = __('A summary of the post follows below:', 'pmprosequence');

	            $email->data['excerpt'] = '<p>' . $settings->excerpt_intro . '</p><p>' . $post->post_excerpt . '</p>';
			}
	        else
				$email->data['excerpt'] = '';


			$email->sendEmail();

			return true;
		}

        /**
         * Creates the Sequence Custom Post Type
         *
         * @return bool -- True if successful
         *
         * @access public
         *
         */
        static public function createCPT()
		{
			//don't want to do this when deactivating
			global $pmpro_sequencedeactivating;

			if(!empty($pmpro_sequencedeactivating))
				return false;

			$error = register_post_type('pmpro_sequence',
					array(
							'labels' => array(
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
	                                'not_found_in_trash' => __( 'No Sequence Found In Trash', 'pmprosequence' ),
	                        ),
					'public' => true,
					/*'menu_icon' => plugins_url('images/icon-sequence16-sprite.png', dirname(__FILE__)),*/
					'show_ui' => true,
					'show_in_menu' => true,
					'publicly_queryable' => true,
					'hierarchical' => true,
					'supports' => array('title','editor','thumbnail','custom-fields','author'),
					'can_export' => true,
					'show_in_nav_menus' => true,
					'rewrite' => array(
							'slug' => 'sequence',
							'with_front' => false
							),
					'has_archive' => 'sequences'
				)
			);

			if (! is_wp_error($error) )
				return true;
			else {
				dbgOut('Error creating post type: ' . $error->get_error_message());
				wp_die($error->get_error_message());
				return false;
			}
		}

        /**
         * Loads the metaboxes in the back-end admin page for the managing the sequence
         *
         * @public
         */
		public function checkForMetaBoxes()
		{
			//add meta boxes
			if (is_admin())
			{
				wp_enqueue_style('pmpros-select2', plugins_url('css/select2.css', dirname(__FILE__)), '', '3.1', 'screen');
				wp_enqueue_script('pmpros-select2', plugins_url('js/select2.js', dirname(__FILE__)), array( 'jquery' ), '3.1' );

				add_action('admin_menu', array("PMProSequence", "defineMetaBoxes"));
	            add_action('save_post', array('PMProSequence', 'pmpro_sequence_meta_save'), 10, 2);

                // dbgOut("Load post Meta");
                /* Fire our meta box setup function on the post editor screen. */
                // add_action( 'add_meta_boxes', array( &$this, 'pmpro_sequence_post_metabox_setup', 10, 2 ) );
                // add_action( 'load-post-new.php', array( &$this, 'pmpro_sequence_post_metabox_setup' ) );
                // add_action( 'add_meta_boxes', array( "PMProSequence", 'pmpro_sequence_post_metabox_setup', 10, 2 ) );
			}
		}

        /**
         * Configure metabox for the normal Post/Page editor
         */
        public function pmpro_sequence_post_metabox_setup( $object, $box ) {

            dbgOut("Post metaboxes being configured");

            //TODO: Find all post types on the system & then loop through for all post types using a foreach() loop.
            /* Add meta boxes on the 'add_meta_boxes' hook. */
            add_meta_box( 'pmpro-seq-post-meta', __('Drip Feed Sequence', 'pmprosequence'), array( "PMProSequence", 'pmpro_sequence_page_meta'), 'page', 'side', 'high');
            add_meta_box( 'pmpro-seq-post-meta', __('Drip Feed Sequence', 'pmprosequence'), array( "PMProSequence", 'pmpro_sequence_page_meta'), 'post', 'side', 'high');
        }


        public function pmpro_sequence_page_meta() {

            $metabox = '';

            global $post;

            $seq = new PMProSequence();

            dbgOut("Page Metabox being loaded");
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

        public function load_sequence_meta( $post_id = null, $seq_id = null) {

            $belongs_to = array();

            $query = array(
                'post_type' => 'pmpro_sequence',
                'post_status' => 'any',
            );

            /* Fetch all Sequence posts */
            $sequence_list = get_posts( $query );

            dbgOut("Loading Sequences (count: " . count($sequence_list) . ")");

            // Post ID specified so we need to look for any sequence related metadata for this post

            if ( empty( $post_id ) ) {

                global $post;
                $post_id = $post->ID;
            }

            if ( ! empty( $seq_id ) ) {
                $belongs_to = array(  $seq_id );
            }
            else {
                dbgOut("Loading sequence ID from DB");
                $belongs_to = get_post_meta( $post_id, "_post_sequences", true );
            }

            dbgOut("Post belongs in sequence(s): " . print_r( $belongs_to, true ) );

            // Make sure there's at least one row in the Metabox.
            dbgOut(" Ensure there's at least one entry in the table ");
            array_push( $belongs_to, 0 );

            ob_start();
            ?>
            <?php wp_nonce_field('pmpro-sequence-post-meta', 'pmpro_sequence_postmeta_nonce');?>
            <div class="seq_spinner vt-alignright"></div>
            <table style="width: 100%;" id="pmpro-seq-metatable">
                <tbody>
                <?php foreach( $belongs_to as $active_id ) { ?>
                    <tr class="delay-row-label<?php echo ( $active_id == 0 ? ' pmpro-sequence-hidden' : '' ); ?>">
                        <td>
                            <label for="pmpor_seq-memberof-sequences"><?php _e("Select drip-feed sequence", "pmprosequence"); ?></label>
                        </td>
                    </tr>
                    <tr class="delay-row-input<?php echo ( $active_id == 0 ? ' pmpro-sequence-hidden' : '' ); ?>">
                        <td class="sequence-list-dropdown">
                            <select id="pmpro_seq-memberof-sequences" name="pmpro_seq-sequences[]">
                                <option value="0" <?php echo ( ( empty( $belongs_to ) || $active_id == 0) ? 'selected' : '' ); ?>><?php _e("Not in a Sequence", "pmprosequence"); ?></option>
                                <?php
                                // Loop through all of the sequences & create an option list
                                foreach ( $sequence_list as $sequence ) {

                                    ?><option value="<?php echo $sequence->ID; ?>" <?php echo selected( $sequence->ID, $active_id ); ?>><?php echo $sequence->post_title; ?></option><?php
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <?php
                    // Figure out the correct delay type and load the value for this post if it exists.
                    if ( $active_id != 0) {
                        dbgOut("Loading options for {$active_id}");
                        $this->fetchOptions( $active_id );
                    }
                    else {
                        $this->sequence_id = 0;
                        $this->options = $this->defaultOptions();
                    }

                    $delay = null; // Set/Reset
                    dbgOut("Loading all posts for {$active_id}");

                    $this->getPosts(true);

                    $delay = $this->getDelayForPost( $post_id );

                    if ( $delay === false ) {
                        $delayVal = "value=''";
                    }
                    else {
                        $delayVal = " value='{$delay}' ";
                    }

                    switch ( $this->options->delayType ) {

                        case 'byDate':

                            dbgOut("Configured to track delays by Date");
                            $delayFormat = __( 'Date', "pmprosequence" );
                            $starts = date_i18n( "Y-m-d", current_time('timestamp') );

                            if ( empty( $delayVal ) ) {
                                $inputHTML = "<input class='pmpro-seq-date' type='date' min='{$starts}' name='pmpro_seq-delay[]' id='pmpro_seq-delay_{$active_id}'>";
                            }
                            else {
                                $inputHTML = "<input class='pmpro-seq-date' type='date' name='pmpro_seq-delay[]' id='pmpro_seq-delay_{$active_id}'{$delayVal}>";
                            }

                            break;

                        default:

                            dbgOut("Configured to track delays by Day count");
                            $delayFormat = __('Day Count', "pmprosequence");
                            $inputHTML = "<input class='pmpro-seq-days' type='text' id='pmpro_seq-delay_{$active_id}' name='pmpro_seq-delay[]'{$delayVal}>";

                    }

                    $label = sprintf( __("Delay (Format: %s)", "pmprosequence"), $delayFormat );
                    // dbgOut(" Label: " . print_r( $label, true ) );
                    ?>
                    <tr class="delay-row-label<?php echo ( $active_id == 0 ? ' pmpro-sequence-hidden' : '' ); ?>">
                        <td>
                            <label for="pmpro_seq-delay_<?php echo $active_id; ?>"> <?php echo $label; ?> </label>
                        </td>
                    </tr>
                    <tr class="delay-row-input<?php echo ( $active_id == 0 ? ' pmpro-sequence-hidden' : '' ); ?>">
                        <td>
                            <?php echo $inputHTML; ?>
                            <label for="remove-sequence_<?php echo $active_id; ?>" ><?php _e('Remove: ', 'pmprosequence'); ?></label><input type="checkbox" name="remove-sequence" class="pmpro_seq-remove-seq" value="<?php echo $active_id; ?>">
                        </td>
                    </tr>
                <?php } // Foreach ?>
                </tbody>
            </table>
            <div id="pmpro-seq-new">
                <hr class="pmpro-seq-hr" />
                <a href="#" id="pmpro-seq-new-meta" class="button-primary">Add New</a>
            </div>
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
	    public function defineMetaBoxes()
		{
			//PMPro box
			add_meta_box('pmpro_page_meta', __('Require Membership', 'pmprosequence'), 'pmpro_page_meta', 'pmpro_sequence', 'side');

            dbgOut("Loading post meta boxes");

			// sequence settings box (for posts & pages)
	        add_meta_box('pmpros-sequence-settings', __('Settings the Sequence', 'pmprosequence'), array("PMProSequence", 'pmpro_sequence_settings_meta_box'), 'pmpro_sequence', 'side', 'high');

			//sequence meta box
			add_meta_box('pmpro_sequence_meta', __('Posts in this Sequence', 'pmprosequence'), array("PMProSequence", "sequenceMetaBox"), 'pmpro_sequence', 'normal', 'high');
            // add_meta_box('pmpro-post-sequence-meta', __('Select Sequence', 'pmprosequence'), array( "PMProSequence", 'pmpro_sequence_page_meta'), 'pmpro_sequence', 'side');

	    }

        /**
         * Defines the Admin UI interface for adding posts to the sequence
         *
         * @access public
         */
        public function sequenceMetaBox()
		{
			global $post;

			if (empty($this))
				$sequence = new PMProSequence($post->ID);
			else
				$sequence = $this;

	        dbgOut('sequenceMetaBox(): Load the post list meta box');

	        // Instantiate the settings & grab any existing settings if they exist.
	     ?>
			<div id="pmpro_sequence_posts">
			<?php
				$box = $sequence->getPostListForMetaBox();
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
		public function getPostListForMetaBox()
		{
			// global $wpdb;

			//show posts
			$this->getPosts();

	        dbgOut('Displaying the back-end meta box content');

			ob_start();
			?>

			<?php // if(!empty($this->error)) { ?>
				<div id="pmpro-seq-error" class="message error"><?php echo $this->error;?></div>
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

			if(empty($this->posts))
			{
	            dbgOut('No Posts found?');

				$errTxt = __('No posts/pages found for this sequence', 'pmprosequence');

				if ( $this->getError() !== null )
					$errorMsg = $this->getError() . ' ' . __('and', 'pmprosequence') . ' ' . $errTxt;
				else
					$errorMsg = $errTxt;

				$this->setError( $errorMsg );
			?>
			<?php
			}
			else
			{
				foreach($this->posts as $post)
				{
				?>
					<tr>
						<td class="pmpro_sequence_tblNumber"><?php echo $count; ?>.</td>
						<td class="pmpro_sequence_tblPostname"><?php echo get_the_title($post->id) . " (ID: {$post->id})"; ?></td>
						<td class="pmpro_sequence_tblNumber"><?php echo $post->delay; ?></td>
						<td><a href="javascript:pmpro_sequence_editPost('<?php echo $post->id; ?>'); void(0); "><?php _e('Post','pmprosequence'); ?></a></td>
						<td>
							<a href="javascript:pmpro_sequence_editEntry('<?php echo $post->id;?>', '<?php echo $post->delay;?>'); void(0);"><?php _e('Edit', 'pmprosequence'); ?></a>
						</td>
						<td>
							<a href="javascript:pmpro_sequence_removeEntry('<?php echo $post->id;?>'); void(0);"><?php _e('Remove', 'pmprosequence'); ?></a>
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
								if ( ($all_posts = $this->getPostListFromDB()) !== FALSE)
									foreach($all_posts as $p)
									{
									?>
									<option value="<?php echo $p->ID;?>"><?php echo esc_textarea($p->post_title);?> (#<?php echo $p->ID;?><?php echo $this->setPostStatus( $p->post_status );?>)</option>
									<?php
									}
								else {
									$this->setError( __( 'No posts found in the database!', 'pmprosequence' ) );
									dbgOut('Error during database search for relevant posts');
								}
							?>
							</select>
							<style>
								.select2-container {width: 100%;}
							</style>
							<script>
								jQuery('#pmpro_sequencepost').select2();
							</script>
							</td>
							<td>
								<input id="pmpro_sequencedelay" name="pmpro_sequencedelay" type="text" value="" size="7" />
								<input id="pmpro_sequence_id" name="pmpro_sequence_id" type="hidden" value="<?php echo $this->id; ?>" size="7" />
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

			( is_null( $this->getError() ) ?
				dbgOut( "getPostListForMetaBox() - No error found, should return success" ) :
				dbgOut( "getPostListForMetaBox() - Error: {$this->getError()}" ) );

			return array(
				'success' => ( is_null($this->getError()) ? true : false ),
				'message' => ( is_null($this->getError()) ? null : $this->getError() ),
				'html' => $html,
			);
		}

		/**
		 * Get all posts with status 'published', 'draft', 'scheduled', 'pending review' or 'private' from the DB
		 *
		 * @return array | bool -- All posts of the post_types defined in the pmpro_sequencepost_types filter)
         *
         * @access private
		 */
		private function getPostListFromDB() {

			global $wpdb;

			$post_types = apply_filters("pmpro_sequencepost_types", array("post", "page") );
			$status = array('publish', 'draft', 'future', 'pending', 'private');

			$sql = $wpdb->prepare(
				"
					SELECT ID, post_title, post_status
					FROM {$wpdb->posts}
					WHERE post_status IN ('" .implode( "', '", $status ). "')
					AND post_type IN ('" .implode( "', '", $post_types ). "')
					AND post_title <> ''
					ORDER BY post_title
				");

			if ( NULL !== ($all_posts = $wpdb->get_results($sql)) )
				return $all_posts;
			else
				return false;
		}

	    /**
	     * Used to label the post list in the metabox
	     *
	     * @param $post_state -- The current post state (Draft, Scheduled, Under Review, Private, other)
	     * @return null|string -- Return the correct postfix for the post
         *
         * @access private
	     */
	    private function setPostStatus( $post_state )
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
	     * Adds notification opt-in to list of posts/pages in sequence.
	     *
	     * @return string -- The HTML containing a form (if the sequence is configured to let users receive notices)
         *
         * @access public
	     */
	    public function pmpro_sequence_addUserNoticeOptIn( )
		{
			$optinForm = '';
	        global $current_user, $wpdb;

			$meta_key = $wpdb->prefix . 'pmpro_sequence_notices';

			dbgOut('addUserNoticeOptIn() - User specific opt-in to sequence display for new content notices for user ' . $current_user->ID);

	        if ($this->options->sendNotice == 1) {

		        dbgOut('addUserNoticeOptIn() - meta key: ' . $meta_key);
		        dbgOut('addUserNoticeOptIn() - sequence ID: ' . $this->sequence_id);
		        dbgOut('addUserNoticeOptIn() - User ID: ' . $current_user->ID);

	            $optIn = get_user_meta( $current_user->ID, $meta_key, true );

		        // dbgOut('addUserNoticeOptIn() - Fetched Meta: ' . print_r(get_user_meta($current_user->ID, $meta_key, true), true));

	            /* Determine the state of the users opt-in for new content notices */
	            if ( empty($optIn->sequence ) || empty( $optIn->sequence[$this->sequence_id] ) ) {

		            dbgOut('addUserNoticeOptIn() - No user specific settings found in general or for this sequence. Creating defaults');

		            // Create new opt-in settings for this user
		            if ( empty($optIn->sequence) )
			            $new = new stdClass();
		            else // Saves existing settings
			            $new = $optIn;

		            $new->sequence[$this->sequence_id]->sendNotice = $this->options->sendNotice;

		            dbgOut('addUserNoticeOptIn() - Using default setting for user ' . $current_user->ID . ' and sequence ' . $this->sequence_id);

		            $optIn = $new;
	            }

		        if ( empty( $optIn->sequence[$this->sequence_id]->notifiedPosts ) )
			        $optIn->sequence[$this->sequence_id]->notifiedPosts = array();

		        update_user_meta($current_user->ID, $meta_key, $optIn);

		        dbgOut('addUserNoticeOptIn() - Saved user meta for notice opt-in');
		        // dbgOut('OptIn options: ' . print_r($optIn, true));

	            /* Add form information */
		        ob_start();
		        ?>
	            <div class="pmpro-seq-centered">
			        <div class="pmpro-sequence-hidden pmpro_sequence_useroptin">
		                <div class="seq_spinner"></div>
		                <form action="<?php echo admin_url('admin-ajax.php'); ?>" method="post">
		                    <input type="hidden" name="hidden_pmpro_seq_useroptin" id="hidden_pmpro_seq_useroptin" value="<?php echo $optIn->sequence[$this->sequence_id]->sendNotice; ?>" >
		                    <input type="hidden" name="hidden_pmpro_seq_id" id="hidden_pmpro_seq_id" value="<?php echo $this->sequence_id; ?>" >
		                    <input type="hidden" name="hidden_pmpro_seq_uid" id="hidden_pmpro_seq_uid" value="<?php echo $current_user->ID; ?>" >
		                    <?php wp_nonce_field('pmpro-sequence-user-optin', 'pmpro_sequence_optin_nonce'); ?>
		                    <p><input type="checkbox" value="1" id="pmpro_sequence_useroptin" name="pmpro_sequence_useroptin" onclick="javascript:pmpro_sequence_optinSelect(); return false;" title="<?php _e('Please email me an alert when any new content in this sequence becomes available', 'pmprosequence'); ?>" <?php echo ($optIn->sequence[$this->sequence_id]->sendNotice == 1 ? ' checked="checked"' : ''); ?> " />
		                    <label for="pmpro-seq-useroptin"><?php _e('Yes, please send me email notifications!', 'pmprosequence'); ?></label></p>
		                </form>
			        </div>
	            </div>

	            <?php
		        $optinForm .= ob_get_clean();
	        }

	        return $optinForm;
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
	    public function pmpro_sequence_settings_meta_box( $object, $box )
	    {
	        global $post;

		    if (empty($this))
			    $sequence = new PMProSequence($post->ID);
		    else
			    $sequence = $this;

	        $sequence->sequence_id = $post->ID;

	        if ( $sequence->sequence_id != 0)
	        {
		        dbgOut('Loading settings for Meta Box');
		        $settings = $sequence->fetchOptions($sequence->sequence_id);
	            // $settings = $sequence->fetchOptions($sequence_id);
	            // dbgOut('Returned settings: ' . print_r($sequence->options, true));
	        }
	        else
	        {
	            dbgOut('Not a valid Sequence ID, cannot load options');
	            return;
	        }

	        // dbgOut('pmpro_sequence_settings_meta_box() - Loaded settings: ' . print_r($settings, true));

		    // Buffer the HTML so we can pick it up in a variable.
		    ob_start();

	        ?>
	        <div class="submitbox" id="pmpro_sequence_meta">
	            <div id="minor-publishing">
	            <input type="hidden" name="pmpro_sequence_settings_noncename" id="pmpro_sequence_settings_noncename" value="<?php echo wp_create_nonce( plugin_basename(__FILE__) )?>" />
	            <input type="hidden" name="pmpro_sequence_settings_hidden_delay" id="pmpro_sequence_settings_hidden_delay" value="<?php echo esc_attr($settings->delayType); ?>"/>
	            <input type="hidden" name="hidden_pmpro_seq_wipesequence" id="hidden_pmpro_seq_wipesequence" value="0"/>
	            <table style="width: 100%;">
		            <tr>
	                    <td style="width: 20px;">
		                    <input type="checkbox" value="1" id="pmpro_sequence_hidden" name="pmpro_sequence_hidden" title="<?php _e('Hide unpublished / future posts for this sequence', 'pmprosequence'); ?>" <?php checked($settings->hidden, 1); ?> />
			                <input type="hidden" name="hidden_pmpro_seq_future" id="hidden_pmpro_seq_future" value="<?php echo esc_attr($settings->hidden); ?>" >
	                    </td>
	                    <td style="width: 160px"><label class="selectit"><?php _e('Hide all future posts', 'pmprosequence'); ?></label></td>
	                </tr>
		            <tr>
			            <td>
				            <input type="checkbox" value="1" id="pmpro_sequence_offsetchk" name="pmpro_sequence_offsetchk" title="<?php _e('Let the user see a number of days worth of technically unavailable posts as a form of &quot;sneak-preview&quot;', 'pmprosequence'); ?>" <?php echo ($settings->previewOffset != 0 ? ' checked="checked"' : ''); ?> />
				            <input type="hidden" name="hidden_pmpro_seq_offset" id="hidden_pmpro_seq_offset" value="<?php echo esc_attr($settings->previewOffset); ?>" >
			            </td>
			            <td><label class="selectit"><?php _e('Allow "sneak preview" of sequence', 'pmprosequence'); ?></label>
			            </td>
		            </tr>
		            <tr>
			            <td colspan="2">
				            <div class="pmpro-sequence-hidden pmpro-sequence-offset">
					            <label class="pmpro-sequence-label" for="pmpro-seq-offset"><?php _e('Days of prev:', 'pmprosequence'); ?> </label>
					            <span id="pmpro-seq-offset-status" class="pmpro-sequence-status"><?php echo ( $settings->previewOffset == 0 ? 'None' : $settings->previewOffset ); ?></span>
					            <a href="#pmpro-seq-offset" id="pmpro-seq-edit-offset" class="pmpro-seq-edit">
						            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
						            <span class="screen-reader-text"><?php _e('Change the number of days to preview', 'pmprosequence'); ?></span>
					            </a>
					            <div id="pmpro-seq-offset-select" class="pmpro-sequence-hidden">
						            <label for="pmpro_sequence_offset"></label>
						            <select name="pmpro_sequence_offset" id="pmpro_sequence_offset">
							            <option value="0">None</option>
							            <?php foreach (range(1, 5) as $previewOffset) { ?>
								            <option value="<?php echo esc_attr($previewOffset); ?>" <?php selected( intval($settings->previewOffset), $previewOffset); ?> ><?php echo $previewOffset; ?></option>
							            <?php } ?>
						            </select>
						            <p class="pmpro-seq-btns">
							            <a href="#pmproseq_offset" id="ok-pmpro-seq-offset" class="save-pmproseq-offset button"><?php _e('OK', 'pmprosequence'); ?></a>
							            <a href="#pmproseq_offset" id="cancel-pmpro-seq-offset" class="cancel-pmproseq-offset button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
						            </p>
					            </div>
				            </div>
			            </td>
		            </tr>
		            <tr>
	                    <td>
		                    <input type="checkbox" value="1" id="pmpro_sequence_lengthvisible" name="pmpro_sequence_lengthvisible" title="<?php _e('Whether to show the &quot;You are on day NNN of your membership&quot; text', 'pmprosequence'); ?>" <?php checked($settings->lengthVisible, 1); ?> />
		                    <input type="hidden" name="hidden_pmpro_seq_lengthvisible" id="hidden_pmpro_seq_lengthvisible" value="<?php echo esc_attr($settings->lengthVisible); ?>" >
	                    </td>
	                    <td><label class="selectit"><?php _e("Show user membership length", 'pmprosequence'); ?></label></td>
	                </tr>
		            <tr><td colspan="2"><hr/></td></tr>
	                <tr>
		                <td colspan="2">
			                <div class="pmpro-sequence-sortorder">
				                <label class="pmpro-sequence-label" for="pmpro-seq-sort"><?php _e('Sort order:', 'pmprosequence'); ?> </label>
				                <span id="pmpro-seq-sort-status" class="pmpro-sequence-status"><?php echo ( $settings->sortOrder == SORT_ASC ? __('Ascending', 'pmprosequence') : __('Descending', 'pmprosequence') ); ?></span>
				                <a href="#pmpro-seq-sort" id="pmpro-seq-edit-sort" class="pmpro-seq-edit">
					                <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
					                <span class="screen-reader-text"><?php _e('Edit the list sort order', 'pmprosequence'); ?></span>
				                </a>
				                <div id="pmpro-seq-sort-select" class="pmpro-sequence-hidden">
					                <input type="hidden" name="hidden_pmpro_seq_sortorder" id="hidden_pmpro_seq_sortorder" value="<?php echo ($settings->sortOrder == SORT_ASC ? SORT_ASC : SORT_DESC); ?>" >
					                <label for="pmpro_sequence_sortorder"></label>
					                <select name="pmpro_sequence_sortorder" id="pmpro_sequence_sortorder">
						                <option value="<?php echo esc_attr(SORT_ASC); ?>" <?php selected( intval($settings->sortOrder), SORT_ASC); ?> > <?php _e('Ascending', 'pmprosequence'); ?></option>
						                <option value="<?php echo esc_attr(SORT_DESC); ?>" <?php selected( intval($settings->sortOrder), SORT_DESC); ?> ><?php _e('Descending', 'pmprosequence'); ?></option>
					                </select>
					                <p class="pmpro-seq-btns">
						                <a href="#pmproseq_sortorder" id="ok-pmpro-seq-sort" class="save-pmproseq-sortorder button"><?php _e('OK', 'pmprosequence'); ?></a>
						                <a href="#pmproseq_sortorder" id="cancel-pmpro-seq-sort" class="cancel-pmproseq-sortorder button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
					                </p>
				                </div>
			                </div>
		                </td>
	                </tr>
	                <tr>
		                <td colspan="2">
			                <div class="pmpro-sequence-delaytype">
				                <label class="pmpro-sequence-label" for="pmpro-seq-delay"><?php _e('Delay type:', 'pmprosequence'); ?> </label>
				                <span id="pmpro-seq-delay-status" class="pmpro-sequence-status"><?php echo ($settings->delayType == 'byDate' ? __('A date', 'pmprosequence') : __('Days after sign-up', 'pmprosequence') ); ?></span>
				                <a href="#pmpro-seq-delay" id="pmpro-seq-edit-delay" class="pmpro-seq-edit">
					                <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
					                <span class="screen-reader-text"><?php _e('Edit the delay type for this sequence', 'pmprosequence'); ?></span>
				                </a>
				                <div id="pmpro-seq-delay-select" class="pmpro-sequence-hidden">
	                                <input type="hidden" name="hidden_pmpro_seq_delaytype" id="hidden_pmpro_seq_delaytype" value="<?php echo ($settings->delayType != '' ? esc_attr($settings->delayType): 'byDays'); ?>" >
					                <label for="pmpro_sequence_delaytype"></label>
					                <select onchange="pmpro_sequence_delayTypeChange(<?php echo $sequence->sequence_id; ?>); return false;" name="pmpro_sequence_delaytype" id="pmpro_sequence_delaytype">
	                                    <option value="byDays" <?php selected( $settings->delayType, 'byDays'); ?> ><?php _e('Days after sign-up', 'pmprosequence'); ?></option>
	                                    <option value="byDate" <?php selected( $settings->delayType, 'byDate'); ?> ><?php _e('A date', 'pmprosequence'); ?></option>
	                                </select>
                                </div>
				                <div class="pmpro-sequence-hidden pmpro-seq-showdelayas" id="pmpro-seq-showdelayas">
					                <label class="pmpro-sequence-label" for="pmpro-seq-showdelayas"><?php _e("Show availability as:", 'pmprosequence'); ?></label>
					                <span id="pmpro-seq-showdelayas-status" class="pmpro-sequence-status"><?php echo ($settings->showDelayAs == PMPRO_SEQ_AS_DATE ? __('Calendar date', 'pmprosequence') : __('Day of membership', 'pmprosequence') ); ?></span>
					                <a href="#pmpro-seq-showdelayas" id="pmpro-seq-edit-showdelayas" class="pmpro-seq-edit">
						                <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
						                <span class="screen-reader-text"><?php _e('How to indicate when the post will be available to the user. Select either "Calendar date" or "day of membership")', 'pmprosequence'); ?></span>

					                </a>
				                </div>
				                <div id="pmpro-seq-showdelayas-select" class="pmpro-sequence-hidden pmpro-seq-select">
					                <!-- Only show this if 'hidden_pmpro_seq_delaytype' == 'byDays' -->
					                <input type="hidden" name="hidden_pmpro_seq_showdelayas" id="hidden_pmpro_seq_showdelayas" value="<?php echo ($settings->showDelayAs == PMPRO_SEQ_AS_DATE ? PMPRO_SEQ_AS_DATE : PMPRO_SEQ_AS_DAYNO ); ?>" >
					                <label for="pmpro_sequence_showdelayas"></label>
					                <select name="pmpro_sequence_showdelayas" id="pmpro_sequence_showdelayas">
						                <option value="<?php echo PMPRO_SEQ_AS_DAYNO; ?>" <?php selected( $settings->showDelayAs, PMPRO_SEQ_AS_DAYNO); ?> ><?php _e('Day of membership', 'pmprosequence'); ?></option>
						                <option value="<?php echo PMPRO_SEQ_AS_DATE; ?>" <?php selected( $settings->showDelayAs, PMPRO_SEQ_AS_DATE); ?> ><?php _e('Calendar date', 'pmprosequence'); ?></option>
					                </select>
				                </div>
				                <div id="pmpro-seq-delay-btns" class="pmpro-sequence-hidden">
					                <p class="pmpro-seq-btns">
						                <a href="#pmproseq_delaytype" id="ok-pmpro-seq-delay" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
						                <a href="#pmproseq_delaytype" id="cancel-pmpro-seq-delay" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
					                </p>
				                </div>
			                </div>
		                </td>
	                </tr>
		            <tr>
			            <td colspan="2">
				            <div class="pmpro-seq-alert-hl"><?php _e('New content alerts', 'pmprosequence'); ?></div>
				            <hr width="100%"/>
			            </td>
		            </tr>
		            <tr>
			            <td colspan="2">
				            <div class="pmpro-sequence-alerts">
					            <input type="checkbox" value="1" title="<?php _e('Whether to send an alert/notice to members when new content for this sequence is available to them', 'pmprosequence'); ?>" id="pmpro_sequence_sendnotice" name="pmpro_sequence_sendnotice" <?php checked($settings->sendNotice, 1); ?> />
					            <input type="hidden" name="hidden_pmpro_seq_sendnotice" id="hidden_pmpro_seq_sendnotice" value="<?php echo esc_attr($settings->sendNotice); ?>" >
					            <label class="selectit" for="pmpro_sequence_sendnotice"><?php _e('Send new content alerts', 'pmprosequence'); ?></label>
					            <?php /* Add 'send now' button if checkbox is set */ ?>
					            <div class="pmpro-sequence-hidden pmpro-sequence-sendnowbtn">
						            <label for="pmpro_seq_send"><?php _e('Manually process alerts', 'pmprosequence'); ?></label>
						            <a href="#sendalerts" class="pmpro-seq-settings-send pmpro-seq-edit" id="pmpro_seq_send" onclick="pmpro_sequence_sendAlertNotice(<?php echo $sequence->sequence_id;?>); return false;">
						                <span aria-hidden="true"><?php _e('Send', 'pmprosequence'); ?></span>
						                <span class="screen-reader-text"><?php _e('Manually issue command to process alert notices for the current sequence', 'pmprosequence'); ?></span>
						            </a>
						            <?php wp_nonce_field('pmpro-sequence-sendalert', 'pmpro_sequence_sendalert_nonce'); ?>
					            </div>
					            <div class="pmpro-sequence-hidden pmpro-sequence-email">
						            <p class="pmpro-seq-email-hl"><?php _e("From:", 'pmprosequence'); ?></p>
						            <div class="pmpro-sequence-replyto">
							            <label class="pmpro-sequence-label" for="pmpro-seq-replyto"><?php _e('Email:', 'pmprosequence'); ?> </label>
							            <span id="pmpro-seq-replyto-status" class="pmpro-sequence-status"><?php echo ( $settings->replyto != '' ? esc_attr($settings->replyto) : pmpro_getOption("from_email") ); ?></span>
							            <a href="#pmpro-seq-replyto" id="pmpro-seq-edit-replyto" class="pmpro-seq-edit">
								            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
								            <span class="screen-reader-text"><?php _e('Enter the email address to use for the sender of the alert', 'pmprosequence'); ?></span>
							            </a>
						            </div>
						            <div class="pmpro-sequence-fromname">
							            <label for="pmpro-seq-fromname"><?php _e('Name:', 'pmprosequence'); ?> </label>
							            <span id="pmpro-seq-fromname-status" class="pmpro-sequence-status"><?php echo ($settings->fromname != '' ? esc_attr($settings->fromname) : pmpro_getOption("from_name") ); ?></span>
							            <a href="#pmpro-seq-fromname" id="pmpro-seq-edit-fromname" class="pmpro-seq-edit">
								            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
								            <span class="screen-reader-text"><?php _e('Enter the name to use for the sender of the alert', 'pmprosequence'); ?></span>
							            </a>
						            </div>
						            <div id="pmpro-seq-email-input" class="pmpro-sequence-hidden">
							            <input type="hidden" name="hidden_pmpro_seq_replyto" id="hidden_pmpro_seq_replyto" value="<?php echo ($settings->replyto != '' ? esc_attr($settings->replyto) : pmpro_getOption("from_email") ); ?>" />
							            <label for="pmpro_sequence_replyto"></label>
							            <input type="text" name="pmpro_sequence_replyto" id="pmpro_sequence_replyto" value="<?php echo ($settings->replyto != '' ? esc_attr($settings->replyto) : pmpro_getOption("from_email")); ?>"/>
							            <input type="hidden" name="hidden_pmpro_seq_fromname" id="hidden_pmpro_seq_fromname" value="<?php echo ($settings->fromname != '' ? esc_attr($settings->fromname) : pmpro_getOption("from_name")); ?>" />
							            <label for="pmpro_sequence_fromname"></label>
							            <input type="text" name="pmpro_sequence_fromname" id="pmpro_sequence_fromname" value="<?php echo ($settings->fromname != '' ? esc_attr($settings->fromname) : pmpro_getOption("from_name") ); ?>"/>
							            <p class="pmpro-seq-btns">
								            <a href="#pmproseq_email" id="ok-pmpro-seq-email" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
								            <a href="#pmproseq_email" id="cancel-pmpro-seq-email" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
							            </p>
						            </div>
					            </div>

					            <div class="pmpro-sequence-hidden pmpro-sequence-template">
						            <hr width="60%"/>
						            <label class="pmpro-sequence-label" for="pmpro-seq-template"><?php _e('Template:', 'pmprosequence'); ?> </label>
						            <span id="pmpro-seq-template-status" class="pmpro-sequence-status"><?php echo esc_attr( $settings->noticeTemplate ); ?></span>
						            <a href="#pmpro-seq-template" id="pmpro-seq-edit-template" class="pmpro-seq-edit">
							            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
							            <span class="screen-reader-text"><?php _e('Select the template to use when posting new content in this sequence', 'pmprosequence'); ?></span>
						            </a>
						            <div id="pmpro-seq-template-select" class="pmpro-sequence-hidden">
							            <input type="hidden" name="hidden_pmpro_seq_noticetemplate" id="hidden_pmpro_seq_noticetemplate" value="<?php echo esc_attr($settings->noticeTemplate); ?>" >
							            <label for="pmpro_sequence_template"></label>
							            <select name="pmpro_sequence_template" id="pmpro_sequence_template">
								            <?php echo $sequence->pmpro_sequence_listEmailTemplates( $settings ); ?>
							            </select>
							            <p class="pmpro-seq-btns">
								            <a href="#pmproseq_template" id="ok-pmpro-seq-template" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
								            <a href="#pmproseq_template" id="cancel-pmpro-seq-template" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
							            </p>
						            </div>
					            </div>
				            </div>
			            </td>
		            </tr>
		            <tr>
			            <td colspan="2">
				            <div class="pmpro-sequence-hidden pmpro-sequence-noticetime">
					            <label class="pmpro-sequence-label" for="pmpro-seq-noticetime"><?php _e('When:', 'pmprosequence'); ?> </label>
					            <span id="pmpro-seq-noticetime-status" class="pmpro-sequence-status"><?php echo esc_attr($settings->noticeTime); ?></span>
					            <a href="#pmpro-seq-noticetime" id="pmpro-seq-edit-noticetime" class="pmpro-seq-edit">
						            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
						            <span class="screen-reader-text"><?php _e('Select when (tomorrow) to send new content posted alerts for this sequence', 'pmprosequence'); ?></span>
					            </a>
					            <div id="pmpro-seq-noticetime-select" class="pmpro-sequence-hidden">
						            <input type="hidden" name="hidden_pmpro_seq_noticetime" id="hidden_pmpro_seq_noticetime" value="<?php echo esc_attr($settings->noticeTime); ?>" >
						            <label for="pmpro_sequence_noticetime"></label>
						            <select name="pmpro_sequence_noticetime" id="pmpro_sequence_noticetime">
						                <?php echo $sequence->pmpro_sequence_createTimeOpts( $settings ); ?>
						            </select>
						            <p class="pmpro-seq-btns">
							            <a href="#pmproseq_noticetime" id="ok-pmpro-seq-noticetime" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
							            <a href="#pmproseq_noticetime" id="cancel-pmpro-seq-noticetime" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
						            </p>
					            </div>
					            <div>
						            <label class="pmpro-sequence-label" for="pmpro-seq-noticetime"><?php _e('Timezone:', 'pmprosequence'); ?> </label>
						            <span class="pmpro-sequence-status" id="pmpro-seq-noticetimetz-status"><?php echo '  ' . get_option('timezone_string'); ?></span>
					            </div>
					            <div class="pmpro-sequence-subject">
						            <label for="pmpro-seq-subject"><?php _e('Subject:', 'pmprosequence'); ?> </label>
						            <span id="pmpro-seq-subject-status" class="pmpro-sequence-status">"<?php echo ( $settings->subject != '' ? esc_attr($settings->subject) : __('New Content', 'pmprosequence') ); ?>"</span>
						            <a href="#pmpro-seq-subject" id="pmpro-seq-edit-subject" class="pmpro-seq-edit">
							            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
							            <span class="screen-reader-text"><?php _e('Update/Edit the Prefix for the subject of the new conent alert', 'pmprosequence'); ?></span>
						            </a>
						            <div id="pmpro-seq-subject-input" class="pmpro-sequence-hidden">
							            <input type="hidden" name="hidden_pmpro_seq_subject" id="hidden_pmpro_seq_subject" value="<?php echo ( $settings->subject != '' ? esc_attr($settings->subject) : __('New', 'pmprosequence') ); ?>" />
							            <label for="pmpro_sequence_subject"></label>
							            <input type="text" name="pmpro_sequence_subject" id="pmpro_sequence_subject" value="<?php echo ( $settings->subject != '' ? esc_attr($settings->subject) : __('New', 'pmprosequence') ); ?>"/>
							            <p class="pmpro-seq-btns">
								            <a href="#pmproseq_subject" id="ok-pmpro-seq-subject" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
								            <a href="#pmproseq_subject" id="cancel-pmpro-seq-subject" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
							            </p>
						            </div>
					            </div>
					            <div class="pmpro-sequence-excerpt">
						            <label class="pmpro-sequence-label" for="pmpro-seq-excerpt"><?php _e('Intro:', 'pmprosequence'); ?> </label>
						            <span id="pmpro-seq-excerpt-status" class="pmpro-sequence-status">"<?php echo ( $settings->excerpt_intro != '' ? esc_attr($settings->excerpt_intro) : __('A summary for the new content follows:', 'pmprosequence') ); ?>"</span>
						            <a href="#pmpro-seq-excerpt" id="pmpro-seq-edit-excerpt" class="pmpro-seq-edit">
							            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
							            <span class="screen-reader-text"><?php _e('Update/Edit the introductory paragraph for the new content excerpt', 'pmprosequence'); ?></span>
						            </a>
						            <div id="pmpro-seq-excerpt-input" class="pmpro-sequence-hidden">
							            <input type="hidden" name="hidden_pmpro_seq_excerpt" id="hidden_pmpro_seq_excerpt" value="<?php echo ($settings->excerpt_intro != '' ? esc_attr($settings->excerpt_intro) : __('A summary for the new content follows:', 'pmprosequence') ); ?>" />
							            <label for="pmpro_sequence_excerpt"></label>
							            <input type="text" name="pmpro_sequence_excerpt" id="pmpro_sequence_excerpt" value="<?php echo ($settings->excerpt_intro != '' ? esc_attr($settings->excerpt_intro) : __('A summary for the new content follows:', 'pmprosequence') ); ?>"/>
							            <p class="pmpro-seq-btns">
								            <a href="#pmproseq_excerpt" id="ok-pmpro-seq-excerpt" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
								            <a href="#pmproseq_excerpt" id="cancel-pmpro-seq-excerpt" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
							            </p>
						            </div>
					            </div>
					            <div class="pmpro-sequence-hidden pmpro-sequence-dateformat">
						            <label class="pmpro-sequence-label" for="pmpro-seq-dateformat"><?php _e('Date type:', 'pmprosequence'); ?> </label>
						            <span id="pmpro-seq-dateformat-status" class="pmpro-sequence-status">"<?php echo ( trim($settings->dateformat) == false ? __('m-d-Y', 'pmprosequence') : esc_attr($settings->dateformat) ); ?>"</span>
						            <a href="#pmpro-seq-dateformat" id="pmpro-seq-edit-dateformat" class="pmpro-seq-edit">
							            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
							            <span class="screen-reader-text"><?php _e('Update/Edit the format of the !!today!! placeholder (a valid PHP date() format)', 'pmprosequence'); ?></span>
						            </a>
						            <div id="pmpro-seq-dateformat-select" class="pmpro-sequence-hidden">
							            <input type="hidden" name="hidden_pmpro_seq_dateformat" id="hidden_pmpro_seq_dateformat" value="<?php echo ( trim($settings->dateformat) == false ? __('m-d-Y', 'pmprosequence') : esc_attr($settings->dateformat) ); ?>" />
							            <label for="pmpro_pmpro_sequence_dateformat"></label>
							            <select name="pmpro_sequence_dateformat" id="pmpro_sequence_dateformat">
								            <?php echo $sequence->pmpro_sequence_listDateformats( $settings ); ?>
							            </select>
							            <p class="pmpro-seq-btns">
								            <a href="#pmproseq_dateformat" id="ok-pmpro-seq-dateformat" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
								            <a href="#pmproseq_dateformat" id="cancel-pmpro-seq-dateformat" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
							            </p>
						            </div>
					            </div>
				            </div>
			            </td>
	                </tr>
		            <tr>
	                    <td colspan="2"><hr style="width: 100%;" /></td>
	                </tr>
	                <tr>
	                    <td colspan="2" style="padding: 0px; margin 0px;">

	                        <a class="button button-primary button-large" class="pmpro-seq-settings-save" id="pmpro_settings_save" onclick="pmpro_sequence_saveSettings(<?php echo $sequence->sequence_id;?>) ; return false;"><?php _e('Update Settings', 'pmprosequence'); ?></a>
		                    <?php wp_nonce_field('pmpro-sequence-save-settings', 'pmpro_sequence_settings_nonce'); ?>
		                    <div class="seq_spinner"></div>
	                    </td>
	                </tr>
		            <!-- TODO: Enable and implement
	                <tr id="pmpro_sequence_foreshadow" style="display: none;">
	                    <td colspan="2">
	                        <label class="screen-reader-text" for="pmpro_sequence_previewwindow"><? _e('Days to preview', 'pmprosequence'); ?></label>
	                    </td>
	                </tr>
	                <tr id="pmpro_sequence_foreshadow_2" style="display: none;" id="pmpro_sequence_previewWindowOpt">
	                    <td colspan="2">
	                        <select name="pmpro_sequence_foreshadow" id="pmpro_sequence_previewwindow">
	                            <option value="0" <?php selected( intval($settings->previewWindow), '0'); ?> >All</option>
	                            <option value="1" <?php selected( intval($settings->previewWindow), '1'); ?> >1 day</option>
	                            <option value="2" <?php selected( intval($settings->previewWindow), '2'); ?> >2 days</option>
	                            <option value="3" <?php selected( intval($settings->previewWindow), '3'); ?> >3 days</option>
	                            <option value="4" <?php selected( intval($settings->previewWindow), '4'); ?> >4 days</option>
	                            <option value="5" <?php selected( intval($settings->previewWindow), '5'); ?> >5 days</option>
	                            <option value="6" <?php selected( intval($settings->previewWindow), '6'); ?> >1 week</option>
	                            <option value="7" <?php selected( intval($settings->previewWindow), '7'); ?> >2 weeks</option>
	                            <option value="8" <?php selected( intval($settings->previewWindow), '8'); ?> >3 weeks</option>
	                            <option value="9" <?php selected( intval($settings->previewWindow), '8'); ?> >1 month</option>
	                        </select>
	                    </td>
	                </tr>
	                -->
		            <!-- TODO: Enable and implement
	                <tr id="pmpro_sequenceseq_start_0" style="display: none;">
	                    <td>
	                        <input id='pmpro_sequence_enablestartwhen' type="checkbox" value="1" title="<?php _e('Configure start parameters for sequence drip. The default is to start day 1 exactly 24 hours after membership started, using the servers timezone and recorded timestamp for the membership check-out.', 'pmprosequence'); ?>" name="pmpro_sequence_enablestartwhen" <?php echo ($sequence->options->startWhen != 0) ? 'checked="checked"' : ''; ?> />
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
	                            <option value="0" <?php selected( intval($settings->startWhen), '0'); ?> >Immediately</option>
	                            <option value="1" <?php selected( intval($settings->startWhen), '1'); ?> >24 hours after membership started</option>
	                            <option value="2" <?php selected( intval($settings->startWhen), '2'); ?> >At midnight, immediately after membership started</option>
	                            <option value="3" <?php selected( intval($settings->startWhen), '3'); ?> >At midnight, 24+ hours after membership started</option>
	                        </select>
	                    </td>
	                </tr>
	               -->
	            </table>
	            </div>
	        </div>
		<?php
		    $metabox = ob_get_clean();

		    dbgOut('pmpro_sequence_settings_meta_box() - Display the settings meta.');
		    // Display the metabox (print it)
		    echo $metabox;
	    }

        /**
		 * List all template files in email directory for this plugin.
		 *
		 * @param $settings (stdClass) - The settings for the sequence.
		 * @return bool| mixed - HTML containing the Option list
         *
         * @access private
		 */
		private function pmpro_sequence_listEmailTemplates( $settings )
		{
            ob_start();

			?>
				<!-- Default template (blank) -->
				<option value=""></option>
			<?php

            // TODO: Add support for having the email templates in a child-theme or theme location.

			dbgOut('Directory containing templates: ' . dirname(__DIR__) . '/email/');

            $templ_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'email';

			chdir($templ_dir);
			foreach ( glob('*.html') as $file)
			{
				echo('<option value="' . sanitize_file_name($file) . '" ' . selected( esc_attr( $settings->noticeTemplate), sanitize_file_name($file) ) . ' >' . sanitize_file_name($file) .'</option>');
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
		private function pmpro_sequence_createTimeOpts( $settings )
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
					<option value="<?php echo( $hour . ':' . $minute ); ?>"<?php selected( $settings->noticeTime, $hour . ':' . $minute ); ?> ><?php echo( $hour . ':' . $minute ); ?></option>
					<?php
				}
			}

            $selectList = ob_get_clean();

            return $selectList;
		}

        /**
         *
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
        private function pmpro_sequence_listDateformats( $settings ) {

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
                echo('<option value="' . esc_attr($key) . '" ' . selected( esc_attr($settings->dateformat), esc_attr($key) ) . ' >' . esc_attr($val) .'</option>');
            }

            $selectList = ob_get_clean();

            return $selectList;
        }

        /**
         * Fetches the posts associated with this sequence, then generates HTML containing the list.
         *
         * @param bool $echo -- Whether to immediately 'echo' the value or return the HTML to the calling function
         * @return bool|mixed|string -- The HTML containing the list of posts in the sequence
         *
         * @access public
         */
        public function getPostList($echo = false)
		{
			//global $current_user;
			$this->getPosts();

			if(!empty($this->posts))
			{
	            // Order the posts in accordance with the 'sortOrder' option
	            dbgOut('getPostLists(): Sorting posts for display');
	            usort($this->posts, array("PMProSequence", "sortByDelay"));

	            // TODO: Have upcoming posts be listed before or after the currently active posts (own section?) - based on sort setting

				$temp_content = pmpro_sequence_createSequenceList( $this->sequence_id, true, 25, true, null, false	);

				//filter
				$temp_content = apply_filters("pmpro_sequence_get_post_list", $temp_content, $this);

				if($echo)
					echo $temp_content;

				return $temp_content;
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
	    public function isPastDelay( $memberFor, $delay )
	    {
		    // Get the preview offset (if it's defined). If not, set it to 0
		    // for compatibility
		    if ( empty($this->options->previewOffset) ) {
			    // TODO: Figure out why this always runs/get set...
			    dbgOut("isPastDelay() - the previewOffset value doesn't exist yet {$this->options->previewOffset}. Fixing now.");
			    $this->options->previewOffset = 0;
			    $this->save_sequence_meta(); // Save the settings (only the first when this variable is empty)

		    }

	        $offset = $this->options->previewOffset;
		    // dbgOut('Preview enabled and set to: ' . $offset);

		    if ($this->isValidDate($delay))
	        {

	            $now = current_time('timestamp') + ($offset * 86400);

	            // TODO: Add support for startWhen options (once the plugin supports differentiating on when the drip starts)
	            $delayTime = strtotime( $delay . ' 00:00:00.0' );
	            dbgOut('isPastDelay() - Now = ' . $now . ' and delay time = ' . $delayTime );

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
	        if ( empty( $settings ) )
		        $settings = $this->options;

		    if (($sequence_id != 0) && ($sequence_id != $this->sequence_id)) {
			    dbgOut( 'save_sequence_meta() - Unknown sequence ID. Need to instantiate the correct sequence first!' );
			    return false;
		    }

            try {

                // Update the *_postmeta table for this sequence
                update_post_meta($this->sequence_id, '_pmpro_sequence_settings', $settings );

                // Preserve the settings in memory / class context
                dbgOut('save_sequence_meta(): Saved Sequence Settings for ' . $this->sequence_id);
            }
            catch (Exception $e)
            {
	            dbgOut('save_sequence_meta() - Error saving sequence settings for ' . $this->sequence_id . ' Msg: ' . $e->getMessage());
                return false;
            }

	        return true;
	    }

        /**
         * Selects & formats the correct delay value in the list of posts, based on admin settings
         *
         * @param $delay (int) -- The delay value
         * @return bool|string -- The number
         *
         * @access public
         */
        public function displayDelay( $delay ) {

	        dbgOut("displayDelay() - {$this->options->showDelayAs} == " . PMPRO_SEQ_AS_DATE);
            if ( $this->options->showDelayAs == PMPRO_SEQ_AS_DATE) {
                // Convert the delay to a date

                $memberDays = round(pmpro_getMemberDays(), 0);

                $delayDiff = ($delay - $memberDays);
	            dbgOut('displayDelay() - Delay: ' .$delay . ', memberDays: ' . $memberDays . ', delayDiff: ' . $delayDiff);

                return strftime('%x', strtotime("+" . $delayDiff ." days"));
            }

            return $delay; // It's stored as a number, not a date

        }

	    /**
	     * Test whether to show future sequence posts (i.e. not yet available to member)
         *
         * @return bool -- True if the admin has requested that unavailable posts not be displayed.
         *
         * @access public
	     */
	    public function hideUpcomingPosts()
	    {
	        // dbgOut('hideUpcomingPosts(): Do we show or hide upcoming posts?');
	        return $this->options->hidden == 1 ? true : false;
	    }

        /**
         * Returns
         * @param null $user_id -- ID of the user
         * @return bool -- Post ID or FALSE (if error)
         *
         * @access public
         */
        public function get_closestPost( $user_id = null ) {

	        // Get the current day of the membership (as a whole day, not a float)
            $membershipDay = ceil( pmpro_getMemberDays( $user_id ) );

            // Load all posts in this sequence
            $postList = $this->getPosts();

            // Find the post ID in the postList array that has the delay closest to the membershipday.
            $closest = $this->get_closestByDelay( $membershipDay, $postList );

	        dbgOut("get_closestPost() - For user {$user_id} on day {$membershipDay}, the closest post is #{$closest->id} (with a delay value of {$closest->delay})");

            if ( !empty($closest->id) )
                return $closest->id;

			return false;
		}

        /**
         * Compares the object to the array of posts in the sequence
         * @param $delayComp -- Delay value to compare to
         * @param $postArr -- The post object
         * @return stdClass -- The post ID of the post with the delay value closest to the $delayVal
         *
         * @access private
         */
        private function get_closestByDelay( $delayComp, $postArr ) {

	        global $current_user;

	        $distances = array();

	        foreach ( $postArr as $key => $post )
	        {
		        // Only interested in posts we actually have access to.
		        // TODO: Rather than look up one post at a time, should just compare against an array of posts we have access to.
		        if ( pmpro_sequence_hasAccess( $current_user->ID, $post->id, true ) )
		            $distances[ $key ] = abs( $delayComp - ( $this->normalizeDelay( $post->delay ) /* + 1 */) );

	        }

	        return $postArr[ array_search( min( $distances ) , $distances ) ];

        }

	    /**
	     * Convert any date string to a number of days worth of delay (since membership started for the current user)
	     *
	     * @param $delay (int | string) -- The delay value (either a # of days or a date YYYY-MM-DD)
	     * @return mixed (int) -- The # of days since membership started (for this user)
         *
         * @access public
	     */
	    public function normalizeDelay( $delay )
	    {

	        if ( $this->isValidDate($delay) ) {
	            // dbgOut('normalizeDelay(): Delay specified as a valid date: ' . $delay);
	            return $this->convertToDays($delay);
	        }
	        //dbgOut('normalizeDelay(): Delay specified as # of days since membership start: ' . $delay);
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
	    public function convertToDays( $date, $userId = null, $levelId = null )
	    {
		    $days = 0;

	        if ( $this->isValidDate( $date ) )
	        {
		        dbgOut('convertToDays() - Date is valid: ' . $date);

	            $startDate = pmpro_getMemberStartdate( $userId, $levelId); /* Needs userID & Level ID ... */

		        if (empty($startDate))
			            $startDate = 0;

		        dbgOut('convertToDays() - Start Date: ' . $startDate);
		        try {

			        // Use v5.2 and v5.3 compatible function to calculate difference
			        $compDate = strtotime($date);
			        $days = pmpro_seq_datediff($startDate, $compDate); // current_time('timestamp')


		        } catch (Exception $e) {
			        dbgOut('convertToDays() - Error calculating days: ' . $e->getMessage());
		        }
	        }
	        else
	            $days = $date;

	        return $days;
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
        public function getDelayForPost($post_id)
		{
			$key = $this->getPostKey($post_id);

			if($key === false)
	        {
	            dbgOut('No key found in getDelayForPost');
				return false;
	        }
	        else {

	            $delay = $this->normalizeDelay( $this->posts[$key]->delay );
	            dbgOut('getDelayForPost(): Delay for post with id = ' . $post_id . ' is ' .$delay);
	            return $delay;
	        }
		}

        /**
         * Find the post in the sequence and return its key
         *
         * @param $post_id -- The ID of the post
         * @return bool|int|string -- The key for the post
         *
         * @access public
         */
        public function getPostKey($post_id)
		{
			$this->getPosts();

			if(empty($this->posts))
				return false;

			foreach($this->posts as $key => $post)
			{
				if($post->id == $post_id)
					return $key;
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
	    private function sortByDelay($a, $b)
	    {
	        if (empty($this->options->sortOrder))
	        {
	            dbgOut('sortByDelay(): Need sortOrder option to base sorting decision on...');
	            // $sequence = $this->getSequenceByID($a->id);
	            if ( $this->sequence_id !== null)
	            {
	                dbgOut('sortByDelay(): Have valid sequence post ID saved: ' . $this->sequence_id);
	                $this->fetchOptions( $this->sequence_id );
	            }
	        }

	        switch ($this->options->sortOrder)
	        {
	            case SORT_ASC:
	                // dbgOut('sortByDelay(): Sorted in Ascending order');
	                return $this->sortAscending($a, $b);
	                break;
	            case SORT_DESC:
	                // dbgOut('sortByDelay(): Sorted in Descending order');
	                return $this->sortDescending($a, $b);
	                break;
	            default:
	                dbgOut('sortByDelay(): sortOrder not defined');
	        }

		    return false;
	    }

	    /**
         * Sort the two posts in Ascending order
	     * @param $a -- Post to compare (including delay variable)
	     * @param $b -- Post to compare against (including delay variable)
	     * @return int -- Return +1 if the Delay for post $a is greater than the delay for post $b (i.e. delay for b is
	     *                  less than delay for a)
         *
         * @access private
	     */
		private function sortAscending($a, $b)
		{
	        list($aDelay, $bDelay) = $this->normalizeDelays($a, $b);
			// dbgOut('sortAscending() - Delays have been normalized');

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
        private function normalizeDelays($a, $b)
	    {
	        return array($this->convertToDays($a->delay), $this->convertToDays($b->delay));
	    }

	    /**
	     * @param $a -- Post to compare (including delay variable)
	     * @param $b -- Post to compare against (including delay variable)
	     * @return int -- Return -1 if the Delay for post $a is greater than the delay for post $b
         *
         * @access private
	     */
	    private function sortDescending($a, $b)
	    {
	        list($aDelay, $bDelay) = $this->normalizeDelays($a, $b);

	        if ($aDelay == $bDelay)
	            return 0;
	        // Descending Sort Order
	        return ($aDelay > $bDelay) ? -1 : +1;
	    }

        /**
         * Disable the WPcron job for the current sequence
         */
        public function stopSendingNotices() {

            dbgOut("Removing alert notice hook for sequence # " . $this->sequence_id );

            wp_clear_scheduled_hook( 'pmpro_sequence_cron_hook', array( $this->sequence_id ) );
        }
	}
