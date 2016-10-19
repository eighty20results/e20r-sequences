<?php

/*
 * License:

	Copyright 2016 - Eighty / 20 Results by Wicked Strong Chicks, LLC (thomas@eighty20results.com)

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

class e20rUtils {

	/**
	 * @var     e20rUtils $instance Instance of this class (using singleton pattern)
	 */
	private static $instance;

	/**
	 * @var array $notice_msg List of error/warning/info messages
	 */
	private $notice_msg = array();

	/**
	 * @var array $notice_class List of CSS classes to match the $notice_msg keys
	 */
	private $notice_class = array();

	/**
	 * @var array   List of classes managed by the/an autoloader
	 */
	protected $autoloader_classes = array();

	/**
	 * e20rUtils constructor (private: using singleton pattern)
	 */
	public function __construct() {

		add_action( 'admin_notice', array( $this, 'display_in_admin' ) );
		add_action( 'wp_ready', array( $this, 'load_error_on_pmpro_page' ) );
	}

	/**
	 * Creates or returns an instance of the e20rUtils class.
	 *
	 * @return  e20rUtils A single instance of this class.
	 */
	public static function get_instance() {

		if ( null === self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	public function add_to_autoloader_list( $name ) {

		if ( ! in_array( $name, $this->autoloader_classes ) ) {
			$this->autoloader_classes[] = strtolower( $name );
		}
	}

	/**
	 * Build and return list of classes to be managed by an autoloader.
	 *
	 * @param array $list
	 *
	 * @return array
	 */
	public function autoloader_list( $list = array() ) {

		if ( empty( $this->autoloader_classes ) ) {

			$this->autoloader_classes = array(
				'e20rutils',
				'e20rlicense'
			);
		}

		return array_unique( $this->autoloader_classes, $list );
	}

	/**
	 * Process REQUEST variable: Check for presence and sanitize it before returning value or default
	 *
	 * @param string $name - Name of the variable to return
	 * @param null|mixed $default - The default value to return if the REQUEST variable doesn't exist or is empty.
	 *
	 * @return array|int|null|string    - Sanitized value from the front-end.
	 */
	public function _get_variable( $name, $default = null ) {

		return isset( $_REQUEST[ $name ] ) && ! empty( $_REQUEST[ $name ] ) ? $this->_sanitize( $_REQUEST[ $name ] ) : $default;
	}

	/**
	 * Sanitizes the passed field/value.
	 *
	 * @param array|int|null|string|stdClass $field The value to sanitize
	 *
	 * @return array|int|string     Sanitized value
	 */
	public function _sanitize( $field ) {

		if ( ! is_numeric( $field ) ) {

			if ( is_array( $field ) ) {

				foreach ( $field as $key => $val ) {
					$field[ $key ] = $this->_sanitize( $val );
				}
			}

			if ( is_object( $field ) ) {

				foreach ( $field as $key => $val ) {
					$field->{$key} = $this->_sanitize( $val );
				}
			}

			if ( ( ! is_array( $field ) ) && ctype_alpha( $field ) ||
			     ( ( ! is_array( $field ) ) && strtotime( $field ) ) ||
			     ( ( ! is_array( $field ) ) && is_string( $field ) )
			) {

				$field = sanitize_text_field( $field );
			}

		} else {

			if ( is_float( $field + 1 ) ) {

				$field = sanitize_text_field( $field );
			}

			if ( is_int( $field + 1 ) ) {

				$field = intval( $field );
			}
		}

		return $field;
	}

	/**
	 * Set 'selected' option for value/array pair
	 *
	 * @param   array $array Array of values to compare with
	 * @param   mixed $value The value to check against
	 *
	 * @return null|string
	 */
	public function _is_selected( $value, $array ) {

		return in_array( $value, $array ) ? 'selected="selected"' : null;
	}

	/**
	 * Save the admin notice text and severity (class) to use
	 *
	 * @param string $message Text to use in admin notice
	 * @param string $severity CSS class for admin notices (see link for appropriate class names)
	 *
	 * @url     https://codex.wordpress.org/Plugin_API/Action_Reference/admin_notices
	 */
	public function set_notice( $message, $severity = 'info' ) {

		$this->notice_msg[]   = $message;
		$this->notice_class[] = $severity;
	}

	/**
	 * Try to load any error messages on the PMPro membership page(s).
	 */
	public function load_error_on_pmpro_page() {

		global $pmpro_pages;

		$included_pages = array();

		foreach ( $pmpro_pages as $pid ) {
			if ( $pid !== $pmpro_pages['levels'] ) {
				$included_pages[] = $pid;
			}
		}

		$check_pages = apply_filters( "e20r_utils_pmpro_error_pages", $included_pages );

		// We're loading one of the included pages.
		if ( is_page( $included_pages ) && ! empty( $this->notice_msg ) ) {

			global $pmpro_msg, $pmpro_msgt;

			$pmpro_msg  = explode( ' - ', $this->notice_msg );
			$pmpro_msgt = array_pop( $this->notice_class );

			$this->notice_msg   = array();
			$this->notice_class = array();
		}
	}

	/**
	 * Display any notice message(s)
	 */
	public function display_notice() {

		if ( ! empty( $this->notice_msg ) ) {
			foreach ( $this->notice_msg as $key => $msg ) ?>
				<div class="notice notice-<?php echo $this->notice_class[ $key ]; ?>">
			<p><?php echo $msg; ?></p>
			</div>
			<?php
		}

		$this->notice_msg   = array();
		$this->notice_class = array();
	}

	/**
	 * Show wp-admin error/warning/notices
	 */
	public function display_in_admin() {

		if ( is_admin() ) {
			if ( ! empty( $this->notice_msg ) ) {
				foreach ( $this->notice_msg as $key => $msg )
					?>
					<div class="notice notice-<?php echo $this->notice_class[ $key ]; ?>">
				<p><?php echo $msg; ?></p>
				</div>
				<?php
			}

		}

		$this->notice_msg   = array();
		$this->notice_class = array();
	}

	/**
	 * @return array|string
	 */
	private function _who_called_me() {

		$trace  = debug_backtrace();
		$caller = $trace[2];

		if ( isset( $caller['class'] ) ) {
			$trace = "{$caller['class']}::{$caller['function']}()";
		} else {
			$trace = "Called by {$caller['function']}()";
		}

		return $trace;
	}

	public function log( $msg ) {

		$tid = sprintf( "%08x", abs( crc32( $_SERVER['REMOTE_ADDR'] . $_SERVER['REQUEST_TIME'] ) ) );

		$from = $this->_who_called_me();

		if ( ! defined( "WP_DEBUG" ) ) {
			echo "[{$tid}] {$from}: {$msg}";
		} else {
			error_log( "[{$tid}] {$from} - {$msg}" );
		}
	}
}

// Load ourselves to memory (early).
// add_action('plugins_loaded', 'e20rUtils::get_instance' );