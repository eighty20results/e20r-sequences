<?php
/*
Plugin Name: PMPro Sequence
Plugin URI: http://www.eighty20results.com/pmpro-sequence/
Description: Offer serialized (drip feed) content to your PMPro members. Based on the PMPro Series plugin by Stranger Studios. Renamed for namespace reasons.
Version: .1.1
Author: Thomas Sjolshagen
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

define('PMPRO_SEQUENCE_DEBUG', true);

/*
	Includes
*/
if (! class_exists( 'PMProSequences' )):
    require_once(dirname(__FILE__) . "/classes/class.pmprosequences.php");
endif;

/*
	Load CSS, JS files
*/
if (! function_exists('pmpro_sequence_scripts')):

    add_action("init", "pmpror_sequence_scripts");

    function pmpror_sequence_scripts()
    {
        if(!is_admin())
        {
            /*if(!defined("PMPRO_VERSION"))
            {*/
                //load some styles that we need from PMPro
                wp_enqueue_style("pmpro_sequence_pmpro", plugins_url('css/pmpro_sequences.css',__FILE__ ));
            /*}*/
        }

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
     * Process additions to
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

    /**
     * Catches ajax POSTs from dashboard/edit for CPT (clear existing sequence members)
     */
    function pmpro_sequence_ajaxClearPosts()
    {
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

    /**
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

        if (isset($_POST['pmpro_sequence_hidden']))
        {
            $settings->hidden = intval($_POST['pmpro_sequence_hidden']);
        }
        if (isset($_POST['pmpro_sequence_daycount']))
        {
            $settings->dayCount = intval($_POST['pmpro_sequence_daycount']);
        }
        if (isset($_POST['pmpro_sequence_sortorder']))
        {
            $settings->sortOrder = intval($_POST['pmpro_sequence_sortorder']);
        }
        if (isset($_POST['pmpro_sequence_delaytype']))
        {
            $settings->delayType = esc_attr($_POST['pmpro_sequence_delaytype']);
        }
        if (isset($_POST['pmpro_sequence_startwhen']))
        {
            $settings->startWhen = esc_attr($_POST['pmpro_sequence_startwhen']);
        }

        $sequenceObj->dbgOut('Settings are now: ' . print_r($settings, true));

        return $sequenceObj->save_sequence_meta($settings, $sequence_id);
    }

endif;

if (! function_exists( 'pmpro_sequence_ajaxSaveSettings')):

    add_action('wp_ajax_pmpro_save_settings', 'pmpro_sequence_ajaxSaveSettings');

    /**
     * Function to process Sequence Settings AJAX POST call (save operation)
     *
     */
    function pmpro_sequence_ajaxSaveSettings()
    {
        try{

            if ( isset($_POST['pmpro_sequence_id']) )
            {
                $sequence_id = intval($_POST['pmpros_sequence_id']);
                $sequence = new PMProSequences($sequence_id);

                if (pmpro_sequence_settings_save($sequence_id, $sequence))
                    echo 'success';
            }

        } catch (Exception $e){
            exit;
        }

        exit;
    }

endif;


if ( ! function_exists( 'pmpro_sequence_content' )):

    add_filter("the_content", "pmpro_sequence_content");

    /*
     * Show list of sequence pages at the bottom of the sequence page
     */
    function pmpro_sequence_content($content)
    {
        global $post;

        if ( ( $post->post_type == "pmpro_sequence" ) && pmpro_has_membership_access() )
        {
            $sequence = new PMProSequences($post->ID);
            $settings = $sequence->fetchOptions();

            if ( intval($settings->dayCount) == 1)
                $content .= "<p>You are on day " . intval(pmpro_getMemberDays()) . " of your membership.</p>";

            $content .= $sequence->getPostList();
        }

        return $content;
    }
endif;

if ( ! function_exists( ' pmpro_sequence_hasAccess')):
    /*
        Make sure people can't view content they don't have access to.
    */
    //returns true if a user has access to a page, including logic for sequence/delays
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

        $tmpSequence = new PMProSequences($post_id);
        $tmpSequence->fetchOptions();
        $tmpSequence->dbgOut('pmpro_sequence_hasAccess() - Sequence ID: ' . print_r($post_id, true));

        //check each sequence
        foreach($post_sequence as $sequence_id)
        {
            //does the user have access to any of the sequence pages?
            $results = pmpro_has_membership_access($sequence_id, $user_id, true); //Using true for levels having access to page

            if($results[0])	// First item in results array == true if user has access
            {
                $tmpSequence->dbgOut('pmpro_sequence_hasAccess() - User has membership level that sequence requires');
                //has the user been around long enough for any of the delays?
                $sequence_posts = get_post_meta($sequence_id, "_sequence_posts", true);

                $tmpSequence->dbgOut('Fetched PostMeta: ' . print_r($sequence_posts, true));

                if(!empty($sequence_posts))
                {
                    foreach($sequence_posts as $sp)
                    {
                        $tmpSequence->dbgOut('Checking post for access - contains: ' . print_r($sp, true));
                        //this post we are checking is in this sequence
                        if($sp->id == $post_id)
                        {
                            //check specifically for the levels with access to this sequence
                            foreach($results[1] as $level_id)
                            {
                                $tmpSequence->dbgOut('pmpro_sequence_hasAccess() - Testing for delay type...');

                                if ($tmpSequence->options->delayType == 'byDays')
                                {
                                    $tmpSequence->dbgOut('Delay Type is # of days since membership start');
                                    // BUG: Assumes the # of days is the right ay to
                                    if(pmpro_getMemberDays($user_id, $level_id) >= $sp->delay)
                                    {
                                        return true;	//user has access to this sequence and has been around longer than this post's delay
                                    }
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

    /*
        Filter the message for users without access.
    */
    function pmpro_seuquence_pmpro_text_filter($text)
    {
        global $wpdb, $current_user, $post;

        if(!empty($current_user) && !empty($post))
        {
            if(!pmpro_sequence_hasAccess($current_user->ID, $post->ID))
            {
                //Update text. The either have to wait or sign up.
                $post_sequence = get_post_meta($post->ID, "_post_sequences", true);

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
                    $sequence = new PMProSequences($post->ID);

                    $day = $sequence->getDelayForPost($insequence->id);
                    $sequence->dbgOut('# of days worth of delay: ' . $day);

                    $text = "This content is part of the <a href='" . get_permalink($post->ID) . "'>" . get_the_title($post->ID) . "</a> sequence. You will gain access on day " . $day . " of your membership.";
                }
                else
                {
                    //user has to sign up for one of the sequence
                    if(count($post_sequence) == 1)
                    {
                        $text = "This content is part of the <a href='" . get_permalink($post_sequence[0]) . "'>" . get_the_title($post_sequence[0]) . "</a> sequence.";
                    }
                    else
                    {
                        $text = "This content is part of the following sequence: ";
                        $sequence = array();
                        foreach($post_sequence as $sequence_id)
                            $sequence[] = "<a href='" . get_permalink($sequence_id) . "'>" . get_the_title($sequence_id) . "</a>";
                        $text .= implode(", ", $sequence) . ".";
                    }
                }
            }
        }

        return $text;
    }
endif;

/*
	Couple functions from PMPro in case we don't have them yet.
*/
if( ! function_exists("pmpro_getMemberStartdate") ):

	/*
		Get a member's start date... either in general or for a specific level_id.
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

            /* Will take Daylight savings changes into account and ensure only integer value days returned */
            $dStart = new DateTime( date('Y-m-d', $startdate) );
            $dEnd = new DateTime( date('Y-m-d') ); // Today's date
            $dDiff = $dStart->diff($dEnd);
            $dDiff->format('%d');
            // $dDiff->format('%R%a');

            $days = $dDiff->days;

            if ($dDiff->invert == 1)
                $days = 0 - $days; // Invert the value

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
    }

endif;

if ( ! function_exists( 'pmpro_sequence_deactivation' )):

    register_deactivation_hook( __FILE__, 'pmpro_sequence_deactivation' );

    function pmpro_sequence_deactivation()
    {
        global $pmpros_deactivating;
        $pmpros_deactivating = true;
        flush_rewrite_rules();
    }
endif;
