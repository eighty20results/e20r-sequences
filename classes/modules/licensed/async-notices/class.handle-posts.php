<?php
/**8 * Copyright 2014-2018 Eighty / 20 Results by Wicked Strong Chicks, LLC (thomas@eighty20results.com)
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

use E20R\Utilities\Email_Notice\Send_Email;
use E20R\Sequences\Tools\User_Notice;
use E20R\Utilities\Utilities;
use E20R\Sequences\Sequence\Controller;
use E20R\Utilities\E20R_Background_Process;

class Handle_Posts extends E20R_Background_Process {
	
	private static $instance = null;
	
	protected $action;
	
	/**
	 * Constructor for Handle_Posts class
	 *
	 * @param object $calling_class
	 */
	public function __construct( $calling_class ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "Instantiated Handle_Posts class" );
		
		self::$instance = $this;
		
		$class_string = get_class( $calling_class );
		$class_name   = explode( '\\', $class_string );
		$this->action = "seq_post_" . strtolower( $class_name[ ( count( $class_name ) - 1 ) ] );
		
		$utils->log( "Set Action variable to {$this->action} for Handle_Posts" );
		
		// Required: Run the parent class constructor
		parent::__construct();
	}
	
	/**
	 * @param array $queue_data
	 *
	 * @return mixed
	 *
	 * @throws \Exception
	 */
	public function task( $queue_data ) {
		
		// Do the work on a per-user basis
		
		$sequence    = Controller::get_instance();
		$utils       = Utilities::get_instance();
		$notice_list = array();
		
		if ( E20R_SEQ_SEND_AS_LIST === $queue_data['send_as'] ) {
			// An array of data objects (E20R_SEQ_SEND_AS_LIST)
			$notice_list = $queue_data;
		} else if ( E20R_SEQ_SEND_AS_SINGLE === $queue_data['send_as'] ) {
			// Single post (E20R_SEQ_SEND_AS_SINGE)
			$notice_list = array( $queue_data );
		}
		
		// List/array of data/posts to process
		foreach ( $notice_list as $queue_data ) {
			
			/**
			 * @var User_Notice $user_notice
			 */
			$user_notice     = $queue_data['user_notice'];
			$notice_settings = $queue_data['notice_settings'];
			$content_data    = $queue_data['content_data'];
			$send_as         = $queue_data['send_as'];
			$all_sequences   = $queue_data['all_sequences'];
			
			$notice_user_id = $user_notice->get_user_id();
			$content_delay  = $sequence->normalize_delay( $content_data->delay );
			
			$flag_value = "{$content_data->id}_{$content_delay}";
			$send_count = array();
			
			// $posts = $sequence->get_postDetails( $post->id );
			$utils->log( "noticeSendAs option is currently: {$send_as}" );
			
			if ( $content_delay == 0 ) {
				$utils->log( "Since the delay value for this post {$content_data->id} is 0 (confirm: {$content_delay}), user (ID: {$notice_user_id}) can't/won't be notified for it..." );
				
				return false;
			}
			
			$utils->log( "Do we notify user of availability of post # {$content_data->id}?" );
			$user_notice->create_new_notice( $all_sequences );
			
			$notice_settings->posts[]          = $flag_value;
			$notice_settings->last_notice_sent = current_time( 'timestamp' );
			$send_count[ $notice_user_id ]     = ( isset( $send_count[ $notice_user_id ] ) ? $send_count[ $notice_user_id ] ++ : 0 ); // Bug/Fix: Sometimes generates an undefined offset notice
			
			
			$utils->log( "Sent email to user {$notice_user_id} about post {$content_data->id} with delay {$content_data->delay} in sequence {$sequence->sequence_id}. The SendCount is {$send_count[ $notice_user_id ]}" );
			
			
		}
		
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
