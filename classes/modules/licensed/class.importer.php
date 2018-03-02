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

namespace E20R\Sequences\Modules\Licensed\Import;

use E20R\Utilities\Licensing\Licensing;
use E20R\Utilities\Utilities;
use E20R\Sequences\Data\Model;
use E20R\Sequences\Sequence\Controller;

if ( ! class_exists( 'E20R\Sequences\Modules\Licensed\Import\Importer' ) ) {
	class Importer {
		
		// TODO: Let user/admin trigger import of PMPro Series from Tools page in wp-admin
		public static function tools_menu_entry() {
		
		}
		
		public static function menu_view() {
		
		}
		
		/**
		 * Import PMPro Series as specified by the e20r-sequence-import-pmpro-series filter
		 */
		public static function import_all_series() {
			
			$utils = Utilities::get_instance();
			
			$import_status = apply_filters( 'e20r-sequence-import-pmpro-series', __return_false() );
			
			// Don't import anything.
			if ( false !== $import_status && false === Licensing::is_licensed( Controller::plugin_prefix ) ) {
				
				$utils->add_message(
					sprintf(
						__( '%1$sE20R Sequences - Plus Edition%2$s is required to import from PMPro Series', Controller::plugin_slug ),
						'<a href="https://eighty20results.com/shop/licenses/e20r-sequences-plus-edition/">', '</a>'
						
						), 'error', 'backend' );
				return $import_status;
			}
			
			global $wpdb;
			
			if ( ( true === $import_status ) || ( 'all' === $import_status ) ) {
				
				//Get all of the defined PMPro Series posts to import from this site.
				$series_sql = "
                        SELECT *
                        FROM {$wpdb->posts}
                        WHERE post_type = 'pmpro_series'
                    ";
			} else if ( is_array( $import_status ) ) {
				
				//Get the specified list of PMPro Series posts to import
				$series_sql = "
                        SELECT *
                        FROM {$wpdb->posts}
                        WHERE post_type = 'pmpro_series'
                        AND ID IN (" . implode( ",", $import_status ) . ")
                    ";
			} else if ( is_numeric( $import_status ) ) {
				
				//Get the specified (by Post ID, we assume) PMPro Series posts to import
				$series_sql = $wpdb->prepare(
					"
                        SELECT *
                        FROM {$wpdb->posts}
                        WHERE post_type = 'pmpro_series'
                        AND ID = %d
                    ",
					$import_status
				);
			}
			
			if ( !empty( $series_sql ) ) {
				$series_list = $wpdb->get_results( $series_sql );
				
				// Series meta: '_post_series' => the series this post belongs to.
				//              '_series_posts' => the posts in the series
				/*
						$format = array(
							'%s', '%s', '%s', '%s', '%s', '%s', '%s','%s','%s','%s',
							'%s', '%s', '%s', '%s', '%s', '%s', '%d','%s','%d','%s',
							'%s', '%d'
						);
				*/
				// Process the list of sequences
				foreach ( $series_list as $series ) {
					
					$wp_error = true;
					
					$seq_id = wp_insert_post( array(
						'post_author'           => $series->post_author,
						'post_date'             => date_i18n( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
						'post_date_gmt'         => date_i18n( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
						'post_content'          => $series->post_content,
						'post_title'            => $series->post_title,
						'post_excerpt'          => $series->post_excerpt,
						'post_status'           => $series->post_status,
						'comment_status'        => $series->comment_status,
						'ping_status'           => $series->ping_status,
						'post_password'         => $series->post_password,
						'post_name'             => $series->post_name,
						'to_ping'               => $series->to_ping,
						'pinged'                => $series->pinged,
						'post_modified'         => $series->post_modified,
						'post_modified_gmt'     => $series->post_modified_gmt,
						'post_content_filtered' => $series->post_content_filtered,
						'post_parent'           => $series->post_parent,
						'guid'                  => $series->guid,
						'menu_order'            => $series->menu_order,
						'post_type'             => Model::cpt_type,
						'post_mime_type'        => $series->post_mime_type,
						'comment_count'         => $series->comment_count,
					),
						$wp_error );
					
					if ( ! is_wp_error( $seq_id ) ) {
						
						$post_list = get_post_meta( $series->ID, '_series_posts', true );
						
						$sequence = Controller::get_instance();
						$model = Model::get_instance();
						
						$sequence->init( $seq_id );
						
						foreach ( $post_list as $seq_member ) {
							
							if ( ! $model->add_post( $seq_member->id, $seq_member->delay, null ) ) {
								return new \WP_Error( 'sequence_import',
									sprintf( __( 'Could not complete import of post id %d for PMPro series "%s"', "e20r-sequences" ), $seq_member->id, $series->post_title ), $sequence->get_error_msg() );
							}
						} // End of foreach
						
						// Save the settings for this Drip Feed Sequence
						$sequence->save_sequence_meta();
						
						// update_post_meta( $seq_id, "_sequence_posts", $post_list );
					} else {
						
						return new \WP_Error( 'db_query_error',
							sprintf( __( 'Could not complete import for PMPro Series "%s"', "e20r-sequences" ), $series->post_title ), $wpdb->last_error );
						
					}
				} // End of foreach (DB result)
			}
		}
	} // End of Importer class definition
}
