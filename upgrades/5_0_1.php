<?php
use E20R\Utilities\Utilities;
use E20R\Sequences\Sequence\Controller;

/**
 * Rename all pmpro_sequence* options to e20r_sequence*
 */
function e20r_sequence_upgrade_settings_501()  {
	
	global $wpdb;
	$utils = Utilities::get_instance();
	$meta_keys = array();
	
	// Rename post meta ( _pmpro_sequence_post_belongs_to -> _e20r_sequence_post_belongs_to )
	if ( false === $wpdb->update(
		$wpdb->postmeta,
		array( 'meta_key' => '_e20r_sequence_post_belongs_to' ),
		array( 'meta_key' => '_pmpro_sequence_post_belongs_to'),
		array( '%s' ),
		array( '%s' )
	) ) {
		$utils->add_message( __("Unable to update E20R Sequences metadata!", Controller::plugin_slug ), 'error', 'backend' );
		return false;
	}
	
	$meta_keys = array(
		'_pmpro_sequence_%%_post_delay',
		'_pmpro_sequence_%%_visibility_delay',
		"{$wpdb->prefix}pmpro_sequence_notices",
		"pmpro_sequence_id_%%_notices",
		'_pmpro_sequence_settings',
	);
	
	foreach( $meta_keys as $meta_key ) {
		
		$meta_sql = $wpdb->prepare(
			"SELECT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",$meta_key
		);
		
		$old_meta_info = $wpdb->get_col( $meta_sql );
		
		if ( !empty( $old_meta_info ) ) {
			
			// Process all metadata columns and update them
			foreach( $old_meta_info as $old_meta ) {
				
				$utils->log("Updating info for {$old_meta}");
				
				$new_meta_key = preg_replace( '/pmpro_sequence_/', 'e20r_sequence_', $old_meta );
				
				$result = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s", $new_meta_key, $old_meta
					)
				);
				
				if ( false === $result ) {
					$utils->log( sprintf( 'Error: Unable to update meta key(s) for %s', $old_meta ) );
				}
			}
		}
	}
}
add_action('e20r_sequence_update_5.0.1', 'e20r_sequence_upgrade_settings_501');
