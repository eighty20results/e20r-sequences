<?php
/**
 *  Copyright (c) 2014-2019. - Eighty / 20 Results by Wicked Strong Chicks.
 *  ALL RIGHTS RESERVED
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *   You should have received a copy of the GNU General Public License
 *   along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace E20R\Sequences\Tools;

use E20R\Sequences\Tools\E20RError as Errors;
use E20R\Tools as E20RTools;

class E20R_Mail {
	private $to;
	private $from;
	private $fromname;
	private $subject;
	private $template;
	private $data;
	private $headers;
	private $body;
	private $attachments;
	private $dateformat;
	
	
	public function __construct() {
		$this->to          = null;
		$this->from        = null;
		$this->fromname    = null;
		$this->subject     = null;
		$this->template    = null;
		$this->data        = null;
		$this->headers     = null;
		$this->body        = null;
		$this->attachments = null;
		$this->dateformat  = null;
		
		return $this;
	}
	
	public function __get( $property ) {
		if ( property_exists( $this, $property ) ) {
			
			return $this->{$property};
		}
		
		return null;
	}
	
	public function __set( $property, $value ) {
		$this->{$property} = $value;
		
		return $this;
	}
	
	public function send( $to = null, $from = null, $fromname = null, $subject = null, $template = null, $data = null ) {
		
		global $current_user;
		
		$sequence = apply_filters( 'get_sequence_class_instance', null );
		
		// Set variables.
		if ( ! empty( $to ) ) {
			
			$this->to = sanitize_email( $to );
		}
		
		if ( ! empty( $from ) ) {
			
			$this->from = sanitize_email( $from );
		}
		
		if ( ! empty( $fromname ) ) {
			
			$this->fromname = sanitize_text_field( $fromname );
		}
		
		if ( ! empty( $subject ) ) {
			
			$this->subject = stripslashes( sanitize_text_field( $subject ) );
		}
		
		if ( ! empty( $template ) ) {
			
			$this->template = $template;
		}
		
		if ( ! empty( $data ) ) {
			
			$this->data = $data;
		}
		
		// Check if everything is configured.
		if ( empty( $this->to ) ) {
			
			$this->to = $current_user->user_email;
		}
		
		if ( empty( $this->from ) ) {
			
			$this->from = $sequence->get_option_by_name( 'replyto' );
		}
		
		if ( empty( $this->fromname ) ) {
			
			$this->fromname = $sequence->get_option_by_name( 'fromname' );
		}
		
		if ( empty( $this->subject ) ) {
			
			$this->subject = $sequence->get_option_by_name( 'subject' );
		}
		
		if ( empty( $this->template ) ) {
			
			$this->template = $sequence->get_option_by_name( 'noticeTemplate' );
		}
		
		if ( empty( $this->dateformat ) ) {
			
			$this->dateformat = $sequence->get_option_by_name( ' dateformat' );
		}
		
		$this->headers     = apply_filters( 'e20r-sequence-email-headers', array( "Content-Type: text/html" ) );
		$this->attachments = null;
		
		if ( is_string( $this->data ) ) {
			$this->data = array( 'body' => $this->data );
		}
		
		E20RTools\DBG::log( "Processing main content for email message" );
		
		$this->body = $this->load_template( $this->template );
		$this->data = apply_filters( 'e20r-sequences-email-data', $this->data, $this );
		
		$filtered_email    = apply_filters( "e20r-sequence-email-filter", $this );        //allows filtering entire email at once
		$this->to          = apply_filters( "e20r-sequence-email-recipient", $filtered_email->to, $this );
		$this->from        = apply_filters( "e20r-sequence-email-sender", $filtered_email->from, $this );
		$this->fromname    = apply_filters( "e20r-sequence-email-sender_name", $filtered_email->fromname, $this );
		$this->subject     = html_entity_decode(
			apply_filters( "e20r-sequence-email-subject", $filtered_email->subject, $this ),
			ENT_QUOTES,
			'UTF-8'
		);
		$this->template    = apply_filters( "e20r-sequence-email-template", $filtered_email->template, $this );
		$this->body        = apply_filters( "e20r-sequence-email-body", $filtered_email->body, $this );
		$this->attachments = apply_filters( "e20r-sequence-email-attachments", $filtered_email->attachments, $this );
		
		$this->body = $this->process_body( $this->data, $this->body );
		
		E20RTools\DBG::log( "Sending email message..." );
		
		if ( wp_mail( $this->to, $this->subject, $this->body, $this->headers, $this->attachments ) ) {
			
			E20RTools\DBG::log( "Sent email to {$this->to} about {$this->subject}" );
			
			return true;
		}
		
		E20RTools\DBG::log( "Failed to send email to {$this->to} about {$this->subject}" );
		
		return false;
	}
	
	private function process_body( $data_array = array(), $body = null ) {
		
		if ( is_null( $body ) ) {
			
			if ( ! empty( $data_array['template'] ) ) {
				
				$this->load_template( $data_array['template'] );
			}
			
			if ( empty( $body ) ) {
				E20RTools\DBG::log( "No body to substitute in. Returning empty string" );
				$this->body = null;
			}
		}
		
		if ( ! is_array( $data_array ) && empty( $body ) ) {
			
			E20RTools\DBG::log( "Not a valid substitution array: " . print_r( $data_array, true ) );
			$this->body = null;
		}
		
		if ( is_array( $data_array ) && ! empty( $data_array ) && ! empty( $body ) ) {
			
			E20RTools\DBG::log( "Substituting variables in body of email" );
			$this->body = $body;
			
			foreach ( $data_array as $key => $value ) {
				
				$this->body = str_ireplace( "!!{$key}!!", $value, $this->body );
			}
		}
		
		return $this->body;
	}
	
	/**
	 * Load email body from specified template (file or editor)
	 *
	 * @param string $template_file - file name
	 *
	 * @return mixed|null|string   - Body value for template
	 */
	private function load_template( $template_file ) {
		
		E20RTools\DBG::log( "Load template for file {$template_file}" );
		
		if ( file_exists( get_stylesheet_directory() . "/sequence-email-alert/" . $template_file ) ) {
			
			$this->body = file_get_contents( get_stylesheet_directory() . "/sequence-email-alert/" . $template_file );        //email template folder in child theme
		} else if ( file_exists( get_stylesheet_directory() . "/sequences-email-alerts/" . $template_file ) ) {
			
			$this->body = file_get_contents( get_stylesheet_directory() . "/sequences-email-alerts/" . $template_file );    //typo in path for email template folder in child theme
		} else if ( file_exists( get_template_directory() . "/sequences-email-alerts/" . $template_file ) ) {
			
			$this->body = file_get_contents( get_template_directory() . "/sequences-email-alerts/" . $template_file );        //email folder in parent theme
		} else if ( file_exists( get_template_directory() . "/sequence-email-alerts/" . $template_file ) ) {
			
			$this->body = file_get_contents( get_template_directory() . "/sequence-email-alerts/" . $template_file );        //typo in path for email folder in parent theme
		} else if ( file_exists( E20R_SEQUENCE_PLUGIN_DIR . "/email/" . $template_file ) ) {
			
			$this->body = file_get_contents( E20R_SEQUENCE_PLUGIN_DIR . "/email/" . $template_file );                        //default template in plugin
		}
		
		/**
		 * @filter e20r-sequence-template-editor-loaded - Determines whether the template editor is loaded & active
		 */
		$use_editor = apply_filters( 'e20r-sequence-template-editor-loaded', false );
		
		if ( true === $use_editor ) {
			/**
			 * @filter e20r-sequence-template-editor-contents - Loads the contents of the specific template_file from the email editor add-on.
			 */
			$this->body = apply_filters( 'e20r-sequence-template-editor-contents', null, $template_file );
		}
		
		return $this->body;
	}
}