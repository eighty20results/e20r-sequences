<?php
/* Check for new content, email a notice to the user if new content exists. */
if (! function_exists('pmpro_sequence_check_for_new_content')):

function pmpro_sequence_check_for_new_content()
{

	global $wpdb;

	//get all members
	$users = $wpdb->get_results("
        SELECT *
        FROM $wpdb->pmpro_memberships_users
        WHERE status = 'active'
	");

	//get all series
	$seq = $wpdb->get_results("
        SELECT *
        FROM $wpdb->posts
        WHERE post_type = 'pmpro_sequence'
    ");

	// Loop through all defined sequences on the system
	foreach ( $seq as $s )
	{
		$sequence = new PMProSequences( $s->ID );

		// Grab the settings for this sequence.
		$seq_settings = $sequence->fetchOptions( $s->ID );

		// Check if this sequence is configured to send new content notices. Exit if not.
		if ( $seq_settings->sendNotice != 1 ) {
			$sequence->dbgOut('Not configured to send notices to users. Exiting!');
			return;
		}

		// Get the posts belonging to this sequence.
		$sequence_posts = $sequence->getPosts();

		// Iterate through all of the posts
		foreach ( $sequence_posts as $sequence_post )
		{
			// Iterate through all users (TODO: Fix this as it will be slow for a large system!)
			foreach ( $users as $user )
			{
				$notified = get_user_meta( $user->user_id,'pmpro_seq_notified', true );

				// Check whether the userID has access to this sequence post and if the post isn't previously "notified"
				if ( pmpro_sequence_hasAccess( $user->user_id, $sequence_post->id ) && !in_array( $sequence_post->id, $notified ) )
				{
					// Send the email to the user about this post
					$sequence->sendEmail( $sequence_post->id, $user->user_id );

					// Update the sequence metadata that user has been notified
					$notified[] = $sequence_post->id;
					update_user_meta( $user->user_id, 'pmpro_seq_notified', $notified );
				}
			}
		}
	}
}
endif;