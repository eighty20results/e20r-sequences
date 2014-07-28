<?php

// if (! array_key_exists('pmpro-sequences', $GLOBALS) ):
define('PMPRO_SEQUENCE_DEBUG', true);

	class PMProSequences
	{
	    public $options;
	    public $sequence_id = 0;
		private $id;
		private $posts;
		private $error = null;

		//constructor
		function PMProSequences($id = null)
		{
			if ( ! empty($id) || ($this->sequence_id != 0))
	        {
	            // $this->dbgOut('__constructor() - Sequence ID: ' . $id);

	            $this->sequence_id = $this->getSequenceByID($id);
		        $this->options = $this->fetchOptions();

	        }
	        else {

	            if ($this->sequence_id != 0) {

	                $this->dbgOut('No ID supplied to __construct(), but ID was set before, so loading options');
		            $this->options = $this->fetchOptions( $this->sequence_id );
	            }
	            else {

	                $this->dbgOut('No sequence ID or options defined! Checking against global variables');
		            $this->options = $this->defaultOptions();

		            global $wp_query;

	                if ($wp_query->post->ID) {

	                    $this->dbgOut('Found Post ID and loading options if not already loaded ' . $wp_query->post->ID);
	                    $this->sequence_id = $wp_query->post->ID;
		                $this->options = $this->fetchOptions($this->sequence_id);
	                }
	            }
	        }

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
	        if ($sequence_id != 0)
	        {
	            self::dbgOut('fetchOptions() - Sequence ID supplied by callee: ' . $sequence_id);

	            // Does the ID differ from the one this object has stored already?
	            if ( ( $this->sequence_id != 0 ) && ( $this->sequence_id != $sequence_id ))
	            {
	                self::dbgOut('fetchOptions() - ID defined in class but callee supplied different sequence ID!');
	                $this->sequence_id = $sequence_id;
	            }
	            elseif ($this->sequence_id == 0)
	            {
	                // This shouldn't be possible... (but never say never!)
	                $this->sequence_id = $sequence_id;
	            }
	        }

	        // Check that we're being called in context of an actual Sequence 'edit' operation
	        self::dbgOut('fetchOptions(): Attempting to load settings from DB for (' . $this->sequence_id . ') "' . get_the_title($this->sequence_id) . '"');
	        $settings = get_post_meta($this->sequence_id, '_pmpro_sequence_settings', false);
	        $options = $settings[0];


	        // Check whether we need to set any default variables for the settings
	        if ( empty($options) ) {

	            self::dbgOut('fetchOptions(): No settings found. Using defaults');
	            $options = self::defaultOptions();
	        }

		    self::dbgOut('fetchOptions() - Returning the options/settings');
	        return $options;
	    }

	    //populate sequence data by post id passed

	    /**
	     * Debug function (if executes if DEBUG is defined)
	     *
	     * @param $msg -- Debug message to print to debug log.
	     */
	    public function dbgOut($msg)
	    {
	        if (PMPRO_SEQUENCE_DEBUG)
	        {
	            $tmpFile = 'sequence_debug_log.txt';
	            $fh = fopen($tmpFile, 'a');
	            fwrite($fh, $msg . "\r\n");
	            fclose($fh);
	        }
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
	     */
	    public function defaultOptions()
	    {
	        $settings = new stdClass();

	        $settings->hidden =  0; // 'hidden' (Show them)
	        $settings->lengthVisible = 1; //'lengthVisible'
	        $settings->sortOrder = SORT_ASC; // 'sortOrder'
	        $settings->delayType = 'byDays'; // 'delayType'
	        $settings->startWhen =  0; // startWhen == immediately (in current_time('timestamp') + n seconds)
		    $settings->sendNotice = 1; // sendNotice == Yes
		    $settings->noticeTemplate = 'new_content.html'; // Default plugin template
		    $settings->noticeTime = '00:00'; // At Midnight (server TZ)
	        $settings->noticeTimestamp = current_time('timestamp'); // The current time (in UTC)
	        $settings->excerpt_intro = 'A summary of the post follows below:';
		    $settings->replyto = pmpro_getOption("from_email");
		    $settings->fromname = pmpro_getOption("from_name");
		    $settings->subject = 'New: ';

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
		        self::dbgOut('pmpro_sequence_meta_save(): No post ID supplied...');
		        return false;
	        }

		    self::dbgOut('Attempting to save post meta');

		    if ( wp_is_post_revision( $post_id ) )
			    return $post_id;

		    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
			    return $post_id;
		    }

		    if ( $post->post_type !== 'pmpro_sequence' )
			    return $post_id;

		    $sequence = new PMProSequences($post_id);

	        self::dbgOut('pmpro_sequence_meta_save(): Saving settings for sequence ' . $post_id);
	        // self::dbgOut('From Web: ' . print_r($_REQUEST, true));

	        // OK, we're authenticated: we need to find and save the data
	        if ( isset($_POST['pmpro_sequence_settings_noncename']) ) {

		        self::dbgOut( 'Have to load new instance of Sequence class' );

		        if ( ! $sequence->options ) {
			        $sequence->options = $this->defaultOptions();
		        }

		        if ( ($retval = pmpro_sequence_settings_save( $post_id, $sequence )) === true ) {

			        if ( $sequence->options->sendNotice == 1 ) {
				        $sequence->dbgOut( 'pmpro_sequence_meta_save(): Updating the cron job for sequence ' . $sequence->sequence_id );
				        $sequence->updateNoticeCron();
			        }

			        $sequence->dbgOut( 'pmpro_sequence_meta_save(): Saved metadata for sequence #' . $post_id );

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
	     * @param $sequence -- stdObject - PMPro Sequence Object
		 */
		function updateNoticeCron()
		{
			try {

	            // Check if the job is previously scheduled. If not, we're using the default cron schedule.
	            if (false !== ($timestamp = wp_next_scheduled( 'pmpro_sequence_cron_hook', array($this->sequence_id) ) )) {

				    // Clear old cronjob for this sequence
		            $this->dbgOut('Current cron job for sequence # ' . $this->sequence_id . ' scheduled for ' . $timestamp);
		            $this->dbgOut('Clearing old cron job for sequence # ' . $this->sequence_id);
				    wp_clear_scheduled_hook($timestamp, 'pmpro_sequence_cron_hook', array( $this->sequence_id ));
	            }

				$this->dbgOut('Cron info: ' . print_r(wp_get_schedule('pmpro_sequence_cron_hook', array($this->sequence_id)), true));

				// Set time (what time) to run this cron job the first time.
				$this->dbgOut('Adding cron job for ' . $this->sequence_id . ' at ' . $this->options->noticeTimestamp);
				wp_schedule_event($this->options->noticeTimestamp, 'hourly', 'pmpro_sequence_cron_hook', array($this->sequence_id));

				$ts = wp_next_scheduled( 'pmpro_sequence_cron_hook', array($this->sequence_id) );

				$this->dbgOut('According to WP, the job is scheduled for: ' . date('d-m-Y H:i:s', $ts) . ' and we asked for ' . date('d-m-Y H:i:s', $this->options->noticeTimestamp));

				if ($ts != $this->options->noticeTimestamp)
					$this->dbgOut('Correctly scheduled cron job for content check?');
			}
			catch (Exception $e) {
				// echo 'Error: ' . $e->getMessage();
				$this->dbgOut('Error updating cron job(s): ' . $e->getMessage());
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

			    $this->dbgOut('calculateTimestamp() - Timestring: ' . $timeString . ', scheduled Hour: ' . $schedHour . ' and current Hour: ' .$nowHour );


	            //             06           05
	            $hourDiff = $schedHour - $nowHour;

	            if ($hourDiff >= 1) {
	                $this->dbgOut('calculateTimestamp() - Assuming current day');
	                $when = ''; // Today
	            }
	            else {
		            $this->dbgOut('calculateTimestamp() - Assuming tomorrow');
	                $when = 'tomorrow ';
	            }

			    $timeInput = $when . $timeString . ' ' . get_option('timezone_string');

			    /* Various debug information to log */
			    $this->dbgOut('calculateTimestamp() Supplied timeString: ' . $timeString);
			    $this->dbgOut('calculateTimestamp() strtotime() input: ' . $timeInput);
			    $this->dbgOut('calculateTimestamp() Current UTC timestamp: ' . $timestamp);

			    $timestamp = strtotime($timeInput);

			    /* Calculate */
			    $this->dbgOut('calculateTimestamp() UTC timestamp for timeString (tomorrow): ' . $timestamp);
		    }
		    catch (Exception $e)
		    {
			    $this->dbgOut('calculateTimestamp() Error calculating timestamp: : ' . $e->getMessage());
		    }

	        return $timestamp;
	    }

	    /**
	     *
	     * Save the settings to the Wordpress DB.
	     *
	     * @param $settings (array) -- Settings for the Sequence
	     * @param $post_id (int) -- The ID for the Sequence
	     * @return bool - Success or failure for the save operation
	     */
	    function save_sequence_meta( $settings, $post_id )
	    {
	        // Make sure the settings array isn't empty (no settings defined)
	        if (! empty( $settings ))
	        {
	            try {

	                // Update the *_postmeta table for this sequence
	                update_post_meta($post_id, '_pmpro_sequence_settings', $settings );

	                // Preserve the settings in memory / class context
	                $this->dbgOut('save_sequence_meta(): Saved Sequence Settings for ' . $post_id);
	            }
	            catch (Exception $e)
	            {
	                return false;
	            }
	        }

	        return true;
	    }

	    //add a post to this sequence

		function addPost($post_id, $delay)
		{

	        if (! $this->isValidDelay($delay) )
	        {
	            $this->dbgOut('addPost(): Admin specified an invalid delay value for post: ' . ( empty($post_id) ? 'Unknown' :  $post_id) );
	            $this->error = 'Error: Invalid delay value specified.';
	            return false;
	        }

			if(empty($post_id) || !isset($delay))
			{
				$this->error = "Please enter a value for post and delay.";
	            $this->dbgOut('addPost(): No Post ID or delay specified');
				return false;
			}

	        $this->dbgOut('addPost(): Post ID: ' . $post_id . ' and delay: ' . $delay);

			$post = get_post($post_id);

			if(empty($post->ID))
			{
				$this->error = "A post with that id does not exist.";
	            $this->dbgOut('addPost(): No Post with ' . $post_id . ' found');
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
	        $this->dbgOut('addPost(): Sorting the Sequence by delay');
			usort($this->posts, array("PMProSequences", "sortByDelay"));

			//save
			update_post_meta($this->id, "_sequence_posts", $this->posts);

			//Get any previously existing sequences this post/page is linked to
			$post_sequence = get_post_meta($post_id, "_post_sequences", true);

	        // Is there any previously saved sequence ID found for the post/page?
			if(empty($post_sequence))
	        {
	            $this->dbgOut('addPost(): Not previously defined sequence(s) found for this post (ID: ' . $post_id . ')');
	            $post_sequence = array($this->id);
	        }
	        else
	        {
	            $this->dbgOut('addPost(): Post/Page w/id ' . $post_id . ' belongs to more than one sequence already: ' . print_r($post_sequence, true));

	            if ( !is_array($post_sequence) ) {

	                // $this->dbgOut('AddPost(): Previously defined sequence(s) found for this post (ID: ' . $post_id . '). Sequence data: ' . print_r($post_sequence, true));
	                $this->dbgOut('addPost(): Not (yet) an array of posts. Adding the single new post to a new array');
	                $post_sequence = array($this->id);
	            }
	            else {

	                // Bug Fix: Never checked if the Post/Page ID was already listed in the sequence.
		            $tmp = array_count_values($post_sequence);
		            $cnt = $tmp[$this->id];

	                if ( $cnt == 0 ) {

	                    // This is the first sequence this post is added to
	                    $post_sequence[] = $this->id;
	                    $this->dbgOut('addPost(): Appended post (ID: ' . $temp->id . ') to sequence ' . $this->id);

	                }
	                else {

		                // Check whether there are repeat entries for the current sequence
		                if ($cnt > 1) {

			                // There are so get rid of the extras (this is a backward compatibility feature due to a bug.)
			                $this->dbgOut('addPost() - More than one entry in the array. Clean it up!');

			                $clean = array_unique( $post_sequence );

			                $this->dbgOut('addPost() - Cleaned array: ' . print_r($clean, true));
			                $post_sequence = $clean;
		                }
	                }
	            }

	        }

			//save
			update_post_meta($post_id, "_post_sequences", $post_sequence);
	        $this->dbgOut('addPost(): Post/Page list updated and saved');

			return true;
	    }

		//remove a post from this sequence

	    /**
	     * Validates that the value received follows a valid "delay" format for the post/page sequence
	     */
	    public function isValidDelay( $delay )
	    {
	        $this->dbgOut('isValidDelay(): Delay value is: ' . $delay);

	        switch ($this->options->delayType)
	        {
	            case 'byDays':
	                $this->dbgOut('isValidDelay(): Delay configured as "days since membership start"');
	                return ( is_numeric( $delay ) ? true : false);
	                break;

	            case 'byDate':
	                $this->dbgOut('isValidDelay(): Delay configured as a date value');
	                return ( $this->isValidDate( $delay ) ? true : false);
	                break;

	            default:
	                $this->dbgOut('isValidDelay(): Not a valid delay value, based on config');
	                return false;
	        }
	    }

		/*
			get array of all posts in this sequence
			force = ignore cache and get data from DB
		*/

	    /**
	     * Pattern recognize whether the data is a valid date format for this plugin
	     * Expected format: YYYY-MM-DD
	     *
	     * @param $data -- Data to test
	     * @return bool -- true | false
	     */
	    public function isValidDate( $data )
	    {
	        if ( preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $data) )
	            return true;

	        return false;
	    }

		function getPosts($force = false)
		{
			if(!isset($this->posts) || $force)
				$this->posts = get_post_meta($this->sequence_id, "_sequence_posts", true);

			return $this->posts;
		}

		//get key of post with id = $post_id

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
					$this->dbgOut('Post # ' . $post_id . ' is already in the sequence.');
					return true;
				}
					return true;
			}

			return false;
		}

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

	            $this->dbgOut('removePost(): Post/Page list updated and saved');

	        }

			return true;
		}

	    // Returns a "days to delay" value for the posts $a & $b, even if the delay value is a date.

		function getDelayForPost($post_id)
		{
			$key = $this->getPostKey($post_id);

			if($key === false)
	        {
	            $this->dbgOut('No key found in getDelayForPost');
				return false;
	        }
	        else {

	            $delay = $this->normalizeDelay( $this->posts[$key]->delay );
	            $this->dbgOut('getDelayForPost(): Delay for post with id = ' . $post_id . ' is ' .$delay);
	            return $delay;
	        }
		}

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
	            $this->dbgOut('normalizeDelay(): Delay specified as a valid date: ' . $delay);
	            return $this->convertToDays($delay);
	        }
	        $this->dbgOut('normalizeDelay(): Delay specified as # of days since membership start: ' . $delay);
	        return $delay;
	    }

	    /**
	     *
	     * Returns a number of days since the users membership started based on the supplied date.
	     * This allows us to mix sequences containing days since membership start and fixed dates for content drips
	     *
	     * @param $date - Take a date in the format YYYY-MM-DD and convert it to a number of days since membership start (for the current member)
	     * @return mixed -- Return the # of days calculated
	     */
	    public function convertToDays( $date )
	    {
		    $days = 0;

		    // $this->dbgOut('In convertToDays()');
		    /*
		    try {
			    $level_id = pmpro_getMembershipLevelForUser();
			    $this->dbgOut('convertToDays() - User Level: ' . $level_id);
		    } catch (Exception $e) {
			    $this->dbgOut('Error getting membership level info: ' . $e->getMessage());
		    }
			*/
	        if ( $this->isValidDate( $date ) )
	        {
		        $this->dbgOut('convertToDays() - Date is valid: ' . $date);

	            $startDate = pmpro_getMemberStartdate(); /* Needs userID & Level ID ... */

		        if (empty($startDate))
			            $startDate = 0;

		        $this->dbgOut('convertToDays() - Start Date: ' . $startDate);
		        try {

			        // Use v5.2 and v5.3 compatible function to calculate difference
			        $compDate = strtotime($date);
			        $days = pmpro_seq_datediff($startDate, $compDate); // current_time('timestamp')


		        } catch (Exception $e) {
			        $this->dbgOut('convertToDays() - Error calculating days: ' . $e->getMessage());
		        }

	            // $this->dbgOut('convertToDays() - Member with start date: ' . date('Y-m-d', $startDate) . ' and end date: ' . $date .  ' for delay day count: ' . $days);

	        }
	        else {
	            $days = $date;
	            // $this->dbgOut('convertToDays() - Member: days of delay from start: ' . $date);
	        }

	        return $days;
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
	            $this->dbgOut('sortByDelay(): Need sortOrder option to base sorting decision on...');
	            // $sequence = $this->getSequenceByID($a->id);
	            if ( $this->sequence_id !== null)
	            {
	                $this->dbgOut('sortByDelay(): Have valid sequence post ID saved: ' . $this->sequence_id);
	                $this->fetchOptions( $this->sequence_id );
	            }
	        }

	        switch ($this->options->sortOrder)
	        {
	            case SORT_ASC:
	                // $this->dbgOut('sortByDelay(): Sorted in Ascending order');
	                return $this->sortAscending($a, $b);
	                break;
	            case SORT_DESC:
	                // $this->dbgOut('sortByDelay(): Sorted in Descending order');
	                return $this->sortDescending($a, $b);
	                break;
	            default:
	                $this->dbgOut('sortByDelay(): sortOrder not defined');
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
			// $this->dbgOut('sortAscending() - Delays have been normalized');

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
	/*
	    public function convertToDate( $days )
	    {
	        $startDate = pmpro_getMemberStartdate();
	        $endDate = date( 'Y-m-d', strtotime( $startDate . " +" . $days . ' days' ));
	        $this->dbgOut('C2Date - Member start date: ' . date('Y-m-d', $startDate) . ' and end date: ' . $endDate .  ' for delay day count: ' . $days);
	        return $endDate;
	    }
	*/

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

		/*
			Create the Custom Post Type for the Sequence/Sequences
		*/

		/**
		 *
		 * Send email to userID about access to new post.
		 *
		 * @param $post_id -- ID of post to send email about
		 * @param $user_id -- ID of user to send the email to.
		 *
		 */
		function sendEmail($post_id, $user_id, $seq_id)
		{
			$email = new PMProEmail();
	        $sequence = new PMProSequences($seq_id);
	        $settings = $this->options;

			$user = get_user_by('id', $user_id);
			$post = get_post($post_id);
			$templ = preg_split('/\./', $settings->noticeTemplate); // Parse the template name

			$email->from = $settings->replyto; // = pmpro_getOption('from_email');
			$email->fromname = $settings->fromname; // = pmpro_getOption('from_name');

			$email->email = $user->user_email;
			$email->ptitle = $post->post_title;

			$email->subject = printf(__('%1$s: %2$s', 'pmprosequence'), $settings->subject, $post->post_title);
			// $email->subject = sprintf(__("New information/post(s) available at %s", "pmpro"), get_option("blogname"));


			$email->template = $templ[0];
			$email->body = file_get_contents(plugins_url('email/'. $settings->noticeTemplate, dirname(__FILE__)));

			// All of the array list names are !!<name>!! escaped values.

			$email->data = array(
				"name" => $user->first_name, // Options are: display_name, first_name, last_name, nickname
				"sitename" => get_option("blogname"),
				"post_link" => '<a href="' . get_permalink($post->ID) . '" title="' . $post->post_title . '">' . $post->post_title . '</a>'
			);


			if(!empty($post->post_excerpt)) {

	            if ( empty( $settings->excerpt_intro ) )
	                $settings->excerpt_intro = __('A summary of the post follows below:', 'pmprosequence');

	            $email->data['excerpt'] = '<p>' . $settings->excerpt_intro . '</p><p>' . $post->post_excerpt . '</p>';
			}
	        else
				$email->data['excerpt'] = '';

			$email->sendEmail();
		}

		/*
			Include the CSS, Javascript and load/define Visual editor Meta boxes
		*/

		function createCPT()
		{
			//don't want to do this when deactivating
			global $pmpro_sequencedeactivating;
			if(!empty($pmpro_sequencedeactivating))
				return false;

			register_post_type('pmpro_sequence',
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
		}

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

	    //this is the Sequence meta box

		function sequenceMetaBox()
		{
			global $post;

			if (empty($this))
				$sequence = new PMProSequences($post->ID);
			else
				$sequence = $this;

	        $sequence->fetchOptions($post->ID);

	        $sequence->dbgOut('sequenceMetaBox(): Load the post list meta box');

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

	        $this->dbgOut('Displaying the back-end meta box content');
	        // usort($this->posts, array("PMProSequences", "sortByDelay"));

			ob_start();
			?>

			<?php if(!empty($this->error)) { ?>
				<div class="message error"><p><?php echo $this->error;?></p></div>
			<?php } ?>
			<table id="pmpro_sequencetable" class="wp-list-table widefat fixed">
			<thead>
				<th><?php _e('Order'); ?></th>
				<th width="50%"><?php _e('Title', 'pmprosequence'); ?></th>
	            <?php $this->dbgOut('Delay Type: ' . $this->options->delayType); ?>
				<?php if ($this->options->delayType == 'byDays'): ?>
	                <th id="pmpro_sequence_delaylabel"><?php _e('Delay', 'pmprosequence'); ?></th>
	            <?php elseif ( $this->options->delayType == 'byDate'): ?>
	                <th id="pmpro_sequence_delaylabel"><?php _e('Avail. On', 'pmprosequence'); ?></th>
	            <?php else: ?>
	                <th id="pmpro_sequence_delaylabel"><?php _e('Not Defined', 'pmprosequence'); ?></th>
	            <?php endif; ?>
				<th></th>
				<th></th>
			</thead>
			<tbody>
			<?php
			$count = 1;

			if(empty($this->posts))
			{
	            $this->dbgOut('No Posts found?');
				$this->setError( __('No posts/pages in Sequence', 'pmprosequence'));
			?>
			<?php
			}
			else
			{
				foreach($this->posts as $post)
				{
				?>
					<tr>
						<td><?php echo $count?>.</td>
						<td><?php echo get_the_title($post->id)?></td>
						<td><?php echo $post->delay ?></td>
	                    <?php $this->dbgOut('Sequence entry # ' . $count . ' for post ' . $post->id . ' delayed ' . $this->normalizeDelay($post->delay)); ?>
						<td>
							<a href="javascript:pmpro_sequence_editPost('<?php echo $post->id;?>', '<?php echo $post->delay;?>'); void(0);">Edit</a>
						</td>
						<td>
							<a href="javascript:pmpro_sequence_removePost('<?php echo $post->id;?>'); void(0);">Remove</a>
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
								$pmpro_sequencepost_types = apply_filters("pmpro_sequencepost_types", array("post", "page"));
								$allposts = $wpdb->get_results("SELECT ID, post_title, post_status FROM $wpdb->posts WHERE post_status IN('publish', 'draft', 'future', 'pending', 'private') AND post_type IN ('" . implode("','", $pmpro_sequencepost_types) . "') AND post_title <> '' ORDER BY post_title");
								foreach($allposts as $p)
								{
								?>
								<option value="<?php echo $p->ID;?>"><?php echo esc_textarea($p->post_title);?> (#<?php echo $p->ID;?><?php echo $this->setPostStatus( $p->post_status );?>)</option>
								<?php
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
							<td><a class="button" id="pmpro_sequencesave" onclick="javascript:pmpro_sequence_addPost(); return false;"><?php _e('Update Sequence', 'pmprosequence'); ?></a></td>
						</tr>
					</tbody>
				</table>
			</div>
			<?php

			$html = ob_get_clean();

			return array(
				'success' => true,
				'message' => $this->getError(),
				'html' => $html,
			);
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

		//this function returns a UL with the current posts

	    /**
	     * @param $sequence -- The Sequence Settings object (contains settings)
	     * @return string -- The HTML containing a form (if the sequence is configured to let users receive notices)
	     */
	    function pmpro_sequence_addUserNoticeOptIn( )
		{
			$optinForm = '';
	        global $current_user, $wpdb;

			$meta_key = $wpdb->prefix . 'pmpro_sequence_notices';

			$this->dbgOut('addUserNoticeOptIn() - User specific opt-in to sequence display for new content notices for user ' . $current_user->ID);

	        if ($this->options->sendNotice == 1) {

		        $this->dbgOut('addUserNoticeOptIn() - meta key: ' . $meta_key);
		        $this->dbgOut('addUserNoticeOptIn() - sequence ID: ' . $this->sequence_id);
		        $this->dbgOut('addUserNoticeOptIn() - User ID: ' . $current_user->ID);

	            $optIn = get_user_meta( $current_user->ID, $meta_key, true );

		        // $this->dbgOut('addUserNoticeOptIn() - Fetched Meta: ' . print_r(get_user_meta($current_user->ID, $meta_key, true), true));

	            /* Determine the state of the users opt-in for new content notices */
	            if ( empty($optIn->sequence ) || empty( $optIn->sequence[$this->sequence_id] ) ) {

		            $this->dbgOut('addUserNoticeOptIn() - No user specific settings found in general or for this sequence. Creating defaults');

		            // Create new opt-in settings for this user
		            if ( empty($optIn->sequence) )
			            $new = new stdClass();
		            else // Saves existing settings
			            $new = $optIn;

		            $new->sequence[$this->sequence_id]->sendNotice = $this->options->sendNotice;

		            $this->dbgOut('addUserNoticeOptIn() - Using default setting for user ' . $current_user->ID . ' and sequence ' . $this->sequence_id);

		            $optIn = $new;
	            }

		        if ( empty( $optIn->sequence[$this->sequence_id]->notifiedPosts ) )
			        $optIn->sequence[$this->sequence_id]->notifiedPosts = array();

		        update_user_meta($current_user->ID, $meta_key, $optIn);

		        $this->dbgOut('addUserNoticeOptIn() - Saved user meta for notice opt-in');
		        // $this->dbgOut('OptIn options: ' . print_r($optIn, true));

	            /* Add form information */
		        ob_start();
		        ?>
	            <div class="pmpro_sequence_useroptin">
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
		        $sequence->dbgOut('Loading settings for Meta Box');
		        $settings = $sequence->fetchOptions($sequence->sequence_id);
	            // $settings = $sequence->fetchOptions($sequence_id);
	            // $sequence->dbgOut('Returned settings: ' . print_r($sequence->options, true));
	        }
	        else
	        {
	            $sequence->dbgOut('Not a valid Sequence ID, cannot load options');
	            return;
	        }

	        // $sequence->dbgOut('pmpro_sequence_settings_meta_box() - Loaded settings: ' . print_r($settings, true));

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
				                <label for="pmpro-seq-sort"><?php _e('Sort order:', 'pmprosequence'); ?> </label>
				                <span id="pmpro-seq-sort-status"><?php echo ( $settings->sortOrder == SORT_ASC ? __('Ascending', 'pmprosequence') : __('Descending', 'pmprosequence') ); ?></span>
				                <a href="#pmpro-seq-sort" id="pmpro-seq-edit-sort" class="edit-pmpro-seq-sort">
					                <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
					                <span class="screen-reader-text"><?php _e('Edit the list sort order', 'pmprosequence'); ?></span>
				                </a>
				                <div id="pmpro-seq-sort-select" style="display: none;">
					                <input type="hidden" name="hidden_pmpro_seq_sortorder" id="hidden_pmpro_seq_sortorder" value="<?php echo ($settings->sortOrder == SORT_ASC ? SORT_ASC : SORT_DESC); ?>" >
					                <select name="pmpro_sequence_sortorder" id="pmpro_sequence_sortorder">
						                <option value="<?php echo esc_attr(SORT_ASC); ?>" <?php selected( intval($settings->sortOrder), SORT_ASC); ?> > <?php _e('Ascending', 'pmprosequence'); ?></option>
						                <option value="<?php echo esc_attr(SORT_DESC); ?>" <?php selected( intval($settings->sortOrder), SORT_DESC); ?> ><?php _e('Descending', 'pmprosequence'); ?></option>
					                </select>
					                <a href="#pmproseq_sortorder" id="ok-pmpro-seq-sort" class="save-pmproseq-sortorder button"><?php _e('OK', 'pmprosequence'); ?></a>
					                <a href="#pmproseq_sortorder" id="cancel-pmpro-seq-sort" class="cancel-pmproseq-sortorder button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
				                </div>
			                </div>
		                </td>
	                </tr>
	                <tr>
		                <td colspan="2">
			                <div class="pmpro-sequence-delaytype">
				                <label for="pmpro-seq-delay"><?php _e('Delay type:', 'pmprosequence'); ?> </label>
				                <span id="pmpro-seq-delay-status"><?php echo ($settings->delayType == 'byDate' ? __('A specific date', 'pmprosequence') : __('Days since sign-up', 'pmprosequence') ); ?></span>
				                <a href="#pmpro-seq-delay" id="pmpro-seq-edit-delay" class="edit-pmpro-seq-delay">
					                <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
					                <span class="screen-reader-text"><?php _e('Edit the delay type for this sequence', 'pmprosequence'); ?></span>
				                </a>
				                <div id="pmpro-seq-delay-select" style="display: none;">
					                <input type="hidden" name="hidden_pmpro_seq_delaytype" id="hidden_pmpro_seq_delaytype" value="<?php echo esc_attr($settings->delayType); ?>" >
					                <select name="pmpro_sequence_delaytype" id="pmpro_sequence_delaytype">
						                <option value="byDays" <?php selected( $settings->delayType, 'byDays'); ?> ><?php _e('Days since sign-up', 'pmprosequence'); ?></option>
						                <option value="byDate" <?php selected( $settings->delayType, 'byDate'); ?> ><?php _e('A specific date', 'pmprosequence'); ?></option>
					                </select>
					                <a href="#pmproseq_delaytype" id="ok-pmpro-seq-delay" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
					                <a href="#pmproseq_delaytype" id="cancel-pmpro-seq-delay" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
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
					            <div class="pmpro-sequence-email">
						            <p class="pmpro-seq-email-hl"><?php _e("Sender:", 'pmprosequence'); ?></p>
						            <div class="pmpro-sequence-replyto">
							            <label for="pmpro-seq-replyto"><?php _e('Addr:', 'pmprosequence'); ?> </label>
							            <span id="pmpro-seq-replyto-status"><?php echo ( $settings->replyto != '' ? esc_attr($settings->replyto) : pmpro_getOption("from_email") ); ?></span>
							            <a href="#pmpro-seq-replyto" id="pmpro-seq-edit-replyto" class="edit-pmpro-seq-replyto">
								            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
								            <span class="screen-reader-text"><?php _e('Enter the email address to use for the sender of the alert', 'pmprosequence'); ?></span>
							            </a>
						            </div>
						            <div class="pmpro-sequence-fromname">
							            <label for="pmpro-seq-fromname"><?php _e('Name:', 'pmprosequence'); ?> </label>
							            <span id="pmpro-seq-fromname-status"><?php _e( ($settings->fromname != '' ? esc_attr($settings->fromname) : pmpro_getOption("from_name")) ); ?></span>
							            <a href="#pmpro-seq-fromname" id="pmpro-seq-edit-fromname" class="edit-pmpro-seq-fromname">
								            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
								            <span class="screen-reader-text"><?php _e('Enter the name to use for the sender of the alert', 'pmprosequence'); ?></span>
							            </a>
						            </div>
						            <div id="pmpro-seq-email-input" style="display: none;">
							            <input type="hidden" name="hidden_pmpro_seq_replyto" id="hidden_pmpro_seq_replyto" value="<?php _e(($settings->replyto != '' ? esc_attr($settings->replyto) : pmpro_getOption("from_email"))); ?>" />
							            <input type="text" name="pmpro_sequence_replyto" id="pmpro_sequence_replyto" value="<?php _e(($settings->replyto != '' ? esc_attr($settings->replyto) : pmpro_getOption("from_email")));; ?>"/>
							            <input type="hidden" name="hidden_pmpro_seq_fromname" id="hidden_pmpro_seq_fromname" value="<?php _e(($settings->fromname != '' ? esc_attr($settings->fromname) : pmpro_getOption("from_name"))); ?>" />
							            <input type="text" name="pmpro_sequence_fromname" id="pmpro_sequence_fromname" value="<?php _e(($settings->fromname != '' ? esc_attr($settings->fromname) : pmpro_getOption("from_name")));; ?>"/>
							            <a href="#pmproseq_email" id="ok-pmpro-seq-email" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
							            <a href="#pmproseq_email" id="cancel-pmpro-seq-email" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
						            </div>
					            </div>

					            <div class="pmpro-sequence-template">
						            <hr width="60%"/>
						            <label for="pmpro-seq-template"><?php _e('Template:', 'pmprosequence'); ?> </label>
						            <span id="pmpro-seq-template-status"><?php _e( esc_attr( $settings->noticeTemplate ) ); ?></span>
						            <a href="#pmpro-seq-template" id="pmpro-seq-edit-template" class="edit-pmpro-seq-template">
							            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
							            <span class="screen-reader-text"><?php _e('Select the template to use when posting new content in this sequence', 'pmprosequence'); ?></span>
						            </a>
						            <div id="pmpro-seq-template-select" style="display: none;">
							            <input type="hidden" name="hidden_pmpro_seq_noticetemplate" id="hidden_pmpro_seq_noticetemplate" value="<?php echo esc_attr($settings->noticeTemplate); ?>" >
							            <select name="pmpro_sequence_template" id="pmpro_sequence_template">
								            <?php $sequence->pmpro_sequence_listEmailTemplates( $settings ); ?>
							            </select>
							            <a href="#pmproseq_template" id="ok-pmpro-seq-template" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
							            <a href="#pmproseq_template" id="cancel-pmpro-seq-template" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
						            </div>
					            </div>
				            </div>
			            </td>
		            </tr>
		            <tr>
			            <td colspan="2">
				            <div class="pmpro-sequence-noticetime">
					            <label for="pmpro-seq-noticetime"><?php _e('When:', 'pmprosequence'); ?> </label>
					            <span id="pmpro-seq-noticetime-status"><?php _e(esc_attr($settings->noticeTime)); ?></span>
					            <a href="#pmpro-seq-noticetime" id="pmpro-seq-edit-noticetime" class="edit-pmpro-seq-noticetime">
						            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
						            <span class="screen-reader-text"><?php _e('Select when (tomorrow) to send new content posted alerts for this sequence', 'pmprosequence'); ?></span>
					            </a>
					            <div id="pmpro-seq-noticetime-select" style="display: none;">
						            <input type="hidden" name="hidden_pmpro_seq_noticetime" id="hidden_pmpro_seq_noticetime" value="<?php echo esc_attr($settings->noticeTime); ?>" >
						            <select name="pmpro_sequence_noticetime" id="pmpro_sequence_noticetime">
						                <?php $sequence->pmpro_sequence_createTimeOpts( $settings ); ?>
						            </select>
						            <a href="#pmproseq_noticetime" id="ok-pmpro-seq-noticetime" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
						            <a href="#pmproseq_noticetime" id="cancel-pmpro-seq-noticetime" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
					            </div>
					            <div>
						            <label for="pmpro-seq-noticetime"><?php _e('Timezone:', 'pmprosequence'); ?> </label>
						            <span id="pmpro-seq-noticetimetz-status"><?php echo '  ' . get_option('timezone_string'); ?></span>
					            </div>
					            <div class="pmpro-sequence-subject">
						            <label for="pmpro-seq-subject"><?php _e('Subject:', 'pmprosequence'); ?> </label>
						            <span id="pmpro-seq-subject-status">"<?php echo ( $settings->subject != '' ? esc_attr($settings->subject) : __('New Content', 'pmprosequence') ); ?>"</span>
						            <a href="#pmpro-seq-subject" id="pmpro-seq-edit-subject" class="edit-pmpro-seq-subject">
							            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
							            <span class="screen-reader-text"><?php _e('Update/Edit the Prefix for the message subject', 'pmprosequence'); ?></span>
						            </a>
						            <div id="pmpro-seq-subject-input" style="display: none;">
							            <input type="hidden" name="hidden_pmpro_seq_subject" id="hidden_pmpro_seq_subject" value="<?php echo ( $settings->subject != '' ? esc_attr($settings->subject) : __('New', 'pmprosequence') ); ?>" />
							            <input type="text" name="pmpro_sequence_subject" id="pmpro_sequence_subject" value="<?php echo ( $settings->subject != '' ? esc_attr($settings->subject) : __('New', 'pmprosequence') ); ?>"/>
							            <a href="#pmproseq_subject" id="ok-pmpro-seq-subject" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
							            <a href="#pmproseq_subject" id="cancel-pmpro-seq-subject" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
						            </div>
					            </div>

					            <div class="pmpro-sequence-excerpt">
						            <label for="pmpro-seq-excerpt"><?php _e('Intro:', 'pmprosequence'); ?> </label>
						            <span id="pmpro-seq-excerpt-status">"<?php echo ( $settings->excerpt_intro != '' ? esc_attr($settings->excerpt_intro) : __('A summary of the post follows below:', 'pmprosequence') ); ?>"</span>
						            <a href="#pmpro-seq-excerpt" id="pmpro-seq-edit-excerpt" class="edit-pmpro-seq-excerpt">
							            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
							            <span class="screen-reader-text"><?php _e('Update/Edit the introductory paragraph for the new content excerpt', 'pmprosequence'); ?></span>
						            </a>
						            <div id="pmpro-seq-excerpt-input" style="display: none;">
							            <input type="hidden" name="hidden_pmpro_seq_excerpt" id="hidden_pmpro_seq_excerpt" value="<?php ($settings->excerpt_intro != '' ? esc_attr($settings->excerpt_intro) : __('A summary of the post follows below:', 'pmprosequence') ); ?>" />
							            <input type="text" name="pmpro_sequence_excerpt" id="pmpro_sequence_excerpt" value="<?php ($settings->excerpt_intro != '' ? esc_attr($settings->excerpt_intro) : __('A summary of the post follows below:', 'pmprosequence') ); ?>"/>
							            <a href="#pmproseq_excerpt" id="ok-pmpro-seq-excerpt" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
							            <a href="#pmproseq_excerpt" id="cancel-pmpro-seq-excerpt" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
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
	    }

		/**
		 * List all template files in email directory for this plugin.
		 *
		 * @param $settings (stdClass) - The settings for the sequence.
		 *
		 */
		function pmpro_sequence_listEmailTemplates( $settings )
		{
			?>
				<!-- Default template (blank) -->
				<option value=""></option>
			<?php
			$files = array();

			$this->dbgOut('Directory containing templates: ' . dirname(__DIR__) . '/email/');
			$templ_dir = dirname(__DIR__) . '/email';

			chdir($templ_dir);
			foreach ( glob('*.html') as $file)
			{
				echo('<option value="' . sanitize_file_name($file) . '" ' . selected( esc_attr( $settings->noticeTemplate), sanitize_file_name($file) ) . ' >' . sanitize_file_name($file) .'</option>');
			}
		}

		/**
		 * Create list of options for time.
		 *
		 * @param $settings -- (array) Sequence specific settings
		 */
		function pmpro_sequence_createTimeOpts( $settings )
		{

			$prepend    = array('00','01','02','03','04','05','06','07','08','09');
			$hours      = array_merge($prepend,range(10, 23));
			$minutes     = array('00', '30');

			// $prepend_mins    = array('00','30');
			// $minutes    = array_merge($prepend_mins, range(10, 55, 5)); // For debug
			// $selTime = preg_split('/\:/', $settings->noticeTime);

			foreach ($hours as $hour) {
				foreach ($minutes as $minute) {
					?>
					<option value="<?php echo( $hour . ':' . $minute ); ?>"<?php selected( $settings->noticeTime, $hour . ':' . $minute ); ?> ><?php echo( $hour . ':' . $minute ); ?></option>
					<?php
				}
			}
		}

		function getPostList($echo = false)
		{
			global $current_user;
			$this->getPosts();
			if(!empty($this->posts))
			{
	            // Order the posts in accordance with the 'sortOrder' option
	            $this->dbgOut('getPostLists(): Sorting posts for display');
	            usort($this->posts, array("PMProSequences", "sortByDelay"));

	            // TODO: Have upcoming posts be listed before or after the currently active posts (own section?) - based on sort setting
				$this->dbgOut('getPostsLists() - Sorted posts in configured order');

				ob_start();
				?>
				<ul id="pmpro_sequence-<?php echo $this->id; ?>" class="pmpro_sequence_list">
				<?php
					$this->dbgOut('Post count: ' . count($this->posts));
					foreach($this->posts as $sp)
					{
	                    $memberFor = pmpro_getMemberDays();

						if ($this->isPastDelay( $memberFor, $sp->delay )):
	                ?>
	                    <li>
	                        <?php $this->dbgOut('Post ' . $sp->id . ' delay: ' . $sp->delay); ?>
							<span class="pmpro_sequence_item-title"><a href="<?php echo get_permalink($sp->id);?>"><?php echo get_the_title($sp->id);?></a></span>
							<span class="pmpro_sequence_item-available"><a class="pmpro_btn pmpro_btn-primary" href="<?php echo get_permalink($sp->id);?>"> <?php _e("Available Now", 'pmprosequence'); ?></a></span>
	                    </li>
	                    <?php elseif ( (! $this->isPastDelay( $memberFor, $sp->delay )) && ( ! $this->hideUpcomingPosts() ) ): ?>
	                    <li>
		                    <?php $this->dbgOut('Show future post:' . $sp->id); ?>
							<span class="pmpro_sequence_item-title"><?php echo get_the_title($sp->id);?></span>

		                    <span class="pmpro_sequence_item-unavailable"><?php echo sprintf( __('available on %s'), ($this->options->delayType == 'byDays' ? __('day', 'pmprosequence') : '')); ?> <?php echo $sp->delay;?></span>
	                    </li>
						<?php endif; ?>
						<!-- TODO: Add text for when there are no posts shown because they're all hidden (future) & all future posts are to be "hidden" -->
						<div class="clear"></div>
					<?php
					}
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
	        if ($this->isValidDate($delay))
	        {
	            $now = current_time('timestamp');
	            // TODO: Add support for startWhen options (once the plugin supports differentiating on when the drip starts)
	            $delayTime = strtotime( $delay . ' 00:00:00.0' );
	            $this->dbgOut('isPastDelay() - Now = ' . $now . ' and delay time = ' . $delayTime );

	            return ( $now >= $delayTime ? true : false ); // a date specified as the $delay
	        }

	        return ( $memberFor >= $delay ? true : false );
	    }

	    /**
	     * Test whether to show future sequence posts (i.e. not yet available to member)
	     */
	    public function hideUpcomingPosts()
	    {
	        // $this->dbgOut('hideUpcomingPosts(): Do we show or hide upcoming posts?');
	        return $this->options->hidden == 1 ? true : false;
	    }
	}

//	$GLOBALS['pmpro-sequences'] = new PMProSequences();

//endif;
