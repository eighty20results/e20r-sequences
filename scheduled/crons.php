<?php
/* Check for new content, email a notice to the user if new content exists. */
if (! function_exists('pmpro_sequence_check_for_new_content')):

	add_action('pmpro_sequence_cron_hook', 'pmpro_sequence_check_for_new_content', 10, 2);

	/**
	 * Cron job - defined per sequence, unless the sequence ID is empty, then we'll run through all sequences
	 */

	function pmpro_sequence_check_for_new_content( $sequenceId )
	{

		global $wpdb;
		$all_sequences = false; // Default: Assume only one sequence is being processed.

		// Prepare SQL to get all sequences and users associated in the system who _may_ need to be notified
		if ( empty($sequenceId) || ($sequenceId == 0)) {

			dbgOut('cron() - No Sequence ID specified. Processing for all sequences');
			$all_sequences = true;

			$sql = $wpdb->prepare(
				"
					SELECT usrs.*, pgs.page_id AS seq_id
					FROM {$wpdb->pmpro_memberships_users} AS usrs
						INNER JOIN {$wpdb->pmpro_memberships_pages} AS pgs
							ON (usrs.membership_id = pgs.membership_id)
						INNER JOIN {$wpdb->posts} AS posts
							ON ( pgs.page_id = posts.ID AND posts.post_type = 'pmpro_sequence')
					WHERE (usrs.status = 'active')
				"
			);
		}
		// Get the specified sequence and its associated users
		else {

			dbgOut('cron() - Sequence ID specified in function argument. Processing for sequence: ' . $sequenceId);

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
			$sequence = new PMProSequence( $s->seq_id );
			dbgOut('cron() - Processing sequence: ' . $sequence->sequence_id . ' for user ' . $s->user_id);

			if ( ($sequence->options->sendNotice == 1) && ($all_sequences === true) ) {
				dbgOut('cron() - This sequence will be processed directly. Skipping it for now (All)');
				continue;
			}

			$schedHr = date('H', strtotime($sequence->options->noticeTime));
/*

Removed since jobs are now being scheduled correctly and (will be able to be) ran directly from the settings metabox.
			// Check whether the Hour (time) is correct. Adjusted for 12 or 24 hour clock.
			if ( $schedHr != date('H', current_time('timestamp')) ) {

				dbgOut('cron() - Not the right time of day. Skipping for now! Calculated Hour: ' .  $schedHr . ' Current Hour: ' . date('H', current_time('timestamp')));
				continue;
			}
*/
			// Get user specific settings regarding sequence alerts.
			$noticeSettings = get_user_meta( $s->user_id, $wpdb->prefix . 'pmpro_sequence_notices', true );

			// Check if this user wants new content notices/alerts
			// OR, if they have not opted out, but the admin has set the sequence to allow notices
			if ( ($noticeSettings->sequence[ $sequence->sequence_id ]->sendNotice == 1) ||
				( empty( $noticeSettings->sequence[ $sequence->sequence_id ]->sendNotice ) &&
				  ( $sequence->options->sendNotice == 1 ) ) ) {

				// Set the opt-in timestamp if this is the first time we're processing alert settings for this user ID.
				if ( empty( $noticeSettings->sequence[ $sequence->sequence_id ]->optinTS ) )
					$noticeSettings->sequence[ $sequence->sequence_id ]->optinTS = current_time('timestamp', true);

				dbgOut('cron() - Sequence ' . $sequence->sequence_id . ' is configured to send new content notices to users.');

                // Load posts for this sequence.
				$sequence_posts = $sequence->getPosts();

				dbgOut("cron() - # of posts in sequence (" . count($sequence_posts) . ") vs number of posts we've already notified for: " . count($noticeSettings->sequence[$sequence->sequence_id]->notifiedPosts));

				// Iterate through all of the posts in the sequence
				foreach ( $sequence_posts as $post ) {

					dbgOut('cron() - Evaluating whether to send alert for "' . get_the_title($post->id) .'"');
					dbgOut( 'cron() - Post: "' . get_the_title($post->id) . '"' .
					        ', user ID: ' . $s->user_id .
					        ', already notified: ' . ( in_array( $post->id, $noticeSettings->sequence[ $sequence->sequence_id ]->notifiedPosts, true ) == false ? 'false' : 'true' ) .
					        ', has access: ' . ( pmpro_sequence_hasAccess( $s->user_id, $post->id ) === true ? 'true' : 'false' ) );

					// Does the userID have access to this sequence post. Make sure the post isn't previously "notified"
					if ( ( ! empty( $post->id ) ) && pmpro_sequence_hasAccess( $s->user_id, $post->id, true ) &&
					     ! in_array( $post->id, $noticeSettings->sequence[ $sequence->sequence_id ]->notifiedPosts, true )
					) {
						// Does the post alert need to be sent (only if its delay makes it available _after_ the user opted in.
						if ( $sequence->isAfterOptIn($s->user_id, $noticeSettings->sequence[$sequence->sequence_id]->optinTS, $post ) ) {

							dbgOut( 'cron() - Preparing the email message' );

							// Send the email notice to the user
							if ( $sequence->sendEmail( $post->id, $s->user_id, $sequence->sequence_id ) ) {

								dbgOut( 'cron() - Email was successfully sent' );
								// Update the sequence metadata that user has been notified
								$noticeSettings->sequence[ $sequence->sequence_id ]->notifiedPosts[] = $post->id;

								// Increment send count.
								$sendCount[ $s->user_id ] ++;

								dbgOut("cron() - Sent email to user {$s->user_id} about post {$post->id} in sequence {$sequence->sequence_id}. The SendCount is {$sendCount[ $s->user_id ]}" );
							} else {
								dbgOut( "cron() - Error sending email message!" );
							}
						}
						else {
							// Only add this post ID if it's not already present in the notifiedPosts array.
							if (! in_array( $post->id, $noticeSettings->sequence[ $sequence->sequence_id ]->notifiedPosts, true ) ) {
								dbgOut( "cron() - Adding this previously released (old) post to the notified list" );
								$noticeSettings->sequence[ $sequence->sequence_id ]->notifiedPosts[] = $post->id;
							}
						}
					}
					else {

						dbgOut( 'cron() - User with ID ' . $s->user_id . ' does not need alert for post #' .
						        $post->id . ' in sequence ' . $sequence->sequence_id . ' Or the sendCount (' . $sendCount[ $s->user_id ] . ')has been exceeded for now' );

					} // End of access test.

					dbgOut("cron() - Cleaning up the notifiedPosts list for this user, just in case");
					$tmp = array_count_values($noticeSettings->sequence[ $sequence->sequence_id ]->notifiedPosts);
					$cnt = $tmp[$post->id];

					// Check whether there are repeat entries for the current sequence
					if ($cnt > 1) {

						// There are so get rid of the extras (this is a backward compatibility feature due to a previous bug.)
						dbgOut('cron() - Post appears more than once in the notify list. Clean it up!');

						$clean = array_unique( $noticeSettings->sequence[ $sequence->sequence_id ]->notifiedPosts);

						// dbgOut('cron() - Cleaned array: ' . print_r($clean, true));
						$noticeSettings->sequence[ $sequence->sequence_id ]->notifiedPosts = $clean;
					}

				} // End foreach for sequence posts

				// Save user specific notification settings (including array of posts we've already notified them of)
				update_user_meta( $s->user_id, $wpdb->prefix . 'pmpro_sequence_notices', $noticeSettings );
				dbgOut('cron() - Updated user meta for the notices');

			} // End if
			else {

				// Move on to the next one since this one isn't configured to send notices
				dbgOut( 'cron() - Sequence ' . $s->seq_id . ' is not configured for sending alerts. Skipping...' );
			} // End of sendNotice test
		} // End of data processing loop
	}
 endif;