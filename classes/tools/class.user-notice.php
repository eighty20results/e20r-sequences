<?php
/**
 * Copyright (c) 2017-2018 - Eighty / 20 Results by Wicked Strong Chicks.
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

namespace E20R\Sequences\Tools;

use E20R\Utilities\Email_Notice\Send_Email;
use E20R\Utilities\Utilities;
use E20R\Utilities\Email_Notice\Email_Notice;
use E20R\Sequences\Sequence\Controller;

class User_Notice {
	
	/**
	 * @var array $posts
	 */
	private $posts = array();
	
	/**
	 * @var int $post_count
	 */
	private $post_count = 0;
	
	/**
	 * @var null|int $user_id
	 */
	private $user_id = null;
	
	/**
	 * @var null|\WP_User $user
	 */
	private $user = null;
	
	/**
	 * @var null|int $sequence_id
	 */
	private $sequence_id = null;
	
	/**
	 * @var int|null $membership_day
	 */
	private $membership_day = null;
	
	/**
	 * @var null|Controller $current_sequence
	 */
	private $current_sequence = null;
	
	/**
	 * @var bool $all_sequences
	 */
	private $all_sequences = false;
	
	/**
	 * User_Notice constructor.
	 *
	 * @param int        $user_id
	 * @param int        $sequence_id
	 * @param array|null $posts
	 * @param int|null   $membership_day
	 * @param bool|null  $is_cron
	 *
	 * @return bool|User_Notice
	 */
	public function __construct( $user_id, $sequence_id, $posts = null, $membership_day = null, $is_cron = false ) {
		
		$this->user_id     = $user_id;
		$this->sequence_id = $sequence_id;
		
		$this->current_sequence = Controller::get_instance();
		$utils                  = Utilities::get_instance();
		
		$this->current_sequence->is_cron = $is_cron;
		
		try {
			
			$this->current_sequence->init( $this->sequence_id );
		} catch ( \Exception $exception ) {
			$utils->log( "Unable to instantiate the Sequence (ID: {$this->sequence_id}! " . $exception->getMessage() );
			
			return false;
		}
		
		if ( is_null( $membership_day ) ) {
			$this->membership_day = $this->current_sequence->get_membership_days( $this->user_id );
		} else {
			$this->membership_day = $membership_day;
		}
		
		if ( ! empty( $posts ) ) {
			
			if ( is_array( $posts ) ) {
				$this->posts = $posts;
			} else {
				$this->posts = array( $posts );
			}
		} else {
			
			$this->posts = $this->current_sequence->find_posts_by_delay( $this->membership_day, $this->user_id );
			
			// Always returns an array (even if empty)
			if ( empty( $this->posts ) ) {
				$this->posts = array();
			}
		}
		
		$this->post_count = count( $this->posts );
		
		return $this;
	}
	
	/**
	 * Return the ID number for the Sequence being processed
	 *
	 * @return int|null
	 */
	public function get_sequence_id() {
		return $this->sequence_id;
	}
	
	/**
	 * Return the user's ID number (WP_User->ID)
	 *
	 * @return int|null
	 */
	public function get_user_id() {
		
		return $this->user_id;
	}
	
	/**
	 * Return the type of message to send
	 *
	 * @return int - E20R_SEQ_SEND_AS_LIST|E20R_SEQ_SEND_AS_SINGLE
	 */
	public function send_type() {
		
		return intval( $this->current_sequence->get_option_by_name( 'noticeSendAs' ) );
	}
	
	/**
	 * Send new content notice for this user?
	 *
	 * @return bool
	 */
	public function send_notice_to_user() {
		
		$user_settings = $this->current_sequence->load_user_notice_settings( $this->user_id, $this->sequence_id );
		
		return (bool) $user_settings->send_notices;
	}
	
	/**
	 * Generate the new notice message and send it (if applicable)
	 *
	 * @param bool $all_sequences
	 *
	 * @return bool
	 */
	public function create_new_notice( $all_sequences = false ) {
		
		$utils = Utilities::get_instance();
		$send_count = array();
		
		$utils->log( "Processing sequence: {$this->sequence_id} for user {$this->user_id}" );
		$send_notice = $this->send_notices();
		
		// TODO: Logic check if 'all_sequences' is set & what we're supposed to do!
		if ( ( true === $send_notice ) && ( true === $all_sequences ) ) {
			$utils->log( "This sequence (ID: {$this->sequence_id}) will be processed directly. Skipping it for now (All)" );
			
			return false;
		}
		
		// Get user specific settings regarding sequence alerts.
		$utils->log( "Loading alert settings for user {$this->user_id} and sequence {$this->sequence_id}" );
		$user_settings    = $this->current_sequence->load_user_notice_settings( $this->user_id, $this->sequence_id );
		$send_user_notice = (bool) $user_settings->send_notices;
		
		// Check if this user wants new content notices/alerts
		// OR, if they have not opted out, and the admin has set the sequence to allow notices
		if ( true === $send_user_notice && true === $send_notice ) {
			
			$utils->log( "Sequence {$this->sequence_id} is configured to send new content notices and this user (ID: {$this->user_id}) hasn't opted out!" );
			
			// Make sure this sequence has posts (for the user)
			if ( empty( $this->posts ) ) {
				
				$utils->log( "Skipping Alert: Did not find a valid/current post for user {$this->user_id} in sequence {$this->sequence_id}" );
				
				// No posts found!
				return false;
			}
			
			$utils->log( "# of posts we've already notified for: " . count( $user_settings->posts ) . ", and number of posts to process: {$this->post_count}" );
			
			// Set the opt-in timestamp if this is the first time we're processing alert settings for this user ID.
			if ( empty( $user_settings->last_notice_sent ) || ( $user_settings->last_notice_sent == - 1 ) ) {
				
				$user_settings->last_notice_sent = current_time( 'timestamp' );
			}
			
			// $posts = $sequence->get_postDetails( $post->id );
			$send_notice_as = intval( $this->current_sequence->get_option_by_name( 'noticeSendAs' ) );
			
			$utils->log( "noticeSendAs option is currently: {$send_notice_as}" );
			
			// Send (potentially) multiple messages to a single user (one per new piece of content in the sequence)
			if ( empty( $send_notice_as ) || E20R_SEQ_SEND_AS_SINGLE === $send_notice_as ) {
				
				$utils->log( "Processing {$this->post_count} individual messages to send to {$this->user_id}" );
				
				foreach ( $this->posts as $post_data ) {
					
					if ( $post_data->delay == 0 ) {
						$utils->log( "Since the delay value for this post {$post_data->id} is 0 (confirm: {$post_data->delay}), user {$this->user_id} won't be notified for it..." );
						continue;
					}
					
					$utils->log( "Do we notify {$this->user_id} of availability of post # {$post_data->id}?" );
					$content_delay = $this->current_sequence->normalize_delay( $post_data->delay );
					$flag_value    = "{$post_data->id}_{$content_delay}";
					
					if ( ! in_array( $flag_value, $user_settings->posts ) ) {
						
						try {
							$has_access_test = $this->current_sequence->has_post_access( $this->user_id, $post_data->id, true, $this->sequence_id );
						} catch ( \Exception $exception ) {
							
							$has_access_test = false;
							$utils->log( "Access test for {$post_data->id} in sequence (ID: {$this->sequence_id}) for user (ID: {$this->user_id} failed: " . $exception->getMessage() );
						}
						
						$utils->log(
							sprintf(
								'Post: "%1$s", post ID: %2$d, membership day: %3$d, post delay: %4$s, user ID: %5$d, already notified: %6$s, has access: %7$s',
								get_the_title( $post_data->id ),
								$post_data->id,
								$this->membership_day,
								$content_delay,
								$this->user_id,
								( ! is_array( $user_settings->posts ) || ( in_array( $flag_value, $user_settings->posts ) == false ) ? 'false' : 'true' ),
								( $has_access_test === true ? 'true' : 'false' )
							)
						);
						
						$utils->log( "Need to send alert to {$this->user_id} for '{$post_data->title}': {$flag_value}" );
						
						// Does the post alert need to be sent (only if its delay makes it available _after_ the user opted in.
						if ( $this->current_sequence->is_after_opt_in( $this->user_id, $user_settings, $post_data ) ) {
							
							$utils->log( 'Preparing the email message' );
							$template_name = $this->current_sequence->get_option_by_name( 'noticeTemplate' );
							$this->user    = get_user_by( 'ID', $this->user_id );
							
							// Send the email notice to the user
							if ( $this->prepare_and_send_notice() ) {
								
								$utils->log( 'Email was successfully sent' );
								// Update the sequence metadata that user has been notified
								$user_settings->posts[] = $flag_value;
								
								// Increment send count.
								$send_count[ $this->user_id ] = ( isset( $send_count[ $sequence_data->user_id ] ) ? $send_count[ $this->user_id ] ++ : 0 ); // Bug/Fix: Sometimes generates an undefined offset notice
								
								$utils->log( "Sent email to user {$this->user_id} about post {$post_data->id} with delay {$post_data->delay} in sequence {$this->sequence_id}. The SendCount is {$send_count[ $this->user_id ]}" );
								
								$user_settings->last_notice_sent = current_time( 'timestamp' );
								
							} else {
								
								$utils->log( "Error creating new notice message!" );
							}
						} else {
							
							// Only add this post ID if it's not already present in the notifiedPosts array.
							if ( ! in_array( "{$post_data->id}_{$content_delay}", $user_settings->posts, true ) ) {
								
								$utils->log( "Adding this previously released (old) post to the notified list" );
								$user_settings->posts[] = "{$post_data->id}_" . $this->current_sequence->normalize_delay( $post_data->delay );
							}
						}
					} else {
						$utils->log( "Will NOT notify user {$this->user_id} about the availability of post {$post_data->id}" );
					}
				} // End of foreach
			} // End of "send as single"
			
			// Processing a digest type of message
			if ( E20R_SEQ_SEND_AS_LIST === $send_notice_as ) {
				
				$alerts = array();
				
				foreach ( $this->posts as $post_key => $post_data ) {
					
					$content_delay = $this->current_sequence->normalize_delay( $post_data->delay );
					$flag_value    = "{$post_data->id}_{$content_delay}";
					
					if ( in_array( $flag_value, $user_settings->posts, true ) ) {
						
						dbg( "We already sent notice for {$flag_value}" );
						unset( $this->posts[ $post_key ] );
						
					} else {
						
						try {
							$has_access_test = $this->current_sequence->has_post_access( $this->user_id, $post_data->id, true, $this->sequence_id );
						} catch ( \Exception $exception ) {
							$has_access_test = false;
							$utils->log( "Access test for {$post_data->id} in sequence (ID: {$this->sequence_id}) for user (ID: {$this->user_id} failed: " . $exception->getMessage() );
						}
						
						$utils->log(
							sprintf(
								'Adding notification setting for: "%1$s", post ID: %2$d, membership day: %3$d, post delay: %4$s, user ID: %5$d, already notified: %6$s, has access: %7$s',
								get_the_title( $post_data->id ),
								$post_data->id,
								$this->membership_day,
								$content_delay,
								$this->user_id,
								( ! is_array( $user_settings->posts ) || ( in_array( $flag_value, $user_settings->posts ) == false ) ? 'false' : 'true' ),
								( $has_access_test === true ? 'true' : 'false' )
							)
						);
						$utils->log( "Adding this ({$post_data->id}) post to the possibly notified list: {$flag_value}" );
						$alerts[ $post_key ] = $flag_value;
						
						$this->posts[ $post_key ]->after_optin = $this->current_sequence->is_after_opt_in( $this->user_id, $user_settings, $post_data );
					}
					
				}
				
				$utils->log( "Sending {$this->post_count} as a list of links to {$this->user_id}" );
				
				// Send the email notice to the user
				if ( true === $this->prepare_and_send_notice() ) {
					
					$user_settings->last_notice_sent = current_time( 'timestamp' );
					$user_settings->posts            = array_merge( $user_settings->posts, $alerts );
					
					$utils->log( "Merged notification settings for newly sent posts: " );
					$utils->log( $user_settings->posts );
					$utils->log( print_r( $alerts, true ) );
					
				} else {
					
					$utils->log( "Will NOT notify user {$this->user_id} about these {$this->post_count} new posts" );
				}
				
			} // End of "send as list"
			
			// Save user specific notification settings (including array of posts we've already notified them of)
			$this->current_sequence->save_user_notice_settings( $this->user_id, $user_settings, $this->sequence_id );
			$utils->log( 'Updated meta for the user notices' );
			
			return true;
		} else {
			
			// Move on to the next one since this one isn't configured to send notices
			$utils->log( "Sequence {$this->sequence_id} is not configured for sending alerts. Skipping..." );
			
			return false;
		} // End of send_notices and sendNotice test
		
	}
	
	/**
	 * Returns true/false for whether the sequence should be sending new content notices
	 * @return bool
	 */
	public function send_notices() {
		return (bool) $this->current_sequence->get_option_by_name( 'sendNotice' );
	}
	
	/**
	 * Send email to userID about access to new post.
	 *
	 * @return bool - True if sent successfully. False otherwise.
	 *
	 * @access public
	 *
	 * TODO: Fix email body to be correct (standards compliant) MIME encoded HTML mail or text mail.
	 */
	public function prepare_and_send_notice() {
		
		$utils = Utilities::get_instance();
		
		$this->user = get_user_by( 'id', $this->user_id );
		$templ      = preg_split( '/\./', $this->current_sequence->get_option_by_name( 'noticeTemplate' ) ); // Parse the template name
		
		$emails = array();
		
		$post_links = '';
		$excerpt    = '';
		$post_urls  = array();
		
		$utils->log( "Preparing to send {$this->post_count} post alerts for user {$this->user_id} regarding sequence {$this->sequence_id}" );
		$utils->log( print_r( $templ, true ) );
		
		$send_as      = $this->current_sequence->get_option_by_name( 'noticeSendAs' );
		$user_started = ( $this->current_sequence->get_user_startdate( $this->user_id ) - DAY_IN_SECONDS );
		
		if ( empty( $send_as ) ) {
			
			$utils->log( "WARNING: Have to update the noticeSendAs setting!" );
			$this->current_sequence->set_option_by_name( 'noticeSendAs', E20R_SEQ_SEND_AS_SINGLE );
			$this->current_sequence->save_settings( $this->sequence_id );
		}
		
		
		foreach ( $this->posts as $notice_post ) {
			
			$as_list = false;
			
			$email = new Send_Email();
			$email->set_module( Controller::plugin_slug );
			
			$email->user_id         = $this->user_id;
			$email->to              = $this->user->user_email;
			$email->from            = $this->current_sequence->get_option_by_name( 'replyto' );
			$email->fromname        = $this->current_sequence->get_option_by_name( 'fromname' );
			$email->template        = $this->current_sequence->get_option_by_name( 'noticeTemplate' );
			$email->dateformat      = $this->current_sequence->get_option_by_name( 'dateformat' );
			$email->user_id         = $this->user->ID;
			$email->content_id_list = array( $notice_post->id );
			$email->body            = $email->load_template( $email->template );
			
			$post_date = date( $this->current_sequence->get_option_by_name( 'dateformat' ), ( $user_started + ( $this->current_sequence->normalize_delay( $notice_post->delay ) * DAY_IN_SECONDS ) ) );
			
			/**
			 * Let user modify URL and title/subject of email notice based on sequences or other plugins that
			 * allow embedding of WP_Post content
			 */
			$login_redirect_link  = apply_filters( 'e20r-sequence-alert-message-post-url', wp_login_url( esc_url_raw( $notice_post->permalink ) ), $notice_post, $this->user, $email->template );
			$post_title = apply_filters( 'e20r-sequence-alert-message-post-title', $notice_post->title, $notice_post, $this->user, $email->template );
			
			/**
			 * Set the email subject text
			 * (Doing it late to let us used the filtered post title)
			 */
			$email->subject         = sprintf( '%s: %s (%s)', $this->current_sequence->get_option_by_name( 'subject' ), $post_title, strftime( "%x", $user_started ) );
			
			// Send all of the links to new content in a single email message.
			if ( E20R_SEQ_SEND_AS_LIST === $send_as ) {
				
				$index      = 0;
				$post_links .= sprintf(
					'<li><a href="%1$s" title="%2$s">$2$s</a></li>',
					$login_redirect_link,
					$post_title
				);
				
				$post_urls[] = $login_redirect_link;
				
				if ( false === $as_list ) {
					
					$as_list = true;
					
					$email_type = apply_filters( 'e20r-email-notice-get-email-type', null, $email->template );
					$email->replace_variable_data( $email_type );
					
					$emails[ $index ] = $email;
					
					$data = array(
						// Options could be: display_name, first_name, last_name, nickname
						"name"      => apply_filters( 'e20r-sequence-alert-message-name', $this->user->user_firstname ),
						"sitename"  => apply_filters( 'e20r-sequence-site-name', get_option( "blogname" ) ),
						"today"     => apply_filters( 'e20r-sequence-alert-message-date', $post_date ),
						"excerpt"   => apply_filters( 'e20r-sequence-alert-message-excerpt-intro', $notice_post->excerpt ),
						"post_link" => apply_filters( 'e20r-sequence-alert-message-link-href-element', $post_links ),
						"post_urls" => apply_filters( 'e20r-sequence-alert-message-url-list', $post_urls ),
						"ptitle"    => apply_filters( 'e20r-sequence-alert-message-title', $notice_post->title ),
					);
					
					
					$emails[ $index ]->variables                     = apply_filters( 'e20r-sequence-email-substitution-fields', $data );
					$emails[ $index ]->variables['google_analytics'] = apply_filters( 'e20r-seq-google-tracking-info', null, $this->sequence_id );
				}
				
			} else if ( E20R_SEQ_SEND_AS_SINGLE === $send_as ) {
				
				$email_type = apply_filters( 'e20r-email-notice-get-email-type', null, $this->current_sequence->get_option_by_name( 'noticeTemplate' ) );
				$email->replace_variable_data( $email_type );
				
				// super defensive programming...
				$index = ( empty( $emails ) ? 0 : count( $emails ) - 1 );
				
				if ( ! empty( $notice_post->excerpt ) ) {
					
					$utils->log( "Adding the post excerpt to email notice" );
					$excerpt_intro = $this->current_sequence->get_option_by_name( 'excerptIntro' );
					
					if ( empty( $excerpt_intro ) ) {
						$this->current_sequence->set_option_by_name( 'excerptIntro', __( 'A summary:', Controller::plugin_slug ) );
						$excerpt_intro = $this->current_sequence->get_option_by_name( 'excerptIntro' );
					}
					
					$excerpt = sprintf(
						'<p>%1$s</p><p>%2$s</p>',
						esc_html( $excerpt_intro ),
						esc_html( $notice_post->excerpt )
					);
				}
				
				$post_links = sprintf(
					'<a href="%1%s" title="%2$s">%3$s</a>',
					$login_redirect_link,
					sprintf( __( 'Link to: %1$s', Controller::plugin_slug ), $post_title ),
					$post_title
				);
				$post_url   = $login_redirect_link;
				
				
				$variables = array(
					"name"      => apply_filters( 'e20r-sequence-alert-message-name', $this->user->user_firstname ),
					// Options could be: display_name, first_name, last_name, nickname
					"sitename"  => apply_filters( 'e20r-sequence-site-name', get_option( "blogname" ) ),
					"post_link" => apply_filters( 'e20r-sequence-alert-message-link-href-element', $post_links ),
					'post_url'  => apply_filters( 'e20r-sequence-alert-message-post-permalink', $post_url ),
					"today"     => apply_filters( 'e20r-sequence-alert-message-date', $post_date ),
					"excerpt"   => apply_filters( 'e20r-sequence-alert-message-excerpt-intro', $excerpt ),
					"ptitle"    => apply_filters( 'e20r-sequence-alert-message-title', $notice_post->title ),
				);
				
				// Maybe data/img entry for google analytics.
				$variables['google_analytics'] = apply_filters( 'e20r-sequences-google-tracking-info', null, $this->sequence_id );
				
				$emails[ $index ]->data = apply_filters( 'e20r-email-notice-set-data-variables', $variables );
			}
		}
		
		// Append the post_link ul/li element list when asking to send as list.
		if ( E20R_SEQ_SEND_AS_LIST === $send_as ) {
			
			$utils->log( 'Set link variable for list of link format' );
			$emails[ ( count( $emails ) - 1 ) ]->post_link = "<ul>\n" . $post_links . "</ul>\n";
		}
		
		$emails[ $index ]->data = apply_filters( 'e20r-sequence-email-substitution-fields', $data );
		
		$utils->log( "Have prepared " . count( $emails ) . " email notices for user {$this->user_id}" );
		
		$this->user = get_user_by( 'id', $this->user_id );
		
		// Send the configured email messages
		foreach ( $emails as $email ) {
			
			$utils->log( 'Email object: ' . get_class( $email ) );
			
			if ( false == $email->send() ) {
				
				$utils->log( "ERROR - Failed to send new sequence content email to {$this->user->user_email}! " );
			}
		}
		
		// wp_reset_postdata();
		// All of the array list names are !!<name>!! escaped values.
		return true;
	}
	
	/**
	 * Return the list/array of posts to process
	 *
	 * @return array|bool|\stdClass[]
	 */
	public function get_post_list() {
		
		return $this->posts;
	}
}
