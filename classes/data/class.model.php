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

namespace E20R\Sequences\Data;

use E20R\Sequences\Main\E20R_Sequences;
use E20R\Utilities\Utilities;
use E20R\Utilities\Cache;
use E20R\Sequences\Sequence\Controller;

class Model {
	
	/**
	 * The post type name
	 */
	const cpt_type = 'e20r_sequence';
	
	/**
	 * @var null|Model
	 */
	private static $instance = null;
	
	/**
	 * Post list for the Sequence
	 * @var array
	 */
	private $posts = array();
	
	/**
	 * List of upcoming (future) posts
	 *
	 * @var array
	 */
	private $upcoming = array();
	
	/**
	 * @var array
	 */
	private $current_metadata_versions = array();
	
	/**
	 * Timestamp for when cached data expires
	 *
	 * @var int
	 */
	private $expires;
	
	/**
	 * Timestamp for when the data was refreshed from DB
	 *
	 * @var int
	 */
	private $refreshed;
	
	/**
	 * Model constructor.
	 *
	 * @access private
	 */
	private function __construct() {
		
		$this->current_metadata_versions = $this->load_metadata_versions();
	}
	
	/**
	 * Load the metadata version info for the sequence(s) - compatible w/previous releases of Sequences
	 *
	 * @return array
	 */
	private function load_metadata_versions() {
		
		$versions = get_option( 'e20r_sequence_metadata_version', array() );
		
		// For backwards compatibility
		if ( empty( $versions ) ) {
			$versions = get_option( "pmpro_sequence_metadata_version", array() );
			update_option( 'e20r_sequence_metadata_version', $versions );
			delete_option( 'pmpro_sequence_metadata_version' );
		}
		
		return $versions;
		
	}
	
	/**
	 * Set the refreshed timestamp value
	 *
	 * @param int $timestamp
	 */
	public function set_refreshed( $timestamp ) {
		
		$this->refreshed = intval( $timestamp );
	}
	
	/**
	 * Return the refreshed value
	 *
	 * @return int
	 */
	public function get_refreshed() {
		return $this->refreshed;
	}
	
	/**
	 * Set the expires timestamp value
	 * @param int $timestamp
	 */
	public function set_expires( $timestamp ) {
		$this->expires = intval( $timestamp );
	}
	
	/**
	 * Return the expires value
	 *
	 * @return int
	 */
	public function get_expires() {
		return $this->expires;
	}
	/**
	 * Get the list of future/upcoming Sequence members/posts
	 *
	 * @return array
	 */
	public function get_upcoming_posts() {
		
		return $this->upcoming;
	}
	
	/**
	 * Set the list of future/upcoming Sequence members/posts
	 *
	 * @param array $upcoming
	 */
	public function set_upcoming_posts( $upcoming ) {
		
		$this->upcoming = $upcoming;
	}
	
	/**
	 * Set the private $posts variable
	 *
	 * @param array $post_list
	 */
	public function set_posts( $post_list ) {
		
		$this->posts = $post_list;
	}
	
	/**
	 * Fetch the entire contents of the private $posts array
	 *
	 * @return array|null
	 */
	public function get_posts() {
		
		if ( ! empty( $this->posts ) ) {
			return $this->posts;
		}
		
		return array();
	}
	
	/**
	 * Check if the conversion process to e20r_sequence as the post type has been completed?
	 *
	 * @return bool|null
	 */
	public static function uses_new_post_type() {
		
		if ( null === ( $uses_new = Cache::get( 'using_new_type', E20R_Sequences::cache_key ) ) ) {
			
			$query = array(
				'post_type'  => Model::cpt_type,
				'post_limit' => 1,
			);
			
			$found = new \WP_Query( $query );
			
			$uses_new = $found->have_posts();
			
			if ( ! is_null( $uses_new ) ) {
				Cache::set( 'using_new_type', $uses_new, HOUR_IN_SECONDS, E20R_Sequences::cache_key );
			}
		}
		
		return $uses_new;
	}
	
	/**
	 * Return true if the sequence ID has a membership level
	 *
	 * @param int $sequence_id
	 *
	 * @return bool
	 */
	public function is_protected( $sequence_id = null ) {
		
		if ( empty( $sequence_id ) ) {
			$controller = Controller::get_instance();
			$sequence_id = $controller->sequence_id;
		}
		
		$is_protected = apply_filters( 'e20r-sequences-membership-is-sequence-protected', false, $sequence_id );
		
		return $is_protected;
	}
	
	/**
	 * Registers the Sequence Custom Post Type (CPT)
	 *
	 * @return bool -- True if successful
	 *
	 * @access public
	 *
	 */
	static public function create_sequence_post_type() {
		
		// Not going to want to do this when deactivating
		global $e20r_sequence_deactivating;
		$utils = Utilities::get_instance();
		
		if ( ! empty( $e20r_sequence_deactivating ) ) {
			return false;
		}
		
		if ( false === self::uses_new_post_type() ) {
			self::convert_post_type();
		}
		
		$default_slug = apply_filters( 'e20r-sequence-cpt-slug', get_option( 'e20r_sequence_slug', Controller::plugin_slug ) );
		$post_type    = Model::cpt_type;
		
		self::create_sequence_taxonomy( $post_type, $default_slug );
		
		$labels = array(
			'name'               => __( 'Sequences', Controller::plugin_slug ),
			'singular_name'      => __( 'Sequence', Controller::plugin_slug ),
			'slug'               => Controller::plugin_slug,
			'add_new'            => __( 'New Sequence', Controller::plugin_slug ),
			'add_new_item'       => __( 'New Sequence', Controller::plugin_slug ),
			'edit'               => __( 'Edit Sequence', Controller::plugin_slug ),
			'edit_item'          => __( 'Edit Sequence', Controller::plugin_slug ),
			'new_item'           => __( 'Add New', Controller::plugin_slug ),
			'view'               => __( 'View Sequence', Controller::plugin_slug ),
			'view_item'          => __( 'View This Sequence', Controller::plugin_slug ),
			'search_items'       => __( 'Search Sequences', Controller::plugin_slug ),
			'not_found'          => __( 'No Sequence Found', Controller::plugin_slug ),
			'not_found_in_trash' => __( 'No Sequence Found In Trash', Controller::plugin_slug ),
		);
		
		$error = register_post_type( $post_type,
			array(
				'labels'             => apply_filters( 'e20r-sequence-cpt-labels', $labels ),
				'public'             => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'publicly_queryable' => true,
				'hierarchical'       => true,
				'supports'           => array( 'title', 'editor', 'thumbnail', 'custom-fields', 'author', 'excerpt' ),
				'can_export'         => true,
				'show_in_nav_menus'  => true,
				'menu_icon'          => E20R_SEQUENCE_PLUGIN_URL . "/images/e20r-drip-feed-sequences-icon-16x16.png",
				'rewrite'            => array(
					'slug'       => $default_slug,
					'with_front' => false,
				),
				'has_archive'        => apply_filters( 'e20r-sequence-cpt-archive-slug', 'sequences' ),
			)
		);
		
		if ( ! is_wp_error( $error ) ) {
			return true;
		} else {
			$utils->log( 'Error creating post type: ' . $error->get_error_message() );
			wp_die( $error->get_error_message() );
			
			return false;
		}
	}
	
	/**
	 * Create Taxonomy for E20R Sequences
	 *
	 * @param string $post_type
	 * @param string $slug
	 */
	private static function create_sequence_taxonomy( $post_type, $slug ) {
		
		$taxonomy_labels = array(
			'name'              => __( 'Sequence Type', $slug ),
			'singular_name'     => __( 'Sequence Type', $slug ),
			'menu_name'         => _x( 'Sequence Types', 'Admin menu name', $slug ),
			'search_items'      => __( 'Search Sequence Type', $slug ),
			'all_items'         => __( 'All Sequence Types', $slug ),
			'parent_item'       => __( 'Parent Sequence Type', $slug ),
			'parent_item_colon' => __( 'Parent Sequence Type:', $slug ),
			'edit_item'         => __( 'Edit Sequence Type', $slug ),
			'update_item'       => __( 'Update Sequence Type', $slug ),
			'add_new_item'      => __( 'Add New Sequence Type', $slug ),
			'new_item_name'     => __( 'New Sequence Type Name', $slug ),
		);
		
		register_taxonomy( 'seq_type', $post_type, array(
				'hierarchical'      => true,
				'label'             => __( 'Sequence Type', $slug ),
				'labels'            => $taxonomy_labels,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_nav_menus' => true,
				'query_var'         => true,
				'rewrite'           => array(
					'slug'         => 'sequence-type',
					'with_front'   => false,
					'hierarchical' => true,
				),
			)
		);
	}
	
	/**
	 * Convert sequence ID to V3 metadata config if it hasn't been converted already.
	 *
	 * @param $seq_id
	 */
	public function upgrade_sequence( $seq_id, $force ) {
		
		$utils      = Utilities::get_instance();
		$controller = Controller::get_instance();
		
		$utils->log( "Process {$seq_id} for V3 upgrade?" );
		
		if ( ( true === version_compare( E20R_SEQUENCE_VERSION, '3.0.0', '<=' ) ) && false === $this->is_converted( $seq_id ) ) {
			
			$utils->log( "Need to convert sequence #{$seq_id} to V3 format" );
			$controller->get_options( $seq_id );
			
			if ( $this->convert_posts_to_v3( $seq_id, true ) ) {
				$utils->log( "Converted {$seq_id} to V3 format" );
				$this->convert_user_notifications( $seq_id );
				$utils->log( "Converted {$seq_id} user notifications to V3 format" );
			} else {
				$utils->log( "Error during conversion of {$seq_id} to V3 format" );
			}
		} else if ( true === version_compare( E20R_SEQUENCE_VERSION, '3.0.0', '>' ) && false === $this->is_converted( $seq_id ) ) {
			$utils->log( "Sequence id# {$controller->sequence_id} doesn't need to be converted to v3 metadata format" );
			$this->current_metadata_versions[ $controller->sequence_id ] = 3;
			update_option( "e20r_sequence_metadata_version", $this->current_metadata_versions );
		}
	}
	
	/**
	 * Check whether a specific sequence ID has been converted to the V3 metadata format
	 *
	 * @param $sequence_id - the ID of the sequnence to check for
	 *
	 * @return bool - True if it's been converted, false otherwise.
	 */
	public function is_converted( $sequence_id ) {
		
		$utils      = Utilities::get_instance();
		$controller = Controller::get_instance();
		
		$utils->log( "Check whether sequence ID {$sequence_id} is converted already" );
		
		if ( empty( $this->current_metadata_versions ) ) {
			
			$this->current_metadata_versions = $this->load_metadata_versions();
		}
		
		// $utils->log( "Sequence metadata map: " ); // . print_r( $this->current_metadata_versions, true ) );
		
		if ( ! isset( $this->current_metadata_versions[ $sequence_id ] ) && true === version_compare( E20R_SEQUENCE_VERSION, '3.0', '>=' ) ) {
			
			$utils->log( "No need to convert {$sequence_id} to v3 as we're past that point" );
			$this->current_metadata_versions[ $sequence_id ] = 3;
			update_option( 'e20r_sequence_metadata_version', $this->current_metadata_versions, true );
			
			return true;
		}
		
		if ( empty( $this->current_metadata_versions ) ) {
			$utils->log( "{$sequence_id} needs to be converted to V3 format (nothing)" );
			
			return false;
		}
		
		$has_pre_v3 = get_post_meta( $sequence_id, "_sequence_posts", true );
		
		if ( ( false !== $has_pre_v3 ) && ( ! isset( $this->current_metadata_versions[ $sequence_id ] ) || ( 3 != $this->current_metadata_versions[ $sequence_id ] ) ) ) {
			$utils->log( "{$sequence_id} needs to be converted to V3 format" );
			
			return false;
		}
		
		if ( ( false === $has_pre_v3 ) || ( isset( $this->current_metadata_versions[ $sequence_id ] ) && ( 3 == $this->current_metadata_versions[ $sequence_id ] ) ) ) {
			
			$utils->log( "{$sequence_id} is at v3 format" );
			
			return true;
		}
		
		if ( wp_is_post_revision( $sequence_id ) ) {
			return true;
		}
		
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return true;
		}
		
		if ( 'trash' == get_post_status( $sequence_id ) ) {
			return true;
		}
		
		$arguments = array(
			'posts_per_page' => - 1,
			'meta_query'     => array(
				array(
					'key'     => '_e20r_sequence_post_belongs_to',
					'value'   => $sequence_id,
					'compare' => '=',
				),
			),
		);
		
		$is_converted = new \WP_Query( $arguments );
		
		$options = $controller->get_options( $sequence_id );
		
		if ( $is_converted->post_count >= 1 && $options->loaded === true ) {
			
			if ( ! isset( $this->current_metadata_versions[ $sequence_id ] ) ) {
				
				$utils->log( "Sequence # {$sequence_id} is converted already. Updating the settings" );
				$this->current_metadata_versions[ $sequence_id ] = 3;
				update_option( 'e20r_sequence_metadata_version', $this->current_metadata_versions, true );
			}
			
			return true;
		}
		
		return false;
	}
	
	/**
	 * Returns a list of all defined drip-sequences
	 *
	 * @param $statuses string|array - Post statuses to return posts for.
	 *
	 * @return mixed - Array of post objects
	 */
	public function get_all_sequences( $statuses = 'publish' ) {
		
		$query = array(
			'post_type'      => Model::cpt_type,
			'post_status'    => $statuses,
			'posts_per_page' => - 1, // BUG: Didn't return more than 5 sequences
		);
		
		/* Fetch all Sequence posts - NOTE: Using \WP_Query and not the sequence specific get_posts() function! */
		$all_posts = get_posts( $query );
		
		wp_reset_query();
		
		return $all_posts;
	}
	
	/**
	 * Static function that returns all sequences in the system that have the specified post status
	 *
	 * @param string $statuses
	 *
	 * @return array of Sequence objects
	 */
	static public function all_sequences( $statuses = 'publish' ) {
		
		$model = self::get_instance();
		
		return $model->get_all_sequences( $statuses );
	}
	
	/**
	 * Converts the posts for a sequence to the V3 metadata format (settings)
	 *
	 * @param null $loaded_sequence_id - The ID of the sequence to convert.
	 * @param bool $force              - Whether to force the conversion or not.
	 *
	 * @return bool - Whether the conversion successfully completed or not
	 */
	public function convert_posts_to_v3( $load_sequence_id = null, $force = false ) {
		
		global $conv_sequence;
		
		$conv_sequence = true;
		$utils         = Utilities::get_instance();
		$controller    = Controller::get_instance();
		
		$current_seq_id = null;
		
		if ( ! is_null( $load_sequence_id ) ) {
			
			if ( isset( $this->current_metadata_versions[ $load_sequence_id ] ) && ( 3 >= $this->current_metadata_versions[ $load_sequence_id ] ) ) {
				
				$utils->log( "Sequence {$load_sequence_id} is already converted." );
				
				return true;
			}
			
			$current_seq_id = $controller->get_current_sequence_id();
			/**
			 * if ( false === $force  ) {
			 *
			 * $utils->log("Loading posts for {$sequence_id}.");
			 * $this->get_options( $sequence_id );
			 * $this->load_sequence_post();
			 * }
			 */
		}
		
		$load_sequence_id = $controller->get_current_sequence_id();
		
		$is_pre_v3 = get_post_meta( $load_sequence_id, "_sequence_posts", true );
		
		$utils->log( "Need to convert from old metadata format to new format for sequence {$load_sequence_id}" );
		$retval = true;
		
		// $tmp = get_post_meta( $sequence_id, "_sequence_posts", true );
		$posts = ( ! empty( $is_pre_v3 ) ? $is_pre_v3 : array() ); // Fixed issue where empty sequences would generate error messages.
		
		foreach ( $posts as $seq_post ) {
			
			$utils->log( "Adding post #{$seq_post->id} with delay {$seq_post->delay} to sequence {$load_sequence_id} " );
			
			$added_to_seq = false;
			$s_list       = get_post_meta( $seq_post->id, "_e20r_sequence_post_belongs_to" );
			
			if ( false == $s_list || ! in_array( $load_sequence_id, $s_list ) ) {
				add_post_meta( $seq_post->id, '_e20r_sequence_post_belongs_to', $load_sequence_id );
				$added_to_seq = true;
			}
			
			if ( true === $controller->allow_repetition() && true === $added_to_seq ) {
				add_post_meta( $seq_post->id, "_e20r_sequence_{$load_sequence_id}_post_delay", $seq_post->delay );
				
			} else if ( false == $controller->allow_repetition() && true === $added_to_seq ) {
				
				update_post_meta( $seq_post->id, "_e20r_sequence_{$load_sequence_id}_post_delay", $seq_post->delay );
			}
			
			if ( ( false !== get_post_meta( $seq_post->id, '_e20r_sequence_post_belongs_to', true ) ) &&
			     ( false !== get_post_meta( $seq_post->id, "_e20r_sequence_{$load_sequence_id}_post_delay", true ) )
			) {
				$utils->log( "Edited metadata for migrated post {$seq_post->id} and delay {$seq_post->delay}" );
				$retval = true;
				
			} else {
				delete_post_meta( $seq_post->id, '_pmpro_sequence_post_belongs_to', $load_sequence_id );
				delete_post_meta( $seq_post->id, "_pmpro_sequence_{$load_sequence_id}_post_delay", $seq_post->delay );
				$retval = false;
			}
			
			// $retval = $retval && $this->add_post_to_sequence( $controller->sequence_id, $seq_post->id, $seq_post->delay );
		}
		
		// $utils->log("Saving to new V3 format... " );
		// $retval = $retval && $this->save_sequence_post();
		
		$utils->log( "(Not) Removing old format meta... " );
		// $retval = $retval && delete_post_meta( $controller->sequence_id, "_sequence_posts" );
		
		if ( empty( $load_sequence_id ) ) {
			
			$retval = false;
			$controller->set_error_msg( __( "Cannot convert to V3 metadata format: No sequences were defined.", Controller::plugin_slug ) );
		}
		
		if ( $retval == true ) {
			
			$utils->log( "Converted sequence id# {$load_sequence_id} to v3 metadata format for all sequence member posts" );
			$this->current_metadata_versions[ $load_sequence_id ] = 3;
			update_option( "e20r_sequence_metadata_version", $this->current_metadata_versions );
			
			// Reset sequence info.
			$controller->get_options( $current_seq_id );
			$this->load_sequence_post( null, null, null, '=', null, true );
			
		} else {
			$controller->set_error_msg( sprintf( __( "Unable to upgrade post metadata for sequence (%s)", Controller::plugin_slug ), get_the_title( $load_sequence_id ) ) );
		}
		
		$conv_sequence = false;
		
		return $retval;
	}
	
	/**
	 * Convert any pmpro_sequence CPT post types to e20r_sequence
	 */
	public static function convert_post_type() {
		
		global $wpdb;
		
		$wpdb->update( $wpdb->posts, array( 'post_type' => Model::cpt_type ), array( 'post_type' => 'pmpro_sequence' ), array( '%s' ), array( '%s' ) );
	}
	
	/**
	 * Trigger conversion of the user notification metadata for all users - called as part of activation or upgrade.
	 *
	 * @param int|null $f_seq_id - Sequence ID to convert user notification(s) for.
	 */
	public function convert_user_notifications( $f_seq_id = null ) {
		
		global $wpdb;
		
		// Load all sequences from the DB
		$query = array(
			'post_type'      => Model::cpt_type,
			'post_status'    => apply_filters( 'e20r-sequence-allowed-post-statuses', array(
				'publish',
				'future',
				'private',
			) ),
			'posts_per_page' => - 1,
		);
		
		
		$sequence_list = new \WP_Query( $query );
		$utils         = Utilities::get_instance();
		$controller    = Controller::get_instance();
		
		$utils->log( "Found " . count( $sequence_list ) . " sequences to process for alert conversion" );
		
		while ( $sequence_list->have_posts() ) {
			
			$sequence_list->the_post();
			$sequence_id = get_the_ID();
			
			if ( is_null( $f_seq_id ) || ( $f_seq_id == $sequence_id ) ) {
				
				$controller->get_options( $sequence_id );
				$users = $controller->get_users_of_sequence();
				
				foreach ( $users as $wp_user ) {
					
					$controller->e20r_sequence_user_id = $wp_user->user_id;
					$user_settings                     = get_user_meta( $wp_user->user_id, "e20r_sequence_id_{$sequence_id}_notices", true );
					
					// No V3 formatted settings found. Will convert from V2 (if available)
					if ( empty( $user_settings ) || ( ! isset( $user_settings->send_notices ) ) ) {
						
						$utils->log( "Converting notification settings for user with ID: {$wp_user->user_id}" );
						$utils->log( "Loading V2 meta: {$wpdb->prefix}pmpro_sequence_notices for user ID: {$wp_user->user_id}" );
						
						$v2_meta = get_user_meta( $wp_user->user_id, "{$wpdb->prefix}e20r_sequence_notices", true );
						
						// $utils->log($old_optIn);
						
						if ( ! empty( $v2_meta ) ) {
							
							$utils->log( "V2 settings found. They are: " );
							$utils->log( $v2_meta );
							
							$utils->log( "Found old-style notification settings for user {$wp_user->user_id}. Attempting to convert" );
							
							// Loop through the old-style array of sequence IDs
							$count = 1;
							
							foreach ( $v2_meta->sequence as $f_seq_id => $seq_data ) {
								
								$utils->log( "Converting sequence notices for {$f_seq_id} - Number {$count} of " . count( $v2_meta->sequence ) );
								$count ++;
								
								$user_settings = $this->convert_alert_setting( $wp_user->user_id, $f_seq_id, $seq_data );
								
								if ( isset( $user_settings->send_notices ) ) {
									
									$controller->save_user_notice_settings( $wp_user->user_id, $user_settings, $f_seq_id );
									$utils->log( " Removing converted opt-in settings from the database" );
									delete_user_meta( $wp_user->user_id, "{$wpdb->prefix}pmpro_sequence_notices" );
								}
							}
						}
						
						if ( empty( $v2_meta ) && empty( $user_settings ) ) {
							
							$utils->log( "convert_user_notification() - No v2 or v3 alert settings found for {$wp_user->user_id}. Skipping this user" );
							continue;
						}
						
						$utils->log( "V3 Alert settings for user {$wp_user->user_id}" );
						$utils->log( $user_settings );
						
						$user_settings->completed = true;
						$utils->log( "Saving new notification settings for user with ID: {$wp_user->user_id}" );
						
						if ( ! $controller->save_user_notice_settings( $wp_user->user_id, $user_settings, $sequence_id ) ) {
							
							$utils->log( "convert_user_notification() - Unable to save new notification settings for user with ID {$wp_user->user_id}" );
						}
					} else {
						$utils->log( "convert_user_notification() - No alert settings to convert for {$wp_user->user_id}" );
						$utils->log( "convert_user_notification() - Checking existing V3 settings..." );
						
						$member_days = $controller->get_membership_days( $wp_user->user_id );
						
						// TODO: Save post list (current)
						$old_posts = $this->posts;
						
						$compare       = $this->load_sequence_post( $sequence_id, $member_days, null, '<=', null, true );
						$user_settings = $controller->fix_user_alert_settings( $user_settings, $compare, $member_days );
						$controller->save_user_notice_settings( $wp_user->user_id, $user_settings, $sequence_id );
						
						// TODO: Restore the post list (current)
						$this->posts = $old_posts;
					}
					
					if ( isset( $wp_user->user_id ) && ! $this->remove_old_user_alert_setting( $wp_user->user_id ) ) {
						
						$utils->log( "Unable to remove old user_alert settings!" );
					}
				}
			}
		}
		
		wp_reset_postdata();
	}
	
	/**
	 * Updates the alert settings for a given userID
	 *
	 * @param int       $user_id  - The user ID to convert settings for
	 * @param int       $f_seq_id - The Sequence ID to check/use
	 * @param \stdClass $v2_meta  - The V2 sequence
	 *
	 * @return \stdClass - The converted notification settings for the $user_id.
	 */
	private function convert_alert_setting( $user_id, $f_seq_id, $v2_meta ) {
		
		$utils      = Utilities::get_instance();
		$controller = Controller::get_instance();
		
		$v3_settings = $controller->create_user_notice_defaults();
		
		$v3_settings->id = $f_seq_id;
		
		$member_days = $controller->get_membership_days( $user_id );
		$options     = $controller->get_options( $f_seq_id );
		
		$compare = $this->load_sequence_post( $f_seq_id, $member_days, null, '<=', null, true );
		
		$utils->log( "Converting the sequence ( {$f_seq_id} ) post list for user alert settings" );
		
		$when = isset( $v2_meta->optinTS ) ? $v2_meta->optinTS : current_time( 'timestamp' );
		
		$v3_settings->send_notices = $v2_meta->sendNotice;
		$v3_settings->posts        = $v2_meta->notifiedPosts;
		$v3_settings->optin_at     = $v2_meta->last_notice_sent = $when;
		
		$utils->log( "Looping through " . count( $v3_settings->posts ) . " alert entries" );
		foreach ( $v3_settings->posts as $key => $post_id ) {
			
			if ( false === strpos( $post_id, '_' ) ) {
				
				$utils->log( "This entry ({$post_id}) needs to be converted..." );
				$posts = $controller->find_by_id( $post_id );
				
				foreach ( $posts as $p ) {
					
					$flag_value = "{$p->id}_" . $controller->normalize_delay( $p->delay );
					
					if ( ( $p->id == $post_id ) && ( $controller->normalize_delay( $p->delay ) <= $member_days ) ) {
						
						if ( $v3_settings->posts[ $key ] == $post_id ) {
							
							$utils->log( "Converting existing alert entry" );
							$v3_settings->posts[ $key ] = $flag_value;
						} else {
							$utils->log( "Adding alert entry" );
							$v3_settings->posts[] = $flag_value;
						}
					}
				}
			}
		}
		
		$compare     = $this->load_sequence_post( null, $member_days, null, '<=', null, true );
		$v3_settings = $controller->fix_user_alert_settings( $v3_settings, $compare, $member_days );
		
		return $v3_settings;
	}
	
	/**
	 * Clear (remove) the usermeta containing the old notification settings for $user_id
	 *
	 * @param $user_id - The ID of the user who's settings we'll remove.
	 *
	 * @return bool -- Whether we were able to remove them or not.
	 */
	private function remove_old_user_alert_setting( $user_id ) {
		
		global $wpdb;
		
		$v2_meta = get_post_meta( $user_id, "{$wpdb->prefix}e20r_sequence_notices", true );
		
		if ( ! empty( $v2_meta ) ) {
			
			return delete_user_meta( $user_id, "{$wpdb->prefix}e20r_sequence_notices" );
		} else {
			// No v2 meta found..
			return true;
		}
	}
	
	/**
	 * Adds the specified post to this sequence
	 *
	 * @param int   $post_id          -- The ID of the post to add to this sequence
	 * @param mixed $delay            -- The delay to apply to the post
	 * @param int   $visibility_delay -- The number of days to show or hide the post for non-members
	 *
	 * @return bool -- Success or failure
	 *
	 * @access public
	 */
	public function add_post( $post_id, $delay, $visibility_delay = null ) {
		
		$utils      = Utilities::get_instance();
		$controller = Controller::get_instance();
		
		$sequence_id = $controller->get_current_sequence_id();
		
		$utils->log( "add_post or sequence ({$sequence_id}): " . $utils->_who_called_me() );
		
		$sequence_status = get_post_status( $sequence_id );
		
		if ( in_array( $sequence_status, array( 'inherit', 'auto-draft', 'trash' ) ) ) {
			
			$utils->log("Sequence isn't saved yet, so can't save add sequence content to it" );
			$controller->set_error_msg( __("The sequence has to be saved before you can add posts/pages/content to it", Controller::plugin_slug ) );
			
			return false;
		}
		
		/*        if (! $this->is_valid_delay($delay) )
        {
            $utils->log('Admin specified an invalid delay value for post: ' . ( empty($post_id) ? 'Unknown' :  $post_id) );
            $this->set_error_msg( sprintf(__('Invalid delay value - %s', Controller::plugin_slug), ( empty($delay) ? 'blank' : $delay ) ) );
            return false;
        }
*/
		if ( empty( $post_id ) || ! isset( $delay ) ) {
			$controller->set_error_msg( __( "Please enter a value for post and delay", Controller::plugin_slug ) );
			$utils->log( 'No Post ID or delay specified' );
			
			return false;
		}
		
		$utils->log( 'Post ID: ' . $post_id . ' and delay: ' . $delay );
		
		if ( $post = get_post( $post_id ) === null ) {
			
			$controller->set_error_msg( __( "A post with that id does not exist", Controller::plugin_slug ) );
			$utils->log( 'No Post with ' . $post_id . ' found' );
			
			return false;
		}
		
		/*        if ( $this->is_present( $post_id, $delay ) ) {

            $utils->log("Post {$post_id} with delay {$delay} is already present in sequence {$controller->sequence_id}");
            return true;
        }
*/
		// Refresh the post list for the sequence, ignore cache
		if ( current_time( 'timestamp' ) >= $this->expires && ! empty( $this->posts ) ) {
			
			$utils->log( "Refreshing post list for sequence #{$sequence_id}" );
			$this->load_sequence_post();
		}
		
		// Add this post to the current sequence.
		
		$utils->log( "Adding post {$post_id} with delay {$delay} to sequence {$sequence_id}" );
		if ( ! $this->add_post_to_sequence( $sequence_id, $post_id, $delay, $visibility_delay ) ) {
			
			$utils->log( "- ERROR: Unable to add post {$post_id} to sequence {$sequence_id} with delay {$delay}" );
			$controller->set_error_msg( sprintf( __( "Error adding %s to %s", Controller::plugin_slug ), get_the_title( $post_id ), get_the_title( $sequence_id ) ) );
			
			return false;
		}
		
		//sort
		$utils->log( 'Sorting the sequence posts by delay value(s)' );
		usort( $this->posts, array( $controller, 'sort_posts_by_delay' ) );
		
		// Save the sequence list for this post id
		
		/* $this->set_sequences_for_post( $post_id, $post_in_sequences ); */
		// update_post_meta( $post_id, "_post_sequences", $post_in_sequences );
		
		$utils->log( 'Post/Page list updated and saved' );
		
		return true;
	}
	
	/**
	 * Save post specific metadata to indicate sequence & delay value(s) for the post.
	 *
	 * @param null $sequence_id - The sequence to save data for.
	 * @param null $post_id     - The ID of the post to save metadata for
	 * @param null $delay       - The delay value
	 *
	 * @return bool - True/False depending on whether the save operation was a success or not.
	 * @since v3.0
	 *
	 */
	public function save_sequence_post( $sequence_id = null, $post_id = null, $delay = null, $visibiliy_delay = null ) {
		
		$utils = Utilities::get_instance();
		
		$controller = Controller::get_instance();
		
		
		if ( is_null( $post_id ) && is_null( $delay ) && is_null( $sequence_id ) ) {
			
			$found_seq_id = $controller->get_current_sequence_id();
			
			// Save all posts in $posts array to new V3 format.
			foreach ( $this->posts as $p_obj ) {
				
				if ( ! $this->add_post_to_sequence( $found_seq_id, $p_obj->id, $p_obj->delay, $visibiliy_delay ) ) {
					
					$utils->log( "Unable to add post {$p_obj->id} with delay {$p_obj->delay} to sequence {$found_seq_id}" );
					
					return false;
				}
			}
			
			return true;
		}
		
		if ( ! is_null( $post_id ) && ! is_null( $delay ) ) {
			
			if ( empty( $sequence_id ) ) {
				
				$sequence_id = $controller->get_current_sequence_id();
			}
			
			$utils->log( "Saving post {$post_id} with delay {$delay} to sequence {$sequence_id}" );
			
			return $this->add_post_to_sequence( $sequence_id, $post_id, $delay, $visibiliy_delay );
		} else {
			$utils->log( "Need both post ID and delay values to save the post to sequence {$sequence_id}" );
			
			return false;
		}
	}
	
	/**
	 * Private function to do the heavy lifting for the sequence specific metadata saves (per post)
	 *
	 * @param     $sequence_id
	 * @param     $post_id
	 * @param     $delay
	 * @param int $visibility_delay
	 *
	 * @return bool
	 */
	private function add_post_to_sequence( $sequence_id, $post_id, $delay, $visibility_delay ) {
		
		global $current_user;
		
		$utils      = Utilities::get_instance();
		$controller = Controller::get_instance();
		
		$utils->log( "Adding post {$post_id} to sequence {$sequence_id} using v3 meta format" );
		
		$new_posts    = array();
		$new_upcoming = array();
		
		/**
		 * if ( false === $found_post && ) {
		 *
		 * $utils->log("Post {$post_id} with delay {$delay} is already present in sequence {$sequence_id}");
		 * $this->set_error_msg( __( 'That post and delay combination is already included in this sequence', Controller::plugin_slug ) );
		 * return true;
		 * }
		 **/
		
		$found_post = $controller->is_present( $post_id, $delay );
		
		$posts = $controller->find_by_id( $post_id, $sequence_id, $current_user->ID );
		
		if ( ( count( $posts ) > 0 && false === $controller->allow_repetition() ) || true === $found_post ) {
			
			$utils->log( "Post is a duplicate and we're not allowed to add duplicates" );
			$utils->add_message( sprintf( __( "'%s' settings do not permit multiple delay values for a single post ID", Controller::plugin_slug ), get_the_title( $sequence_id ) ), 'warning', 'backend' );
			
			foreach ( $posts as $p ) {
				
				$utils->log( "Delay is different & we can't have repeat posts. Need to remove existing instances of {$post_id} and clear any notices" );
				$this->remove_post( $p->id, $p->delay, true );
			}
		}
		
		if ( is_admin() ) {
			
			$member_days = - 1;
		} else {
			
			$member_days = $controller->get_membership_days( $current_user->ID );
		}
		
		$utils->log( "The post was not found in the current list of posts for {$sequence_id}" );
		$utils->log( "Loading post {$post_id} from DB using WP_Query" );
		
		$tmp = new \WP_Query( array(
				'p'              => $post_id,
				'post_type'      => apply_filters( 'e20r-sequence-managed-post-types', array( 'post', 'page' ) ),
				'posts_per_page' => 1,
				'post_status'    => apply_filters( 'e20r-sequence-allowed-post-statuses', array(
					'publish',
					'future',
					'private',
				) ),
			)
		);
		
		$p = $tmp->get_posts();
		
		$utils->log( "Loaded " . count( $p ) . " posts with WP_Query" );
		
		$new_post     = new \stdClass();
		$new_post->id = $p[0]->ID;
		
		$new_post->delay = $delay;
		// $new_post->order_num = $this->normalize_delay( $delay ); // BUG: Can't handle repeating delay values (ie. two posts with same delay)
		$new_post->permalink        = get_permalink( $new_post->id );
		$new_post->title            = get_the_title( $new_post->id );
		$new_post->is_future        = ( $member_days < $delay ) && ( $controller->hide_upcoming_posts() ) ? true : false;
		$new_post->current_post     = false;
		$new_post->type             = get_post_type( $new_post->id );
		$new_post->visibility_delay = $visibility_delay;
		
		$belongs_to = get_post_meta( $new_post->id, "_e20r_sequence_post_belongs_to" );
		
		wp_reset_postdata();
		
		$utils->log( "Found the following sequences for post {$new_post->id}: " . ( false === $belongs_to ? 'Not found' : null ) );
		$utils->log( "Sequence list: " . print_r( $belongs_to, true ) );
		
		if ( ( false === $belongs_to ) || ( is_array( $belongs_to ) && ! in_array( $sequence_id, $belongs_to ) ) ) {
			
			if ( false === add_post_meta( $post_id, "_e20r_sequence_post_belongs_to", $sequence_id ) ) {
				$utils->log( "Unable to add/update this post {$post_id} for the sequence {$sequence_id}" );
			}
		}
		
		$utils->log( "Attempting to add delay value {$delay} for post {$post_id} to sequence: {$sequence_id}" );
		
		if ( ! $controller->allow_repetition() ) {
			// TODO: Need to check if the meta/value combination already exists for the post ID.
			if ( false === add_post_meta( $post_id, "_e20r_sequence_{$sequence_id}_post_delay", $delay, true ) ) {
				
				$utils->log( "Couldn't add {$post_id} with delay {$delay}. Attempting update operation" );
				
				if ( false === update_post_meta( $post_id, "_e20r_sequence_{$sequence_id}_post_delay", $delay ) ) {
					$utils->log( "Both add and update operations for {$post_id} in sequence {$sequence_id} with delay {$delay} failed!" );
				}
			}
			
			if ( ! empty( $visibility_delay ) ) {
				if ( false === add_post_meta( $post_id, "_e20r_sequence_{$sequence_id}_visibility_delay", $visibility_delay, true ) ) {
					$utils->log( "Couldn't add {$post_id} with visibility delay {$visibility_delay}. Attempting update operation" );
					
					if ( false === update_post_meta( $post_id, "_e20r_sequence_{$sequence_id}_visibility_delay", $visibility_delay ) ) {
						$utils->log( "Both add and update operations for {$post_id} in sequence {$sequence_id} with visibility delay {$visibility_delay} failed!" );
					}
				}
			}
		} else {
			
			$delays = get_post_meta( $post_id, "_e20r_sequence_{$sequence_id}_post_delay" );
			
			$utils->log( "Checking whether the '{$delay}' delay value is already recorded for this post: {$post_id}" );
			
			if ( ( false === $delays ) || ( ! in_array( $delay, $delays ) ) ) {
				
				$utils->log( "add_post_to_seuqence() - Not previously added. Now adding delay value meta ({$delay}) to post id {$post_id}" );
				add_post_meta( $post_id, "_e20r_sequence_{$sequence_id}_post_delay", $delay );
				
				if ( ! empty( $visibility_delay ) ) {
					add_post_meta( $post_id, "_e20r_sequence_{$sequence_id}_visibility_delay", $visibility_delay );
				}
			} else {
				$utils->log( "Post # {$post_id} in sequence {$sequence_id} is already recorded with delay {$delay}" );
			}
		}
		
		if ( false === get_post_meta( $post_id, "_e20r_sequence_post_belongs_to" ) ) {
			
			$utils->log( "Didn't add {$post_id} to {$sequence_id}" );
			
			return false;
		}
		
		if ( false === get_post_meta( $post_id, "_e20r_sequence_{$sequence_id}_post_delay" ) ) {
			
			$utils->log( "Couldn't add post/delay value(s) for {$post_id}/{$delay} to {$sequence_id}" );
			
			return false;
		}
		
		if ( ! empty( $visibility_delay ) && false === get_post_meta( $post_id, "_e20r_sequence_{$sequence_id}_visibility_delay" ) ) {
			
			$utils->log( "Couldn't add post/visibility delay value(s) for {$post_id}/{$visibility_delay} to {$sequence_id}" );
			
			return false;
		}
		
		// If we shoud be allowed to access this post.
		if ( $controller->has_post_access( $current_user->ID, $post_id, false, $sequence_id ) ||
		     false === $new_post->is_future ||
		     ( ( true === $new_post->is_future ) && false === $controller->hide_upcoming_posts() )
		) {
			
			$utils->log( "Adding post to sequence: {$sequence_id}" );
			$new_posts[] = $new_post;
		} else {
			
			$utils->log( "User doesn't have access to the post so not adding it." );
			$new_upcoming[] = $new_post;
		}
		
		usort( $new_posts, array( $controller, 'sort_posts_by_delay' ) );
		
		if ( ! empty( $new_upcoming ) ) {
			
			usort( $new_upcoming, array( $controller, 'sort_posts_by_delay' ) );
			$this->set_upcoming_posts( $new_upcoming );
		}
		
		
		$controller->set_cache( $new_posts, $sequence_id );
		$this->posts = $new_posts;
		
		return true;
	}
	
	/**
	 * Removes a post from the list of posts belonging to this sequence
	 *
	 * @param int  $post_id        -- The ID of the post to remove from the sequence
	 * @param int  $delay          - The delay value for the post we'd like to remove from the sequence.
	 * @param bool $remove_alerted - Whether to also remove any 'notified' settings for users
	 *
	 * @return bool - returns TRUE if the post was removed and the metadata for the sequence was updated successfully
	 *
	 * @access public
	 */
	public function remove_post( $post_id, $delay = null, $remove_alerted = true ) {
		
		$utils      = Utilities::get_instance();
		$controller = Controller::get_instance();
		
		$is_multi_post = false;
		
		if ( empty( $post_id ) ) {
			
			return false;
		}
		
		$sequence_id = $controller->get_current_sequence_id();
		
		$this->load_sequence_post();
		
		if ( empty( $this->posts ) ) {
			
			return true;
		}
		
		foreach ( $this->posts as $i => $post ) {
			
			// Remove this post from the sequence
			if ( ( $post_id == $post->id ) && ( $delay == $post->delay ) ) {
				
				// $this->posts = array_values( $this->posts );
				
				$delays = get_post_meta( $post->id, "_e20r_sequence_{$sequence_id}_post_delay" );
				
				$utils->log( "Delay meta_values: " . print_r( $delays, true ) );
				
				if ( 1 == count( $delays ) ) {
					
					$utils->log( "A single post associated with this post id: {$post_id}" );
					
					if ( false === delete_post_meta( $post_id, "_e20r_sequence_{$sequence_id}_post_delay", $post->delay ) ) {
						
						$utils->log( "Unable to remove the delay meta for {$post_id} / {$post->delay}" );
						
						return false;
					}
					
					if ( false === delete_post_meta( $post_id, "_e20r_sequence_post_belongs_to", $sequence_id ) ) {
						
						$utils->log( "Unable to remove the sequence meta for {$post_id} / {$sequence_id}" );
						
						return false;
					}
				} else if ( 1 < count( $delays ) ) {
					
					$utils->log( "Multiple (" . count( $delays ) . ") posts associated with this post id: {$post_id} in sequence {$sequence_id}" );
					
					if ( false == delete_post_meta( $post_id, "_e20r_sequence_{$sequence_id}_post_delay", $post->delay ) ) {
						
						$utils->log( "Unable to remove the sequence meta for {$post_id} / {$sequence_id}" );
						
						return false;
					};
					
					$utils->log( "Keeping the sequence info for the post_id" );
				} else {
					$utils->log( "ERROR: There are _no_ delay values for post ID {$post_id}????" );
					
					return false;
				}
				
				$utils->log( "Removing entry #{$i} from posts array: " . print_r( $this->posts[ $i ], true ) );
				
				unset( $this->posts[ $i ] );
			}
			
		}
		
		$utils->log( "Updating cache for sequence {$sequence_id}" );
		$controller->delete_cache( $sequence_id );
		$controller->set_cache( $this->posts, $sequence_id );
		
		
		// Remove the post ($post_id) from all cases where a User has been notified.
		if ( $remove_alerted ) {
			
			$this->remove_post_notified_flag( $post_id, $delay );
		}
		
		if ( 0 >= count( $this->posts ) ) {
			$utils->log( "Nothing left to cache. Cleaning up..." );
			$controller->delete_cache( $sequence_id );
			$this->set_expires( null );;
		}
		
		return true;
	}
	
	/**
	 * Function will remove the flag indicating that the user has been notified already for this post.
	 * Searches through all active User IDs with the same level as the Sequence requires.
	 *
	 * @param $post_id - The ID of the post to search through the active member list for
	 *
	 * @returns bool|array() - The list of user IDs where the remove operation failed, or true for success.
	 * @access public
	 */
	public function remove_post_notified_flag( $post_id, $delay ) {
		
		$utils      = Utilities::get_instance();
		$controller = Controller::get_instance();
		
		$sequence_id = $controller->get_current_sequence_id();
		
		$utils->log( 'Preparing SQL. Using sequence ID: ' . $sequence_id );
		
		$error_users = array();
		
		// Find all users that are active members of this sequence.
		$users = $controller->get_users_of_sequence();
		
		foreach ( $users as $user ) {
			
			$utils->log( "Searching for Post ID {$post_id} in notification settings for user with ID: {$user->user_id}" );
			
			$user_settings = $controller->load_user_notice_settings( $user->user_id, $sequence_id );
			
			isset( $user_settings->id ) && $user_settings->id == $sequence_id ? $utils->log( "Notification settings exist for {$sequence_id}" ) : $utils->log( 'No notification settings found' );
			
			$notified_posts = isset( $user_settings->posts ) ? $user_settings->posts : array();
			
			if ( is_array( $notified_posts ) &&
			     ( $key = array_search( "{$post_id}_{$delay}", $notified_posts ) ) !== false
			) {
				
				$utils->log( "Found post # {$post_id} in the notification settings for user_id {$user->user_id} with key: {$key}" );
				$utils->log( "Found in settings: {$user_settings->posts[ $key ]}" );
				unset( $user_settings->posts[ $key ] );
				
				if ( $controller->save_user_notice_settings( $user->user_id, $user_settings, $sequence_id ) ) {
					
					// update_user_meta( $user->user_id, $wpdb->prefix . 'e20r_sequence_notices', $user_settings );
					$utils->log( "Deleted post # {$post_id} in the notification settings for user with id {$user->user_id}" );
				} else {
					$utils->log( "Unable to remove post # {$post_id} in the notification settings for user with id {$user->user_id}" );
					$error_users[] = $user->user_id;
				}
			} else {
				$utils->log( "Could not find the post_id/delay combination: {$post_id}_{$delay} for user {$user->user_id}" );
			}
		}
		
		if ( ! empty( $error_users ) ) {
			return $error_users;
		}
		
		return true;
	}
	
	/**
	 * Loads the post list for a sequence (or the current sequence) from the DB
	 *
	 * @param null   $sequence_id
	 * @param null   $delay
	 * @param null   $post_id
	 * @param string $comparison
	 * @param null   $pagesize
	 * @param bool   $force
	 * @param string $status
	 *
	 * @return array|bool|mixed
	 */
	public function load_sequence_post( $sequence_id = null, $delay = null, $post_id = null, $comparison = '=', $pagesize = null, $force = false, $status = 'default' ) {
		
		global $current_user;
		global $loading_sequence;
		
		$controller = Controller::get_instance();
		
		$find_by_delay = false;
		$found         = array();
		$data_type     = 'NUMERIC';
		$page_num      = 0;
		$utils         = Utilities::get_instance();
		
		$options = $controller->get_options( $sequence_id );
		
		if ( ! is_null( $controller->e20r_sequence_user_id ) && ( $controller->e20r_sequence_user_id != $current_user->ID ) ) {
			
			$utils->log( "Using user id from e20r_sequence_user_id: {$controller->e20r_sequence_user_id}" );
			$user_id = $controller->e20r_sequence_user_id;
		} else {
			$utils->log( "Using user id (from current_user): {$current_user->ID}" );
			$user_id = $current_user->ID;
		}
		
		if ( is_null( $sequence_id ) && ( ! empty( $controller->sequence_id ) ) ) {
			$utils->log( "No sequence ID specified in call. Using default value of {$controller->sequence_id}" );
			$sequence_id = $controller->sequence_id;
		}
		
		if ( empty( $sequence_id ) ) {
			
			$utils->log( "No sequence ID configured. Returning error (null)" );
			
			return null;
		}
		
		if ( ! empty( $delay ) ) {
			
			if ( $options->delayType == 'byDate' ) {
				
				$utils->log( "Expected delay value is a 'date' so need to convert" );
				$startdate = $controller->get_user_startdate( $user_id );
				
				$delay     = date( 'Y-m-d', ( $startdate + ( $delay * DAY_IN_SECONDS ) ) );
				$data_type = 'DATE';
			}
			
			$utils->log( "Using delay value: {$delay}" );
			$find_by_delay = true;
		}
		
		$utils->log( "Sequence ID var: " . ( empty( $sequence_id ) ? 'Not defined' : $sequence_id ) );
		$utils->log( "Force var: " . ( ( $force === false ) ? 'False' : 'True' ) );
		$utils->log( "Post ID var: " . ( is_null( $post_id ) ? 'Not defined' : $post_id ) );
		
		if ( ( false === $force ) && empty( $post_id ) && ( false !== ( $found = $controller->get_cache( $sequence_id ) ) ) ) {
			
			$utils->log( "Loaded post list for sequence # {$sequence_id} from cache. " . count( $found ) . " entries" );
			$this->posts = $found;
		}
		
		$utils->log( "Delay var: " . ( empty( $delay ) ? 'Not defined' : $delay ) );
		$utils->log( "Comparison var: {$comparison}" );
		$utils->log( "Page size ID var: " . ( empty( $pagesize ) ? 'Not defined' : $pagesize ) );
		$utils->log( "have to refresh data..." );
		
		// $this->refreshed = current_time('timestamp', true);
		$this->refreshed = null;
		$this->expires   = - 1;
		
		/**
		 * Expected format: array( $key_1 => stdClass $post_obj, $key_2 => stdClass $post_obj );
		 * where $post_obj = stdClass  -> id
		 *                   stdClass  -> delay
		 */
		$order_by = $options->delayType == 'byDays' ? 'meta_value_num' : 'meta_value';
		$order    = $options->sortOrder == SORT_DESC ? 'DESC' : 'ASC';
		
		if ( ( $status == 'default' ) && ( ! is_null( $post_id ) ) ) {
			
			$statuses = apply_filters( 'e20r-sequence-allowed-post-statuses', array(
				'publish',
				'future',
				'draft',
				'private',
			) );
		} else if ( $status == 'default' ) {
			
			$statuses = apply_filters( 'e20r-sequence-allowed-post-statuses', array( 'publish', 'future', 'private' ) );
		} else {
			
			$statuses = apply_filters( 'e20r-sequence-allowed-post-statuses', $status );
		}
		
		// $utils->log( "Loading posts with status: " . print_r( $statuses, true ) );
		
		if ( is_null( $post_id ) ) {
			
			$utils->log( "No post ID specified. Loading all posts...." );
			
			$seq_args = array(
				'post_type'      => apply_filters( 'e20r-sequence-managed-post-types', array( 'post', 'page' ) ),
				'post_status'    => $statuses,
				'posts_per_page' => - 1,
				'orderby'        => $order_by,
				'order'          => $order,
				'meta_key'       => "_e20r_sequence_{$sequence_id}_post_delay",
				'meta_query'     => array(
					array(
						'key'     => '_e20r_sequence_post_belongs_to',
						'value'   => $sequence_id,
						'compare' => '=',
					),
				),
			);
		} else {
			
			$utils->log( "Post ID specified so we'll only search for post #{$post_id}" );
			
			$seq_args = array(
				'post_type'      => apply_filters( 'e20r-sequence-managed-post-types', array( 'post', 'page' ) ),
				'post_status'    => $statuses,
				'posts_per_page' => - 1,
				'order_by'       => $order_by,
				'p'              => $post_id,
				'order'          => $order,
				'meta_key'       => "_e20r_sequence_{$sequence_id}_post_delay",
				'meta_query'     => array(
					array(
						'key'     => '_e20r_sequence_post_belongs_to',
						'value'   => $sequence_id,
						'compare' => '=',
					),
				),
			);
		}
		
		if ( ! is_null( $pagesize ) ) {
			
			$utils->log( "Enable paging, grab page #: " . get_query_var( 'page' ) );
			
			$page_num = ( get_query_var( 'page' ) ) ? absint( get_query_var( 'page' ) ) : 1;
		}
		
		if ( $find_by_delay ) {
			$utils->log( "Requested look-up by delay value {$delay} in sequence {$sequence_id}" );
			$seq_args['meta_query'][] = array(
				'key'     => "_e20r_sequence_{$sequence_id}_post_delay",
				'value'   => $delay,
				'compare' => $comparison,
				'type'    => $data_type,
			);
		}
		
		// $utils->log("Args for \WP_Query(): ");
		// $utils->log($args);
		
		if ( empty( $found ) ) {
			
			$utils->log( "Having to load from database" );
			
			$found = array();
			$posts = new \WP_Query( $seq_args );
			
			$utils->log( "Loaded {$posts->post_count} posts from wordpress database for sequence {$sequence_id}" );
			
			/*        if ( ( 0 === $posts->post_count ) && is_null( $pagesize ) && ( is_null( $post_id ) ) && false === $conv_sequence ) {
	
				$utils->log("Didn't find any posts. Checking if we need to convert...?");
	
				if ( !$this->is_converted( $sequence_id ) ) {
	
					$utils->log("Forcing conversion attempt for sequence # {$sequence_id}");
					$this->convert_posts_to_v3( $sequence_id, true );
				}
			}
	*/
			$is_admin = user_can( $user_id, 'manage_options' );
			
			$member_days = ( ( $is_admin && $controller->show_all_for_admin() ) || ( is_admin() && $controller->is_cron == false && $controller->show_all_for_admin() ) ) ? 9999 : $controller->get_membership_days( $user_id );
			
			$utils->log( "User {$user_id} has been a member for {$member_days} days. Admin? " . ( $is_admin ? 'Yes' : 'No' ) );
			
			$post_list = $posts->get_posts();
			
			wp_reset_postdata();
			
			foreach ( $post_list as $post_key => $s_post ) {
				
				$utils->log( "Loading metadata for post #: {$s_post->ID}" );
				
				$s_post_id = $s_post->ID;
				
				$tmp_delay = get_post_meta( $s_post_id, "_e20r_sequence_{$sequence_id}_post_delay" );
				$v_delays  = get_post_meta( $s_post_id, "_e20r_sequence_{$sequence_id}_visibility_delay" );
				
				$is_repeat = false;
				
				// Add posts for all delay values with this post_id
				foreach ( $tmp_delay as $delay_key => $p_delay ) {
					
					$new_post = new \stdClass();
					
					$new_post->id = $s_post_id;
					// BUG: Doesn't work because you could have multiple post_ids released on same day: $p->order_num = $this->normalize_delay( $p_delay );
					$new_post->delay            = isset( $s_post->delay ) ? $s_post->delay : $p_delay;
					$new_post->permalink        = get_permalink( $s_post->ID );
					$new_post->title            = $s_post->post_title;
					$new_post->excerpt          = $s_post->post_excerpt;
					$new_post->closest_post     = false;
					$new_post->current_post     = false;
					$new_post->is_future        = false;
					$new_post->list_include     = true;
					$new_post->visibility_delay = isset( $v_delays[ $delay_key ] ) ? $v_delays[ $delay_key ] : null;
					$new_post->type             = $s_post->post_type;
					
					// Only add posts to list if the member is supposed to see them
					if ( $member_days >= $controller->normalize_delay( $new_post->delay ) ) {
						
						$utils->log( "Adding {$new_post->id} ({$new_post->title}) with delay {$new_post->delay} to list of available posts" );
						$new_post->is_future = false;
						$found[]             = $new_post;
					} else {
						
						// Or if we're not supposed to hide the upcoming posts.
						
						if ( false === $controller->hide_upcoming_posts() || is_admin() ) {
							
							$utils->log( "Loading {$new_post->id} with delay {$new_post->delay} to list of upcoming posts. User is administrator level? " . ( $is_admin ? 'true' : 'false' ) );
							$new_post->is_future = true;
							$found[]             = $new_post;
						} else {
							
							$utils->log( "Ignoring post {$new_post->id} with delay {$new_post->delay} to sequence list for {$sequence_id}. User is administrator level? " . ( $is_admin ? 'true' : 'false' ) );
							if ( ! is_null( $pagesize ) ) {
								
								$post_list[ $post_key ]->list_include = false;
								$post_list[ $post_key ]->is_future    = true;
								//unset( $post_list[ $post_key ] );
							}
							
						}
					}
				} // End of foreach for delay values
				
				$is_repeat = false;
			} // End of foreach for post_list
		}
		
		$utils->log( "Found " . count( $found ) . " posts for sequence {$sequence_id} and user {$user_id}" );
		
		if ( empty( $post_id ) && empty( $delay ) /* && ! empty( $post_list ) */ ) {
			
			$utils->log( "Preparing array of posts to return to calling function" );
			
			$this->posts = $found;
			
			// Default to old _sequence_posts data
			if ( 0 == count( $this->posts ) ) {
				
				$utils->log( "No posts found using the V3 meta format. Need to convert! " );

//                $tmp = get_post_meta( $controller->sequence_id, "_sequence_posts", true );
//                $this->posts = ( $tmp ? $tmp : array() ); // Fixed issue where empty sequences would generate error messages.
				
				/*                $utils->log("Saving to new V3 format... ", E20R_DEBUG_SEQ_WARNING );
				$this->save_sequence_post();

				$utils->log("Removing old format meta... ", E20R_DEBUG_SEQ_WARNING );
				delete_post_meta( $controller->sequence_id, "_sequence_posts" );
*/
			}
			
			$utils->log( "Identify the closest post for {$user_id}" );
			
			$this->posts = $controller->set_closest_post( $this->posts, $user_id );
			
			$utils->log( "Have " . count( $this->posts ) . " posts we're sorting" );
			usort( $this->posts, array( $controller, "sort_posts_by_delay" ) );
			/*
			$utils->log("Have " . count( $this>upcoming )  ." upcoming/future posts we need to sort");
			if (!empty( $this>upcoming ) ) {

				usort( $this>upcoming, array( $this, "sortPostsByDelay" ) );
			}
*/
			
			$utils->log( "Will return " . count( $this->posts ) . " sequence members and refreshing cache for {$sequence_id}" );
			
			if ( is_null( $pagesize ) ) {
				
				$utils->log( "Returning non-paginated list" );
				$controller->set_cache( $this->posts, $sequence_id );
				
				return $this->posts;
			} else {
				
				$utils->log( "Preparing paginated list after updating cache for {$sequence_id}" );
				$controller->set_cache( $this->posts, $sequence_id );
				
				if ( ! empty( $this->upcoming ) ) {
					
					$utils->log( "Appending the upcoming array to the post array. posts =  " . count( $this->posts ) . " and upcoming = " . count( $this->upcoming ) );
					$this->posts = array_combine( $this->posts, $this->upcoming );
					
					$utils->log( "Joined array contains " . count( $this->posts ) . " total posts" );
				}
				
				$paged_list = $this->paginate_posts( $this->posts, $pagesize, $page_num );
				
				// Special processing since we're paginating.
				// Make sure the $delay value is > first element's delay in $page_list and < last element
				
				list( $minimum, $maximum ) = $this->set_min_max( $pagesize, $page_num, $paged_list );
				
				$utils->log( "Check max / min delay values for paginated set. Max: {$maximum}, Min: {$minimum}" );
				
				$max_pages  = ceil( count( $this->posts ) / $pagesize );
				$post_count = count( $paged_list );
				
				$utils->log( "Returning the WP_Query result to process for pagination. Max # of pages: {$max_pages}, total posts {$post_count}" );
				
				return array( $paged_list, $max_pages );
			}
			
		} else {
			$utils->log( "Returning list of posts (size: " . count( $found ) . " ) located by specific post_id: {$post_id}" );
			
			return $found;
		}
	}
	
	/**
	 * Get the first and last post to include on the page during pagination
	 *
	 * @param $pagesize  - The size of the page
	 * @param $page_num  - The order of the page
	 * @param $post_list - The posts to paginate
	 *
	 * @return array - Array consisting of the max and min delay value for the post(s) on the page.
	 */
	private function set_min_max( $pagesize, $page_num, $post_list ) {
		
		$controller = Controller::get_instance();
		$utils = Utilities::get_instance();
		
		$options = $controller->get_options();
		
		/**
		 * Doesn't account for sort order.
		 * @since 4.4.1
		 */
		
		/**
		 * Didn't account for pages < pagesize.
		 * @since 4.4
		 */
		
		if ( $options->sortOrder == SORT_DESC ) {
			$min_key = 0;
			$max_key = ( count( $post_list ) >= $pagesize ) ? $pagesize - 1 : count( $post_list ) - 1;
		}
		
		if ( $options->sortOrder == SORT_ASC ) {
			$min_key = ( count( $post_list ) >= $pagesize ) ? $pagesize - 1 : count( $post_list ) - 1;
			$max_key = 0;
		}
		
		$utils->log( "Max key: {$max_key} and min key: {$min_key}" );
		
		$min_val = $post_list[ $max_key ]->delay;
		$max_val = $post_list[ $min_key ]->delay;
		
		$utils->log( "Gives min/max values: Min: {$min_val}, Max: {$max_val}" );
		
		return array( $min_val, $max_val );
		
	}
	
	/**
	 * Pagination logic for posts.
	 *
	 * @param $post_list    - Posts to paginate
	 * @param $page_size    - Size of page (# of posts per page).
	 * @param $current_page - The current page number
	 *
	 * @return array - Array of posts to include in the current page.
	 */
	private function paginate_posts( $post_list, $page_size, $current_page ) {
		
		$page  = array();
		$utils = Utilities::get_instance();
		
		$last_key  = ( $page_size * $current_page ) - 1;
		$first_key = $page_size * ( $current_page - 1 );
		
		foreach ( $post_list as $k => $post ) {
			
			// skip if we've already marked this post for exclusion.
			if ( false === $post->list_include ) {
				
				continue;
			}
			
			if ( ! ( ( $k <= $last_key ) && ( $k >= $first_key ) ) ) {
				$utils->log( "Excluding {$post->id} with delay {$post->delay} from post/page/list" );
				// $page[] = $post;
				$post_list[ $k ]->list_include = false;
			}
		}
		
		return $post_list;
		
	}
	
	/**
	 * Returning the instance
	 *
	 * @return Model
	 *
	 * @since v5.0
	 */
	public static function get_instance() {
		
		if ( is_null( self::$instance ) ) {
			self::$instance = new self;
		}
		
		return self::$instance;
	}
}
