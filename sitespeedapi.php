<?php
/**
 * Plugin Name: Site Speed API
 * Description: Registers a REST API endpoint that returns the site name and a cached page load speed indicator with lazy background refresh, secured with a shared secret.
 * Version: 1.3.0
 * Author: Joseph Shown
 * License: GPL2+
 * Text Domain: site-speed-api
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Speed_API {

	// Cache key for transient storage
	private $cache_key  = 'site_speed_api_data';

	// Cache freshness window in seconds
	private $cache_ttl  = 3600; // 1 hour

	// Unique WP-Cron hook name
	private $cron_hook  = 'site_speed_api_refresh_event';

	// Shared secret key for authentication (change this)
	private $secret_key = 'ParadigmSecretKey25';

	// Constructor – hook into REST API and cron
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_api_routes' ] );
		add_action( $this->cron_hook, [ $this, 'refresh_speed_cache' ] );
	}

	// Register the REST API endpoint
	public function register_api_routes() {
		register_rest_route(
			'site-speed/v1',
			'/status',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_site_speed' ],
				'permission_callback' => [ $this, 'check_secret_key' ],
			]
		);
	}

	// Permission check for secret key
	public function check_secret_key( $request ) {
		$key = $request->get_param( 'key' );

		// Compare safely with hash_equals
		if ( is_string( $key ) && hash_equals( $this->secret_key, $key ) ) {
			return true;
		}

		// Deny if missing or incorrect
		return new WP_Error(
			'rest_forbidden',
			__( 'Invalid or missing API key.', 'site-speed-api' ),
			[ 'status' => 403 ]
		);
	}

	// Main API callback
	public function get_site_speed() {
		$data = get_transient( $this->cache_key );

		// No cache yet – return pending and schedule refresh
		if ( false === $data ) {
			$data = [
				'site_name' => get_bloginfo( 'name' ),
				'speed_ms'  => null,
				'status'    => 'pending',
			];
			$this->schedule_refresh();
		} else {
			// Cache exists – check freshness
			$cache_age = time() - ( $data['timestamp'] ?? 0 );
			if ( $cache_age >= $this->cache_ttl ) {
				$this->schedule_refresh();
			}
			unset( $data['timestamp'] );
		}

		return $data;
	}

	// Schedule background refresh if not already pending
	private function schedule_refresh() {
		if ( ! wp_next_scheduled( $this->cron_hook ) ) {
			wp_schedule_single_event( time() + 10, $this->cron_hook );
		}
	}

	// Refresh the cached speed measurement
	public function refresh_speed_cache() {
		$start_time = microtime(true);
		$response   = wp_remote_get( home_url(), [ 'timeout' => 10 ] );
		$end_time   = microtime(true);

		$load_speed = is_wp_error( $response ) ? null : round( ( $end_time - $start_time ) * 1000, 2 );

		$data = [
			'site_name' => get_bloginfo( 'name' ),
			'speed_ms'  => $load_speed,
			'timestamp' => time(),
		];

		// Store with TTL * 2 so we never serve "no data"
		set_transient( $this->cache_key, $data, $this->cache_ttl * 2 );
	}
}

// Initialize the plugin
new Site_Speed_API();
