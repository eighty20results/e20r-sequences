<?php
/**
 * Copyright 2014-2018 Eighty / 20 Results by Wicked Strong Chicks, LLC (thomas@eighty20results.com)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2, as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

namespace E20R\Sequences\Modules\Licensed\Async_Notices;

use E20R\Sequences\Tools\User_Notice;
use E20R\Utilities\Utilities;
use E20R\Sequences\Sequence\Controller;
use E20R\Utilities\E20R_Background_Process;

class Handle_User extends E20R_Background_Process {
	
	private static $instance = null;
	
	/**
	 * Action name (type)
	 *
	 * @var string
	 */
	protected $action;
	
	/**
	 * Constructor for Handle_User class
	 *
	 * @param object $calling_class
	 */
	public function __construct( $calling_class ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "Instantiated Handle_User class" );
		
		self::$instance = $this;
		
		$class_string = get_class( $calling_class );
		$class_name   = explode( '\\', $class_string );
		$this->action = "seq_user_" . strtolower( $class_name[ ( count( $class_name ) - 1 ) ] );
		
		$utils->log( "Set Action variable to {$this->action} for Handle_User" );
		
		// Required: Run the parent class constructor
		parent::__construct();
	}
	
	/**
	 * @param array $queue_data
	 *
	 * @return bool
	 *
	 * @throws \Exception
	 */
	public function task( $queue_data ) {
		
		// Do the work on a per-user basis
		$utils     = Utilities::get_instance();
		$sequence  = Controller::get_instance();
		$post_list = array();
		
		$utils->log( "Queue data is: " . print_r( $queue_data, true ) );
		
		$user_notice = new User_Notice( $queue_data['user_data']->user_id, $queue_data['user_data']->seq_id, null, null, true );
		
		$utils->log( "Processing sequence (ID: {$queue_data['user_data']->seq_id}) for user (ID: {$queue_data['user_data']->user_id})" );
		
		$send_as          = $user_notice->send_type();
		$send_user_notice = $user_notice->send_notice_to_user();
		
		// Check if this user wants new content notices/alerts
		// OR, if they have not opted out, but the admin has set the sequence to allow notices
		if ( ( true === $send_user_notice ) || ( false === $send_user_notice && true === $user_notice->send_notices() ) ) {
			
			$utils->log( "Sequence {$queue_data['user_data']->seq_id} is configured to send new content notices to the current user (ID: {$queue_data['user_data']->user_id})." );
			
			$utils->log( "noticeSendAs option is currently: {$send_as}" );
			
			$posts        = $user_notice->get_post_list();
			$post_handler = $sequence->get_post_handler();
			
			foreach ( $posts as $content_key => $content_data ) {
				
				$post_info = array(
					'send_as'         => $send_as,
					'user_notice'     => $user_notice,
					'notice_settings' => $sequence->load_user_notice_settings( $queue_data['user_data']->user_id, $queue_data['user_data']->seq_id ),
					'content_data'    => $content_data,
					'all_sequences'   => (bool) $queue_data['all_sequences'],
				);
				
				// Append to list of post(s) to process
				if ( E20R_SEQ_SEND_AS_LIST === $send_as ) {
					$utils->log( "Appending to digest list of posts" );
					$post_list[] = $post_info;
					
					// Send individual post to queue
				} else if ( E20R_SEQ_SEND_AS_SINGLE === $send_as ) {
					
					$utils->log( "Adding single entry post info to post handler" );
					$post_handler->push_to_queue( $post_info );
					
				} else {
					$utils->log( "No setting specified for noticeSendAs?!?! {$send_as}" );
				}
				
			} // End of foreach
			
			if ( ! empty( $post_list ) ) {
				$utils->log( "Processing for digest/list message" );
				$post_handler->push_to_queue( $post_list );
			}
			
			$utils->log( "Save the post handler queue and dispatch it" );
			$post_handler->save()->dispatch();
			
		} // End if
		else {
			
			// Move on to the next one since this one isn't configured to send notices
			$utils->log( "Sequence {$queue_data['user_data']->seq_id} is not configured to send user content alerts. Skipping..." );
		} // End of sendNotice test
		
		// Remove the current entry/task from the task list
		return false;
	}
	
	/**
	 *
	 */
	public function complete() {
		
		parent::complete();
		
		// Show notice to user or perform some other arbitrary task...
		$utils = Utilities::get_instance();
		$utils->log( "Completed sending notice(s) for all users/sequences" );
		
	}
}
