<?php
/**
 * Copyright (c) 2017 - Eighty / 20 Results by Wicked Strong Chicks.
 * ALL RIGHTS RESERVED
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace E20R\Sequences\Modules\Licensed\Unsubscribe;


use E20R\Sequences\Sequence\Controller;
use E20R\Utilities\Utilities;
use E20R\Utilities\Licensing\Licensing;

class Unsubscribe_Link {
	/**
	 * @var null|Unsubscribe_Link
	 */
	private static $instance = null;
	
	/**
	 * Load any action hooks and filter hooks
	 *
	 * @access private
	 */
	private function load_hooks() {
		
		// TODO: Implement any hook handler(s)
		if ( Licensing::is_licensed( Controller::plugin_prefix ) ) {
			add_action( 'wp_ajax_nopriv_unsub_sequence_notification', array( $this, 'unsubscribe_handler' ) );
		}
	}
	
	public function unsubscribe_handler() {
		
		$utils = Utilities::get_instance();
		
		// No more than 24 hours for the timeout value
		$window_max = intval( apply_filters( 'e20r-sequence-unsubscribe-timeout-max', DAY_IN_SECONDS ) );
		
		if ( $window_max > DAY_IN_SECONDS ) {
			
			$utils->add_message( __( 'Invalid max value for the unsubscription timeout', Controller::plugin_slug ), 'warning', 'backend' );
			$window_max = DAY_IN_SECONDS;
		}
		
		// Use GMT when handling timeout window(s)
		$timeout = current_time( 'timestamp', true ) - $window_max;
		
		$encoded_data = get_query_var( 'sequence_id' );
		
		/**
		 * $user_data = array(
		 *        'member' => $user_id,
		 *        'sequence' => $sequence_id,
		 *        'when' => $timestamp,
		 * );
		 */
		$user_data = json_decode( base64_decode( urldecode( $encoded_data ) ) );
		
		// TODO: Process unsubscribe/deactivate notification emails
		
	}
	
	/**
	 * Generate unique link to let users unsubscribe to email notifications from the email notice
	 *
	 * @param int $user_id
	 * @param int $sequence_id
	 *
	 * @return string|null
	 */
	public static function create_unsubscribe_link( $user_id, $sequence_id ) {
		
		if ( false === Licensing::is_licensed( Controller::plugin_prefix ) ) {
			return null;
		}
		
		$utils     = Utilities::get_instance();
		$timestamp = current_time( 'timestamp', true ); // Use GMT for timeout window(s)
		
		$user_data = array(
			'member'   => $user_id,
			'sequence' => $sequence_id,
			'when'     => $timestamp,
		);
		
		$encoded_data = urlencode( base64_encode( json_encode( $user_data ) ) );
		$utils->log( "Encoded user data: {$encoded_data}" );
		
		$secure_url = esc_url_raw(
			wp_nonce_url(
				add_query_arg(
					array(
						'action'      => 'unsub_sequence_notification',
						'sequence_id' => $encoded_data,
					),
					admin_url( 'admin-ajax.php' )
				)
			)
		);
		
		$utils->log( "Returning URL for unsubscribe link: {$secure_url}" );
		
		return $secure_url;
	}
	
	/**
	 * Returns an instance of the class (Singleton pattern)
	 *
	 * @return Unsubscribe_Link|null
	 */
	public static function get_instance() {
		
		if ( is_null( self::$instance ) ) {
			
			self::$instance = new self;
			
			self::$instance->load_hooks();
		}
		
		return self::$instance;
	}
}
