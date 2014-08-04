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

		/** TODO: Skip sequence entries that are older than 'today', unless admin configures otherwise.
		 *  This means we'll have to use the 'show' logic and compare $sequence_post->delay
		 *      (converted to timestamp based on $PMProSequence($sequenceId)->options->delayType) to
		 *      $user_settings->sequence[sequence_id]->optinTS. We'll only process posts that have a 'delay' value >= $user_settings->sequence[sequence_id]->optinTS
		 *
		 */



		// Get all sequences and users associated in the system who _may_ need to be notified
		if ( empty($sequenceId) || ($sequenceId == 0)) {

			dbgOut('cron() - No Sequence ID specified. Processing for all sequences');

			$sql = $wpdb->prepare("
				SELECT usrs.*, pgs.page_id AS seq_id
				FROM $wpdb->pmpro_memberships_users AS usrs
					INNER JOIN $wpdb->pmpro_memberships_pages AS pgs
						ON (usrs.membership_id = pgs.membership_id)
					INNER JOIN $wpdb->posts AS posts
						ON ( pgs.page_id = posts.ID AND posts.post_type = 'pmpro_sequence')
				WHERE (usrs.status = 'active')
			");
		}
		// Get the specified sequence and its associated users
		else {

			dbgOut('cron() - Sequence ID specified in function argument. Processing for sequence: ' . $sequenceId);

			$sql = $wpdb->prepare("
				SELECT usrs.*, pgs.page_id AS seq_id
				FROM $wpdb->pmpro_memberships_users AS usrs
					INNER JOIN $wpdb->pmpro_memberships_pages AS pgs
						ON (usrs.membership_id = pgs.membership_id)
					INNER JOIN $wpdb->posts AS posts
						ON ( posts.ID = pgs.page_id AND posts.post_type = 'pmpro_sequence')
				WHERE (usrs.status = 'active') AND (pgs.page_id = %d)
			",
			$sequenceId);
		}

		// Get all sequence and user IDs from the database
		$sequences = $wpdb->get_results($sql);

		// Track user send-count for this iteration...
		$sendCount[] = array();

        // Loop through all selected sequences and users
		foreach ( $sequences as $s )
		{
			// Grab a sequence object
			$sequence = new PMProSequences( $s->seq_id );
			dbgOut('cron() - Processing sequence: ' . $sequence->sequence_id . ' for user ' . $s->user_id);

			$schedHr = date('H', strtotime($sequence->options->noticeTime));

			// Check whether the Hour (time) is correct. Adjusted for 12 or 24 hour clock.
			if ( $schedHr != date('H', current_time('timestamp')) ) {

				dbgOut('cron() - Not the right time of day. Skipping for now! Calculated Hour: ' .  $schedHr . ' Current Hour: ' . date('H', current_time('timestamp')));
				continue;
			}

			// Get user specific settings regarding sequence alerts.
			$noticeSettings = get_user_meta( $s->user_id, $wpdb->prefix . 'pmpro_sequence_notices', true );
			// dbgOut( 'Notice settings: ' . print_r( $noticeSettings, true ) );

			// Check if this user wants new content notices/alerts
			// OR, if they have not opted out, but the admin has set the sequence to allow notices
			if ( ($noticeSettings->sequence[ $sequence->sequence_id ]->sendNotice == 1) ||
				( empty( $noticeSettings->sequence[ $sequence->sequence_id ]->sendNotice ) &&
				  ( $sequence->options->sendNotice == 1 ) ) ) {

				// Set the optin timestamp if this is the first time we're processing this users alert settings.
				if ( empty( $noticeSettings->sequence[ $sequence->sequence_id ]->optinTS ) )
					// First time this user has a notice processed. Set the timestamp to now.
					$noticeSettings->sequence[ $sequence->sequence_id ]->optinTS = current_time('timestamp');

				dbgOut('cron() - Sequence ' . $sequence->sequence_id . ' is configured to send new content notices to users.');

                // Get all posts belonging to this sequence.
				$sequence_posts = $sequence->getPosts();

				dbgOut("cron() - # of posts in sequence (" . count($sequence_posts) . ") vs number of posts we've notified for: " . count($noticeSettings->sequence[$sequence->sequence_id]->notifiedPosts));

				// Iterate through all of the posts in the sequence
				foreach ( $sequence_posts as $post ) {

					dbgOut('Evaluating whether to send alert for "' . get_the_title($post->id) .'"');
					/**
					 * if 'byDays':
					 *  Find the post that would be displayed "today" per the sequence rules
					 *      This is the post that has the same delay as the user's #of days since 'startdate'
					 *          use convertToDays( date('Y-m-d', strtotime($s->startdate) ) ) for user day count
					 *          use $post->delay for post day count (since start)
					 *
					 * if 'byDate':
					 *    $days-since-start-for-this-user = $sequence->convertToDays($post->delay, $s->user_id, $s->membership_id);
					 *
					 *  Compare earliest $post->delay value (as a timestamp) to the User specific optinTS. If Greater or equal, then send.
					 */
					if ( $sequence->isAfterOptIn($s->user_id, $noticeSettings, $post ) &&
						($sendCount[$s->user_id] < PMPRO_SEQUENCE_MAX_EMAILS) ) {

						// Test if $post->delay >= maxNotifyDelay

						dbgOut( 'cron() - Post: ' . get_the_title($post->id) .
						        ', user ID: ' . $s->user_id .
						        ', in_array: ' . ( in_array( $post->id, $noticeSettings->sequence[ $sequence->sequence_id ]->notifiedPosts, true ) == false ? 'false' : 'true' ) .
						        ', hasAccess: ' . ( pmpro_sequence_hasAccess( $s->user_id, $post->id ) == true ? 'true' : 'false' ) );

						// Check whether the userID has access to this sequence post and if the post isn't previously "notified"
						if ( ( ! empty( $post->id ) ) && pmpro_sequence_hasAccess( $s->user_id, $post->id ) &&
						     ! in_array( $post->id, $noticeSettings->sequence[ $sequence->sequence_id ]->notifiedPosts, true )
						) {

							dbgOut( 'cron() - Preparing the email message' );

							// Send the email notice to the user
							if ( $sequence->sendEmail( $post->id, $s->user_id, $sequence->sequence_id ) ) {

								dbgOut( 'cron() - Email was successfully sent' );
								// Update the sequence metadata that user has been notified
								$noticeSettings->sequence[ $sequence->sequence_id ]->notifiedPosts[] = $post->id;

								// Increment send count.
								$sendCount[ $s->user_id ] ++;

								dbgOut( 'cron() - Sent email to user ' . $s->user_id . ' about post ' .
								        $post->id . ' in sequence: ' . $sequence->sequence_id . '. SendCount = ' . $sendCount[ $s->user_id ] );
							} else {
								dbgOut( 'cron() - Error sending email message!' );
							}

						} else {
							dbgOut( 'cron() - User with ID ' . $s->user_id . ' does not need alert for post #' .
							        $post->id . ' in sequence ' . $sequence->sequence_id . ' Or the sendCount (' . $sendCount[ $s->user_id ] . ')has been exceeded for now' );

						} // End of access test.

					}
				} // End foreach for sequence posts

				update_user_meta( $s->user_id, $wpdb->prefix . 'pmpro_sequence_notices', $noticeSettings );
				dbgOut('cron() - Updated user meta for the notices');

			} // End if
			else {
				// Move on to the next one since this one isn't configured to send notices
				dbgOut( 'cron() - Sequence ' . $s->seq_id . ' is not configured to send notices. Skipping...' );
			} // End of sendNotice test
		} // End of data processing loop
	}
 endif;