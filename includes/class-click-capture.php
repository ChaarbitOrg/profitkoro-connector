<?php

declare( strict_types=1 );

namespace ProfitKoro\Connector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ClickCapture {

	public const QUERY_PARAM = 'cpa_click';

	public const REASON_NO_COOKIE = 'no_cookie';
	public const REASON_MALFORMED = 'malformed_token';
	public const REASON_DISABLED  = 'connector_disabled';

	public static function init(): void {
		// Capture the token before redirects or output prevent cookie headers from being sent.
		add_action( 'init', [ __CLASS__, 'capture' ], 1 );
	}

	public static function capture(): void {
		if ( is_admin() || wp_doing_cron() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Affiliate landing requests cannot carry a WordPress nonce.
		$raw = isset( $_GET[ self::QUERY_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_PARAM ] ) ) : '';
		if ( '' === $raw ) {
			return;
		}

		if ( ! self::is_valid_token( $raw ) ) {
			// Preserve the failure reason so malformed landing links can be diagnosed at checkout.
			self::store( '', self::REASON_MALFORMED );
			return;
		}

		self::store( $raw, '' );
		ClickLog::record( $raw, self::landing_path() );
	}

	private static function landing_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		return is_string( $path ) && '' !== $path ? $path : '/';
	}

	// The platform uses UUIDs for click IDs; reject other values before persisting attribution.
	public static function is_valid_token( string $token ): bool {
		return 1 === preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
			$token
		);
	}

	private static function store( string $token, string $reason ): void {
		$expiry = time() + ( Settings::cookie_days() * DAY_IN_SECONDS );
		$value  = '' !== $token ? $token : 'invalid:' . $reason;

		if ( ! headers_sent() ) {
			setcookie(
				Settings::COOKIE_NAME,
				$value,
				[
					'expires'  => $expiry,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				]
			);
		}

		// Expose the token to checkout in the same request before the browser returns the cookie.
		$_COOKIE[ Settings::COOKIE_NAME ] = $value;
	}

	public static function current_token(): string {
		$raw = isset( $_COOKIE[ Settings::COOKIE_NAME ] )
			? sanitize_text_field( wp_unslash( $_COOKIE[ Settings::COOKIE_NAME ] ) )
			: '';

		return self::is_valid_token( $raw ) ? $raw : '';
	}

	public static function current_missing_reason(): string {
		if ( '' !== self::current_token() ) {
			return '';
		}
		$raw = isset( $_COOKIE[ Settings::COOKIE_NAME ] )
			? sanitize_text_field( wp_unslash( $_COOKIE[ Settings::COOKIE_NAME ] ) )
			: '';

		if ( str_starts_with( $raw, 'invalid:' ) ) {
			return substr( $raw, strlen( 'invalid:' ) );
		}
		return self::REASON_NO_COOKIE;
	}
}
