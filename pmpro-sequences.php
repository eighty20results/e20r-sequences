<?php
/*
Plugin Name: PMPro Sequence
Plugin URI: http://www.eighty20results.com/pmpro-series/
Description: Offer serialized (drip feed) content to your PMPro members. Based on the PMPro Series plugin by Stranger Studios. Renamed for namespace reasons.
Version: .1
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
	- An email is sent to the user letting them know that content is available.	
	
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

define('PMPROS_SEQUENCE_DEBUG', true);

/*
	Includes
*/
if (! class_exists( 'PMProSequences' )):
    require_once(dirname(__FILE__) . "/classes/class.pmprosequences.php");
endif;

/*
if (! class_exists('PMPros_Settings')):
    require_once(dirname(__FILE__) . "/classes/class.pmpros-settings.php");
endif;
*/

/*
	Load CSS, JS files
*/
function pmprors_scripts()
{
	if(!is_admin())
	{
		/*if(!defined("PMPRO_VERSION"))
		{*/
			//load some styles that we need from PMPro
			wp_enqueue_style("pmprors_pmpro", plugins_url('css/pmpro_sequences.css',__FILE__ ));
		/*}*/
	}

}
add_action("init", "pmprors_scripts");


/*
	PMPro Series CPT
*/
add_action("init", array("PMProSequences", "createCPT"));

/*
	Add the PMPro meta box and the meta box to add posts/pages to sequence
*/
add_action("init", array("PMProSequences", "checkForMetaBoxes"), 20);

/*
	Detect AJAX calls
*/
function pmpros_ajax()
{
    //

    if ( isset($_REQUEST['pmpros_add_post']) || isset($_REQUEST['pmpros_clear_series']) )
	{

        $sequence_id = intval($_REQUEST['pmpros_sequence']);

        if ($sequence_id == 0 )
        {
            global $wp_query;
            $sequence_id = $wp_query->post->ID;
        }

        $sequence = new PMProSequences($sequence_id);
        $sequence->dbgOut('Processing sequence # ' . $sequence_id);

        // Clear the sequence metadata if the series type (by date or by day count) changed.
        if (isset($_REQUEST['pmpros_clear_series']))
        {
            $sequence->dbgOut('Deleting all entries in sequence #' .$sequence_id);

            if (! delete_post_meta($sequence_id, '_post_sequence'))
            {
                $sequence->dbgOut('Unable to delete the sequence');
            }
        }


        $sequence->getPostListForMetaBox();
        exit;
    }
}
add_action("init", "pmpros_ajax");

function pmpro_sequence_ajaxResponse(){

    try{

    } catch (Exception $e){
        exit;
    }

    exit;
}
add_action('wp_ajax_pmpro_save_settings', 'pmpro_sequence_ajaxResponse');
/*
	Show list of sequence pages at end of sequence
*/
function pmpros_the_content($content)
{
	global $post;
	
	if($post->post_type == "pmpro_sequence" && pmpro_has_membership_access())
	{
		$sequence = new PMProSequences($post->ID);
        $sequence->fetchOptions( $post->ID );
        $settings = $sequence->getSettings();

        if ( $settings[1] == 1)
            $content .= "<p>You are on day " . intval(pmpro_getMemberDays()) . " of your membership.</p>";

		$content .= $sequence->getPostList();
	}
	
	return $content;
}
add_filter("the_content", "pmpros_the_content");

/*
	Make sure people can't view content they don't have access to.
*/
//returns true if a user has access to a page, including logic for sequence/delays
function pmpros_hasAccess($user_id, $post_id)
{
	//is this post in a sequence?
	$post_sequence = get_post_meta($post_id, "_post_sequence", true);
	if(empty($post_sequence))
		return true;		//not in a sequence
		
	//does this user have a level giving them access to everything?
	$all_access_levels = apply_filters("pmproap_all_access_levels", array(), $user_id, $post_id);	
	if(!empty($all_access_levels) && pmpro_hasMembershipLevel($all_access_levels, $user_id))
		return true;	//user has one of the all access levels
		
	//check each sequence
	foreach($post_sequence as $sequence_id)
	{
		//does the user have access to any of the sequence pages?
		$results = pmpro_has_membership_access($sequence_id, $user_id, true);	//passing true there to get the levels which have access to this page
		if($results[0])	//first item in array is if the user has access
		{
			//has the user been around long enough for any of the delays?
			$sequence_posts = get_post_meta($sequence_id, "_post_sequence", true);
			if(!empty($sequence_posts))
			{
				foreach($sequence_posts as $sp)
				{
					//this post we are checking is in this sequence
					if($sp->id == $post_id)
					{
						//check specifically for the levels with access to this sequence
						foreach($results[1] as $level_id)
						{
                            if ($this->options )
							if(pmpro_getMemberDays($user_id, $level_id) >= $sp->delay)
							{						
								return true;	//user has access to this sequence and has been around longer than this post's delay
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


/*
	Filter pmpro_has_membership_access based on sequence access.
*/
function pmpros_pmpro_has_membership_access_filter($hasaccess, $mypost, $myuser, $post_membership_levels)
{
	//If the user doesn't have access already, we won't change that. So only check if they already have access.
	if($hasaccess)
	{			
		//okay check if the user has access
		if(pmpros_hasAccess($myuser->ID, $mypost->ID))
			$hasaccess = true;
		else
		{
			$hasaccess = false;		
		}
	}
	
	return $hasaccess;
}
add_filter("pmpro_has_membership_access_filter", "pmpros_pmpro_has_membership_access_filter", 10, 4);

/*
	Filter the message for users without access.
*/
function pmpros_pmpro_text_filter($text)
{
	global $wpdb, $current_user, $post;
	
	if(!empty($current_user) && !empty($post))
	{
		if(!pmpros_hasAccess($current_user->ID, $post->ID))
		{						
			//Update text. The either have to wait or sign up.
			$post_sequence = get_post_meta($post->ID, "_post_sequence", true);
			
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
				$sequence = new PMProSequences($insequence);
				$day = $sequence->getDelayForPost($post->ID);
				$text = "This content is part of the <a href='" . get_permalink($insequence) . "'>" . get_the_title($insequence) . "</a> sequence. You will gain access on day " . $day . " of your membership.";
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
add_filter("pmpro_non_member_text_filter", "pmpros_pmpro_text_filter");
add_filter("pmpro_not_logged_in_text_filter", "pmpros_pmpro_text_filter");

/*
	Couple functions from PMPro in case we don't have them yet.
*/
if(!function_exists("pmpro_getMemberStartdate"))
{
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
            $days = $dDiff->days;

			$pmpro_member_days[$user_id][$level_id] = $days;
		}
		
		return $pmpro_member_days[$user_id][$level_id];
	}
}

add_action( 'admin_head', 'sequence_post_type_icon' );
 
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

/*
	We need to flush rewrite rules on activation/etc for the CPTs.
*/
function pmpros_activation() 
{	
	PMProSequences::createCPT();
    // PMPros_Settings::createDefaults();

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'pmpros_activation' );
function pmpros_deactivation() 
{	
	global $pmpros_deactivating;
	$pmpros_deactivating = true;
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'pmpros_deactivation' );
