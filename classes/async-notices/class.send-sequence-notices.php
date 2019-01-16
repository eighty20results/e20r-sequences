<?php
/**
 *  Copyright (c) 2014-2019. - Eighty / 20 Results by Wicked Strong Chicks.
 *  ALL RIGHTS RESERVED
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *   You should have received a copy of the GNU General Public License
 *   along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace E20R\Sequences\Async_Notices;

class Send_Sequence_Notices extends E20R_Background_Process {

	/**
	 * @var string The action to perform
	 */
	protected $action = 'send_sequence_notice';

	/**
	 * @param mixed $item
	 *
	 * @return mixed
	 */
	public function task( $item ) {

		return parent::task( $item );
	}

	/**
	 *
	 */
	public function complete() {

		parent::complete();
	}
}