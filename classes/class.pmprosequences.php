<?php
define('PMPRO_SEQUENCE_DEBUG', true);

class PMProSequences
{
    public $options;
    public $sequence_id = 0;

	//constructor
	function PMProSequences($id = null)
	{
		if ( ! empty($id) )
        {
            // $this->dbgOut('__constructor() - Sequence ID: ' . $id);

            $this->sequence_id = $id;
			return $this->getSequenceByID($id);
        }
        else
        {
            if ($this->sequence_id != 0)
            {
                $this->dbgOut('No ID supplied to __construct(), but ID was set before, so load options');
            }
            else
            {
                $this->dbgOut('No sequence ID or options defined! Checking against global variables');
                global $wp_query;
                if ($wp_query->post->ID)
                {
                   $this->dbgOut('Found Post ID and loading options if not already loaded ' . $wp_query->post->ID);
                    $this->sequence_id = $wp_query->post->ID;
//                    self::fetchOptions( self::getID() );
                    if ( empty( $this->options ) )
                        $this->defaultOptions();
                }
            }
        }
	}


    //populate sequence data by post id passed
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
     * Return the default options for a sequence
     * @return array -- Default options for the sequence
     */
    public function defaultOptions()
    {
        $settings = new stdClass();

        $settings->hidden =  0; // 'hidden'
        $settings->lengthVisible = 1; //'lengthVisible'
        $settings->sortOrder = SORT_ASC; // 'sortOrder'
        $settings->delayType = 'byDays'; // 'delayType'
        $settings->startWhen =  0; // startWhen == immediately
	    $settings->sendNotice = 1; // sendNotice == Yes
	    $settings->noticeTemplate = 'new_content.html'; // Default plugin template
	    $settings->noticeTime = '12:00 AM'; // At Midnight (server TZ)

        $this->options = $settings; // Save as options for this sequence

        return $settings;
    }

    /**
     *
     * Fetch any options for this specific sequence from the database (stored as post metadata)
     * Use default options if the sequence ID isn't supplied
     *
     * Array content:
     *  [0] => hidden (boolean) - Whether to show or hide upcoming (future) posts in sequence from display.
     *  [1] => lengthVisible (boolean) - Whether to show or hide the "You are on day X of your membership" information.
     *  [2] => sortOrder (int) - Constant: Ascending or Descending
     *  [3] => delayType (string) - byDays or byDate
     *  TODO: [4] => startTime (int) - The time window when the first day of the sequence should be considered 'Day 1'
     *                           (and 'day 1' content becomes available)
     *                   0 = Immediately (this makes 'day 0' and 'day 1' the same.
     *                   1 = 24 hours after the membership started (i.e. 'member start date/time + 24 hours)
     *                   2 = At midnight after the membership started, i.e. if membership starts at 4am on 12/1,
     *                       Day 1 starts at midnight on 12/2.
     *                   3 = At midnight at least 24 hours after the membership started. I.e. Start at 3am on 12/1,
     *                       Day 1 starts at midnight on 12/3
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
        else
        {
            if ($this->sequence_id != 0)
                $sequence_id = $this->sequence_id;
        }

        // Check that we're being called in context of an actual Sequence 'edit' operation
        self::dbgOut('fetchOptions(): Attempting to load settings from DB for (' . $this->sequence_id . ') "' . get_the_title($this->sequence_id) . '"');
        $settings = get_post_meta($this->sequence_id, '_pmpro_sequence_settings', false);
        $this->options = $settings[0];

        self::dbgOut('fetchOptions() - Fetched options: '. print_r($this->options , true));
        self::dbgOut('Loaded from DB: '. print_r($this->options, true));

        // Check whether we need to set any default variables for the settings
        if ( empty($this->options) )
        {
            self::dbgOut('fetchOptions(): No settings found. Using defaults');
            $this->options = self::defaultOptions();
            self::dbgOut('fetchOptions() - Loaded defaults: '. print_r($this->options , true));
        }

        return $this->options;
    }

    /**
     * Save the settings as metadata for the sequence
     *
     * @param $post_id -- ID of the sequence these options belong to.
     * @return int | mixed - Either the ID of the Sequence or its content
     */
    function pmpro_sequence_meta_save( $post_id )
    {
        // Check that the function was called correctly. If not, just return
        if(empty($post_id)) {
	        self::dbgOut('pmpro_sequence_meta_save(): No post ID supplied...');
	        return false;
        }
        //Verify that this is a valid call (from the actual edit page)
        if (!isset($_POST['pmpro_sequence_settings_noncename']) || !wp_verify_nonce( $_POST['pmpro_sequence_settings_noncename'], plugin_basename(__FILE__) ))
            return $post_id;

        // Is this an auto save routine? If our form has not been submitted (clicked "save"), we'd probably not want to save anything yet
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE )
            return $post_id;

        // Only run this if the post type is correct.
        if ( ! isset($_POST['post_type']) && 'pmpro_sequence' != $_POST['post_type'] )
                return $post_id;

        // Verify that we're allowed to update the sequence data
        if ( !current_user_can( 'edit_post', $post_id ) )
            return $post_id;

        self::dbgOut('pmpro_sequence_meta_save(): About to save settings for sequence ' . $post_id);
        self::dbgOut('From Web: ' . print_r($_REQUEST, true));

        // OK, we're authenticated: we need to find and save the data
        if ( isset($_POST['pmpro_sequence_settings_noncename']) )
        {

            self::dbgOut('Have to load new instance of Sequence class');

            $sequence = new PMProSequences($post_id);
            $settings = $sequence->fetchOptions($post_id);

            if (!$settings)
                $settings = $sequence->defaultOptions();

	        // Checkbox - not included during post/save if unchecked
            if ( isset($_POST['hidden_pmpro_seq_future']) )
            {
                $settings->hidden = intval($_POST['hidden_pmpro_seq_future']);
                self::dbgOut('pmpro_sequence_meta_save(): POST value for settings->hidden: ' . $_POST['hidden_pmpro_seq_future'] );
            }
            elseif ( empty($settings->hidden) )
                $settings->hidden = 0;

	        // Checkbox - not included during post/save if unchecked
            if (isset($_POST['hidden_pmpro_seq_lengthvisible']) )
            {
		            $settings->lengthVisible = intval($_POST['hidden_pmpro_seq_lengthvisible']);
		            self::dbgOut('pmpro_sequence_meta_save(): POST value for settings->lengthVisible: ' . $_POST['hidden_pmpro_seq_lengthvisible']);
            }
            elseif (empty($settings->lengthVisible)) {
	            self::dbgOut('Setting lengthVisible to default value (checked)');
	            $settings->lengthVisible = 1;
            }


            if ( isset($_POST['hidden_pmpro_seq_sortorder']) )
            {
                $settings->sortOrder = intval($_POST['hidden_pmpro_seq_sortorder']);
                self::dbgOut('pmpro_sequence_meta_save(): POST value for settings->sortOrder: ' . $_POST['hidden_pmpro_seq_sortorder'] );
            }
            elseif (empty($settings->sortOrder))
                $settings->sortOrder = SORT_ASC;

            if ( isset($_POST['hidden_pmpro_seq_delaytype']) )
            {
                $settings->delayType = esc_attr($_POST['hidden_pmpro_seq_delaytype']);
                self::dbgOut('pmpro_sequence_meta_save(): POST value for settings->delayType: ' . esc_attr($_POST['hidden_pmpro_seq_delaytype']) );
            }
            elseif (empty($settings->delayType))
                $settings->delayType = 'byDays';

            if ( isset($_POST['hidden_pmpro_seq_startwhen']) )
            {
                $settings->startWhen = esc_attr($_POST['hidden_pmpro_seq_startwhen']);
                self::dbgOut('pmpro_sequence_meta_save(): POST value for settings->startWhen: ' . esc_attr($_POST['hidden_pmpro_seq_startwhen']) );
            }
            elseif (empty($settings->startWhen))
                $settings->startWhen = 0;

	        // Checkbox - not included during post/save if unchecked
	        if ( isset($_POST['hidden_pmpro_seq_sendnotice']) )
	        {
		        $settings->sendNotice = intval($_POST['hidden_pmpro_seq_sendnotice']);
		        self::dbgOut('pmpro_sequence_meta_save(): POST value for settings->sendNotice: ' . intval($_POST['hidden_pmpro_seq_sendnotice']) );
	        }
	        elseif (empty($settings->sendNotice)) {
		        $settings->sendNotice = 1;
	        }

	        if ( isset($_POST['hidden_pmpro_seq_template']) )
	        {
		        $settings->noticeTemplate = esc_attr($_POST['hidden_pmpro_seq_template']);
		        self::dbgOut('pmpro_sequence_meta_save(): POST value for settings->noticeTemplate: ' . esc_attr($_POST['hidden_pmpro_seq_template']) );
	        }
	        else
		        $settings->noticeTemplate = 'new_content.html';

	        if ( isset($_POST['hidden_pmpro_seq_noticetime']) )
	        {
		        $settings->noticeTime = esc_attr($_POST['hidden_pmpro_seq_noticetime']);
		        self::dbgOut('pmpro_sequence_meta_save(): POST value for settings->noticeTime: ' . esc_attr($_POST['hidden_pmpro_seq_noticetime']) );
	        }
	        else
		        $settings->noticeTime = '12:00 AM';

	        // $sequence->options = $settings;

            // Save settings to WPDB
            $sequence->save_sequence_meta( $settings, $post_id );

            self::dbgOut('pmpro_sequence_meta_save(): Saved metadata for sequence #' . $post_id);
            // update_post_meta($post_id, '_tls_sequence_settings', (array)$settings->options);

        }
    }

    /**
     *
     * Save the settings to the Wordpress DB.
     *
     * @param $settings (array) -- Settings for the Sequence
     *
     */
    function save_sequence_meta( $settings, $post_id )
    {
        // Make sure the settings array isn't empty (no settings defined)
        if (! empty( $settings ))
        {
            try {
                self::dbgOut('save_sequence_meta(): Settings for ' . $post_id .' will be: ' . print_r( $settings, true));

                // Update the *_postmeta table for this sequence
                update_post_meta($post_id, '_pmpro_sequence_settings', $settings );

                // Preserve the settings in memory / class context
                self::dbgOut('save_sequence_meta(): Saved Sequence Settings for ' . $post_id);
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
        if (! self::isValidDelay($delay) )
        {
            self::dbgOut('addPost(): Admin specified an invalid delay value for post: ' . ( empty($post_id) ? 'Unknown' :  $post_id) );
            $this->error = 'Invalid delay value specified.';
            return false;
        }

		if(empty($post_id) || !isset($delay))
		{
			$this->error = "Please enter a value for post and delay.";
            self::dbgOut('addPost(): No Post ID or delay specified');
			return false;
		}

        self::dbgOut('addPost(): Post ID: ' . $post_id . ' and delay: ' . $delay);

		$post = get_post($post_id);
			
		if(empty($post->ID))
		{
			$this->error = "A post with that id does not exist.";
            self::dbgOut('addPost(): No Post with ' . $post_id . ' found');
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
		$this->posts[] = $temp;
		
		//sort
        self::dbgOut('addPost(): Sorting the Sequence by delay');
		usort($this->posts, array("PMProSequences", "sortByDelay"));

		//save
		update_post_meta($this->id, "_sequence_posts", $this->posts);

		//Get any previously existing sequences this post/page is linked to
		$post_sequence = get_post_meta($post_id, "_post_sequences", true);

        // Is there any previously saved sequence ID found for the post/page?
		if(empty($post_sequence))
        {
            self::dbgOut('addPost(): Not previously defined sequence(s) found for this post (ID: ' . $post_id . ')');
            $post_sequence = array($this->id);
        }
        else
        {
            self::dbgOut('addPost(): Post/Page w/id ' . $post_id . ' belongs to more than one sequence already: ' . print_r($post_sequence, true));

            if ( !is_array($post_sequence) )
            {
                self::dbgOut('AddPost(): Previously defined sequence(s) found for this post (ID: ' . $post_id . '). Sequence data: ' . print_r($post_sequence, true));
                self::dbgOut('addPost(): Not (yet) an array of posts. Adding the single new post to a new array');
                $post_sequence = array($this->id);
            }
            else
            {
                // Bug Fix: Never checked if the Post/Page ID was already included in the sequence meta.
                if ( !array_search( $this->id, $post_sequence) )
                {
                    // If not, add it.
                    $post_sequence[] = $this->id;
                    self::dbgOut('addPost(): Appended post (ID: ' . $post_id . ') to Sequence');
                }
            }

        }
		//save
		update_post_meta($post_id, "_post_sequences", $post_sequence);
        self::dbgOut('addPost(): Post/Page list updated and saved');

    }
	
	//remove a post from this sequence
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

            self::dbgOut('removePost(): Post/Page list updated and saved');

        }

		return true;
	}
	
	/*
		get array of all posts in this sequence
		force = ignore cache and get data from DB
	*/
	function getPosts($force = false)
	{
		if(!isset($this->posts) || $force)
			$this->posts = get_post_meta($this->sequence_id, "_sequence_posts", true);

        if (!isset($this->options->hidden))
        {
            // echo print_r($this->posts);
            $this->fetchOptions($this->id);
        }
		return $this->posts;
	}
	
	//does this sequence include post with id = post_id
	function hasPost($post_id)
	{
		$this->getPosts();
		
		if(empty($this->posts))
			return false;
				
		foreach($this->posts as $key => $post)
		{
			if($post->id == $post_id)
				return true;
		}
		
		return false;
	}
	
	//get key of post with id = $post_id
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
	
	function getDelayForPost($post_id)
	{
		$key = $this->getPostKey($post_id);
		
		if($key === false)
        {
            $this->dbgOut('No key found in getDelayForPost');
			return false;
        }
        else
        {
            $delay = $this->normalizeDelay( $this->posts[$key]->delay );
            $this->dbgOut('getDelayForPost(): Delay for post with id = ' . $post_id . ' is ' .$delay);
            return $delay;
        }
	}

    // Returns a "days to delay" value for the posts $a & $b, even if the delay value is a date.

    function normalizeDelays($a, $b)
    {
        return array($this->convertToDays($a->delay), $this->convertToDays($b->delay));
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
            self::dbgOut('normalizeDelay(): Delay specified as a valid date: ' . $delay);
            return $this->convertToDays($delay);
        }
        self::dbgOut('normalizeDelay(): Delay specified as # of days since membership start: ' . $delay);
        return $delay;
    }

    /**
     *
     * Sort the two post objects (order them) according to the defined sortOrder
     *
     * @param $a (post object)
     * @param $b (post object)
     */
    function sortByDelay($a, $b)
    {
        if (empty($this->options->sortOrder))
        {
            self::dbgOut('sortByDelay(): Need sortOrder option to base sorting decision on...');
            // $sequence = $this->getSequenceByID($a->id);
            if ( $this->sequence_id !== null)
            {
                self::dbgOut('sortByDelay(): Have valid sequence post ID saved: ' . $this->sequence_id);
                $this->fetchOptions( $this->sequence_id );
            }
        }

        switch ($this->options->sortOrder)
        {
            case SORT_ASC:
                self::dbgOut('sortByDelay(): Sorted in Ascending order');
                return $this->sortAscending($a, $b);
                break;
            case SORT_DESC:
                self::dbgOut('sortByDelay(): Sorted in Descending order');
                return $this->sortDescending($a, $b);
                break;
            default:
                self::dbgOut('sortByDelay(): sortOrder not defined');
        }
    }

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
     * @param $a -- Post to compare (including delay variable)
     * @param $b -- Post to compare against (including delay variable)
     * @return int -- Return +1 if the Delay for post $a is greater than the delay for post $b (i.e. delay for b is
     *                  less than delay for a)
     */
	public function sortAscending($a, $b)
	{
        list($aDelay, $bDelay) = $this->normalizeDelays($a, $b);

        // Now sort the data
        if ($aDelay == $bDelay)
            return 0;
        // Ascending sort order
        return ($aDelay > $bDelay) ? +1 : -1;

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
     * Returns a number of days since the users membership started based on the supplied date.
     * This allows us to mix sequences containing days since membership start and fixed dates for content drips
     *
     * @param $date - Take a date in the format YYYY-MM-DD and convert it to a number of days since membership start (for the current member)
     * @return mixed -- Return the # of days calculated
     */
    public function convertToDays( $date )
    {
	    $level_id = pmpro_getMembershipLevelForUser();

        if ( $this->isValidDate( $date ) )
        {
            $startDate = pmpro_getMemberStartdate(); /* Needs userID & Level ID ... */

	        if (empty($startDate))
		            $startDate = 0;
	        else
	        {
	            $dStart = new DateTime( date( 'Y-m-d', $startDate ) );
	            $dEnd = new DateTime( date( 'Y-m-d', strtotime($date) ) ); // Today's date
	            $dDiff = $dStart->diff($dEnd);
	            $dDiff->format('%d');
	            // $dDiff->format('%R%a');

	            //self::dbgOut('Diff Object:' . print_r($dDiff, true));

	            $days = $dDiff->days;

	            if ($dDiff->invert == 1)
	                $days = 0 - $days; // Invert the value
		        }

            self::dbgOut('convertToDays() - Member (Level: ' . $level_id . ') with start date: ' . date('Y-m-d', $startDate) . ' and end date: ' . $date .  ' for delay day count: ' . $days);

        } else {
            $days = $date;
            self::dbgOut('convertToDays() - Member Level: ' . $level_id . ', - days of delay from start: ' . $date);
        }

        return $days;
    }
/*
    public function convertToDate( $days )
    {
        $startDate = pmpro_getMemberStartdate();
        $endDate = date( 'Y-m-d', strtotime( $startDate . " +" . $days . ' days' ));
        self::dbgOut('C2Date - Member start date: ' . date('Y-m-d', $startDate) . ' and end date: ' . $endDate .  ' for delay day count: ' . $days);
        return $endDate;
    }
*/
	//send an email RE new access to post_id to email of user_id

	/**
	 *
	 * Send email to userID about access to new post.
	 *
	 * TODO: Add per-sequence support for independent times to send the message(s).
	 *
	 * @param $post_id -- ID of post to send email about
	 * @param $user_id -- ID of user to send the email to.
	 *
	 */
	function sendEmail($post_id, $user_id)
	{
		$email = new PMProEmail();

		$user = get_user_by('id', $user_id);
		$post = get_post($post_id);

		$email->email = $user->user_email;
		$email->subject = sprintf(__("New information/post(s) available at %s", "pmpro"), get_option("blogname"));
		$email->template = "new_content"; // TODO Use sequence META to specify the template to use.
		$email->body = file_get_contents(plugins_url('email/'. $email->template . '.html', dirname(__FILE__)));

		// All of the array list names are !!<name>!! escaped values.

		$email->data = array(
			"name" => $user->display_name,
			"sitename" => get_option("blogname"),
			"excerpt_intro" => '<p>A summary of the post follows below.</p>',
			"post_link" => '<a href="' . get_permalink($post->ID) . '" title="' . $post->post_title . '">' . $post->post_title . '</a>'
		);

		if(!empty($post->post_excerpt))
			// TODO - Fix the excerpt prefix (<p>A summary ... </p>)
			$email->data['excerpt'] = $email->data['excerpt_intro'] . '<p>' . $post->post_excerpt . '</p>';
		else
			$email->data['excerpt'] = '';

		$email->sendEmail();
	}
	
	/*
		Create the Custom Post Type for the Sequence/Sequences
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
                                'name' => __( 'Sequences' ),
								'singular_name' => __( 'Sequence' ),
                                'slug' => 'pmpro_sequence',
                                'add_new' => __( 'New Sequence' ),
                                'add_new_item' => __( 'New Sequence' ),
                                'edit' => __( 'Edit Sequence' ),
                                'edit_item' => __( 'Edit Sequence' ),
                                'new_item' => __( 'Add New' ),
                                'view' => __( 'View Sequence' ),
                                'view_item' => __( 'View This Sequence' ),
                                'search_items' => __( 'Search Sequences' ),
                                'not_found' => __( 'No Sequence Found' ),
                                'not_found_in_trash' => __( 'No Sequence Found In Trash' ),
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
	
	/*
		Include the CSS, Javascript and load/define Visual editor Meta boxes
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

    /**
     * Add the actual meta box definitions as add_meta_box() functions (3 meta boxes; One for the page meta,
     * one for the Settings & one for the sequence posts/page definitions.
     */
    function defineMetaBoxes()
	{
		//PMPro box
		add_meta_box('pmpro_page_meta', 'Require Membership', 'pmpro_page_meta', 'pmpro_sequence', 'side');

		// sequence settings box (for posts & pages)
        add_meta_box('pmpros-sequence-settings', esc_html__('Settings for this Sequence', 'pmpro_sequence'), array("PMProSequences", 'pmpro_sequence_settings_meta_box'), 'pmpro_sequence', 'side', 'high');
//        add_meta_box('pmpro_sequence_settings_meta', __('Settings', 'pmpro_sequence'), 'settings_page_meta', 'page', 'side');

		//sequence meta box
		add_meta_box('pmpro_sequence_meta', 'Posts in this Sequence', array("PMProSequences", "sequenceMetaBox"), 'pmpro_sequence', 'normal', 'high');

    }

    /**
     *
     * Pre PHP v5.3 datetime::diff() alternative (calculate # of days between two dates)
     *
     * @param $date1 (string) - Date in format 'YYYY-MM-DD'
     * @param $date2 (string) - Date in format 'YYYY-MM-DD'
     * @return int - Number of days (as a count)
     */

    // TODO - Bug? What if date1 is after date 2 (Negative days)? - http://www.php.net/manual/en/datetime.diff.php#97810

    function date_diff($date1, $date2)
    {
        $current = $date1;
        $datetime2 = date_create($date2);
        $count = 0;

        // TODO: Does not include support for TIME in the date calculation.

        while(date_create($current) < $datetime2){
            $current = gmdate("Y-m-d", strtotime("+1 day", strtotime($current)));
            $count++;
        }
        return $count;
    }

    //this is the Sequence meta box
	function sequenceMetaBox()
	{
		global $post;

        $sequence = new PMProSequences($post->ID);
        $sequence->fetchOptions($post->ID);

        $sequence->dbgOut('sequenceMetaBox(): Load the post list meta box');

        // Instantiate the settings & grab any existing settings if they exist.
     ?>
		<div id="pmpro_sequence_posts">
		<?php $sequence->getPostListForMetaBox(); ?>
		</div>				
		<?php		
	}

	/**
	 * Create list of options for time.
	 *
	 * @param string $postfix -- AM or PM for the time (hours)
	 */
	function pmpro_sequence_createTimeOpts( $postfix = 'AM', $settings )
	{

		// TODO: Support language settings for time (24 vs 12 hour time, etc).
		$hr = '12:00 ' . $postfix;
		?>
			<option value="<?php echo($hr); ?>" <?php selected( $settings->noticeTime, $hr ); ?> ><?php echo($hr);?></option>
		<?php
		foreach (range(1, 11) as $hour )
		{
			$hr = $hour . ':00 ' . $postfix;
			?>
			<option value="<?php echo($hr); ?>" <?php selected( $settings->noticeTime, $hr); ?> ><?php echo ($hr);?></option>
		<?php }

	}

	/**
	 * List all template files in email directory for this plugin.
	 */
	function pmpro_sequence_listEmailTemplates( $settings )
	{
		?>
			<!-- Default template (blank) -->
			<option value=""></option>
		<?php
		$files = array();

		self::dbgOut('Directory containing templates: ' . dirname(__DIR__) . '/email/');
		$dir = opendir(dirname(__DIR__) . '/email/');

		while(false != ($file = readdir($dir)))
		{
			if(($file != ".") and ($file != "..") and ($file != "index.php"))
			{
				$files[] = $file; // put in array.
			}
		}

		natsort($files); // sort.

		foreach($files as $file)
		{
			// self::dbgOut('<option value="' . sanitize_file_name($file) . '" ' . selected( esc_attr( $settings->noticeTemplate ), sanitize_file_name($file) ) . ' >' . sanitize_file_name($file) .'</option>');
			echo('<option value="' . sanitize_file_name($file) . '" ' . selected( esc_attr( $settings->noticeTemplate), sanitize_file_name($file) ) . ' >' . sanitize_file_name($file) .'</option>');
		}
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

        $sequence_id = $post->ID;
        $sequence = new PMProSequences($sequence_id);

        if ( $sequence_id != 0)
        {
            $sequence->dbgOut('Loading settings for Meta Box');
            $sequence->fetchOptions($sequence_id);
            $settings = $sequence->fetchOptions($sequence_id);
            $sequence->dbgOut('Returned settings: ' . print_r($sequence->options, true));
        }
        else
        {
            self::dbgOut('Not a valid Sequence ID, cannot load options');
            return;
        }

        self::dbgOut('pmpro_sequence_settings_meta_box() - Loaded settings: ' . print_r($settings, true));

        ?>
        <div class="submitbox" id="pmpro_sequence_meta">
            <div id="minor-publishing">
            <input type="hidden" name="pmpro_sequence_settings_noncename" id="pmpro_sequence_settings_noncename" value="<?php echo wp_create_nonce( plugin_basename(__FILE__) )?>" />
            <input type="hidden" name="pmpro_sequence_settings_hidden_delay" id="pmpro_sequence_settings_hidden_delay" value="<?php echo esc_attr($settings->delayType); ?>"/>
            <table style="width: 100%;">
	            <tr>
                    <td style="width: 20px;">
	                    <input type="checkbox" value="1" id="pmpro_sequence_hidden" name="pmpro_sequence_hidden" title="<?php _e('Hide unpublished / future posts for this sequence'); ?>" <?php checked($settings->hidden, 1); ?> />
		                <input type="hidden" name="hidden_pmpro_seq_future" id="hidden_pmpro_seq_future" value="<?php echo esc_attr($settings->hidden); ?>" >
                    </td>
                    <td style="width: 160px"><label class="selectit"><?php _e('Hide all future posts'); ?></label></td>
                </tr>
                <tr>
                    <td>
	                    <input type="checkbox" value="1" id="pmpro_sequence_lengthvisible" name="pmpro_sequence_lengthvisible" title="<?php _e('Whether to show the &quot;You are on day NNN of your membership&quot; text'); ?>" <?php checked($settings->lengthVisible, 1); ?> />
	                    <input type="hidden" name="hidden_pmpro_seq_lengthvisible" id="hidden_pmpro_seq_lengthvisible" value="<?php echo esc_attr($settings->lengthVisible); ?>" >
                    </td>
                    <td><label class="selectit"><?php _e('Show membership length info'); ?></label></td>
                </tr>
	            <tr><td colspan="2"><hr/></td></tr>
                <tr>
	                <td colspan="2">
		                <div class="pmpro-sequence-sortorder">
			                <label for="pmpro-seq-sort"><?php _e('Sort order:'); ?> </label>
			                <span id="pmpro-seq-sort-status"><?php _e(($settings->sortOrder == SORT_ASC ? 'Ascending' : 'Descending')); ?></span>
			                <a href="#pmpro-seq-sort" id="pmpro-seq-edit-sort" class="edit-pmpro-seq-sort">
				                <span aria-hidden="true"><?php _e('Edit'); ?></span>
				                <span class="screen-reader-text"><?php _e('Edit the list sort order'); ?></span>
			                </a>
			                <div id="pmpro-seq-sort-select" style="display: none;">
				                <input type="hidden" name="hidden_pmpro_seq_sortorder" id="hidden_pmpro_seq_sortorder" value="<?php echo ($settings->sortOrder == SORT_ASC ? SORT_ASC : SORT_DESC); ?>" >
				                <select name="pmpro_sequence_sortorder" id="pmpro_sequence_sortorder">
					                <option value="<?php echo esc_attr(SORT_ASC); ?>" <?php selected( intval($settings->sortOrder), SORT_ASC); ?> > <?php _e('Ascending'); ?></option>
					                <option value="<?php echo esc_attr(SORT_DESC); ?>" <?php selected( intval($settings->sortOrder), SORT_DESC); ?> ><?php _e('Descending'); ?></option>
				                </select>
				                <a href="#pmproseq_sortorder" id="ok-pmpro-seq-sort" class="save-pmproseq-sortorder button"><?php _e('OK'); ?></a>
				                <a href="#pmproseq_sortorder" id="cancel-pmpro-seq-sort" class="cancel-pmproseq-sortorder button-cancel"><?php _e('Cancel'); ?></a>
			                </div>
		                </div>
	                </td>
                </tr>
                <tr>
	                <td colspan="2">
		                <div class="pmpro-sequence-delaytype">
			                <label for="pmpro-seq-delay"><?php _e('Delay type:'); ?> </label>
			                <span id="pmpro-seq-delay-status"><?php _e(($settings->delayType == 'byDate' ? _e('A date') : _e('Days since sign-up'))); ?></span>
			                <a href="#pmpro-seq-delay" id="pmpro-seq-edit-delay" class="edit-pmpro-seq-delay">
				                <span aria-hidden="true"><?php _e('Edit'); ?></span>
				                <span class="screen-reader-text"><?php _e('Edit the delay type for this sequence'); ?></span>
			                </a>
			                <div id="pmpro-seq-delay-select" style="display: none;">
				                <input type="hidden" name="hidden_pmpro_seq_delaytype" id="hidden_pmpro_seq_delaytype" value="<?php echo esc_attr($settings->delayType); ?>" >
				                <select name="pmpro_sequence_delaytype" id="pmpro_sequence_delaytype">
					                <option value="byDays" <?php selected( $settings->delayType, 'byDays'); ?> ><?php _e('Number of Days'); ?></option>
					                <option value="byDate" <?php selected( $settings->delayType, 'byDate'); ?> ><?php _e('Release Date (YYYY-MM-DD)'); ?></option>
				                </select>
				                <a href="#pmproseq_delaytype" id="ok-pmpro-seq-delay" class="save-pmproseq button"><?php _e('OK'); ?></a>
				                <a href="#pmproseq_delaytype" id="cancel-pmpro-seq-delay" class="cancel-pmproseq button-cancel"><?php _e('Cancel'); ?></a>
			                </div>
		                </div>
	                </td>
                </tr>
	            <!-- TODO: Add support for time to run new-content job (cron) for this sequence (the default value is set at plugin registration -->
	            <tr>
		            <td colspan="2">
			            <div class="pmpro-seq-alert-hl">New content alerts</div>
			            <hr width="100%"/>
		            </td>
	            </tr>
	            <tr>
		            <td colspan="2">
			            <div class="pmpro-sequence-alerts">
				            <input type="checkbox" value="1" title="<?php _e('Whether to send an alert/notice to members when new content for this sequence is available to them'); ?>" id="pmpro_sequence_sendnotice" name="pmpro_sequence_sendnotice" <?php checked($settings->sendNotice, 1); ?> />
				            <input type="hidden" name="hidden_pmpro_seq_sendnotice" id="hidden_pmpro_seq_sendnotice" value="<?php echo esc_attr($settings->sendNotice); ?>" >
				            <label class="selectit" for="pmpro_sequence_sendnotice"><?php _e('Send new content alerts'); ?></label>
				            <div class="pmpro-sequence-template">
					            <hr width="60%"/>
					            <label for="pmpro-seq-template"><?php _e('Email templ:'); ?> </label>
					            <span id="pmpro-seq-template-status"><?php _e( esc_attr( $settings->noticeTemplate ) ); ?></span>
					            <a href="#pmpro-seq-template" id="pmpro-seq-edit-template" class="edit-pmpro-seq-template">
						            <span aria-hidden="true"><?php _e('Edit'); ?></span>
						            <span class="screen-reader-text"><?php _e('Select the template to use when posting new content in this sequence'); ?></span>
					            </a>
					            <div id="pmpro-seq-template-select" style="display: none;">
						            <input type="hidden" name="hidden_pmpro_seq_template" id="hidden_pmpro_seq_template" value="<?php echo esc_attr($settings->noticeTemplate); ?>" >
						            <select name="pmpro_sequence_template" id="pmpro_sequence_template">
							            <?php $sequence->pmpro_sequence_listEmailTemplates( $settings ); ?>
						            </select>
						            <a href="#pmproseq_template" id="ok-pmpro-seq-template" class="save-pmproseq button"><?php _e('OK'); ?></a>
						            <a href="#pmproseq_template" id="cancel-pmpro-seq-template" class="cancel-pmproseq button-cancel"><?php _e('Cancel'); ?></a>
					            </div>
				            </div>
			            </div>
		            </td>
	            </tr>
	            <tr>
		            <td colspan="2">
			            <div class="pmpro-sequence-noticetime">
				            <label for="pmpro-seq-noticetime"><?php _e('When:'); ?> </label>
				            <span id="pmpro-seq-noticetime-status"><?php _e(esc_attr($settings->noticeTime)); ?></span>
				            <a href="#pmpro-seq-noticetime" id="pmpro-seq-edit-noticetime" class="edit-pmpro-seq-noticetime">
					            <span aria-hidden="true"><?php _e('Edit'); ?></span>
					            <span class="screen-reader-text"><?php _e('Edit the transmission time for any new content posted alerts for this sequence'); ?></span>
				            </a>
				            <div>
					            <label for="pmpro-seq-noticetime"><?php _e('Timezone:'); ?> </label>
					            <span id="pmpro-seq-noticetime-status"><?php echo '  ' . get_option('timezone_string'); ?></span>
				            </div>
				            <div id="pmpro-seq-noticetime-select" style="display: none;">
					            <input type="hidden" name="hidden_pmpro_seq_noticetime" id="hidden_pmpro_seq_noticetime" value="<?php echo esc_attr($settings->noticeTime); ?>" >
					            <select name="pmpro_sequence_noticetime" id="pmpro_sequence_noticetime">
						            <?php $sequence->pmpro_sequence_createTimeOpts('AM', $settings); ?>
						            <?php $sequence->pmpro_sequence_createTimeOpts('PM', $settings); ?>
					            </select>
					            <a href="#pmproseq_noticetime" id="ok-pmpro-seq-noticetime" class="save-pmproseq button"><?php _e('OK'); ?></a>
					            <a href="#pmproseq_noticetime" id="cancel-pmpro-seq-noticetime" class="cancel-pmproseq button-cancel"><?php _e('Cancel'); ?></a>
				            </div>
			            </div>
		            </td>
	            <tr>
                    <td colspan="2"><hr style="width: 100%;" /></td>
                </tr>
                <tr>
                    <td colspan="2" style="padding: 0px; margin 0px;">
                        <a class="button button-primary button-large" class="pmpro-seq-settings-save" id="pmpro_settings_save"><?php _e('Save Settings'); ?></a>
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
                        <input id='pmpro_sequence_enablestartwhen' type="checkbox" value="1" title="<?php _e('Configure start parameters for sequence drip. The default is to start day 1 exactly 24 hours after membership started, using the servers timezone and recorded timestamp for the membership check-out.'); ?>" name="pmpro_sequence_enablestartwhen" <?php echo ($sequence->options->startWhen != 0) ? 'checked="checked"' : ''; ?> />
                    </td>
                    <td><label class="selectit"><?php _e('Sequence starts'); ?></label></td>
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

        <!-- Test whether the sequence delay type has been changed. Submit AJAX request to delete existing posts if it has -->
        <script language="javascript">
            jQuery(document).ready(function () {
                jQuery("#pmpro_sequence_delaytype")
                    .change(function(){
                        console.log('Process changes to delayType option');
                        var selected = jQuery(this).val();
                        var current = jQuery('input[name=pmpro_sequence_settings_hidden_delay]').val();
                        console.log( 'Post # ' + <?php echo $post->ID; ?> );
                        console.log( 'delayType: ' + selected );
                        console.log( 'Current: ' + current );

                        if ( jQuery(this).val() != jQuery('#pmpro_sequence_settings_hidden_delay').val() ) {
                            if (! confirm("Changing the delay type will erase all\n existing posts or pages in the Sequence list.\n\nAre you sure?\n (Cancel if 'No')\n\n"))
                            {
                                jQuery(this).val(jQuery.data(this, 'pmpro_sequence_settings_hidden_delay'));
                                jQuery(this).val(current);

                                return false;
                            }

                            jQuery.data(this, 'pmpro_sequence_settings_delaytype', jQuery(this).val());

                            // Send POST (AJAX) request to delete all existing articles/posts in sequence.
                            jQuery.post( pmproSequenceAjax.ajaxurl,
                                {
                                    action: 'pmpro_sequence_clear',
                                    'pmpro_sequenceclear_sequence': '1',
	                                'security': pmproSequenceAjax.pmproSequenceNonce,
                                    'pmpro_sequence_id': '<?php echo $post->ID ?>',
                                    'hidden_pmpro_seq_hidden': isHidden(),
                                    'hidden_pmpro_seq_lengthvisible': showLength(),
                                    'hidden_pmpro_seq_startwhen': jQuery('#pmpro_sequence_startwhen').val(),
                                    'hidden_pmpro_seq_sortorder': jQuery('#hidden_pmpro_seq_sortorder').val(),
                                    'hidden_pmpro_seq_delaytype': jQuery('#hidden_pmpro_seq_delaytype').val(),
	                                'hidden_pmpro_seq_sendnotice': jQuery('#hidden_pmpro_seq_sendnotice').val(),
	                                'hidden_pmpro_seq_noticetime': jQuery('#hidden_pmpro_seq_noticetime').val(),
	                                'hidden_pmpro_seq_noticetemplate': jQuery('#hidden_pmpro_seq_template').val()
                                },
                                function(responseHTML)
                                {
                                    if ( ! responseHTML.match("^Error") )
                                    {
                                        /*
                                         * Refresh the list of posts (now empty) in the #pmpro_sequence_posts meta box
                                         */
                                        setLabels();
                                        jQuery('#pmpro_sequence_posts').html(responseHTML);
                                    }
                                    else
                                    {
                                        alert(responseHTML);
                                        jQuery(this).val(current);
                                    }
                                }
                            );
                        };

                        console.log('Selected: '+ jQuery(this).val());
                        console.log('Current (hidden): ' + jQuery('input[name=pmpro_sequence_settings_hidden_delay]').val());
                    });
                // For the Sequence Settings 'Save Settings' button
                jQuery('#pmpro_settings_save').click(function(){

                    if(jQuery(this).html() == 'Saving...')
                        return;	//already saving, ignore this request

                    //disable save button
                    jQuery(this).html('Saving...');

                    jQuery.post( pmproSequenceAjax.ajaxurl,
                        //Data to send to back-end
                        {
                            action: 'pmpro_save_settings',
                            'cookie': encodeURIComponent(document.cookie),
	                        'security': pmproSequenceAjax.pmproSequenceNonce,
                            'pmpro_sequence_id': '<?php echo $post->ID; ?>',
	                        'hidden_pmpro_seq_hidden': isHidden(),
	                        'hidden_pmpro_seq_lengthvisible': showLength(),
	                        'hidden_pmpro_seq_startwhen': jQuery('#pmpro_sequence_startwhen').val(),
	                        'hidden_pmpro_seq_sortorder': jQuery('#hidden_pmpro_seq_sortorder').val(),
	                        'hidden_pmpro_seq_delaytype': jQuery('#hidden_pmpro_seq_delaytype').val(),
	                        'hidden_pmpro_seq_sendnotice': jQuery('#hidden_pmpro_seq_sendnotice').val(),
	                        'hidden_pmpro_seq_noticetime': jQuery('#hidden_pmpro_seq_noticetime').val(),
	                        'hidden_pmpro_seq_noticetemplate': jQuery('#hidden_pmpro_seq_template').val()
                        },
                        //on success function
                        function(status)
                        {
                            if (status == 'success')
                            {
                                setLabels();
                                jQuery('#pmpro_settings_save').html('Save Settings');
                                // location.reload();
                            }
                            else
                            {
                                alert(status);
                                jQuery('#pmpro_settings_save').html('Save Settings');
                            }
                            /* Do Some Stuff in here to update elements on the page...*/
                            /*Reset the form*/
                            // jQuery('input#newsliders').val('');
                            //jQuery('#sliders-adder').addClass('wp-hidden-children');

                            return false;
                        }
                    );
                    return false;
                });
            });


        </script>
	<?php

    }

	//this function returns a UL with the current posts
	function getPostList($echo = false)
	{
		global $current_user;
		$this->getPosts();
		if(!empty($this->posts))
		{
            // Order the posts in accordance with the 'sortOrder' option
            self::dbgOut('getPostLists(): Sorting posts for display');
            usort($this->posts, array("PMProSequences", "sortByDelay"));

            // TODO: Have upcoming posts be listed before or after the currently active posts (own section?) - based on sort setting

			ob_start();
			?>		
			<ul id="pmpro_sequence-<?php echo $this->id; ?>" class="pmpro_sequence_list">
			<?php			
				foreach($this->posts as $sp)
				{
                    $memberFor = pmpro_getMemberDays();

                    if ($this->isPastDelay( $memberFor, $sp->delay )) {
                ?>
                    <li>
                        <?php self::dbgOut('Post ' . $sp->id . ' delay: ' . $sp->delay); ?>
						<span class="pmpro_sequence_item-title"><a href="<?php echo get_permalink($sp->id);?>"><?php echo get_the_title($sp->id);?></a></span>
						<span class="pmpro_sequence_item-available"><a class="pmpro_btn pmpro_btn-primary" href="<?php echo get_permalink($sp->id);?>">Available Now</a></span>
                    </li>
 					<?php } elseif ( ! ($this->isPastDelay( $memberFor, $sp->delay )) && ( ! $this->hideUpcomingPosts() ) ) { ?>
                    <li>
						<span class="pmpro_sequence_item-title"><?php echo get_the_title($sp->id);?></span>
						<span class="pmpro_sequence_item-unavailable">available on <?php echo ($this->options->delayType == 'byDays' ? 'day' : ''); ?> <?php echo $sp->delay;?></span>
                    </li>
					<?php } ?>
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
     * Test whether to show future sequence posts (i.e. not yet available to member)
     */
    public function hideUpcomingPosts()
    {
        self::dbgOut('hideUpcomingPosts(): Do we show or hide upcoming posts?');
        return $this->options->hidden == 1 ? true : false;
    }

    /**
     * Validates that the value received follows a valid "delay" format for the post/page sequence
     */
    public function isValidDelay( $delay )
    {
        self::dbgOut('isValidDelay(): Delay value is: ' . $delay);

        switch ($this->options->delayType)
        {
            case 'byDays':
                self::dbgOut('isValidDelay(): Delay configured as "days since membership start"');
                return ( is_numeric( $delay ) ? true : false);
                break;

            case 'byDate':
                self::dbgOut('isValidDelay(): Delay configured as a date value');
                return ( $this->isValidDate( $delay ) ? true : false);
                break;

            default:
                self::dbgOut('isValidDelay(): Not a valid delay value, based on config');
                return false;
        }
    }

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
            $now = time();
            // TODO: Add support for startWhen options (once the plugin supports differentiating on when the drip starts)
            $delayTime = strtotime( $delay . ' 00:00:00.0' );
            $this->dbgOut('isPastDelay() - Now = ' . $now . ' and delay time = ' . $delayTime );

            return ( $now >= $delayTime) ? true : false; // a date specified as the $delay
        }
        return ( $memberFor >= $delay ) ? true : false;

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
                $txtState = '-DRAFT';
                break;

            case 'future':
                $txtState = '-SCHED';
                break;

            case 'pending':
                $txtState = '-REVIEW';
                break;

            case 'private':
                $txtState = '-PRIVT';
                break;

            default:
                $txtState = '';
        }

        return $txtState;
    }

    //this code updates the posts and draws the list/form
	function getPostListForMetaBox()
	{
		global $wpdb;
		
		//boot out people without permissions
		if(!current_user_can("edit_posts"))
        {
            self::dbgOut('add_post(): User is not permitted to edit');
			return false;
        }

		if(isset($_REQUEST['pmpro_sequencepost']))
			$pmpro_sequencepost = intval($_REQUEST['pmpro_sequencepost']);

		if(isset($_REQUEST['pmpro_sequencedelay'])) {
            if ( $this->isValidDelay( $_REQUEST['pmpro_sequencedelay'] ) )
            {
                self::dbgOut('add_post(): Delay value is recognizable');
                if ( $this->isValidDate($_REQUEST['pmpro_sequencedelay']))
                {
                    self::dbgOut('add_post(): Delay specified as a valid date format');
                    $delay = $_REQUEST['pmpro_sequencedelay'];
                }
                else
                {
                    self::dbgOut('add_post(): Delay specified as the number of days');
                    $delay = intval($_REQUEST['pmpro_sequencedelay']);
                }
            }
            else
            {
                // Ignore this post & return error message to display for the user/admin
                $expectedDelay = ( $this->options->delayType == 'byDate' ) ? 'a date (Format: YYYY-MM-DD)' : 'a number (days since membership start)';
                self::dbgOut('getPostListForMetaBox(): Invalid delay value specified, not adding the post: ' . $_REQUEST['pmpro_sequencedelay']);
                $this->error = 'Error: Invalid delay type specified (' . $_REQUEST['pmpro_sequencedelay'] . '). Expected ' . $expectedDelay;
                $delay = null;
                $pmpro_sequencepost = null;
                return $this->error;
            }
        } else
            self::dbgOut('add_post(): No delay specified');

		if(isset($_REQUEST['pmpro_sequenceremove']))
			$remove = intval($_REQUEST['pmpro_sequenceremove']);
			
		//adding a post
		if(!empty($pmpro_sequencepost))
        {
            self::dbgOut('Adding post in metabox');
            $this->addPost($pmpro_sequencepost, $delay);
        }
		//removing a post
		if(!empty($remove))
			$this->removePost($remove);
						
		//show posts
		$this->getPosts();

        self::dbgOut('Displaying the back-end meta box content');
        // usort($this->posts, array("PMProSequences", "sortByDelay"));

		?>		
			
		<?php if(!empty($this->error)) { ?>
			<div class="message error"><p><?php echo $this->error;?></p></div>
		<?php } ?>
		<table id="pmpro_sequencetable" class="wp-list-table widefat fixed">
		<thead>
			<th>Order</th>
			<th width="50%">Title</th>
            <?php self::dbgOut('Delay Type: ' . $this->options->delayType); ?>
			<?php if ($this->options->delayType == 'byDays'): ?>
                <th id="pmpro_sequence_delaylabel">Delay</th>
            <?php elseif ( $this->options->delayType == 'byDate'): ?>
                <th id="pmpro_sequence_delaylabel">Avail. On</th>
            <?php else: ?>
                <th id="pmpro_sequence_delaylabel">Not Defined</th>
            <?php endif; ?>
			<th></th>
			<th></th>
		</thead>
		<tbody>
		<?php		
		$count = 1;
		
		if(empty($this->posts))
		{
            self::dbgOut('No Posts found?');
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
                    <?php self::dbgOut('Sequence entry # ' . $count . ' for post ' . $post->id . ' delayed ' . $this->normalizeDelay($post->delay)); ?>
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
			<p><strong>Add/Edit Posts:</strong></p>
			<table id="newmeta">
				<thead>
					<tr>
						<th>Post/Page</th>
                        <?php if ($this->options->delayType == 'byDays'): ?>
                            <th id="pmpro_sequence_delayentrylabel">Days to delay</th>
                        <?php elseif ( $this->options->delayType == 'byDate'): ?>
                            <th id="pmpro_sequence_delayentrylabel">Release on (YYYY-MM-DD)</th>
                        <?php else: ?>
                            <th id="pmpro_sequence_delayentrylabel">Not Defined</th>
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
						<td><input id="pmpro_sequencedelay" name="pmpro_sequencedelay" type="text" value="" size="7" /></td>
						<td><a class="button" id="pmpro_sequencesave">Add to Sequence</a></td>
					</tr>
				</tbody>
			</table>
		</div>
		<script>						
			jQuery(document).ready(function() {
				jQuery('#pmpro_sequencesave').click(function() {
					
					if(jQuery(this).html() == 'Saving...')
						return;	//already saving, ignore this request
					
					// Disable save button
					jQuery(this).html('Saving...');					

					//pass field values to AJAX service and refresh table above - Timeout is 5 seconds
					jQuery.ajax({
						url: '<?php echo home_url()?>',type:'GET',timeout:5000,
						dataType: 'html',
						data: "pmpro_sequenceadd_post=1&pmpro_sequence_id=<?php echo $this->sequence_id; ?>&pmpro_sequencepost=" + jQuery('#pmpro_sequencepost').val() + '&pmpro_sequencedelay=' + jQuery('#pmpro_sequencedelay').val(),
						error: function(xml){
							alert('Website error while saving sequence post');
							// Re-enable save button
							jQuery(this).html('Save');												
						},
						success: function(responseHTML){
							if ( responseHTML.match("^Error") )
							{
								alert(responseHTML);
								// Re-enable save button
								jQuery('#pmpro_sequencesave').html('Save');
							}
							else
							{
								jQuery('#pmpro_sequence_posts').html(responseHTML);
							}																						
						}
					});
				});
			});				
			
			function pmpro_sequence_editPost(post_id, delay)
			{
				jQuery('#pmpro_sequencepost').val(post_id).trigger("change");
				jQuery('#pmpro_sequencedelay').val(delay);
				jQuery('#pmpro_sequencesave').html('Save');
				location.href = "#pmpro_sequenceedit_post";
			}
			
			function pmpro_sequence_removePost(post_id)
			{								
				jQuery.ajax({
					url: '<?php echo home_url()?>',type:'GET',timeout:5000,
					dataType: 'html',
					data: "pmpro_sequenceadd_post=1&pmpro_sequence_id=<?php echo $this->sequence_id;?>&pmpro_sequenceremove="+post_id,
					error: function(xml){
						alert('Error removing sequence post [1]');
						//enable save button
						jQuery('#pmpro_sequencesave').removeAttr('disabled');												
					},
					success: function(responseHTML){
						if (responseHTML.match("^Error"))
						{
							alert(responseHTML);
							//enable save button
							jQuery('#pmpro_sequencesave').removeAttr('disabled');	
						}
						else
						{
                            alert('Removed Post/Page from this Sequence');
							jQuery('#pmpro_sequence_posts').html(responseHTML);
						}																						
					}
				});
			}
		</script>		
		<?php		
	}
}

