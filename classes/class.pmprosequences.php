<?php
define('PMPROS_SEQUENCE_DEBUG', true);

class PMProSequences
{
    public $options = array();
    private $sequence_id = null;

	//constructor
	function PMProSequences($id = null)
	{
        $this->dbgOut('Instantiated class & fetching sequence: ' . $id);

		if ( ! empty($id) )
        {
            $this->dbgOut('ID provided');
            $this->sequence_id = $id;
//            $this->options = $this->fetchOptions($this->sequence_id);

			return $this->getSeriesByID($this->sequence_id);
        }
        else
            $this->options = $this->defaultOptions();
	}

	//populate sequence data by post id passed
	function getSeriesByID($id)
	{
		$this->post = get_post($id);

		if(!empty($this->post->ID))
        {
			$this->id = $id;
            $this->fetchOptions( $this->id );
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
            'hidden' => false,
            'dayCount' => true,
            'sortOrder' => SORT_ASC,
            'delayType' => 'byDays',
        );
    }

    /**
     *
     * Fetch any options for this specific sequence from the database (stored as post metadata)
     * Use default options if the sequence ID isn't supplied
     *
     * @param int|null $sequence_id - The Sequence ID to fetch options for
     * @return bool - true on success | false on error
     *
     */
    public function fetchOptions( $sequence_id = null )
    {

        // Check that we're being called in context of an actual Sequence 'edit' operation
        if (is_null($sequence_id))
            $this->dbgOut('No sequence id provided. Will return defaults');
        else
        {
            $this->dbgOut('Sequence ID given (' . $sequence_id .') so loading options from post metadata');
            $this->options = get_post_meta($sequence_id, '_pmpros_sequence_settings', false);

            if ( empty($this->options))
                $this->options = array(
                    false, true, SORT_ASC, 'byDays'
                );

            // ToDo: format options?

/*                                'dayCount' => get_post_meta($sequence_id, '_tls_pmpros_sequence_daycount', true),
                                'sortOrder' => get_post_meta($sequence_id, '_tls_pmpros_sequence_sortorder', true),
                                'delayType' => get_post_meta($sequence_id, '_tls_pmpros_sequence_delaytype', true)
                            );
*/
            // $this->options = get_post_meta($sequence_id, '_tls_sequence_settings', false);
            $this->dbgOut('Options read from WPDB');
        }

        // Check whether there is any data in the DB.
        // If not, use the defaults
/*
        if ( empty( $this->options['hidden']) )
            $this->options['hidden'] = false; // Default option

        if ( empty( $this->options['dayCount']) )
            $this->options['dayCount'] = 'on'; // Default option

        if ( empty( $this->options['sortOrder']) )
            $this->options['sortOrder'] = SORT_ASC; // Default option

        if ( empty( $this->options['delayType']) )
            $this->options['delayType'] = 'byDays'; // Default option
*/
        return true;
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

        // OK, we're authenticated: we need to find and save the data
        if ( isset($_POST['pmpros_settings_noncename']) )
        {
            $sequence = new PMProSequences($post_id);
            $sequence->dbgOut('About to try saving settings for post ' . $post_id);
            $settings = $sequence->options;

            if ( isset($_POST['pmpros_sequence_hidden']) )
            {
                $settings[0] = $_POST['pmpros_sequence_hidden'];
                $sequence->dbgOut('POST value for hidden: ' . $_POST['pmpros_sequence_hidden'] );
            }
            else
                $settings[0] = false;

            if ( isset($_POST['pmpros_sequence_daycount']) )
            {
                $settings[1] = $_POST['pmpros_sequence_daycount'];
                $sequence->dbgOut('POST value for dayCount: ' . $_POST['pmpros_sequence_daycount']);
            }
            else
                $settings[1] = 'on';

            if ( isset($_POST['pmpros_sequence_sortorder']) )
            {
                $settings[2] = $_POST['pmpros_sequence_sortorder'];
                $sequence->dbgOut('POST value for sortOrder: ' . $_POST['pmpros_sequence_sortorder'] );
            }
            else
                $settings[2] = SORT_ASC;

            if ( isset($_POST['pmpros_sequence_delaytype']) )
            {
                $settings[3] = $_POST['pmpros_sequence_delaytype'];
                $sequence->dbgOut('POST value for delayType: ' . $_POST['pmpros_sequence_delaytype'] );
            }
            else
                $settings[3] = 'byDays';

            // Save settings to WPDB
            update_post_meta($post_id, '_pmpros_sequence_settings', $settings);

            /*
            if (! update_post_meta($post_id, '_pmpros_sequence_settings', $_POST['pmpros_sequence_hidden']) )
                $sequence->dbgOut('Unable to save parameter "hidden": ' . $_POST['pmpros_sequence_hidden']);

            if (! update_post_meta($post_id, '_tls_pmpros_sequence_daycount', $_POST['pmpros_sequence_daycount']) )
                $sequence->dbgOut('Unable to save parameter "dayCount": ' . $_POST['pmpros_sequence_daycount']);

            if (! update_post_meta($post_id, '_tls_pmpros_sequence_sortorder', $_POST['pmpros_sequence_sortorder'] ) )
                $sequence->dbgOut('Unable to save parameter "sortOrder": ' . $_POST['pmpros_sequence_sortorder']);

            if (! update_post_meta($post_id, '_tls_pmpros_sequence_delaytype', $_POST['pmpros_sequence_delaytype'] ) )
                $sequence->dbgOut('Unable to save parameter "delayType": ' . $_POST['pmpros_sequence_delaytype']);
            */
            $sequence->dbgOut('Saved metadata for sequence #' . $post_id);
            // update_post_meta($post_id, '_tls_sequence_settings', (array)$settings->options);

        }
    }

    //add a post to this sequence
	function addPost($post_id, $delay)
	{
        $this->dbgOut('Adding post');

        if (! $this->isValidDelay($delay) )
        {
            $this->dbgOut('Admin specified an invalid delay value for post: ' . ( empty($post_id) ? 'Unknown' :  $post_id) );
            $this->error = 'Invalid delay value specified.';
            return false;
        }

		if(empty($post_id) || !isset($delay))
		{
			$this->error = "Please enter a value for post and delay.";
            $this->dbgOut('No Post ID or delay specified');
			return false;
		}

        $this->dbgOut('Post ID: ' . $post_id . ' and delay: ' . $delay);

		$post = get_post($post_id);
			
		if(empty($post->ID))
		{
			$this->error = "A post with that id does not exist.";
            $this->dbgOut('No Post with ' . $post_id . ' found');
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
        $this->dbgOut('Sorting the Sequence by delay');
		usort($this->posts, array("PMProSequences", "sortByDelay"));

		//save
		update_post_meta($this->id, "_post_sequence", $this->posts);

		//add sequence to post
		$post_sequence = get_post_meta($post_id, "_post_sequence", true);
		if(!is_array($post_sequence)) {
            $this->dbgOut('No (yet) an array of posts. Adding the single new post');
			$post_sequence = array($this->id);
        }
        else
        {
			$post_sequence[] = $this->id;
            $this->dbgOut('Appended post (ID: ' . $this->id . ') to Sequence');
        }
		//save
		update_post_meta($post_id, "_post_sequence", $post_sequence);
        $this->dbgOut('Post/Page list updated and saved');

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

            $this->dbgOut('Post/Page list updated and saved');

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

        if (!isset($this->options['sortOrder']))
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
            $this->dbgOut('Delay for post with id = ' . $post_id . ' is ' .$delay);
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
        $this->dbgOut('In normalizeDelay() for delay:' . $delay);

        if ( $this->isValidDate($delay) ) {
            $this->dbgOut('Delay specified as a valid date: ' . $delay);
            return $this->convertToDays($delay);
        }
        $this->dbgOut('Delay specified as # of days since membership start: ' . $delay);
        return $delay;
    }

    function sortByDelay($a, $b)
    {
        $this->dbgOut('in sortByDelay()');

        if (empty($this->options['sortOrder']))
        {
            $this->dbgOut('Need sortOrder option to base sorting decision on...');
            // $sequence = $this->getSeriesByID($a->id);
            if ( $this->sequence_id !== null)
            {
                $this->dbgOut('Have valid sequence post ID saved: ' . $this->sequence_id);
                $this->fetchOptions( $this->sequence_id );
            }
        }

        switch ($this->options['sortOrder'])
        {
            case SORT_ASC:
                $this->dbgOut('Sorted in Ascending order');
                return $this->sortAscending($a, $b);
                break;
            case SORT_DESC:
                $this->dbgOut('Sorted in Descending order');
                return $this->sortDescending($a, $b);
                break;
            default:
                $this->dbgOut('sortOrder not defined');
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
            $tmpFile = '/Users/sjolshag/strongcubedfitness.com/sequence_debug_log.txt';
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
            $this->dbgOut('C2Days - Member start date: ' . date('Y-m-d', $startDate) . ' and end date: ' . $date .  ' for delay day count: ' . $days);
        } else {
            $days = $date;
            $this->dbgOut('C2Days - Days of delay from start: ' . $date);
        }

        return $days;
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
        $sequence->dbgOut('Load the post list meta box');

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

        $settings = new PMProSequences($post->ID);

        if (! $settings->fetchOptions($post->ID))
        {
            $settings->error = 'Error fetching the Sequence options';
            $settings->dbgOut('Error fecthing the Sequence options for ' . $post->ID);
        }
        ?>
        <input type="hidden" name="pmpros_settings_noncename" id="pmpros_settings_noncename" value="<?php echo wp_create_nonce( plugin_basename(__FILE__) )?>" />
        <input type="hidden" name="pmpros_settings_hidden_delay" id="pmpros_settings_hidden_delay" value="<?php echo $settings->options['delayType']; ?>"/>
        <label class="selectit">
            <input type="checkbox" value="1" title="Hide unpublished / future posts for this sequence" name="pmpros_sequence_hidden" <?php echo ($settings->options['hidden'] == true ? 'checked="checked">' : '>'); ?>
            Hide future posts
        </label>
        <br />
        <label class="selectit">
            <input type="checkbox" value="1" title="Whether to show &quot;You are on day NNN of your membership&quot; text" name="pmpros_sequence_daycount" <?php echo ($settings->options['dayCount'] == true ? 'checked="checked">' : '>'); ?>
            Show membership length info
        </label>
        <br />
        <p><strong>Sort order (listing order)</strong></p>
        <label class="screen-reader-text" for="pmpros_sequence_sortorder">Sort Order</label>
        <select name="pmpros_sequence_sortorder" id="pmpros_sequence_sortorder">
            <option value="<?php echo SORT_ASC; ?>" <?php selected( $settings->options['sortOrder'], SORT_ASC); ?> >Ascending</option>
            <option value="<?php echo SORT_DESC; ?>" <?php selected( $settings->options['sortOrder'], SORT_DESC); ?> >Descending</option>
        </select>
        <br />
        <p><strong>Sequence Delay type</strong></p>
        <label class="screen-reader-text" for="pmpros_sequence_delaytype">Delay Type</label>
        <select name="pmpros_sequence_delaytype" id="pmpros_sequence_delaytype" >
            <option value="<?php echo 'byDays'; ?>" <?php selected( $settings->options['delayType'], 'byDays'); ?> >Number of Days</option>
            <option value="<?php echo 'byDate'; ?>" <?php selected( $settings->options['delayType'], 'byDate'); ?> >Release Date (YYYY-MM-DD)</option>
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
            $this->dbgOut('Sorting posts for display');
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
                        <?php $this->dbgOut('Post ' . $sp->id . ' delay: ' . $sp->delay); ?>
						<span class="pmpro_sequence_item-title"><a href="<?php echo get_permalink($sp->id);?>"><?php echo get_the_title($sp->id);?></a></span>
						<span class="pmpro_sequence_item-available"><a class="pmpro_btn pmpro_btn-primary" href="<?php echo get_permalink($sp->id);?>">Available Now</a></span>
                    </li>
 					<?php } elseif ( ! ($this->isPastDelay( $memberFor, $sp->delay )) && ( ! $this->hideUpcomingPosts() ) ) { ?>
                    <li>
						<span class="pmpro_sequence_item-title"><?php echo get_the_title($sp->id);?></span>
						<span class="pmpro_sequence_item-unavailable">available on <?php echo ($this->options['delayType'] == 'byDays' ? 'day' : ''); ?> <?php echo $sp->delay;?></span>
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
        $this->dbgOut('Do we show or hide upcoming posts?');
        return ($this->options['hidden'] == 'on' ? true : false );
    }

    /**
     * Validates that the value received follows a valid "delay" format for the post/page sequence
     *
     */
    public function isValidDelay( $delay )
    {
        $this->dbgOut('Delay value is: ' . $delay);

        switch ($this->options['delayType'])
        {
            case 'byDays':
                $this->dbgOut('Delay configured as "days since membership start"');
                return ( is_numeric( $delay ) ? true : false);
                break;

            case 'byDate':
                $this->dbgOut('Delay configured as a date value');
                return ( $this->isValidDate( $delay ) ? true : false);
                break;

            default:
                $this->dbgOut('Not a valid delay value, based on config');
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
            $this->dbgOut('User is not permitted to edit');
			return false;
        }

		if(isset($_REQUEST['pmpros_post']))
			$pmpros_post = intval($_REQUEST['pmpros_post']);

		if(isset($_REQUEST['pmpros_delay'])) {
            if ( $this->isValidDelay( $_REQUEST['pmpros_delay'] ) )
            {
                $this->dbgOut('Delay value is recognizable');
                if ( $this->isValidDate($_REQUEST['pmpros_delay']))
                {
                    $this->dbgOut('Delay specified as a valid date format');
                    $delay = $_REQUEST['pmpros_delay'];
                }
                else
                {
                    $this->dbgOut('Delay specified as the number of days');
                    $delay = intval($_REQUEST['pmpros_delay']);
                }
            }
            else
            {
                // Ignore this post (TODO: Return error with correct warning message)
                $this->dbgOut('Invalid delay value specified: ' . $_REQUEST['pmpros_delay']);
                $delay = null;
                $pmpros_post = null;
            }
        } else
            $this->dbgOut('No delay specified');

		if(isset($_REQUEST['pmpros_remove']))
			$remove = intval($_REQUEST['pmpros_remove']);
			
		//adding a post
		if(!empty($pmpros_post))
        {
            $this->dbgOut('Adding post in metabox');
            $this->addPost($pmpros_post, $delay);
        }
		//removing a post
		if(!empty($remove))
			$this->removePost($remove);
						
		//show posts
		$this->getPosts();

        $this->dbgOut('Displaying the back-end meta box content');
        // usort($this->posts, array("PMProSequences", "sortByDelay"));

		?>		
			
		<?php if(!empty($this->error)) { ?>
			<div class="message error"><p><?php echo $this->error;?></p></div>
		<?php } ?>
		<table id="pmpros_table" class="wp-list-table widefat fixed">
		<thead>
			<th>Order</th>
			<th width="50%">Title</th>
            <?php $this->dbgOut('Delay Type: ' . $this->options['delayType']); ?>
			<?php if ($this->options['delayType'] == 'byDays'): ?>
                <th>Delay (# of days)</th>
            <?php elseif ( $this->options['delayType'] == 'byDate'): ?>
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
            $this->dbgOut('No Posts found?');
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
                        <?php if ($this->options['delayType'] == 'byDays'): ?>
                            <th>Delay (# of days)</th>
                        <?php elseif ( $this->options['delayType'] == 'byDate'): ?>
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
						data: "pmpros_add_post=1&pmpros_sequence=<?php echo get_post_id;?>&pmpros_post=" + jQuery('#pmpros_post').val() + '&pmpros_delay=' + jQuery('#pmpros_delay').val(),
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
					data: "pmpros_add_post=1&pmpros_sequence=<?php echo $this->id;?>&pmpros_remove="+post_id,
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