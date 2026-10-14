<?php

declare( strict_types=1 );

namespace ProfitKoro\Connector;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ConnectionTest {

	public const REJECTED = 'credentials_rejected';

	// Credentials are checked before they are saved, so a rejected pair never replaces a working one.
	public static function verify( string $store_key, string $secret ): bool|WP_Error {
		if ( '' === $store_key || '' === $secret ) {
			return new WP_Error( 'missing_credentials', __( 'Enter both the store key and the signing secret.', 'profitkoro-connector' ) );
		}

		$event_id = wp_generate_uuid4();
		$body     = wp_json_encode(
			[
				'event_type' => 'connection.setup',
				'event_id'   => $event_id,
				'connector'  => PayloadBuilder::CONNECTOR_NAME . '/' . VERSION,
				'site_url'   => home_url( '/' ),
			]
		);
		$timestamp = (string) time();
		$response = wp_remote_post(
			Settings::events_endpoint(),
			[
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => [
					'Content-Type'            => 'application/json',
					'X-CPA-Store-Key'         => $store_key,
					'X-CPA-Timestamp'         => $timestamp,
					'X-CPA-Signature'         => Transmitter::sign( $timestamp, $body, $secret ),
					'X-CPA-Connector-Version' => VERSION,
				],
				'body'        => $body,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'unreachable', __( 'ProfitKoro could not be reached. Check your server connection and try again.', 'profitkoro-connector' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		// A successful HTTP response alone may be a proxy page; require acceptance of this exact test event.
		if ( $code >= 200 && $code < 300 && is_array( $data )
			&& true === ( $data['accepted'] ?? false )
			&& 'connection_ready' === ( $data['status'] ?? '' )
			&& $event_id === ( $data['event_id'] ?? '' ) ) {
			return true;
		}

		if ( 401 === $code ) {
			return new WP_Error( self::REJECTED, __( 'ProfitKoro rejected this store key or signing secret. Copy both again from your merchant panel and check that your server clock is correct.', 'profitkoro-connector' ) );
		}
		return new WP_Error( 'test_rejected', __( 'ProfitKoro did not accept the connection. Please try again or contact support.', 'profitkoro-connector' ) );
	}

	public static function connect( string $store_key, string $secret ): bool|WP_Error {
		$result = self::verify( $store_key, $secret );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_option( Settings::OPT_STORE_KEY, $store_key );
		update_option( Settings::OPT_SECRET, $secret );
		update_option( Settings::OPT_CONNECTION, [ 'fingerprint' => Settings::connection_fingerprint(), 'verified_at' => time() ], false );
		Health::reset();
		return true;
	}
}
