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

if ( ! defined( 'E20R_LICENSE_SERVER_URL' ) ) {
	define( 'E20R_LICENSE_SERVER_URL', 'https://eighty20results.com' );
}

if ( ! defined( 'E20R_LICENSE_SECRET_KEY' ) ) {
	define( 'E20R_LICENSE_SECRET_KEY', '5687dc27b50520.33717427' );
}

// Don't redefine the class if it exsits in memory already
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
	 * @var string $option_name The name to use in the WordPress options table
	 */
	private $option_name;

	/**
	 * @var array $defaults The default settings for the Level Setup Fee
	 */
	private $defaults;

	/**
	 * @var array $options Array of levels with setup fee values.
	 */
	private $options;

	/**
	 * @var string $notice_msg The Admin notice (text)
	 */
	private $notice_msg;

	/**
	 * @var string $notice_class The admin notice CSS class
	 */
	private $notice_class;

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

		// Hook into admin_init when we need to.
		if ( ! empty ( $GLOBALS['pagenow'] )
		     && ( 'options-general.php' === $GLOBALS['pagenow']
		          || 'options.php' === $GLOBALS['pagenow']
		     )
		) {
			add_action( 'admin_init', array( $this, 'registerSettings' ) );
		}

		add_action( 'admin_notices', array( $this, 'displayNotice' ) );
		add_action( 'http_api_curl', array( $this, 'force_tls_12' ) );

		if ( class_exists( 'e20rUtils' ) ) {
			$this->utils = e20rUtils::get_instance();
			$this->utils->add_to_autoloader_list( get_class( $this ) );
		}
	}

	/**
	 * Retrieve and initiate the class instance
	 *
	 * @return e20rMembershipSetupFee
	 */
	public static function get_instance() {

		if ( is_null( self::$instance ) ) {
			self::$instance = new self;
		}

		$class = self::$instance;

		return $class;
	}

	/**
	 * Test & register a license
	 * @param string    $name
	 * @param string    $descr
	 */
	public static function registerLicense( $name, $descr ) {

		$class = self::get_instance();

		$licenses = $class->getAllLicenses();

		// Need to add the supplied license.
		if ( !in_array( $name, array_keys( $licenses ) ) ) {

			$class->utils->log("{$name} not found in existing list of licenses");

			// The license is active on the remote Eighty/20 Results license server?
			if ( false === $class->hasActiveLicense( $name ) ) {

				if ( false === $class->activateExistingLicenseOnServer( $name, $descr )) {

					$class->utils->log("{$name} license not active, using trial license");
					// No, we'll add default (trial) license
					$class->addLicense( $name, $class->generateDefaultLicense( $name, $descr ) );
				}
			}
		}
	}

	public function getAllLicenses() {

		if ( empty( $this->license_list ) ) {
			$this->setDefaultLicense();
		}

		return $this->license_list;
	}

	public function loadLicense( $name ) {

		if ( ! empty( $this->license_list[ $name ] ) ) {
			return $this->license_list[ $name ];
		}

		return null;
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
		update_option( $this->option_name, $this->license_list, false );

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

		if ( ! empty( $this->utils ) ) {
			$this->utils->log( "Deleting license: {$name}" );
		}
		// Remove the license information from the local server.
		if ( isset( $this->license_list[ $name ] ) && false === strpos( 'e20r_default_license', $name ) ) {

			delete_transient( "{$this->option_name}_{$name}" );
			unset( $this->license_list[ $name ] );
			update_option( $this->option_name, $this->license_list, false );

			if ( ! empty( $this->utils ) ) {
				$this->utils->log( "License has been removed: {$name}" );
			}

			return true;
		}

		return false;
	}

	private function setDefaultLicense() {

		$this->defaults = array(
			'e20r_default_license' =>
				$this->generateDefaultLicense( 'e20r_default_license', __( "Temporary Update license", "e20rlicense" ) )
		);

		$this->license_list = get_option( $this->option_name );

		if ( ! empty( $this->license_list ) ) {
			$this->license_list = shortcode_atts( $this->defaults, $this->license_list );
		} else {
			$this->license_list = $this->defaults;
		}
	}

	/**
	 * Creates a default license for the specified shortname/product name
	 *
	 * @param null $name
	 * @param null $product_name
	 *
	 * @return array|mixed
	 */
	public function generateDefaultLicense( $name = null, $product_name = null ) {

		if ( is_null( $name ) ) {
			$name = 'e20r_default_license';
		}

		if ( is_null( $product_name ) ) {
			$product_name = __( "Temporary Update license", "e20rlicense" );
		}

		$new_license = array(
			'fulltext_name' => $product_name,
			'key'           => $name,
			'expires'       => strtotime( '+1 week', current_time( 'timestamp' ) ),
			'status'        => 'inactive',
		);


		if ( empty( $this->license_list[ $name ] ) ) {
			return $new_license;
		}

		return $this->license_list[ $name ];
	}

	/**
	 * TODO: Include e20rLicense::isLicenseActive()
	 *
	 * Static wrapper for the checkLicense() function
	 * @param $license_name
	 */
	public static function isLicenseActive( $license_name ) {

		$class = self::get_instance();
		return $class->checkLicense( $license_name );
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
			if ( $this->hasActiveLicense( $name ) ) {

				$msg = sprintf(
					__( "You're currently using the trial license. It will expire <strong>%s</strong>", "e20rlicense" ),
					$expiration_info
				);

				if ( ! empty( $this->utils ) ) {
					$this->utils->log( $msg );
				}

				$this->setNotice( $msg, 'warning' );

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
			$this->setNotice( $msg, 'warning' );

			return false;
		}

		// Is the license active
		if ( $this->hasActiveLicense( $name ) ) {
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
			$this->setNotice( $msg, 'warning' );
		}

		return false;
	}

	/**
	 * Checks if the specified license is an active membership
	 *
	 * @param string        $name       Shortname of license
	 *
	 * @return bool
	 */
	public function hasActiveLicense( $name ) {

		if ( empty( $this->license_list[ $name ] ) ) {
			$this->utils->log( "No license of that name found: {$name}" );

			return false;
		}

		if ( false === $this->verifyLicense( $name, true ) ) {
			$this->utils->log( "Unable to verify license with server {$name}" );

			return false;
		}

		$this->utils->log( "License data: " . print_r( $this->license_list[ $name ], true ) );

		return ( current_time( 'timestamp' ) <= $this->license_list[ $name ]['expires'] );
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

		// Does this license even exist?
		if ( ! isset( $this->license_list[ $name ]['key'] ) ) {
			$this->utils->log( "License info not found for {$name}" );

			return false;
		}

		// Load from transients (cache)
		if ( true === $force || false === ( $license_status = get_transient( "{$this->option_name}_{$name}" ) ) ) {

			$this->utils->log( "No transient found for: {$this->option_name}_{$name}" );

			// Configure request for license check
			$api_params = array(
				'slm_action'  => 'slm_check',
				'secret_key'  => E20R_LICENSE_SECRET_KEY,
				'license_key' => $this->license_list[ $name ]['key'],
			);

			// Send query to the license manager server
			$query    = esc_url_raw( add_query_arg( $api_params, E20R_LICENSE_SERVER_URL ) );
			$response = wp_remote_get( $query, array( 'timeout' => 30, 'sslverify' => true, 'httpversion' => '1.1' ) );

			// Check for error in the response
			if ( is_wp_error( $response ) ) {

				$msg = sprintf( __( "E20R License: %s", "e20rlicense" ), $response->get_error_message() );

				if ( ! empty( $this->utils ) ) {
					$this->utils->log( $msg );
				}

				$this->setNotice( $msg, 'error' );

				return false;
			}

			// var_dump( $response );//uncomment it if you want to look at the full response

			// License data.
			$license_data = json_decode( wp_remote_retrieve_body( $response ) );

			if ( ! empty( $this->utils ) ) {
				$this->utils->log( "License data: " . print_r( $license_data, true ) );
			}

			// License not validated
			if ( 'success' != $license_data->result ) {
				$msg = sprintf( __( "Sorry, you need a valid update license for the %s add-on", "e20rlicense" ), 'E20R Membership Setup Fee' );
				if ( ! empty( $this->utils ) ) {
					$this->utils->log( $msg );
				}
				$this->setNotice( $msg, 'error' );

				return false;
			}

			if ( is_array( $license_data->registered_domains ) ) {

				$this->utils->log("Processing license data for (count: " . count( $license_data->registered_domains ) . " domains )");

				foreach( $license_data->registered_domains as $domain ) {

					if ( isset( $domain->registered_domain ) && $domain->registered_domain == $_SERVER['SERVER_NAME'] ) {

						$this->license_list[ $name ]['fulltext_name'] = $domain->item_reference;
						$this->license_list[ $name ]['expires'] = strtotime( $license_data->date_expiry, current_time( 'timestamp' ) );
						$this->license_list[ $name ]['renewed'] = strtotime( $license_data->date_renewed, current_time( 'timestamp' ) );
						$this->license_list[ $name ]['status']  = $license_data->status;

						$this->license_list[ $name ]['first_name'] = $current_user->user_firstname;
						$this->license_list[ $name ]['last_name']  = $current_user->user_lastname;
						$this->license_list[ $name ]['email']      = $license_data->email;
					}
				}
			}

			if ( false === $this->addLicense( $name, $this->license_list[ $name ] ) ) {

				$msg = sprintf(
					__( "Unable to save license data to WordPress database for %s", "e20rlicense" ),
					$name
				);

				if ( empty( $this->utils ) ) {
					$this->utils->log( $msg );
				}

				$this->setNotice( $msg, 'error' );

				return false;
			}

			if ( 'active' !== $this->license_list[ $name ]['status'] || $this->license_list[ $name ]['expires'] < current_time( 'timestamp' ) ) {
				$msg = sprintf(
					__( "Your update license has expired for the %s add-on!", "e20rlicense" ),
					$this->license_list[ $name ]['fulltext_name']
				);

				if ( ! empty( $this->utils ) ) {
					$this->utils->log( $msg );
				}
				$this->setNotice( $msg, 'error' );

				return false;
			}

			// Doesn't really matter what the status of the transient update is.
			set_transient( "{$this->option_name}_{$name}", "{$name}_license_is_valid", DAY_IN_SECONDS );

			return true;
		} else {

			if ( "{$name}_license_is_valid" === $license_status ) {

				if ( ! empty( $this->utils ) ) {
					$this->utils->log( "Valid license found for {$name}" );
				}

				return true;
			}
		}

		$msg = sprintf( __( "Sorry, you need a valid update license for the %s add-on", "e20rlicense" ), 'E20R Membership Setup Fee' );

		if ( ! empty( $this->utils ) ) {
			$this->utils->log( $msg );
		}

		$this->setNotice( $msg, 'error' );

		return false;
	}

	/**
	 * Default User settings for the license.
	 *
	 * @return array
	 */
	private function defaultUserSettings() {
		global $current_user;

		return array(
			'first_name' => $current_user->user_firstname,
			'last_name'  => $current_user->user_lastname,
			'email'      => $current_user->user_email,
		);
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

		if ( ! empty( $this->utils ) ) {
			$this->utils->log( "Attempting to activate {$name} on remote server" );
		}

		if ( empty( $user_settings ) ) {

			// Default settings (not ideal)
			$user_settings = $this->defaultUserSettings();
		}

		if ( empty( $this->license_list[ $name ] ) ) {

			if ( ! empty( $this->utils ) ) {
				$this->utils->log( "Have to generate a default license for now" );
			}

			$this->license_list[ $name ]        = $this->generateDefaultLicense( $name, $product_name );
			$this->license_list[ $name ]['key'] = $name;
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

		if ( ! empty( $this->utils ) ) {
			$this->utils->log( "Transmitting...: " . print_r( $api_params, true ) );
		}
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
			if ( ! empty( $this->utils ) ) {
				$this->utils->log( "Unexpected Error! The server request returned with an error." );
			}
		}

		// License data.
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! empty( $this->utils ) ) {
			$this->utils->log( "License data received: " . print_r( $license_data, true ) );
		}

		$this->verifyLicense( $name, $product_name );

		if ( ! empty( $this->utils ) ) {
			$this->utils->log( "Activated license {$name}." );
		}

		return $this->addLicense( $name, $this->license_list[ $name ] );
	}

	/**
	 * Deactivate the license on the remote license server
	 *
	 * @param string $name License name/key.
	 *
	 * @return bool
	 */
	public function deactivateExistingLicenseOnServer( $name ) {

		if ( ! empty( $this->utils ) ) {
			$this->utils->log( "Attempting to deactivate {$name} on remote server" );
		}

		$api_params = array(
			'slm_action'        => 'slm_deactivate',
			'license_key'       => $this->license_list[ $name ]['key'],
			'secret_key'        => E20R_LICENSE_SECRET_KEY,
			'registered_domain' => $_SERVER['SERVER_NAME'],
			'item_reference'    => urlencode( $name ),
			'status'            => 'pending'
		);

		if ( ! empty( $this->utils ) ) {
			$this->utils->log( "Transmitting...: " . print_r( $api_params, true ) );
		}
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
			if ( ! empty( $this->utils ) ) {
				$this->utils->log( "Unexpected Error! The server request returned with an error." );
			}
		}

		// License data.
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! empty( $this->utils ) ) {
			$this->utils->log( "License data received: " . print_r( $license_data, true ) );
		}

		if ( ! empty( $this->utils ) ) {
			$this->utils->log( "Removing license {$name}..." );
		}

		return $this->deleteLicense( $name );

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
			$this->option_name, // group, used for settings_fields()
			$this->option_name,  // option name, used as key in database
			array( $this, 'validateLicenseSettings' )      // validation callback
		);

		add_settings_section(
			'e20r_license_section',
			__( "Update Licenses", "e20rlicense" ),
			array( $this, 'showLicenseSection' ),
			'e20r-license'
		);

		foreach ( $this->license_list as $key => $settings ) {

			if ( $key !== 'e20r_default_license' ) {

				add_settings_field(
					"e20r_license_{$key}",
					$settings['fulltext_name'],
					array( $this, 'showLicenseKeyInput' ),
					'e20r-license',
					'e20r_license_section',
					array(
						'label_for'   => $key,
						'option_name' => $this->option_name,
						'name'        => $key,
						'input_type'  => 'password',
						'value'       => $settings['key'],
						'placeholder' => sprintf( __( "Paste/enter the %s license key here", "e20rlicense" ), $settings['fulltext_name'] )
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
				'option_name' => $this->option_name,
				'name'        => 'new_license',
				'input_type'  => 'password',
				'value'       => null,
				'placeholder' => __( 'Enter the new license key here', 'e20rlicense' )
			)
		);
	}

	/**
	 * Validate the license specific settings as they're being saved
	 * @param $values
	 *
	 * @return array|mixed|void
	 */
	public function validateLicenseSettings( $values ) {

		global $current_user;

		$this->utils->log( 'License settings: ' . print_r( $values, true ) );

		$this->license_list = get_option( $this->option_name );
		$this->utils->log( "Saved list: " . print_r( $this->license_list, true ) );

		//<input name=e20rlicense[e20r_test_license_1]" type="password" id="e20r_test_license_1" value="<license_key>" placeholder="Paste/enter the license key here" class="regular_text">

		/**
		 * Array: array ( 'e20r_test_license_1' => 'test_license_1_key' );
		 */
		$keys = array_keys( $this->license_list );
		$user_settings = array(
			'first_name' => $current_user->user_firstname,
			'last_name' => $current_user->user_lastname,
			'email' => $current_user->user_email
		);

		foreach( $values as $key => $v ) {

			if ( 'name' === $key ) {
				$license_id = $values[$key];

				if ( !in_array( $license_id, $keys ) ) {

					// Need to add this license to the list of licenses to process.
					$this->activateExistingLicenseOnServer( $license_id, '' ,$user_settings );
				}
			}

		}

		// Processed and updated the license list, so we can return it as the savable option.
		return $this->license_list;
	}

	/**
	 * License management page (Settings)
	 */
	public function licensePage() {

		if ( ! function_exists( "current_user_can" ) || ( ! current_user_can( "manage_options" ) && ! current_user_can( "e20r_license" ) ) ) {
			wp_die( __( "You are not permitted to perform this action.", "e20rlicense" ) );
		}
		?>
		<h2><?php echo $GLOBALS['title']; ?></h2>
		<form action="options.php" method="POST">
			<?php
			settings_fields( $this->option_name );
			do_settings_sections( 'e20r-license' );
			submit_button();
			?>
		</form>
		<?php

		foreach ( $this->license_list as $key => $license ) {

			$license_valid = $this->verifyLicense( $key );
			?>

			<div class="wrap"><?php
				if ( $license['expires'] <= current_time( 'timestamp' ) || empty( $license['expires'] ) ) {
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
	 * @param array $args   Arguments used to configure input field(s)
	 */
	public function showLicenseKeyInput( $args ) {

		/**
		'label_for'   => $key,
		'option_name' => $this->option_name,
		'name'        => $key,
		'input_type'  => 'password',
		'value'       => $settings['key'],
		'placeholder' => sprintf( __( "Paste/enter the %s license key here", "e20rlicense" ), $settings['fulltext_name'] )
		 */

		// printf( '<label for="%s">%s</label>', $args['label_for'], $args['label'] );
		printf( '<input type="hidden" name="%1$s[name]" value="%2$s" />', $this->option_name, $args['name'] );
		printf(
			'<input name="%1$s[%2$s]" type="%3$s" id="%4$s" value="%5$s" placeholder="%6$s" class="regular_text">',
			$args['option_name'],
			$args['name'],
			$args['input_type'],
			$args['label_for'],
			$args['value'],
			$args['placeholder']
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
	 * Save the admin notice text and severity (class) to use
	 *
	 * @param string $message Text to use in admin notice
	 * @param string $severity CSS class for admin notices (see link for appropriate class names)
	 *
	 * @url     https://codex.wordpress.org/Plugin_API/Action_Reference/admin_notices
	 */
	private function setNotice( $message, $severity = 'notice-info' ) {

		if ( !empty( $this->utils )) {
			$this->utils->set_notice( $message, $severity );
		} else {
			$this->notice_msg[]   = $message;
			$this->notice_class[] = $severity;
		}
	}

	/**
	 * Show wp-admin error/warning/notices
	 */
	public function displayNotice() {

		if (!empty( $this->utils ) ) {
			$this->utils->display_notice();
		} else {

			if ( ! empty( $this->notice_msg ) && is_admin() ) {

				foreach ( $this->notice_msg as $key => $msg )
					?>
					<div class="notice notice-<?php echo $this->notice_class[ $key ]; ?>">
				<p><strong><?php echo ucfirst( $this->notice_class[ $key ] ); ?></strong>: <?php echo $msg; ?></p>
				</div>
				<?php
			}
		}

		$this->notice_msg   = array();
		$this->notice_class = array();
	}

}