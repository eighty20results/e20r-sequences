<?php

/**
 * License:
 *
 * Copyright 2017 Eighty/20 Results by Wicked Strong Chicks, LLC (thomas@eighty20results.com)
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
 **/
namespace E20R\Sequences\Tools;

// Deny direct access to the file
if ( ! defined( 'ABSPATH' ) ) {
	wp_die( __( "Cannot access file directly", 'e20r-sequences' ) );
}

if ( ! class_exists( 'E20R\Sequences\Tools\Cache_Object' ) ) {
	
	class Cache_Object {
		
		/**
		 * The Cache Key
		 * @var string
		 */
		private $key = null;
		
		/**
		 * The Cached value
		 * @var mixed
		 */
		private $value = null;
		
		/**
		 * Cache_Object constructor.
		 *
		 * @param string $key
		 * @param mixed  $value
		 */
		public function __construct( $key, $value ) {
			
			$this->key   = $key;
			$this->value = $value;
		}
		
		/**
		 * Setter for the key and value properties
		 *
		 * @param string $name
		 * @param mixed  $value
		 */
		public function __set( $name, $value ) {
			
			switch ( $name ) {
				case 'key':
				case 'value':
					$this->{$name} = $value;
					break;
			}
		}
		
		/**
		 * Getter for the key and value properties
		 *
		 * @param string $name
		 *
		 * @return mixed|null - Property value (for Key or Value property)
		 */
		public function __get( $name ) {
			
			$result = null;
			
			switch ( $name ) {
				case 'key':
				case 'value':
					
					$result = $this->{$name};
					break;
			}
			
			return $result;
		}
		
		public function __isset( $name ) {
			
			return isset( $this->{$name} );
		}
	}
}