<?php
/* Check for new content, email a link/dummsty to the user if new content exists. */
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

	foreach($seq as $s)
	{
		$sequence = new PMProSequences($s->ID);

		// Grab the settings for this sequence.
		$seq_settings = $sequence->fetchOptions($s->ID);

		// Check if sequence is configured to send member updates. Return if not.
		if ($seq_settings->sendNotice != 1) {
			$sequence->dbgOut('Not configured to send notices to users. Exiting!');
			return;
		}

		$sequence_posts = $sequence->getPosts();

		foreach($sequence_posts as $sequence_post)
		{
			foreach($users as $user)
			{
				$notified = get_user_meta($user->user_id,'pmpro_seq_notified', true);

				if(pmpro_sequence_hasAccess($user->user_id, $sequence_post->id) && !in_array($sequence_post->id, $notified))
				{
					$sequence->sendEmail($sequence_post->id, $user->user_id);
					$notified[] = $sequence_post->id;
					update_user_meta($user->user_id, 'pmpro_seq_notified', $notified);
				}
			}
		}
	}
}
endif;