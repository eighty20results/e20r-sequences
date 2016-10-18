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

defined( 'ABSPATH' ) or die( 'Cannot access plugin sources directly' );

if ( !defined( 'E20R_LICENSE_VERSION' ) ) {
	define( 'E20R_LICENSE_VERSION', '1.0' );
}
if ( ! defined( 'E20R_LICENSE_SERVER_URL' ) ) {
	define( 'E20R_LICENSE_SERVER_URL', 'https://eighty20results.com' );
}

if ( ! defined( 'E20R_LICENSE_SECRET_KEY' ) ) {
	define( 'E20R_LICENSE_SECRET_KEY', '5687dc27b50520.33717427' );
}

// Define constants related to upstream license status
if ( ! defined( 'E20R_LICENSE_MAX_DOMAINS' ) ) {
	define( 'E20R_LICENSE_MAX_DOMAINS', 0x10000 );
}

if ( ! defined( 'E20R_LICENSE_REGISTERED' ) ) {
	define( 'E20R_LICENSE_REGISTERED', 0x20000 );
}

if ( ! defined( 'E20R_LICENSE_ERROR' ) ) {
	define( 'E20R_LICENSE_ERROR', 0x01000 );
}

// Don't redefine the class if it exists in memory already
if ( class_exists( 'e20rLicense' ) ) {
	return;
}

class e20rLicense {

	/**
	 * @var e20rLicense $instance The class instance
	 */
	private static $instance = null;

	/**
	 * @var e20rUtils   Utilities class instance
	 */
	private $utils;

	/**
	 * @var string $option_name The name to use in the WordPress options table (default: class name)
	 */
	private $option_name = '';

	/**
	 * @var array $license_list Licenses we're managing
	 */
	protected $license_list = array();

	/**
	 * @var e20rLicense $license
	 */
	private $license;

	/**
	 * e20rLicense constructor.
	 */
	public function __construct() {

		$this->option_name = strtolower( get_class( $this ) );

		$this->setDefaultLicense();

		$list = get_option( $this->option_name );

		if ( ! empty( $list ) ) {
			$this->license_list = shortcode_atts( $this->license_list, $list );
		}

		/**
		 * Filters and actions for this licesne class
		 */
		add_action( 'init', array( $this, 'loadTranslation' ) );
		add_action( 'admin_menu', array( $this, 'addOptionsPage' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Hook into admin_init when we need to.
		if ( ! empty ( $GLOBALS['pagenow'] )
		     && ( 'options-general.php' === $GLOBALS['pagenow']
		          || 'options.php' === $GLOBALS['pagenow']
		     )
		) {
			add_action( 'admin_init', array( $this, 'registerSettings' ) );
		}

		add_action( 'http_api_curl', array( $this, 'force_tls_12' ) );

		if ( class_exists( 'e20rUtils' ) ) {
			$this->utils = e20rUtils::get_instance();
			$this->utils->add_to_autoloader_list( get_class( $this ) );
		}
	}

	/**
	 * Retrieve and initiate the class instance
	 *
	 * @return e20rLicense
	 */
	public static function get_instance() {

		if ( is_null( self::$instance ) ) {
			self::$instance = new self;
		}

		$class = self::$instance;

		return $class;
	}

	/**
	 * Return the existing instance of the e20rUtils class (for notices/etc)
	 *
	 * @return e20rUtils
	 */
	public function get_utils() {
		return $this->utils;
	}

	/**
	 * Test & register a license
	 *
	 * @param string $name
	 * @param string $descr
	 */
	public static function registerLicense( $name, $descr ) {

		$class = self::get_instance();
		$utils = $class->get_utils();

		$licenses = $class->getAllLicenses();

		foreach ( $licenses as $ln => $settings ) {

			if ( 'e20r_default_license' === $ln ) {
				$utils->log( "No need to process the default license" );
				continue;
			}

			// Is a version of the key present?
			if ( false !== strpos( $ln, $name ) ) {

				$utils->log( "Similar key to {$name} found in license settings: {$ln}" );

				if ( $ln !== $name ) {

					$utils->log( "But not identical, so using saved name" );
					$licenses[ $ln ]                  = $settings;
					$licenses[ $ln ]['fulltext_name'] = $descr;
					unset( $licenses[ $name ] );
				}
			}

			/*
			// The license is active on the remote Eighty/20 Results license server?
			if ( false === $class->$this->verifyLicense( $name, true ) ) {

				if ( false === $class->activateExistingLicenseOnServer( $name, $descr ) ) {

					$utils->log( "{$name} license not active, using trial license" );
					// No, we'll add default (trial) license
					$class->addLicense( $name, $class->generateNullLicense( $name, $descr ) );
				}
			}
			*/
		}
	}

	/**
	 * Static wrapper to allow a 3rd party to set a license description text for a given license name
	 *
	 * @param $license
	 * @param $text
	 */
	public static function setDescription( $license, $text ) {

		$me = self::get_instance();
		$me->setDescr( $license, $text );
	}

	/**
	 * Static wrapper for the checkLicense() function
	 *
	 * @param $license_name
	 *
	 * @return bool
	 *
	 * TODO: Include e20rLicense::isLicenseActive() in class using this infrastructure
	 */
	public static function isLicenseActive( $license_name, $package, $reply ) {

		$class = self::get_instance();

		return $class->checkLicense( $license_name );
	}

	public function setDescr( $license, $descr ) {

		$this->license_list[ $license ]['fulltext_name'] = $descr;

		return $this->updateLicenses( $this->license_list );
	}

	/**
	 * Add license settings to the
	 *
	 * @param string $name
	 * @param array $definition
	 *
	 * @return bool
	 */
	public function addLicense( $name, $definition ) {

		// Save the license definition to the license list
		$this->license_list[ $name ] = $definition;

		// Update the options table w/the new license definition
		$this->updateLicenses( $this->license_list );

		// Remove the transient
		delete_transient( "{$this->option_name}_{$name}" );

		return true;
	}

	/**
	 * Remove license from list of licenses.
	 *
	 * @param string $name The short name for the license
	 *
	 * @return bool
	 */
	public function deleteLicense( $name ) {

		$this->utils->log( "Deleting license: {$name}" );

		// Remove the license information from the local server.
		if ( isset( $this->license_list[ $name ] ) && false === strpos( 'e20r_default_license', $name ) ) {

			delete_transient( "{$this->option_name}_{$name}" );
			unset( $this->license_list[ $name ] );
			$this->updateLicenses( $this->license_list );

			$this->utils->log( "License has been removed: {$name}" );

			return true;
		}

		return false;
	}

	/**
	 * Save license information to the options table
	 *
	 * @param $licenses
	 *
	 * @return array
	 */
	private function updateLicenses( $licenses ) {

		$retVal = true;

		$this->license_list = wp_parse_args( $this->license_list, $licenses );
		$this->utils->log( "About to save: " . print_r( $this->license_list, true ) );

		$updated = update_option( $this->option_name, $this->license_list, true );

		if ( false === $updated ) {
			$test = get_option( $this->option_name );

			if ( is_array( $test ) && is_array( $this->license_list ) ) {

				$diff = array_udiff_assoc( $test, $this->license_list, array( $this, 'compare_licenses' ) );

				if ( ! empty( $diff ) ) {
					$this->utils->log( "The saved license list and the in-memory list are different!" );
				}
			} else {
				$this->utils->log( "The license data isn't in array format??" );
			}
		}

		$this->utils->log("Returning the license list as saved");
		return $this->license_list;
	}

	/**
	 * Compare license entries by the timestamp they were updated on the local system
	 *
	 * @param stdClass $lic_a - First license
	 * @param stdClass $lic_b - Second license
	 *
	 * @return int
	 */
	public function compare_licenses( $lic_a, $lic_b ) {

		if ( isset( $lic_a->timestamp ) ) {
			$a = $lic_a->timestamp;
		} else {
			$a = null;
		}

		if ( isset( $lic_b->timestamp ) ) {
			$b = $lic_b->timestamp;
		} else {
			$b = null;
		}

		if ( $a == $b ) {
			return 0;
		} elseif ( $a > $b ) {
			return 1;
		} else {
			return - 1;
		}
	}

	/**
	 * Get a copy of the licenses from the options table (if needed).
	 *
	 * @return array
	 */
	public function getAllLicenses() {

		$list               = get_option( $this->option_name, array() );
		$this->license_list = wp_parse_args( $list, $this->license_list );

		return $this->license_list;
	}

	/**
	 * Generates a default (dummy) license
	 */
	private function setDefaultLicense() {

		$this->defaults = array(
			'e20r_default_license' =>
				$this->generateNullLicense( 'e20r_default_license', __( "Temporary Update license", "e20rlicense" ) )
		);

		$this->license_list = get_option( $this->option_name );

		if ( ! empty( $this->license_list ) ) {
			$this->license_list = shortcode_atts( $this->defaults, $this->license_list );
		} else {
			$this->license_list = $this->defaults;
		}
	}

	/**
	 * Create a default license definition for the specified shortname/product name
	 *
	 * @param null $name
	 * @param null $product_name
	 *
	 * @return array|mixed
	 */
	public function generateNullLicense( $name = null, $product_name = null ) {

		if ( is_null( $name ) ) {
			$name = 'e20r_default_license';
		}

		if ( is_null( $product_name ) ) {
			$product_name = sprintf( __( "Update license for %s", "e20rlicense" ), $name );
		}

		if ( empty( $this->license_list[ $name ] ) ) {

			$new_license = array(
				'fulltext_name' => $product_name,
				'key'           => $name,
				'expires'       => strtotime( '+1 week', current_time( 'timestamp' ) ),
				'status'        => 'inactive',
				'timestamp'     => current_time( 'timestamp' ),
			);

			return $new_license;

		} else {
			return $this->license_list[ $name ];
		}
	}

	/**
	 * Load and validate the license information for a named license
	 *
	 * @param string $name The name of the license
	 *
	 * @return bool     True if the license is valid & exists.
	 */
	public function checkLicense( $name = 'e20r_default_license' ) {

		if ( empty( $this->license_list ) ) {

			$this->license_list = get_option( $this->option_name );
		}

		// Generate expiration info for the license
		if ( isset( $this->license_list[ $name ]['expires'] ) ) {
			$expiration_info = sprintf(
				__( "on %s", "e20rlicense" ),
				date_i18n( get_option( 'date_format' ), $this->license_list[ $name ]['expires'] ) );
		} else {
			$expiration_info = __( "soon", "e20rlicense" );
		}

		// We're using a default license
		if ( $name === 'e20r_default_license' ) {

			// Is the trial/default license active?
			if ( $this->verifyLicense( $name, true ) ) {

				$msg = sprintf(
					__( "You're currently using the trial license. It will expire <strong>%s</strong>", "e20rlicense" ),
					$expiration_info
				);

				if ( ! empty( $this->utils ) ) {
					$this->utils->log( $msg );
				}

				$this->utils->set_notice( $msg, 'warning' );

				return true;
			}

			$msg = sprintf(
				__( "You have been using a trial license. It has expired as of <strong>%s</strong>", "e20rlicense" ),
				$expiration_info
			);

			if ( ! empty( $this->utils ) ) {
				$this->utils->log( $msg );
			}

			// It's not
			$this->utils->set_notice( $msg, 'warning' );

			return false;
		}

		// Is the license active
		if ( $this->verifyLicense( $name, true ) ) {
			// Yes
			return true;
		} else {

			$msg = sprintf(
				__( "Your license for %s has expired (as of: %s)", "e20rlicense" ),
				$this->license_list[ $name ]['fulltext_name'],
				date_i18n( get_option( 'date_format' ), $this->license_list[ $name ]['expires'] )
			);

			if ( ! empty( $this->utils ) ) {
				$this->utils->log( $msg );
			}

			// No. Warn & return.
			$this->utils->set_notice( $msg, 'warning' );
		}

		return false;
	}

	/**
	 * Default User settings for the license.
	 *
	 * @return array
	 */
	private function defaultUserSettings() {
		global $current_user;

		if ( ! is_user_logged_in() ) {
			return null;
		}

		return array(
			'first_name' => $current_user->user_firstname,
			'last_name'  => $current_user->user_lastname,
			'email'      => $current_user->user_email,
		);
	}

	/**
	 * Validate the license specific settings as they're being saved
	 *
	 * @param array $values
	 *
	 * @return array|mixed|void
	 */
	public function validateLicenseSettings( $input ) {

		$this->utils->log("Validation input: " . print_r( $input, true ) );

		$licenses = $this->getAllLicenses();
		$out = array();

		if ( isset( $input[0]['status'] ) ) {
			$this->utils->log("Skipping validate since our data consists of the expected stuff already");
			$bypass = true;
			return $input;
		}

		if (!empty( $input['fieldname'][0] ) && is_array( $input['fieldname']) ) {

			// Process all values received
			foreach ( $input['fieldname'] as $key => $name ) {

				if ( false === stripos( $name, 'new_license' ) && !empty( $name ) ) {
					$license_key   = $name;
					$license_email = $input['license_email'][ $key ];
				} else {
					$license_key   = $input['new_key'][0];
					$license_email = $input['new_email'][0];
				}

				if ( !empty( $license_email ) && !empty( $license_key ) ) {

					$this->utils->log( "Processing {$license_key} with email {$license_email}" );
					$status = $this->licenseManagement( $license_key, $license_email );

					$out[] = array(
						'email'  => $license_email,
						'key'    => $license_key,
						'status' => $status
					);
				}

				if ( 'e20r_default_license' === $license_key && is_array( $licenses['e20r_default_license']['key'] ) ) {
					$this->utils->log( "Resetting the default key." );
					$this->license_list['e20r_default_license'] = $this->generateNullLicense( 'e20r_default_license', 'Temporary Update license' );
				}
			}
		} elseif( !empty( $input['new_key'][0] )) {

			$this->utils->log("Processing a new license. No other licenses on page.");

			$license_key   = $input['new_key'][0];
			$license_email = $input['new_email'][0];

			if ( !empty( $license_email ) && !empty( $license_key ) ) {

				$status = $this->licenseManagement( $license_key, $license_email );

				$out[] = array(
					'email'  => $license_email,
					'key'    => $license_key,
					'status' => $status
				);
			}

		}

		$this->utils->log( "Returning after validation: " . print_r( $out, true ) );

		if ( empty( $out ) ){
			$out = get_option("{$this->option_name}_settings", array() );
		}

		return $out;

	}

	private function licenseManagement( $license_key, $license_email ) {

		global $current_user;

		$status = false;

		if ( false === $this->verifyLicense( $license_key, true ) ) {

			$user_settings = array(
				'first_name' => $current_user->user_firstname,
				'last_name'  => $current_user->user_lastname,
				'email'      => $license_email,
			);

			$status = $this->activateExistingLicenseOnServer( $license_key, __( "Add-on Update License", "e20rlicense" ), $user_settings );

			switch ( $status ) {
				case E20R_LICENSE_MAX_DOMAINS:

					$msg = sprintf( __( "Exceeded the available registration domains for the %s license (email: %s)", "e20rlicense" ), $license_key, $license_email );
					$this->utils->set_notice( $msg, 'error' );
					$this->utils->log( $msg );

					break;

				case E20R_LICENSE_ERROR:
					$msg = sprintf( __( "Failed during activation of the %s license for %s", "e20rlicense" ), $license_key, $license_email );
					$this->utils->set_notice( $msg, 'error' );
					$this->utils->log( $msg );
					break;

				case E20R_LICENSE_REGISTERED:
					$this->utils->log( "Attempting to verify the {$license_key} license again" );
					$this->verifyLicense( $license_key, true );
					$msg = sprintf( __( "Activated the %s license for %s", "e20rlicense" ), $license_key, $license_email );
					$this->utils->set_notice( $msg, 'notice' );
					$this->utils->log( $msg );
					break;
			}
		}

		return $status;
	}

	/**
	 * Activate an existing license for the domain where this license is running.
	 *
	 * @param string $name The shortname of the license to register
	 * @param string $product_name The fulltext name of the product being registered
	 *
	 * @return bool
	 */
	public function activateExistingLicenseOnServer( $name, $product_name, $user_settings = array() ) {

		$state = null;
		$this->utils->log( "Attempting to activate {$name} on remote server" );

		if ( empty( $user_settings ) ) {

			// Default settings (not ideal)
			$user_settings = $this->defaultUserSettings();
		}

		if ( empty( $user_settings ) ) {
			return false;
		}

		if ( empty( $this->license_list[ $name ] ) ) {

			$this->license_list[ $name ]        = $this->generateNullLicense( $name, $product_name );
			$this->license_list[ $name ]['key'] = $name;

			$this->utils->log( "Have to generate a default license for now: " . print_r( $this->license_list, true ) );
		}

		$api_params = array(
			'slm_action'        => 'slm_activate',
			'license_key'       => $this->license_list[ $name ]['key'],
			'secret_key'        => E20R_LICENSE_SECRET_KEY,
			'registered_domain' => $_SERVER['SERVER_NAME'],
			'item_reference'    => urlencode( $product_name ),
			'first_name'        => $user_settings['first_name'],
			'last_name'         => $user_settings['last_name'],
			'email'             => $user_settings['email'],
		);

		$this->utils->log( "Transmitting...: " . print_r( $api_params, true ) );

		// Send query to the license manager server
		$response = wp_remote_get(
			add_query_arg( $api_params, E20R_LICENSE_SERVER_URL ),
			array(
				'timeout'     => apply_filters( 'e20r-license-server-timeout', 30 ),
				'sslverify'   => true,
				'httpversion' => '1.1',
				'decompress'  => true,
			)
		);

		// Check for error in the response
		if ( is_wp_error( $response ) ) {

			$this->utils->log( "Unexpected Error! The server request returned with an error." );

			return false;
		}

		// License data.
		$license_data = stripslashes( wp_remote_retrieve_body( $response ) );

		$bom          = pack( 'H*', 'EFBBBF' );
		$license_data = preg_replace( "/^$bom/", '', $license_data );
		$decoded      = json_decode( $license_data );

		if ( null === $decoded && json_last_error() !== JSON_ERROR_NONE ) {

			switch ( json_last_error() ) {
				case JSON_ERROR_DEPTH:
					$error = 'Maximum stack depth exceeded';
					break;
				case JSON_ERROR_STATE_MISMATCH:
					$error = 'Underflow or the modes mismatch';
					break;
				case JSON_ERROR_CTRL_CHAR:
					$error = 'Unexpected control character found';
					break;
				case JSON_ERROR_SYNTAX:
					$error = 'Syntax error, malformed JSON';
					break;
				case JSON_ERROR_UTF8:
					$error = 'Malformed UTF-8 characters, possibly incorrectly encoded';
					break;
				default:
					$error = "No error, supposedly? " . print_r( json_last_error(), true );
			}

			$this->utils->log( "Response from remote server: <" . $license_data . ">" );
			$this->utils->log( "JSON decode error: " . $error );
		} else {

			$this->utils->log( "License data received: (" . print_r( $decoded, true ) . ")" );
		}

		if ( isset( $decoded->result ) ) {

			$this->utils->log( "Decoded JSON and received a status... ({$decoded->result})" );

			switch ( $decoded->result ) {

				case 'success':
					$this->license_list[ $name ]['status']    = 'active';
					$this->license_list[ $name ]['timestamp'] = current_time( 'timestamp' );
					$this->utils->log( "Added {$name} to license list" );
					$this->utils->log( "Activated {$name} on the remote server." );
					$state = E20R_LICENSE_REGISTERED;
					break;

				case 'error':

					$msg = $decoded->message;

					if ( false !== stripos( $msg, 'maximum' ) ) {
						$state = E20R_LICENSE_MAX_DOMAINS;
					} else {
						$state = E20R_LICENSE_ERROR;
					}
					$this->utils->set_notice( $decoded->message, $decoded->result );
					$this->utils->log( "{$decoded->message}" );
					// unset( $this->license_list[ $name ] );
					break;
			}

			$this->utils->log( "Saving new license information" );
			$this->updateLicenses( $this->license_list );
		}

		return $state;

	}

	/**
	 * Check and cache the license status for this instance.
	 *
	 * @param   string $name License shortname
	 * @param   bool $force Whether to load from transient or force a check
	 *
	 * @return bool
	 */
	public function verifyLicense( $name, $force = false ) {

		$license_status = null;
		global $current_user;

		// Specified license doesn't exist.
		if ( !isset( $this->license_list[$name]['key']) ) {
			$this->utils->log("The {$name} license doesn't exist locally (yet)");
			return false;
		}

		// Load from transients (cache)
		if ( true === $force || false === ( $license_status = get_transient( "{$this->option_name}_{$name}" ) ) ) {

			$this->utils->log( "No transient found, or request forced for: {$name} / {$this->license_list[$name]['key']}" );

			// Configure request for license check
			$api_params = array(
				'slm_action'  => 'slm_check',
				'secret_key'  => E20R_LICENSE_SECRET_KEY,
				'license_key' => $this->license_list[ $name ]['key'],
			);

			// Send query to the license manager server
			$response = wp_remote_get(
				add_query_arg( $api_params, E20R_LICENSE_SERVER_URL ),
				array(
					'timeout'     => apply_filters( 'e20r-license-server-timeout', 30 ),
					'sslverify'   => true,
					'httpversion' => '1.1',
					'decompress'  => true,
				)
			);

			// Check for error in the response
			if ( is_wp_error( $response ) ) {

				$msg = sprintf( __( "E20R License: %s", "e20rlicense" ), $response->get_error_message() );

				$this->utils->log( $msg );
				$this->utils->set_notice( $msg, 'error' );

				return false;
			}

			$license_data = stripslashes( wp_remote_retrieve_body( $response ) );

			$bom          = pack( 'H*', 'EFBBBF' );
			$license_data = preg_replace( "/^$bom/", '', $license_data );
			$decoded      = json_decode( $license_data );

			if ( null === $decoded && json_last_error() !== JSON_ERROR_NONE ) {

				switch ( json_last_error() ) {
					case JSON_ERROR_DEPTH:
						$error = 'Maximum stack depth exceeded';
						break;
					case JSON_ERROR_STATE_MISMATCH:
						$error = 'Underflow or the modes mismatch';
						break;
					case JSON_ERROR_CTRL_CHAR:
						$error = 'Unexpected control character found';
						break;
					case JSON_ERROR_SYNTAX:
						$error = 'Syntax error, malformed JSON';
						break;
					case JSON_ERROR_UTF8:
						$error = 'Malformed UTF-8 characters, possibly incorrectly encoded';
						break;
					default:
						$error = "No error, supposedly? " . print_r( json_last_error(), true );
				}

				$this->utils->log( "Response from remote server: <" . $license_data . ">" );
				$this->utils->log( "JSON decode error: " . $error );
			} else {

				$this->utils->log( "License data received: (" . print_r( $decoded, true ) . ")" );
			}

			// License not validated
			if ( ! isset( $decoded->result ) || 'success' != $decoded->result ) {

				$msg = sprintf( __( "Sorry, you need a valid update license for the %s add-on", "e20rlicense" ), $this->license_list[ $name ]['fulltext_name'] );
				$this->utils->log( $msg );
				$this->utils->set_notice( $msg, 'error' );

				return false;
			}

			if ( is_array( $decoded->registered_domains ) ) {

				$this->utils->log( "Processing license data for (count: " . count( $decoded->registered_domains ) . " domains )" );

				foreach ( $decoded->registered_domains as $domain ) {

					if ( isset( $domain->registered_domain ) && $domain->registered_domain == $_SERVER['SERVER_NAME'] ) {

						if ( '0000-00-00' != $decoded->date_renewed ) {
							$this->license_list[ $name ]['renewed'] = strtotime( $decoded->date_renewed, current_time( 'timestamp' ) );
						} else {
							$this->license_list[ $name ]['renewed'] = current_time( 'timestamp' );
						}
						$this->license_list[ $name ]['domain']        = $domain->registered_domain;
						$this->license_list[ $name ]['fulltext_name'] = $domain->item_reference;
						$this->license_list[ $name ]['expires']       = strtotime( $decoded->date_expiry, current_time( 'timestamp' ) );
						$this->license_list[ $name ]['status']        = $decoded->status;
						$this->license_list[ $name ]['first_name']    = $current_user->user_firstname;
						$this->license_list[ $name ]['last_name']     = $current_user->user_lastname;
						$this->license_list[ $name ]['email']         = $decoded->email;
						$this->license_list[ $name ]['timestamp']     = current_time( 'timestamp' );

						$this->updateLicenses( $this->license_list );
					}
				}
			} else {

				$this->utils->log("The {$name} license is on the server, but not active for this domain");
				return false;
			}

			if ( $this->license_list[ $name ]['expires'] < current_time( 'timestamp' ) || 'active' !== $this->license_list[ $name ]['status'] ) {

				$msg = sprintf(
					__( "Your update license has expired for the %s add-on!", "e20rlicense" ),
					$this->license_list[ $name ]['fulltext_name']
				);

				$this->utils->log( $msg );
				$this->utils->set_notice( $msg, 'error' );

				return false;
			}

			// Doesn't really matter what the status of the transient update is.
			set_transient( "{$this->option_name}_{$name}", "{$name}_license_is_valid", DAY_IN_SECONDS );
			$this->utils->log( "{$name} license is active and current." );

			return true;

		} else {

			if ( "{$name}_license_is_valid" === $license_status ) {

				$this->utils->log( "Valid license found for {$name}" );

				return true;
			}
		}

		$msg = sprintf( __( "Sorry, you need a valid update license for the %s add-on", "e20rlicense" ), 'E20R Membership Setup Fee' );

		$this->utils->log( $msg );
		$this->utils->set_notice( $msg, 'error' );

		return false;
	}

	/**
	 * Deactivate the license on the remote license server
	 *
	 * @param string $name License name/key.
	 *
	 * @return bool
	 */
	public function deactivateExistingLicenseOnServer( $name, $key ) {

		$this->utils->log( "Attempting to deactivate {$name} on remote server" );

		$api_params = array(
			'slm_action'        => 'slm_deactivate',
			'license_key'       => $key,
			'secret_key'        => E20R_LICENSE_SECRET_KEY,
			'registered_domain' => $_SERVER['SERVER_NAME'],
			'status'            => 'pending'
		);

		$this->utils->log( "Transmitting...: " . print_r( $api_params, true ) );

		// Send query to the license manager server
		$response = wp_remote_get(
			add_query_arg( $api_params, E20R_LICENSE_SERVER_URL ),
			array(
				'timeout'     => apply_filters( 'e20r-license-server-timeout', 30 ),
				'sslverify'   => true,
				'httpversion' => '1.1',
			)
		);

		// Check for error in the response
		if ( is_wp_error( $response ) ) {
			$this->utils->log( "Unexpected Error! The server request returned with an error." );
		}

		// License data.
		$license_data = stripslashes( wp_remote_retrieve_body( $response ) );

		$bom          = pack( 'H*', 'EFBBBF' );
		$license_data = preg_replace( "/^$bom/", '', $license_data );
		$decoded      = json_decode( $license_data );

		if ( null === $decoded && json_last_error() !== JSON_ERROR_NONE ) {

			switch ( json_last_error() ) {
				case JSON_ERROR_DEPTH:
					$error = 'Maximum stack depth exceeded';
					break;
				case JSON_ERROR_STATE_MISMATCH:
					$error = 'Underflow or the modes mismatch';
					break;
				case JSON_ERROR_CTRL_CHAR:
					$error = 'Unexpected control character found';
					break;
				case JSON_ERROR_SYNTAX:
					$error = 'Syntax error, malformed JSON';
					break;
				case JSON_ERROR_UTF8:
					$error = 'Malformed UTF-8 characters, possibly incorrectly encoded';
					break;
				default:
					$error = "No error, supposedly? " . print_r( json_last_error(), true );
			}

			$this->utils->log( "Response from remote server: <" . $license_data . ">" );
			$this->utils->log( "JSON decode error: " . $error );
		} else {

			$this->utils->log( "License data received: (" . print_r( $decoded, true ) . ")" );
		}

		$this->utils->log( "Removing license {$name}..." );

		return $this->deleteLicense( $name );

	}

	/**
	 * Options page for E20R Licenses
	 */
	public function addOptionsPage() {

		add_options_page(
			__( "E20R Licensing", "e20rlicense" ),
			__( "E20R Licensing", "e20rlicense" ),
			'manage_options',
			'e20r-license',
			array( $this, 'licensePage' )
		);
	}

	/**
	 * Settings API functionality for license management
	 */
	public function registerSettings() {

		register_setting(
			"{$this->option_name}_settings", // group, used for settings_fields()
			"{$this->option_name}_settings",  // option name, used as key in database
			array( $this, 'validateLicenseSettings' )      // validation callback
		);

		add_settings_section(
			'e20r_license_section',
			__( "Update Licenses", "e20rlicense" ),
			array( $this, 'showLicenseSection' ),
			'e20r-license'
		);

		$this->getAllLicenses();
		$this->utils->log( "License info: " . print_r( $this->license_list, true ) );

		$settings = get_option("{$this->option_name}_settings", array() );

		$this->utils->log("License settings: " . print_r( $settings, true ));

		foreach ( $settings as $k => $license ) {

			// Skip and clean up.
			if (empty( $license['key']) ) {

				unset($settings[$k]);
				update_option( "{$this->option_name}_settings", $settings );
				continue;
			}

			$this->utils->log( "Generate settings fields for {$license['key']}" );

			if ( $license['key'] != 'e20r_default_license' && !empty( $license['key'] ) ) {

				add_settings_field(
					"e20r_license_{$license['key']}",
					$this->license_list[$license['key']]['fulltext_name'],
					array( $this, 'showLicenseKeyInput' ),
					'e20r-license',
					'e20r_license_section',
					array(
						'label_for'   => $license['key'],
						'option_name' => "{$this->option_name}_settings",
						'name'        => 'license_key',
						'input_type'  => 'password',
						'value'       => $license['key'],
						'email_field' => "license_email",
						'email_value' => ! empty( $license['email'] ) ? $license['email'] : null,
						'placeholder' => __( "Paste/enter the license key here", "e20rlicense" )
					)
				);
			}
		}

		add_settings_field(
			'e20r_license_new',
			__( "Add new license", "e20rlicense" ),
			array( $this, 'showLicenseKeyInput' ),
			'e20r-license',
			'e20r_license_section',
			array(
				'label_for'   => 'new_license',
				'option_name' => "{$this->option_name}_settings",
				'name'        => 'new_key',
				'input_type'  => 'text',
				'value'       => null,
				'email_field' => "new_email",
				'email_value' => null,
				'placeholder' => __( 'Enter the new license key here', 'e20rlicense' )
			)
		);
	}

	/**
	 * License management page (Settings)
	 */
	public function licensePage() {

		if ( ! function_exists( "current_user_can" ) || ( ! current_user_can( "manage_options" ) && ! current_user_can( "e20r_license" ) ) ) {
			wp_die( __( "You are not permitted to perform this action.", "e20rlicense" ) );
		}
		?>
		<?php $this->utils->display_notice(); ?>
		<br/>
		<h2><?php echo $GLOBALS['title']; ?></h2>
		<form action="options.php" method="POST">
			<?php
			settings_fields( "{$this->option_name}_settings" );
			do_settings_sections( 'e20r-license' );
			submit_button();
			?>
		</form>
		<?php

		$settings = get_option( "{$this->option_name}_settings", array() );

		foreach ( $settings as $license ) {

			if ( $license['key'] == 'e20r_default_license' ) {
				continue;
			}

			$license_valid = $this->verifyLicense( $license['key'] );

			?>

			<div class="wrap"><?php
				if ( $this->license_list[$license['key']]['expires'] <= current_time( 'timestamp' ) || empty( $this->license_list[$license['key']]['expires'] ) ) {
					?>
					<div class="notice notice-error inline">
					<p>
						<strong><?php _e( 'Your update license is invalid or expired.', 'e20rsetupfee' ); ?></strong>
						<?php _e( 'Visit your Eighty / 20 Results <a href="http://eighty20results.com/login/?redirect_to=/accounts/" target="_blank">Support Account</a> page to confirm that your account is active and to locate your update license key.', 'e20rlicense' ); ?>
					</p>
					</div><?php
				}

				if ( $license_valid ) {
					?>
					<div class="notice notice-info inline">
					<p>
						<strong><?php _e( 'Thank you!', "e20rlicense" ); ?></strong>
						<?php _e( "A valid license key has been used as your update license for this site.", 'e20rlicense' ); ?>
					</p>
					</div><?php

				} ?>
			</div> <!-- end wrap -->
			<?php
		}
	}

	/**
	 * Header for License settings
	 */
	public function showLicenseSection() {
		?>
		<p class="e20r-license-section"><?php _e( "This add-on is distributed under version 2 of the GNU Public License (GPLv2). One of the things the GPLv2 license grants is the right to use this software on your site, free of charge.", "e20rlicense" ); ?></p>
		<p class="e20r-license-section">
			<strong>
				<?php _e( "An annual update license is recommended for websites running this add-on together with the Paid Memberships Pro WordPress plugin.", "e20rlicense" ); ?>
			</strong>
			<a href="https://eighty20results.com/pricing/"
			   target="_blank"><?php _e( "View License Options &raquo;", "e20rlicense" ); ?></a>
		</p>
		<?php

	}

	/**
	 * Generate input for license information
	 *
	 * @param array $args Arguments used to configure input field(s)
	 */
	public function showLicenseKeyInput( $args ) {

		printf( '<input type="hidden" name="%1$s" value="%2$s" />', "{$args['option_name']}[fieldname][]", $args['value'] );
		printf(
			'<input name="%1$s[%2$s][]" type="%3$s" id="%4$s" value="%5$s" placeholder="%6$s" class="regular_text">',
			$args['option_name'],
			$args['name'],
			$args['input_type'],
			$args['label_for'],
			$args['value'],
			$args['placeholder']
		);
		printf(
			'<input name="%1$s[%2$s][]" type="email" id=%3$s_email value="%4$s" placeholder="%5$s" class="email_address" style="width: 250px;">',
			$args['option_name'],
			$args['email_field'],
			$args['label_for'],
			$args['email_value'],
			__( "Email address used to purhcase license", "e20rlicense" )
		);
		printf(
			'<input type="button" name="%1$s[delete][]" class="clear_license button-primary" value="%2$s">',
			$args['option_name'],
			__("Deactivate License", "e20rlicense")
		);
	}

	/**
	 * Load the required translation file for the add-on
	 */
	public function loadTranslation() {

		$locale = apply_filters( "plugin_locale", get_locale(), "e20rlicense" );
		$mo     = "e20rlicense-{$locale}.mo";

		// Paths to local (plugin) and global (WP) language files
		$local_mo  = plugin_dir_path( __FILE__ ) . "/languages/{$mo}";
		$global_mo = WP_LANG_DIR . "/e20rlicense/{$mo}";

		// Load global version first
		load_textdomain( "e20rlicense", $global_mo );

		// Load local version second
		load_textdomain( "e20rlicense", $local_mo );
	}

	/**
	 * Connect to the license server using TLS 1.2
	 *
	 * @param $handle - File handle for the pipe to the CURL process
	 */
	public function force_tls_12( $handle ) {

		// set the CURL option to use.
		curl_setopt( $handle, CURLOPT_SSLVERSION, 6 );
	}

	public function enqueue_scripts() {

		wp_enqueue_script( 'e20r-license-admin', plugins_url( '/js/e20r-license-admin.js', __FILE__ ), array( 'jquery' ), E20R_LICENSE_VERSION, true );
	}
}