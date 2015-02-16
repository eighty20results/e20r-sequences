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
		private $posts = array(); // List of posts
		private $post; // Individual post
        private $refreshed;
		public $error = null;
        private $managed_types = null;

        /**
         * Constructor for the Sequence
         *
         * @param null $id -- The ID of the sequence to initialize
         * @throws Exception - If the sequence doesn't exist.
         */
        function PMProSequence($id = null) {

            // Make sure it's not a dummy construct() call - i.e. for a post that doesn't exist.
            if ( ( $id != null ) && ( $this->sequence_id == 0 ) ) {

                $this->sequence_id = $this->getSequenceByID( $id ); // Try to load it from the DB

                if ( $this->sequence_id == false ) {
                    throw new Exception( "A Sequence with the ID {$this->sequence_id} does not exist on this system");
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

            if ( $id != null ) {

                $this->dbgOut('init() - Loading the "' . get_the_title($id) . '" sequence settings');

                // Set options for the sequence
                $this->fetchOptions( $id );
                $this->getPosts( true );

                $this->dbgOut( 'init() -- Done.' );

                return $this->sequence_id;
            }

            if (( $id == null ) && ( $this->sequence_id == 0 ) ) {
                throw new Exception('No sequence ID specified.');
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
        public function defaultOptions() {

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
            $settings->noticeSendAs = PMPRO_SEQ_SEND_AS_SINGLE; // ALways send the alert notice as one notice per message.
            $settings->noticeTime = '00:00'; // At Midnight (server TZ)
            $settings->noticeTimestamp = current_time('timestamp'); // The current time (in UTC)
            $settings->excerpt_intro = __('A summary of the post follows below:', 'pmprosequence');
            $settings->replyto = pmpro_getOption("from_email");
            $settings->fromname = pmpro_getOption("from_name");
            $settings->subject = __('New Content ', 'pmprosequence');
            $settings->dateformat = __('m-d-Y', 'pmprosequence'); // Using American MM-DD-YYYY format.

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
	    public function fetchOptions( $sequence_id = 0 ) {

            // Does the ID differ from the one this object has stored already?
            if ( ( $this->sequence_id != 0 ) && ( $this->sequence_id != $sequence_id )) {

                $this->dbgOut('fetchOptions() - ID defined already but we were given a different sequence ID');
                $this->sequence_id = $sequence_id;
            }
            elseif ($this->sequence_id == 0) {

                // This shouldn't be possible... (but never say never!)
	            $this->dbgOut("The defined sequence ID is empty so we'll set it to " . $sequence_id);
                $this->sequence_id = $sequence_id;
            }

	        // Check that we're being called in context of an actual Sequence 'edit' operation
	        $this->dbgOut('fetchOptions(): Loading settings from DB for (' . $this->sequence_id . ') "' . get_the_title($this->sequence_id) . '"');

	        $settings = get_post_meta($this->sequence_id, '_pmpro_sequence_settings', true);
            $this->dbgOut("fetchOptions() - Settings are now: " . print_r( $settings, true ) );

            // Fix: Offset error when creating a brand new sequence for the first time.
            if ( empty( $settings ) ) {

                $settings = $this->defaultOptions();
            }

            $loaded_options = $settings;
            $default_options = $this->defaultOptions();

            $this->options = (object) array_replace( (array)$default_options, (array)$loaded_options );

            // $this->dbgOut( "fetchOptions() for {$this->sequence_id}: Current: " . print_r( $this->options, true ) );

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

                $this->dbgOut( 'save_sequence_meta() - Unknown sequence ID. Need to instantiate the correct sequence first!' );
                return false;
            }

            try {

                // Update the *_postmeta table for this sequence
                update_post_meta($this->sequence_id, '_pmpro_sequence_settings', $settings );

                // Preserve the settings in memory / class context
                $this->dbgOut('save_sequence_meta(): Saved Sequence Settings for ' . $this->sequence_id);
            }
            catch (Exception $e) {

                $this->dbgOut('save_sequence_meta() - Error saving sequence settings for ' . $this->sequence_id . ' Msg: ' . $e->getMessage());
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
        public function getSequenceByID($id)
        {
            $this->post = get_post($id);

            if(!empty($this->post->ID))
            {
                $this->sequence_id = $id;
            }
            else
                $this->sequence_id = false;

            return $this->sequence_id;
        }

       /**
         * Load the private class variable $posts with the list of posts belonging to this sequence (semi-cached)
         *
         * @param bool $force -- Ignore the cache and force fetch from the DB
         * @return mixed -- Returns the aray of posts belonging to this sequence
         *
         * @access public
         */
        public function getPosts( $force = false )
        {
            if ( ( $force === true ) || empty( $this->posts ) ||
                ( ( $this->refreshed + 5*60 )  <= current_time( 'timestamp', true )  ) ) {

                $this->posts = array();
                $this->dbgOut("getPosts() - Refreshing post list for sequence # {$this->sequence_id}");

                $this->refreshed = current_time('timestamp', true);
                $tmp = get_post_meta( $this->sequence_id, "_sequence_posts", true );
                $this->posts = ( $tmp ? $tmp : array() ); // Fixed issue where empty sequences would generate error messages.


                if ( ! empty( $this->posts ) ) {

                    $this->dbgOut( "getPosts() - Sorting the current post list" );
                    usort( $this->posts, array( &$this, "sortByDelay" ) );
                }
            }

            return $this->posts;
        }

        /**
         * Find the post in the sequence and return its key
         *
         * @param $post_id -- The ID of the post
         * @return bool|int|string -- The key for the post
         *
         * @access public
         */
        public function getPostKey($post_id) {

            $this->getPosts();

            if ( empty( $this->posts ) ) {

                return false;
            }

            foreach( $this->posts as $key => $post ) {

                if ( $post->id == $post_id ) {

                    return $key;
                }
            }

            return false;
        }

        /**
         * Test whether a post belongs to a sequence & return a stdClass containing Sequence specific meta for the post ID
         *
         * @param id $post - Post ID to search for.
         * @return stdClass - The sequence specific post data for the specified post_id.
         *
         * @access public
         */
        public function get_postDetails( $post_id ) {

            if ( ( $key = $this->hasPost( $post_id ) ) !== false ) {

                return $this->posts[$key];

            }
            else
                return null;
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
        public function addPost( $post_id, $delay )
		{
            $this->dbgOut("addPost() for sequence {$this->sequence_id}: " . $this->whoCalledMe() );

	        if (! $this->isValidDelay($delay) )
	        {
	            $this->dbgOut('addPost(): Admin specified an invalid delay value for post: ' . ( empty($post_id) ? 'Unknown' :  $post_id) );
	            $this->setError( sprintf(__('Invalid delay value - %s', 'pmprosequence'), ( empty($delay) ? 'blank' : $delay ) ) );
	            return false;
	        }

			if(empty($post_id) || !isset($delay))
			{
				$this->setError( __("Please enter a value for post and delay", 'pmprosequence') );
	            $this->dbgOut('addPost(): No Post ID or delay specified');
				return false;
			}

	        $this->dbgOut('addPost(): Post ID: ' . $post_id . ' and delay: ' . $delay);

			if ( $post = get_post($post_id) === null ) {

                $this->setError( __("A post with that id does not exist", 'pmprosequence') );
                $this->dbgOut('addPost(): No Post with ' . $post_id . ' found');

                return false;
            }

            // Refresh the post list for the sequence, ignore cache
            $this->dbgOut("addPost(): Force refresh of post list for sequence");
            $this->getPosts( true );

			// Add post
			$temp = new stdClass();
			$temp->id = $post_id;
			$temp->delay = $delay;

            $key = $this->hasPost( $post_id );

			/* Only add the post if it's not already present. */
			if ( $key === false ) {

                $this->dbgOut( "addPost(): Not previously saved in the list of posts for this ({$this->sequence_id}) sequence" );
                $this->posts[] = $temp;
            }
            else {
                $this->dbgOut( "addPost() - Post already in sequence. Check if we need to update it. Post: {$this->posts[$key]->id} with delay {$this->posts[$key]->delay} versus {$delay}");

                switch ($this->options->delayType) {

                    case 'byDays':

                        if  ( intval($this->posts[$key]->delay) != intval($delay) ) {

                            $this->dbgOut("addPost(): Delay is different. Need to update everything and clear the notices");
                            $this->removePost( $post_id, true );
                            $this->posts[] = $temp;
                            $key = false;
                        }
                        break;

                    case 'byDate':

                        if ( $this->posts[$key]->delay != $delay ) {

                            $this->dbgOut("addPost(): Delay is different. Need to update everything and clear the notices");
                            $this->removePost( $post_id, true );
                            $this->posts[] = $temp;
                            $key = false;
                        }
                        break;
                }

            }

            if ( $key === false ) {

                // Fetch the list of sequences for this post, clean it up and save it (if needed)
                $post_sequence =  get_post_meta($post_id, "_post_sequences", true);

                //sort
                $this->dbgOut('addPost(): Sorting the Sequence by delay');
                usort($this->posts, array(&$this, "sortByDelay"));

                //save
                update_post_meta($this->sequence_id, "_sequence_posts", $this->posts);

                // Is there any previously saved sequence ID found for the post/page?
                if ( empty( $post_sequence ) ) {

                    $this->dbgOut('addPost(): No previously defined sequence(s) found for this post (ID: ' . $post_id . ')');
                    $post_sequence = array( $this->sequence_id );
                }
                else {

                    $this->dbgOut( 'addPost(): Post/Page w/id ' . $post_id . ' belongs to one or more sequences already: ' . count( $post_sequence ) );

                    if ( ! is_array( $post_sequence ) ) {

                        $this->dbgOut( 'addPost(): Not (yet) an array of posts. Adding the single new post to a new array' );
                        $post_sequence = array( $this->sequence_id );
                    } else {

                        // Bug Fix: Never checked if the Post/Page ID was already listed in the sequence.
                        $tmp = array_count_values( $post_sequence );
                        $cnt = $tmp[ $this->sequence_id ];

                        if ( $cnt == 0 ) {

                            // This is the first sequence this post is added to
                            $post_sequence[] = $this->sequence_id;
                            $this->dbgOut( 'addPost(): Appended post (ID: ' . $temp->id . ') to sequence ' . $this->sequence_id );
                        } else {

                            // Check whether there are repeat entries for the current sequence
                            if ( $cnt > 1 ) {

                                // There are so get rid of the extras (this is a backward compatibility feature due to a previous bug.)
                                $this->dbgOut( 'addPost() - More than one entry in the array. Clean it up!' );

                                $clean = array_unique( $post_sequence );

                                $this->dbgOut( 'addPost() - Cleaned array: ' . print_r( $clean, true ) );
                                $post_sequence = $clean;
                            }
                        }
                    }
                }

                // Save the sequence list for this post id
                update_post_meta( $post_id, "_post_sequences", $post_sequence );

                $this->dbgOut('addPost(): Post/Page list updated and saved');
	        }
            else {

                $this->dbgOut("addPost() - Nothing new to save");
            }

			return true;
	    }

        /**
         * Removes a post from the list of posts belonging to this sequence
         *
         * @param int $post_id -- The ID of the post to remove from the sequence
         * @param bool $remove_notice - Whether to also remove any 'notified' settings for users
         * @return bool - returns TRUE if the post was removed and the metadata for the sequence was updated successfully
         *
         * @access public
         */
        public function removePost($post_id, $remove_alerted = true)
		{
			if ( empty( $post_id ) ) {

                return false;
            }

			$this->getPosts();

			if ( empty( $this->posts ) ) {

                return true;
            }

			//remove this post from the sequence
			foreach( $this->posts as $i => $post ) {

				if ( $post->id == $post_id ) {

					unset($this->posts[$i]);
					$this->posts = array_values( $this->posts );
					update_post_meta( $this->sequence_id, "_sequence_posts", $this->posts );

					break;	//assume there is only one
				}
			}

			// Remove the post ($post_id) from all cases where a User has been notified.
            if ( $remove_alerted ) {

                $this->removeNotifiedFlagForPost( $post_id );
            }

			// Remove the sequence id from the post's metadata
			$post_sequence = get_post_meta($post_id, "_post_sequences", true);

			if ( is_array( $post_sequence ) && ( $key = array_search( $this->sequence_id, $post_sequence ) ) !== false ) {

				unset( $post_sequence[$key] );
				update_post_meta( $post_id, "_post_sequences", $post_sequence );

	            $this->dbgOut( "removePost(): Post/Page list updated and saved" );
                $this->dbgOut( "removePost(): Post/Page list is now: " . print_r( $post_sequence, true ) );
	        }

			return true;
		}

        /********************************************** Metaboxes **********************************************/

        /**
         * Configure metabox for the normal Post/Page editor
         */
        public function loadPostMetabox( $object = null, $box = null ) {

            $this->dbgOut("Post metaboxes being configured");
            global $load_pmpro_sequence_admin_script;

            $load_pmpro_sequence_admin_script = true;

            foreach( $this->managed_types as $type ) {

                if ( $type !== 'pmpro_sequence' ) {
                    add_meta_box( 'pmpro-seq-post-meta', __( 'Drip Feed Settings', 'pmprosequence' ), array( &$this, 'renderEditorMetabox' ), $type, 'side', 'high' );
                }
            }
        }

        /**
         * Initial load of the metabox for the editor sidebar
         */
        public function renderEditorMetabox() {

            $metabox = '';

            global $post;

            $seq = new PMProSequence();

            $this->dbgOut("Page Metabox being loaded");

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
        public function getAllSequences( $statuses = 'publish' ) {

            $query = array(
                'post_type' => 'pmpro_sequence',
                'post_status' => $statuses,
            );

            wp_reset_query();

            /* Fetch all Sequence posts */
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

            $this->dbgOut("Parameters for load_sequence_meta() {$post_id} and {$seq_id}.");
            $belongs_to = array();

            /* Fetch all Sequence posts */
            $sequence_list = $this->getAllSequences( 'any' );

            $this->dbgOut("Loading Sequences (count: " . count($sequence_list) . ")");

            // Post ID specified so we need to look for any sequence related metadata for this post

            if ( empty( $post_id ) ) {

                global $post;
                $post_id = $post->ID;
            }

            // if ( ! empty( $seq_id ) && ( $seq_id != 0 ) ) {
            //    $belongs_to = array(  $seq_id );
            // }
            // else {
            $this->dbgOut("Loading sequence ID(s) from DB");
            $belongs_to = get_post_meta( $post_id, "_post_sequences", true );

            // $this->dbgOut( "Sequences for {$post_id}: " . print_r($belongs_to, true) );
            // $belongs_to = $this->update_postSeqList( $post_id, true );

            if ( ! empty( $belongs_to ) ) { // get_post_meta( $post_id, "_post_sequences", true ) ) {

                if ( is_array( $belongs_to ) && ( $seq_id != 0 ) && ( ! in_array( $seq_id, $belongs_to ) ) ) {

                    $this->dbgOut("Adding the new sequence ID to the existing array of sequences");
                    array_push( $belongs_to, $seq_id );
                }
            }
            elseif ( $seq_id != 0 ) {

                $this->dbgOut("This post has never belonged to a sequence. Adding it to one now");
                $belongs_to = array( $seq_id );
            }
            else {
                // Empty array
                $belongs_to = array();
            }

            // Make sure there's at least one row in the Metabox.
            $this->dbgOut(" Ensure there's at least one entry in the table. Sequence ID: {$seq_id}");
            array_push( $belongs_to, 0 );

            // $this->dbgOut("Post belongs to # of sequence(s): " . count( $belongs_to ) . ", content: " . print_r( $belongs_to, true ) );
            ob_start();
            ?>
            <?php wp_nonce_field('pmpro-sequence-post-meta', 'pmpro_sequence_postmeta_nonce');?>
            <div class="seq_spinner vt-alignright"></div>
            <table style="width: 100%;" id="pmpro-seq-metatable">
                <tbody>
                <?php foreach( $belongs_to as $active_id ) { ?>
                    <?php $this->dbgOut("Adding rows for {$active_id}");?>
                    <tr><td><fieldset></td></tr>
                    <tr class="select-row-label<?php echo ( $active_id == 0 ? ' new-sequence-select-label' : ' sequence-select-label' ); ?>">
                        <td>
                            <label for="pmpro_seq-memberof-sequences"><?php _e("Managed by (drip content feed)", "pmprosequence"); ?></label>
                        </td>
                    </tr>
                    <tr class="select-row-input<?php echo ( $active_id == 0 ? ' new-sequence-select' : ' sequence-select' ); ?>">
                        <td class="sequence-list-dropdown">
                            <select class="<?php echo ( $active_id == 0 ? 'new-sequence-select' : 'pmpro_seq-memberof-sequences'); ?>" name="pmpro_seq-sequences[]">
                                <option value="0" <?php echo ( ( empty( $belongs_to ) || $active_id == 0) ? 'selected' : '' ); ?>><?php _e("Not managed", "pmprosequence"); ?></option>
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
                        $this->dbgOut("Loading options for {$active_id}");
                        $this->fetchOptions( $active_id );
                    }
                    else {
                        $this->sequence_id = 0;
                        $this->options = $this->defaultOptions();
                    }

                    $delay = false; // Set/Reset
                    $this->dbgOut("Loading all posts for {$active_id}");

                    if ( $this->sequence_id != 0 ) {
                        $this->getPosts( true );

                        $delay = $this->getDelayForPost( $post_id, false );
                        $this->dbgOut( "Delay Value: {$delay}" );
                    }

                    if ( $delay === false ) {
                        $delayVal = "value=''";
                    }
                    else {
                        $delayVal = " value='{$delay}' ";
                    }

                    switch ( $this->options->delayType ) {

                        case 'byDate':

                            $this->dbgOut("Configured to track delays by Date");
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

                            $this->dbgOut("Configured to track delays by Day count");
                            $delayFormat = __('Day count', "pmprosequence");
                            $inputHTML = "<input class='pmpro-seq-delay-info pmpro-seq-days' type='text' id='pmpro_seq-delay_{$active_id}' name='pmpro_seq-delay[]'{$delayVal}>";

                    }

                    $label = sprintf( __("Delay (Format: %s)", "pmprosequence"), $delayFormat );
                    // $this->dbgOut(" Label: " . print_r( $label, true ) );
                    ?>
                    <tr class="delay-row-label<?php echo ( $active_id == 0 ? ' new-sequence-delay-label' : ' sequence-delay-label' ); ?>">
                        <td>
                            <label for="pmpro_seq-delay_<?php echo $active_id; ?>"> <?php echo $label; ?> </label>
                        </td>
                    </tr>
                    <tr class="delay-row-input<?php echo ( $active_id == 0 ? ' new-sequence-delay' : ' sequence-delay' ); ?>">
                        <td>
                            <?php echo $inputHTML; ?>
                            <label for="remove-sequence_<?php echo $active_id; ?>" ><?php _e('Remove: ', 'pmprosequence'); ?></label><input type="checkbox" name="remove-sequence" class="pmpro_seq-remove-seq" value="<?php echo $active_id; ?>">
                        </td>
                    </tr>
                    <tr><td></fieldset></td></tr>
                <?php } // Foreach ?>
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

	    /**
	     * Add the actual meta box definitions as add_meta_box() functions (3 meta boxes; One for the page meta,
	     * one for the Settings & one for the sequence posts/page definitions.
         *
         * @access public
	     */
	    public function defineMetaBoxes() {

			//PMPro box
			add_meta_box('pmpro_page_meta', __('Require Membership', 'pmprosequence'), 'pmpro_page_meta', 'pmpro_sequence', 'side');

            $this->dbgOut("Loading post meta boxes");

			// sequence settings box (for posts & pages)
	        add_meta_box('pmpros-sequence-settings', __('Settings for this Sequence', 'pmprosequence'), array( &$this, 'settings_meta_box'), 'pmpro_sequence', 'side', 'high');

			//sequence meta box
			add_meta_box('pmpro_sequence_meta', __('Posts in this Sequence', 'pmprosequence'), array(&$this, "sequenceMetaBox"), 'pmpro_sequence', 'normal', 'high');
	    }

        /**
         * Defines the Admin UI interface for adding posts to the sequence
         *
         * @access public
         */
        public function sequenceMetaBox() {
			global $post;

			if ( ! isset( $this->sequence_id ) || ( $this->sequence_id != $post->ID )  ) {
                $this->dbgOut("sequenceMetaBox() - Loading the sequence metabox for {$post->ID} and not {$this->sequence_id}");
                $this->init( $post->ID );
            }

	        $this->dbgOut('sequenceMetaBox(): Load the post list meta box');

	        // Instantiate the settings & grab any existing settings if they exist.
	     ?>
			<div id="pmpro_sequence_posts">
			<?php
				$box = $this->getPostListForMetaBox();
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
		public function getPostListForMetaBox() {
			// global $wpdb;

			//show posts
			$this->getPosts();

	        $this->dbgOut('Displaying the back-end meta box content');

			ob_start();
			?>

			<?php // if(!empty($this->getError() )) { ?>
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
	            $this->dbgOut('No Posts found?');

				$this->setError( __('No posts/pages found for this sequence', 'pmprosequence') );
			?>
			<?php
			}
			else {
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
									$this->dbgOut('Error during database search for relevant posts');
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

			is_null( $this->getError() ) ?
				$this->dbgOut( "getPostListForMetaBox() - No error found, should return success" ) :
				$this->dbgOut( "getPostListForMetaBox() - Errors:" . $this->display_error() );

			return array(
				'success' => ( is_null( $this->getError() ) ? true : false ),
				'message' => ( is_null( $this->getError() ) ? null : ( is_array( $this->getError() ) ? join( ', ', $this->getError() ) : $this->getError() ) ),
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

            $this->dbgOut("settings_meta_box() - Post ID: {$post->ID} and Sequence ID: {$this->sequence_id}");

		    if ( ( ! isset( $this->sequence_id )  ) || ( $this->sequence_id != $post->ID ) ) {
                $this->dbgOut("settings_meta_box() - Loading sequence ID {$post->ID} in place of {$this->sequence_id}");
                $this->init( $post->ID );
            }

	        if ( $this->sequence_id != 0)
	        {
		        $this->dbgOut( "Loading sequence {$this->sequence_id} settings for Meta Box");
		        $this->fetchOptions($this->sequence_id);
	            // $settings = $this->fetchOptions($sequence_id);
	            // $this->dbgOut('Returned settings: ' . print_r($sequence->options, true));
	        }
	        else
	        {
	            $this->dbgOut('Not a valid Sequence ID, cannot load options');
                $this->setError( __('Invalid drip-feed sequence specified', 'pmprosequence') );
	            return;
	        }
	        // $this->dbgOut('settings_meta_box() - Loaded settings: ' . print_r($settings, true));

		    // Buffer the HTML so we can pick it up in a variable.
		    ob_start();

	        ?>
	        <div class="submitbox" id="pmpro_sequence_meta">
	            <div id="minor-publishing">
	            <input type="hidden" name="pmpro_sequence_settings_noncename" id="pmpro_sequence_settings_noncename" value="<?php echo wp_create_nonce( plugin_basename(__FILE__) )?>" />
	            <input type="hidden" name="pmpro_sequence_settings_hidden_delay" id="pmpro_sequence_settings_hidden_delay" value="<?php echo esc_attr($this->options->delayType); ?>"/>
	            <input type="hidden" name="hidden_pmpro_seq_wipesequence" id="hidden_pmpro_seq_wipesequence" value="0"/>
	            <table style="width: 100%;">
		            <tr>
	                    <td style="width: 20px;">
		                    <input type="checkbox" value="1" id="pmpro_sequence_hidden" name="pmpro_sequence_hidden" title="<?php _e('Hide unpublished / future posts for this sequence', 'pmprosequence'); ?>" <?php checked( $this->options->hidden, 1); ?> />
			                <input type="hidden" name="hidden_pmpro_seq_future" id="hidden_pmpro_seq_future" value="<?php echo esc_attr($this->options->hidden); ?>" >
	                    </td>
	                    <td style="width: 160px"><label class="selectit"><?php _e('Hide all future posts', 'pmprosequence'); ?></label></td>
	                </tr>
		            <tr>
			            <td>
				            <input type="checkbox" value="1" id="pmpro_sequence_offsetchk" name="pmpro_sequence_offsetchk" title="<?php _e('Let the user see a number of days worth of technically unavailable posts as a form of &quot;sneak-preview&quot;', 'pmprosequence'); ?>" <?php echo ( $this->options->previewOffset != 0 ? ' checked="checked"' : ''); ?> />
				            <input type="hidden" name="hidden_pmpro_seq_offset" id="hidden_pmpro_seq_offset" value="<?php echo esc_attr($this->options->previewOffset); ?>" >
			            </td>
			            <td><label class="selectit"><?php _e('Allow "sneak preview" of sequence', 'pmprosequence'); ?></label>
			            </td>
		            </tr>
		            <tr>
			            <td colspan="2">
				            <div class="pmpro-sequence-hidden pmpro-sequence-offset">
					            <label class="pmpro-sequence-label" for="pmpro-seq-offset"><?php _e('Days of prev:', 'pmprosequence'); ?> </label>
					            <span id="pmpro-seq-offset-status" class="pmpro-sequence-status"><?php echo ( $this->options->previewOffset == 0 ? 'None' : $this->options->previewOffset ); ?></span>
					            <a href="#" id="pmpro-seq-edit-offset" class="pmpro-seq-edit">
						            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
						            <span class="screen-reader-text"><?php _e('Change the number of days to preview', 'pmprosequence'); ?></span>
					            </a>
					            <div id="pmpro-seq-offset-select" class="pmpro-sequence-hidden">
						            <label for="pmpro_sequence_offset"></label>
						            <select name="pmpro_sequence_offset" id="pmpro_sequence_offset">
							            <option value="0">None</option>
							            <?php foreach (range(1, 5) as $previewOffset) { ?>
								            <option value="<?php echo esc_attr($previewOffset); ?>" <?php selected( intval($this->options->previewOffset), $previewOffset); ?> ><?php echo $previewOffset; ?></option>
							            <?php } ?>
						            </select>
						            <p class="pmpro-seq-btns">
							            <a href="#" id="ok-pmpro-seq-offset" class="save-pmproseq-offset button"><?php _e('OK', 'pmprosequence'); ?></a>
							            <a href="#" id="cancel-pmpro-seq-offset" class="cancel-pmproseq-offset button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
						            </p>
					            </div>
				            </div>
			            </td>
		            </tr>
		            <tr>
	                    <td>
		                    <input type="checkbox" value="1" id="pmpro_sequence_lengthvisible" name="pmpro_sequence_lengthvisible" title="<?php _e('Whether to show the &quot;You are on day NNN of your membership&quot; text', 'pmprosequence'); ?>" <?php checked( $this->options->lengthVisible, 1); ?> />
		                    <input type="hidden" name="hidden_pmpro_seq_lengthvisible" id="hidden_pmpro_seq_lengthvisible" value="<?php echo esc_attr($this->options->lengthVisible); ?>" >
	                    </td>
	                    <td><label class="selectit"><?php _e("Show user membership length", 'pmprosequence'); ?></label></td>
	                </tr>
		            <tr><td colspan="2"><hr/></td></tr>
	                <tr>
		                <td colspan="2">
			                <div class="pmpro-sequence-sortorder">
				                <label class="pmpro-sequence-label" for="pmpro-seq-sort"><?php _e('Sort order:', 'pmprosequence'); ?> </label>
				                <span id="pmpro-seq-sort-status" class="pmpro-sequence-status"><?php echo ( $this->options->sortOrder == SORT_ASC ? __('Ascending', 'pmprosequence') : __('Descending', 'pmprosequence') ); ?></span>
				                <a href="#" id="pmpro-seq-edit-sort" class="pmpro-seq-edit">
					                <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
					                <span class="screen-reader-text"><?php _e('Edit the list sort order', 'pmprosequence'); ?></span>
				                </a>
				                <div id="pmpro-seq-sort-select" class="pmpro-sequence-hidden">
					                <input type="hidden" name="hidden_pmpro_seq_sortorder" id="hidden_pmpro_seq_sortorder" value="<?php echo ($this->options->sortOrder == SORT_ASC ? SORT_ASC : SORT_DESC); ?>" >
					                <label for="pmpro_sequence_sortorder"></label>
					                <select name="pmpro_sequence_sortorder" id="pmpro_sequence_sortorder">
						                <option value="<?php echo esc_attr(SORT_ASC); ?>" <?php selected( intval($this->options->sortOrder), SORT_ASC); ?> > <?php _e('Ascending', 'pmprosequence'); ?></option>
						                <option value="<?php echo esc_attr(SORT_DESC); ?>" <?php selected( intval($this->options->sortOrder), SORT_DESC); ?> ><?php _e('Descending', 'pmprosequence'); ?></option>
					                </select>
					                <p class="pmpro-seq-btns">
						                <a href="#" id="ok-pmpro-seq-sort" class="save-pmproseq-sortorder button"><?php _e('OK', 'pmprosequence'); ?></a>
						                <a href="#" id="cancel-pmpro-seq-sort" class="cancel-pmproseq-sortorder button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
					                </p>
				                </div>
			                </div>
		                </td>
	                </tr>
	                <tr>
		                <td colspan="2">
			                <div class="pmpro-sequence-delaytype">
				                <label class="pmpro-sequence-label" for="pmpro-seq-delay"><?php _e('Delay type:', 'pmprosequence'); ?> </label>
				                <span id="pmpro-seq-delay-status" class="pmpro-sequence-status"><?php echo ($this->options->delayType == 'byDate' ? __('A date', 'pmprosequence') : __('Days after sign-up', 'pmprosequence') ); ?></span>
				                <a href="#" id="pmpro-seq-edit-delay" class="pmpro-seq-edit">
					                <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
					                <span class="screen-reader-text"><?php _e('Edit the delay type for this sequence', 'pmprosequence'); ?></span>
				                </a>
				                <div id="pmpro-seq-delay-select" class="pmpro-sequence-hidden">
	                                <input type="hidden" name="hidden_pmpro_seq_delaytype" id="hidden_pmpro_seq_delaytype" value="<?php echo ($this->options->delayType != '' ? esc_attr($this->options->delayType): 'byDays'); ?>" >
					                <label for="pmpro_sequence_delaytype"></label>
					                <select onchange="pmpro_sequence_delayTypeChange(<?php echo $this->sequence_id; ?>); return false;" name="pmpro_sequence_delaytype" id="pmpro_sequence_delaytype">
	                                    <option value="byDays" <?php selected( $this->options->delayType, 'byDays'); ?> ><?php _e('Days after sign-up', 'pmprosequence'); ?></option>
	                                    <option value="byDate" <?php selected( $this->options->delayType, 'byDate'); ?> ><?php _e('A date', 'pmprosequence'); ?></option>
	                                </select>
                                </div>
				                <div class="pmpro-sequence-hidden pmpro-seq-showdelayas" id="pmpro-seq-showdelayas">
					                <label class="pmpro-sequence-label" for="pmpro-seq-showdelayas"><?php _e("Show availability as:", 'pmprosequence'); ?></label>
					                <span id="pmpro-seq-showdelayas-status" class="pmpro-sequence-status"><?php echo ($this->options->showDelayAs == PMPRO_SEQ_AS_DATE ? __('Calendar date', 'pmprosequence') : __('Day of membership', 'pmprosequence') ); ?></span>
					                <a href="#" id="pmpro-seq-edit-showdelayas" class="pmpro-seq-edit">
						                <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
						                <span class="screen-reader-text"><?php _e('How to indicate when the post will be available to the user. Select either "Calendar date" or "day of membership")', 'pmprosequence'); ?></span>

					                </a>
				                </div>
				                <div id="pmpro-seq-showdelayas-select" class="pmpro-sequence-hidden pmpro-seq-select">
					                <!-- Only show this if 'hidden_pmpro_seq_delaytype' == 'byDays' -->
					                <input type="hidden" name="hidden_pmpro_seq_showdelayas" id="hidden_pmpro_seq_showdelayas" value="<?php echo ($this->options->showDelayAs == PMPRO_SEQ_AS_DATE ? PMPRO_SEQ_AS_DATE : PMPRO_SEQ_AS_DAYNO ); ?>" >
					                <label for="pmpro_sequence_showdelayas"></label>
					                <select name="pmpro_sequence_showdelayas" id="pmpro_sequence_showdelayas">
						                <option value="<?php echo PMPRO_SEQ_AS_DAYNO; ?>" <?php selected( $this->options->showDelayAs, PMPRO_SEQ_AS_DAYNO); ?> ><?php _e('Day of membership', 'pmprosequence'); ?></option>
						                <option value="<?php echo PMPRO_SEQ_AS_DATE; ?>" <?php selected( $this->options->showDelayAs, PMPRO_SEQ_AS_DATE); ?> ><?php _e('Calendar date', 'pmprosequence'); ?></option>
					                </select>
				                </div>
				                <div id="pmpro-seq-delay-btns" class="pmpro-sequence-hidden">
					                <p class="pmpro-seq-btns">
						                <a href="#" id="ok-pmpro-seq-delay" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
						                <a href="#" id="cancel-pmpro-seq-delay" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
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
					            <input type="checkbox" value="1" title="<?php _e('Whether to send an alert/notice to members when new content for this sequence is available to them', 'pmprosequence'); ?>" id="pmpro_sequence_sendnotice" name="pmpro_sequence_sendnotice" <?php checked($this->options->sendNotice, 1); ?> />
					            <input type="hidden" name="hidden_pmpro_seq_sendnotice" id="hidden_pmpro_seq_sendnotice" value="<?php echo esc_attr($this->options->sendNotice); ?>" >
					            <label class="selectit" for="pmpro_sequence_sendnotice"><?php _e('Send email alerts', 'pmprosequence'); ?></label>
					            <?php /* Add 'send now' button if checkbox is set */ ?>
					            <div class="pmpro-sequence-hidden pmpro-sequence-sendnowbtn">
						            <label for="pmpro_seq_send"><?php _e('Send alerts now', 'pmprosequence'); ?></label>
						            <a href="#" class="pmpro-seq-settings-send pmpro-seq-edit" id="pmpro_seq_send" onclick="pmpro_sequence_sendAlertNotice(<?php echo $this->sequence_id;?>); return false;">
						                <span aria-hidden="true"><?php _e('Send', 'pmprosequence'); ?></span>
						                <span class="screen-reader-text"><?php echo sprintf( __( 'Manually trigger sending of alert notices for the %s sequence', 'pmprosequence'), get_the_title( $this->sequence_id) ); ?></span>
						            </a>
						            <?php wp_nonce_field('pmpro-sequence-sendalert', 'pmpro_sequence_sendalert_nonce'); ?>
                                    <div class="pmpro-sequence-hidden pmpro-sequence-email">
						            <p class="pmpro-seq-email-hl"><?php _e("From:", 'pmprosequence'); ?></p>
						            <div class="pmpro-sequence-replyto">
							            <label class="pmpro-sequence-label" for="pmpro-seq-replyto"><?php _e('Email:', 'pmprosequence'); ?> </label>
							            <span id="pmpro-seq-replyto-status" class="pmpro-sequence-status"><?php echo ( $this->options->replyto != '' ? esc_attr($this->options->replyto) : pmpro_getOption("from_email") ); ?></span>
							            <a href="#" id="pmpro-seq-edit-replyto" class="pmpro-seq-edit">
								            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
								            <span class="screen-reader-text"><?php _e('Enter the email address to use as the sender of the alert', 'pmprosequence'); ?></span>
							            </a>
						            </div>
						            <div class="pmpro-sequence-fromname">
							            <label for="pmpro-seq-fromname"><?php _e('Name:', 'pmprosequence'); ?> </label>
							            <span id="pmpro-seq-fromname-status" class="pmpro-sequence-status"><?php echo ($this->options->fromname != '' ? esc_attr($this->options->fromname) : pmpro_getOption("from_name") ); ?></span>
							            <a href="#" id="pmpro-seq-edit-fromname" class="pmpro-seq-edit">
								            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
								            <span class="screen-reader-text"><?php _e('Enter the name to use for the sender of the alert', 'pmprosequence'); ?></span>
							            </a>
						            </div>
						            <div id="pmpro-seq-email-input" class="pmpro-sequence-hidden">
							            <input type="hidden" name="hidden_pmpro_seq_replyto" id="hidden_pmpro_seq_replyto" value="<?php echo ($this->options->replyto != '' ? esc_attr($this->options->replyto) : pmpro_getOption("from_email") ); ?>" />
							            <label for="pmpro_sequence_replyto"></label>
							            <input type="text" name="pmpro_sequence_replyto" id="pmpro_sequence_replyto" value="<?php echo ($this->options->replyto != '' ? esc_attr($this->options->replyto) : pmpro_getOption("from_email")); ?>"/>
							            <input type="hidden" name="hidden_pmpro_seq_fromname" id="hidden_pmpro_seq_fromname" value="<?php echo ($this->options->fromname != '' ? esc_attr($this->options->fromname) : pmpro_getOption("from_name")); ?>" />
							            <label for="pmpro_sequence_fromname"></label>
							            <input type="text" name="pmpro_sequence_fromname" id="pmpro_sequence_fromname" value="<?php echo ($this->options->fromname != '' ? esc_attr($this->options->fromname) : pmpro_getOption("from_name") ); ?>"/>
							            <p class="pmpro-seq-btns">
								            <a href="#" id="ok-pmpro-seq-email" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
								            <a href="#" id="cancel-pmpro-seq-email" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
							            </p>
						            </div>
					            </div>
                                    <div class="pmpro-sequence-hidden pmpro-sequence-sendas">
                                        <hr width="60%"/>
                                        <label class="pmpro-sequence-label" for="pmpro-seq-sendas"><?php _e('Send as:', 'pmprosequence'); ?> </label>
                                        <span id="pmpro-seq-sendas-status" class="pmpro-sequence-status"><?php echo ( $this->options->noticeSendAs = 10 ? _e('One alert per post', 'pmprosequence') : _e('List of post links', 'pmprosequence')); ?></span>
                                        <a href="#" id="pmpro-seq-edit-sendas" class="pmpro-seq-edit">
                                            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
                                            <span class="screen-reader-text"><?php _e('Select the format of the alert notice when posting new content for this sequence', 'pmprosequence'); ?></span>
                                        </a>
                                        <div id="pmpro-seq-sendas-select" class="pmpro-sequence-hidden">
                                            <input type="hidden" name="hidden_pmpro_seq_sendas" id="hidden_pmpro_seq_sendas" value="<?php echo esc_attr($this->options->noticeSendAs); ?>" >
                                            <label for="pmpro_sequence_sendas"></label>
                                            <select name="pmpro_sequence_sendas" id="pmpro_sequence_sendas">
                                                <option value="<?php echo PMPRO_SEQ_SEND_AS_SINGLE; ?>" <?php selected( $this->options->noticeSendAs, PMPRO_SEQ_SEND_AS_SINGLE ); ?> ><?php _e('One alert per post', 'pmprosequence'); ?></option>
                                                <option value="<?php echo PMPRO_SEQ_SEND_AS_LIST; ?>" <?php selected( $this->options->noticeSendAs, PMPRO_SEQ_SEND_AS_LIST ); ?> ><?php _e('List of post links', 'pmprosequence'); ?></option>
                                            </select>
                                            <p class="pmpro-seq-btns">
                                                <a href="#" id="ok-pmpro-seq-sendas" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
                                                <a href="#" id="cancel-pmpro-seq-sendas" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
                                            </p>
                                        </div>
                                    </div>

					            <div class="pmpro-sequence-hidden pmpro-sequence-template">
						            <label class="pmpro-sequence-label" for="pmpro-seq-template"><?php _e('Template:', 'pmprosequence'); ?> </label>
						            <span id="pmpro-seq-template-status" class="pmpro-sequence-status"><?php echo esc_attr( $this->options->noticeTemplate ); ?></span>
						            <a href="#" id="pmpro-seq-edit-template" class="pmpro-seq-edit">
							            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
							            <span class="screen-reader-text"><?php _e('Select the template to use when posting new content in this sequence', 'pmprosequence'); ?></span>
						            </a>
						            <div id="pmpro-seq-template-select" class="pmpro-sequence-hidden">
							            <input type="hidden" name="hidden_pmpro_seq_noticetemplate" id="hidden_pmpro_seq_noticetemplate" value="<?php echo esc_attr($this->options->noticeTemplate); ?>" >
							            <label for="pmpro_sequence_template"></label>
							            <select name="pmpro_sequence_template" id="pmpro_sequence_template">
								            <?php echo $this->listEmailTemplates(); ?>
							            </select>
							            <p class="pmpro-seq-btns">
								            <a href="#" id="ok-pmpro-seq-template" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
								            <a href="#" id="cancel-pmpro-seq-template" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
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
					            <span id="pmpro-seq-noticetime-status" class="pmpro-sequence-status"><?php echo esc_attr($this->options->noticeTime); ?></span>
					            <a href="#" id="pmpro-seq-edit-noticetime" class="pmpro-seq-edit">
						            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
						            <span class="screen-reader-text"><?php _e('Select when (tomorrow) to send new content posted alerts for this sequence', 'pmprosequence'); ?></span>
					            </a>
					            <div id="pmpro-seq-noticetime-select" class="pmpro-sequence-hidden">
						            <input type="hidden" name="hidden_pmpro_seq_noticetime" id="hidden_pmpro_seq_noticetime" value="<?php echo esc_attr($this->options->noticeTime); ?>" >
						            <label for="pmpro_sequence_noticetime"></label>
						            <select name="pmpro_sequence_noticetime" id="pmpro_sequence_noticetime">
						                <?php echo $this->createTimeOpts(); ?>
						            </select>
						            <p class="pmpro-seq-btns">
							            <a href="#" id="ok-pmpro-seq-noticetime" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
							            <a href="#" id="cancel-pmpro-seq-noticetime" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
						            </p>
					            </div>
					            <div>
						            <label class="pmpro-sequence-label" for="pmpro-seq-noticetime"><?php _e('Timezone:', 'pmprosequence'); ?> </label>
						            <span class="pmpro-sequence-status" id="pmpro-seq-noticetimetz-status"><?php echo '  ' . get_option('timezone_string'); ?></span>
					            </div>
					            <div class="pmpro-sequence-subject">
						            <label for="pmpro-seq-subject"><?php _e('Subject:', 'pmprosequence'); ?> </label>
						            <span id="pmpro-seq-subject-status" class="pmpro-sequence-status">"<?php echo ( $this->options->subject != '' ? esc_attr($this->options->subject) : __('New Content', 'pmprosequence') ); ?>"</span>
						            <a href="#" id="pmpro-seq-edit-subject" class="pmpro-seq-edit">
							            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
							            <span class="screen-reader-text"><?php _e('Update/Edit the Prefix for the subject of the new conent alert', 'pmprosequence'); ?></span>
						            </a>
						            <div id="pmpro-seq-subject-input" class="pmpro-sequence-hidden">
							            <input type="hidden" name="hidden_pmpro_seq_subject" id="hidden_pmpro_seq_subject" value="<?php echo ( $this->options->subject != '' ? esc_attr($this->options->subject) : __('New Content', 'pmprosequence') ); ?>" />
							            <label for="pmpro_sequence_subject"></label>
							            <input type="text" name="pmpro_sequence_subject" id="pmpro_sequence_subject" value="<?php echo ( $this->options->subject != '' ? esc_attr($this->options->subject) : __('New Content', 'pmprosequence') ); ?>"/>
							            <p class="pmpro-seq-btns">
								            <a href="#" id="ok-pmpro-seq-subject" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
								            <a href="#" id="cancel-pmpro-seq-subject" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
							            </p>
						            </div>
					            </div>
					            <div class="pmpro-sequence-excerpt">
						            <label class="pmpro-sequence-label" for="pmpro-seq-excerpt"><?php _e('Intro:', 'pmprosequence'); ?> </label>
						            <span id="pmpro-seq-excerpt-status" class="pmpro-sequence-status">"<?php echo ( $this->options->excerpt_intro != '' ? esc_attr($this->options->excerpt_intro) : __('A summary for the new content follows:', 'pmprosequence') ); ?>"</span>
						            <a href="#" id="pmpro-seq-edit-excerpt" class="pmpro-seq-edit">
							            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
							            <span class="screen-reader-text"><?php _e('Update/Edit the introductory paragraph for the new content excerpt', 'pmprosequence'); ?></span>
						            </a>
						            <div id="pmpro-seq-excerpt-input" class="pmpro-sequence-hidden">
							            <input type="hidden" name="hidden_pmpro_seq_excerpt" id="hidden_pmpro_seq_excerpt" value="<?php echo ($this->options->excerpt_intro != '' ? esc_attr($this->options->excerpt_intro) : __('A summary for the new content follows:', 'pmprosequence') ); ?>" />
							            <label for="pmpro_sequence_excerpt"></label>
							            <input type="text" name="pmpro_sequence_excerpt" id="pmpro_sequence_excerpt" value="<?php echo ($this->options->excerpt_intro != '' ? esc_attr($this->options->excerpt_intro) : __('A summary for the new content follows:', 'pmprosequence') ); ?>"/>
							            <p class="pmpro-seq-btns">
								            <a href="#" id="ok-pmpro-seq-excerpt" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
								            <a href="#" id="cancel-pmpro-seq-excerpt" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
							            </p>
						            </div>
					            </div>
					            <div class="pmpro-sequence-hidden pmpro-sequence-dateformat">
						            <label class="pmpro-sequence-label" for="pmpro-seq-dateformat"><?php _e('Date type:', 'pmprosequence'); ?> </label>
						            <span id="pmpro-seq-dateformat-status" class="pmpro-sequence-status">"<?php echo ( trim($this->options->dateformat) == false ? __('m-d-Y', 'pmprosequence') : esc_attr($this->options->dateformat) ); ?>"</span>
						            <a href="#" id="pmpro-seq-edit-dateformat" class="pmpro-seq-edit">
							            <span aria-hidden="true"><?php _e('Edit', 'pmprosequence'); ?></span>
							            <span class="screen-reader-text"><?php _e('Update/Edit the format of the !!today!! placeholder (a valid PHP date() format)', 'pmprosequence'); ?></span>
						            </a>
						            <div id="pmpro-seq-dateformat-select" class="pmpro-sequence-hidden">
							            <input type="hidden" name="hidden_pmpro_seq_dateformat" id="hidden_pmpro_seq_dateformat" value="<?php echo ( trim($this->options->dateformat) == false ? __('m-d-Y', 'pmprosequence') : esc_attr($this->options->dateformat) ); ?>" />
							            <label for="pmpro_pmpro_sequence_dateformat"></label>
							            <select name="pmpro_sequence_dateformat" id="pmpro_sequence_dateformat">
								            <?php echo $this->listDateFormats(); ?>
							            </select>
							            <p class="pmpro-seq-btns">
								            <a href="#" id="ok-pmpro-seq-dateformat" class="save-pmproseq button"><?php _e('OK', 'pmprosequence'); ?></a>
								            <a href="#" id="cancel-pmpro-seq-dateformat" class="cancel-pmproseq button-cancel"><?php _e('Cancel', 'pmprosequence'); ?></a>
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

	                        <a class="button button-primary button-large" class="pmpro-seq-settings-save" id="pmpro_settings_save" onclick="pmpro_sequence_saveSettings(<?php echo $this->sequence_id;?>) ; return false;"><?php _e('Update Settings', 'pmprosequence'); ?></a>
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
	                            <option value="0" <?php //selected( intval($this->options->previewWindow), '0'); ?> >All</option>
	                            <option value="1" <?php //selected( intval($this->options->previewWindow), '1'); ?> >1 day</option>
	                            <option value="2" <?php //selected( intval($this->options->previewWindow), '2'); ?> >2 days</option>
	                            <option value="3" <?php //selected( intval($this->options->previewWindow), '3'); ?> >3 days</option>
	                            <option value="4" <?php //selected( intval($this->options->previewWindow), '4'); ?> >4 days</option>
	                            <option value="5" <?php //selected( intval($this->options->previewWindow), '5'); ?> >5 days</option>
	                            <option value="6" <?php //selected( intval($this->options->previewWindow), '6'); ?> >1 week</option>
	                            <option value="7" <?php //selected( intval($this->options->previewWindow), '7'); ?> >2 weeks</option>
	                            <option value="8" <?php //selected( intval($this->options->previewWindow), '8'); ?> >3 weeks</option>
	                            <option value="9" <?php //selected( intval($this->options->previewWindow), '8'); ?> >1 month</option>
	                        </select>
	                    </td>
	                </tr>
	                -->
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
	               -->
	            </table>
	            </div>
	        </div>
		<?php
		    $metabox = ob_get_clean();

		    $this->dbgOut('settings_meta_box() - Display the settings meta.');
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

            if ( ( $post->post_type == "pmpro_sequence" ) && pmpro_has_membership_access() )
            {
                global $load_pmpro_sequence_script;

                $load_pmpro_sequence_script = true;

                $this->dbgOut( "PMPRO Sequence display {$post->ID} - " . get_the_title( $post->ID ) . " : " . $this->whoCalledMe() . ' and page base: ' . $pagenow );

                $this->init( $post->ID );

                // If we're supposed to show the "days of membership" information, adjust the text for type of delay.
                if ( intval($this->options->lengthVisible) == 1 ) {

                    $content .= sprintf("<p>%s</p>", sprintf(__("You are on day %s of your membership", "pmprosequence"), $this->getMemberDays()));
                }

                // Add the list of posts in the sequence to the content.
                $content .= $this->getPostList();
            }

            return $content;
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


            if ( ! empty( $current_user ) && ( ! empty( $post ) ) ) {

                // $this->dbgOut(" text_filter() - Current sequence ID: {$this->sequence_id}" );
                // $this->init( $post->ID );

                if ( ! $this->hasAccess( $current_user->ID, $post->ID ) ) {

                    $post_sequences = get_post_meta($post->ID, "_post_sequences", true);

                    //Update text. The user either will have to wait or sign up.
                    $insequence = false;

                    foreach ( $post_sequences as $ps ) {

                        if ( pmpro_has_membership_access( $ps ) ) {

                            $this->dbgOut("User may have access to: {$ps} ");
                            $insequence = $ps;
                            $this->init( $ps );
                            $delay = $this->getDelayForPost($post->ID);
                            break;
                        }
                    }

                    if ( $insequence !== false ) {

                        //user has one of the sequence levels, find out which one and tell him how many days left
                        $text = sprintf("%s<br/>", sprintf( __("This content managed as part of the members only <a href='%s'>%s</a> sequence", 'pmprosequence'), get_permalink($ps), get_the_title($ps)) );

                        switch ( $this->options->delayType ) {

                            case 'byDays':

                                switch ( $this->options->showDelayAs ) {

                                    case PMPRO_SEQ_AS_DAYNO:

                                        $text .= sprintf( __( 'You will get access to this content ("%s") on day %s of your membership', 'pmprosequence' ), get_the_title( $post->ID ), $this->displayDelay( $delay ) );
                                        break;

                                    case PMPRO_SEQ_AS_DATE:

                                        $text .= sprintf( __( 'You will get access to this content ("%s") on %s', 'pmprosequence' ), get_the_title( $post->ID ), $this->displayDelay( $delay ) );
                                        break;
                                }

                                break;

                            case 'byDate':
                                $text .= sprintf( __('You will get access to this content ("%s") on %s', 'pmprosequence'), get_the_title($post->ID), $delay );
                                break;

                            default:

                        }

                    }
                    else
                    {
                        // User has to sign up for one of the sequence(s)
                        if ( count( $post_sequences ) == 1 ) {

                            $text = sprintf("%s<br/>", sprintf( __( "This content is part of the members only <a href='%s'>%s</a> sequence", 'pmprosequence' ), get_permalink( $post_sequences[0] ), get_the_title( $post_sequences[0] ) ) );
                        }
                        else {

                            $text = sprintf( "<p>%s</p>", __( 'This content is part of the following members only sequences: ', 'pmprosequence' ) );
                            $seq_links = array();

                            foreach ( $post_sequences as $sequence_id ) {

                                $seq_links[] = "<p><a href='" . get_permalink( $sequence_id ) . "'>" . get_the_title( $sequence_id ) . "</a></p>";
                            }

                            $text .= implode( $seq_links );
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
        public function getPostList($echo = false) {

            $this->dbgOut("getPostList() - Post List for sequence #: {$this->sequence_id}");

            //global $current_user;
            $this->getPosts();

            if ( ! empty( $this->posts ) ) {

                // TODO: Have upcoming posts be listed before or after the currently active posts (own section?) - based on sort setting

                $content = $this->createSequenceList( true, 25, true, null, false	);

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
        public function createSequenceList( $highlight = false, $pagesize = 0, $button = false, $title = null, $scrollbox = false ) {

            global $wpdb, $current_user, $id, $post;
            $html = '';

            $savePost = $post;

            // Set a default page size.
            if ($pagesize == 0) {
                $pagesize = 15;
            }

            if ( empty( $this->posts ) ) {
                $this->dbgOut( "createSequenceList() - Loading posts - it's empty in here!" );
                $this->getPosts();
            }

            $sequence_posts = $this->posts;
            $memberDayCount = $this->getMemberDays();

            $this->dbgOut( "Sequence {$this->sequence_id} has " . count( $sequence_posts ) . " posts. Current user has been a member for {$memberDayCount} days" );

            if ( ! $this->hasAccess( $current_user->ID, $this->sequence_id ) ) {
                $this->dbgOut( 'No access to sequence ' . $this->sequence_id . ' for user ' . $current_user->ID );
                return '';
            }

            /* Get the ID of the post in the sequence who's delay is the closest
             *  to the members 'days since start of membership'
             */
            $closestPostId = apply_filters( 'pmpro-sequence-found-closest-post', $this->get_closestPost( $current_user->ID ) );

            // Image to bring attention to the closest post item
            $closestPostImg = '<img src="' . plugins_url( '/../images/most-recent.png', __FILE__ ) . '" >';

            $this->dbgOut( 'createSequenceList() - The most recently available post for user #' . $current_user->ID . ' is post #' . $closestPostId );

            $post_list = array();

            // Generate a list of posts for the sequence (used in WP_Query object)
            foreach ( $this->posts as $post ) {

                if ( $this->hasAccess( $current_user->ID, $post->id ) ) {
                    $post_list[] = $post->id;
                }
                elseif ( ! $this->hideUpcomingPosts() ) {
                    $this->dbgOut( "createSequenceList() - We're supposed to show hidden posts, so adding # {$post->id}" );
                    $post_list[] = $post->id;
                }
            }

            $query_args = array(
                'post_type'           => apply_filters( 'pmpro_sequencepost_types', array( 'post', 'page' ) ),
                // Filter returns an array()
                'post__in'            => ( empty( $post_list ) ? array( 0 ) : $post_list ),
                'ignore_sticky_posts' => 1,
                'paged'               => ( get_query_var( 'page' ) ) ? absint( get_query_var( 'page' ) ) : 1,
                'posts_per_page'      => $pagesize,
                'orderby'             => 'post__in'
            );

            $seqList = new WP_Query( apply_filters( 'pmpro-sequence-list-sequences-wpquery', $query_args ) );

            $listed_postCnt   = 0;
            // $noPostsDisplayed = true;
            $this->dbgOut( "createSequenceList() - Loading posts for the sequence_list shortcode...");
            ob_start();
            ?>

            <!-- Preface the table of links with the title of the sequence -->
            <div id="pmpro_sequence-<?php echo $this->sequence_id; ?>" class="pmpro_sequence_list">

            <?php echo apply_filters( 'pmpro-sequence-list-title',  $this->setShortcodeTitle( $title ) ); ?>

            <!-- Add opt-in to the top of the shortcode display. -->
            <?php echo $this->addUserNoticeOptIn(); ?>

            <!-- List of sequence entries (paginated as needed) -->
            <?php
            if ( $seqList->post_count == 0 ) {

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
                while ( $seqList->have_posts() ) : $seqList->the_post();

                    // Should the current post be highlighted?
                    if ( ( $this->isPastDelay( $memberDayCount, $this->posts[ $this->getPostKey( $id ) ]->delay ) ) ) {

                        $listed_postCnt++;

                        if ( ( $id == $closestPostId ) && ( $highlight ) ) {
                            // Show the highlighted post info
                            ?>
                            <tr id="pmpro-seq-selected-post">
                                <td class="pmpro-seq-post-img"><?php echo apply_filters( 'pmpro-sequence-closest-post-indicator-image', $closestPostImg ); ?></td>
                                <td class="pmpro-seq-post-hl">
                                    <a href="<?php the_permalink(); ?>" title="<?php the_title(); ?>"><strong><?php the_title(); ?></strong>&nbsp;&nbsp;<em>(Current)</em></a>
                                </td>
                                <td <?php echo( $button ? 'class="pmpro-seq-availnow-btn"' : '' ); ?>><?php

                                    if ( $button ) {
                                        ?>
                                    <a class="pmpro_btn pmpro_btn-primary" href="<?php echo get_permalink(); ?>"> <?php _e( "Available Now", 'pmprosequence' ); ?></a><?php
                                    } ?>
                                </td>
                            </tr> <?php
                        } else {
                            ?>
                            <tr id="pmpro-seq-post">
                                <td class="pmpro-seq-post-img">&nbsp;</td>
                                <td class="pmpro-seq-post-fade">
                                    <a href="<?php the_permalink(); ?>" title="<?php the_title(); ?>"><?php the_title(); ?></a>
                                </td>
                                <td<?php echo( $button ? ' class="pmpro-seq-availnow-btn">' : '>' );
                                if ( $button ) {
                                    ?>
                                <a class="pmpro_btn pmpro_btn-primary" href="<?php echo get_permalink(); ?>"> <?php _e( "Available Now", 'pmprosequence' ); ?></a><?php
                                } ?>
                                </td>
                            </tr>
                        <?php
                        }
                    } elseif ( ( ! $this->isPastDelay( $memberDayCount, $this->posts[ $this->getPostKey( $id ) ]->delay ) ) &&
                        ( $this->hideUpcomingPosts() === false ) ) {

                        $listed_postCnt++;

                        // Do we need to highlight the (not yet available) post?
                        if ( ( $id == $closestPostId ) && ( $highlight ) ) {
                            ?>

                            <tr id="pmpro-seq-post">
                                <td class="pmpro-seq-post-img">&nbsp;</td>
                                <td id="pmpro-seq-post-future-hl">
                                    <?php $this->dbgOut( "Highlight post #: {$id} with future availability" ); ?>
                                    <span class="pmpro_sequence_item-title">
                                            <?php echo get_the_title(); ?>
                                        </span>
                                        <span class="pmpro_sequence_item-unavailable">
                                            <?php echo sprintf( __( 'available on %s', 'pmprosequence' ),
                                                ( $this->options->delayType == 'byDays' &&
                                                    $this->options->showDelayAs == PMPRO_SEQ_AS_DAYNO ) ?
                                                    __( 'day', 'pmprosequence' ) : '' ); ?>
                                            <?php echo $this->displayDelay( $this->posts[ $this->getPostKey( $id ) ]->delay ); ?>
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
                                    <span class="pmpro_sequence_item-title"><?php echo get_the_title(); ?></span>
                                        <span class="pmpro_sequence_item-unavailable">
                                            <?php echo sprintf( __( 'available on %s', 'pmprosequence' ),
                                                ( $this->options->delayType == 'byDays' &&
                                                    $this->options->showDelayAs == PMPRO_SEQ_AS_DAYNO ) ?
                                                    __( 'day', 'pmprosequence' ) : '' ); ?>
                                            <?php echo $this->displayDelay( $this->posts[ $this->getPostKey( $id ) ]->delay ); ?>
                                        </span>
                                </td>
                                <td></td>
                            </tr> <?php
                        }
                    } else {
                        if ( ( count( $post_list ) > 0 ) && ( $listed_postCnt > 0 ) ) {
                            ?>
                            <tr id="pmpro-seq-post">
                                <td>
                                    <span style="text-align: center;">There is <em>no content available</em> for you at this time. Please check back later.</span>
                                </td>
                            </tr><?php
                        }
                    }
                endwhile;

                ?></table>
                </div>
                <div class="clear"></div>
                <?php
                echo apply_filters( 'pmpro-sequence-list-pagination-code', $this->post_paging_nav( $seqList->max_num_pages ) );
                wp_reset_postdata();
            }
            ?>
            </div><?php

            $post = $savePost;

            $html .= ob_get_contents();
            ob_end_clean();

            $this->dbgOut("createSequenceList() - Returning the - possibly filtered - HTML for the sequence_list shortcode");

            return apply_filters( 'pmpro-sequence-list-html', $html );

        }

        /**
         * Adds notification opt-in to list of posts/pages in sequence.
         *
         * @return string -- The HTML containing a form (if the sequence is configured to let users receive notices)
         *
         * @access public
         */
        public function addUserNoticeOptIn( ) {

            $optinForm = '';

            global $current_user, $wpdb;

            $meta_key = $wpdb->prefix . 'pmpro_sequence_notices';

            $this->dbgOut('addUserNoticeOptIn() - User specific opt-in to sequence display for new content notices for user ' . $current_user->ID);

            if ($this->options->sendNotice == 1) {

                $optIn = get_user_meta( $current_user->ID, $meta_key, true );

                // $this->dbgOut('addUserNoticeOptIn() - Fetched Meta: ' . print_r( $optIn, true));

                /* Determine the state of the users opt-in for new content notices */
                if ( empty($optIn->sequence ) || empty( $optIn->sequence[$this->sequence_id] ) ) {

                    $this->dbgOut('addUserNoticeOptIn() - No user specific settings found in general or for this sequence. Creating defaults');

                    // Create new opt-in settings for this user
                    if ( empty($optIn->sequence) ) {

                        $new = new stdClass();
                    }
                    else { // Saves existing settings

                        $new = $optIn;
                    }

                    $new->sequence[$this->sequence_id]->sendNotice = $this->options->sendNotice;

                    $this->dbgOut('addUserNoticeOptIn() - Using default setting for user ' . $current_user->ID . ' and sequence ' . $this->sequence_id);

                    $optIn = $new;
                }

                if ( empty( $optIn->sequence[$this->sequence_id]->notifiedPosts ) )
                    $optIn->sequence[$this->sequence_id]->notifiedPosts = array();

                update_user_meta($current_user->ID, $meta_key, $optIn);

                /* Add form information */
                ob_start();
                ?>
                <div class="pmpro-seq-centered">
                    <div class="pmpro-sequence-hidden pmpro_sequence_useroptin">
                        <div class="seq_spinner"></div>
                        <form class="pmpro-sequence" action="<?php echo admin_url('admin-ajax.php'); ?>" method="post">
                            <input type="hidden" name="hidden_pmpro_seq_useroptin" id="hidden_pmpro_seq_useroptin" value="<?php echo $optIn->sequence[$this->sequence_id]->sendNotice; ?>" >
                            <input type="hidden" name="hidden_pmpro_seq_id" id="hidden_pmpro_seq_id" value="<?php echo $this->sequence_id; ?>" >
                            <input type="hidden" name="hidden_pmpro_seq_uid" id="hidden_pmpro_seq_uid" value="<?php echo $current_user->ID; ?>" >
                            <?php wp_nonce_field('pmpro-sequence-user-optin', 'pmpro_sequence_optin_nonce'); ?>
                            <span>
                                <input type="checkbox" value="1" id="pmpro_sequence_useroptin" name="pmpro_sequence_useroptin" onclick="javascript:pmpro_sequence_optinSelect(); return false;" title="<?php _e('Please email me an alert when any new content in this sequence becomes available', 'pmprosequence'); ?>" <?php echo ($optIn->sequence[$this->sequence_id]->sendNotice == 1 ? ' checked="checked"' : ''); ?> " />
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
        public function hideUpcomingPosts()
        {
            // $this->dbgOut('hideUpcomingPosts(): Do we show or hide upcoming posts?');
            return ( $this->options->hidden == 1 ? true : false );
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

            if ( $msg !== null ) {

                $this->dbgOut("setError(): {$msg}");
                add_settings_error( 'pmpro_seq_errors', '', $msg, 'error' );
            }
            else{

            }
        }

        /**
         * Display the error message (if it's defined).
         */
        public function display_error() {

            $this->dbgOut("Display error messages, if there are any");
            global $current_screen;

            $msg = $this->getError();

            if ( ! empty( $msg ) ){
                $this->dbgOut("Display error for Drip Feed operation(s)");
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
        private function listDateFormats() {

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
        private function createTimeOpts( )
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
        private function listEmailTemplates()
        {
            ob_start();

            ?>
            <!-- Default template (blank) -->
            <option value=""></option>
            <?php

            // $this->dbgOut('Directory containing templates: ' . PMPRO_SEQUENCE_PLUGIN_DIR . "/email/" );

            $templ_dir = apply_filters( 'pmpro-sequence-email-alert-template-path', PMPRO_SEQUENCE_PLUGIN_DIR . "/email/" );

            $this->dbgOut( "Directory containing templates: {$templ_dir}");

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
                    <h4 class="screen-reader-text"><?php _e( 'Link Navigation', 'pmprosequence' ); ?></h4>
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
        private function setShortcodeTitle( $title = null ) {

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
	    public function isPastDelay( $memberFor, $delay )
	    {
		    // Get the preview offset (if it's defined). If not, set it to 0
		    // for compatibility
		    if ( ! isset( $this->options->previewOffset ) ) {

			    $this->dbgOut("isPastDelay() - the previewOffset value doesn't exist yet {$this->options->previewOffset}. Fixing now.");
			    $this->options->previewOffset = 0;
			    $this->save_sequence_meta(); // Save the settings (only the first when this variable is empty)

		    }

	        $offset = $this->options->previewOffset;
		    // $this->dbgOut('Preview enabled and set to: ' . $offset);

		    if ($this->isValidDate($delay))
	        {
                // Fixed: Now supports DST changes (i.e. no "early or late availability" in DST timezones
	            // $now = current_time('timestamp') + ($offset * 86400);
                $now = $this->calculateNowPlusOffsetSecs( $offset );

	            // TODO: Add support for startWhen options (once the plugin supports differentiating on when the drip starts)
	            $delayTime = strtotime( $delay . ' 00:00:00.0 ' . get_option( 'timezone_string' ) );
	            // $this->dbgOut('isPastDelay() - Now = ' . $now . ' and delay time = ' . $delayTime );

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
        public function validatePOSTDelay( $delay ) {

            $delay = ( is_numeric( $delay ) ? intval( $delay ) : esc_attr( $delay ) );

            if ( ($delay !== 0) && ( ! empty( $delay ) ) ) {

                // Check that the provided delay format matches the configured value.
                if ( $this->isValidDelay( $delay ) ) {

                    $this->dbgOut( 'validatePOSTDelay(): Delay value is recognizable' );

                    if ( $this->isValidDate( $delay ) ) {

                        $this->dbgOut( 'validatePOSTDelay(): Delay specified as a valid date format' );

                    } else {

                        $this->dbgOut( 'validatePOSTDelay(): Delay specified as the number of days' );
                    }
                } else {
                    // Ignore this post & return error message to display for the user/admin
                    // NOTE: Format of date is not translatable
                    $expectedDelay = ( $this->options->delayType == 'byDate' ) ? __( 'date: YYYY-MM-DD', 'pmprosequence' ) : __( 'number: Days since membership started', 'pmprosequence' );

                    $this->dbgOut( 'validatePOSTDelay(): Invalid delay value specified, not adding the post. Delay is: ' . $delay );
                    $this->setError( sprintf( __( 'Invalid delay specified ( %1$s ). Expected format is a %2$s', 'pmprosequence' ), $delay, $expectedDelay ) );

                    $delay = false;
                }
            } elseif ($delay === 0) {

                // Special case:
                return $delay;

            } else {

                $this->dbgOut( 'validatePOSTDelay(): Delay value was not specified. Not adding the post. Delay is: ' . esc_attr( $delay ) );

                if ( empty( $delay ) ) {

                    $this->setError( __( 'No delay has been specified', 'pmprosequence' ) );
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
        public function displayDelay( $delay ) {

            if ( $this->options->showDelayAs == PMPRO_SEQ_AS_DATE) {
                // Convert the delay to a date

                $memberDays = round(pmpro_getMemberDays(), 0);

                $delayDiff = ($delay - $memberDays);
	            $this->dbgOut('displayDelay() - Delay: ' .$delay . ', memberDays: ' . $memberDays . ', delayDiff: ' . $delayDiff);

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
        public function get_closestPost( $user_id = null ) {

	        // Get the current day of the membership (as a whole day, not a float)
            $membershipDay =  $this->getMemberDays( $user_id );

            // Load all posts in this sequence
            $this->getPosts();

            $this->dbgOut("get_closestPost() - Found " . count($this->posts) . ".");

            // Find the post ID in the postList array that has the delay closest to the membershipday.
            $closest = $this->get_closestByDelay( $membershipDay, $this->posts, $user_id );

            if ( isset($closest->id) ) {

                $this->dbgOut("get_closestPost() - For user {$user_id} on day {$membershipDay}, the closest post is #{$closest->id} (with a delay value of {$closest->delay})");
                return $closest->id;
            }

			return 0;
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
        public function getDelayForPost($post_id, $normalize = true ) {

			$key = $this->getPostKey($post_id);

			if($key === false)
	        {
	            $this->dbgOut('No key found in getDelayForPost');
				return false;
	        }
	        else {
                // BUG: Would return "days since membership start" as the delay value, regardless of setting.
                // Fix: Choose whether to normalize (but leave default as "yes" to ensure no unintentional breakage).
                if ( $normalize ) {

                    $delay = $this->normalizeDelay( $this->posts[ $key ]->delay );
                }
                else {

                    $delay = $this->posts[ $key ]->delay;
                }
	            $this->dbgOut('getDelayForPost(): Delay for post with id = ' . $post_id . ' is ' .$delay);
	            return $delay;
	        }
		}

        /**
         * Convert any date string to a number of days worth of delay (since membership started for the current user)
         *
         * @param $delay (int | string) -- The delay value (either a # of days or a date YYYY-MM-DD)
         * @return mixed (int) -- The # of days since membership started (for this user)
         *
         * @access public
         */
        public function normalizeDelay( $delay ) {

            if ( $this->isValidDate($delay) ) {

                return $this->convertToDays($delay);
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
        public function convertToDays( $date, $userId = null, $levelId = null ) {

            $days = 0;

            // Return immediately if the value we're given is a # of days (i.e. an integer)
            if ( is_numeric( $date ) ) {
                $days = $date;
            }

            if ( $this->isValidDate( $date ) )
            {
                $startDate = pmpro_getMemberStartdate( $userId, $levelId); /* Needs userID & Level ID ... */

                if (empty($startDate))
                    $startDate = 0;

                try {

                    // Use PHP v5.2 and v5.3 compatible function to calculate difference
                    $compDate = strtotime( $date );
                    $days = $this->seq_datediff( $startDate, $compDate ); // current_time('timestamp')

                } catch (Exception $e) {
                    $this->dbgOut('convertToDays() - Error calculating days: ' . $e->getMessage());
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
        public function getMemberDays( $user_id = NULL, $level_id = 0 ) {

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
        private function calculateNowPlusOffsetSecs( $days ) {

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

            $this->dbgOut("calculateOffsetSecs() - Offset Days: {$days} = When (in seconds): {$seconds}", DEBUG_SEQ_INFO );
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
         * @param $mypost (int) -- The post we're processing
         * @param $myuser (int) -- The user ID we're testing
         * @param $post_membership_levels -- The membership level(s) we're testing against
         *
         * @return bool -- True if access is granted, false if not
         */
        public function has_membership_access_filter($hasaccess, $mypost, $myuser, $post_membership_levels)
        {
            // If the user has been granted access already, we'll verify that they have access to the specific post
            if ( $hasaccess ) {
                // $this->dbgOut( "has_membership_access_filter() - (current access - 1 == true: {$hasaccess}) for {$myuser->ID} related to post {$mypost->ID} and sequence {$this->sequence_id}");
                //See if the user has access to the specific post
                return $this->hasAccess( $myuser->ID, $mypost->ID );
            }

            return $hasaccess;
        }

        /**
         * Check the whether the User ID has access to the post ID
         * Make sure people can't view content they don't have access to.
         *
         * @param $user_id (int) -- The users ID to check access for
         * @param $post_id (int) -- The ID of the post we're checking access for
         * @param $isAlert (bool) - If true, ignore any preview value settings when calculating access
         *
         * @return bool -- true | false -- Indicates user ID's access privileges to the post/sequence
         */
        public function hasAccess($user_id, $post_id, $isAlert = false)
        {
            // FixMe: Fix cases where user is member of one sequence but not another.

            // Does the current user have a membership level giving them access to everything?
            $all_access_levels = apply_filters("pmproap_all_access_levels", array(), $user_id, $post_id);

            if (!empty($all_access_levels) && pmpro_hasMembershipLevel($all_access_levels, $user_id)) {

                $this->dbgOut("hasAccess() - This user ({$user_id}) has one of the 'all access' membership levels");
                return true; //user has one of the all access levels
            }

            $post_sequence = get_post_meta( $post_id, "_post_sequences", true );

            if ( empty( $post_sequence ) ) {
                // $this->dbgOut( "hasAccess() with empty post_sequence: " . $this->whoCalledMe() );
                // $this->dbgOut( "hasAccess() - No sequences manage this post {$post_id} for user {$user_id} so granting access (seq: {$this->sequence_id}): " . print_r( $post_sequence, true ) );
                return true;
            }

            if ( $this->sequence_id == 0) {

                $this->dbgOut( "hasAccess(): Sequence ID was NOT set by calling function: " . $this->whoCalledMe() );
                foreach ( $post_sequence as $seqId ) {

                    $this->init( $seqId );
                    return $this->get_accessStatus( $post_id, $user_id, $post_sequence, $isAlert );
                }
            }
            else {

                return $this->get_accessStatus( $post_id, $user_id, $post_sequence, $isAlert );
            }

        } // End of function

        /************************************ Private Sequence Functions ***********************************************/

        /**
         * Returns key (array key) of the post if it's included in this sequence.
         *
         * @param $post_id (int) -- Page/post ID to check for inclusion in this sequence.
         *
         * @return bool|int -- Key of post in $this->posts array if the post is already included in the sequence. False otherwise
         *
         * @access private
         */
        private function hasPost( $post_id ) {

            $this->getPosts();

            if ( ( $key = $this->getPostKey( $post_id ) ) !== false ) {

                return $key;
            }

            return false;
        }

        /**
         * Validates that the value received follows a valid "delay" format for the post/page sequence
         *
         * @param $delay (string) - The specified post delay value
         * @return bool - Delay is recognized (parseable).
         *
         * @access private
         */
        private function isValidDelay( $delay )
        {
            $this->dbgOut( "isValidDelay(): Delay value is: {$delay} for setting: {$this->options->delayType}" );

            switch ($this->options->delayType)
            {
                case 'byDays':
                    $this->dbgOut('isValidDelay(): Delay configured as "days since membership start"');
                    return ( is_numeric( $delay ) ? true : false);
                    break;

                case 'byDate':
                    $this->dbgOut('isValidDelay(): Delay configured as a date value');
                    return ( apply_filters( 'pmpro-sequence-check-valid-date', $this->isValidDate( $delay ) ) ? true : false);
                    break;

                default:
                    $this->dbgOut('isValidDelay(): NOT a valid delay value, based on config');
                    $this->dbgOut("isValidDelay() - options Array: " . print_r( $this->options, true ) );
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
        private function isValidDate( $data )
        {
            // Fixed: isValidDate() needs to support all expected date formats...
            if ( false === strtotime( $data ) ) {

                return false;
            }

            return true;
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
                    //$this->dbgOut('sortByDelay(): Sorted in Ascending order');
                    return $this->sortAscending($a, $b);
                    break;
                case SORT_DESC:
                    //$this->dbgOut('sortByDelay(): Sorted in Descending order');
                    return $this->sortDescending($a, $b);
                    break;
                default:
                    $this->dbgOut('sortByDelay(): sortOrder not defined');
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
        private function sortAscending($a, $b)
        {
            list($aDelay, $bDelay) = $this->normalizeDelays($a, $b);
            // $this->dbgOut('sortAscending() - Delays have been normalized');

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
         * Sort the two posts in ascending order
         *
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
         * Get all posts with status 'published', 'draft', 'scheduled', 'pending review' or 'private' from the DB
         *
         * @return array | bool -- All posts of the post_types defined in the pmpro_sequencepost_types filter)
         *
         * @access private
         */
        private function getPostListFromDB() {

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

            $this->dbgOut('removeNotifiedFlagForPost() - Preparing SQL. Using sequence ID: ' . $this->sequence_id);

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

            foreach ( $users as $user ) {

                $this->dbgOut( "removeNotifiedFlagForPost() - Searching for Post ID {$post_id} in notification settings for user with ID: {$user->user_id}" );
                $userSettings = get_user_meta( $user->user_id, $wpdb->prefix . 'pmpro_sequence_notices', true );

                count($userSettings) > 0 ? $this->dbgOut("Notification settings exist for {$this->sequence_id}") : $this->dbgOut('No notification settings found');

                $notifiedPosts = $userSettings->sequence[ $this->sequence_id ]->notifiedPosts;

                if ( is_array( $notifiedPosts ) &&
                    ($key = array_search( $post_id, $notifiedPosts ) ) !== false ) {

                    $this->dbgOut( "removeNotifiedFlagForPost() - Found post # {$post_id} in the notification settings for user_id {$user->user_id} with key: {$key}" );
                    $this->dbgOut( "removeNotifiedFlagForPost() - Found in settings: {$userSettings->sequence[ $this->sequence_id ]->notifiedPosts[ $key ]}");
                    unset( $userSettings->sequence[ $this->sequence_id ]->notifiedPosts[ $key ] );

                    update_user_meta( $user->user_id, $wpdb->prefix . 'pmpro_sequence_notices', $userSettings );
                    $this->dbgOut( "removeNotifiedFlagForPost() - Deleted post # {$post_id} in the notification settings for user with id {$user->user_id}" );
                }
            }
        }

        /**
         * Compares the object to the array of posts in the sequence
         * @param $delayComp -- Delay value to compare to
         * @param $postArr -- The post object
         * @return stdClass -- The post ID of the post with the delay value closest to the $delayVal
         *
         * @access private
         */
        private function get_closestByDelay( $delayComp, $postArr, $user_id = null ) {


            if ( empty($user_id) ) {

                global $current_user;
                $user_id = $current_user->ID;
            }

            $distances = array();

            foreach ( $postArr as $key => $post ) {

                // Only interested in posts we actually have access to.
                // TODO: Rather than look up one post at a time, should just compare against an array of posts we have access to.
                if ( $this->hasAccess( $user_id, $post->id, true ) ) {

                    $distances[ $key ] = abs( $delayComp - ( $this->normalizeDelay( $post->delay ) /* + 1 */ ) );
                }
            }

            // Verify that we have one or more than one element
            if ( count( $distances ) > 1 ) {

                $retVal = $postArr[ array_search( min( $distances ) , $distances ) ];
            }
            elseif ( count( $distances ) == 1 ) {
                $retVal = $postArr[$key];
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
        private function postDelayAsTS($delay, $user_id = null, $level_id = null) {

            $delayTS = current_time('timestamp', true); // Default is 'now'

            $startTS = pmpro_getMemberStartdate($user_id, $level_id);

            switch ($this->options->delayType) {
                case 'byDays':
                    $delayTS = strtotime( '+' . $delay . ' days', $startTS);
                    $this->dbgOut('postDelayAsTS() -  byDays:: delay = ' . $delay . ', delayTS is now: ' . $delayTS . ' = ' . date('Y-m-d', $startTS) . ' vs ' . date('Y-m-d', $delayTS));
                    break;

                case 'byDate':
                    $delayTS = strtotime( $delay );
                    $this->dbgOut('postDelayAsTS() -  byDate:: delay = ' . $delay . ', delayTS is now: ' . $delayTS . ' = ' . date('Y-m-d', $startTS) . ' vs ' . date('Y-m-d', $delayTS));
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
        private function calculateTimestamp( $timeString ) {

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

                $this->dbgOut('calculateTimestamp() - Timestring: ' . $timeString . ', scheduled Hour: ' . $schedHour . ' and current Hour: ' .$nowHour );

                /*
                 *  Using these to decide whether or not to assume 'today' or 'tomorrow' for initial schedule for
                 * this cron() job.
                 *
                 * If the admin attempts to schedule a job that's less than 30 minutes away, we'll schedule it for tomorrow.
                 */
                $hourDiff = $schedHour - $nowHour;
                $hourDiff += ( ( ($hourDiff == 0) && (($schedMin - $nowMin) <= 0 )) ? 0 : 1);

                if ( $hourDiff >= 1 ) {
                    $this->dbgOut('calculateTimestamp() - Assuming current day');
                    $when = ''; // Today
                }
                else {
                    $this->dbgOut('calculateTimestamp() - Assuming tomorrow');
                    $when = 'tomorrow ';
                }
                /* Create the string we'll use to generate a timestamp for cron() */
                $timeInput = $when . $timeString . ' ' . get_option('timezone_string');
                $timestamp = strtotime($timeInput);
            }
            catch (Exception $e)
            {
                $this->dbgOut('calculateTimestamp() -- Error calculating timestamp: : ' . $e->getMessage());
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

            $seq_list = get_post_meta( $post_id, "_post_sequences", true );
            /*
                        if ( empty( $seq_list ) && ( $post_id == $this->sequence_id ) ) {
                            return array( $this->sequence_id );
                        }
            */
            if ( ! empty( $seq_list ) && is_array( $seq_list )) {

                $this->dbgOut("Cleaning up the list of sequences ");
                $list = array_unique( $seq_list, SORT_NUMERIC );

                if ( ! empty( $list ) ) {
                    $seq_list = $list;
                }
            }
            elseif ( empty( $seq_list ) && ( $post_id == $this->sequence_id ) ) {

                $seq_list = array( $this->sequence_id );
            }

            if ( $doSave === true ) {
                update_post_meta( $post_id, '_post_sequences', $seq_list );
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
        public function isAfterOptIn( $user_id, $optinTS, $post ) {

            // = $user_settings->sequence[ $this->sequence_id ]->optinTS;

            if ($optinTS != -1) {

                $this->dbgOut( 'isAfterOptIn() -- User: ' . $user_id . ' Optin TS: ' . $optinTS .
                    ', Optin Date: ' . date( 'Y-m-d', $optinTS )
                );

                $delayTS = $this->postDelayAsTS( $post->delay, $user_id );

                // Compare the Delay to the optin (subtract 24 hours worth of time from the opt-in TS)
                if ( $delayTS >= ($optinTS - (3600 * 24)) ) {

                    $this->dbgOut('isAfterOptIn() - This post SHOULD be allowed to be alerted on');
                    return true;
                } else {
                    $this->dbgOut('isAfterOptIn() - This post should NOT be allowed to be alerted on');
                    return false;
                }
            } else {
                $this->dbgOut('isAfterOptIn() - Negative opt-in timestamp value. The user  (' . $user_id . ') does not want to receive alerts');
                return false;
            }
        }

        /**
         * Update the when we're supposed to run the New Content Notice cron job for this sequence.
         *
         * @access public
         */
        private function updateNoticeCron() {

            /* TODO: Does not support Daylight Savings Time (DST) transitions well! - Update check hook in init? */
            $prevScheduled = false;
            try {

                // Check if the job is previously scheduled. If not, we're using the default cron schedule.
                if (false !== ($timestamp = wp_next_scheduled( 'pmpro_sequence_cron_hook', array($this->sequence_id) ) )) {

                    // Clear old cronjob for this sequence
                    $this->dbgOut('Current cron job for sequence # ' . $this->sequence_id . ' scheduled for ' . $timestamp);
                    $prevScheduled = true;

                    // wp_clear_scheduled_hook($timestamp, 'pmpro_sequence_cron_hook', array( $this->sequence_id ));
                }

                $this->dbgOut('updateNoticeCron() - Next scheduled at (timestamp): ' . print_r(wp_next_scheduled('pmpro_sequence_cron_hook', array($this->sequence_id)), true));

                // Set time (what time) to run this cron job the first time.
                $this->dbgOut('updateNoticeCron() - Alerts for sequence #' . $this->sequence_id . ' at ' . date('Y-m-d H:i:s', $this->options->noticeTimestamp) . ' UTC');

                if  ( ($prevScheduled) &&
                    ($this->options->noticeTimestamp != $timestamp) ) {

                    $this->dbgOut('updateNoticeCron() - Admin changed when the job is supposed to run. Deleting old cron job for sequence w/ID: ' . $this->sequence_id);
                    wp_clear_scheduled_hook( 'pmpro_sequence_cron_hook', array($this->sequence_id) );

                    // Schedule a new event for the specified time
                    if ( false === wp_schedule_event(
                            $this->options->noticeTimestamp,
                            'daily',
                            'pmpro_sequence_cron_hook',
                            array( $this->sequence_id )
                        )) {

                        $this->setError( printf( __('Could not schedule new content alert for %s', 'pmprosequence'), $this->options->noticeTime) );
                        $this->dbgOut("updateNoticeCron() - Did not schedule the new cron job at ". $this->options->noticeTime . " for this sequence (# " . $this->sequence_id . ')');
                        return false;
                    }
                }
                elseif (! $prevScheduled)
                    wp_schedule_event($this->options->noticeTimestamp, 'daily', 'pmpro_sequence_cron_hook', array($this->sequence_id));
                else
                    $this->dbgOut("updateNoticeCron() - Timestamp didn't change so leave the schedule as-is");

                // Validate that the event was scheduled as expected.
                $ts = wp_next_scheduled( 'pmpro_sequence_cron_hook', array($this->sequence_id) );

                $this->dbgOut('updateNoticeCron() - According to WP, the job is scheduled for: ' . date('d-m-Y H:i:s', $ts) . ' UTC and we asked for ' . date('d-m-Y H:i:s', $this->options->noticeTimestamp) . ' UTC');

                if ($ts != $this->options->noticeTimestamp)
                    $this->dbgOut("updateNoticeCron() - Timestamp for actual cron entry doesn't match the one in the options...");
            }
            catch (Exception $e) {
                // echo 'Error: ' . $e->getMessage();
                $this->dbgOut('Error updating cron job(s): ' . $e->getMessage());

                if ( is_null($this->getError()) )
                    $this->setError("Exception in updateNoticeCron(): " . $e->getMessage());

                return false;
            }

            return true;
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
         * TODO: Fix email body to be correct (standards compliant) MIME encoded HTML mail or text mail.
         */
        public function sendEmail($post_id, $user_id, $seq_id) {

            // Make sure the email class is loaded.
            if ( ! class_exists( 'PMProEmail' ) ) {
                return;
            }

            $email = new PMProEmail();

            $user = get_user_by('id', $user_id);
            $post = get_post($post_id);

            $templ = preg_split('/\./', $this->options->noticeTemplate); // Parse the template name
            $email->template = $templ[0];

            $this->dbgOut('sendEmail() - Loading template for email message');

            if (false === ($template_content = file_get_contents( $this->getEmailTemplatePath() ) ) ) {

                $this->dbgOut('ERROR: Could not read content from template file: '. $this->options->noticeTemplate);
                return false;
            }

            $this->dbgOut('sendEmail() - Setting sender information');

            $email->from = $this->options->replyto; // = pmpro_getOption('from_email');
            $email->fromname = $this->options->fromname; // = pmpro_getOption('from_name');

            $email->email = $user->user_email;
            $email->ptitle = $post->post_title;

            $seqPost = $this->get_postDetails($post->ID);
            $this->dbgOut("sendEmail() Subject information: {$seqPost->delay} for {$post->ID}");

            $email->subject = sprintf('%s: %s (%s)', $this->options->subject, $post->post_title, strftime("%x", current_time('timestamp') ));

            $email->dateformat = $this->options->dateformat;

            $email->body = $template_content;

            // All of the array list names are !!<name>!! escaped values.
            $email->data = array(
                "name" => $user->first_name, // Options are: display_name, first_name, last_name, nickname
                "sitename" => get_option("blogname"),
                "post_link" => '<a href="' . get_permalink($post->ID) . '" title="' . $post->post_title . '">' . $post->post_title . '</a>',
                "today" => date($this->options->dateformat, current_time('timestamp')),
            );

            // $this->dbgOut('sendEmail() - Array contains: ' . print_r($email->data, true));

            if(!empty($post->post_excerpt)) {

                $this->dbgOut("Adding the post excerpt to email notice");

                if ( empty( $this->options->excerpt_intro ) ) {
                    $this->options->excerpt_intro = __('A summary of the post follows below:', 'pmprosequence');
                }

                $email->data['excerpt'] = '<p>' . $this->options->excerpt_intro . '</p><p>' . $post->post_excerpt . '</p>';
            }
            else
                $email->data['excerpt'] = '';


            $email->sendEmail();

            return true;
        }

        /**
         * Check the theme/child-theme/PMPro Sequence plugin directory for the specified notice template.
         *
         * @return null|string -- Path to the selected template for the email alert notice.
         */
        private function getEmailTemplatePath() {

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
         * TODO: Make it sequence specific - right now it's too broad.
         *
         * @param $userId - User's ID
         * @param $sequenceId - ID of the sequence we're clearning
         *
         * @return mixed - false means the reset didn't work.
         */
        public function resetUserNotifications( $userId, $sequenceId ) {

            global $wpdb;

            return delete_user_meta( $userId, $wpdb->prefix . 'pmpro_sequence_notices' );
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

            //	$this->dbgOut('email_body filter() -  Mailer Obj contains: ' . print_r($phpmailer, true));

            $phpmailer->Body = apply_filters( 'pmpro-sequence-alert-message-excerpt-intro', str_replace( "!!excerpt_intro!!", $phpmailer->excerpt_intro, $phpmailer->Body ) );
            // $phpmailer->Body = str_replace( "!!today!!", date($phpmailer->dateformat, current_time('timestamp')), $phpmailer->Body );
            $phpmailer->Body = apply_filters( 'pmpro-sequence-alert-message-title', str_replace( "!!ptitle!!", $phpmailer->ptitle , $phpmailer->Body ) );
        }

        /**
         * Does the heavy lifting in checking access the sequence post for the user_id
         *
         * @since 2.0
         *
         * @param $post_id (int) - ID of the post to check access for
         * @param $user_id (int) - User ID for the user to check access for
         * @param $post_sequences (array) - Array of sequences that $post_id claims to belong to.
         * @param $isAlert -- Whether or not we're checking access as part of alert processing or not.
         *
         * @return bool -- True if the user has a membership level and the post's delay value is <= the # of days the user has been a member.
         *
         * @access private
         */
        private function get_accessStatus( $post_id, $user_id, $post_sequences, $isAlert ) {

            if ( in_array( $this->sequence_id, $post_sequences ) ) {

                if ( user_can( $user_id, 'publish_posts' ) && ( is_preview() ) ) {
                    $this->dbgOut("Post #{$post_id} is a preview for {$user_id}");
                    return true;
                }

                $allowed_post_statuses = apply_filters( 'pmpro-sequence-allowed-post-statuses', array( 'publish', 'future', 'private' ) );
                $curr_post_status = get_post_status( $post_id );

                // Only consider granting access to the post if it is in one of the allowed statuses
                if ( ! in_array( $curr_post_status, $allowed_post_statuses ) ) {

                    $this->dbgOut("get_accessStatus() - Post {$post_id} with status {$curr_post_status} isn't accessible", DEBUG_SEQ_WARNING );
                    return false;
                }

                // $this->dbgOut( "get_AccessStatus() for post {$post_id} is managed by PMProSequence: " . $this->whoCalledMe() );

                /* Bugfix: It's possible there are duplicate values in the list of sequences for this post. */
                $sequence_list = array_unique( $post_sequences );

                if ( count( $sequence_list ) < count( $post_sequences ) ) {

                    $this->dbgOut("get_AccessStatus() - Saving the pruned array of sequences");
                    update_post_meta( $post_id, '_post_sequences', $sequence_list );
                }

                // $this->dbgOut("UserID: {$user_id}, post: {$post_id}, Alert: {$isAlert} for sequence: {$this->sequence_id} - posts: " .print_r( $sequence_list, true));

                $results = pmpro_has_membership_access( $this->sequence_id, $user_id, true ); //Using true to return all level IDs that have access to the sequence

                // $this->dbgOut(" hasAccess() - PMPRO function returns: " . print_r( $results, true ) );

                if ( $results[0] !== true ) { // First item in results array == true if user has access

                    $this->dbgOut( "get_AccessStatus() - User {$user_id} does NOT have access to this sequence ({$this->sequence_id})", DEBUG_SEQ_WARNING );
                    return false;
                }

                $usersLevels = pmpro_getMembershipLevelsForUser( $user_id );

                // Check if the post exists in the list of posts for the current sequence & return its details if it's there
                if ( ( $sp = $this->get_postDetails( $post_id ) ) !== null ) {

                    // Verify for all levels given access to this post
                    foreach ( $results[1] as $level_id ) {

                        if ( ! in_object_r( 'id', $level_id, $usersLevels ) ) {
                            // $level_id (i.e. membership_id) isn't in the array of levels this $user_id also belongs to...
                            // $this->dbgOut("hasAccess() - Users membership level list does not include {$level_id} - skipping");
                            continue;
                        }

                        if ( $this->options->delayType == 'byDays' ) {

                            // Don't add 'preview' value if this is for an alert notice.
                            if (! $isAlert) {

                                $durationOfMembership = $this->getMemberDays( $user_id, $level_id ) + $this->options->previewOffset;
                            }
                            else {

                                $durationOfMembership = $this->getMemberDays( $user_id, $level_id );
                            }

                            // $this->dbgOut( sprintf('hasAccess() - Member %d has been active at level %d for %f days. The post has a delay of: %d', $user_id, $level_id, $durationOfMembership, $sp->delay) );

                            if ( $durationOfMembership >= $sp->delay ) {
                                // $this->dbgOut("hatByAccess() - using byDays as the delay type, this user is given access to post ID {$post_id}.");
                                return true;
                            }

                        } elseif ( $this->options->delayType == 'byDate' ) {

                            // Don't add 'preview' value if this is for an alert notice.
                            if (! $isAlert)
                                $previewAdd = ((60*60*24) * $this->options->previewOffset);
                            else
                                $previewAdd = 0;

                            $today = date( __( 'Y-m-d', 'pmprosequence' ), ( current_time( 'timestamp' ) + $previewAdd ) );

                            if ( $today >= $sp->delay ) {
                                // $this->dbgOut("hasAccess() - using byDate as the delay type, this user is given access to post ID {$post_id}.");
                                return true;
                            }
                        } // EndIf for delayType
                    } // End of foreach -> $level_id
                } // EndIF

            } // End of if

            $this->dbgOut("get_AccessStatus() - User {$user_id} does NOT have access to post {$post_id} in sequence {$this->sequence_id}" );
            // Haven't found anything yet, so must not have access.
            return false;
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
        private function userCanEdit( $user_id ) {

            if ( ( user_can( $user_id, 'publish_pages' ) ) ||
                ( user_can( $user_id, 'publish_posts' ) ) ) {

                $this->dbgOut("User with ID {$user_id} has permission to update/edit this sequence");
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
        private function whoCalledMe() {

            $trace=debug_backtrace();
            $caller=$trace[2];

            $trace =  "Called by {$caller['function']}()";
            if (isset($caller['class']))
                $trace .= " in {$caller['class']}()";

            return $trace;
        }

        /**
         * Debug function (if executes if DEBUG is defined)
         *
         * @param $msg -- Debug message to print to debug log.
         *
         * @access public
         * @since v2.1
         */
        public function dbgOut( $msg, $lvl = DEBUG_SEQ_INFO ) {

            $dbgPath = PMPRO_SEQUENCE_PLUGIN_DIR . 'debug';

            if ( ( WP_DEBUG === true ) && ( $lvl >= DEBUG_SEQ_LOG_LEVEL ) ) {

                if (!  file_exists( $dbgPath )) {
                    // Create the debug logging directory
                    mkdir( $dbgPath, 0750 );

                    if (! is_writable( $dbgPath )) {
                        error_log("PMPro Sequence: Debug log directory {$dbgPath} is not writable. exiting.");
                        return;
                    }
                }

                $dbgFile = $dbgPath . DIRECTORY_SEPARATOR . 'sequence_debug_log-' . date('Y-m-d', current_time("timestamp") ) . '.txt';

                if ( ($fh = fopen($dbgFile, 'a')) !== false ) {

                    // Format the debug log message
                    $tid = sprintf("%08x", abs(crc32($_SERVER['REMOTE_ADDR'] . $_SERVER['REQUEST_TIME'] . $_SERVER['REMOTE_PORT'])));
                    $dbgMsg = '(' . date('d-m-y H:i:s', current_time( "timestamp" ) ) . "-{$tid}) -- ". $msg;

                    // Write it to the debug log file
                    fwrite( $fh, $dbgMsg . "\r\n" );
                    fclose( $fh );
                }
                else {
                    error_log( 'PMPro Sequence: Unable to open debug log' );
                }
            }
        }

        /**
         * Access the private $error value
         *
         * @return string|null -- Error message or NULL
         * @access public
         */
        public function getError() {

            if ( empty( $this->error ) ) {

                $this->dbgOut("Attempt to load error info");

                // Check if the settings_error string is set:
                $this->error = get_settings_errors( 'pmpro_seq_errors' );
            }

            if ( ! empty( $this->error ) ) {

                $this->dbgOut("Error info found: " . print_r( $this->error, true));
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

            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                $this->dbgOut("Exit during autosave");
                return;
            }

            if ( wp_is_post_revision( $post_id ) !== false ) {
                $this->dbgOut("post_save_action() - Not saving revisions ({$post_id}) to sequence");
                return;
            }

            if ( ! in_array( $post->post_type, $this->managed_types ) ) {
                $this->dbgOut("post_save_action() - Not saving delay info for {$post->post_type}");
                return;
            }

            $this->dbgOut("post_save_action() - Sequences & Delays have been configured for page save. " . $this->whoCalledMe());

            $seq_ids = is_array( $_POST['pmpro_seq-sequences'] ) ? $_POST['pmpro_seq-sequences'] : null;
            $delays = is_array( $_POST['pmpro_seq-delay']) ? $_POST['pmpro_seq-delay'] : null;

            if ( empty( $delays ) ) {

                $this->setError( __( "Error: No delay value(s) received", "pmprosequence") );
                $this->dbgOut( "post_save_action() - Error: delay not specified! ", DEBUG_SEQ_CRITICAL );
                return;
            }

            $errMsg = null;

            $already_in = get_post_meta( $post_id, "_post_sequences", true );

            $this->dbgOut( "post_save_action() - Saved received variable values...");

            foreach ($seq_ids as $key => $id ) {

                $this->dbgOut("post_save_action() - Processing for sequence {$id}");

                if ( $id == 0 ) {
                    continue;
                }

                $this->init( $id );

                $user_can = apply_filters( 'pmpro-sequence-has-edit-privileges', $this->userCanEdit( $current_user->ID ) );

                if (! $user_can ) {

                    $this->setError( __( 'Incorrect privileges for this operation', 'pmprosequence' ) );
                    $this->dbgOut("post_save_action() - User lacks privileges to edit", DEBUG_SEQ_WARNING);
                    return;
                }

                if ( $id == 0 ) {

                    $this->dbgOut("post_save_action() - No specified sequence or it's set to 'nothing'");

                }
                elseif ( empty( $delays[$key] ) ) {

                    $this->dbgOut("post_save_action() - Not a valid delay value...: " . $delays[$key], DEBUG_SEQ_CRITICAL);
                    $this->setError( sprintf( __( "You must specify a delay value for the '%s' sequence", 'pmprosequence'), get_the_title( $id ) ) );
                }
                else {

                    $this->dbgOut( "post_save_action() - Processing post {$post_id} for sequence {$this->sequence_id} with delay {$delays[$key]}" );
                    $this->addPost( $post_id, $delays[ $key ] );
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
        public function savePostMeta( $post_id )
        {
            global $post;

            // Check that the function was called correctly. If not, just return
            if ( empty( $post_id ) ) {

                $this->dbgOut('savePostMeta(): No post ID supplied...', DEBUG_SEQ_WARNING);
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

            $this->init( $post_id );

            $this->dbgOut('savePostMeta(): Saving settings for sequence ' . $post_id);
            // $this->dbgOut('From Web: ' . print_r($_REQUEST, true));

            // OK, we're authenticated: we need to find and save the data
            if ( isset($_POST['pmpro_sequence_settings_noncename']) ) {

                $this->dbgOut( 'Have to load new instance of Sequence class' );

                if ( ! $this->options ) {
                    $this->options = $this->defaultOptions();
                }

                if ( ($retval = $this->save_settings( $post_id, $this )) === true ) {

                    $this->dbgOut( 'savePostMeta(): Saved metadata for sequence #' . $post_id );

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
        function settings_callback()
        {
            // Validate that the ajax referrer is secure
            check_ajax_referer('pmpro-sequence-save-settings', 'pmpro_sequence_settings_nonce');

            /** @noinspection PhpUnusedLocalVariableInspection */
            $status = false;

            /** @noinspection PhpUnusedLocalVariableInspection */
            $response = '';

            try {

                if ( isset($_POST['pmpro_sequence_id']) ) {

                    $sequence_id = intval($_POST['pmpro_sequence_id']);
                    $this->init( $sequence_id );

                    $this->dbgOut('ajaxSaveSettings() - Saving settings for ' . $this->sequence_id);

                    if ( ($status = $this->save_settings( $sequence_id ) ) === true) {

                        if ( isset($_POST['hidden_pmpro_seq_wipesequence'])) {

                            if (intval($_POST['hidden_pmpro_seq_wipesequence']) == 1) {

                                // Wipe the list of posts in the sequence.
                                $sposts = get_post_meta( $sequence_id, '_sequence_posts' );

                                if ( count($sposts) > 0) {

                                    if ( ! delete_post_meta( $sequence_id, '_sequence_posts' ) ) {

                                        $this->dbgOut( 'ajaxSaveSettings() - Unable to delete the posts in sequence # ' . $sequence_id, DEBUG_SEQ_CRITICAL );
                                        $this->setError( __('Unable to wipe existing posts', 'pmprosequence') );
                                        $status = false;
                                    }
                                    else
                                        $status = true;
                                }

                                $this->dbgOut( 'ajaxSaveSettings() - Deleted all posts in the sequence' );
                            }
                        }
                    }
                    else {
                        $this->setError( printf( __('Save status returned was: %s', 'pmprosequence'), $status ) );
                    }

                    $response = $this->getPostListForMetaBox();
                }
                else {
                    $this->setError( __( 'No sequence ID found/specified', 'pmprosequence' ) );
                    $status = false;
                }

            } catch (Exception $e) {

                $status = false;
                $this->setError( printf( __('(exception) %s', 'pmprosequence'), $e->getMessage()) );
                $this->dbgOut(print_r($this->getError(), true), DEBUG_SEQ_CRITICAL);
            }


            if ($status)
                wp_send_json_success( $response['html'] );
            else
                wp_send_json_error( $this->getError() );

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

                if ( isset($_POST['hidden_pmpro_seq_uid'])) {

                    $user_id = intval($_POST['hidden_pmpro_seq_uid']);
                    $this->dbgOut('Updating user settings for user #: ' . $user_id);

                    // Grab the metadata from the database
                    $usrSettings = get_user_meta($user_id, $wpdb->prefix . 'pmpro_sequence_notices', true);

                }
                else {
                    $this->dbgOut( 'No user ID specified. Ignoring settings!', DEBUG_SEQ_WARNING );

                    wp_send_json_error( __('Unable to save your settings', 'pmprosequence') );
                }

                if ( isset($_POST['hidden_pmpro_seq_id'])) {

                    $seqId = intval( $_POST['hidden_pmpro_seq_id']);
                }
                else {

                    $this->dbgOut( 'No sequence number specified. Ignoring settings for user', DEBUG_SEQ_WARNING );

                    wp_send_json_error( __('Unable to save your settings', 'pmprosequence') );
                }

                $this->init( $seqId );
                $this->dbgOut('Updating user settings for sequence #: ' . $this->sequence_id);

                if ( empty($usrSettings->sequence) || empty( $usrSettings->sequence[$seqId] ) ) {

                    $this->dbgOut('No user specific settings found in general or for this sequence. Creating defaults');

                    // Create new opt-in settings for this user
                    if ( empty($usrSettings->sequence) )
                        $new = new stdClass();
                    else // Saves existing settings
                        $new = $usrSettings;

                    $this->dbgOut('Using default setting for user ' . $current_user->ID . ' and sequence ' . $this->sequence_id);

                    $usrSettings = $new;
                }

                $usrSettings->sequence[$seqId]->sendNotice = ( isset( $_POST['hidden_pmpro_seq_useroptin'] ) ?
                    intval($_POST['hidden_pmpro_seq_useroptin']) : $this->options->sendNotice );

                // If the user opted in to receiving alerts, set the opt-in timestamp to the current time.
                // If they opted out, set the opt-in timestamp to -1
                if ($usrSettings->sequence[$seqId]->sendNotice == 1)
                    // Set the timestamp when the user opted in.
                    $usrSettings->sequence[$seqId]->optinTS = current_time('timestamp');
                else
                    $usrSettings->sequence[$seqId]->optinTS = -1; // Opted out.

                // Add an empty array to store posts that the user has already been notified about
                if ( empty( $usrSettings->sequence[$seqId]->notifiedPosts ) )
                    $usrSettings->sequence[$seqId]->notifiedPosts = array();

                /* Save the user options we just defined */
                if ( $user_id == $current_user->ID ) {

                    $this->dbgOut('Opt-In Timestamp is: ' . $usrSettings->sequence[$seqId]->optinTS);
                    // $this->dbgOut('Saving user_meta for UID ' . $user_id . ' Settings: ' . print_r($usrSettings, true));
                    update_user_meta( $user_id, $wpdb->prefix . 'pmpro_sequence_notices', $usrSettings );
                    $status = true;
                    $this->setError(null);
                }
                else {

                    $this->dbgOut('Error: Mismatched User IDs -- user_id: ' . $user_id . ' current_user: ' . $current_user->ID, DEBUG_SEQ_CRITICAL);
                    $this->setError( __( 'Unable to save your settings', 'pmprosequence' ) );
                    $status = false;
                }
            }
            catch (Exception $e) {
                $this->setError( sprintf( __('Error: %s', 'pmprosequence' ), $e->getMessage() ) );
                $status = false;
                $this->dbgOut('optin_save() - Exception error: ' . $e->getMessage(), DEBUG_SEQ_CRITICAL);
            }

            if ($status)
                wp_send_json_success();
            else
                wp_send_json_error( $this->getError() );

        }

        /**
         * Callback to catch request from admin to send any new Sequence alerts to the users.
         *
         * Triggers the cron hook to achieve it.
         */
        function sendalert_callback() {

            $this->dbgOut('sendalert() - Processing the request to send alerts manually');

            check_ajax_referer('pmpro-sequence-sendalert', 'pmpro_sequence_sendalert_nonce');

            $this->dbgOut('Nonce is OK');

            if ( isset( $_POST['pmpro_sequence_id'] ) ) {

                $sequence_id = intval($_POST['pmpro_sequence_id']);
                $this->dbgOut('Will send alerts for sequence #' . $sequence_id);
                do_action( 'pmpro_sequence_cron_hook', $sequence_id);
                $this->dbgOut('Completed action for sequence');
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

                    $this->dbgOut('Deleting all entries in sequence # ' .$sequence_id);

                    if (! delete_post_meta($sequence_id, '_sequence_posts'))
                    {
                        $this->dbgOut('Unable to delete the posts in sequence # ' . $sequence_id, DEBUG_SEQ_CRITICAL);
                        $this->setError( __('Could not delete posts from this sequence', 'pmprosequence'));

                    }
                    else {
                        $result = $this->getPostListForMetaBox();
                    }

                }
                else
                {
                    $this->setError( __('Unable to identify the Sequence', 'pmprosequence') );
                }
            }
            else {
                $this->setError( __('Unknown request', 'pmprosequence') );
            }

            // Return the status to the calling web page
            if ( $result['success'] )
                wp_send_json_success( $result['html']  );
            else
                wp_send_json_error( $this->getError() );

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

            $this->init( $sequence_id );

            // Remove the post (if the user is allowed to)
            if ( current_user_can( 'edit_posts' ) && ! is_null($seq_post_id) ) {

                $this->removePost($seq_post_id);
                //$result = __('The post has been removed', 'pmprosequence');
                $success = true;

            }
            else {

                $success = false;
                $this->setError( __( 'Incorrect privileges to remove posts from this sequence', 'pmprosequence'));
            }

            // Return the content for the new listbox (sans the deleted item)
            $result = $this->getPostListForMetaBox();

            if ( is_null( $result['message'] ) && is_null( $this->getError() ) && ($success)) {
                $this->dbgOut('Returning success to calling javascript');
                wp_send_json_success( $result['html'] );
            }
            else
                wp_send_json_error( ( ! is_null( $this->getError() ) ? $this->getError() : $result['message']) );

        }

        /**
         * Removes the sequence from managing this $post_id.
         * Returns the table of sequences the post_id belongs to back to the post/page editor using JSON.
         */
        function rm_sequence_from_post_callback() {

            /** @noinspection PhpUnusedLocalVariableInspection */
            $success = false;

            $this->dbgOut("In rm_sequence_from_post()");
            check_ajax_referer('pmpro-sequence-post-meta', 'pmpro_sequence_postmeta_nonce');

            $this->dbgOut("NONCE is OK for pmpro_sequence_rm");

            $sequence_id = ( isset( $_POST['pmpro_sequence_id'] ) && ( intval( $_POST['pmpro_sequence_id'] ) != 0 ) ) ? intval( $_POST['pmpro_sequence_id'] ) : null;
            $post_id = isset( $_POST['pmpro_seq_post_id'] ) ? intval( $_POST['pmpro_seq_post_id'] ) : null;

            $this->init( $sequence_id );
            $this->setError( null ); // Clear any pending error messages (don't care at this point).

            // Remove the post (if the user is allowed to)
            if ( current_user_can( 'edit_posts' ) && ( ! is_null( $post_id ) ) && ( ! is_null( $sequence_id ) ) ) {

                $this->dbgOut("Removing post # {$post_id} from sequence {$sequence_id}");
                $this->removePost( $post_id, true );
                //$result = __('The post has been removed', 'pmprosequence');
                $success = true;
            } else {

                $success = false;
                $this->setError( __( 'Incorrect privileges to remove posts from this sequence', 'pmprosequence' ) );
            }

            $result = $this->load_sequence_meta( $post_id );

            if ( ! empty( $result ) && is_null( $this->getError() ) && ( $success ) ) {

                $this->dbgOut( 'Returning success to caller' );
                wp_send_json_success( $result );
            } else {

                wp_send_json_error( ( ! is_null( $this->getError() ) ? $this->getError() : 'Error clearing the sequence from this post' ) );
            }
        }

        /**
         * Updates the delay for a post in the specified sequence (AJAX)
         *
         * @throws Exception
         */
        function update_delay_post_meta_callback() {

            $this->dbgOut("Update the delay input for the post/page meta");

            check_ajax_referer('pmpro-sequence-post-meta', 'pmpro_sequence_postmeta_nonce');

            $this->dbgOut("Nonce Passed for postmeta AJAX call");

            $seq_id = isset( $_POST['pmpro_sequence_id'] ) ? intval( $_POST['pmpro_sequence_id'] ) : null;
            $post_id = isset( $_POST['pmpro_sequence_post_id']) ? intval( $_POST['pmpro_sequence_post_id'] ) : null;

            $this->dbgOut("Sequence: {$seq_id}, Post: {$post_id}" );

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
            $delayVal = isset( $_POST['pmpro_sequencedelay'] ) ? $_POST['pmpro_sequencedelay'] : null ;

            if ( $sequence_id != 0 ) {

                // Initiate & configure the Sequence class
                $this->init( $sequence_id );

                $this->dbgOut( 'add_post_callback() - Checking whether delay value is correct' );
                $delay = $this->validatePOSTDelay( $delayVal );

                // Get the Delay to use for the post (depends on type of delay configured)
                if ( $delay !== false ) {

                    $user_can = apply_filters( 'pmpro-sequence-has-edit-privileges', $this->userCanEdit( $current_user->ID ) );

                    if ( $user_can && ! is_null( $seq_post_id ) ) {

                        $this->dbgOut( 'pmpro_sequence_add_post_callback() - Adding post ' . $seq_post_id . ' to sequence ' . $this->sequence_id );
                        $this->addPost( $seq_post_id, $delay );
                        $success = true;
                        $this->setError( null );

                    } else {
                        $success = false;
                        $this->setError( __( 'Not permitted to modify the sequence', 'pmprosequence' ) );
                    }

                } else {

                    $this->dbgOut( 'pmpro_sequence_add_post_callback(): Delay value was not specified. Not adding the post: ' . esc_attr( $_POST['pmpro_sequencedelay'] ) );

                    if ( empty( $seq_post_id ) && ( $this->getError() == null ) ) {

                        $this->setError( sprintf( __( 'Did not specify a post/page to add', 'pmprosequence' ) ) );
                    }
                    elseif ( ( $delay !== 0 ) && empty( $delay ) ) {

                        $this->setError( __( 'No delay has been specified', 'pmprosequence' ) );
                    }

                    $delay       = null;
                    $seq_post_id = null;

                    $success = false;

                }

                if ( empty( $seq_post_id ) && ( $this->getError() == null ) ) {

                    $success = false;
                    $this->setError( sprintf( __( 'Did not specify a post/page to add', 'pmprosequence' ) ) );
                }
                elseif ( empty( $sequence_id ) && ( $this->getError() == null ) ) {

                    $success = false;
                    $this->setError( sprintf( __( 'This sequence was not found on the server!', 'pmprosequence' ) ) );
                }

                $result = $this->getPostListForMetaBox();

                // $this->dbgOut("pmpro_sequence_add_post_callback() - Data added to sequence. Returning status to calling JS script: " . print_r($result, true));

                if ( $result['success'] && $success ) {
                    $this->dbgOut( 'pmpro_sequence_add_post_callback() - Returning success to javascript frontend' );

                    wp_send_json_success( $result['html'] );
                } else {
                    $this->dbgOut( 'pmpro_sequence_add_post_callback() - Returning error to javascript frontend' );
                    wp_send_json_error( $this->getError() );
                }
            }
            else {
                $this->dbgOut( "Sequence ID was 0. That's a 'blank' sequence" );
                wp_send_json_error( 'No sequence specified on save.' );
            }
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
            $this->dbgOut('Saving settings for Sequence w/ID: ' . $sequence_id);

            // Check that the function was called correctly. If not, just return
            if(empty($sequence_id)) {
                $this->dbgOut('save_settings(): No sequence ID supplied...');
                $this->setError( __('No sequence provided', 'pmprosequence'));
                return false;
            }

            // Is this an auto save routine? If our form has not been submitted (clicked "save"), we'd probably not want to save anything yet
            if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
                $this->setError(null);
                return $sequence_id;
            }

            // Verify that we're allowed to update the sequence data
            if ( !current_user_can( 'edit_post', $sequence_id ) ) {
                $this->dbgOut('save_settings(): User is not allowed to edit this post type', DEBUG_SEQ_CRITICAL);
                $this->setError( __('User is not allowed to change settings', 'pmprosequence'));
                return false;
            }

            if (!$this->options) {
                $this->options = $this->defaultOptions();
            }

            // Checkbox - not included during post/save if unchecked
            if ( isset($_POST['hidden_pmpro_seq_future']) )
            {
                $this->options->hidden = intval($_POST['hidden_pmpro_seq_future']);
                $this->dbgOut('save_settings(): POST value for settings->hidden: ' . $_POST['hidden_pmpro_seq_future'] );
            }
            elseif ( empty($this->options->hidden) )
                $this->options->hidden = 0;

            // Checkbox - not included during post/save if unchecked
            if (isset($_POST['hidden_pmpro_seq_lengthvisible']) )
            {
                $this->options->lengthVisible = intval($_POST['hidden_pmpro_seq_lengthvisible']);
                $this->dbgOut('save_settings(): POST value for settings->lengthVisible: ' . $_POST['hidden_pmpro_seq_lengthvisible']);
            }
            elseif (empty($this->options->lengthVisible)) {
                $this->dbgOut('Setting lengthVisible to default value (checked)');
                $this->options->lengthVisible = 1;
            }

            if ( isset($_POST['hidden_pmpro_seq_sortorder']) )
            {
                $this->options->sortOrder = intval($_POST['hidden_pmpro_seq_sortorder']);
                $this->dbgOut('save_settings(): POST value for settings->sortOrder: ' . $_POST['hidden_pmpro_seq_sortorder'] );
            }
            elseif (empty($this->options->sortOrder))
                $this->options->sortOrder = SORT_ASC;

            if ( isset($_POST['hidden_pmpro_seq_delaytype']) )
            {
                $this->options->delayType = esc_attr($_POST['hidden_pmpro_seq_delaytype']);
                $this->dbgOut('save_settings(): POST value for settings->delayType: ' . esc_attr($_POST['hidden_pmpro_seq_delaytype']) );
            }
            elseif (empty($this->options->delayType))
                $this->options->delayType = 'byDays';

            // options->showDelayAs
            if ( isset($_POST['hidden_pmpro_seq_showdelayas']) )
            {
                $this->options->showDelayAs = esc_attr($_POST['hidden_pmpro_seq_showdelayas']);
                $this->dbgOut('save_settings(): POST value for settings->showDelayAs: ' . esc_attr($_POST['hidden_pmpro_seq_showdelayas']) );
            }
            elseif (empty($this->options->showDelayAs))
                $this->options->delayType = PMPRO_SEQ_AS_DAYNO;

            if ( isset($_POST['hidden_pmpro_seq_offset']) )
            {
                $this->options->previewOffset = esc_attr($_POST['hidden_pmpro_seq_offset']);
                $this->dbgOut('save_settings(): POST value for settings->previewOffset: ' . esc_attr($_POST['hidden_pmpro_seq_offset']) );
            }
            elseif (empty($this->options->previewOffset))
                $this->options->previewOffset = 0;

            if ( isset($_POST['hidden_pmpro_seq_startwhen']) )
            {
                $this->options->startWhen = esc_attr($_POST['hidden_pmpro_seq_startwhen']);
                $this->dbgOut('save_settings(): POST value for settings->startWhen: ' . esc_attr($_POST['hidden_pmpro_seq_startwhen']) );
            }
            elseif (empty($this->options->startWhen))
                $this->options->startWhen = 0;

            // Checkbox - not included during post/save if unchecked
            if ( isset($_POST['hidden_pmpro_seq_sendnotice']) )
            {
                $this->options->sendNotice = intval($_POST['hidden_pmpro_seq_sendnotice']);

                if ( $this->options->sendNotice == 0 ) {

                    $this->stopSendingNotices();
                }

                $this->dbgOut('save_settings(): POST value for settings->sendNotice: ' . intval($_POST['hidden_pmpro_seq_sendnotice']) );
            }
            elseif (empty($this->options->sendNotice)) {
                $this->options->sendNotice = 1;
            }

            if ( isset($_POST['hidden_pmpro_seq_sendas']) )
            {
                $this->options->noticeSendAs = esc_attr($_POST['hidden_pmpro_seq_sendas']);
                $this->dbgOut('save_settings(): POST value for settings->noticeSendAs: ' . esc_attr($_POST['hidden_pmpro_seq_sendas']) );
            }
            else
                $this->options->noticeSendAs = PMPRO_SEQ_SEND_AS_SINGLE;

            if ( isset($_POST['hidden_pmpro_seq_noticetemplate']) )
            {
                $this->options->noticeTemplate = esc_attr($_POST['hidden_pmpro_seq_noticetemplate']);
                $this->dbgOut('save_settings(): POST value for settings->noticeTemplate: ' . esc_attr($_POST['hidden_pmpro_seq_noticetemplate']) );
            }
            else
                $this->options->noticeTemplate = 'new_content.html';

            if ( isset($_POST['hidden_pmpro_seq_noticetime']) )
            {
                $this->options->noticeTime = esc_attr($_POST['hidden_pmpro_seq_noticetime']);
                $this->dbgOut('save_settings() - noticeTime in settings: ' . $this->options->noticeTime);

                /* Calculate the timestamp value for the noticeTime specified (noticeTime is in current timezone) */
                $this->options->noticeTimestamp = $this->calculateTimestamp($settings->noticeTime);

                $this->dbgOut('save_settings(): POST value for settings->noticeTime: ' . esc_attr($_POST['hidden_pmpro_seq_noticetime']) );
            }
            else
                $this->options->noticeTime = '00:00';

            if ( isset($_POST['hidden_pmpro_seq_excerpt']) )
            {
                $this->options->excerpt_intro = esc_attr($_POST['hidden_pmpro_seq_excerpt']);
                $this->dbgOut('save_settings(): POST value for settings->excerpt_intro: ' . esc_attr($_POST['hidden_pmpro_seq_excerpt']) );
            }
            else
                $this->options->excerpt_intro = 'A summary of the post follows below:';

            if ( isset($_POST['hidden_pmpro_seq_fromname']) )
            {
                $this->options->fromname = esc_attr($_POST['hidden_pmpro_seq_fromname']);
                $this->dbgOut('save_settings(): POST value for settings->fromname: ' . esc_attr($_POST['hidden_pmpro_seq_fromname']) );
            }
            else
                $this->options->fromname = pmpro_getOption('from_name');

            if ( isset($_POST['hidden_pmpro_seq_dateformat']) )
            {
                $this->options->dateformat = esc_attr($_POST['hidden_pmpro_seq_dateformat']);
                $this->dbgOut('save_settings(): POST value for settings->dateformat: ' . esc_attr($_POST['hidden_pmpro_seq_dateformat']) );
            }
            else
                $this->options->dateformat = __('m-d-Y', 'pmprosequence'); // Default is MM-DD-YYYY (if translation supports it)

            if ( isset($_POST['hidden_pmpro_seq_replyto']) )
            {
                $this->options->replyto = esc_attr($_POST['hidden_pmpro_seq_replyto']);
                $this->dbgOut('save_settings(): POST value for settings->replyto: ' . esc_attr($_POST['hidden_pmpro_seq_replyto']) );
            }
            else
                $this->options->replyto = pmpro_getOption('from_email');

            if ( isset($_POST['hidden_pmpro_seq_subject']) )
            {
                $this->options->subject = esc_attr($_POST['hidden_pmpro_seq_subject']);
                $this->dbgOut('save_settings(): POST value for settings->subject: ' . esc_attr($_POST['hidden_pmpro_seq_subject']) );
            }
            else
                $this->options->subject = __('New Content ', 'pmprosequence');

            // $sequence->options = $settings;
            if ( $this->options->sendNotice == 1 ) {

                $this->dbgOut( 'save_settings(): Updating the cron job for sequence ' . $this->sequence_id );

                if (! $this->updateNoticeCron() )
                    $this->dbgOut('save_settings() - Error configuring cron() system for sequence ' . $this->sequence_id, DEBUG_SEQ_CRITICAL);
            }

            // $this->dbgOut('save_settings() - Settings are now: ' . print_r($settings, true));

            // Save settings to WPDB
            return $this->save_sequence_meta($this->options, $sequence_id);
        }

        /********************************* Plugin Activation/Deactivation Functionality *******************/


        /**
         * Disable the WPcron job for the current sequence
         */
        public function stopSendingNotices() {

            $this->dbgOut("Removing alert notice hook for sequence # " . $this->sequence_id );

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
                    $this->dbgOut('Deactivated email alert(s) for sequence ' . $s->ID);
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

                $errorMessage = "The PMPro Sequence plugin requires the ";
                $errorMessage .= "<a href='http://www.paidmembershipspro.com/' target='_blank' title='Opens in a new window/tab.'>";
                $errorMessage .= "Paid Memberships Pro</a> membership plugin.<br/><br/>";
                $errorMessage .= "Please install Paid Memberships Pro before attempting to activate this PMPro Sequence plugin.<br/><br/>";
                $errorMessage .= "Click the 'Back' button in your browser to return to the Plugin management page.";
                wp_die($errorMessage);
            }

            PMProSequence::createCPT();
            flush_rewrite_rules();

            /* Search for existing pmpro_series posts & import */
            pmpro_sequence_import_all_PMProSeries();

            /* Register the default cron job to send out new content alerts */
            wp_schedule_event( current_time( 'timestamp' ), 'daily', 'pmpro_sequence_cron_hook' );

        }

        /**
         * Registers the Sequence Custom Post Type (CPT)
         *
         * @return bool -- True if successful
         *
         * @access public
         *
         */
        static public function createCPT() {

            // Not going to want to do this when deactivating
            global $pmpro_sequence_deactivating;

            if ( ! empty( $pmpro_sequence_deactivating ) ) {
                return false;
            }

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
                        'slug' => apply_filters('pmpro-sequence-cpt-slug', 'sequence'),
                        'with_front' => false
                    ),
                    'has_archive' => apply_filters('pmpro-sequence-cpt-archive-slug', 'sequences')
                )
            );

            if (! is_wp_error($error) )
                return true;
            else {
                PMProSequence::dbgOut('Error creating post type: ' . $error->get_error_message(), DEBUG_SEQ_CRITICAL);
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
                /* Admin Menu - 16px */
                #menu-posts-pmpro_sequence .wp-menu-image {
                    background: url("<?php echo PMPRO_SEQUENCE_PLUGIN_URL; ?>images/icon-sequence16-sprite.png") no-repeat 6px 6px !important;
                }
                #menu-posts-pmpro_sequence:hover .wp-menu-image, #menu-posts-pmpro_sequence.wp-has-current-submenu .wp-menu-image {
                    background-position: 6px -26px !important;
                }
                /* Post Screen - 32px */
                .icon32-posts-pmpro_sequence {
                    background: url("<?php echo PMPRO_SEQUENCE_PLUGIN_URL; ?>images/icon-sequence32.png") no-repeat left top !important;
                }
                @media
                only screen and (-webkit-min-device-pixel-ratio: 1.5),
                only screen and (   min--moz-device-pixel-ratio: 1.5),
                only screen and (     -o-min-device-pixel-ratio: 3/2),
                    /* only screen and (        min-device-pixel-ratio: 1.5), */
                only screen and (                min-resolution: 1.5dppx) {

                    /* Admin Menu - 16px @2x */
                    #menu-posts-pmpro_sequence .wp-menu-image {
                        background-image: url("<?php echo PMPRO_SEQUENCE_PLUGIN_URL; ?>images/icon-sequence16-sprite_2x.png") !important;
                        -webkit-background-size: 16px 48px;
                        -moz-background-size: 16px 48px;
                        background-size: 16px 48px;
                    }
                    /* Post Screen - 32px @2x */
                    .icon32-posts-pmpro_sequence {
                        background-image:url("<?php echo PMPRO_SEQUENCE_PLUGIN_URL; ?>images/icon-sequence32_2x.png") !important;
                        -webkit-background-size: 32px 32px;
                        -moz-background-size: 32px 32px;
                        background-size: 32px 32px;
                    }
                }
            </style>
        <?php
        }

        public function register_user_scripts() {

            $this->dbgOut("Running register_user_scripts()");

            global $e20r_sequence_editor_page;
            global $post;

            $foundShortcode = has_shortcode( $post->post_content, 'sequence_links');

            $this->dbgOut("'sequence_links' shortcode present? " . ( $foundShortcode ? 'Yes' : 'No') );

            if ( ( $foundShortcode ) || ( $this->getCurrentPostType() == 'pmpro_sequence' ) ) {

                $this->dbgOut("Loading client side javascript and CSS");

                wp_register_style( 'pmpro-sequence', PMPRO_SEQUENCE_PLUGIN_URL . 'css/pmpro_sequences.css' );
                wp_enqueue_style( "pmpro-sequence" );

                wp_register_script('pmpro-sequence-user', PMPRO_SEQUENCE_PLUGIN_URL . 'js/pmpro-sequences.js', array('jquery'), null, true);

                wp_localize_script('pmpro-sequence-user', 'pmpro_sequence',
                    array(
                        'ajaxurl' => admin_url('admin-ajax.php'),
                    )
                );
            }

        }

        public function register_admin_scripts() {

            $this->dbgOut("Running register_admin_scripts()");

            wp_register_script('select2', '//cdnjs.cloudflare.com/ajax/libs/select2/3.5.2/select2.min.js', array( 'jquery' ), '3.5.2' );
            wp_register_script('pmpro-sequence-admin', PMPRO_SEQUENCE_PLUGIN_URL . 'js/pmpro-sequences-admin.js', array( 'jquery', 'select2' ), null, true);


            wp_register_style( 'select2', '//cdnjs.cloudflare.com/ajax/libs/select2/3.5.2/select2.min.css', '', '3.5.2', 'screen');
            wp_register_style( 'pmpro-sequence', PMPRO_SEQUENCE_PLUGIN_URL . 'css/pmpro_sequences.css' );

            /* Localize ajax script */
            wp_localize_script('pmpro-sequence-admin', 'pmpro_sequence',
                array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'lang' => array(
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

            if ( ! $load_pmpro_sequence_script ) {
                return;
            }

            wp_print_scripts('pmpro-sequence-user');

        }

        /**
         * Load all JS & CSS for Admin page
         */
        function enqueue_admin_scripts( $hook ) {

            if ( $hook == 'edit.php' || $hook == 'post.php' || $hook == 'post-new.php' ) {

                $this->dbgOut("Loading admin scripts & styles for PMPro Sequence");
                $this->register_admin_scripts();
            }

            $this->dbgOut("End of loading admin scripts & styles");
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
            $pagesize = 10;
            $id = 0;
            $title = null;

            extract( shortcode_atts( array(
                'id' => 0,
                'pagesize' => 0,
                'title' => '',
                'button' => false,
                'highlight' => false,
                'scrollbox' => false,
            ), $attributes ) );

            if ( $pagesize == 0 ) {

                $pagesize = 15; // Default
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
            $this->dbgOut("We're given the ID of: {$id} ");

            $this->init( $id );

            $this->dbgOut("shortcode() - Ready to build link list for sequence with ID of: " . $id);

            if ( $this->hasAccess( $current_user->ID, $id, false ) ) {

                return $this->createSequenceList( $highlight, $pagesize, $button, $title, $scrollbox );
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
        public function ajaxUnprivError() {

            $this->dbgOut('Unprivileged ajax call attempted', DEBUG_SEQ_CRITICAL);

            wp_send_json_error( array(
                'message' => __('You must be logged in to edit PMPro Sequences', 'pmprosequence')
            ) );
        }
        /**
         * Loads actions & filters for the plugin.
         */
        public function load_actions()
        {

            // Load filters
            add_filter("pmpro_after_phpmailer_init", array(&$this, "email_body"));
            add_filter('pmpro_sequencepost_types', array(&$this, 'included_cpts'));

            add_filter("pmpro_has_membership_access_filter", array(&$this, "has_membership_access_filter"), 10, 4);
            add_filter("pmpro_non_member_text_filter", array(&$this, "text_filter"));
            add_filter("pmpro_not_logged_in_text_filter", array(&$this, "text_filter"));
            add_filter("the_content", array(&$this, "display_sequence_content"));

            // Add Custom Post Type
            add_action("init", array(&$this, "load_textdomain"), 9);
            add_action("init", array(&$this, "createCPT"), 10);
            add_action("init", array(&$this, "register_shortcodes"), 11);

//            add_action("init", array(&$this, "register_user_scripts") );
//            add_action("init", array(&$this, "register_admin_scripts") );

            // Add CSS & Javascript
            add_action("wp_enqueue_scripts", array(&$this, 'register_user_scripts'));
            add_action("wp_footer", array( &$this, 'enqueue_user_scripts') );

            add_action("admin_enqueue_scripts", array(&$this, 'enqueue_admin_scripts'));
            add_action('admin_head', array(&$this, 'post_type_icon'));

            // Load metaboxes for editor(s)
            add_action('add_meta_boxes', array(&$this, 'loadPostMetabox'));

            // Load add/save actions
            add_action('admin_notices', array(&$this, 'display_error'));
            // add_action( 'save_post', array( &$this, 'post_save_action' ) );
            add_action('post_updated', array(&$this, 'post_save_action'));

            add_action('admin_menu', array(&$this, "defineMetaBoxes"));
            add_action('save_post', array(&$this, 'savePostMeta'), 10, 2);

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
            add_action('wp_ajax_nopriv_pmpro_sequence_add_post', array(&$this, 'ajaxUnprivError'));
            add_action('wp_ajax_nopriv_pmpro_sequence_update_post_meta', array(&$this, 'ajaxUnprivError'));
            add_action('wp_ajax_nopriv_pmpro_rm_sequence_from_post', array(&$this, 'ajaxUnprivError'));
            add_action('wp_ajax_nopriv_pmpro_sequence_rm_post', array(&$this, 'ajaxUnprivError'));
            add_action('wp_ajax_nopriv_pmpro_sequence_clear', array(&$this, 'ajaxUnprivError'));
            add_action('wp_ajax_nopriv_pmpro_send_notices', array(&$this, 'ajaxUnprivError'));
            add_action('wp_ajax_nopriv_pmpro_sequence_save_user_optin', array(&$this, 'ajaxUnprivError'));
            add_action('wp_ajax_nopriv_pmpro_save_settings', array(&$this, 'ajaxUnprivError'));

        }

        /**
         * Returns the current post type of the post being processed by WP
         *
         * @return mixed | null - The post type for the current post.
         */
        private function getCurrentPostType() {

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
    }
