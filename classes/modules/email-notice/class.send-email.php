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
 *
 * @version 2.2
 */

namespace E20R\Utilities\Email_Notice;

use E20R\Utilities\Utilities;
use E20R\Utilities\Licensing\Licensing;
use E20R\Sequences\Modules\Analytics\Google;

class Send_Email {
	
	/**
	 * @var int|null $user_id - The ID of the user receving this email message
	 */
	private $user_id;
	
	/**
	 * @var null|int[] $content_id_list = Post ID for any linked content
	 */
	private $content_id_list;
	
	/**
	 * Email address for recipient
	 *
	 * @var null|string
	 */
	private $to;
	
	/**
	 * Recipient's full name
	 *
	 * @var null|string
	 */
	private $toname;
	
	/**
	 * Sender email address
	 *
	 * @var null|string
	 */
	private $from;
	
	/**
	 * Sender's name
	 *
	 * @var null|string
	 */
	private $fromname;
	
	/**
	 * Subject text for email message
	 *
	 * @var null|string
	 */
	private $subject;
	
	/**
	 * Name of HTML template (<filename>.html) without the .html extension
	 *
	 * @var null|string
	 */
	private $template;
	
	/**
	 * Substitution variables (array)
	 *
	 * @var null|array
	 */
	private $variables;
	
	/**
	 * SMTP Headers for email message
	 *
	 * @var null|array
	 */
	private $headers;
	
	/**
	 * Body of email message
	 *
	 * @var null|string|array
	 */
	private $body;
	
	/**
	 * Attachments to email message (if applicable)
	 *
	 * @var string[]|null
	 */
	private $attachments;
	
	/**
	 * Format of any dates used (uses PHP date() formatting)
	 *
	 * @var null|string
	 */
	private $dateformat;
	
	/**
	 * Send_Email constructor.
	 */
	public function __construct() {
		
		$this->user_id         = null;
		$this->content_id_list = array();
		$this->to              = null;
		$this->toname          = null;
		$this->from            = null;
		$this->fromname        = null;
		$this->subject         = null;
		$this->template        = null;
		$this->variables       = array();
		$this->headers         = array();
		$this->body            = null;
		$this->attachments     = array();
		$this->dateformat      = null;
		
		return $this;
	}
	
	/**
	 * Magic method to get/fetch class variable values
	 *
	 * @param string $property
	 *
	 * @return mixed
	 */
	public function __get( $property ) {
		if ( property_exists( $this, $property ) ) {
			
			return $this->{$property};
		}
		
		return null;
	}
	
	/**
	 * Magic method to set/save class variable values
	 *
	 * @param string $property
	 * @param mixed  $value
	 *
	 * @return Send_Email $this
	 */
	public function __set( $property, $value ) {
		$this->{$property} = $value;
		
		return $this;
	}
	
	/**
	 * Prepare the header content for the email message
	 */
	public function prepare_headers() {
		
		/**
		 * @filter string[] e20r-email-notice-cc-list List of email addresses to Carbon Copy (visible list) for these payment/expiration warning messages
		 */
		$cc_list = apply_filters( 'e20r-email-notice-cc-list', array() );
		
		/**
		 * @filter string[] e20r-email-notice-bcc-list List of email addresses to Blind Copy (invisible list) for these payment/expiration warning messages
		 */
		$bcc_list = apply_filters( 'e20r-email-notice-bcc-list', array() );
		
		/**
		 * @filter string e20r-email-notice-sender-email Email address (formatted: '[ First Last | Site Name ] <email@example.com>' )
		 */
		$sender_info = apply_filters( 'e20r-email-notice-from-header', "From: {$this->fromname} <{$this->from}>" );
		
		/**
		 * @filter string e20r-email-notice-email-content-type Default eMail content type (HTML in UTF-8)
		 */
		$content_type = apply_filters( 'e20r-email-notice-email-content-type', 'Content-Type: text/html; charset=UTF-8' );
		
		// Process all headers
		if ( ! empty( $cc_list ) ) {
			
			foreach ( $cc_list as $cc_email ) {
				$this->headers[] = "Cc: {$cc_email}";
			}
		}
		
		if ( ! empty( $bcc_list ) ) {
			foreach ( $bcc_list as $bcc_email ) {
				$this->headers[] = "Bcc: {$bcc_email}";
			}
		}
		
		if ( ! empty( $from ) ) {
			
			$this->headers[] = "From: {$sender_info}";
		}
		
		if ( ! empty( $content_type ) ) {
			$this->headers[] = $content_type;
		}
	}
	
	/**
	 * Exception handler for PHPMail
	 *
	 * @filter Action Hook for the wp_mail PHPMail exception handler
	 *
	 * @param \WP_Error $error
	 */
	public static function email_error_handler( \WP_Error $error ) {
		
		$utils       = Utilities::get_instance();
		$error_data = $error->get_error_data( 'wp_mail_failed' );
		
		$utils->log( "Error while attempting to send the email message for {$error_data['to']}/{$error_data['subject']}" );
		$utils->log( "Actual PHPMailer error: " . $error->get_error_message( 'wp_mail_failed' ) );
	}
	
	/**
	 * Send the email message to the defined recipient
	 *
	 * @param null|string $to
	 * @param null|string $from
	 * @param null|string $fromname
	 * @param null|string $subject
	 * @param string      $template
	 * @param array|null  $variables
	 *
	 * @return bool
	 */
	public function send( $to = null, $from = null, $fromname = null, $subject = null, $template = null, $variables = null ) {
		
		global $current_user;
		
		$utils = Utilities::get_instance();
		
		// The default from source for WordPress messages
		$default_sender = get_user_by( 'email', get_option( 'admin_email' ) );
		
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
			
			$this->subject = sanitize_text_field( wp_unslash( $subject ) );
		}
		
		if ( ! empty( $template ) ) {
			
			$this->template = $template;
		}
		
		if ( ! empty( $variables ) ) {
			
			$this->variables = $variables;
		}
		
		// Check if everything is configured.
		if ( empty( $this->to ) ) {
			
			$this->to = $current_user->user_email;
		}
		
		if ( empty( $this->from ) ) {
			
			$this->from = apply_filters( 'e20r-email-notice-sender', $default_sender->user_email );
		}
		
		if ( empty( $this->fromname ) ) {
			
			$this->fromname = apply_filters( 'e20r-email-notice-sender-name', $default_sender->display_name );
		}
		
		if ( empty( $this->subject ) ) {
			
			$this->subject = html_entity_decode( apply_filters( 'e20r-email-notice-subject', $subject ), ENT_QUOTES, 'UTF-8' );
			// $this->subject = html_entity_decode( $sequence->get_option_by_name( 'subject' ), ENT_QUOTES, 'UTF-8' );
		}
		
		if ( empty( $this->template ) ) {
			
			$this->template = apply_filters( 'e20r-email-notice-template-name', $template );
			// $this->template = $sequence->get_option_by_name( 'noticeTemplate' );
		}
		
		if ( empty( $this->dateformat ) ) {
			
			$this->dateformat = apply_filters( 'e20r-email-notice-date-format', get_option( 'date_format' ) );
			// $this->dateformat = $sequence->get_option_by_name( ' dateformat' );
		}
		
		// $this->headers     = apply_filters( 'e20r-sequence-email-headers', array( "Content-Type: text/html" ) );
		$this->headers     = apply_filters( 'e20r-email-notice-headers', array( "Content-Type: text/html" ) );
		$this->attachments = apply_filters( 'e20r-email-notice-attachments', $this->attachments );
		
		$utils->log( "Processing main content for email message" );
		
		$this->body      = $this->load_template( $this->template );
		$this->variables = apply_filters( 'e20r-email-notice-substitution-variables', $this->variables, $this );
		
		$filtered_email    = apply_filters( "e20r-email-notice-filter", $this );        //allows filtering entire email at once
		$this->to          = apply_filters( "e20r-email-notice-recipient", $filtered_email->to, $this );
		$this->from        = apply_filters( "e20r-email-notice-sender", $filtered_email->from, $this );
		$this->fromname    = apply_filters( "e20r-email-notice-sender-name", $filtered_email->fromname, $this );
		$this->subject     = apply_filters( "e20r-email-notice-subject", $filtered_email->subject, $this );
		$this->template    = apply_filters( "e20r-email-notice-template-name", $filtered_email->template, $this );
		$this->body        = apply_filters( "e20r-email-notice-body", $filtered_email->body, $this );
		
		$this->body = $this->process_body( $this->variables, $this->body );
		
		$utils->log( "Sending email message..." );
		
		if ( true === wp_mail( $this->to, $this->subject, $this->body, $this->headers, $this->attachments ) ) {
			
			$utils->log( "Sent email to {$this->to} about {$this->subject}" );
			
			return true;
		}
		
		$utils->log( "Failed to send email to {$this->to} about {$this->subject}" );
		
		return false;
	}
	
	/**
	 * Return the Credit Card information we have on file (formatted for email/HTML use)
	 *
	 * @return string
	 */
	public function get_html_payment_info() {
		
		$utils = Utilities::get_instance();
		
		$cc_data = apply_filters( 'e20r-email-notice-fetch-user-cc-data', null, $this->user_id );
		$billing_page_id= apply_filters( 'e20r-email-notice-billing-info-page', null );
		
		$billing_page = get_permalink( $billing_page_id );
		
		$utils->log( "Info: " . print_r( $cc_data, true ) );
		
		if ( ! empty( $cc_data ) ) {
			
			$cc_info = sprintf( '<div class="e20r-email-notice-cc-descr">%1$s:', __( 'The following payment source(s) is being used', Email_Notice::plugin_slug ) );
			
			
			foreach ( $cc_data as $pi_key => $card_data ) {
				
				$card_description = sprintf( __( 'Your %s card ending in %s ( Expires: %s/%s )', Email_Notice::plugin_slug ), $card_data['brand'], $card_data['last4'], sprintf( '%02d', $card_data['exp_month'] ), $card_data['exp_year'] ) . '<br />';
				
				$cc_info .= '<p class="e20r-email-notice-cc-entry">';
				$cc_info .= apply_filters( 'e20r-email-notice-credit-card-text', $card_description, $card_data );
				$cc_info .= '</p>';
			}
			
			
			$warning_text = sprintf(
				__( 'Please make sure your %1$sbilling information%2$s is up to date on our system before %3$s', Email_Notice::plugin_slug ),
				sprintf(
					'<a href="%s" target="_blank" title="%s">',
					esc_url_raw( $billing_page ),
						__( 'Link to update credit card information', Email_Notice::plugin_slug )
					),
				'</a>',
				apply_filters( 'e20r-email-notice-next-payment-date', null )
			);
			
			$cc_info .= sprintf( '<p>%s</p>', apply_filters( 'e20r-email-notice-cc-billing-info-warning', $warning_text ) );
			$cc_info .= '</div>';
			
		} else {
			$cc_info = '<p>' . sprintf( __( "Payment Type: %s", Email_Notice::plugin_slug ), $this->user_info->get_last_pmpro_order()->payment_type ) . '</p>';
		}
		
		return $cc_info;
	}
	
	/**
	 * Generate the billing address information stored locally as HTML formatted text
	 *
	 * @return string
	 */
	public function format_billing_address() {
		
		$address = '';
		
		$bfname    = apply_filters( 'e20r-email-notice-billing-firstname', get_user_meta( $this->user_id, 'pmpro_bfirstname', true ) );
		$blname    = apply_filters( 'e20r-email-notice-billing-lastname', get_user_meta( $this->user_id, 'pmpro_blastname', true ) );
		$bsaddr1   = apply_filters( 'e20r-email-notice-billing-address1', get_user_meta( $this->user_id, 'pmpro_baddress1', true ) );
		$bsaddr2   = apply_filters( 'e20r-email-notice-billing-address2', get_user_meta( $this->user_id, 'pmpro_baddress2', true ) );
		$bcity     = apply_filters( 'e20r-email-notice-billing-city', get_user_meta( $this->user_id, 'pmpro_bcity', true ) );
		$bpostcode = apply_filters( 'e20r-email-notice-billing-postcode', get_user_meta( $this->user_id, 'pmpro_bzipcode', true ) );
		$bstate    = apply_filters( 'e20r-email-notice-billing-state', get_user_meta( $this->user_id, 'pmpro_bstate', true ) );
		$bcountry  = apply_filters( 'e20r-email-notice-billing-country', get_user_meta( $this->user_id, 'pmpro_bcountry', true ) );
		
		$address = '<div class="e20r-email-notice-billing-address">';
		$address .= sprintf( '<p class="e20r-pw-billing-name">' );
		
		if ( ! empty( $bfname ) ) {
			$address .= sprintf( '	<span class="e20r-email-notice-billing-firstname">%s</span>', $bfname );
		}
		
		if ( ! empty( $blname ) ) {
			$address .= sprintf( '	<span class="e20r-email-notice-billing-lastname">%s</span>', $blname );
		}
		$address .= sprintf( '</p>' );
		$address .= sprintf( '<p class="e20r-email-notice-billing-address">' );
		if ( ! empty( $bsaddr1 ) ) {
			$address .= sprintf( '%s', $bsaddr1 );
		}
		
		if ( ! empty( $bsaddr1 ) ) {
			$address .= sprintf( '<br />%s', $bsaddr2 );
		}
		
		if ( ! empty( $bcity ) ) {
			$address .= '<br />';
			$address .= sprintf( '<span class="e20r-email-notice-billing-city">%s</span>', $bcity );
		}
		
		if ( ! empty( $bstate ) ) {
			$address .= sprintf( ', <span class="e20r-email-notice-billing-state">%s</span>', $bstate );
		}
		
		if ( ! empty( $bpostcode ) ) {
			$address .= sprintf( '<span class="e20r-email-notice-billing-postcode">%s</span>', $bpostcode );
		}
		
		if ( ! empty( $bcountry ) ) {
			$address .= sprintf( '<br/>><span class="e20r-email-notice-billing-country">%s</span>', $bcountry );
		}
		
		$address .= sprintf( '</p>' );
		$address .= '</div > ';
		
		/**
		 * HTML formatted billing address for the current user (uses PMPro's billing info fields & US formatting by default)
		 *
		 * @filter string e20r-email-notice-formatted-billing-address
		 */
		return apply_filters( 'e20r-email-notice-formatted-billing-address', $address );
	}
	
	/**
	 * Load email body from specified template (file or editor)
	 *
	 * @param string $template_file - file name
	 *
	 * @return mixed|null|string   - Body value for template
	 */
	private function load_template( $template_file ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "Load template for file {$template_file}" );
		
		/**
		 * @filter e20r-email-notice-loaded - Determines whether the template editor is loaded & active
		 */
		$use_email_notice = apply_filters( 'e20r-email-notice-loaded', false );
		
		// Add valid HTML and embedded styling
		$this->body = $this->add_email_html_header( wp_unslash( $this->subject ), wp_unslash( $this->subject ) );
		
		if ( true === $use_email_notice ) {
			
			/**
			 * @filter e20r-sequence-template-editor-contents - Loads the contents of the specific template_file from the email editor add-on.
			 */
			$this->body .= apply_filters( 'e20r-email-notice-template-contents', null, $template_file );
			
		} else {
			
			if ( 1 === preg_match( '/\.html/i', $template_file ) ) {
				$extless_file = str_replace( '.html', '', $template_file );
			} else {
				$extless_file = $template_file;
			}
			
			$tlocation = apply_filters( 'e20r-email-notice-custom-template-location', 'email-notice', $extless_file );
			
			// Haven't got the plus license, using file system saved template(s)
			if ( file_exists( get_stylesheet_directory() . "/{$tlocation}/{$template_file}" ) ) {
				
				$this->body .= file_get_contents( get_stylesheet_directory() . "/{$tlocation}/{$template_file}" );        //email template folder in child theme
			} else if ( file_exists( get_stylesheet_directory() . "/{$tlocation}/{$template_file}" ) ) {
				
				$this->body .= file_get_contents( get_stylesheet_directory() . "/{$tlocation}/{$template_file}" );    //typo in path for email template folder in child theme
			} else if ( file_exists( get_template_directory() . "/{$tlocation}/{$template_file}" ) ) {
				
				$this->body .= file_get_contents( get_template_directory() . "/{$tlocation}/{$template_file}" );        //email folder in parent theme
			} else if ( file_exists( get_template_directory() . "/{$tlocation}/{$template_file}" ) ) {
				
				$this->body .= file_get_contents( get_template_directory() . "/{$tlocation}/{$template_file}" );        //typo in path for email folder in parent theme
			} else if ( file_exists( plugin_dir_path( __FILE__ ) . "/templates/{$template_file}") ) {
				
				$this->body .= file_get_contents( plugin_dir_path( __FILE__ ) . "/templates/{$template_file}" );   //default template in plugin
			} else if ( file_exists( plugin_dir_path( __FILE__ ) . "/email/{$template_file}" ) ) {
				
				$this->body .= file_get_contents( plugin_dir_path( __FILE__ ) . "/email/{$template_file}" );       //default email directory in plugin
			}
		}
		
		// Add HTML footer
		$this->body .= $this->add_email_html_footer();
		
		return $this->body;
	}
	
	/**
	 * Process the body & complete variable substitution if/when needed
	 *
	 * @param array $data_array
	 * @param null  $body
	 *
	 * @return array|null|string
	 */
	private function process_body( $data_array = array(), $body = null ) {
		
		// FIXME: Doesn't handle HTML content & substitution variables the way we want/expect!
		
		$utils = Utilities::get_instance();
		
		if ( is_null( $body ) ) {
			
			if ( ! empty( $data_array['template'] ) ) {
				
				$this->load_template( $data_array['template'] );
			}
			
			if ( empty( $body ) ) {
				$utils->log( "No body to substitute in. Returning empty string" );
				$this->body = null;
			}
		}
		
		if ( ! is_array( $data_array ) && empty( $body ) ) {
			
			$utils->log( "Not a valid substitution array: " . print_r( $data_array, true ) );
			$this->body = null;
		}
		
		if ( is_array( $data_array ) && ! empty( $data_array ) && ! empty( $this->body ) ) {
			
			$utils->log( "Building email info" );
			
			foreach ( $data_array as $subst_key => $value ) {
				
				$this->body = str_ireplace( "!!{$subst_key}!!", $value, $this->body );
			}
		}
		
		return $this->body;
	}
	
	/**
	 * Add HTML header to message
	 *
	 * @param string      $email_subject
	 * @param null|string $header_text
	 *
	 * @return string
	 *
	 * @copyright HTML from InterNations GmbH Antwort email templates (MIT License, see below)
	 * @copyright PHP from Wicked Strong Chicks, LLC
	 *
	 * @credit Antwort: Responsive Layouts for Email - Github.com/InterNations ( https://github.com/InterNations/antwort )
	 */
	public function add_email_html_header( $email_subject = "From !!sitename!!", $header_text = null ) {
		/**
		 * Copyright (c) 2012-2013 InterNations GmbH
		 *
		 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated
		 * documentation files (the "Software"), to deal in the Software without restriction, including without
		 * limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
		 * copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the
		 * following conditions:
		 *
		 * The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
		 *
		 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO
		 * THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
		 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF
		 * CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
		 * IN THE SOFTWARE.
		 *
		 */
		ob_start();
		?>
		<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
		<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
		<head>
			<!--[if gte mso 9]>
			<xml>
				<o:OfficeDocumentSettings>
					<o:AllowPNG/>
					<o:PixelsPerInch>96</o:PixelsPerInch>
				</o:OfficeDocumentSettings>
			</xml><![endif]-->
			<!-- fix outlook zooming on 120 DPI windows devices -->
			<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<!-- So that mobile will display zoomed in -->
			<meta http-equiv="X-UA-Compatible" content="IE=edge"> <!-- enable media queries for windows phone 8 -->
			<meta name="format-detection" content="date=no"> <!-- disable auto date linking in iOS 7-9 -->
			<meta name="format-detection" content="telephone=no"> <!-- disable auto telephone linking in iOS 7-9 -->
			<title><?php esc_html_e( $email_subject ); ?></title>
			<!-- <link rel="stylesheet" type="text/css" href="<?php echo plugins_url( 'css/email-message-styles.css', __FILE__ ); ?>"> -->
			<!-- <link rel="stylesheet" type="text/css" href="<?php echo plugins_url( 'css/email-message-responsive.css', __FILE__ ); ?>"> -->
		</head>
		<body style="margin:0; padding:0;" bgcolor="#F0F0F0" leftmargin="0" topmargin="0" marginwidth="0" marginheight="0">
		<!-- Standard Styles (styles.css) -->
		<style type="text/css">
			.header, .title, .subtitle,	.footer-text { font-family: Helvetica, Arial, sans-serif; }
			.header { font-size: 24px; font-weight: bold; padding-bottom: 12px; color: #DF4726; }
			.footer-text { font-size: 12px; line-height: 16px;color: #aaaaaa; }
			.footer-text a {color: #aaaaaa;}
			.container { width: 600px; max-width: 600px; }
			.container-padding { padding-left: 24px; padding-right: 24px; }
			.content { padding-top: 12px; padding-bottom: 12px; background-color: #ffffff; }
			code { background-color: #eee; padding: 0 4px; font-family: Menlo, Courier, monospace; font-size: 12px; }
			hr { border: 0; border-bottom: 1px solid #cccccc; }
			.hr { height: 1px; border-bottom: 1px solid #cccccc; }
			.title { font-size: 18px; font-weight: 600; color: #374550; }
			.subtitle { font-size: 16px; font-weight: 600; color: #2469A0; }
			.subtitle span { font-weight: 400; color: #999999; }
			.body-text { font-family: Helvetica, Arial, sans-serif; font-size: 14px; line-height: 20px; text-align: left; color: #333333;}
			a[href^="x-apple-data-detectors:"],
			a[x-apple-data-detectors] {
				color: inherit !important;
				text-decoration: none !important;
				font-size: inherit !important;
				font-family: inherit !important;
				font-weight: inherit !important;
				line-height: inherit !important;
			}
		</style>
		<!-- Responsive Styles (responsive.css) -->
		<style type="text/css">
			body { margin: 0; padding: 0; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; }
			table { border-spacing: 0; }
			table td { border-collapse: collapse; }
			.ExternalClass { width: 100%; }
			.ExternalClass, .ExternalClass p, .ExternalClass span, .ExternalClass font,	.ExternalClass td, .ExternalClass div { line-height: 100%; }
			.ReadMsgBody { width: 100%; background-color: #ebebeb; }
			table {	mso-table-lspace: 0; mso-table-rspace: 0; }
			img { -ms-interpolation-mode: bicubic; }
			.yshortcuts a { border-bottom: none !important; }
			@media screen and (max-width: 599px) {
				.force-row, .container { width: 100% !important; max-width: 100% !important; }
			}
			@media screen and (max-width: 400px) {
				.container-padding { padding-left: 12px !important;	padding-right: 12px !important; }
			}
			.ios-footer a { color: #aaaaaa !important; text-decoration: underline; }
		</style>
		<!-- 100% background wrapper (grey background) -->
		<table border="0" width="100%" height="100%" cellpadding="0" cellspacing="0" bgcolor="#F0F0F0">
		<tr>
		<td align="center" valign="top" bgcolor="#F0F0F0" style="background-color: #F0F0F0;">
		<br>
		<!-- 600px container (white background) -->
		<table border="0" width="600" cellpadding="0" cellspacing="0" class="container">
		<?php if (!empty( $header_text ) ): ?>
		<tr>
			<td class="container-padding header" align="left">
				<?php esc_html_e( $header_text ); ?>
			</td>
		</tr>
		<?php endif; ?>
		<tr>
		<td class="container-padding content" align="left">
		<br>
		<div class="title"><?php esc_html_e( $email_subject ); ?></div>
		<br>
		<div class="body-text">
		<?php
		return ob_get_clean();
	}
	
	/**
	 * Add HTML footer to message
	 *
	 * @return string
	 *
	 * @copyright HTML from InterNations GmbH Antwort email templates (MIT License, see below)
	 * @copyright PHP from Wicked Strong Chicks, LLC
	 *
	 * @credit Antwort: Responsive Layouts for Email - Github.com/InterNations ( https://github.com/InterNations/antwort )
	 */
	public function add_email_html_footer() {
		/**
		 * Copyright (c) 2012-2013 InterNations GmbH
		 *
		 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated
		 * documentation files (the "Software"), to deal in the Software without restriction, including without
		 * limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
		 * copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the
		 * following conditions:
		 *
		 * The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
		 *
		 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO
		 * THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
		 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF
		 * CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
		 * IN THE SOFTWARE.
		 *
		 */
		ob_start();
		?>
		</div>
		</td>
		</tr>
		<tr>
			<td class="container-padding footer-text" align="left">
				<br><br>
				Sample Footer text: &copy; 2015 Acme, Inc.
				<br><br>
				
				You are receiving this email because you opted in on our website. Update your <a href="#">email
					preferences</a> or <a href="#">unsubscribe</a>.
				<br><br>
				
				<strong>Acme, Inc.</strong><br>
				<span class="ios-footer">
					              123 Main St.<br>
					              Springfield, MA 12345<br>
					            </span>
				<a href="http://www.acme-inc.com">www.acme-inc.com</a><br>
				
				<br><br>
			
			</td>
		</tr>
		</table><!--/600px container -->
		</td>
		</tr>
		</table><!--/100% background wrapper-->
		</body>
		</html>
		<?php
		return ob_get_clean();
	}
	
	/**
	 * Prepare the (soon-to-be) PHPMailer() object to send
	 *
	 * @param \WP_Post $post     - Post Object
	 * @param \WP_User $user     - User Object
	 * @param          $template - Template name (string)
	 *
	 * @return Send_EMail - Mail object to process
	 */
	public function prepare_mail_obj( $post, $user, $template ) {
		
		$utils      = Utilities::get_instance();
		$controller = Controller::get_instance();
		
		$user_started = ( $controller->get_user_startdate( $user->ID ) - DAY_IN_SECONDS ) + ( $controller->normalize_delay( $post->delay ) * DAY_IN_SECONDS );
		
		$this->from            = $controller->get_option_by_name( 'replyto' );
		$this->template        = $template;
		$this->fromname        = $controller->get_option_by_name( 'fromname' );
		$this->to              = $user->user_email;
		$this->subject         = sprintf( '%s: %s (%s)', $controller->get_option_by_name( 'subject' ), $post->title, strftime( "%x", $user_started ) );
		$this->dateformat      = $controller->get_option_by_name( 'dateformat' );
		$this->user_id         = $user->ID;
		$this->content_id_list = array( $post->id );
		$this->body            = $this->load_template( $this->template );
		
		return $this;
		
	}
	
	/**
	 * Substitute the included variables for the appropriate text
	 *
	 * @param mixed $email_type
	 *
	 */
	public function replace_variable_data( $email_type ) {
		
		$notice    = Email_Notice::get_instance();
		$variables = $notice->default_data_variables( array(), $email_type );
		
		foreach ( $variables as $var_name => $settings ) {
			
			if ( in_array( $settings['type'], array( 'link', 'wp_post' ) ) ) {
				$settings['post_id'] = $this->content_id_list;
			}
			
			$var_value = $this->get_value_for_variable( $this->user_id, $var_name, $settings );
			
			$this->variables[ $var_name ] = $var_value;
		}
		
		$this->body = apply_filters( 'e20r-email-notice-substitute-data-variables', $this->body, $this->variables );
	}
	
	/**
	 * Fetch the value (user's value) for the
	 * @param int   $user_id
	 * @param string $var_name
	 * @param array $settings - Array of settings for this request array( 'type' => <type of table>, 'variable' => <name in WP DB> [, post_id => <array of post(s)>] ),
	 *
	 * @return mixed
	 */
	public static function get_value_for_variable( $user_id, $var_name, $settings ) {
		
		$value = null;
		
		switch ( $settings['type'] ) {
			case 'wp_user':
				$user_info = get_user_by( 'ID', $user_id );
				
				if ( ! empty( $user_info ) ) {
					$value = $user_info->{$settings['variable']};
				}
				
				unset( $user_info );
				break;
			
			case 'user_meta':
				$value = get_user_meta( $user_id, $settings['variable'], true );
				break;
			
			case 'wp_options':
				$value = get_option( $settings['variable'], null );
				break;
			
			case 'link':
				switch ( $settings['variable'] ) {
					case 'wp_login';
						$value = wp_login_url();
						break;
					
					case 'post':
						$value = array();
						
						foreach ( $settings['post_id'] as $post_id ) {
							$value[ $post_id ] = get_permalink( $post_id );
						}
						break;
				}
				break;
			
			case 'encoded_link':
				switch ( $settings['variable'] ) {
					case 'post':
						$value = array();
						
						foreach ( $settings['post_id'] as $post_id ) {
							$value[ $post_id ] = urlencode_deep( get_permalink( $post_id ) );
						}
						break;
				}
				break;
			
			case 'membership':
				
				$level = apply_filters( 'e20r-email-notice-membership-level-for-user', null, $user_id, true );
				
				switch ( $settings['variable'] ) {
					case 'membership_id':
						$value = $level->id;
						break;
					case 'membership_level_name':
						$value = $level->name;
						break;
					case 'enddate':
						$value = date('Y-m-d', $level->enddate );
						break;
				}
				break;
			
			case 'wp_post':
				
				$content = get_posts( array( 'include' => $settings['post_id'] ) );
				
				foreach ( $content as $content_post ) {
					$value[ $content_post->ID ] = $content_post->{$settings['variable']};
				}
				
				wp_reset_postdata();
				break;
				
			default:
				$value = apply_filters( 'e20r-email-notice-custom-variable-filter', $var_name, $user_id, $settings );
		}
		
		$utils = Utilities::get_instance();
		$utils->log( "Found setting (type: {$settings['type']} value for user (ID: {$user_id}): " . print_r( $value, true ) );
		
		return $value;
	}
	
	private function inline_html() {
		?>
		<!doctype html>
		<html>
		<head>
			<meta name="viewport" content="width=device-width">
			<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
			<title>Simple Transactional Email</title>
			<style>
				/* -------------------------------------
					INLINED WITH htmlemail.io/inline
				------------------------------------- */
				/* -------------------------------------
					RESPONSIVE AND MOBILE FRIENDLY STYLES
				------------------------------------- */
				@media only screen and (max-width: 620px) {
					table[class=body] h1 {
						font-size: 28px !important;
						margin-bottom: 10px !important;
					}
					
					table[class=body] p,
					table[class=body] ul,
					table[class=body] ol,
					table[class=body] td,
					table[class=body] span,
					table[class=body] a {
						font-size: 16px !important;
					}
					
					table[class=body] .wrapper,
					table[class=body] .article {
						padding: 10px !important;
					}
					
					table[class=body] .content {
						padding: 0 !important;
					}
					
					table[class=body] .container {
						padding: 0 !important;
						width: 100% !important;
					}
					
					table[class=body] .main {
						border-left-width: 0 !important;
						border-radius: 0 !important;
						border-right-width: 0 !important;
					}
					
					table[class=body] .btn table {
						width: 100% !important;
					}
					
					table[class=body] .btn a {
						width: 100% !important;
					}
					
					table[class=body] .img-responsive {
						height: auto !important;
						max-width: 100% !important;
						width: auto !important;
					}
				}
				
				/* -------------------------------------
					PRESERVE THESE STYLES IN THE HEAD
				------------------------------------- */
				@media all {
					.ExternalClass {
						width: 100%;
					}
					
					.ExternalClass,
					.ExternalClass p,
					.ExternalClass span,
					.ExternalClass font,
					.ExternalClass td,
					.ExternalClass div {
						line-height: 100%;
					}
					
					.apple-link a {
						color: inherit !important;
						font-family: inherit !important;
						font-size: inherit !important;
						font-weight: inherit !important;
						line-height: inherit !important;
						text-decoration: none !important;
					}
					
					.btn-primary table td:hover {
						background-color: #34495e !important;
					}
					
					.btn-primary a:hover {
						background-color: #34495e !important;
						border-color: #34495e !important;
					}
				}
			</style>
		</head>
		<body class=""
		      style="background-color: #f6f6f6; font-family: sans-serif; -webkit-font-smoothing: antialiased; font-size: 14px; line-height: 1.4; margin: 0; padding: 0; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%;">
		<table border="0" cellpadding="0" cellspacing="0" class="body"
		       style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background-color: #f6f6f6;">
			<tr>
				<td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td>
				<td class="container"
				    style="font-family: sans-serif; font-size: 14px; vertical-align: top; display: block; Margin: 0 auto; max-width: 580px; padding: 10px; width: 580px;">
					<div class="content"
					     style="box-sizing: border-box; display: block; Margin: 0 auto; max-width: 580px; padding: 10px;">
						
						<!-- START CENTERED WHITE CONTAINER -->
						<span class="preheader"
						      style="color: transparent; display: none; height: 0; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; mso-hide: all; visibility: hidden; width: 0;">This is preheader text. Some clients will show this text as a preview.</span>
						<table class="main"
						       style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background: #ffffff; border-radius: 3px;">
							
							<!-- START MAIN CONTENT AREA -->
							<tr>
								<td class="wrapper"
								    style="font-family: sans-serif; font-size: 14px; vertical-align: top; box-sizing: border-box; padding: 20px;">
									<table border="0" cellpadding="0" cellspacing="0"
									       style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
										<tr>
											<td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">
												<p style="font-family: sans-serif; font-size: 14px; font-weight: normal; margin: 0; Margin-bottom: 15px;">
													Hi there,</p>
												<p style="font-family: sans-serif; font-size: 14px; font-weight: normal; margin: 0; Margin-bottom: 15px;">
													Sometimes you just want to send a simple HTML email with a simple
													design and clear call to action. This is it.</p>
												<table border="0" cellpadding="0" cellspacing="0"
												       class="btn btn-primary"
												       style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; box-sizing: border-box;">
													<tbody>
													<tr>
														<td align="left"
														    style="font-family: sans-serif; font-size: 14px; vertical-align: top; padding-bottom: 15px;">
															<table border="0" cellpadding="0" cellspacing="0"
															       style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: auto;">
																<tbody>
																<tr>
																	<td style="font-family: sans-serif; font-size: 14px; vertical-align: top; background-color: #3498db; border-radius: 5px; text-align: center;">
																		<a href="http://htmlemail.io" target="_blank"
																		   style="display: inline-block; color: #ffffff; background-color: #3498db; border: solid 1px #3498db; border-radius: 5px; box-sizing: border-box; cursor: pointer; text-decoration: none; font-size: 14px; font-weight: bold; margin: 0; padding: 12px 25px; text-transform: capitalize; border-color: #3498db;">Call
																			To Action</a></td>
																</tr>
																</tbody>
															</table>
														</td>
													</tr>
													</tbody>
												</table>
												<p style="font-family: sans-serif; font-size: 14px; font-weight: normal; margin: 0; Margin-bottom: 15px;">
													This is a really simple email template. Its sole purpose is to get
													the recipient to click the button with no distractions.</p>
												<p style="font-family: sans-serif; font-size: 14px; font-weight: normal; margin: 0; Margin-bottom: 15px;">
													Good luck! Hope it works.</p>
											</td>
										</tr>
									</table>
								</td>
							</tr>
							
							<!-- END MAIN CONTENT AREA -->
						</table>
						
						<!-- START FOOTER -->
						<div class="footer" style="clear: both; Margin-top: 10px; text-align: center; width: 100%;">
							<table border="0" cellpadding="0" cellspacing="0"
							       style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
								<tr>
									<td class="content-block"
									    style="font-family: sans-serif; vertical-align: top; padding-bottom: 10px; padding-top: 10px; font-size: 12px; color: #999999; text-align: center;">
										<span class="apple-link"
										      style="color: #999999; font-size: 12px; text-align: center;">Company Inc, 3 Abbey Road, San Francisco CA 94102</span>
										<br> Don't like these emails? <a href="http://i.imgur.com/CScmqnj.gif"
										                                 style="text-decoration: underline; color: #999999; font-size: 12px; text-align: center;">Unsubscribe</a>.
									</td>
								</tr>
								<tr>
									<td class="content-block powered-by"
									    style="font-family: sans-serif; vertical-align: top; padding-bottom: 10px; padding-top: 10px; font-size: 12px; color: #999999; text-align: center;">
										Powered by <a href="http://htmlemail.io"
										              style="color: #999999; font-size: 12px; text-align: center; text-decoration: none;">HTMLemail</a>.
									</td>
								</tr>
							</table>
						</div>
						<!-- END FOOTER -->
						
						<!-- END CENTERED WHITE CONTAINER -->
					</div>
				</td>
				<td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td>
			</tr>
		</table>
		</body>
		</html><?php
	}
}
