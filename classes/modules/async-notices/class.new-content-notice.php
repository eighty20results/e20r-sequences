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

namespace E20R\Sequences\Modules\Async_Notices;

if ( ! defined( 'E20R_SEQ_TEMPLATES' ) ) {
	define( 'E20R_SEQ_TEMPLATES', plugin_dir_path( __FILE__ ) . 'email' );
}

use E20R\Sequences\Sequence\Controller;
use E20R\Utilities\Email_Notice\Email_Notice;
use E20R\Utilities\Email_Notice\Email_Notice_View;
use E20R\Utilities\Licensing\Licensing;
use E20R\Utilities\Utilities;

class New_Content_Notice extends Email_Notice {
	
	/**
	 * @var null|New_Content_Notice
	 */
	private static $instance = null;
	
	/**
	 * The name of the email message taxonomy term
	 *
	 * @var string $taxonomy_name
	 */
	protected $taxonomy_name = 'e20r-sequence-notices';
	
	/**
	 * The label for the custom taxonomy term of this plugin.
	 *
	 * @var null|string $taxonomy_nicename
	 */
	protected $taxonomy_nicename = null;
	
	/**
	 * The description text for the custom taxonomy term of this plugin.
	 *
	 * @var null|string $taxonomy_description
	 */
	protected $taxonomy_description = null;
	
	/**
	 * New_Content_Notice constructor.
	 */
	public function __construct() {
		
		$this->taxonomy_nicename    = __( "Sequence Content", Controller::plugin_slug );
		$this->taxonomy_description = __( "New Sequence Content Message", Controller::plugin_slug );
	}
	
	/**
	 * Fetch instance of the New_Content_Notice (child) class
	 *
	 * @return New_Content_Notice|null
	 */
	public static function get_instance() {
		
		if ( is_null( self::$instance ) ) {
			
			self::$instance = new self;
			// parent::get_instance();
		}
		
		return self::$instance;
	}
	
	/**
	 * Loading all Notice Editor hooks and filters
	 */
	public function load_hooks() {
		
		$utils = Utilities::get_instance();
		
		parent::load_hooks();
		
		$utils->log( "Loading Email Notice editor functionality" );
		
		add_filter( 'e20r-email-notice-variable-help', array( self::$instance, 'variable_help' ), 10, 2 );
		
		add_action( 'init', array( self::$instance, 'install_taxonomy' ), 99 );
		
		add_action( 'wp_ajax_e20r_util_save_template', array( self::$instance, 'save_template' ) );
		add_action( 'wp_ajax_e20r_util_reset_template', array( self::$instance, 'reset_template' ) );
		
		add_action( 'e20r_sequence_module_deactivating_core', array( self::$instance, 'deactivate_plugin' ), 10, 1 );
		add_action( 'e20r_sequence_module_activating_core', array( self::$instance, 'activate_plugin' ), 10 );
		
		add_filter( 'e20r-email-notice-loaded', array( $this, 'using_email_notice_editor' ), 10, 1 );
		add_action( 'e20r-sequence-template-editor-email-entry', array( self::$instance, 'add_email_options' ), 10, 2 );
		
		add_action( 'e20r-email-notice-load-message-meta', array( self::$instance, 'load_message_metabox' ), 10, 1 );
		add_action( 'e20r-email-notice-load-message-meta', array( self::$instance, 'load_template_help' ), 10, 1 );
		
		add_action( 'save_post', array( self::$instance, 'save_message_meta' ), 10, 1 );
		
		add_filter( 'e20r-email-notice-data-variables', array( self::$instance, 'default_data_variables' ), 10, 2 );
		add_filter( 'e20r-email-notice-message-types', array( self::$instance, 'define_message_types' ), 10, 1 );
		add_filter( 'e20r-email-notice-content', array( self::$instance, 'load_template_content' ), 10, 2 );
		add_filter( 'e20r-email-notice-template-contents', array(
			self::$instance,
			'load_template_content',
		), 10, 2 );
		
		add_filter( 'e20r-email-notice-custom-template-location', array( $this, 'sequence_template_dir' ), 10, 2 );
		
		add_filter( 'e20r-email-notice-custom-variable-filter', array( $this, 'load_analytics_info' ), 10, 4 );
	}
	
	/**
	 * Get and print the message type (string) for the email notice column
	 *
	 * @param string $column
	 * @param int $post_id
	 */
	public function custom_post_column( $column, $post_id ) {
		
		$msg_types = $this->define_message_types( array() );
		
		if ( $column === 'message_type' ) {
			
			$sequence_notice_type = get_post_meta( $post_id, '_e20r_sequence_message_type', true );
			$terms = wp_get_object_terms( $post_id, 'e20r_email_type', array( 'fields' => 'slugs') );
			
			if ( empty( $sequence_notice_type ) ) {
				$sequence_notice_type = -1;
			}
			
			if ( !empty( $sequence_notice_type ) && in_array( 'e20r-sequence-notices', $terms ) ) {
				esc_html_e( $msg_types[ $sequence_notice_type ]['label'] );
			}
		}
		
	}
	
	/**
	 * Decide whether to use the EMail Notice functionality
	 *
	 * @param bool $active
	 *
	 * @return bool
	 */
	public function using_email_notice_editor( $active = false ) {
		
		if ( true === Licensing::is_licensed( Controller::plugin_prefix ) ) {
			$active = true;
		} else {
			$active = ( false || $active ); // Set to false unless it's already set to active.
		}
		
		return $active;
	}
	
	/**
	 * Load the Substitution variable help text on the Email Notices Editor page
	 *
	 * @param int|string|null $term_type
	 */
	public function load_template_help( $term_type ) {
		
		if ( false == $this->processing_this_term( $term_type ) ) {
			return;
		}
		
		add_meta_box(
			'e20r_message_help',
			__( 'New Content Substitution Variables', Controller::plugin_slug ),
			array( self::$instance, "show_template_help", ),
			Email_Notice::cpt_type,
			'normal',
			'high'
		);
	}
	
	/**
	 * Display Substitution variable help text on the Email Notices Editor page
	 */
	public function show_template_help() {
		
		global $post;
		global $post_ID;
		
		$notice_type = false;
		
		if ( ! empty( $post_ID ) ) {
			$notice_type = get_post_meta( $post_ID, '_e20r_sequence_message_type', true );
		}
		
		if ( empty( $notice_type ) ) {
			$notice_type = $this->taxonomy_name;
		}?>
		
		<div id="e20r-message-notice-variable-info">
			<div class="e20r-message-template-col">
				<label for="variable_references"><?php _e( 'Reference', Email_Notice::plugin_slug ); ?>:</label>
			</div>
			<div>
				<div class="template_reference" style="background: #FAFAFA; border: 1px solid #CCC; color: #666; padding: 5px;">
					<p>
						<em><?php _e( 'Use these variables in the editor window above.', Email_Notice::plugin_slug ); ?></em>
					</p>
					<?php Email_Notice_View::add_placeholder_variables( $notice_type ); ?>
				</div>
				<p class="e20r-message-template-help">
					<?php printf( __( "%sSuggestion%s: Type in a message title, select the Message Type and save this notice. It will give you access to even more substitution variables.", Controller::plugin_slug ), '<strong>', '</strong>' ); ?>
				</p>
			</div>
		</div><?php
	}
	
	/**
	 * Save the message type as post meta for the current post/message
	 *
	 * @param int $post_id
	 *
	 * @return bool|int
	 */
	public function save_message_meta( $post_id ) {
		
		global $post;
		
		$utils = Utilities::get_instance();
		
		// Check that the function was called correctly. If not, just return
		if ( empty( $post_id ) ) {
			
			$utils->log( 'No post ID supplied...' );
			
			return false;
		}
		
		if ( wp_is_post_revision( $post_id ) ) {
			return $post_id;
		}
		
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $post_id;
		}
		
		if ( ! isset( $post->post_type ) || ( Email_Notice::cpt_type != $post->post_type ) ) {
			return $post_id;
		}
		
		if ( 'trash' == get_post_status( $post_id ) ) {
			return $post_id;
		}
		
		if ( ! isset( $_REQUEST['e20r-email-notice-type'] ) ) {
			return $post_id;
		}
		
		$types = $this->define_message_types( array() );
		
		$type_list = array();
		
		foreach ( $types as $msg_type => $msg_settings ) {
			if ( '_e20r_sequence_message_type' == $msg_settings['meta_key'] && - 1 !== $msg_type ) {
				$type_list[] = $msg_type;
			}
		}
		
		if ( ! isset( $_REQUEST['e20r-email-notice-type'] ) ||
		     ( isset( $_REQUEST['e20r-email-notice-type'] ) &&
		       ! in_array( $_REQUEST['e20r-email-notice-type'], $type_list ) ) ) {
			return $post_id;
		}
		
		$message_type = $utils->get_variable( 'e20r-email-notice-type', null );
		
		if ( ! empty( $message_type ) ) {
			update_post_meta( $post_id, '_e20r_sequence_message_type', $message_type );
		}
	}
	
	/**
	 * @param array $types
	 *
	 * @return array
	 */
	public function define_message_types( $types ) {
		
		global $post;
		global $post_ID;
		
		$meta_key      = '_e20r_sequence_message_type';
		$current_value = - 1;
		
		if ( ! empty( $post_ID ) ) {
			$current_value = get_post_meta( $post_ID, $meta_key, true );
		}
		
		$new_types = array(
			- 1                     => array(
				'label'    => __( 'Not selected', Controller::plugin_slug ),
				'value'    => - 1,
				'meta_key' => $meta_key,
				'selected' => selected( $current_value, null, false ),
			),
			E20R_SEQ_SEND_AS_SINGLE => array(
				'label'    => __( 'One alert per post', Controller::plugin_slug ),
				'value'    => E20R_SEQ_SEND_AS_SINGLE,
				'meta_key' => $meta_key,
				'selected' => selected( $current_value, E20R_SEQ_SEND_AS_SINGLE, false ),
			),
			E20R_SEQ_SEND_AS_LIST   => array(
				'label'    => __( 'Digest of post links', Controller::plugin_slug ),
				'value'    => E20R_SEQ_SEND_AS_LIST,
				'meta_key' => $meta_key,
				'selected' => selected( $current_value, E20R_SEQ_SEND_AS_LIST, false ),
			),
		);
		
		$types = $types + $new_types;
		
		return $types;
	}
	
	/**
	 * Set the message type for the Email Notices (as used by Sequences)
	 *
	 * @param string|null $term_type
	 */
	public function load_message_metabox( $term_type ) {
		
		if ( false == $this->processing_this_term( $term_type ) ) {
			return;
		}
		
		add_meta_box(
			'e20r-editor-settings',
			__( 'Message Type', Controller::plugin_slug ),
			array( self::$instance, 'display_message_metabox', ),
			Email_Notice::cpt_type,
			'side',
			'high'
		);
	}
	
	/**
	 * Defining list of data (substitution) variables and fields/locations
	 *
	 * @param array  $variable_list
	 * @param string $type
	 *
	 * @return array
	 */
	public function default_data_variables( $variable_list, $type = 'e20r-sequence-notice' ) {
		
		if ( true === $this->processing_this_term( $type ) ) {
			$variable_list = array(
				'display_name'          => array( 'type' => 'wp_user', 'variable' => 'display_name' ),
				'first_name'            => array( 'type' => 'wp_user', 'variable' => 'first_name' ),
				'last_name'             => array( 'type' => 'wp_user', 'variable' => 'last_name' ),
				'user_login'            => array( 'type' => 'wp_user', 'variable' => 'user_login' ),
				'user_email'            => array( 'type' => 'wp_user', 'variable' => 'user_email' ),
				// 'random_user_meta'   => array( 'type' => 'user_meta', 'variable' => 'meta_key' ),
				'sitename'              => array( 'type' => 'wp_options', 'variable' => 'blogname' ),
				'siteemail'             => array( 'type' => 'wp_options', 'variable' => 'admin_email' ),
				'membership_id'         => array( 'type' => 'membership', 'variable' => 'membership_id' ),
				'membership_level_name' => array( 'type' => 'membership', 'variable' => 'membership_level_name' ),
				'login_link'            => array( 'type' => 'link', 'variable' => 'wp_login' ),
				'content_link'          => array( 'type' => 'link', 'variable' => 'post' ),
				'encoded_content_link'  => array( 'type' => 'encoded_link', 'variable' => 'post' ),
				'content_body'          => array( 'type' => 'wp_post', 'variable' => 'post_content' ),
				'content_title'         => array( 'type' => 'wp_post', 'variable' => 'post_title' ),
				'content_excerpt'       => array( 'type' => 'wp_post', 'variable' => 'post_excerpt' ),
				// Digest variables
				'content_exerpts'       => array( 'type' => 'wp_post', 'variable' => 'post_excerpt' ),
				'content_titles'        => array( 'type' => 'wp_post', 'variable' => 'post_title' ),
				'content_links'         => array( 'type' => 'link', 'variable' => 'post' ),
				'encoded_content_links'  => array( 'type' => 'encoded_link', 'variable' => 'post' ),
			);
		}
		
		return $variable_list;
	}
	
	/**
	 * Handler to return the Google Analytics HTML to let us track this message/user ID
	 *
	 * @filter 'e20r-email-notice-custom-variable-filter', $variable_name, $user_id, $settings
	 *
	 * @param mixed $value
	 * @param string $variable_name
	 * @param int $user_id
	 * @param array $settings
	 *
	 * @return mixed
	 */
	public function load_analytics_info( $value, $variable_name, $user_id, $settings ) {
		
		if ( 'google_analytics' === $variable_name ) {
			// TODO: Add a way to generate a GOOGLE tracking ID for the user ID/sequence/etc as needed
			return $value;
		}
		
		return $value;
	}
	/**
	 * Help info for the Substitution variables available for the Editor page/post
	 *
	 * @param array $variables
	 * @param int   $type
	 *
	 * @return array
	 */
	public function variable_help( $variables, $type ) {
		
		$utils = Utilities::get_instance();
		$utils->log( "Processing for type {$type} by " . $utils->_who_called_me() );
		
		if ( true === $this->processing_this_term( $type ) ) {
			
			// Default (always available) variables
			$variables = array(
				'display_name'          => __( 'Display Name (User Profile setting) for the user receiving the message', Controller::plugin_slug ),
				'first_name'            => __( "The first name for the user receiving the message", Controller::plugin_slug ),
				'last_name'             => __( "The last/surname for the user receiving the message", Controller::plugin_slug ),
				'user_login'            => __( 'Login / username for the user receiving the message', Controller::plugin_slug ),
				'user_email'            => __( 'The email address of the user receiving the message', Controller::plugin_slug ),
				'sitename'              => __( 'The blog name (see General Settings)', Controller::plugin_slug ),
				'siteemail'             => __( "The email address used as the 'From' email when sending this message to the user", Controller::plugin_slug ),
				'membership_id'         => __( 'The ID of the membership level for the user receiving the message', Controller::plugin_slug ),
				'membership_level_name' => __( "The active Membership Level name for the user receiving the message  (from the Membership Level settings page)", Controller::plugin_slug ),
				'login_link'            => __( "A link to the login page for this site. Can be used to send the user to the content after they've logged in/authenticated. Specify the link as HTML: `<a href=\"!!login_link!!?redirect_to=!!encoded_content_link!!\">Access the content</a>`", Controller::plugin_slug ),
			);
			
			switch ( $type ) {
				case E20R_SEQ_SEND_AS_SINGLE:
					$variables['content_title']        = __( "The title of the new content post/page", Controller::plugin_slug );
					$variables['content_body']         = __( "Include the body (post_content) of the new sequence content", Controller::plugin_slug );
					$variables['content_exerpt']       = __( "The excerpt (post_excerpt) for the new sequence content", Controller::plugin_slug );
					$variables['content_link']         = __( "Link to the new sequence content", Controller::plugin_slug );
					$variables['encoded_content_link'] = __( "Login encoded link to the new sequence content", Controller::plugin_slug );
					
					break;
				
				case E20R_SEQ_SEND_AS_LIST:
					$variables['content_titles']        = __( "List of the titles for the new content", Controller::plugin_slug );
					$variables['content_exerpts']       = __( "List of the excerpt for the new content", Controller::plugin_slug );
					$variables['content_links']         = __( "List of links to the sequence content posts", Controller::plugin_slug );
					$variables['encoded_content_links'] = __( "List of login encoded links to sequence content posts", Controller::plugin_slug );
					
					break;
			}
		}
		
		return $variables;
	}
	/**
	 * Verify if the child function is processing this term
	 *
	 * @param null|int|string $type
	 *
	 * @return bool
	 */
	public function processing_this_term( $type = null ) {
		
		global $post;
		global $post_ID;
		
		$utils           = Utilities::get_instance();
		$current_post_id = null;
		$slug_list       = array();
		
		// Find the post ID (numeric)
		if ( empty( $post ) && empty( $post_ID ) && ! empty( $_REQUEST['post'] ) ) {
			$current_post_id = intval( $_REQUEST['post'] );
		} else if ( ! empty( $post_ID ) ) {
			$current_post_id = $post_ID;
		} else if ( ! empty( $post->ID ) ) {
			$current_post_id = $post->ID;
		}
		
		$utils->log( "Called by: " . $utils->_who_called_me() );
		
		if ( empty( $current_post_id ) ) {
			return false;
		}
		
		$terms = wp_get_post_terms( $current_post_id, Email_Notice::taxonomy,parent::get_term_args( $this->taxonomy_name ) );
		// $utils->log( "Terms found for {$this->taxonomy_name} / {$type}: " . print_r( $terms, true ) );
		
		foreach ( $terms as $term ) {
			$slug_list[] = $term->slug;
		}
		
		// $utils->log( "Slug list: " . print_r( $slug_list, true ) );
		
		$type_list = $this->define_message_types( array() );
		unset( $type_list[ - 1 ] );
		
		// $utils->log( "Type list: " . print_r( $type_list, true ) );
		
		$is_the_type = array_key_exists( $type, $type_list );
		$is_in_terms = in_array( $type, $slug_list );
		
		$utils->log( "Found {$type} in {$current_post_id} for this plugin? " . ( $is_the_type || $is_in_terms ? 'Yes' : 'No' ) );
		
		if ( ( false === $is_in_terms && false === $is_in_terms ) &&  ( $type === $this->taxonomy_name && empty($post) && empty($post_ID) ) ) {
			$utils->log("Processing our own type: {$type} vs {$this->taxonomy_name}. Returning success");
			return true;
		}
		
		return ( $is_in_terms || $is_the_type );
	}
	
	/**
	 * Generate list of templates to include in Sequence "Template" settings drop-down
	 *
	 * @param string $selected - Name of selected/current template being used
	 * @param int    $type     - Type of template to add/include
	 *
	 */
	public function add_email_options( $selected, $type = E20R_SEQ_SEND_AS_SINGLE ) {
		
		$templates = $this->configure_cpt_templates( $type );
		
		foreach ( $templates as $template ) {
			printf(
				'<option label="%1$s" value="%2$s" %3$s>%2$s</option>',
				esc_attr( $template['description'] ),
				sanitize_file_name( $template['file_name'] ),
				selected( $selected, sanitize_file_name( $template['file_name'] ), false )
			);
		}
	}
	
	/**
	 * Create and return all relevant notice templates for E20R Sequences
	 *
	 * @param integer $alert_type E20R_SEQ_SEND_AS_SINGLE|E20R_SEQ_SEND_AS_LIST
	 *
	 * @return array
	 */
	public function configure_cpt_templates( $alert_type = E20R_SEQ_SEND_AS_SINGLE ) {
		
		$query_args = array(
			'posts_per_page' => - 1,
			'post_type'      => Email_Notice::cpt_type,
			'post_status'    => 'publish',
			'tax_query'      => array(
				'relation' => 'AND',
				array(
					'taxonomy'         => Email_Notice::taxonomy,
					'field'            => 'slug',
					'operator'         => 'IN',
					'include_children' => false,
					'terms'            => array( $this->taxonomy_name ),
				),
			),
		);
		
		$emails = new \WP_Query( $query_args );
		
		$utils = Utilities::get_instance();
		
		$utils->log( "Number of email templates found: " . $emails->found_posts );
		$messages  = $emails->get_posts();
		$templates = self::$instance->load_template_settings( 'all' );
		
		unset( $templates['messageheader'] );
		unset( $templates['messagefooter'] );
		
		foreach ( $messages as $msg ) {
			
			$msg_type = get_post_meta( $msg->ID, '_e20r_sequence_message_type', true );
			
			$utils->log( "Processing {$msg->ID} with type {$msg_type}" );
			
			if ( $msg_type == $alert_type ) {
				
				$templates[ $msg->post_name ]            = self::$instance->default_template_settings( $msg->post_name );
				$templates[ $msg->post_name ]['subject'] = $msg->post_title;
				$templates[ $msg->post_name ]['active']  = true;
				
				$templates[ $msg->post_name ]['type']           = ! empty( $msg_type ) ? $msg_type : E20R_SEQ_SEND_AS_SINGLE;
				$templates[ $msg->post_name ]['body']           = $msg->post_content;
				$templates[ $msg->post_name ]['data_variables'] = apply_filters( 'e20r-email-notice-data-variables', array(), $this->taxonomy_name );
				$templates[ $msg->post_name ]['description']    = $msg->post_excerpt;
				$templates[ $msg->post_name ]['file_name']      = "{$msg->post_name}.html";
				$templates[ $msg->post_name ]['file_path']      = E20R_SEQ_TEMPLATES;
				$templates[ $msg->post_name ]['schedule']       = array();
				
			}
		}
		
		$utils->log( "Templates: " . print_r( $templates, true ) );
		
		wp_reset_postdata();
		
		return $templates;
	}
	
	/**
	 * Set the location for the .html email templates for Sequences in the active theme directory, etc.
	 *
	 * @param string $location
	 * @param string $template_file
	 *
	 * @return string - Portion of the path to the Sequences email .html file templates (the sub-directory name used in the active theme to host the )
	 */
	public function sequence_template_dir( $location = 'sequence-email-alert', $template_file ) {
		
		$sequence_templ = array(
			'new_content',
			'new_content_digest'
		);
		
		if ( in_array( $template_file,$sequence_templ ) ) {
			$location = 'sequence-email-alert';
		}
		
		return $location;
	}
	/**
	 * Return the current settings for the specified message template
	 *
	 * @param string $template_name
	 * @param bool   $load_body
	 *
	 * @return array
	 *
	 * @access private
	 */
	public function load_template_settings( $template_name, $load_body = false ) {
		
		$utils = Utilities::get_instance();
		
		$utils->log( "Loading Message templates for {$template_name}" );
		
		// TODO: Load existing PMPro templates that apply for this editor
		$pmpro_email_templates = apply_filters( 'pmproet_templates', array() );
		
		$template_info = get_option( $this->option_name, $this->default_templates() );
		
		if ( 'all' === $template_name ) {
			
			if ( true === $load_body ) {
				
				foreach ( $template_info as $name => $settings ) {
					
					if ( empty( $name ) ) {
						unset( $template_info[ $name ] );
						continue;
					}
					
					$utils->log( "Loading body for {$name}" );
					
					if ( empty( $settings['body'] ) ) {
						
						$utils->log( "Body has no content, so loading from default template: {$name}" );
						$settings['body']       = $this->load_default_template_body( $name );
						$template_info[ $name ] = $settings;
					}
				}
			}
			
			return $template_info;
		}
		
		// Specified template settings not found so have to return the default settings
		if ( $template_name !== 'all' && ! isset( $template_info[ $template_name ] ) ) {
			
			$template_info[ $template_name ] = $this->default_template_settings( $template_name );
			
			// Save the new template info
			update_option( $this->option_name, $template_info, 'no' );
		}
		
		if ( $template_name !== 'all' && true === $load_body && empty( $template_info[ $template_name ]['body'] ) ) {
			
			$template_info[ $template_name ]['body'] = $this->load_default_template_body( $template_name );
		}
		
		return $template_info[ $template_name ];
	}
	
	/**
	 * Define default notice templates for Sequences/Notice Editor
	 *
	 * @return array
	 */
	public function default_templates() {
		
		$templates = array(
			'messageheader'  => array(
				'subject'        => null,
				'active'         => true,
				'type'           => null,
				'body'           => null,
				'schedule'       => array(),
				'data_variables' => array(),
				'description'    => __( 'Standard Header for Alert Messages', Email_Notice::plugin_slug ),
				'file_name'      => 'messageheader.html',
				'file_path'      => apply_filters( 'e20r-sequence-email-alert-template-path', E20R_SEQ_TEMPLATES ),
			),
			'messagefooter'  => array(
				'subject'        => null,
				'active'         => true,
				'type'           => null,
				'body'           => null,
				'data_variables' => array(),
				'schedule'       => array(),
				'description'    => __( 'Standard Footer for Alert Messages', Email_Notice::plugin_slug ),
				'file_name'      => 'messagefooter.html',
				'file_path'      => apply_filters( 'e20r-sequence-email-alert-template-path', E20R_SEQ_TEMPLATES ),
			),
			'single_default' => array(
				'subject'        => sprintf( __( "New content for you at %s", Email_Notice::plugin_slug ), get_option( "blogname" ) ),
				'active'         => true,
				'type'           => E20R_SEQ_SEND_AS_SINGLE,
				'body'           => null,
				'data_variables' => apply_filters( 'e20r-email-notice-data-variables', array(), $this->taxonomy_name ),
				'description'    => __( 'New content alert (single)', Email_Notice::plugin_slug ),
				'file_name'      => 'new_content.html',
				'file_path'      => apply_filters( 'e20r-sequence-email-alert-template-path', E20R_SEQ_TEMPLATES ),
			),
			'digest_default' => array(
				'subject'        => sprintf( __( "New content for you at %s (digest/list)", Email_Notice::plugin_slug ), get_option( "blogname" ) ),
				'active'         => true,
				'type'           => E20R_SEQ_SEND_AS_LIST,
				'body'           => null,
				'data_variables' => apply_filters( 'e20r-email-notice-data-variables', array(), $this->taxonomy_name ),
				'description'    => __( 'New content alert (digest/list)', Email_Notice::plugin_slug ),
				'file_name'      => 'new_content_digest.html',
				'file_path'      => apply_filters( 'e20r-sequence-email-alert-template-path', E20R_SEQ_TEMPLATES ),
			),
		);
		
		return apply_filters( 'e20r_sequence_email_notice_templates', $templates );
	}
	
	/**
	 * Default settings for any new template(s)
	 *
	 * @param string $template_name
	 *
	 * @return array
	 */
	public function default_template_settings( $template_name ) {
		
		return array(
			'subject'        => null,
			'active'         => false,
			'type'           => E20R_SEQ_SEND_AS_SINGLE,
			'body'           => null,
			'data_variables' => array(),
			'description'    => null,
			'schedule'       => array(),
			'file_name'      => "{$template_name}.html",
			'file_path'      => E20R_SEQ_TEMPLATES,
		);
	}
	
	/**
	 * Filter handler to load the Editor Email Notice content
	 *
	 * @filter 'e20r-email-notice-content'
	 *
	 * @param string $content
	 * @param string $template_slug
	 *
	 * @return string|null
	 */
	public function load_template_content( $content, $template_slug ) {
		
		$utils = Utilities::get_instance();
		
		if ( 1 === preg_match( '/\.html/i', $template_slug ) ) {
			$utils->log( "Removing trailing .html (added by option for compatibility reasons)" );
			$template_slug = preg_replace( '/\.html/i', '', $template_slug );
		}
		
		if ( false === Licensing::is_licensed( Controller::plugin_prefix ) ) {
			$utils->log("Unlicensed version. Returning a default template body if possible");
			$content = $this->load_default_template_body( $template_slug );
			return $content;
		}
		
		$utils->log( "Searching for: {$template_slug}" );
		
		$notice = new \WP_Query( array(
			'post_type' => Email_Notice::cpt_type,
			'name'      => $template_slug,
			'tax_query' => array(
				'relation' => 'AND',
				array(
					'taxonomy'         => Email_Notice::taxonomy,
					'field'            => 'slug',
					'operator'         => 'IN',
					'terms'            => array( $this->taxonomy_name ),
					'include_children' => true,
				),
			),
		) );
		
		if ( ! empty( $notice ) ) {
			
			$notices = $notice->get_posts();
			
			$utils->log( "Found {$notice->found_posts} templates for {$template_slug}: " . print_r( $notices, true ) );
			
			if ( count( $notices ) > 1 ) {
				$utils->log( "Found more than a single email notice/template for {$template_slug}!!!" );
			} else if ( count( $notices ) === 1 ) {
				$utils->log( "Found a single email template to use for {$template_slug}" );
			}
			
			/**
			 * @var \WP_Post $email_notice
			 */
			$email_notice = array_pop( $notices );
			$content      = $email_notice->post_content;
		} else {
			$content = $this->load_default_template_body( $template_slug );
		}
		
		return $content;
	}
	
	/**
	 * Set/select the default reminder schedule based on the type of reminder
	 *
	 * @param array  $schedule
	 * @param string $type
	 * @param string $slug
	 *
	 * @return array
	 */
	public function load_schedule( $schedule, $type = 'single', $slug ) {
		
		if ( $slug !== Controller::plugin_slug ) {
			return $schedule;
		}
		
		switch ( $type ) {
			case 'single':
				
				$pw_schedule = array_keys( apply_filters( 'pmpro_upcoming_recurring_payment_reminder', array(
					7 => 'membership_recurring',
				) ) );
				$pw_schedule = apply_filters( 'e20r-payment-warning-recurring-reminder-schedule', $pw_schedule );
				break;
			
			case 'digest':
				$pw_schedule = array_keys( apply_filters( 'pmproeewe_email_frequency_and_templates', array(
					30 => 'membership_expiring',
					60 => 'membership_expiring',
					90 => 'membership_expiring',
				) ) );
				$pw_schedule = apply_filters( 'e20r-payment-warning-expiration-schedule', $pw_schedule );
				break;
			
			default:
				$pw_schedule = array( 7, 15, 30 );
		}
		
		return array_merge( $pw_schedule, $schedule );
	}
	
	/**
	 * Install custom taxonomy for the New Content Notice child class ('e20r-sequence-notices' for Editor class)
	 *
	 * @param null|string $name
	 * @param null|string $nicename
	 * @param null|string $descr
	 */
	public function install_taxonomy( $name = null, $nicename = null, $descr = null ) {
		
		parent::install_taxonomy( $this->taxonomy_name, $this->taxonomy_nicename, $this->taxonomy_description  );
	}
}
