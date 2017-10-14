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

use E20R\Utilities\Utilities;
use E20R\Utilities\Cache;
use E20R\Sequences\Sequence\Controller;

class Model {
	
	private static $instance = null;
	
	private function __construct() {
	}
	
	/**
	 * Check if the conversion process to e20r_sequence as the post type has been completed?
	 *
	 * @return bool|null
	 */
	public static function uses_new_post_type() {
		
		if ( null === ( $uses_new = Cache::get( 'using_new_type', Controller::cache_key ) ) ) {
			
			$query = array(
				'post_type'  => 'e20r_sequence',
				'post_limit' => 1,
			);
			
			$found = new \WP_Query( $query );
			
			$uses_new = $found->have_posts();
			
			if ( ! is_null( $uses_new ) ) {
				Cache::set( 'using_new_type', $uses_new, HOUR_IN_SECONDS, Controller::cache_key );
			}
		}
		
		return $uses_new;
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
		$post_type    = 'e20r_sequence';
		
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
		
		$utils = Utilities::get_instance();
		$utils->log( "Process {$seq_id} for V3 upgrade?" );
		
		if ( ( true === version_compare( E20R_SEQUENCE_VERSION, '3.0.0', '<=' ) ) && false === $this->is_converted( $seq_id ) ) {
			
			$utils->log( "Need to convert sequence #{$seq_id} to V3 format" );
			$this->get_options( $seq_id );
			
			if ( $this->convert_posts_to_v3( $seq_id, true ) ) {
				$utils->log( "Converted {$seq_id} to V3 format" );
				$this->convert_user_notifications( $seq_id );
				$utils->log( "Converted {$seq_id} user notifications to V3 format" );
			} else {
				$utils->log( "Error during conversion of {$seq_id} to V3 format" );
			}
		} else if ( true === version_compare( E20R_SEQUENCE_VERSION, '3.0.0', '>' ) && false === $this->is_converted( $seq_id ) ) {
			$utils->log( "Sequence id# {$this->sequence_id} doesn't need to be converted to v3 metadata format" );
			$this->current_metadata_versions[ $this->sequence_id ] = 3;
			update_option( "pmpro_sequence_metadata_version", $this->current_metadata_versions );
		}
	}
	
	/**
	 * Converts the posts for a sequence to the V3 metadata format (settings)
	 *
	 * @param null $sequence_id - The ID of the sequence to convert.
	 * @param bool $force       - Whether to force the conversion or not.
	 *
	 * @return bool - Whether the conversion successfully completed or not
	 */
	public function convert_posts_to_v3( $sequence_id = null, $force = false ) {
		
		global $conv_sequence;
		
		$conv_sequence = true;
		$utils         = Utilities::get_instance();
		$old_seq_id    = null;
		
		if ( ! is_null( $sequence_id ) ) {
			
			if ( isset( $this->current_metadata_versions[ $sequence_id ] ) && ( 3 >= $this->current_metadata_versions[ $sequence_id ] ) ) {
				
				$utils->log( "Sequence {$sequence_id} is already converted." );
				
				return;
			}
			
			$old_seq_id = $this->sequence_id;
			/**
			 * if ( false === $force  ) {
			 *
			 * $utils->log("Loading posts for {$sequence_id}.");
			 * $this->get_options( $sequence_id );
			 * $this->load_sequence_post();
			 * }
			 */
		}
		
		$is_pre_v3 = get_post_meta( $this->sequence_id, "_sequence_posts", true );
		
		$utils->log( "Need to convert from old metadata format to new format for sequence {$this->sequence_id}" );
		$retval = true;
		
		if ( ! empty( $this->sequence_id ) ) {
			
			// $tmp = get_post_meta( $sequence_id, "_sequence_posts", true );
			$posts = ( ! empty( $is_pre_v3 ) ? $is_pre_v3 : array() ); // Fixed issue where empty sequences would generate error messages.
			
			foreach ( $posts as $seq_post ) {
				
				$utils->log( "Adding post #{$seq_post->id} with delay {$seq_post->delay} to sequence {$this->sequence->id} " );
				
				$added_to_seq = false;
				$s_list       = get_post_meta( $seq_post->id, "_pmpro_sequence_post_belongs_to" );
				
				if ( false == $s_list || ! in_array( $sequence_id, $s_list ) ) {
					add_post_meta( $seq_post->id, '_pmpro_sequence_post_belongs_to', $sequence_id );
					$added_to_seq = true;
				}
				
				if ( true === $this->allow_repetition() && true === $added_to_seq ) {
					add_post_meta( $seq_post->id, "_pmpro_sequence_{$sequence_id}_post_delay", $seq_post->delay );
					
				} else if ( false == $this->allow_repetition() && true === $added_to_seq ) {
					
					update_post_meta( $seq_post->id, "_pmpro_sequence_{$sequence_id}_post_delay", $seq_post->delay );
				}
				
				if ( ( false !== get_post_meta( $seq_post->id, '_pmpro_sequence_post_belongs_to', true ) ) &&
				     ( false !== get_post_meta( $seq_post->id, "_pmpro_sequence_{$sequence_id}_post_delay", true ) )
				) {
					$utils->log( "Edited metadata for migrated post {$seq_post->id} and delay {$seq_post->delay}" );
					$retval = true;
					
				} else {
					delete_post_meta( $seq_post->id, '_pmpro_sequence_post_belongs_to', $this->sequence_id );
					delete_post_meta( $seq_post->id, "_pmpro_sequence_{$this->sequence_id}_post_delay", $seq_post->delay );
					$retval = false;
				}
				
				// $retval = $retval && $this->add_post_to_sequence( $this->sequence_id, $seq_post->id, $seq_post->delay );
			}
			
			// $utils->log("Saving to new V3 format... " );
			// $retval = $retval && $this->save_sequence_post();
			
			$utils->log( "(Not) Removing old format meta... " );
			// $retval = $retval && delete_post_meta( $this->sequence_id, "_sequence_posts" );
		} else {
			
			$retval = false;
			$this->set_error_msg( __( "Cannot convert to V3 metadata format: No sequences were defined.", Controller::plugin_slug ) );
		}
		
		if ( $retval == true ) {
			
			$utils->log( "Converted sequence id# {$this->sequence_id} to v3 metadata format for all sequence member posts" );
			$this->current_metadata_versions[ $this->sequence_id ] = 3;
			update_option( "pmpro_sequence_metadata_version", $this->current_metadata_versions );
			
			// Reset sequence info.
			$this->get_options( $old_seq_id );
			$this->load_sequence_post( null, null, null, '=', null, true );
			
		} else {
			$this->set_error_msg( sprintf( __( "Unable to upgrade post metadata for sequence (%s)", Controller::plugin_slug ), get_the_title( $this->sequence_id ) ) );
		}
		
		$conv_sequence = false;
		
		return $retval;
	}
	
	/**
	 * Convert any pmpro_sequence CPT post types to e20r_sequence
	 */
	public static function convert_post_type() {
		
		global $wpdb;
		
		$wpdb->update( $wpdb->posts, array( 'post_type' => 'e20r_sequence' ), array( 'post_type' => 'pmpro_sequence' ), array( '%s' ), array( '%s' ) );
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
			'post_type'      => 'e20r_sequence',
			'post_status'    => apply_filters( 'e20r-sequence-allowed-post-statuses', array(
				'publish',
				'future',
				'private',
			) ),
			'posts_per_page' => - 1,
		);
		
		
		$sequence_list = new \WP_Query( $query );
		$utils         = Utilities::get_instance();
		
		$utils->log( "Found " . count( $sequence_list ) . " sequences to process for alert conversion" );
		
		while ( $sequence_list->have_posts() ) {
			
			$sequence_list->the_post();
			$sequence_id = get_the_ID();
			
			if ( is_null( $f_seq_id ) || ( $f_seq_id == $sequence_id ) ) {
				
				$this->get_options( $sequence_id );
				
				$users = $this->get_users_of_sequence();
				
				foreach ( $users as $wp_user ) {
					
					$this->e20r_sequence_user_id = $wp_user->user_id;
					$user_settings               = get_user_meta( $wp_user->user_id, "pmpro_sequence_id_{$sequence_id}_notices", true );
					
					// No V3 formatted settings found. Will convert from V2 (if available)
					if ( empty( $user_settings ) || ( ! isset( $user_settings->send_notices ) ) ) {
						
						$utils->log( "Converting notification settings for user with ID: {$wp_user->user_id}" );
						$utils->log( "Loading V2 meta: {$wpdb->prefix}pmpro_sequence_notices for user ID: {$wp_user->user_id}" );
						
						$v2_meta = get_user_meta( $wp_user->user_id, "{$wpdb->prefix}" . "pmpro_sequence_notices", true );
						
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
									
									$this->save_user_notice_settings( $wp_user->user_id, $user_settings, $f_seq_id );
									$utils->log( " Removing converted opt-in settings from the database" );
									delete_user_meta( $wp_user->user_id, $wpdb->prefix . "pmpro_sequence_notices" );
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
						
						if ( ! $this->save_user_notice_settings( $wp_user->user_id, $user_settings, $this->sequence_id ) ) {
							
							$utils->log( "convert_user_notification() - Unable to save new notification settings for user with ID {$wp_user->user_id}" );
						}
					} else {
						$utils->log( "convert_user_notification() - No alert settings to convert for {$wp_user->user_id}" );
						$utils->log( "convert_user_notification() - Checking existing V3 settings..." );
						
						$member_days = $this->get_membership_days( $wp_user->user_id );
						
						$old = $this->posts;
						
						$compare       = $this->load_sequence_post( $sequence_id, $member_days, null, '<=', null, true );
						$user_settings = $this->fix_user_alert_settings( $user_settings, $compare, $member_days );
						$this->save_user_notice_settings( $wp_user->user_id, $user_settings, $sequence_id );
						
						$this->posts = $old;
					}
				}
				
				if ( isset( $user->user_id ) && ! $this->remove_old_user_alert_setting( $wp_user->user_id ) ) {
					
					$utils->log( "Unable to remove old user_alert settings!" );
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
		
		$utils       = Utilities::get_instance();
		$v3_settings = $this->create_user_notice_defaults();
		
		$v3_settings->id = $f_seq_id;
		
		$member_days = $this->get_membership_days( $user_id );
		$this->get_options( $f_seq_id );
		
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
				$posts = $this->find_by_id( $post_id );
				
				foreach ( $posts as $p ) {
					
					$flag_value = "{$p->id}_" . $this->normalize_delay( $p->delay );
					
					if ( ( $p->id == $post_id ) && ( $this->normalize_delay( $p->delay ) <= $member_days ) ) {
						
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
		$v3_settings = $this->fix_user_alert_settings( $v3_settings, $compare, $member_days );
		
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
		
		$v2_meta = get_post_meta( $user_id, $wpdb->prefix . "pmpro_sequence_notices", true );
		
		if ( ! empty( $v2_meta ) ) {
			
			return delete_user_meta( $user_id, $wpdb->prefix . "pmpro_sequence_notices" );
		} else {
			// No v2 meta found..
			return true;
		}
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