<?php

// if (! array_key_exists('pmpro-sequences', $GLOBALS) ):

	class PMProSequences
	{
	    public $options;
	    public $sequence_id = 0;
		private $id;
		private $posts; // List of posts
		private $post; // Individual post
		private $error = null;

		//constructor
		function PMProSequences($id = null)
		{
            if (is_null($id) && ($this->sequence_id == 0) ) {
                // Have no sequence ID to play off of..
                dbgOut('No sequence ID or options defined! Checking against global variables');
                global $wp_query;

                if ($wp_query->post->ID) {

                    dbgOut('Found Post ID and loading options if not already loaded ' . $wp_query->post->ID);
                    $this->sequence_id = $wp_query->post->ID;
                } else
                    return false; // ERROR. No sequence ID provided.
            }
            elseif ( ! is_null($id) )
                $this->sequence_id = $this->getSequenceByID($id);
			else
                $this->sequence_id = $id;

            dbgOut('__construct() - Loading sequence options');

            // Set options for the sequence
            $this->options = $this->fetchOptions($this->sequence_id);

			return $this->sequence_id;
		}

		function getSequenceByID($id)
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
	     *
	     * Fetch any options for this specific sequence from the database (stored as post metadata)
	     * Use default options if the sequence ID isn't supplied*
	     *
	     * @param int $sequence_id - The Sequence ID to fetch options for
	     * @return mixed -- Returns array of options if options were successfully fetched & saved.
	     */
	    public function fetchOptions( $sequence_id = 0 )
	    {
	        // Did we receive an ID to process/use?
	        if ($sequence_id != 0) {
                dbgOut('fetchOptions() - Sequence ID supplied by callee: ' . $sequence_id);
            }

            // Does the ID differ from the one this object has stored already?
            if ( ( $this->sequence_id != 0 ) && ( $this->sequence_id != $sequence_id ))
            {
                dbgOut('fetchOptions() - ID defined in class but callee supplied different sequence ID!');
                $this->sequence_id = $sequence_id;
            }
            elseif ($this->sequence_id == 0)
            {
                // This shouldn't be possible... (but never say never!)
                $this->sequence_id = $sequence_id;
            }

	        // Check that we're being called in context of an actual Sequence 'edit' operation
	        dbgOut('fetchOptions(): Attempting to load settings from DB for (' . $this->sequence_id . ') "' . get_the_title($this->sequence_id) . '"');

	        $settings = get_post_meta($this->sequence_id, '_pmpro_sequence_settings', false);
	        $options = $settings[0];


	        // Check whether we need to set any default variables for the settings
	        if ( empty($options) ) {

	            dbgOut('fetchOptions(): No settings found. Using defaults');
	            $options = $this->defaultOptions();
	        }

		    dbgOut('fetchOptions() - Returning the options/settings');
	        return $options;
	    }

	    //populate sequence data by post id passed

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
	        $settings->excerpt_intro = 'A summary of the post follows below:';
		    $settings->replyto = pmpro_getOption("from_email");
		    $settings->fromname = pmpro_getOption("from_name");
		    $settings->subject = 'New: ';
            $settings->dateformat = 'm-d-Y'; // Using American DD-MM-YYYY format.

	        $this->options = $settings; // Save as options for this sequence

	        return $settings;
	    }

	    /**
	     * Save the settings as metadata for the sequence
	     *
	     * @param $post_id -- ID of the sequence these options belong to.
	     * @return int | mixed - Either the ID of the Sequence or its content
	     */
	    function pmpro_sequence_meta_save( $post_id )
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

		    $sequence = new PMProSequences($post_id);

	        dbgOut('pmpro_sequence_meta_save(): Saving settings for sequence ' . $post_id);
	        // dbgOut('From Web: ' . print_r($_REQUEST, true));

	        // OK, we're authenticated: we need to find and save the data
	        if ( isset($_POST['pmpro_sequence_settings_noncename']) ) {

		        dbgOut( 'Have to load new instance of Sequence class' );

		        if ( ! $sequence->options ) {
			        $sequence->options = $this->defaultOptions();
		        }

		        if ( ($retval = pmpro_sequence_settings_save( $post_id, $sequence )) === true ) {

			        if ( $sequence->options->sendNotice == 1 ) {
				        dbgOut( 'pmpro_sequence_meta_save(): Updating the cron job for sequence ' . $sequence->sequence_id );
				        $sequence->updateNoticeCron();
			        }

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
		 */
		function updateNoticeCron()
		{
			try {

	            // Check if the job is previously scheduled. If not, we're using the default cron schedule.
	            if (false !== ($timestamp = wp_next_scheduled( 'pmpro_sequence_cron_hook', array($this->sequence_id) ) )) {

				    // Clear old cronjob for this sequence
		            dbgOut('Current cron job for sequence # ' . $this->sequence_id . ' scheduled for ' . $timestamp);
		            dbgOut('Clearing old cron job for sequence # ' . $this->sequence_id);
				    wp_clear_scheduled_hook($timestamp, 'pmpro_sequence_cron_hook', array( $this->sequence_id ));
	            }

				dbgOut('Cron info: ' . print_r(wp_get_schedule('pmpro_sequence_cron_hook', array($this->sequence_id)), true));

				// Set time (what time) to run this cron job the first time.
				dbgOut('Adding cron job for ' . $this->sequence_id . ' at ' . $this->options->noticeTimestamp);
				wp_schedule_event($this->options->noticeTimestamp, 'hourly', 'pmpro_sequence_cron_hook', array($this->sequence_id));

				$ts = wp_next_scheduled( 'pmpro_sequence_cron_hook', array($this->sequence_id) );

				dbgOut('According to WP, the job is scheduled for: ' . date('d-m-Y H:i:s', $ts) . ' and we asked for ' . date('d-m-Y H:i:s', $this->options->noticeTimestamp));

				if ($ts != $this->options->noticeTimestamp)
					dbgOut('Correctly scheduled cron job for content check?');
			}
			catch (Exception $e) {
				// echo 'Error: ' . $e->getMessage();
				dbgOut('Error updating cron job(s): ' . $e->getMessage());
			}
		}

	    /**
	     * Converts a timeString to a timestamp value (UTC compliant).
	     * Will use the supplied timeString to calculate & return the UTC seconds-since-epoch for that clock time tomorrow.
	     *
	     * @param $timeString (string) -- A clock value ('12:00 AM' for instance)
	     * @return int -- The calculated timestamp value
	     */
	    public function calculateTimestamp( $timeString )
	    {

		    $timestamp = current_time('timestamp');

		    try {
			    /* current time & date */
	            $schedHour = date( 'H', strtotime($timeString));
	            $nowHour = date('H', $timestamp);

			    dbgOut('calculateTimestamp() - Timestring: ' . $timeString . ', scheduled Hour: ' . $schedHour . ' and current Hour: ' .$nowHour );


	            //             06           05
	            $hourDiff = $schedHour - $nowHour;

	            if ($hourDiff >= 1) {
	                dbgOut('calculateTimestamp() - Assuming current day');
	                $when = ''; // Today
	            }
	            else {
		            dbgOut('calculateTimestamp() - Assuming tomorrow');
	                $when = 'tomorrow ';
	            }

			    $timeInput = $when . $timeString . ' ' . get_option('timezone_string');

			    /* Various debug information to log */
			    dbgOut('calculateTimestamp() Supplied timeString: ' . $timeString);
			    dbgOut('calculateTimestamp() strtotime() input: ' . $timeInput);
			    dbgOut('calculateTimestamp() Current UTC timestamp: ' . $timestamp);

			    $timestamp = strtotime($timeInput);

			    /* Calculate */
			    dbgOut('calculateTimestamp() UTC timestamp for timeString (tomorrow): ' . $timestamp);
		    }
		    catch (Exception $e)
		    {
			    dbgOut('calculateTimestamp() Error calculating timestamp: : ' . $e->getMessage());
		    }

	        return $timestamp;
	    }

		function addPost($post_id, $delay)
		{

	        if (! $this->isValidDelay($delay) )
	        {
	            dbgOut('addPost(): Admin specified an invalid delay value for post: ' . ( empty($post_id) ? 'Unknown' :  $post_id) );
	            $this->error = 'Error: Invalid delay value specified.';
	            return false;
	        }

			if(empty($post_id) || !isset($delay))
			{
				$this->error = "Please enter a value for post and delay.";
	            dbgOut('addPost(): No Post ID or delay specified');
				return false;
			}

	        dbgOut('addPost(): Post ID: ' . $post_id . ' and delay: ' . $delay);

			$post = get_post($post_id);

			if(empty($post->ID))
			{
				$this->error = "A post with that id does not exist.";
	            dbgOut('addPost(): No Post with ' . $post_id . ' found');
				return false;
			}

			$this->getPosts();

			//remove any old post with this id
			if($this->hasPost($post_id))
				$this->removePost($post_id);

	        // Calculate delay

			//add post
			$temp = new stdClass();
			$temp->id = $post_id;
			$temp->delay = $delay;

			/* Only add the post if it's not already present. */
			if (! in_array($temp->id, $this->posts))
				$this->posts[] = $temp;

			//sort
	        dbgOut('addPost(): Sorting the Sequence by delay');
			usort($this->posts, array("PMProSequences", "sortByDelay"));

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

	    //add a post to this sequence

	    /**
	     * Validates that the value received follows a valid "delay" format for the post/page sequence
	     *
	     * @param $delay (string) - The specified post delay value
	     * @returns bool - Delay is recognized (parsable).
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

		//remove a post from this sequence

	    /**
	     * Pattern recognize whether the data is a valid date format for this plugin
	     * Expected format: YYYY-MM-DD
	     *
	     * @param $data -- Data to test
	     * @return bool -- true | false
	     */
	    public function isValidDate( $data )
	    {
		    // TODO: This - isValidDate() needs to support an international date format.
	        if ( preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $data) ) {
		        dbgOut('Date value is correcly formatted');
		        return true;
	        }


	        return false;
	    }

		/*
			get array of all posts in this sequence
			force = ignore cache and get data from DB
		*/

		function getPosts($force = false)
		{
			if(!isset($this->posts) || $force)
				$this->posts = get_post_meta($this->sequence_id, "_sequence_posts", true);

			return $this->posts;
		}

		/**
		 * @param $post_id (int) -- Page/post ID to check for inclusion in this sequence.
		 *
		 * @return bool -- True if the post is already included in the sequence. False otherwise
		 */
		function hasPost($post_id)
		{
			$this->getPosts();

			if(empty($this->posts))
				return false;

			foreach($this->posts as $key => $post)
			{
				if($post->id == $post_id) {
					dbgOut('Post # ' . $post_id . ' is already in the sequence.');
					return true;
				}
					return true;
			}

			return false;
		}

		//get key of post with id = $post_id

		function removePost($post_id)
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

			//remove this sequence from the post
			$post_sequence = get_post_meta($post_id, "_post_sequences", true);

			if(is_array($post_sequence) && ($key = array_search($this->id, $post_sequence)) !== false)
			{
				unset($post_sequence[$key]);
				update_post_meta($post_id, "_post_sequences", $post_sequence);

	            dbgOut('removePost(): Post/Page list updated and saved');

	        }

			return true;
		}

		function getDelayForPost($post_id)
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

	    // Returns a "days to delay" value for the posts $a & $b, even if the delay value is a date.

		function getPostKey($post_id)
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
	     *
	     * Convert any date string to a number of days worth of delay (since membership started for the current user)
	     *
	     * @param $delay (int | string) -- The delay value (either a # of days or a date YYYY-MM-DD)
	     * @return mixed (int) -- The # of days since membership started (for this user)
	     */
	    public function normalizeDelay( $delay )
	    {

	        if ( $this->isValidDate($delay) ) {
	            dbgOut('normalizeDelay(): Delay specified as a valid date: ' . $delay);
	            return $this->convertToDays($delay);
	        }
	        dbgOut('normalizeDelay(): Delay specified as # of days since membership start: ' . $delay);
	        return $delay;
	    }

	    /**
	     *
	     * Returns a number of days since the users membership started based on the supplied date.
	     * This allows us to mix sequences containing days since membership start and fixed dates for content drips
	     *
	     * @param $date - Take a date in the format YYYY-MM-DD and convert it to a number of days since membership start (for the current member)
	     * @param $userId - Optional ID for the user being processed
	     * @param $levelId - Optional ID for the level of the user
	     * @return mixed -- Return the # of days calculated
	     */
	    public function convertToDays( $date, $userId = null, $levelId = null )
	    {
		    $days = 0;

		    // dbgOut('In convertToDays()');
		    /*
		    try {
			    $level_id = pmpro_getMembershipLevelForUser();
			    dbgOut('convertToDays() - User Level: ' . $level_id);
		    } catch (Exception $e) {
			    dbgOut('Error getting membership level info: ' . $e->getMessage());
		    }
			*/
	        if ( $this->isValidDate( $date ) )
	        {
		        dbgOut('convertToDays() - Date is valid: ' . $date);

	            $startDate = pmpro_getMemberStartdate(); /* Needs userID & Level ID ... */

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

	            // dbgOut('convertToDays() - Member with start date: ' . date('Y-m-d', $startDate) . ' and end date: ' . $date .  ' for delay day count: ' . $days);

	        }
	        else {
	            $days = $date;
	            // dbgOut('convertToDays() - Member: days of delay from start: ' . $date);
	        }

	        return $days;
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
		public function isAfterOptIn( $user, $post ) {


			return true;
		}

	    /**
	     *
	     * Sort the two post objects (order them) according to the defined sortOrder
	     *
	     * @param $a (post object)
	     * @param $b (post object)
	     * @return int | bool - The usort() return value
	     */
	    function sortByDelay($a, $b)
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
	     * @param $a -- Post to compare (including delay variable)
	     * @param $b -- Post to compare against (including delay variable)
	     * @return int -- Return +1 if the Delay for post $a is greater than the delay for post $b (i.e. delay for b is
	     *                  less than delay for a)
	     */
		public function sortAscending($a, $b)
		{
	        list($aDelay, $bDelay) = $this->normalizeDelays($a, $b);
			// dbgOut('sortAscending() - Delays have been normalized');

	        // Now sort the data
	        if ($aDelay == $bDelay)
	            return 0;
	        // Ascending sort order
	        return ($aDelay > $bDelay) ? +1 : -1;

		}

	    function normalizeDelays($a, $b)
	    {
	        return array($this->convertToDays($a->delay), $this->convertToDays($b->delay));
	    }

	    /**
	     * @param $a -- Post to compare (including delay variable)
	     * @param $b -- Post to compare against (including delay variable)
	     * @return int -- Return -1 if the Delay for post $a is greater than the delay for post $b
	     */
	    public function sortDescending($a, $b)
	    {
	        list($aDelay, $bDelay) = $this->normalizeDelays($a, $b);

	        if ($aDelay == $bDelay)
	            return 0;
	        // Descending Sort Order
	        return ($aDelay > $bDelay) ? -1 : +1;
	    }

		/**
		 *
		 * Send email to userID about access to new post.
		 *
		 * @param $post_id -- ID of post to send email about
		 * @param $user_id -- ID of user to send the email to.
		 * @param $seq_id -- ID of sequence to process (not used)
		 * @return bool - True if sent successfully. False otherwise.
		 *
		 */
		function sendEmail($post_id, $user_id, $seq_id)
		{
			$email = new PMProEmail();
	        // $sequence = new PMProSequences($seq_id);
	        $settings = $this->options;

			$user = get_user_by('id', $user_id);
			$post = get_post($post_id);
			$templ = preg_split('/\./', $settings->noticeTemplate); // Parse the template name

			dbgOut('sendEmail() - Setting sender information');

			$email->from = $settings->replyto; // = pmpro_getOption('from_email');
			$email->fromname = $settings->fromname; // = pmpro_getOption('from_name');

			$email->email = $user->user_email;
			$email->ptitle = $post->post_title;

			$email->subject = sprintf('%s: %s', $settings->subject, $post->post_title);
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

			dbgOut('sendEmail() - Array contains: ' . print_r($email->data, true));

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
	/*
	    public function convertToDate( $days )
	    {
	        $startDate = pmpro_getMemberStartdate();
	        $endDate = date( 'Y-m-d', strtotime( $startDate . " +" . $days . ' days' ));
	        dbgOut('C2Date - Member start date: ' . date('Y-m-d', $startDate) . ' and end date: ' . $endDate .  ' for delay day count: ' . $days);
	        return $endDate;
	    }
	*/

		function createCPT()
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

		/*
			Create the Custom Post Type for the Sequence/Sequences
		*/

		function checkForMetaBoxes()
		{
			//add meta boxes
			if (is_admin())
			{
				wp_enqueue_style('pmpros-select2', plugins_url('css/select2.css', dirname(__FILE__)), '', '3.1', 'screen');
				wp_enqueue_script('pmpros-select2', plugins_url('js/select2.js', dirname(__FILE__)), array( 'jquery' ), '3.1' );

				add_action('admin_menu', array("PMProSequences", "defineMetaBoxes"));
	            add_action('save_post', array('PMProSequences', 'pmpro_sequence_meta_save'), 10, 2);
			}
		}

		/*
			Include the CSS, Javascript and load/define Visual editor Meta boxes
		*/

	    /**
	     * Add the actual meta box definitions as add_meta_box() functions (3 meta boxes; One for the page meta,
	     * one for the Settings & one for the sequence posts/page definitions.
	     */
	    function defineMetaBoxes()
		{
			//PMPro box
			add_meta_box('pmpro_page_meta', __('Require Membership', 'pmprosequence'), 'pmpro_page_meta', 'pmpro_sequence', 'side');

			// sequence settings box (for posts & pages)
	        add_meta_box('pmpros-sequence-settings', __('Settings for this Sequence', 'pmprosequence'), array("PMProSequences", 'pmpro_sequence_settings_meta_box'), 'pmpro_sequence', 'side', 'high');
	//        add_meta_box('pmpro_sequence_settings_meta', __('Settings', 'pmprosequence'), 'settings_page_meta', 'page', 'side');

			//sequence meta box
			add_meta_box('pmpro_sequence_meta', __('Posts in this Sequence', 'pmprosequence'), array("PMProSequences", "sequenceMetaBox"), 'pmpro_sequence', 'normal', 'high');

	    }

		function sequenceMetaBox()
		{
			global $post;

			if (empty($this))
				$sequence = new PMProSequences($post->ID);
			else
				$sequence = $this;

	        $sequence->fetchOptions($post->ID);

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
		 * 	   Refreshes the Post list for the sequence
		 */
		function getPostListForMetaBox()
		{
			global $wpdb;

			//show posts
			$this->getPosts();

	        dbgOut('Displaying the back-end meta box content');
	        // usort($this->posts, array("PMProSequences", "sortByDelay"));

			ob_start();
			?>

			<?php // if(!empty($this->error)) { ?>
				<div id="pmpro-seq-error" class="message error"><?php echo $this->error;?></div>
			<?php //} ?>
			<table id="pmpro_sequencetable" class="pmpro_sequence_postscroll wp-list-table widefat fixed">
			<thead>
				<th><?php _e('Order'); ?></th>
				<th width="50%"><?php _e('Title', 'pmprosequence'); ?></th>
	            <?php dbgOut('Delay Type: ' . $this->options->delayType); ?>
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
				$this->setError( __('No posts/pages found for this sequence', 'pmprosequence'));
			?>
			<?php
			}
			else
			{
				foreach($this->posts as $post)
				{
				?>
					<tr>
						<td class="pmpro_sequence_tblNumber"><?php echo $count?>.</td>
						<td class="pmpro_sequence_tblPostname"><?php echo get_the_title($post->id)?></td>
						<td class="pmpro_sequence_tblNumber"><?php echo $post->delay ?></td>
	                    <?php dbgOut('Sequence entry # ' . $count . ' for post ' . $post->id . ' delayed ' . $this->normalizeDelay($post->delay)); ?>
						<td><a href="javascript:pmpro_sequence_editPost('<?php echo $post->id; ?>'); void(0); "><?php _e('Edit','pmprosequence'); ?></a></td>
						<td>
							<a href="javascript:pmpro_sequence_editEntry('<?php echo $post->id;?>', '<?php echo $post->delay;?>'); void(0);"><?php _e('Update', 'pmprosequence'); ?></a>
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
	                            <th id="pmpro_sequence_delayentrylabel"><?php _e('Days to delay', 'pmprosequence'); ?></th>
	                        <?php elseif ( $this->options->delayType == 'byDate'): ?>
	                            <th id="pmpro_sequence_delayentrylabel"><?php _e("Release on (YYYY-MM-DDD)", 'pmprosequence'); ?></th>
	                        <?php else: ?>
	                            <th id="pmpro_sequence_delayentrylabel"><?php _e('Not Defined', 'pmprosequence'); ?></th>
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
								if ( ($allposts = $this->getPostListFromDB()) !== FALSE)
									foreach($allposts as $p)
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

			return array(
				'success' => ( is_null($this->getError()) ? true : false),
				'message' => $this->getError(),
				'html' => $html,
			);

		}

	    //this is the Sequence meta box

		/**
		 * Get all posts with status 'published', 'draft', 'scheduled', 'pending review' or 'private' from the DB
		 *
		 * @return array | bool -- All posts of the post_types defined in the pmpro_sequencepost_types filter)
		 */
		function getPostListFromDB() {

			global $wpdb;

			$pmpro_sequencepost_types = apply_filters("pmpro_sequencepost_types", array("post", "page") );

			$sql = $wpdb->prepare("
					SELECT ID, post_title, post_status
					FROM $wpdb->posts
					WHERE post_status IN('publish', 'draft', 'future', 'pending', 'private')
					AND post_type IN ('" . implode("','", $pmpro_sequencepost_types) . "')
					AND post_title <> ''
					ORDER BY post_title
				");

			if ( NULL !== ($allposts = $wpdb->get_results($sql)) )
				return $allposts;
			else
				return FALSE;
		}

	    /**
	     * Used to label the post list in the metabox
	     *
	     * @param $post_state -- The current post state (Draft, Scheduled, Under Review, Private, other)
	     * @return null|string -- Return the correct postfix for the post
	     */
	    function setPostStatus( $post_state )
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

		public function getError() {
			return $this->error;
		}

		public function setError( $msg ) {
			$this->error = $msg;
		}

	    /**
	     * Adds notification opt-in to list of posts/pages in sequence.
	     *
	     * @return string -- The HTML containing a form (if the sequence is configured to let users receive notices)
	     */
	    function pmpro_sequence_addUserNoticeOptIn( )
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
	     * Define and create the metabox for the Sequence Settings (per sequence page/list)
	     *
	     * @param $object -- The class object (sequence class)
	     * @param $box -- The metabox object
	     *
	     */
	    function pmpro_sequence_settings_meta_box( $object, $box )
	    {
	        global $post;

		    if (empty($this))
			    $sequence = new PMProSequences($post->ID);
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
		                    <input type="checkbox" value="1" id="pmpro_sequence_lengthvisible" name="pmpro_sequence_lengthvisible" title="<?php _e('Whether to show the &quot;You are on day NNN of your membership&quot; text', 'pmprosequence'); ?>" <?php checked($settings->lengthVisible, 1); ?> />
		                    <input type="hidden" name="hidden_pmpro_seq_lengthvisible" id="hidden_pmpro_seq_lengthvisible" value="<?php echo esc_attr($settings->lengthVisible); ?>" >
	                    </td>
	                    <td><label class="selectit"><?php _e('Show membership length info', 'pmprosequence'); ?></label></td>
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
	                                <select onchange="javascript:pmpro_sequence_delayTypeChange(<?php echo $sequence->sequence_id; ?>); return false;" name="pmpro_sequence_delaytype" id="pmpro_sequence_delaytype">
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
							            <span id="pmpro-seq-fromname-status" class="pmpro-sequence-status"><?php _e( ($settings->fromname != '' ? esc_attr($settings->fromname) : pmpro_getOption("from_name")) ); ?></span>
							            <a href="#pmpro-seq-fromname" id="pmpro-seq-edit-fromname" class="pmpro-seq-edit">
								            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
								            <span class="screen-reader-text"><?php _e('Enter the name to use for the sender of the alert', 'pmprosequence'); ?></span>
							            </a>
						            </div>
						            <div id="pmpro-seq-email-input" class="pmpro-sequence-hidden">
							            <input type="hidden" name="hidden_pmpro_seq_replyto" id="hidden_pmpro_seq_replyto" value="<?php _e(($settings->replyto != '' ? esc_attr($settings->replyto) : pmpro_getOption("from_email"))); ?>" />
							            <input type="text" name="pmpro_sequence_replyto" id="pmpro_sequence_replyto" value="<?php _e(($settings->replyto != '' ? esc_attr($settings->replyto) : pmpro_getOption("from_email")));; ?>"/>
							            <input type="hidden" name="hidden_pmpro_seq_fromname" id="hidden_pmpro_seq_fromname" value="<?php _e(($settings->fromname != '' ? esc_attr($settings->fromname) : pmpro_getOption("from_name"))); ?>" />
							            <input type="text" name="pmpro_sequence_fromname" id="pmpro_sequence_fromname" value="<?php _e(($settings->fromname != '' ? esc_attr($settings->fromname) : pmpro_getOption("from_name")));; ?>"/>
							            <p class="pmpro-seq-btns">
								            <a href="#pmproseq_email" id="ok-pmpro-seq-email" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
								            <a href="#pmproseq_email" id="cancel-pmpro-seq-email" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
							            </p>
						            </div>
					            </div>

					            <div class="pmpro-sequence-hidden pmpro-sequence-template">
						            <hr width="60%"/>
						            <label class="pmpro-sequence-label" for="pmpro-seq-template"><?php _e('Template:', 'pmprosequence'); ?> </label>
						            <span id="pmpro-seq-template-status" class="pmpro-sequence-status"><?php _e( esc_attr( $settings->noticeTemplate ) ); ?></span>
						            <a href="#pmpro-seq-template" id="pmpro-seq-edit-template" class="pmpro-seq-edit">
							            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
							            <span class="screen-reader-text"><?php _e('Select the template to use when posting new content in this sequence', 'pmprosequence'); ?></span>
						            </a>
						            <div id="pmpro-seq-template-select" class="pmpro-sequence-hidden">
							            <input type="hidden" name="hidden_pmpro_seq_noticetemplate" id="hidden_pmpro_seq_noticetemplate" value="<?php echo esc_attr($settings->noticeTemplate); ?>" >
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
					            <span id="pmpro-seq-noticetime-status" class="pmpro-sequence-status"><?php _e(esc_attr($settings->noticeTime)); ?></span>
					            <a href="#pmpro-seq-noticetime" id="pmpro-seq-edit-noticetime" class="pmpro-seq-edit">
						            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
						            <span class="screen-reader-text"><?php _e('Select when (tomorrow) to send new content posted alerts for this sequence', 'pmprosequence'); ?></span>
					            </a>
					            <div id="pmpro-seq-noticetime-select" class="pmpro-sequence-hidden">
						            <input type="hidden" name="hidden_pmpro_seq_noticetime" id="hidden_pmpro_seq_noticetime" value="<?php echo esc_attr($settings->noticeTime); ?>" >
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
							            <input type="text" name="pmpro_sequence_excerpt" id="pmpro_sequence_excerpt" value="<?php echo ($settings->excerpt_intro != '' ? esc_attr($settings->excerpt_intro) : __('A summary for the new content follows:', 'pmprosequence') ); ?>"/>
							            <p class="pmpro-seq-btns">
								            <a href="#pmproseq_excerpt" id="ok-pmpro-seq-excerpt" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
								            <a href="#pmproseq_excerpt" id="cancel-pmpro-seq-excerpt" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
							            </p>
						            </div>
					            </div>
					            <div class="pmpro-sequence-hidden pmpro-sequence-dateformat">
						            <label class="pmpro-sequence-label" for="pmpro-seq-dateformat"><?php _e('Date Type:', 'pmprosequence'); ?> </label>
						            <span id="pmpro-seq-dateformat-status" class="pmpro-sequence-status">"<?php echo ( $settings->dateformat != '' ? esc_attr($settings->dateformat) : 'd-m-Y' ); ?>"</span>
						            <a href="#pmpro-seq-dateformat" id="pmpro-seq-edit-dateformat" class="pmpro-seq-edit">
							            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
							            <span class="screen-reader-text"><?php _e('Update/Edit the format of the !!today!! placeholder (a valid PHP date() format)', 'pmprosequence'); ?></span>
						            </a>
						            <div id="pmpro-seq-dateformat-select" class="pmpro-sequence-hidden">
							            <input type="hidden" name="hidden_pmpro_seq_dateformat" id="hidden_pmpro_seq_dateformat" value="<?php ($settings->dateformat != '' ? esc_attr($settings->dateformat) : __('d-m-Y:', 'pmprosequence') ); ?>" />
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

	                        <a class="button button-primary button-large" class="pmpro-seq-settings-save" id="pmpro_settings_save" onclick="javascript:pmpro_sequence_saveSettings(<?php echo $sequence->sequence_id;?>) ; return false;"><?php _e('Update Settings', 'pmprosequence'); ?></a>
		                    <?php wp_nonce_field('pmpro-sequence-save-settings', 'pmpro_sequence_settings_nonce'); ?>
		                    <div class="seq_spinner"></div>
	                    </td>
	                </tr>
		            <!-- TODO: Enable and implement
	                <tr id="pmpro_sequence_foreshadow" style="display: none;">
	                    <td colspan="2">
	                        <label class="screen-reader-text" for="pmpro_sequence_previewwindow"><? _e('Days to preview'); ?></label>
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

		//this function returns a UL with the current posts

        /**
		 * List all template files in email directory for this plugin.
		 *
		 * @param $settings (stdClass) - The settings for the sequence.
		 * @return bool| mixed - HTML containing the Option list
		 */
		function pmpro_sequence_listEmailTemplates( $settings )
		{
            ob_start();

			?>
				<!-- Default template (blank) -->
				<option value=""></option>
			<?php
			$files = array();

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
		 */
		function pmpro_sequence_createTimeOpts( $settings )
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
         */
        function pmpro_sequence_listDateformats( $settings ) {
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
         */
        function getPostList($echo = false)
		{
			global $current_user;
			$this->getPosts();
			if(!empty($this->posts))
			{
	            // Order the posts in accordance with the 'sortOrder' option
	            dbgOut('getPostLists(): Sorting posts for display');
	            usort($this->posts, array("PMProSequences", "sortByDelay"));

	            // TODO: Have upcoming posts be listed before or after the currently active posts (own section?) - based on sort setting
				dbgOut('getPostsLists() - Sorted posts in configured order');
				$posts_listed = false;
				$empty_notification = false;

				ob_start();
				?>
				<ul id="pmpro_sequence-<?php echo $this->id; ?>" class="pmpro_sequence_list">
				<?php
					dbgOut('Post count: ' . count($this->posts));
					foreach($this->posts as $sp)
					{
	                    $memberFor = pmpro_getMemberDays();

						if ($this->isPastDelay( $memberFor, $sp->delay )) {
							$posts_listed = true;
	                ?>
	                    <li>
	                        <?php dbgOut('Post ' . $sp->id . ' delay: ' . $this->displayDelay($sp->delay)); ?>
							<span class="pmpro_sequence_item-title"><a href="<?php echo get_permalink($sp->id);?>"><?php echo get_the_title($sp->id);?></a></span>
							<span class="pmpro_sequence_item-available"><a class="pmpro_btn pmpro_btn-primary" href="<?php echo get_permalink($sp->id);?>"> <?php _e("Available Now", 'pmprosequence'); ?></a></span>
	                    </li>
                <?php   }
						 elseif ( (! $this->isPastDelay( $memberFor, $sp->delay )) && ( ! $this->hideUpcomingPosts() ) ) { ?>
	                    <li>
		                    <?php dbgOut('Show upcoming post #:' . $sp->id); ?>
							<span class="pmpro_sequence_item-title"><?php echo get_the_title($sp->id);?></span>

		                    <span class="pmpro_sequence_item-unavailable"><?php echo sprintf( __('available on %s'), ($this->options->delayType == 'byDays' && $this->options->showDelayAs == PMPRO_SEQ_AS_DAYNO) ? __('day', 'pmprosequence') : ''); ?> <?php echo $this->displayDelay($sp->delay);?></span>
	                    </li>
				<?php   }
						elseif ( ( $posts_listed == false ) && ($empty_notification == false) ) {

							$empty_notification = true;
							?>
							<span><center>There is <em>no content available</em> for you at this time. Please check back later.</center></span>
				<?php   }; ?>
						<!-- TODO: Add text for when there are no posts shown because they're all hidden (future) & all future posts are to be "hidden" -->
						<div class="clear"></div>
					<?php
					};
				?>
				</ul>
				<?php
				$temp_content = ob_get_contents();
				ob_end_clean();

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
	     */
	    public function isPastDelay( $memberFor, $delay )
	    {
		    // Get the preview offset (if it's defined). If not, set it to 0
		    // for compatibility
		    if ( empty($this->options->previewOffset) || is_null($this->options->previewOffset) ) {

			    dbgOut("isPastDelay() - the previewOffset value doesn't exist yet. Fixing it now");
			    $this->options->previewOffset = 0;
			    $this->save_sequence_meta(); // Save the settings (only the first this variable is empty)
			    dbgOut("isPastDelay() - the previewOffset value being saved");

		    }

	        $offset = $this->options->previewOffset;

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
	     *
	     * Save the settings to the Wordpress DB.
	     *
	     * @param $settings (array) -- Settings for the Sequence
	     * @param $sequence_id (int) -- The ID for the Sequence
	     * @return bool - Success or failure for the save operation
	     */
	    function save_sequence_meta( $settings = null, $sequence_id = 0)
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

        public function displayDelay( $delay ) {

            if ( $this->options->showDelayAs == PMPRO_SEQ_AS_DATE) {
                // Convert the delay to a date
                $memberDays = round(pmpro_getMemberDays(), 0);

                $delayDiff = ($delay - $memberDays);
	            dbgOut('Delay: ' .$delay . ', memberDays: ' . $memberDays . ', delayDiff: ' . $delayDiff);
                return date('Y-m-d', strtotime("+" . $delayDiff ." days"));
            }

            return $delay; // It's stored as a number, not a date

        }

	    /**
	     * Test whether to show future sequence posts (i.e. not yet available to member)
         *
         * @returns bool -- True if the admin has requested that unavailable posts not be displayed.
	     */
	    public function hideUpcomingPosts()
	    {
	        // dbgOut('hideUpcomingPosts(): Do we show or hide upcoming posts?');
	        return $this->options->hidden == 1 ? true : false;
	    }


        public function get_closestByDelay( $delayVal, $objArr, $userId = null ) {

            $closest = null;

            foreach($objArr as $item) {

                if ( ($closest == null) || (
                    ( abs($delayVal - $closest) > abs($this->normalizeDelay($item->delay) - $delayVal) )
                     && pmpro_sequence_hasAccess( $userId, $item->id ) ) )
                    $cllosest = $item->id;
            }

            return $closest;
        }

		public function get_closestPost( $user_id = null ) {

            $membershipDay = pmpro_getMemberDays( $user_id, null );

            // Load all posts in the sequence
            $postList = $this->getPosts();

            $closestPostId = getClosestByDelay( $user_id, $membershipDay , $postList );

            if ( !empty( $closestPostId ) )
                return $closestPostId;

			return false;
		}
	}

//	$GLOBALS['pmpro-sequences'] = new PMProSequences();

//endif;
