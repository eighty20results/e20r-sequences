<?php

namespace E20R\Sequences\Tools;

/*
  License:

	Copyright 2014-2017 Eighty / 20 Results by Wicked Strong Chicks, LLC (thomas@eighty20results.com)

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

use E20R\Utilities\Utilities;
use E20R\Utilities\Licensing\Licensing;
use E20R\Sequences\Sequence\Controller;
use E20R\Sequences\Main\E20R_Sequences;
use E20R\Utilities\Cache;

class Cron {
	/**
	 * Refers to a single instance of this class.
	 *
	 * @var Cron|null
	 */
	private static $_this = null;
	
	/**
	 * Cron constructor.
	 */
	function __construct() {
		
		if ( null != self::$_this ) {
			$error_message = sprintf(
				__( "Attempted to load a second instance of a singleton class (%s)", Controller::plugin_slug ),
				get_class( $this )
			);
			
			error_log( $error_message );
			wp_die( $error_message );
		}
		
		self::$_this = $this;
	}
	
	/**
	 * Set the default schedule for the cron hooks
	 */
	static public function schedule_default() {
		
		$existing     = wp_get_schedule( "e20r_sequence_cron_hook" );
		$old_schedule = wp_get_schedule( "pmpro_sequence_cron_hook" );
		
		if ( false !== $existing ) {
			wp_clear_scheduled_hook( 'e20r_sequence_cron_hook' );
			wp_schedule_event( current_time( 'timestamp' ), 'daily', "e20r_sequence_cron_hook" );
		}
		
		if ( ( false !== $old_schedule ) && ( ! class_exists( "PMProSequence" ) ) ) {
			wp_clear_scheduled_hook( 'pmpro_sequence_cron_hook' );
		}
	}
	
	/**
	 * Update the when we're supposed to run the New Content Notice cron job for this sequence.
	 *
	 * @access public
	 */
	static public function update_user_notice_cron() {
		
		/* TODO: Does not support Daylight Savings Time (DST) transitions well! - Update check hook in init? */
		
		$sequence = Controller::get_instance();
		$utils    = Utilities::get_instance();
		
		$prev_scheduled = false;
		try {
			
			// Check if the job is previously scheduled. If not, we're using the default cron schedule.
			if ( false !== ( $timestamp = wp_next_scheduled( 'e20r_sequence_cron_hook', array( $sequence->sequence_id ) ) ) ) {
				
				// Clear old cronjob for this sequence
				$utils->log( "Current cron job for sequence # {$sequence->sequence_id} scheduled for{$timestamp}" );
				$prev_scheduled = true;
				
				// wp_clear_scheduled_hook($timestamp, 'e20r_sequence_cron_hook', array( $this->sequence_id ));
			}
			
			$utils->log( ' Next scheduled at (timestamp): ' . print_r( wp_next_scheduled( 'e20r_sequence_cron_hook', array( $sequence->sequence_id ) ), true ) );
			
			// Set time (what time) to run this cron job the first time.
			$utils->log( "Alerts for sequence # {$sequence->sequence_id} at " . date_i18n( 'Y-m-d H:i:s', $sequence->options->noticeTimestamp ) . " UTC" );
			
			if ( ( true === $prev_scheduled ) &&
			     ( $sequence->options->noticeTimestamp != $timestamp )
			) {
				
				$utils->log( "Admin changed when the job is supposed to run. Deleting old cron job for sequence w/ID: {$sequence->sequence_id}" );
				wp_clear_scheduled_hook( 'e20r_sequence_cron_hook', array( $sequence->sequence_id ) );
				
				// Schedule a new event for the specified time
				if ( false === wp_schedule_event(
						$sequence->options->noticeTimestamp,
						'daily',
						'e20r_sequence_cron_hook',
						array( $sequence->sequence_id )
					)
				) {
					
					$sequence->set_error_msg( printf( __( 'Could not schedule new content alert for %s', Controller::plugin_slug ), $sequence->options->noticeTime ) );
					$utils->log( "Did not schedule the new cron job at {$sequence->options->noticeTime} for this sequence (# {$sequence->sequence_id})" );
					
					return false;
				}
			} else if ( false === $prev_scheduled ) {
				wp_schedule_event( $sequence->options->noticeTimestamp, 'daily', 'e20r_sequence_cron_hook', array( $sequence->sequence_id ) );
			} else {
				$utils->log( " Timestamp didn't change so leave the schedule as-is" );
			}
			
			// Validate that the event was scheduled as expected.
			$cron_ts = wp_next_scheduled( 'e20r_sequence_cron_hook', array( $sequence->sequence_id ) );
			
			$utils->log( ' According to WP, the job is scheduled for: ' . date_i18n( 'd-m-Y H:i:s', $cron_ts ) . ' UTC and we asked for ' . date( 'd-m-Y H:i:s', $sequence->options->noticeTimestamp ) . ' UTC' );
			
			if ( intval( $cron_ts ) !== intval( $sequence->options->noticeTimestamp ) ) {
				$utils->log( "Timestamp for actual cron entry doesn't match the one in the options..." );
			}
		} catch ( \Exception $exception ) {
			
			$utils->log( "Error updating cron job(s): " . $exception->getMessage() );
			
			if ( is_null( $sequence->get_error_msg() ) ) {
				$utils->add_message( "Exception in update_user_notice_cron(): " . $exception->getMessage(), 'error', 'backend' );
			}
			
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
		
		$utils = Utilities::get_instance();
		$utils->log( "Removing alert notice hook for sequence # {$sequence_id}" );
		
		if ( is_null( $sequence_id ) ) {
			wp_clear_scheduled_hook( 'e20r_sequence_cron_hook' );
			
			if ( ! class_exists( "PMProSequence" ) ) {
				wp_clear_scheduled_hook( 'pmpro_sequence_cron_hook' );
			}
			
		} else {
			wp_clear_scheduled_hook( 'e20r_sequence_cron_hook', array( $sequence_id ) );
			
			if ( ! class_exists( "PMProSequence" ) ) {
				wp_clear_scheduled_hook( 'pmpro_sequence_cron_hook', array( $sequence_id ) );
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
	public static function check_for_new_content( $sequence_id = null ) {
		
		$all_sequences = false; // Default: Assume only one sequence is being processed.
		$received_id   = null;
		
		$sequence          = Controller::get_instance();
		$utils             = Utilities::get_instance();
		$sequence->is_cron = true; // This is a cron job (only)
		
		// Process arguments we may (or may not) have received from WP_Cron
		if ( is_array( $sequence_id ) ) {
			
			$utils->log( "Received argument as array (Cron job): " . print_r( $sequence_id, true ) );
			
			$received_id = array_pop( $sequence_id );
		}
		
		if ( ! is_array( $sequence_id ) && ! is_null( $sequence_id ) ) {
			
			$received_id = $sequence_id;
		}
		
		// No sequence number received, so processing all sequences
		if ( empty( $sequence_id ) ) {
			$all_sequences = true;
		}
		
		$utils->log( "Sequence {$received_id} is ready to process messages... (received: " . ( is_null( $received_id ) ? 'null' : $received_id ) . ")" );
		
		// Get the data from the database
		$sequence_list = self::get_user_sequence_list( $received_id );
		
		$utils->log( "Found " . count( $sequence_list ) . " sequences to process for {$received_id}" );
		
		// Track user send-count (just in case we'll need it to ensure there's not too many mails being sent to one user.
		$send_count[] = array();
		
		// Get ready to possibly use the background notice handler
		$user_handler = $sequence->get_user_handler();
		$is_licensed = Licensing::is_licensed( Controller::plugin_slug );
		
		// Loop through all selected sequences and users
		foreach ( $sequence_list as $user_data ) {
			
			if ( true === $is_licensed ) {
				
				$utils->log( "Using background processing" );
				$queue_data = array( 'user_data' => $user_data, 'all_sequences' => $all_sequences );
				$user_handler->push_to_queue( $queue_data );
				
			} else {
				
				// Set the user ID we're processing for:
				$sequence->e20r_sequence_user_id = $user_data->user_id;
				$sequence->sequence_id           = $user_data->seq_id;
				
				// Load sequence data
				if ( ! $sequence->init( $user_data->seq_id ) ) {
					
					$utils->log( "Sequence {$user_data->seq_id} is not converted to V3 metadata format. Exiting!" );
					$sequence->set_error_msg( __( "Please de-activate and activate the E20R Sequences for Paid Memberships Pro plug-in to facilitate conversion to the v3 meta data format.", Controller::plugin_slug ) );
					continue;
				}
				
				$utils->log( "Processing sequence: {$sequence->sequence_id} for user {$user_data->user_id}" );
				
				if ( ( $sequence->options->sendNotice == 1 ) && ( $all_sequences === true ) ) {
					$utils->log( 'This sequence will be processed directly. Skipping it for now (All)' );
					continue;
				}
				
				// Get user specific settings regarding sequence alerts.
				$utils->log( "Loading alert settings for user {$user_data->user_id} and sequence {$sequence->sequence_id}" );
				$notice_settings = $sequence->load_user_notice_settings( $user_data->user_id, $sequence->sequence_id );
				// $utils->log($notice_settings);
				
				// Check if this user wants new content notices/alerts
				// OR, if they have not opted out, but the admin has set the sequence to allow notices
				if ( ( isset( $notice_settings->send_notices ) && ( $notice_settings->send_notices == 1 ) ) ||
				     ( empty( $notice_settings->send_notices ) &&
				       ( $sequence->options->sendNotice == 1 ) )
				) {
					
					$utils->log( "Sequence {$sequence->sequence_id} is configured to send new content notices to users." );
					
					// Load posts for this sequence.
					// $sequence_posts = $sequence->getPosts();
					
					$membership_day = $sequence->get_membership_days( $user_data->user_id );
					$posts          = $sequence->find_posts_by_delay( $membership_day, $user_data->user_id );
					
					if ( empty( $posts ) ) {
						
						$utils->log( "Skipping Alert: Did not find a valid/current post for user {$user_data->user_id} in sequence {$sequence->sequence_id}" );
						// No posts found!
						continue;
					}
					
					$utils->log( "# of posts we've already notified for: " . count( $notice_settings->posts ) . ", and number of posts to process: " . count( $posts ) );
					
					// Set the opt-in timestamp if this is the first time we're processing alert settings for this user ID.
					if ( empty( $notice_settings->last_notice_sent ) || ( $notice_settings->last_notice_sent == - 1 ) ) {
						
						$notice_settings->last_notice_sent = current_time( 'timestamp' );
					}
					
					// $posts = $sequence->get_postDetails( $post->id );
					$utils->log( "noticeSendAs option is currently: {$sequence->options->noticeSendAs}" );
					
					if ( empty( $sequence->options->noticeSendAs ) || E20R_SEQ_SEND_AS_SINGLE == $sequence->options->noticeSendAs ) {
						
						$utils->log( "Processing " . count( $posts ) . " individual messages to send to {$user_data->user_id}" );
						
						foreach ( $posts as $post_data ) {
							
							if ( $post_data->delay == 0 ) {
								$utils->log( "Since the delay value for this post {$post_data->id} is 0 (confirm: {$post_data->delay}), user {$user_data->user_id} won't be notified for it..." );
								continue;
							}
							
							$utils->log( "Do we notify {$user_data->user_id} of availability of post # {$post_data->id}?" );
							$flag_value = "{$post_data->id}_" . $sequence->normalize_delay( $post_data->delay );
							
							if ( ! in_array( $flag_value, $notice_settings->posts ) ) {
								
								$utils->log( 'Post: "' . get_the_title( $post_data->id ) . '"' .
								             ', post ID: ' . $post_data->id .
								             ', membership day: ' . $membership_day .
								             ', post delay: ' . $sequence->normalize_delay( $post_data->delay ) .
								             ', user ID: ' . $user_data->user_id .
								             ', already notified: ' . ( ! is_array( $notice_settings->posts ) || ( in_array( $flag_value, $notice_settings->posts ) == false ) ? 'false' : 'true' ) .
								             ', has access: ' . ( $sequence->has_post_access( $user_data->user_id, $post_data->id, true, $sequence->sequence_id ) === true ? 'true' : 'false' ) );
								
								$utils->log( "Need to send alert to {$user_data->user_id} for '{$post_data->title}': {$flag_value}" );
								
								// Does the post alert need to be sent (only if its delay makes it available _after_ the user opted in.
								if ( $sequence->is_after_opt_in( $user_data->user_id, $notice_settings, $post_data ) ) {
									
									$utils->log( 'Preparing the email message' );
									
									// Send the email notice to the user
									if ( $sequence->send_notice( $post_data, $user_data->user_id, $sequence->sequence_id ) ) {
										
										$utils->log( 'Email was successfully sent' );
										// Update the sequence metadata that user has been notified
										$notice_settings->posts[] = $flag_value;
										
										// Increment send count.
										$send_count[ $user_data->user_id ] = ( isset( $send_count[ $sequence_data->user_id ] ) ? $send_count[ $user_data->user_id ] ++ : 0 ); // Bug/Fix: Sometimes generates an undefined offset notice
										
										$utils->log( "Sent email to user {$user_data->user_id} about post {$post_data->id} with delay {$post_data->delay} in sequence {$sequence->sequence_id}. The SendCount is {$send_count[ $user_data->user_id ]}" );
										$notice_settings->last_notice_sent = current_time( 'timestamp' );
									} else {
										
										$utils->log( "Error sending email message!" );
									}
								} else {
									
									// Only add this post ID if it's not already present in the notifiedPosts array.
									if ( ! in_array( "{$post_data->id}_{$post_data->delay}", $notice_settings->posts, true ) ) {
										
										$utils->log( "Adding this previously released (old) post to the notified list" );
										$notice_settings->posts[] = "{$post_data->id}_" . $sequence->normalize_delay( $post_data->delay );
									}
								}
							} else {
								$utils->log( "Will NOT notify user {$user_data->user_id} about the availability of post {$post_data->id}" );
							}
						} // End of foreach
					} // End of "send as single"
					
					if ( E20R_SEQ_SEND_AS_LIST == $sequence->options->noticeSendAs ) {
						
						$alerts = array();
						
						foreach ( $posts as $post_key => $post_data ) {
							
							$flag_value = "{$post_data->id}_" . $sequence->normalize_delay( $post_data->delay );
							
							if ( in_array( $flag_value, $notice_settings->posts, true ) ) {
								
								dbg( "We already sent notice for {$flag_value}" );
								unset( $posts[ $post_key ] );
								
							} else {
								
								$utils->log( 'Adding notification setting for : "' . get_the_title( $post_data->id ) . '"' .
								             ', post ID: ' . $post_data->id .
								             ', membership day: ' . $membership_day .
								             ', post delay: ' . $sequence->normalize_delay( $post_data->delay ) .
								             ', user ID: ' . $user_data->user_id .
								             ', already notified: ' . ( ! is_array( $notice_settings->posts ) || ( in_array( $flag_value, $notice_settings->posts ) == false ) ? 'false' : 'true' ) .
								             ', has access: ' . ( $sequence->has_post_access( $user_data->user_id, $post_data->id, true, $sequence->sequence_id ) === true ? 'true' : 'false' ) );
								
								$utils->log( "Adding this ({$post_data->id}) post to the possibly notified list: {$flag_value}" );
								$alerts[ $post_key ] = $flag_value;
								
								$posts[ $post_key ]->after_optin = $sequence->is_after_opt_in( $user_data->user_id, $notice_settings, $post_data );
							}
							
						}
						
						$utils->log( "Sending " . count( $posts ) . " as a list of links to {$user_data->user_id}" );
						
						// Send the email notice to the user
						if ( $sequence->send_notice( $posts, $user_data->user_id, $sequence->sequence_id ) ) {
							
							$notice_settings->last_notice_sent = current_time( 'timestamp' );
							$notice_settings->posts            = array_merge( $notice_settings->posts, $alerts );
							
							$utils->log( "Merged notification settings for newly sent posts: " );
							$utils->log( $notice_settings->posts );
							$utils->log( $alerts );
							
						} else {
							
							$utils->log( "Will NOT notify user {$user_data->user_id} about these " . count( $posts ) . " new posts" );
						}
						
					} // End of "send as list"
					
					// Save user specific notification settings (including array of posts we've already notified them of)
					$sequence->save_user_notice_settings( $user_data->user_id, $notice_settings, $sequence->sequence_id );
					$utils->log( 'Updated meta for the user notices' );
					
				} // End if
				else {
					
					// Move on to the next one since this one isn't configured to send notices
					$utils->log( "Sequence {$user_data->seq_id} is not configured for sending alerts. Skipping..." );
				} // End of sendNotice test
				
			} // End of licensing check
			
		} // End of data processing loop
		
		if ( true === $is_licensed ) {
			$utils->log("Save and dispatch background send of alerts");
			$user_handler->save()->dispatch();
		}
		$utils->log( "Completed execution of cron job for {$received_id}" );
	}
	
	/**
	 * Return the Cron class instance (when using singleton pattern)
	 *
	 * @return Cron $this
	 */
	public static function get_instance() {
		
		if ( is_null( self::$_this ) ) {
			self::$_this = new self;
		}
		
		return self::$_this;
	}
	
	/**
	 * Loads a sequence (or a list of sequences) and its consumers (users)
	 *
	 * @param null $sequence_id - The ID of the sequence to load info about
	 *
	 * @return mixed -  Returns records containing
	 *
	 * @since 4.2.6
	 * @since 5.0 - Cache the user sequence list (is membership plugin specific)
	 */
	private static function get_user_sequence_list( $sequence_id = null ) {
		
		$result = array();
		$utils  = Utilities::get_instance();
		
		if ( null === ( $result = Cache::get( 'member_user_posts',  E20R_Sequences::cache_key ) ) ) {
			
			$result = apply_filters( 'e20r-sequence-get-protected-users-posts', $result, $sequence_id );
			
			if ( !empty( $result ) ) {
				Cache::set( 'member_user_posts', $result, 30 * MINUTE_IN_SECONDS, E20R_Sequences::cache_key );
			}
		}
		
		return $result;
	}
}
