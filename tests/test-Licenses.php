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

/**
 * Class testLicenses
 *
 * Unit tests for the e20rLicense class.
 */
class testLicenses extends WP_UnitTestCase {

	/**
	 *
	 * Default license structure.
	 *		$values = array(
	 *          'fieldname' => array( 'e20r_sequence_A08GQI5B' ),
	 *          'license_key' => array( 'e20r_sequence_A08GQI5B' ),
	 *          'license_email' => array( 'thomas+license@eighty20results.com' ),
	 *          'delete' => array(  'e20r_57dade3586636' ),
	 *          'new_key' => array( null ),
	 *          'new_email' => array( null ),
	 *      );
	 */

	/**
	 * Activate an existing (purchased) license on the remote server
	 */
	public function test_ValidatePurchasedLicense() {

		$input = array(
	           'fieldname' => array( null ),
	           'license_key' => array( null ),
	           'license_email' => array( null ),
	           'delete' => array( null ),
	           'new_key' => array( 'e20r_sequence_A08GQI5B' ),
	           'new_email' => array( 'thomas+license@eighty20results.com' ),
		);

		$lic = e20rLicense::get_instance();

		$settings = $lic->validateLicenseSettings( $input );
		$licenses = $lic->getAllLicenses();

		$key = 'e20r_sequence_A08GQI5B';
		$email = 'thomas+license@eighty20results.com';

		$this->assertArrayHasKey( $key, $licenses, "License e20r_sequence_A08GQI5B not present in list of licenses"  );

		$lic_info = $licenses[ $key ];
		$this->assertArrayHasKey( 'expires', $settings, "Didn't save the upstream license info to the local license list" );

		foreach( $settings as $s ) {

			$this->assertArrayHasKey( 'key', $s, "Didn't save the license info to the local license settings" );
			if ( $key == $s['key'] ) {
				$this->assertEquals( $email, $s['email'] );
			}
		}
	}

	public function test_ActivateSecondLicense() {

		$input = array(
			'fieldname' => array( 'e20r_sequence_A08GQI5B' ),
			'license_key' => array( 'e20r_sequence_A08GQI5B' ),
			'license_email' => array( 'thomas+license@eighty20results.com' ),
			'delete' => array(  null ),
			'new_key' => array( 'e20r_57dade3586636' ),
			'new_email' => array( 'thomas@eighty20results.com' ),
		);

		$lic = e20rLicense::get_instance();

		$settings = $lic->validateLicenseSettings( $input );
		$licenses = $lic->getAllLicenses();

		$key = 'e20r_57dade3586636';
		$email = 'thomas@eighty20results.com';

		$this->assertArrayHasKey( $key, $licenses, "License e20r_sequence_A08GQI5B not present in list of licenses"  );
	}

	public function test_DeactivateSecondLicenseFromSettings() {

		$input = array(
			'fieldname' => array( 'e20r_sequence_A08GQI5B', 'e20r_57dade3586636' ),
			'license_key' => array( 'e20r_sequence_A08GQI5B', 'e20r_57dade3586636' ),
			'license_email' => array( 'thomas+license@eighty20results.com', 'thomas@eighty20results.com' ),
			'delete' => array(  'e20r_57dade3586636' ),
			'new_key' => array( null ),
			'new_email' => array( null ),
		);

		$lic = e20rLicense::get_instance();

		$settings = $lic->validateLicenseSettings( $input );
		$licenses = $lic->getAllLicenses();

		$key = 'e20r_57dade3586636';
		$email = 'thomas@eighty20results.com';

		$this->assertArrayNotHasKey( $key, $licenses, "License e20r_57dade3586636 is still present in list of licenses" );
	}

	public function test_ActivateWithWrongEmail() {

		$input = array(
			'fieldname' => array( null ),
			'license_key' => array( null ),
			'license_email' => array( null ),
			'delete' => array(  null ),
			'new_key' => array( 'e20r_57dade3586636' ),
			'new_email' => array( 'thomas+license@eighty20results.com' ),
		);

		$lic = e20rLicense::get_instance();

		$settings = $lic->validateLicenseSettings( $input );
		$licenses = $lic->getAllLicenses();

		$key = 'e20r_57dade3586636';
		$email = 'thomas+license@eighty20results.com';

		$this->assertArrayNotHasKey( $key, $licenses, "License e20r_57dade3586636 not present in list of licenses"  );
	}

	public function test_DeactivateAndActivateFromSettings() {

		$input = array(
			'fieldname' => array( null ),
			'license_key' => array( null ),
			'license_email' => array( null ),
			'delete' => array(  'e20r_57dade3586636' ),
			'new_key' => array( 'e20r_sequence_A08GQI5B' ),
			'new_email' => array( 'thomas+license@eighty20results.com' ),
		);

		$lic = e20rLicense::get_instance();

		$settings = $lic->validateLicenseSettings( $input );
		$licenses = $lic->getAllLicenses();

		$key1 = 'e20r_57dade3586636';
		$key2 = 'e20r_sequence_A08GQI5B';
		$email = 'thomas+license@eighty20results.com';

		$this->assertArrayNotHasKey( $key1, $licenses, "License e20r_57dade3586636 present in list of licenses"  );
		$this->assertArrayHasKey( $key2, $licenses, "License e20r_sequence_A08GQI5B is NOT present in list of licenses"  );
	}

	/**
	 * Try activating a single-use license for the 2nd time (and expect to fail).
	 */
	public function test_ActivateMaxLicense() {

		$maxStatus = E20R_LICENSE_MAX_DOMAINS;

		$lic = e20rLicense::get_instance();

		$user = array(
			'first_name' => 'Thomas',
			'last_name'  => 'TestUser',
			'email'      => 'thomas+license@eighty20results.com',
		);

		$status = $lic->activateExistingLicenseOnServer( 'e20r_sequence_A08GQI5B', 'Error test of license', $user );

		$this->assertEquals( $maxStatus, $status, "Status didn't match {$maxStatus}" );
	}

	/**
	 * Remove the active state for the specified license
	 */
	public function test_DeregisterLicense() {

		$lic = e20rLicense::get_instance();

		$ln = 'e20r_sequence_A08GQI5B';
		$lk = 'e20r_sequence_A08GQI5B';

		$status = $lic->deactivateExistingLicenseOnServer( $ln, $lk );

		$this->assertTrue( $status, "Unable to deactivate {$lk} license for test domain" );
	}

	public function setUp() {

		if ( ! defined( 'E20R_LICENSE_SERVER_URL' ) ) {
			define( 'E20R_LICENSE_SERVER_URL', 'https://eighty20results.com' );
		}

		if ( ! defined( 'E20R_LICENSE_SECRET_KEY' ) ) {
			define( 'E20R_LICENSE_SECRET_KEY', '5687dc27b50520.33717427' );
		}

		// Define constants related to upstream license status
		if ( !defined('E20R_LICENSE_MAX_DOMAINS' ) ) {
			define( 'E20R_LICENSE_MAX_DOMAINS', 0x10000 );
		}

		if ( !defined('E20R_LICENSE_REGISTERED' ) ) {
			define( 'E20R_LICENSE_REGISTERED', 0x20000 );
		}

		if ( !defined('E20R_LICENSE_ERROR' ) ) {
			define( 'E20R_LICENSE_ERROR', 0x01000 );
		}

		parent::setUp(); // TODO: Change the autogenerated stub
	}
}
