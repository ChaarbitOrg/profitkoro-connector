<?php

declare( strict_types=1 );

namespace ProfitKoro\Connector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	public const DEFAULT_API_BASE = 'https://profitkoro.com';
	public const OPT_CONNECTION = 'profitkoro_connector_connection';

	// Retain option and meta names so existing credentials and order attribution remain available.
	public const OPT_API_BASE   = 'cpa_connector_api_base';
	public const OPT_STORE_KEY  = 'cpa_connector_store_key';
	public const OPT_SECRET     = 'cpa_connector_secret';

	// Retain the token so the platform can enforce its attribution window using the original click time.
	public const COOKIE_DAYS = 90;

	public const COOKIE_NAME = 'cpa_click';

	// Private meta keys keep attribution fields out of the generic custom-field editor.
	public const META_CLICK_ID       = '_cpa_click_id';
	public const META_CLICK_MISSING  = '_cpa_click_missing_reason';
	public const META_LAST_EVENT     = '_cpa_last_event_id';
	public const META_LAST_STATUS    = '_cpa_last_sync_status';
	public const META_LAST_SNAPSHOT  = '_cpa_last_snapshot';
	// Stored on each order line, not the order.
	public const META_LINE_PRODUCT   = '_cpa_line_product';

	public const META_TRACKING_URL   = '_cpa_shipment_tracking_url';

	public static function api_base(): string {
		return (string) apply_filters(
			'profitkoro_connector_api_base',
			get_option( self::OPT_API_BASE, self::DEFAULT_API_BASE )
		);
	}

	public static function store_key(): string {
		return (string) get_option( self::OPT_STORE_KEY, '' );
	}

	public static function secret(): string {
		return (string) get_option( self::OPT_SECRET, '' );
	}

	// Saving credentials enables reporting without a separate switch that could prevent delivery.
	public static function is_enabled(): bool {
		return self::is_configured();
	}

	public static function is_configured(): bool {
		return '' !== self::store_key() && '' !== self::secret();
	}

	public static function cookie_days(): int {
		return self::COOKIE_DAYS;
	}

	public static function events_endpoint(): string {
		return rtrim( self::api_base(), '/' ) . '/api/events';
	}

	public static function connection_state(): array {
		$state = (array) get_option( self::OPT_CONNECTION, [] );
		if ( ( $state['fingerprint'] ?? '' ) !== self::connection_fingerprint() ) {
			return [];
		}
		return $state;
	}

	public static function connection_fingerprint(): string {
		return hash( 'sha256', wp_json_encode( [ self::api_base(), self::store_key(), self::secret() ] ) );
	}
}
