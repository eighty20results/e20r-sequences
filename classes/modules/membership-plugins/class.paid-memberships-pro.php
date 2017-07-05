<?php
/**
 * Copyright (c) $today.year. - Eighty / 20 Results by Wicked Strong Chicks.
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

namespace E20R\Sequences\Modules\Membership_Plugins;


class Paid_Memberships_Pro {
	
	/**
	 * @var null|Paid_Memberships_Pro
	 */
	private static $instance = null;
	
	/**
	 * Load any action hooks and filter hooks
	 *
	 * @access private
	 */
	private function load_hooks() {
		
		// TODO: Implement any hook handler(s)
	}
	
	/**
	 * Returns an instance of the class (Singleton pattern)
	 *
	 * @return Paid_Memberships_Pro
	 */
	public static function get_instance() {
		
		if ( is_null( self::$instance ) ) {
			
			self::$instance = new self;
			
			self::$instance->load_hooks();
		}
		
		return self::$instance;
	}
}