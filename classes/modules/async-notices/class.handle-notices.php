<?php
/**
 * Copyright 2014-2017 Eighty / 20 Results by Wicked Strong Chicks, LLC (thomas@eighty20results.com)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2, as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

namespace E20R\Sequences\Modules\Async_Notices;

use E20R\Sequences\Utilities\Utilities;

class Handle_Notices extends E20R_Background_Process {

	private static $instance = null;
	
	/**
	 * Constructor for Handle_Subscriptions class
	 *
	 * @param object $calling_class
	 */
	public function __construct( $calling_class ) {
		
		$util = Utilities::get_instance();
		$util->log("Instantiated Handle_Notices class");
		
		self::$instance = $this;
		
		$av = get_class( $calling_class );
		$name = explode( '\\', $av );
		$this->action = "seq_" . strtolower( $name[(count( $name ) - 1 )] );
		
		$util->log("Set Action variable to {$this->action} for Handle_Notices");
		
		// Required: Run the parent class constructor
		parent::__construct();
	}
	/**
	 * @param \WP_User $user
	 * @param Sequence_Controller
	 *
	 * @return mixed
	 */
	public function task( $user, $sequence = null ) {
		
		$util = Utilities::get_instance();
		
		// DO the work on a per-user basis
		
		// Remove the current entry/task from the task list
		return false;
	}

	/**
	 *
	 */
	public function complete() {
		
		parent::complete();
		
		// Show notice to user or perform some other arbitrary task...
		$util = Utilities::get_instance();
		$util->log("Completed sending notice(s) for all users/sequences");
		
	}
}