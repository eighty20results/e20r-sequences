<?php
/*
  License:

	Copyright 2014-2016 Eighty / 20 Results by Wicked Strong Chicks, LLC (thomas@eighty20results.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

*/

namespace E20R\Sequences\Tools;

use E20R\Utilities\Utilities;

class Sequence_Updates
{
    private $current_version;
    private static $instance = null;

    public function __construct()
    {
    	$utils = Utilities::get_instance();
        $utils->log("Loading the sequence_update class");

        if ( ! is_null( self::$instance ) ) {

            $utils->log("Error loading the sequence update class");
            $error_message = sprintf(__("Attempted to load a second instance of this singleton class (%s)", "e20r-sequences"),
                get_class($this)
            );

            $utils->log($error_message);
            wp_die( $error_message);

        }

        self::$instance = $this;
    }
	
	
	/**
	 * Generate list of version(s) to upgrade the DB for
	 *
	 * @return array
	 */
    public static function load_upgrade_versions() {
    	
    	$versions = array();
    	$utils = Utilities::get_instance();
    	
    	$location = E20R_SEQUENCE_PLUGIN_DIR . "/upgrades/";
    	
    	if ( file_exists( $location ) ) {
		    
    		if ( false !== ( $files = scandir( $location ) ) ) {
			
			    foreach ( $files as $file ) {
				
				    // Skip (ignore) as add-ons to process/list
				    if ( '.' === $file || '..' === $file ) {
					    continue;
				    }
				
				    $parts      = explode( '.', $file );
				    $filename = array_shift( $parts );
				    $version = preg_replace( '/_/', '.', $filename );
				
				    if ( ! in_array( $version, $versions ) ) {
					
					    $utils->log( "Added {$version} to list of items to include in upgrade" );
					    $versions[] = $version;
				    }
			    }
		    }
	    }
	    
	    if ( !empty( $versions ) ) {
    		sort($versions);
	    }
	    
    	return $versions;
    }
	
	/**
	 * Configure and start upgrade functionality
	 */
    public static function init() {
    	
	    $utils = Utilities::get_instance();
        $utils->log("Getting plugin data for E20R Sequences");

        $update_functions = self::load_upgrade_versions();
	    
        $utils->log("Can upgrade for: " . print_r( $update_functions, true ) );
        
        if (function_exists('get_plugin_data')) {
	        $plugin_status = get_plugin_data( E20R_SEQUENCE_PLUGIN_DIR . 'class.e20r-sequences.php', false, false );
        }
        
        $utils->log("Current plugin version is: {$plugin_status['Version']}");
        $version = ( !empty($plugin_status['Version']) ? $plugin_status['Version'] : E20R_SEQUENCE_VERSION );

        $class = self::get_instance();
	    $class->set_version($version);

        $already_updated = get_option( 'e20r-sequence-updated', array() );

        foreach( $update_functions as $vconsider ) {
        
        	$utils->log("Processing possible upgrade for {$vconsider}");
            if ( 1 === version_compare( $version,$vconsider, 'ge' ) && false === in_array( $vconsider, array_keys($already_updated ) )) {
	            
            	$utils->log("Permit running update functionality for v{$vconsider}");
	
	            $upgrade_file = str_replace('.', '_', $vconsider );
	
	            if (!empty($upgrade_file) &&
	                (file_exists(E20R_SEQUENCE_PLUGIN_DIR . "upgrades/{$upgrade_file}.php") &&
	                 (!isset( $already_updated[$vconsider] ) || false === $already_updated[$vconsider] )  ) ) {
		
		            $utils->log("Adding update actions for file: {$upgrade_file}");
		            
		            add_action("e20r_sequence_before_update_{$upgrade_file}", array(self::get_instance(), 'e20r_sequence_before_update'));
		            add_action("e20r_sequence_update_{$upgrade_file}", array(self::get_instance(), 'e20r_sequence_update'));
		            add_action("e20r_sequence_after_update_{$upgrade_file}", array(self::get_instance(), 'e20r_sequence_after_update'));
		            
		            $already_updated[$vconsider] = true;
	            }
            }
        }

        update_option('e20r-sequence-updated', $already_updated, 'no');
    }
	
	/**
	 * Return the version we're processing
	 *
	 * @return null|string
	 */
    public function get_version() {
        return isset( $this->current_version ) ? $this->current_version : null;
    }
	
	/**
	 * Save the version we're processing
	 *
	 * @param string|null $version
	 */
    public function set_version( $version ) {
        $this->current_version = $version;
    }
	
	/**
	 * Get or instantiate the Sequence_Updates class (DB upgrade handler)
	 *
	 * @return Sequence_Updates|null
	 */
    public static function get_instance() {
    	
        if ( is_null( self::$instance ) ) {
        	
            self::$instance = new self;
        }

        return self::$instance;
    }
	
	/**
	 * Run database updater
	 */
    static public function update() {
    	
	    $utils = Utilities::get_instance();
        $version = self::get_instance()->get_version();

        if (empty($version)) {
            return;
        }

        $is_updated = get_option('e20r-sequence-updated', array() );
        
        if (!array_key_exists( $version, $is_updated )) {

            $utils->log("Appending {$version} to list of stuff to upgrade");
            $is_updated[$version] = false;
        }

        foreach ($is_updated as $update_ver => $status ) {

            $upgrade_file = str_replace('.', '_', $update_ver);

            if (file_exists(E20R_SEQUENCE_PLUGIN_DIR . "upgrades/{$upgrade_file}.php") && (false == $status)  ) {

                require_once(E20R_SEQUENCE_PLUGIN_DIR . "upgrades/{$upgrade_file}.php");

                $utils->log("Running pre (before) update action for {$update_ver}");
                do_action("e20r_sequence_before_update_{$update_ver}");

                // FIXME: Will always run, every time the plugin loads (prevent this!)
                $utils->log("Running update action for {$update_ver}");
                do_action("e20r_sequence_update_{$update_ver}");

                $utils->log("Running clean-up (after) update action for {$update_ver}");
                do_action("e20r_sequence_after_update_{$update_ver}");
                $is_updated[$update_ver] = true;
                
            }
        }

        update_option('e20r-sequence-updated', $is_updated, 'no');
    }

    /**
     * Stub function
     */
    public function e20r_sequence_before_update() {
        return;
    }

    /**
     * Stub function
     */
    public function e20r_sequence_after_update() {
        return;
    }

    /**
     * Handler to update
     */
    public function e20r_sequence_update() {
        return;
    }
}
