<?php
/*
Plugin Name: PMPro Sequence
Plugin URI: http://www.eighty20results.com/pmpro-sequence/
Description: Offer serialized (drip feed) content to your PMPro members. Based on the PMPro Series plugin by Stranger Studios. Renamed for namespace reasons.
Version: .1.2
Author: Thomas Sjolshagen (Original and owned by Stranger Studios)
Author URI: http://www.eighty20results.com
*/

/*
	The Story
	
	1. There will be a new "Sequences" tab in the Memberships menu of the WP dashboard.
	2. Admins can create a new "Sequence".
	3. Admins can add a page or post to a sequence along with a # of days after signup (or a specific date)
	4. Admins can add a sequence to a membership level.
	5. Admins can adjust the email template via an added page to their active theme.
	
	Then...
	
	1. User signs up for a membership level that gives him access to Sequence A.
	2. User gets access to any "0 days after" (or on a specific date) sequence content.
	3. Each day a script checks if a user should gain access to any new content, if so:
	- User is given access to the content.
	- TODO: An email is sent to the user letting them know that content is available.
	
	Checking for access:
	* Is a membership level required?
	* If so, does the user have one of those levels?
	* Is the user's level "assigned" to a sequence?
	* If so, does the user have access to that content yet? (count days)
	* If not, then the user will have access. (e.g. Pro members get access to everything right away.)
	
	Checking to send emails: (planned feature)
	* For all members with sequence levels.
	* What day of the membership is it?
	* For all sequences.
	* Get content.
	* Send content for this day.
	* Email update.
	
	Data Structure
	* Sequence is a CPT
	* Use wp_pmpro_memberships_pages to link to membership levels
	* wp_pmpro_sequence_content (sequence_id, post_id, day) stored in post meta
*/

/* Enable / Disable DEBUG logging to separate file */
define('PMPRO_SEQUENCE_DEBUG', true);

/*
	Include the class for PMProSequences
*/
if (! class_exists( 'PMProSequences' )):
    require_once(dirname(__FILE__) . "/classes/class.pmprosequences.php");
	require_once(dirname(__FILE__) . "/scheduled/crons.php");
endif;

/*
	Load CSS, JS files
*/
if (! function_exists('pmpro_sequence_scripts')):

    add_action("init", "pmpror_sequence_scripts");

    function pmpror_sequence_scripts()
    {
	    wp_register_script('pmpro_sequence_script', plugins_url('js/pmpro-sequences.js',__FILE__), array('jquery'));

	    /* Localize ajax script */
	    wp_localize_script('pmpro_sequence_script', 'pmproSequenceAjax',
		    array(
			    'ajaxurl' => admin_url('admin-ajax.php'),
			    'pmproSequenceNonce' => wp_create_nonce('pmpro-sequence-send-settings')
		    )
	    );

	    wp_enqueue_style("pmpro_sequence_css", plugins_url('css/pmpro_sequences.css',__FILE__ ));
	    wp_enqueue_script('pmpro_sequence_script');

	    if(!is_admin())
        {
            /*if(!defined("PMPRO_VERSION"))
            {*/
                //load some styles that we need from PMPro
//                wp_enqueue_style("pmpro_sequence_pmpro", plugins_url('css/pmpro_sequences.css',__FILE__ ));
//	            wp_enqueue_script("pmpro_sequence_pmpro", plugins_url('js/pmpro_sequences.js', __FILE__), '', '0.1', true);
            /*}*/
        }

    }

endif;

if (! function_exists('pmpro_sequence_ajaxUnprivError')):
	/**
	 * Functions returns error message. Used by nopriv Ajax traps.
	 */
	function pmpro_sequence_ajaxUnprivError() {
		echo "Error: You must log in to edit PMPro Sequences";
		die();
	}
endif;

/*
	PMPro Sequence CPT
*/
add_action("init", array("PMProSequences", "createCPT"));

/*
	Add the PMPro meta box and the meta box to add posts/pages to sequence
*/
add_action("init", array("PMProSequences", "checkForMetaBoxes"), 20);

/*
	Detect AJAX calls
*/
if ( !function_exists( 'pmpro_sequence_ajax')):

    add_action("init", "pmpro_sequence_ajax");

    /**
     * Process AJAX based additions to the sequence list
     *
     * Returns 'error' message (or nothing, if success) to calling JavaScript function
     */
    function pmpro_sequence_ajax()
    {

        if ( isset($_REQUEST['pmpro_sequenceadd_post']) )
        {

            $sequence_id = intval($_REQUEST['pmpro_sequence_id']);

            if ($sequence_id == 0 )
            {
                global $wp_query;
                $sequence_id = $wp_query->post->ID;
            }

            $sequence = new PMProSequences($sequence_id);
            $sequence->fetchOptions();
            $sequence->dbgOut('Running Ajax add_post');
            $sequence->dbgOut('REQUEST: ' . print_r($_REQUEST, true));
            $status = $sequence->getPostListForMetaBox();

            if ( preg_match("/^Error/", $status) )
            {
                $sequence->dbgOut('getPostListForMetaBox() returned error');
                echo $status;
            }

            exit;
        }
    }


endif;

if ( ! function_exists( 'pmpro_sequence_ajaxClearPosts')):

    add_action('wp_ajax_pmpro_sequence_clear', 'pmpro_sequence_ajaxClearPosts');
	add_action('wp_ajax_nopriv_pmpro_sequence_clear', 'pmpro_sequence_ajaxUnprivError');

    /**
     * Catches ajax POSTs from dashboard/edit for CPT (clear existing sequence members)
     */
    function pmpro_sequence_ajaxClearPosts()
    {
	    // Validate that the ajax referrer is secure
	    check_ajax_referer('pmpro-sequence-send-settings', 'security');

	    // Clear the sequence metadata if the sequence type (by date or by day count) changed.
        if (isset($_POST['pmpro_sequence_clear']))
        {
            if (isset($_POST['pmpro_sequence_id']))
            {
                $sequence_id = intval($_POST['pmpro_sequence_id']);
                $sequence = new PMProSequences($sequence_id);
            }
            else
            {
                echo 'Error: Unable to identify the Sequence';
                exit;
            }

            if (! pmpro_sequence_settings_save($sequence_id, $sequence))
            {
                echo 'Error: Unable to save Sequence settings';
                exit;
            }

            $sequence->dbgOut('Deleting all entries in sequence # ' .$sequence_id);
            if (! delete_post_meta($sequence_id, '_sequence_posts'))
            {
                $sequence->dbgOut('Unable to delete the posts in sequence # ' . $sequence_id);
                echo 'Error: Unable to delete posts from sequence';
            }
            else
                $sequence->getPostListForMetaBox();
        }
        else
             echo 'Error: Unknown request';

    }

endif;

if (! function_exists('pmpro_sequence_optinsave')):

    add_action('wp_ajax_pmpro_sequence_save_user_optin', 'pmpro_sequence_optinsave');
    add_action('wp_ajax_nopriv_pmpro_sequence_save_user_optin', 'pmpro_sequence_ajaxUnprivError');

    function pmpro_sequence_optinsave()
    {
        global $current_user;

        $response = array();

        try {
	        check_ajax_referer('pmpro-sequence-user-optin', 'security');
	        $seq = new PMProSequences();
	        $seq->dbgOut('optinsave(): ' . print_r( $_POST, true));

	        $optIn = new stdClass();
	        $optIn->sequence = array();

	        // Settings for sequence(s) and data

	        if ( isset($_POST['pmpro_sequence_id'])) {

		        // Update setting for this sequence
		        $optIn->sequence[intval( $_POST['pmpro_sequence_id'] )] = array(
			        'sendNotice' => ( isset($_POST['pmpro_sequence_optIn']) ? intval($_POST['pmpro_sequence_optIn']) : $seq->options->sendNotice )
		        );

		        $seq = new PMProSequences( intval( $_POST['pmpro_sequence_id']) );
		        $seq->dbgOut('Updating user settings for sequence #: ' . $seq->sequence_id);
	        }
	        else {
		        $seq->dbgOut( 'No sequence number specified. Ignoring settings for user' );
		        $response = json_encode(array('success' => 'success'));
		        echo $response;
		        exit;
	        }

	        if ( isset($_POST['pmpro_sequence_userId'])) {
		        $user_id = intval($_POST['pmpro_sequence_userId']);
		        $seq->dbgOut('Updating user settings for user #: ' . $user_id);
	        }
	        else {
		        $seq->dbgOut( 'No user ID specified. Ignoring settings!' );
		        $response = json_encode(array('success' => 'success'));
		        echo $response;
		        exit;
	        }


	        $seq->dbgOut('User options: ' . print_r($optIn, true));

            // TODO: add save logic for update_user_option() for the opt-in values
            if ($user_id == $current_user->ID)
                if ( ! update_user_option($user_id, 'pmpro_sequence_notices', $optIn))
                    $seq->dbgOut('Error: Unable to save user options: ' . print_r($optIn, true));
            else {
                $seq->dbgOut('Error: Mismatched User IDs!');
                $response = json_encode(array('error' => 'Error saving settings'));
            }
        } catch (Exception $e) {
            // $response = array( 'result' => 'Error: ' . $e->getMessage());
            $response = json_encode(array('error' => 'Error: ' . $e->getMessage()));
        }

        echo $response;
        exit;
    }
endif;

if (! function_exists( 'pmpro_sequence_ajaxSaveSettings')):

    add_action('wp_ajax_pmpro_save_settings', 'pmpro_sequence_ajaxSaveSettings');
	add_action('wp_ajax_nopriv_pmpro_save_settings', 'pmpro_sequence_ajaxUnprivError');

    /**
     * Function to process Sequence Settings AJAX POST call (save operation)
     *
     * Returns 'success' or 'error' message to calling JavaScript function
     */
    function pmpro_sequence_ajaxSaveSettings()
    {
	    // Validate that the ajax referrer is secure
	    check_ajax_referer('pmpro-sequence-send-settings', 'security');
	    $response = array();

	    try{

            if ( isset($_POST['pmpro_sequence_id']) )
            {
                $sequence_id = intval($_POST['pmpro_sequence_id']);
                $sequence = new PMProSequences($sequence_id);

                if (pmpro_sequence_settings_save($sequence_id, $sequence))
	                $response = 'success';
	                // $response = array( 'result' => 'success' );

            }
		    else
			    $response = 'Error: No post ID specified';
		        // $response = array( 'result' => 'Error: No post ID specified');
        } catch (Exception $e) {
		    // $response = array( 'result' => 'Error: ' . $e->getMessage());
		    $response = 'Error: ' . $e->getMessage();
        }

	    // header('Content-Type: application/json');
        // echo json_encode($response);
	    echo $response;
	    exit;
    }


	/**
	 * Save the settings for a sequence ID as post_meta for that Sequence CPT
	 *
	 * @param $sequence_id -- ID of the sequence to save options for
	 * @param $sequenceObj -- stdObject containing configuration settings
	 * @return bool - Returns true if save is successful
	 */

	function pmpro_sequence_settings_save( $sequence_id, $sequenceObj )
	{

		$settings = $sequenceObj->fetchOptions($sequence_id);
		$sequenceObj->dbgOut('Saving settings for Sequence w/ID: ' . $sequence_id);

		$sequenceObj->dbgOut('Pre-Save settings are: ' . print_r($settings, true));
		$sequenceObj->dbgOut('POST: ' . print_r($_POST, true));

//	    $sequenceObj->pmpro_sequence_meta_save($sequence_id);
		// Check that the function was called correctly. If not, just return
		if(empty($sequence_id)) {
			$sequenceObj->dbgOut('pmpro_sequence_settings_save(): No sequence ID supplied...');
			return false;
		}

		// Verify that we're allowed to update the sequence data
		if ( !current_user_can( 'edit_post', $sequence_id ) ) {
			$sequenceObj->dbgOut('pmpro_sequence_settings_save(): User is not allowed to edit this post type');
			return $sequence_id;
		}

		$sequenceObj->dbgOut('pmpro_sequence_settings_save(): About to save settings for sequence ' . $sequence_id);
		$sequenceObj->dbgOut('From Web: ' . print_r($_REQUEST, true));

		$sequenceObj->dbgOut('Have to load new instance of Sequence class');

		if (!$settings)
			$settings = $sequenceObj->defaultOptions();

		// Checkbox - not included during post/save if unchecked
		if ( isset($_POST['hidden_pmpro_seq_future']) )
		{
			$settings->hidden = intval($_POST['hidden_pmpro_seq_future']);
			$sequenceObj->dbgOut('pmpro_sequence_settings_save(): POST value for settings->hidden: ' . $_POST['hidden_pmpro_seq_future'] );
		}
		elseif ( empty($settings->hidden) )
			$settings->hidden = 0;

		// Checkbox - not included during post/save if unchecked
		if (isset($_POST['hidden_pmpro_seq_lengthvisible']) )
		{
			$settings->lengthVisible = intval($_POST['hidden_pmpro_seq_lengthvisible']);
			$sequenceObj->dbgOut('pmpro_sequence_settings_save(): POST value for settings->lengthVisible: ' . $_POST['hidden_pmpro_seq_lengthvisible']);
		}
		elseif (empty($settings->lengthVisible)) {
			$sequenceObj->dbgOut('Setting lengthVisible to default value (checked)');
			$settings->lengthVisible = 1;
		}

		if ( isset($_POST['hidden_pmpro_seq_sortorder']) )
		{
			$settings->sortOrder = intval($_POST['hidden_pmpro_seq_sortorder']);
			$sequenceObj->dbgOut('pmpro_sequence_settings_save(): POST value for settings->sortOrder: ' . $_POST['hidden_pmpro_seq_sortorder'] );
		}
		elseif (empty($settings->sortOrder))
			$settings->sortOrder = SORT_ASC;

		if ( isset($_POST['hidden_pmpro_seq_delaytype']) )
		{
			$settings->delayType = esc_attr($_POST['hidden_pmpro_seq_delaytype']);
			$sequenceObj->dbgOut('pmpro_sequence_settings_save(): POST value for settings->delayType: ' . esc_attr($_POST['hidden_pmpro_seq_delaytype']) );
		}
		elseif (empty($settings->delayType))
			$settings->delayType = 'byDays';

		if ( isset($_POST['hidden_pmpro_seq_startwhen']) )
		{
			$settings->startWhen = esc_attr($_POST['hidden_pmpro_seq_startwhen']);
			$sequenceObj->dbgOut('pmpro_sequence_settings_save(): POST value for settings->startWhen: ' . esc_attr($_POST['hidden_pmpro_seq_startwhen']) );
		}
		elseif (empty($settings->startWhen))
			$settings->startWhen = 0;

		// Checkbox - not included during post/save if unchecked
		if ( isset($_POST['hidden_pmpro_seq_sendnotice']) )
		{
			$settings->sendNotice = intval($_POST['hidden_pmpro_seq_sendnotice']);
			$sequenceObj->dbgOut('pmpro_sequence_settings_save(): POST value for settings->sendNotice: ' . intval($_POST['hidden_pmpro_seq_sendnotice']) );
		}
		elseif (empty($settings->sendNotice)) {
			$settings->sendNotice = 1;
		}

		if ( isset($_POST['hidden_pmpro_seq_noticetemplate']) )
		{
			$settings->noticeTemplate = esc_attr($_POST['hidden_pmpro_seq_noticetemplate']);
			$sequenceObj->dbgOut('pmpro_sequence_settings_save(): POST value for settings->noticeTemplate: ' . esc_attr($_POST['hidden_pmpro_seq_noticetemplate']) );
		}
		else
			$settings->noticeTemplate = 'new_content.html';

		if ( isset($_POST['hidden_pmpro_seq_noticetime']) )
		{
			$settings->noticeTime = esc_attr($_POST['hidden_pmpro_seq_noticetime']);
			$sequenceObj->dbgOut('pmpro_sequence_settings_save() - noticeTime in settings: ' . $settings->noticeTime);

			/* Calculate the timestamp value for the noticeTime specified (noticeTime is in current timezone) */
			$settings->noticeTimestamp = $sequenceObj->calculateTimestamp($settings->noticeTime);

			$sequenceObj->dbgOut('pmpro_sequence_settings_save(): POST value for settings->noticeTime: ' . esc_attr($_POST['hidden_pmpro_seq_noticetime']) );
		}
		else
			$settings->noticeTime = '00:00';

        if ( isset($_POST['hidden_pmpro_seq_excerpt']) )
        {
            $settings->excerpt_intro = esc_attr($_POST['hidden_pmpro_seq_excerpt']);
            $sequenceObj->dbgOut('pmpro_sequence_settings_save(): POST value for settings->excerpt_intro: ' . esc_attr($_POST['hidden_pmpro_seq_excerpt']) );
        }
        else
            $settings->excerpt_intro = 'A summary of the post follows below:';

		// $sequence->options = $settings;
		if ( $settings->sendNotice == 1 ) {
			$sequenceObj->dbgOut( 'pmpro_sequence_meta_save(): Updating the cron job for sequence ' . $sequenceObj->sequence_id );
			$sequenceObj->updateNoticeCron( $sequenceObj->sequence_id );
		}

		$sequenceObj->dbgOut('pmpro_sequence_settings_save() - Settings are now: ' . print_r($settings, true));

		// Save settings to WPDB
		return $sequenceObj->save_sequence_meta($settings, $sequence_id);
	}
endif;

if ( ! function_exists( 'pmpro_sequence_content' )):

    add_filter("the_content", "pmpro_sequence_content");

    /**
     *
     * Show list of sequence pages at the bottom of the sequence page
     *
     * @param $content -- The content to process as part of the filter action
     * @return string -- The filtered content
     */
    function pmpro_sequence_content($content)
    {
        global $post;

        if ( ( $post->post_type == "pmpro_sequence" ) && pmpro_has_membership_access() )
        {
            $sequence = new PMProSequences($post->ID);
            $settings = $sequence->fetchOptions();

            // If we're supposed to show the "days of membership" information, adjust the text for type of delay.
            if ( intval($settings->lengthVisible) == 1 )
                $content .= "<p>You are on day " . intval(pmpro_getMemberDays()) . " of your membership.</p>";

	        if ( intval($settings->sendNotice) == 1)
		        $content .= $sequence->pmpro_sequence_addUserNoticeOptIn( $sequence );

            // Add the list of posts in the sequence to the content.
            $content .= $sequence->getPostList();
        }

        return $content;
    }
endif;



if ( ! function_exists( 'pmpro_sequence_hasAccess')):

    /**
     * Check the whether the User ID has access to the post ID
     * Make sure people can't view content they don't have access to.
     *
     * @param $user_id (int) -- The users ID to check access for
     * @param $post_id (int) -- The ID of the post we're checking access for
     * @return bool -- true | false -- Indicates user ID's access privileges to the post/sequence
     */
    function pmpro_sequence_hasAccess($user_id, $post_id)
    {
        //is this post in a sequence?
        $post_sequence = get_post_meta($post_id, "_post_sequences", true);
        if(empty($post_sequence))
            return true;		//not in a sequence

        //does this user have a level giving them access to everything?
        $all_access_levels = apply_filters("pmproap_all_access_levels", array(), $user_id, $post_id);
        if(!empty($all_access_levels) && pmpro_hasMembershipLevel($all_access_levels, $user_id))
            return true;	//user has one of the all access levels

        /**
         * BUG: Assumed that $post_id == the sequence ID, but
         * it's not when filtering the actual sequence member
         */

        $tmpSequence = new PMProSequences($post_sequence[0]);
        $tmpSequence->fetchOptions();
        $tmpSequence->dbgOut('pmpro_sequence_hasAccess() - Sequence ID: ' . print_r($post_sequence[0], true));

	    /** Test to see what happens if user has multiple membership levels
	     *
	     * $user_levels = pmpro_getMembershipLevelsForUser($user_id);
	     * $tmpSequence->dbgOut('pmpro_sequence_hasAccess() - User (ID: ' . $user_id .') has paid for: ' . print_r($user_levels, true))
	     *
		 */

        //check each sequence
        foreach($post_sequence as $sequence_id)
        {
            //does the user have access to any of the sequence pages?
            $results = pmpro_has_membership_access($sequence_id, $user_id, true); //Using true for levels having access to page

	        $tmpSequence->dbgOut('Returned from access check: ' . print_r($results, true));

            if($results[0])	// First item in results array == true if user has access
            {
                $tmpSequence->dbgOut('pmpro_sequence_hasAccess() - User has membership level that sequence requires');
                //has the user been around long enough for any of the delays?
                $sequence_posts = get_post_meta($sequence_id, "_sequence_posts", true);

                // $tmpSequence->dbgOut('Fetched PostMeta: ' . print_r($sequence_posts, true));

                if(!empty($sequence_posts))
                {
                    foreach($sequence_posts as $sp)
                    {
                        // $tmpSequence->dbgOut('Checking post for access - contains: ' . print_r($sp, true));
                        //this post we are checking is in this sequence
                        if($sp->id == $post_id)
                        {
                            //check specifically for the levels with access to this sequence
                            foreach($results[1] as $level_id)
                            {
                                $tmpSequence->dbgOut('pmpro_sequence_hasAccess() - Testing delay type for level (ID: ' . $level_id . ')');

                                if ($tmpSequence->options->delayType == 'byDays')
                                {
                                    $tmpSequence->dbgOut('Delay Type is # of days since membership start');
                                    // BUG: Assumes the # of days is the right ay to
                                    if(pmpro_getMemberDays($user_id, $level_id) >= $sp->delay)
                                        return true;	//user has access to this sequence and has been a member for longer than this post's delay
                                }
                                elseif ($tmpSequence->options->delayType == 'byDate')
                                {
                                    $tmpSequence->dbgOut('Delay Type is a fixed date');
                                    $today = date('Y-m-d');
                                    $tmpSequence->dbgOut('Today: ' . $today . ' and delay: ' . $sp->delay);

                                    if ($today >= $sp->delay)
                                        return true;
                                }
                            }
                        }
                    }
                }
            }
        }

        //haven't found anything yet. so must not have access
        return false;
    }

endif;

if ( ! function_exists( 'pmpro_sequence_pmpro_has_membership_access_filter' ) ):

add_filter("pmpro_has_membership_access_filter", "pmpro_sequence_pmpro_has_membership_access_filter", 10, 4);

    /*
        Filter pmpro_has_membership_access based on sequence access.
    */
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
    function pmpro_sequence_pmpro_has_membership_access_filter($hasaccess, $mypost, $myuser, $post_membership_levels)
    {
        //If the user doesn't have access already, we won't change that. So only check if they already have access.
        if($hasaccess)
        {
            //okay check if the user has access
            if(pmpro_sequence_hasAccess($myuser->ID, $mypost->ID))
                $hasaccess = true;
            else
            {
                $hasaccess = false;
            }
        }

        return $hasaccess;
}

endif;

if ( ! function_exists( 'pmpro_seuquence_pmpro_text_filter' )):

    add_filter("pmpro_non_member_text_filter", "pmpro_seuquence_pmpro_text_filter");
    add_filter("pmpro_not_logged_in_text_filter", "pmpro_seuquence_pmpro_text_filter");

    /**
     * Filter the message for users without access.
     *
     * @param $text (string) -- The text to filter
     * @return string -- the filtered text
     */
    function pmpro_seuquence_pmpro_text_filter($text)
    {
        global $wpdb, $current_user, $post;

        if(!empty($current_user) && !empty($post))
        {
            if(!pmpro_sequence_hasAccess($current_user->ID, $post->ID))
            {
                $sequence = new PMProSequences($post->ID);

                //Update text. The either have to wait or sign up.
                $post_sequence = get_post_meta($post->ID, "_post_sequences", true);
                $sequence->dbgOut('Post Sequence Dump: ' . print_r($post_sequence, true));

                $insequence = false;
                foreach($post_sequence as $ps)
                {
                    if(pmpro_has_membership_access($ps))
                    {
                        $insequence = $ps;
                        break;
                    }
                }

                if($insequence)
                {
                    //user has one of the sequence levels, find out which one and tell him how many days left

                    $day = $sequence->getDelayForPost($post->ID);
                    $sequence->dbgOut('# of days worth of delay: ' . $day);

                    $text = "This content is part of the <a href='" . get_permalink($post->ID) . "'>" . get_the_title($post->ID) . "</a> sequence. You will gain access on day " . $day . " of your membership.";
                }
                else
                {
                    // User has to sign up for one of the sequence(s)
                    if(count($post_sequence) == 1)
                    {
                        $text = "This content is part of the <a href='" . get_permalink($post_sequence[0]) . "'>" . get_the_title($post_sequence[0]) . "</a> sequence.";
                    }
                    else
                    {
                        $text = "This content is part of the following sequences: ";
                        $seq_links = array();

                        foreach($post_sequence as $sequence_id) {
                            $seq_links[] = "<a href='" . get_permalink($sequence_id) . "'>" . get_the_title($sequence_id) . "</a>";
                        }

                        $text .= implode(" and ", $seq_links) . ".";
                    }
                }
            }
        }

        return $text;
    }
endif;

/**
 * Filter to replace the !!excerpt_intro!! variable content in a "new content alert" message.
 */
if ( ! function_exists('pmpro_sequence_email_body')):

	add_filter("pmpro_after_phpmailer_init", "pmpro_sequence_email_body");

	function pmpro_sequence_email_body( $phpmailer )
	{
		$phpmailer->Body = str_replace("!!excerpt_intro!!", $phpmailer->excerpt_intro, $phpmailer->Body );
	}
endif;

if ( ! function_exists( 'pmpro_sequence_datediff') ):

	// TODO: Create a function that supports datediff functionality if PHP < 5.3.0
	function pmpro_sequence_datediff( $start, $end )
	{
		global $wpdb;

		// $startDate = date_time_set( $start );

		//$sql = "SELECT DATEDIFF( '" . $startDate . '", "' . $endDate . "');";
	}
endif;
/*
	Couple functions from PMPro in case we don't have them loaded yet.
*/
if( ! function_exists("pmpro_getMemberStartdate") ):

	/*
		Get a member's start date... either in general or for a specific level_id.
	*/

    /**
     *
     * Returns the member's start date (either generally speaking or for a specific level)
     *
     * @param $user_id (int) - ID of user who's start date we're finding
     * @param $level_id (int) - ID of the level to find the start date for (optional)
     *
     * @returns mixed - The start date for this user_id at the specific level_id (or in general)
     */
    function pmpro_getMemberStartdate($user_id = NULL, $level_id = 0)
	{		
		if(empty($user_id))
		{
			global $current_user;
			$user_id = $current_user->ID;
		}

		global $pmpro_startdates;	//for cache
		if(empty($pmpro_startdates[$user_id][$level_id]))
		{			
			global $wpdb;
			
			if(!empty($level_id))
				$sqlQuery = "SELECT UNIX_TIMESTAMP(startdate) FROM $wpdb->pmpro_memberships_users WHERE status = 'active' AND membership_id IN(" . $wpdb->escape($level_id) . ") AND user_id = '" . $user_id . "' ORDER BY id LIMIT 1";		
			else
				$sqlQuery = "SELECT UNIX_TIMESTAMP(startdate) FROM $wpdb->pmpro_memberships_users WHERE status = 'active' AND user_id = '" . $user_id . "' ORDER BY id LIMIT 1";
				
			$startdate = apply_filters("pmpro_member_startdate", $wpdb->get_var($sqlQuery), $user_id, $level_id);
			
			$pmpro_startdates[$user_id][$level_id] = $startdate;
		}
		
		return $pmpro_startdates[$user_id][$level_id];
	}

    /**
     * Calculate the # of days since the membership level (or membership in general) was started for a specific user_id
     *
     * @param int $user_id -- user_id to calculate # of days since membership start for
     * @param int $level_id -- level_id to calculate the # of days for
     * @return int -- Number of days since user_id started their membership (at this level)
     */
    function pmpro_getMemberDays($user_id = NULL, $level_id = 0)
	{
		if(empty($user_id))
		{
			global $current_user;
			$user_id = $current_user->ID;
		}
		
		global $pmpro_member_days;

		if(empty($pmpro_member_days[$user_id][$level_id]))
		{		
			$startdate = pmpro_getMemberStartdate($user_id, $level_id);
		/**
		    Removed to support TZ transitions and whole days

			$now = time();
			$days = ($now - $startdate)/3600/24;
		**/

			// Check that there is a start date at all
			if(empty($startdate))
				$days = 0;
			else
			{

				/* Will take Daylight savings changes into account and ensure only integer value days returned */
				$dStart = new DateTime( date( 'Y-m-d', $startdate ) );
				$dEnd   = new DateTime( date( 'Y-m-d' ) ); // Today's date
				$dDiff  = $dStart->diff( $dEnd );
				$dDiff->format( '%d' );
				// $dDiff->format('%R%a');

				$days = $dDiff->days;

				if ( $dDiff->invert == 1 )
					$days = 0 - $days; // Invert the value
			}

			$pmpro_member_days[$user_id][$level_id] = $days;
		}
		
		return $pmpro_member_days[$user_id][$level_id];
	}

endif;

if ( ! function_exists( 'sequence_post_type_icon' )):

    add_action( 'admin_head', 'sequence_post_type_icon' );

    /**
     * Configure & display the icon for the Sequence Post type (in the Dashboard)
     */
    function sequence_post_type_icon() {
        ?>
        <style>
            /* Admin Menu - 16px */
            #menu-posts-pmpro_sequence .wp-menu-image {
                background: url(<?php echo plugins_url('images/icon-sequence16-sprite.png', __FILE__); ?>) no-repeat 6px 6px !important;
            }
            #menu-posts-pmpro_sequence:hover .wp-menu-image, #menu-posts-pmpro_sequence.wp-has-current-submenu .wp-menu-image {
                background-position: 6px -26px !important;
            }
            /* Post Screen - 32px */
            .icon32-posts-pmpro_sequence {
                background: url(<?php echo plugins_url('images/icon-sequence32.png', __FILE__); ?>) no-repeat left top !important;
            }
            @media
            only screen and (-webkit-min-device-pixel-ratio: 1.5),
            only screen and (   min--moz-device-pixel-ratio: 1.5),
            only screen and (     -o-min-device-pixel-ratio: 3/2),
            only screen and (        min-device-pixel-ratio: 1.5),
            only screen and (                min-resolution: 1.5dppx) {

                /* Admin Menu - 16px @2x */
                #menu-posts-pmpro_sequence .wp-menu-image {
                    background-image: url(<?php echo plugins_url('images/icon-sequence16-sprite_2x.png', __FILE__); ?>) !important;
                    -webkit-background-size: 16px 48px;
                    -moz-background-size: 16px 48px;
                    background-size: 16px 48px;
                }
                /* Post Screen - 32px @2x */
                .icon32-posts-pmpro_sequence {
                    background-image:url(<?php echo plugins_url('images/icon-sequence32_2x.png', __FILE__); ?>) !important;
                    -webkit-background-size: 32px 32px;
                    -moz-background-size: 32px 32px;
                    background-size: 32px 32px;
                }
            }
        </style>
    <?php }
endif;

if ( ! function_exists('pmpro_sequence_activation')):

    register_activation_hook( __FILE__, 'pmpro_sequence_activation' );

    /*
        We need to flush rewrite rules on activation/etc for the CPTs.
    */
    function pmpro_sequence_activation()
    {
        PMProSequences::createCPT();
        flush_rewrite_rules();

	    /* Register Cron job for new content check */
	    // TODO: Use per-sequence meta to identify time of cron job. (Set Daily/Weekly or hourly)
	    // Set time (what time) to run this cron job the first time.
	    wp_schedule_event(time(), 'daily', 'pmpro_sequence_check_for_new_content');
    }

endif;

if ( ! function_exists( 'pmpro_sequence_deactivation' )):

    register_deactivation_hook( __FILE__, 'pmpro_sequence_deactivation' );

    function pmpro_sequence_deactivation()
    {
        global $pmpros_deactivating;
        $pmpros_deactivating = true;
        flush_rewrite_rules();

	    /* Unregister Cron job for new content check */

	    wp_clear_scheduled_hook(time(), 'daily', 'pmpro_sequence_check_for_new_content');
    }
endif;


// register_activation_hook(__FILE__, 'pmpros_activation');
// register_deactivation_hook(__FILE__, 'pmpros_deactivation');

if ( ! function_exists('pmpro_sequence_member_links_bottom')):

	add_action('pmpro_member_links_bottom', 'pmpro_sequence_member_links_bottom');

	/**
	 * Add series post links to the account page for the user.
	 */

	function pmpro_sequence_member_links_bottom() {
		global $wpdb, $current_user;

		//get all series
		$seqs = $wpdb->get_results("
	        SELECT *
	        FROM $wpdb->posts
	        WHERE post_type = 'pmpro_sequence'
	    ");

		foreach($seqs as $s)
		{
			$sequence = new PMProSequences($s->ID);
			// TODO: Check whether this sequence is configured to send out notices on new content.

			$sequence_posts = $sequence->getPosts();

			foreach($sequence_posts as $sequence_post)
			{
				if(pmpro_sequence_hasAccess($current_user->user_id, $sequence_post->id))
				{
					?>
					<li><a href="<?php echo get_permalink($sequence_post->id); ?>" title="<?php echo get_the_title($sequence_post->id); ?>"><?php echo get_the_title($sequence_post->id); ?></a></li>
				<?php
				}
			}
		}
	}

endif;