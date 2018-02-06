<?php

namespace E20R\Sequences\Tools;

/*
  License:

	Copyright 2014-2018 Eighty / 20 Results by Wicked Strong Chicks, LLC (thomas@eighty20results.com)

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
	 * @since v5.0 - ENHANCEMENT: Renamed $_this variable to $instance
	 */
	private static $instance = null;
	
	/**
	 * Cron constructor.
	 */
	function __construct() {
		
		$utils = Utilities::get_instance();
		
		if ( null != self::$instance ) {
			$error_message = sprintf(
				__( "Attempted to load a second instance of a singleton class (%s)", Controller::plugin_slug ),
				get_class( $this )
			);
			$utils->log( $error_message );
			wp_die( $error_message );
		}
		
		self::$instance = $this;
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
				
				// TODO: self::update_for_DST( $timestamp );
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
	 * Update when the 'e20r_sequence_cron_hook' job runs due to DST changes if needed
	 *
	 * @param int $timestamp
	 *
	 * @since 5.0 - ENHANCEMENT: Stub function for future ability to update the cron job based on DST changes
	 */
	static public function update_for_DST( $timestamp ) {
		
		// TODO: Figure out whether today is a DST change day
		
		// TODO: Change the scheduled time for the cron job to match the new (DST) time.
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
	 * @since 5.0 - BUG FIX: Didn't identify the specified sequence number during new content notice transmission
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
		
		if ( ! is_array( $sequence_id ) && ! empty( $sequence_id ) ) {
			
			$received_id = $sequence_id;
		}
		
		// No sequence number received, so processing all sequences
		/**
		 * @since 5.0 - BUG FIX: Didn't identify the specified sequence number during new content notice transmission
		 */
		if ( empty( $received_id ) ) {
			$all_sequences = true;
		}
		
		$utils->log( "Sequence {$received_id} is ready to process messages... (received: " . ( is_null( $received_id ) ? 'null' : $received_id ) . ")" );
		
		// Get the data from the database
		$sequence_list = self::get_user_sequence_list( $received_id );
		
		$utils->log( "Found " . count( $sequence_list ) . " sequences to process for {$received_id}" );
		
		// Get ready to possibly use the background notice handler
		$user_handler = $sequence->get_user_handler();
		$plus_license = Licensing::is_licensed( Controller::plugin_prefix );
		
		// Loop through all selected sequences and users
		foreach ( $sequence_list as $user_data ) {
			
			if ( true === $plus_license ) {
				
				$utils->log( "Using background processing" );
				$queue_data = array( 'user_data' => $user_data, 'all_sequences' => $all_sequences );
				$user_handler->push_to_queue( $queue_data );
				
			} else {
				
				$membership_day = $sequence->get_membership_days( $user_data->user_id );
				$posts          = $sequence->find_posts_by_delay( $membership_day, $user_data->user_id );
				
				$user_notice = new User_Notice( $posts,$user_data->user_id, $received_id, $membership_day, true );
				
				if ( !empty( $user_notice ) ) {
					
					if ( false === $user_notice->create_new_notice( $all_sequences ) ) {
						$utils->log("Error creating new user (content) notice message!");
					}
				}
				
				// Clean up.
				unset( $user_notice );
				
			} // End of licensing check
		} // End of data processing loop
		
		if ( true === $plus_license ) {
			$utils->log("Save and dispatch background send of alerts");
			$user_handler->save()->dispatch();
		}
		
		$utils->log( "Completed execution of cron job for {$received_id}" );
	}
	
	/**
	 * Loads a sequence (or a list of sequences) and its consumers (users)
	 *
	 * @param null $sequence_id - The ID of the sequence to load info about
	 *
	 * @return mixed -  Returns records containing
	 *
	 * @since 4.2.6
	 * @since 5.0 - ENHANCEMENT: Cache the protected user/post list for the sequence(s) (is membership plugin specific)
	 * @since 5.0 - ENHANCEMENT: Using filter to fetch list of protected posts for the active members
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
		
		$utils->log("Result is: " . print_r( $result, true ));
		return $result;
	}
	
	/**
	 * Return the Cron class instance (when using singleton pattern)
	 *
	 * @return Cron $this
	 */
	public static function get_instance() {
		
		if ( is_null( self::$instance ) ) {
			self::$instance = new self;
		}
		
		return self::$instance;
	}
}
