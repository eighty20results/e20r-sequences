<?php
namespace E20R\Sequences\Tools;

/*
  License:

	Copyright 2014-2016 Eighty / 20 Results by Wicked Strong Chicks, LLC (thomas@eighty20results.com)

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

use E20R\Sequences as Sequences;
use E20R\Sequences\Tools as Tools;
use E20R\Tools as E20RTools;

class Cron
{
    // Refers to a single instance of this class.
    private static $_this = null;
/**
     * Job constructor.
     */
    function __construct()
    {
        if (null !== self::$_this) {
            $error_message = sprintf(__("Attempted to load a second instance of a singleton class (%s)", "e20rsequence"),
                get_class($this)
            );

            error_log($error_message);
            wp_die( $error_message);
        }

        self::$_this = $this;

        add_action('e20r_sequence_cron_hook', array(apply_filters("get_cron_class_instance", null), 'check_for_new_content'), 10, 1);
    }

    static public function schedule_default() {

        $existing = wp_get_schedule("e20r_sequence_cron_hook");
        $old = wp_get_schedule("pmpro_sequence_cron_hook");

        if ( false !== $existing ) {
            wp_clear_scheduled_hook('e20r_sequence_cron_hook');
            wp_schedule_event( current_time( 'timestamp' ), 'daily', "e20r_sequence_cron_hook" );
        }

        if (( false !== $old ) && (!class_exists("PMProSequence")) ) {
            wp_clear_scheduled_hook('pmpro_sequence_cron_hook');
        }
    }

    /**
     * Update the when we're supposed to run the New Content Notice cron job for this sequence.
     *
     * @access public
     */
    static public function update_user_notice_cron() {

        /* TODO: Does not support Daylight Savings Time (DST) transitions well! - Update check hook in init? */

        $sequence = apply_filters('get_sequence_class_instance', null);

        $prevScheduled = false;
        try {

            // Check if the job is previously scheduled. If not, we're using the default cron schedule.
            if (false !== ($timestamp = wp_next_scheduled( 'e20r_sequence_cron_hook', array($sequence->sequence_id) ) )) {

                // Clear old cronjob for this sequence
                E20RTools\DBG::log('Current cron job for sequence # ' . $sequence->sequence_id . ' scheduled for ' . $timestamp, 'e20r-sequences');
                $prevScheduled = true;

                // wp_clear_scheduled_hook($timestamp, 'e20r_sequence_cron_hook', array( $this->sequence_id ));
            }

            E20RTools\DBG::log(' Next scheduled at (timestamp): ' . print_r(wp_next_scheduled('e20r_sequence_cron_hook', array($sequence->sequence_id)), true));

            // Set time (what time) to run this cron job the first time.
            E20RTools\DBG::log(' Alerts for sequence #' . $sequence->sequence_id . ' at ' . date('Y-m-d H:i:s', $sequence->options->noticeTimestamp) . ' UTC');

            if  ( ($prevScheduled) &&
                ($sequence->options->noticeTimestamp != $timestamp) ) {

                E20RTools\DBG::log(' Admin changed when the job is supposed to run. Deleting old cron job for sequence w/ID: ' . $sequence->sequence_id);
                wp_clear_scheduled_hook( 'e20r_sequence_cron_hook', array($sequence->sequence_id) );

                // Schedule a new event for the specified time
                if ( false === wp_schedule_event(
                        $sequence->options->noticeTimestamp,
                        'daily',
                        'e20r_sequence_cron_hook',
                        array( $sequence->sequence_id )
                    )) {

                    $sequence->set_error_msg( printf( __('Could not schedule new content alert for %s', "e20rsequence"), $sequence->options->noticeTime) );
                    E20RTools\DBG::log(" Did not schedule the new cron job at ". $sequence->options->noticeTime . " for this sequence (# " . $sequence->sequence_id . ')');
                    return false;
                }
            }
            elseif (! $prevScheduled)
                wp_schedule_event($sequence->options->noticeTimestamp, 'daily', 'e20r_sequence_cron_hook', array($sequence->sequence_id));
            else
                E20RTools\DBG::log(" Timestamp didn't change so leave the schedule as-is");

            // Validate that the event was scheduled as expected.
            $ts = wp_next_scheduled( 'e20r_sequence_cron_hook', array($sequence->sequence_id) );

            E20RTools\DBG::log(' According to WP, the job is scheduled for: ' . date('d-m-Y H:i:s', $ts) . ' UTC and we asked for ' . date('d-m-Y H:i:s', $sequence->options->noticeTimestamp) . ' UTC');

            if ($ts != $sequence->options->noticeTimestamp)
                E20RTools\DBG::log(" Timestamp for actual cron entry doesn't match the one in the options...");
        }
        catch (\Exception $e) {
            // echo 'Error: ' . $e->getMessage();
            E20RTools\DBG::log('Error updating cron job(s): ' . $e->getMessage());

            if ( is_null($sequence->get_error_msg()) )
                $sequence->set_error_msg("Exception in update_user_notice_cron(): " . $e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Disable the WPcron job for the specified sequence
     *
     * @param int $sequence_id - The ID of the sequence to stop the daily schedule for
     */
    static public function stop_sending_user_notices( $sequence_id = null ) {

        $sequence = apply_filters('get_sequence_class_instance', null);
        E20RTools\DBG::log("Cron\\Job::stop_sending_user_notices() - Removing alert notice hook for sequence # " . $sequence_id );

        if ( is_null( $sequence_id ) )
        {
            wp_clear_scheduled_hook( 'e20r_sequence_cron_hook' );
            if (!class_exists("PMProSequence"))
            {
                wp_clear_scheduled_hook( 'pmpro_sequence_cron_hook' );
            }

        } else {
            wp_clear_scheduled_hook( 'e20r_sequence_cron_hook', array( $sequence_id ) );

            if (!class_exists("PMProSequence")) {
                wp_clear_scheduled_hook('pmpro_sequence_cron_hook', array($sequence_id));
            }

        }
    }

    /**
     * Cron job - defined per sequence, unless the sequence ID is empty, then we'll run through all sequences
     *
     * @param int $sequence_id - The Sequence ID (if supplied)
     *
     * @throws \Exception
     *
     * @since 3.1.0
     */
    public static function check_for_new_content($sequence_id = null)
    {

        global $wpdb;
        $all_sequences = false; // Default: Assume only one sequence is being processed.
        $received_id = null;

        $sequence = apply_filters('get_sequence_class_instance', null);

        // Process arguments we may (or may not) have received
        if (is_array($sequence_id)) {

            E20RTools\DBG::log("Received argument as array: ");
            E20RTools\DBG::log($sequence_id);

            $received_id = array_pop($sequence_id);
        }

        if (!is_array($sequence_id) && !is_null($sequence_id)) {

            $received_id = $sequence_id;
        }

        E20RTools\DBG::log("Sequence {$received_id} is ready to process messages... (received: " . (is_null($received_id) ? 'null' : $received_id) . ")");

        // TODO: Remove dependency on PMPro to obtain sequence(s) and users...

        // Get the data from the database
        $sequences = self::get_user_sequence_list($received_id);

        E20RTools\DBG::log("Found " . count($sequences) . " records to process for {$received_id}");
        // Track user send-count (just in case we'll need it to ensure there's not too many mails being sent to one user.
        $sendCount[] = array();

        // Loop through all selected sequences and users
        foreach ($sequences as $s) {
            // Grab a sequence object
            $sequence->is_cron = true;

            // Set the user ID we're processing for:
            $sequence->e20r_sequence_user_id = $s->user_id;
            $sequence->sequence_id = $s->seq_id;

            // Load sequence data
            if (!$sequence->init($s->seq_id)) {

                E20RTools\DBG::log("Sequence {$s->seq_id} is not converted to V3 metadata format. Exiting!");
                $sequence->set_error_msg(__("Please de-activiate and activiate the Eighty / 20 Results - Sequences plug-in to facilitate conversion to v3 meta data format.", "e20rsequence"));
                continue;
            }

            E20RTools\DBG::log('Processing sequence: ' . $sequence->sequence_id . ' for user ' . $s->user_id);

            if (($sequence->options->sendNotice == 1) && ($all_sequences === true)) {
                E20RTools\DBG::log('This sequence will be processed directly. Skipping it for now (All)');
                continue;
            }

            // Get user specific settings regarding sequence alerts.
            E20RTools\DBG::log("Loading alert settings for user {$s->user_id} and sequence {$sequence->sequence_id}");
            $notice_settings = $sequence->load_user_notice_settings($s->user_id, $sequence->sequence_id);
            // E20RTools\DBG::log($notice_settings);

            // Check if this user wants new content notices/alerts
            // OR, if they have not opted out, but the admin has set the sequence to allow notices
            if ((isset($notice_settings->send_notices) && ($notice_settings->send_notices == 1)) ||
                (empty($notice_settings->send_notices) &&
                    ($sequence->options->sendNotice == 1))
            ) {

                E20RTools\DBG::log('Sequence ' . $sequence->sequence_id . ' is configured to send new content notices to users.');

                // Load posts for this sequence.
                // $sequence_posts = $sequence->getPosts();

                $membership_day = $sequence->get_membership_days($s->user_id);
                $posts = $sequence->find_posts_by_delay($membership_day, $s->user_id);

                if (empty($posts)) {

                    E20RTools\DBG::log("Skipping Alert: Did not find a valid/current post for user {$s->user_id} in sequence {$sequence->sequence_id}");
                    // No posts found!
                    continue;
                }

                E20RTools\DBG::log("# of posts we've already notified for: " . count($notice_settings->posts) , ", and number of posts to process: " . count($posts));

                // Set the opt-in timestamp if this is the first time we're processing alert settings for this user ID.
                if (empty($notice_settings->last_notice_sent) || ($notice_settings->last_notice_sent == -1)) {

                    $notice_settings->last_notice_sent = current_time('timestamp');
                }

                // $posts = $sequence->get_postDetails( $post->id );
                E20RTools\DBG::log("noticeSendAs option is currently: {$sequence->options->noticeSendAs}");

                if (empty($sequence->options->noticeSendAs) || E20R_SEQ_SEND_AS_SINGLE == $sequence->options->noticeSendAs) {

                    E20RTools\DBG::log("Processing " . count($posts) . " individual messages to send to {$s->user_id}");

                    foreach ($posts as $post) {

                        if ( $post->delay == 0) {
                            E20RTools\DBG::log("Since the delay value for this post {$post->id} is 0 (confirm: {$post->delay}), user {$s->user_id} won't be notified for it...");
                            continue;
                        }

                        E20RTools\DBG::log("Do we notify {$s->user_id} of availability of post # {$post->id}?");
                        $flag_value = "{$post->id}_" . $sequence->normalize_delay($post->delay);

                        if (!in_array($flag_value, $notice_settings->posts)) {

                            E20RTools\DBG::log('Post: "' . get_the_title($post->id) . '"' .
                                ', post ID: ' . $post->id .
                                ', membership day: ' . $membership_day .
                                ', post delay: ' . $sequence->normalize_delay($post->delay) .
                                ', user ID: ' . $s->user_id .
                                ', already notified: ' . (!is_array($notice_settings->posts) || (in_array($flag_value, $notice_settings->posts) == false) ? 'false' : 'true') .
                                ', has access: ' . ($sequence->has_post_access($s->user_id, $post->id, true, $sequence->sequence_id) === true ? 'true' : 'false'));

                            E20RTools\DBG::log("Need to send alert to {$s->user_id} for '{$post->title}': {$flag_value}");

                            // Does the post alert need to be sent (only if its delay makes it available _after_ the user opted in.
                            if ($sequence->is_after_opt_in($s->user_id, $notice_settings, $post)) {

                                E20RTools\DBG::log('Preparing the email message');

                                // Send the email notice to the user
                                if ($sequence->send_notice($post, $s->user_id, $sequence->sequence_id)) {

                                    E20RTools\DBG::log('Email was successfully sent');
                                    // Update the sequence metadata that user has been notified
                                    $notice_settings->posts[] = $flag_value;

                                    // Increment send count.
                                    $sendCount[$s->user_id] = (isset($sendCount[$s->user_id]) ? $sendCount[$s->user_id]++ : 0); // Bug/Fix: Sometimes generates an undefined offset notice

                                    E20RTools\DBG::log("Sent email to user {$s->user_id} about post {$post->id} with delay {$post->delay} in sequence {$sequence->sequence_id}. The SendCount is {$sendCount[ $s->user_id ]}");
                                    $notice_settings->last_notice_sent = current_time('timestamp');
                                } else {

                                    E20RTools\DBG::log("Error sending email message!", E20R_DEBUG_SEQ_CRITICAL);
                                }
                            } else {

                                // Only add this post ID if it's not already present in the notifiedPosts array.
                                if (!in_array("{$post->id}_{$post->delay}", $notice_settings->posts, true)) {

                                    E20RTools\DBG::log("Adding this previously released (old) post to the notified list");
                                    $notice_settings->posts[] = "{$post->id}_" . $sequence->normalize_delay($post->delay);
                                }
                            }
                        } else {
                            E20RTools\DBG::log("Will NOT notify user {$s->user_id} about the availability of post {$post->id}", E20R_DEBUG_SEQ_WARNING);
                        }
                    } // End of foreach
                } // End of "send as single"

                if (E20R_SEQ_SEND_AS_LIST == $sequence->options->noticeSendAs) {

                    $alerts = array();

                    foreach ($posts as $pk => $post) {

                        $flag_value = "{$post->id}_" . $sequence->normalize_delay($post->delay);

                        if (in_array($flag_value, $notice_settings->posts, true)) {

                            dbg("We already sent notice for {$flag_value}");
                            unset($posts[$pk]);

                        } else {

                            E20RTools\DBG::log('Adding notification setting for : "' . get_the_title($post->id) . '"' .
                                ', post ID: ' . $post->id .
                                ', membership day: ' . $membership_day .
                                ', post delay: ' . $sequence->normalize_delay($post->delay) .
                                ', user ID: ' . $s->user_id .
                                ', already notified: ' . (!is_array($notice_settings->posts) || (in_array($flag_value, $notice_settings->posts) == false) ? 'false' : 'true') .
                                ', has access: ' . ($sequence->has_post_access($s->user_id, $post->id, true, $sequence->sequence_id) === true ? 'true' : 'false'));

                            E20RTools\DBG::log("Adding this ({$post->id}) post to the possibly notified list: {$flag_value}");
                            $alerts[$pk] = $flag_value;

                            $posts[$pk]->after_optin = $sequence->is_after_opt_in($s->user_id, $notice_settings, $post);
                        }

                    }

                    E20RTools\DBG::log("Sending " . count($posts) . " as a list of links to {$s->user_id}");

                    // Send the email notice to the user
                    if ($sequence->send_notice($posts, $s->user_id, $sequence->sequence_id)) {

                        $notice_settings->last_notice_sent = current_time('timestamp');
                        $notice_settings->posts = array_merge($notice_settings->posts, $alerts);

                        E20RTools\DBG::log("Merged notification settings for newly sent posts: ");
                        E20RTools\DBG::log($notice_settings->posts);
                        E20RTools\DBG::log($alerts);

                    } else {

                        E20RTools\DBG::log("Will NOT notify user {$s->user_id} about these " . count($posts) . " new posts", E20R_DEBUG_SEQ_WARNING);
                    }

                } // End of "send as list"

                // Save user specific notification settings (including array of posts we've already notified them of)
                $sequence->save_user_notice_settings($s->user_id, $notice_settings, $sequence->sequence_id);
                E20RTools\DBG::log('Updated meta for the user notices');

            } // End if
            else {

                // Move on to the next one since this one isn't configured to send notices
                E20RTools\DBG::log('Sequence ' . $s->seq_id . ' is not configured for sending alerts. Skipping...', E20R_DEBUG_SEQ_WARNING);
            } // End of sendNotice test
        } // End of data processing loop

        E20RTools\DBG::log("Completed execution of cron job for {$received_id}");
    }

    /**
     * Return the Tools\Cron class instance (when using singleton pattern)
     *
     * @return Tools\Cron $this
     */
    public static function get_instance()
    {
        if (null == self::$_this) {
            self::$_this = new self;
        }

        return self::$_this;
    }

    /**
     * Loads a sequence (or a list of sequences) and its consumers (users)
     *
     * @param null $sequence_id - The ID of the sequence to load info about
     * @return mixed|void -  Returns records containing
     *
     * @since 4.2.6
     */
    private function get_user_sequence_list($sequence_id = null) {

        $sequence = apply_filters('get_sequence_class_instance', null);
        $result = array();

        if (function_exists('pmpro_has_membership_access')) {

            global $wpdb;

            // Prepare SQL to get all sequences and users associated in the system who _may_ need to be notified
            if (is_null($sequence_id)) {

                // dbgOut('get_user_sequence_list() - No Sequence ID specified. Processing for all sequences');
                $all_sequences = true;
                E20RTools\DBG::log("get_user_sequence_list() - Loading and processing ALL sequences");
                $sql = "
                        SELECT usrs.*, pgs.page_id AS seq_id
                        FROM {$wpdb->pmpro_memberships_users} AS usrs
                            INNER JOIN {$wpdb->pmpro_memberships_pages} AS pgs
                                ON (usrs.membership_id = pgs.membership_id)
                            INNER JOIN {$wpdb->posts} AS posts
                                ON ( pgs.page_id = posts.ID AND posts.post_type = 'pmpro_sequence')
                        WHERE (usrs.status = 'active')
                    ";
            } // Get the specified sequence and its associated users
            else {

                // dbgOut('Sequence ID specified in function argument. Processing for sequence: ' . $sequenceId);
                E20RTools\DBG::log("get_user_sequence_list() - Loading and processing specific sequence: {$sequence_id}");

                $sql = $wpdb->prepare(
                    "
                        SELECT usrs.*, pgs.page_id AS seq_id
                        FROM {$wpdb->pmpro_memberships_users} AS usrs
                            INNER JOIN {$wpdb->pmpro_memberships_pages} AS pgs
                                ON (usrs.membership_id = pgs.membership_id)
                            INNER JOIN {$wpdb->posts} AS posts
                                ON ( posts.ID = pgs.page_id AND posts.post_type = 'pmpro_sequence')
                        WHERE (usrs.status = 'active') AND (pgs.page_id = %d)
                    ",
                    $sequence_id
                );
            }

            $result = $wpdb->get_results($sql);
        }

        return apply_filters('e20r-sequence-get-protected-users-posts', $result, $sequence_id);
    }
}
