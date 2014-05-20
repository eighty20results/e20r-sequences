<?php
define('PMPROS_SEQUENCE_DEBUG', true);

class PMProSequences
{
    private $options = array();
    private $sequence_id = 0;

	//constructor
	function PMProSequences($id = null)
	{
		if ( ! empty($id) )
        {
            self::dbgOut('__constructor() - Sequence ID: ' . $id);

            $this->sequence_id = $id;
			return $this->getSeriesByID($id);
        }
        else
            if (self::sequence_id != 0)
            {
                self::dbgOut('No ID supplied to __construct(), but ID was set before, so load options');
                self::setSettings( self::defaultOptions() );
            }
            else
            {
                self::dbgOut('No sequence ID or options defined! Checking against global variables');
                global $wp_query;
                if ($wp_query->post->ID)
                {
                    self::dbgOut('Found Post ID and loading options if not already loaded ' . $wp_query->post->ID);
                    self::setID( $wp_query->post->ID );
                    self::fetchOptions( self::getID() );
                    if ( empty( $this->options ) )
                        $this->defaultOptions();
                }


            }
	}

    /*************************************************************************************/
    /* Internal routines for fetching sequence related information (ID & Settings array) */

    public function getID()
    {
        return $this->sequence_id;
    }

    public function setID( $var )
    {
        $this->sequence_id = (int) $var;
    }

    public function getSettings()
    {
        return $this->options;
    }

    public function setSettings( $varArray )
    {
        if (! is_array($varArray))
            self::dbgOut('Not a valid settings array!');

        $this->options = $varArray;
        if (self::getID() != 0)
            self::save_sequence_meta( self::getID() );
    }

    public function setSetting( $value, $idx = 0 )
    {
        if (! empty($this->options[$idx]))
            self::dbgOut('Overwriting setting # ' . $idx . ' (old: ' . $this->options[$idx] . ') with ' . $value);

        $this->options[$idx] = $value;
    }

    /*************************************************************************************/

    //populate sequence data by post id passed
	function getSeriesByID($id)
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
        return array(
            0, // 'hidden'
            1, //'dayCount'
            SORT_ASC, // 'sortOrder'
            'byDays', // 'delayType'
            0, // startTime
        );
    }

    /**
     *
     * Fetch any options for this specific sequence from the database (stored as post metadata)
     * Use default options if the sequence ID isn't supplied
     *
     * Array content:
     *  [0] => hidden (boolean) - Whether to show or hide upcoming (future) posts in sequence from display.
     *  [1] => dayCount (boolean) - Whether to show or hide the "You are on day X of your membership" information.
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
     * @return bool -- Returns True if options were successfully fetched & saved.
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
                self::setID($sequence_id);
            }
            elseif ($this->sequence_id == 0)
            {
                // This shouldn't be possible... (but never say never!)
                self::setID($sequence_id);
            }
        }

        // Check that we're being called in context of an actual Sequence 'edit' operation
        if ( empty($this->options) )
        {
            self::dbgOut('fetchOptions(): No settings found. Using defaults');
            $this->options = self::defaultOptions();
        }
        else
        {
            self::dbgOut('fetchOptions(): Attempting to load settings from DB');
            $this->options = get_post_meta(self::getSettings(), '_pmpros_sequence_settings');

            self::dbgOut('Loaded from DB: '. print_r($this->options, true));

            // Check whether we need to set any default variables for the settings
            if ( ! $this->options )
            {
                self::dbgOut('fetchOptions(): Failed to load options from DB');
                $this->options = self::defaultOptions();
            }
        }
    }

    /**
     * Save the settings as metadata for the sequence
     *
     * @param $post_id -- ID of the sequence these options belong to.
     * @return int | mixed - Either the ID of the Sequence or its content
     */
    function pmpros_sequence_meta_save( $post_id )
    {
        // Check that the function was called correctly. If not, just return
        if(empty($post_id))
            return false;

        //Verify that this is a valid call (from the actual edit page)
        if (!isset($_POST['pmpros_settings_noncename']) || !wp_verify_nonce( $_POST['pmpros_settings_noncename'], plugin_basename(__FILE__) ))
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

        self::dbgOut('pmpros_sequence_meta_save(): About to save settings for sequence ' . $post_id);
        self::dbgOut('From Web: ' . print_r($_REQUEST, true));

        // OK, we're authenticated: we need to find and save the data
        if ( isset($_POST['pmpros_settings_noncename']) )
        {
            $settings = self::getSettings();

            if ( empty($settings) )
            {
                self::dbgOut('Have to load new instance of Sequence class');
                $sequence = new PMProSequences($post_id);
                $sequence->fetchOptions($post_id);
                $settings = $sequence->getSettings();
            }
            else
                self::dbgOut('pmpros_sequence_meta_save(): Current Settings: ' . print_r($settings, true));

            if ( isset($_POST['pmpros_sequence_hidden']) )
            {
                $settings[0] = $_POST['pmpros_sequence_hidden'];
                self::dbgOut('pmpros_sequence_meta_save(): POST value for hidden: ' . $_POST['pmpros_sequence_hidden'] );
            }
            elseif (empty($settings[0]))
                $settings[0] = false;

            if ( isset($_POST['pmpros_sequence_daycount']) )
            {
                $settings[1] = $_POST['pmpros_sequence_daycount'];
                self::dbgOut('pmpros_sequence_meta_save(): POST value for dayCount: ' . $_POST['pmpros_sequence_daycount']);
            }
            elseif (empty($settings[1]))
                $settings[1] = true;

            if ( isset($_POST['pmpros_sequence_sortorder']) )
            {
                $settings[2] = $_POST['pmpros_sequence_sortorder'];
                self::dbgOut('pmpros_sequence_meta_save(): POST value for sortOrder: ' . $_POST['pmpros_sequence_sortorder'] );
            }
            elseif (empty($settings[2]))
                $settings[2] = SORT_ASC;

            if ( isset($_POST['pmpros_sequence_delaytype']) )
            {
                $settings[3] = $_POST['pmpros_sequence_delaytype'];
                self::dbgOut('pmpros_sequence_meta_save(): POST value for delayType: ' . $_POST['pmpros_sequence_delaytype'] );
            }
            elseif (empty($settings[3]))
                $settings[3] = 'byDays';

            // $sequence->options = $settings;

            // Save settings to WPDB
            self::save_sequence_meta( $settings );

            self::dbgOut('pmpros_sequence_meta_save(): Saved metadata for sequence #' . $post_id);
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
    function save_sequence_meta( $settings )
    {
        // Make sure the settings array isn't empty (no settings defined)
        if (! empty( $settings ))
        {
            // Get the ID of this sequence CPT
            $post_id = self::getID();

            self::dbgOut('save_sequence_meta(): Settings for ' . $post_id .' will be: ' . print_r( $settings, true));

            // Update the *_postmeta table for this sequence
            update_post_meta($post_id, '_pmpros_sequence_settings', $settings );

            // Preserve the settings in memory / class context
            self::setSettings($settings);

            self::dbgOut('save_sequence_meta(): Saved Sequence Settings for ' . $post_id);
            self::dbgOut('save_sequence_meta(): Settings are now: ' . print_r( self::getSettings(), true));
        }
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
		update_post_meta($this->id, "_post_sequence", $this->posts);

		//add sequence to post
		$post_sequence = get_post_meta($post_id, "_post_sequence", true);
		if(!is_array($post_sequence)) {
            self::dbgOut('addPost(): No (yet) an array of posts. Adding the single new post');
			$post_sequence = array($this->id);
        }
        else
        {
			$post_sequence[] = $this->id;
            self::dbgOut('addPost(): Appended post (ID: ' . $this->id . ') to Sequence');
        }
		//save
		update_post_meta($post_id, "_post_sequence", $post_sequence);
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
				update_post_meta($this->id, "_post_sequence", $this->posts);
				break;	//assume there is only one				
			}
		}
								
		//remove this sequence from the post
		$post_sequence = get_post_meta($post_id, "_post_sequence", true);
		if(is_array($post_sequence) && ($key = array_search($this->id, $post_sequence)) !== false)
		{
			unset($post_sequence[$key]);
			update_post_meta($post_id, "_post_sequence", $post_sequence);

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
			$this->posts = get_post_meta($this->id, "_post_sequence", true);

        if (!isset($this->options[3]))
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
			return false;
		else
        {
            $delay = $this->normalizeDelay( $this->posts[$key]->delay );
            self::dbgOut('getDelayForPost(): Delay for post with id = ' . $post_id . ' is ' .$delay);
            return $delay;
        }
	}

    // Returns a "days to delay" value for the posts $a & $b, even if the delay value is a date.

    function normalizeDelays($a, $b)
    {
        return array($this->convertToDays($a->delay), $this->convertToDays($b->delay));
    }

    public function normalizeDelay( $delay )
    {

        if ( $this->isValidDate($delay) ) {
            self::dbgOut('normalizeDelay(): Delay specified as a valid date: ' . $delay);
            return $this->convertToDays($delay);
        }
        self::dbgOut('normalizeDelay(): Delay specified as # of days since membership start: ' . $delay);
        return $delay;
    }

    function sortByDelay($a, $b)
    {
        if (empty($this->options[2]))
        {
            self::dbgOut('sortByDelay(): Need sortOrder option to base sorting decision on...');
            // $sequence = $this->getSeriesByID($a->id);
            if ( $this->sequence_id !== null)
            {
                self::dbgOut('sortByDelay(): Have valid sequence post ID saved: ' . $this->sequence_id);
                $this->fetchOptions( $this->sequence_id );
            }
        }

        switch ($this->options[2])
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
        if (PMPROS_SEQUENCE_DEBUG)
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
        if ( $this->isValidDate( $date ) )
        {
            $startDate = pmpro_getMemberStartdate();
            $dStart = new DateTime( date( 'Y-m-d', $startDate ) );
            $dEnd = new DateTime( date( 'Y-m-d', strtotime($date) ) ); // Today's date
            $dDiff = $dStart->diff($dEnd);
            $dDiff->format('%d');

            $days = $dDiff->days;
            self::dbgOut('convertToDays() - Member start date: ' . date('Y-m-d', $startDate) . ' and end date: ' . $date .  ' for delay day count: ' . $days);
        } else {
            $days = $date;
            self::dbgOut('convertToDays() - Days of delay from start: ' . $date);
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
	function sendEmail($post_id, $user_id)
	{
	}
	
	/*
		Create CPT
	*/
	function createCPT()
	{
		//don't want to do this when deactivating
		global $pmpros_deactivating;
		if(!empty($pmpros_deactivating))
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
		Meta boxes
	*/	
	function checkForMetaBoxes()
	{
		//add meta boxes
		if (is_admin())
		{
			wp_enqueue_style('pmpros-select2', plugins_url('css/select2.css', dirname(__FILE__)), '', '3.1', 'screen');
			wp_enqueue_script('pmpros-select2', plugins_url('js/select2.js', dirname(__FILE__)), array( 'jquery' ), '3.1' );
            // wp_enqueue_script('pmpros-settings', plugins_url('js/pmpro-sequence.js', dirname(__FILE__)), array( 'jquery' ), '3.1' );

			add_action('admin_menu', array("PMProSequences", "defineMetaBoxes"));
            add_action('save_post', array('PMProSequences', 'pmpros_sequence_meta_save'), 10, 2);
		}
	}

	function defineMetaBoxes()
	{
		//PMPro box
		add_meta_box('pmpro_page_meta', 'Require Membership', 'pmpro_page_meta', 'pmpro_sequence', 'side');

		// sequence settings box (for posts & pages)
        add_meta_box('pmpros-sequence-settings', esc_html__('Settings for this Sequence', 'pmpros_sequence'), array("PMProSequences", 'pmpro_sequence_settings_meta_box'), 'pmpro_sequence', 'side', 'high');
//        add_meta_box('pmpros_settings_meta', __('Settings', 'pmpros_sequence'), 'settings_page_meta', 'page', 'side');

		//sequence meta box
		add_meta_box('pmpro_sequence_meta', 'Posts in this Sequence', array("PMProSequences", "sequenceMetaBox"), 'pmpro_sequence', 'normal', 'high');

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
		<div id="pmpros_sequence_posts">
		<?php $sequence->getPostListForMetaBox(); ?>
		</div>				
		<?php		
	}

    function pmpro_sequence_settings_meta_box( $object, $box )
    {
        global $post;

        $sequence = new PMProSequences($post->ID);
        $settings = $sequence->fetchOptions($post->ID);
        if ( empty($sequence->options) )
        {
            $sequence->error = 'Error fetching the Sequence options';
            $sequence->dbgOut('pmpro_sequence_settings_meta_box(): Error fetching the Sequence options for #' . $post->ID);
        }

        $sequence->dbgOut('pmpro_sequence_settings_meta_box() - Loaded settings: ' . print_r($settings, true));

        ?>
        <input type="hidden" name="pmpros_settings_noncename" id="pmpros_settings_noncename" value="<?php echo wp_create_nonce( plugin_basename(__FILE__) )?>" />
        <input type="hidden" name="pmpros_settings_hidden_delay" id="pmpros_settings_hidden_delay" value="<?php echo $sequence->options[3]; ?>"/>
        <label class="selectit">
            <input type="checkbox" value="1" title="Hide unpublished / future posts for this sequence" name="pmpros_sequence_hidden" <?php checked($sequence->options[0], 1); ?> />
            Hide future posts
        </label>
        <br />
        <label class="selectit">
            <input type="checkbox" value="1" title="Whether to show &quot;You are on day NNN of your membership&quot; text" name="pmpros_sequence_daycount" <?php checked($sequence->options[1], 1); ?> />
            Show membership length info
        </label>
        <br />
        <!-- TODO: Enable and implement
        <label class="selectit">
            <input type="checkbox" value="1" title="Configure start parameters for sequence drip. The default is to start day 1 exactly 24 hours after membership started, using the servers timezone and recorded timestamp for the membership check-out." name="pmpros_sequence_daycount" <?php checked($sequence->options[5], 1); ?> />
            Configure Sequence Start
        </label>
        <div id="pmpros_sequence_startTime" style="display: none;">
        <p><strong>Day 1 of Sequence starts:</strong></p>
        <label class="screen-reader-text" for="pmpros_sequence_sortorder">Display Order</label>
        <select name="pmpros_sequence_sortorder" id="pmpros_sequence_sortorder">
            <option value="0" <?php selected( intval($sequence->options[4]), '0'); ?> >24 hours after membership started</option>
            <option value="1" <?php selected( intval($sequence->options[4]), '1'); ?> >At midnight, immediately after membership started</option>
            <option value="2" <?php selected( intval($sequence->options[4]), '2'); ?> >At midnight, 24+ hours after membership started</option>
        </select>
        </div>
        -->
        <br />
        <p><strong>Display order</strong></p>
        <label class="screen-reader-text" for="pmpros_sequence_sortorder">Display Order</label>
        <select name="pmpros_sequence_sortorder" id="pmpros_sequence_sortorder">
            <option value="<?php echo SORT_ASC; ?>" <?php selected( intval($sequence->options[2]), SORT_ASC); ?> >Ascending</option>
            <option value="<?php echo SORT_DESC; ?>" <?php selected( intval($sequence->options[2]), SORT_DESC); ?> >Descending</option>
        </select>
        <br />
        <p><strong>Sequence Delay type</strong></p>
        <label class="screen-reader-text" for="pmpros_sequence_delaytype">Delay Type</label>
        <select name="pmpros_sequence_delaytype" id="pmpros_sequence_delaytype" >
            <option value="byDays" <?php selected( $sequence->options[3], 'byDays'); ?> >Number of Days</option>
            <option value="byDate" <?php selected( $sequence->options[3], 'byDate'); ?> >Release Date (YYYY-MM-DD)</option>
        </select>
        <!-- Test whether the sequence delay type has been changed. Submit AJAX request to delete existing posts if it has -->
        <script language="javascript">
            jQuery(document).ready(function () {
                jQuery("#pmpros_sequence_delaytype")
                    .change(function(){
                        console.log('Process changes to delayType option');
                        var selected = jQuery(this).val();
                        var current = jQuery('input[name=pmpros_settings_hidden_delay]').val();
                        console.log( 'Post # ' + <?php echo $post->ID; ?> );
                        console.log( 'delayType: ' + selected );
                        console.log( 'Current: ' + current );

                        if ( jQuery(this).val() != jQuery('#pmpros_settings_hidden_delay').val() ) {
                            if (! confirm("Changing the delay type will erase all\n existing posts or pages in the Sequence list.\n\nAre you sure?\n (Cancel if 'No')\n\n"))
                            {
                                jQuery(this).val(jQuery.data(this, 'pmpros_settings_hidden_delay'));
                                jQuery(this).val(current);

                                return false;
                            };

                            jQuery.data(this, 'pmpros_settings_delaytype', jQuery(this).val());
                            // Send Ajax request to delete all existing articles/posts in sequence.
                            jQuery.ajax({
                                url: '<?php echo home_url()?>',type:'GET',timeout:5000,
                                dataType: 'html',
                                data: "pmpros_clear_series=1&pmpros_sequence=<?php echo $post->ID; ?>",
                                error: function(xml)
                                {
                                    alert('Error clearing old Sequence posts [1]');
                                },
                                success: function(responseHTML)
                                {
                                    if (responseHTML != 'error')
                                    {
                                        jQuery('#pmpros_sequence_posts').html(responseHTML);
                                    }
                                }
                            });
                        };

                        console.log('Selected: '+ jQuery(this).val());
                        console.log('Current (hidden): ' + jQuery('input[name=pmpros_settings_hidden_delay]').val());
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
				?>

					<?php
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
						<span class="pmpro_sequence_item-unavailable">available on <?php echo ($this->options[3] == 'byDays' ? 'day' : ''); ?> <?php echo $sp->delay;?></span>
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

    // Test whether to show future sequence posts (i.e. not yet available to member)
    public function hideUpcomingPosts()
    {
        self::dbgOut('hideUpcomingPosts(): Do we show or hide upcoming posts?');
        return $this->options[0] == 1 ? true : false;
    }

    /**
     * Validates that the value received follows a valid "delay" format for the post/page sequence
     *
     */
    public function isValidDelay( $delay )
    {
        self::dbgOut('isValidDelay(): Delay value is: ' . $delay);

        switch ($this->options[3])
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

    public function isPastDelay( $memberFor, $delay )
    {
        if ($this->isValidDate($delay))
            return ( time() >= strtotime( $delay . ' 00:00:00.0' )) ? true : false; // a date specified as the $delay

        return ( $memberFor >= $delay ) ? true : false;

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

		if(isset($_REQUEST['pmpros_post']))
			$pmpros_post = intval($_REQUEST['pmpros_post']);

		if(isset($_REQUEST['pmpros_delay'])) {
            if ( $this->isValidDelay( $_REQUEST['pmpros_delay'] ) )
            {
                self::dbgOut('add_post(): Delay value is recognizable');
                if ( $this->isValidDate($_REQUEST['pmpros_delay']))
                {
                    self::dbgOut('add_post(): Delay specified as a valid date format');
                    $delay = $_REQUEST['pmpros_delay'];
                }
                else
                {
                    self::dbgOut('add_post(): Delay specified as the number of days');
                    $delay = intval($_REQUEST['pmpros_delay']);
                }
            }
            else
            {
                // Ignore this post (TODO: Return error with correct warning message)
                self::dbgOut('add_post(): Invalid delay value specified: ' . $_REQUEST['pmpros_delay']);
                $delay = null;
                $pmpros_post = null;
            }
        } else
            self::dbgOut('add_post(): No delay specified');

		if(isset($_REQUEST['pmpros_remove']))
			$remove = intval($_REQUEST['pmpros_remove']);
			
		//adding a post
		if(!empty($pmpros_post))
        {
            self::dbgOut('Adding post in metabox');
            $this->addPost($pmpros_post, $delay);
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
		<table id="pmpros_table" class="wp-list-table widefat fixed">
		<thead>
			<th>Order</th>
			<th width="50%">Title</th>
            <?php self::dbgOut('Delay Type: ' . $this->options[3]); ?>
			<?php if ($this->options[3] == 'byDays'): ?>
                <th>Delay (# of days)</th>
            <?php elseif ( $this->options[3] == 'byDate'): ?>
                <th>Date</th>
            <?php else: ?>
                <th>Not Defined</th>
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
						<a href="javascript:pmpros_editPost('<?php echo $post->id;?>', '<?php echo $post->delay;?>'); void(0);">Edit</a>
					</td>
					<td>
						<a href="javascript:pmpros_removePost('<?php echo $post->id;?>'); void(0);">Remove</a>
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
                        <?php if ($this->options[3] == 'byDays'): ?>
                            <th>Delay (# of days)</th>
                        <?php elseif ( $this->options[3] == 'byDate'): ?>
                            <th>Date (YYYY-MM-DD)</th>
                        <?php else: ?>
                            <th>Not Defined</th>
                        <?php endif; ?>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>
						<select id="pmpros_post" name="pmpros_post">
							<option value=""></option>
						<?php
							$pmpros_post_types = apply_filters("pmpros_post_types", array("post", "page"));
							$allposts = $wpdb->get_results("SELECT ID, post_title, post_status FROM $wpdb->posts WHERE post_status IN('publish', 'draft', 'future', 'pending', 'private') AND post_type IN ('" . implode("','", $pmpros_post_types) . "') AND post_title <> '' ORDER BY post_title");
							foreach($allposts as $p)
							{
							?>
							<option value="<?php echo $p->ID;?>"><?php echo esc_textarea($p->post_title);?> (#<?php echo $p->ID;?><?php if($p->post_status == "draft") echo "-DRAFT";?>)</option>
							<?php
							}
						?>
						</select>
						<style>
							.select2-container {width: 100%;}
						</style>
						<script>
							jQuery('#pmpros_post').select2();
						</script>
						</td>
						<td><input id="pmpros_delay" name="pmpros_delay" type="text" value="" size="7" /></td>
						<td><a class="button" id="pmpros_save">Add to Series</a></td>
					</tr>
				</tbody>
			</table>
		</div>
		<script>						
			jQuery(document).ready(function() {
				jQuery('#pmpros_save').click(function() {
					
					if(jQuery(this).html() == 'Saving...')
						return;	//already saving, ignore this request
					
					//disable save button
					jQuery(this).html('Saving...');					

					//pass field values to AJAX service and refresh table above - Timeout is 5 seconds
					jQuery.ajax({
						url: '<?php echo home_url()?>',type:'GET',timeout:5000,
						dataType: 'html',
						data: "pmpros_add_post=1&pmpros_sequence=<?php echo $post->id; ?>&pmpros_post=" + jQuery('#pmpros_post').val() + '&pmpros_delay=' + jQuery('#pmpros_delay').val(),
						error: function(xml){
							alert('Error saving sequence post [1]');
							//enable save button
							jQuery(this).html('Save');												
						},
						success: function(responseHTML){
							if (responseHTML == 'error')
							{
								alert('Error saving sequence post [2]');
								//enable save button
								jQuery(this).html('Save');		
							}
							else
							{
								jQuery('#pmpros_sequence_posts').html(responseHTML);
							}																						
						}
					});
				});
			});				
			
			function pmpros_editPost(post_id, delay)
			{
				jQuery('#pmpros_post').val(post_id).trigger("change");
				jQuery('#pmpros_delay').val(delay);
				jQuery('#pmpros_save').html('Save');
				location.href = "#pmpros_edit_post";
			}
			
			function pmpros_removePost(post_id)
			{								
				jQuery.ajax({
					url: '<?php echo home_url()?>',type:'GET',timeout:2000,
					dataType: 'html',
					data: "pmpros_add_post=1&pmpros_sequence=<?php echo $post->id;?>&pmpros_remove="+post_id,
					error: function(xml){
						alert('Error removing sequence post [1]');
						//enable save button
						jQuery('#pmpros_save').removeAttr('disabled');												
					},
					success: function(responseHTML){
						if (responseHTML == 'error')
						{
							alert('Error removing sequence post [2]');
							//enable save button
							jQuery('#pmpros_save').removeAttr('disabled');	
						}
						else
						{
                            alert('Saved post <?php echo $this->id ?>');
							jQuery('#pmpros_sequence_posts').html(responseHTML);
						}																						
					}
				});
			}
		</script>		
		<?php		
	}
}

/*
if (is_admin())
{
    add_action('admin_menu', 'pmpro_page_meta_wrapper');
}
*/