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

namespace E20R\Sequences\Modules\Licensed\Export;

use E20R\Utilities\Licensing\Licensing;
use E20R\Sequences\Sequence\Controller;

class WP_All_Export {
	
	/**
	 * @var null|WP_All_Export
	 */
	private static $instance = null;
	
	private $addon;
	
	public function load_hooks() {
		
		if ( false === Licensing::is_licensed( Controller::plugin_prefix ) ) {
			return;
		} else {
			
			include_once( 'rapid-addon.php' );
			
			$this->addon = new \RapidAddon( __( "E20R Sequences Drip Feed Content", Controller::plugin_slug ), Controller::plugin_slug );
		}
	}
	/**
	 * Return the class instance (uses singleton pattern)
	 *
	 * @return WP_All_Export|null
	 */
	public static function get_instance() {
		
		
		if ( is_null( self::$instance ) ) {
			self::$instance = new self;
			self::$instance->load_hooks();
		}
		
		return self::$instance;
	}
}
