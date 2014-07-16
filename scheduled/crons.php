<?php
/* Check for new content, email a notice to the user if new content exists. */
if (! function_exists('pmpro_sequence_check_for_new_content')):

	add_action('pmpro_sequence_cron_hook', 'pmpro_sequence_check_for_new_content', 10, 2);

	/**
	 * Cron job - defined per sequence, unless the sequence ID is empty, then we'll run through all sequences
	 */

	function pmpro_sequence_check_for_new_content( $sequenceId )
	{

        if ( empty($sequenceId) ) {
            error_log('Sequence ID is unset...');
            $sequenceId = 0;
            $sequence = new PMProSequences();
            // exit();
        } else
		    $sequence = new PMProSequences( $sequenceId );

        error_log('cron - Sequence ID:' . $sequenceId);

		global $wpdb;

		// Fetch list of members
		$users = $wpdb->get_results("
	        SELECT *
	        FROM $wpdb->pmpro_memberships_users
	        WHERE status = 'active'
		");

		 // Get the specified sequence data from the database
        if ( $sequenceId == 0)
            $sql = "
	        SELECT *
	        FROM $wpdb->posts
            WHERE post_type = 'pmpro_sequence'
	    ";
        else
            $sql = "
	        SELECT *
	        FROM $wpdb->posts
            WHERE post_type = 'pmpro_sequence' AND ID = $sequenceId
	    ";

		$seq = $wpdb->get_results($sql);

            // Loop through all defined sequences on the system
		foreach ( $seq as $s )
		{
			// Grab a sequence object
			$sequence = new PMProSequences( $s->ID );

			// Grab the settings for this sequence.
			$seq_settings = $sequence->fetchOptions( $s->ID );

			// Check if this sequence is configured to send new content notices.
			if ( $seq_settings->sendNotice == 1 ) {
				error_log('cron() - Sequence ' . $s->ID . ' is configured to send new content notices to users.' );

                // Get all posts belonging to this sequence.
				$sequence_posts = $sequence->getPosts();

				// Iterate through all of the posts in the sequence
				foreach ( $sequence_posts as $sequence_post ) {

					// Iterate through all users (TODO: Fix iterative approach as it will be slow for a system with large no of users!)
					foreach ( $users as $user ) {

						// Get user specific settings regarding sequence alerts.
						$options = get_user_option($user->user_id, 'pmpro_sequence_alert', true);
						$sequence->dbgOut('User options:' . print_r($options, true));

						// Check whether the userID has access to this sequence post and if the post isn't previously "notified"
						if ( pmpro_sequence_hasAccess( $user->user_id, $sequence_post->id ) ) {

                            $notified = get_user_meta( $user->user_id, 'pmpro_sequence_notices', true );
                            $sequence->dbgOut('Notice settings:' . print_r($notified, true));

                            // Assuming the user has configured their send_notice settings
                            if ( ( $options->sequence[$s->id]['sendNotice'] == 1 ) ||
                                ( empty($options->sequence[$s->id]['sendNotice']) &&
                                    ($sequence->options->sendNotice == 1) ) ) {

                                $sequence->sendEmail($sequence_post->id, $user->user_id, $s->ID);
                                $sequence->dbgOut('Sent email to user ' . $user->user_id . ' about post post ' .
                                    $sequence_post->id . ' in sequence ' . $sequence->sequence_id);

                                // Update the sequence metadata that user has been notified
                                $notified->sequence[] = $sequence_post->id;
                                update_user_meta($user->user_id, 'pmpro_sequence_notices', $notified);
                            }
						} else
							$sequence->dbgOut('User with ID ' . $user->user_id . ' does not need alert for post' .
							                  $sequence_post->id . ' in sequence ' . $sequence->sequence_id);
					} // End foreach for user iteration
				} // End foreach for sequence posts
			} // End if
			else {
				// Move on to the next one since this one isn't configured to send notices
				$sequence->dbgOut( 'cron() - Sequence ' . $s->ID . ' is not configured to send notices. Skipping...' );
				continue;
			}
		}
		error_log('Completed cron job for PMPro Sequences');
	}
 endif;