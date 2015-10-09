<?php

/*
  License:

	Copyright 2014 Thomas Sjolshagen (thomas@eighty20results.com)

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

/* Check for new content, email a notice to the user if new content exists. */
if (! function_exists('pmpro_sequence_check_for_new_content')):

	add_action('pmpro_sequence_cron_hook', 'pmpro_sequence_check_for_new_content', 10, 1);

    /**
     * Cron job - defined per sequence, unless the sequence ID is empty, then we'll run through all sequences
     *
     * @param $sequenceId - The Sequence ID (if supplied)
     *
     * @throws Exception
     */
	function pmpro_sequence_check_for_new_content( $sequenceId = 0 ) {

		global $wpdb;
		$all_sequences = false; // Default: Assume only one sequence is being processed.

		// Prepare SQL to get all sequences and users associated in the system who _may_ need to be notified
		if ( empty($sequenceId) || ($sequenceId == 0)) {

			// dbgOut('cron() - No Sequence ID specified. Processing for all sequences');
			$all_sequences = true;

			$sql = "
					SELECT usrs.*, pgs.page_id AS seq_id
					FROM {$wpdb->pmpro_memberships_users} AS usrs
						INNER JOIN {$wpdb->pmpro_memberships_pages} AS pgs
							ON (usrs.membership_id = pgs.membership_id)
						INNER JOIN {$wpdb->posts} AS posts
							ON ( pgs.page_id = posts.ID AND posts.post_type = 'pmpro_sequence')
					WHERE (usrs.status = 'active')
				";
		}
		// Get the specified sequence and its associated users
		else {

			// dbgOut('cron() - Sequence ID specified in function argument. Processing for sequence: ' . $sequenceId);

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
				$sequenceId
			);
		}

		// Get the data from the database
		$sequences = $wpdb->get_results($sql);

		// Track user send-count (just in case we'll need it to ensure there's not too many mails being sent to one user.
		$sendCount[] = array();

        // Loop through all selected sequences and users
		foreach ( $sequences as $s )
		{
			// Grab a sequence object
			$sequence = new PMProSequence();
			$sequence->is_cron = true;

			// Set the user ID we're processing for:
			$sequence->pmpro_sequence_user_id = $s->user_id;

			// Load sequence data
			if ( !$sequence->get_options( $s->seq_id ) ) {

				$sequence->dbg_log("cron() - Sequence {$s->seq_id} is not converted to V3 metadata format. Exiting!");
				$sequence->set_error_msg( __( "Please de-activiate and activiate the PMPro Sequences plug-in to facilitate conversion to v3 meta data format.", "pmprosequences" ) );
				continue;
			}

			$sequence->dbg_log('cron() - Processing sequence: ' . $sequence->sequence_id . ' for user ' . $s->user_id);

			if ( ($sequence->options->sendNotice == 1) && ($all_sequences === true) ) {
				$sequence->dbg_log('cron() - This sequence will be processed directly. Skipping it for now (All)');
				continue;
			}

			// Get user specific settings regarding sequence alerts.
			$sequence->dbg_log("cron() - Loading alert settings for user {$s->user_id} and sequence {$sequenceId}");
			$notice_settings = $sequence->load_user_notice_settings( $s->user_id, $sequenceId );
			$sequence->dbg_log( $notice_settings );

            // The user has no previously defined sequence alert settings.
            /* Fixed: Taken care of in load_user_notice_settings();
            if ( empty ( $notice_settings ) ) {

                $sequence->dbg_log("User with ID {$s->user_id} has no notice settings. Setting defaults");
				$notice_settings = $sequence->defaultNoticeSettings( $sequence->sequence_id );
            }
*/
			// Check if this user wants new content notices/alerts
			// OR, if they have not opted out, but the admin has set the sequence to allow notices
			if ( ( isset( $notice_settings->send_notices ) && ( $notice_settings->send_notices == 1) ) ||
				( empty( $notice_settings->send_notices ) &&
				  ( $sequence->options->sendNotice == 1 ) ) ) {

				// Set the opt-in timestamp if this is the first time we're processing alert settings for this user ID.
				if ( empty( $notice_settings->last_notice_sent ) ) {

					$notice_settings->last_notice_sent = current_time('timestamp');
                }

                $sequence->dbg_log('cron() - Sequence ' . $sequence->sequence_id . ' is configured to send new content notices to users.');

                // Load posts for this sequence.
				// $sequence_posts = $sequence->getPosts();

                // Get the most recent post in the sequence and notify for it (if we're supposed to notify.
                $post = $sequence->find_closest_post( $s->user_id );
                $membership_day = $sequence->get_membership_days( $s->user_id );

				if ( empty( $post ) ) {

					$sequence->dbg_log("cron() - Could not find a post for user {$s->user_id} in sequence {$sequence->sequence_id}");
					// No posts found!
					continue;
				}

				// $posts = $sequence->get_postDetails( $post->id );

                $sequence->dbg_log( 'cron() - Post: "' . get_the_title($post->id) . '"' .
                        ', post ID: ' . $post->id .
                        ', membership day: ' . $membership_day .
                        ', post delay: ' . $sequence->normalize_delay( $post->delay ).
                        ', user ID: ' . $s->user_id .
                        ', already notified: ' . ( !is_array($notice_settings->posts) || ( in_array( "{$post->id}_{$post->delay}", $notice_settings->posts ) == false ) ? 'false' : 'true' ) .
                         ', has access: ' . ( $sequence->has_post_access( $s->user_id, $post->id, true ) === true ? 'true' : 'false' ) );

                $sequence->dbg_log("cron() - # of posts we've already notified for: " . count( $notice_settings->posts ));
                $sequence->dbg_log( "cron() - Do we notify {$s->user_id} of availability of post # {$post->id}?" );

                if  ( ( !empty( $post ) ) &&
                      ( $membership_day >= $sequence->normalize_delay( $post->delay ) ) &&
                    ( !in_array( "{$post->id}_{$post->delay}", $notice_settings->posts ) ) ) {

                    $sequence->dbg_log( "cron() - Need to send alert to {$s->user_id} for '{$post->title}': {$post->id}_{$post->delay}" );

                    // Does the post alert need to be sent (only if its delay makes it available _after_ the user opted in.
                    if ( $sequence->is_after_opt_in( $s->user_id, $notice_settings->optin_at, $post ) ) {

                        $sequence->dbg_log( 'cron() - Preparing the email message' );

                        // Send the email notice to the user
                        if ( $sequence->send_notice( $post->id, $s->user_id, $sequence->sequence_id ) ) {

                            $sequence->dbg_log( 'cron() - Email was successfully sent' );
                            // Update the sequence metadata that user has been notified
							$notice_settings->posts[] = "{$post->id}_{$post->delay}";

                            // Increment send count.
                            $sendCount[ $s->user_id ] ++;

                            $sequence->dbg_log("cron() - Sent email to user {$s->user_id} about post {$post->id} with delay {$post->delay} in sequence {$sequence->sequence_id}. The SendCount is {$sendCount[ $s->user_id ]}" );
                        }
                        else {

                            $sequence->dbg_log( "cron() - Error sending email message!", DEBUG_SEQ_CRITICAL );
                        }
                    }
                    else {

                        // Only add this post ID if it's not already present in the notifiedPosts array.
                        if (! in_array( "{$post->id}_{$post->delay}", $notice_settings->posts, true ) ) {

                            $sequence->dbg_log( "cron() - Adding this previously released (old) post to the notified list" );
							$notice_settings->posts[] = "{$post->id}_{$post->delay}";
                        }
                    }
                }
                else {
                    $sequence->dbg_log("cron() - Will NOT notify user {$s->user_id} about the availability of post {$post->id}", DEBUG_SEQ_WARNING);
                }

				// Iterate through all of the posts in the sequence
/*
				foreach ( $sequence_posts as $post ) {

					dbg_log('cron() - Evaluating whether to send alert for "' . get_the_title($post->id) .'"');
					dbg_log( 'cron() - Post: "' . get_the_title($post->id) . '"' .
					        ', user ID: ' . $s->user_id .
					        ', already notified: ' . ( in_array( $post->id, $noticeSettings->notifiedPosts, true ) == false ? 'false' : 'true' ) .
					        ', has access: ' . ( pmpro_sequence_hasAccess( $s->user_id, $post->id ) === true ? 'true' : 'false' ) );

					// Does the userID have access to this sequence post. Make sure the post isn't previously "notified"
					if ( ( ! empty( $post->id ) ) && pmpro_sequence_hasAccess( $s->user_id, $post->id, true ) &&
					     ! in_array( $post->id, $noticeSettings->notifiedPosts, true )
					) {
						// Does the post alert need to be sent (only if its delay makes it available _after_ the user opted in.
						if ( $sequence->isAfterOptIn($s->user_id, $noticeSettings->sequence[$sequence->sequence_id]->optinTS, $post ) ) {

							dbg_log( 'cron() - Preparing the email message' );

							// Send the email notice to the user
							if ( $sequence->sendEmail( $post->id, $s->user_id, $sequence->sequence_id ) ) {

								dbg_log( 'cron() - Email was successfully sent' );
								// Update the sequence metadata that user has been notified
								$noticeSettings->notifiedPosts[] = $post->id;

								// Increment send count.
								$sendCount[ $s->user_id ] ++;

								dbg_log("cron() - Sent email to user {$s->user_id} about post {$post->id} in sequence {$sequence->sequence_id}. The SendCount is {$sendCount[ $s->user_id ]}" );
							} else {
								dbg_log( "cron() - Error sending email message!" );
							}
						}
						else {
							// Only add this post ID if it's not already present in the notifiedPosts array.
							if (! in_array( $post->id, $noticeSettings->notifiedPosts, true ) ) {
								dbg_log( "cron() - Adding this previously released (old) post to the notified list" );
								$noticeSettings->notifiedPosts[] = $post->id;
							}
						}
					}
					else {

						dbg_log( 'cron() - User with ID ' . $s->user_id . ' does not need alert for post #' .
						        $post->id . ' in sequence ' . $sequence->sequence_id . ' Or the sendCount (' . $sendCount[ $s->user_id ] . ')has been exceeded for now' );

					} // End of access test.

					dbg_log("cron() - Cleaning up the notifiedPosts list for this user, just in case");
					$tmp = array_count_values($noticeSettings->notifiedPosts);
					$cnt = $tmp[$post->id];

					// Check whether there are repeat entries for the current sequence
					if ($cnt > 1) {

						// There are so get rid of the extras (this is a backward compatibility feature due to a previous bug.)
						dbg_log('cron() - Post appears more than once in the notify list. Clean it up!');

						$clean = array_unique( $noticeSettings->notifiedPosts);

						// dbg_log('cron() - Cleaned array: ' . print_r($clean, true));
						$noticeSettings->notifiedPosts = $clean;
					}

				} // End foreach for sequence posts
*/
				// Save user specific notification settings (including array of posts we've already notified them of)
				$sequence->save_user_notice_settings( $s->user_id, $notice_settings, $sequenceId );
                $sequence->dbg_log('cron() - Updated user meta for the notices');

			} // End if
			else {

				// Move on to the next one since this one isn't configured to send notices
                $sequence->dbg_log( 'cron() - Sequence ' . $s->seq_id . ' is not configured for sending alerts. Skipping...', DEBUG_SEQ_WARNING );
			} // End of sendNotice test
		} // End of data processing loop
	}
 endif;