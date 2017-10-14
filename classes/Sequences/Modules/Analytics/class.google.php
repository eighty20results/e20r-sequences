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

namespace E20R\Sequences\Modules\Analytics;

use E20R\Sequences\Sequence\Controller;
use E20R\Licensing\Licensing;
use E20R\Utilities\Utilities;

/**
 * Add Google Analytics tracking by sequence message/notification
 *
 * Class Google
 * @package E20R\Sequences\Modules\Analytics
 */
class Google {
	
	private static $instance = null;
	
	private function __construct() {
	}
	
	/**
	 * Returning the instance
	 *
	 * @return Google
	 *
	 * @since v5.0
	 */
	public static function get_instance() {
		
		if ( is_null( self::$instance ) ) {
			self::$instance = new self;
		}
		
		return self::$instance;
	}
	
	/**
	 * Retrieve the Google Analytics Cookie ID
	 *
	 * @return null - Return the Cookie ID for the Google Analytics cookie
	 */
	public function ga_getCid() {
		
		$contents = $this->ga_parseCookie();
		
		return isset( $contents['cid'] ) ? $contents['cid'] : null;
	}
	
	/**
	 * Get the Tracking ID for Google Analytics (from Sequence settings)
	 *
	 * @param int $sequence_id
	 *
	 * @return string
	 */
	private function getTID( $sequence_id ) {
		
		$controller = Controller::get_instance();
		$tracking_id = $controller->get_option_by_name( 'gaTid' );
		
		return $tracking_id;
	}
	
	/**
	 * Parse the Google Analytics cookie to locate the Client ID info.
	 *
	 * By: Matt Clarke - https://plus.google.com/110147996971766876369/posts/Mz1ksPoBGHx
	 *
	 * @return array
	 */
	public function ga_parseCookie() {
		
		if ( isset( $_COOKIE["_ga"] ) ) {
			
			list( $version, $domainDepth, $cid1, $cid2 ) = preg_split( '/[\.]/i', $_COOKIE["_ga"], 4 );
			
			return array( 'version' => $version, 'domainDepth' => $domainDepth, 'cid' => $cid1 . '.' . $cid2 );
		}
		
		return array();
	}
	
	/**
	 * Add Google Analytics tracking image/URL
	 *
	 * @param int $sequence_id
	 *
	 * @return null|string
	 */
	public function maybeAddGoogleTracking( $sequence_id ) {
		
		$ga_tracking   = null;
		$utils = Utilities::get_instance();
		
		if ( false === Licensing::is_licensed( Controller::plugin_slug ) ) {
			$utils->log("Attempted to use a licensed feature (Google Analytics tracking) without a valid license");
			return $ga_tracking;
		}
		
		$controller = Controller::get_instance();
		$track_with_ga = $controller->get_option_by_name( 'trackGoogleAnalytics' );
		
		if ( ! empty( $track_with_ga ) && ( true === $track_with_ga ) ) {
			
			// FIXME: get_google_analytics_client_id() can't work since this isn't being run during a user session!
			$client_id     = esc_html( $this->ga_getCid() );
			$tracking_id   = esc_html( $this->getTID( $sequence_id ) );
			$sequence      = get_post( $sequence_id );
			$campaign_name = esc_html( $sequence->post_title );
			
			// http://www.google-analytics.com/collect?v=1&tid=UA-12345678-1&cid=CLIENT_ID_NUMBER&t=event&ec=email&ea=open&el=recipient_id&cs=newsletter&cm=email&cn=Campaign_Name
			
			$protocol = ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443 ) ? "https://" : "http://";
			
			if ( ! empty( $client_id ) ) {
				
				//https://strongcubedfitness.com/?utm_source=daily_lesson&utm_medium=email&utm_campaign=vpt
				$tracking_url = sprintf( '%1$s://www.google-analytics.com/collect/v=1&aip=1&ds=lesson&tid=%2$s&cid=%3$s}', $protocol, $tracking_id, $client_id );
				$tracking_url .= sprintf( '&t=event&ec=email&ea=open&el=recipient_id&cs=newsletter&cm=email&cn=%1%s', $campaign_name );
				
				$ga_tracking = sprintf('<img src="%s" >', esc_url_raw( $tracking_url ) );
			}
		}
		
		return $ga_tracking;
	}
}