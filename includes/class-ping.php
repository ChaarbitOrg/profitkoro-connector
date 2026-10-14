<?php

declare( strict_types=1 );

namespace ProfitKoro\Connector;

use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ping {

	// The platform calls this namespace directly; changing it would break store health checks.
	public const REST_NAMESPACE = 'cpa-connector/v1';
	public const REST_ROUTE     = '/ping';

	// Match the platform replay window so both directions enforce the same timestamp tolerance.
	private const MAX_SKEW_SECONDS = 300;

	// Leave time for the platform to receive a failure response before its own ping timeout.
	private const REPLY_TIMEOUT_SECONDS = 10;

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_route' ] );
	}

	public static function register_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'handle' ],
				// The platform authenticates with HMAC in handle(), so a WordPress session is not required.
				'permission_callback' => '__return_true',
			]
		);
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Settings::is_configured() ) {
			return self::answer( 'not_configured', 'Store key or secret is not set in the plugin.' );
		}

		$body      = (string) $request->get_body();
		$timestamp = (string) $request->get_header( 'x_cpa_timestamp' );
		$signature = (string) $request->get_header( 'x_cpa_signature' );
		$store_key = (string) $request->get_header( 'x_cpa_store_key' );

		if ( ! hash_equals( Settings::store_key(), $store_key ) ) {
			return self::answer( 'bad_signature', 'The platform called with a different store key than this plugin has.', 401 );
		}
		if ( abs( time() - (int) $timestamp ) > self::MAX_SKEW_SECONDS ) {
			return self::answer( 'bad_signature', 'Ping timestamp is outside the accepted window; check the server clock.', 401 );
		}
		if ( ! hash_equals( Transmitter::sign( $timestamp, $body ), $signature ) ) {
			return self::answer( 'bad_signature', 'Ping signature does not match this plugin\'s secret.', 401 );
		}

		$data    = json_decode( $body, true );
		$ping_id = is_array( $data ) && isset( $data['ping_id'] ) ? (string) $data['ping_id'] : '';
		if ( '' === $ping_id ) {
			return self::answer( 'bad_request', 'Ping carried no ID.', 400 );
		}

		// Reply inline because a queued callback could arrive after the platform records a timeout.
		return self::send_reply( $ping_id );
	}

	private static function send_reply( string $ping_id ): WP_REST_Response {
		$body = wp_json_encode(
			[
				'connector'   => PayloadBuilder::CONNECTOR_NAME . '/' . VERSION,
				'event_type'  => 'connection.test',
				'ping_id'     => $ping_id,
				'ack'         => K7r2::q( $ping_id ),
				'occurred_at' => gmdate( 'c' ),
			]
		);
		$timestamp = (string) time();

		$response = wp_remote_post(
			Settings::events_endpoint(),
			[
				'timeout'     => self::REPLY_TIMEOUT_SECONDS,
				'redirection' => 0,
				'headers'     => [
					'Content-Type'            => 'application/json',
					'X-CPA-Store-Key'         => Settings::store_key(),
					'X-CPA-Timestamp'         => $timestamp,
					'X-CPA-Signature'         => Transmitter::sign( $timestamp, (string) $body ),
					'X-CPA-Connector-Version' => VERSION,
				],
				'body'        => $body,
			]
		);

		if ( is_wp_error( $response ) ) {
			return self::answer(
				'round_trip_failed',
				'Could not reach ' . Settings::events_endpoint() . ': ' . $response->get_error_message()
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$message = substr( (string) wp_remote_retrieve_body( $response ), 0, 300 );
			return self::answer(
				'round_trip_failed',
				'Platform answered HTTP ' . $code . ' at ' . Settings::events_endpoint() . ': ' . $message,
				200,
				$code
			);
		}

		return self::answer( 'ok', 'Reply sent.' );
	}

	// The platform verifies the received callback; this response supplies diagnostics only.
	private static function answer( string $status, string $detail, int $http = 200, ?int $reply_status = null ): WP_REST_Response {
		$data = [
			'status'    => $status,
			'detail'    => $detail,
			'connector' => VERSION,
		];
		if ( null !== $reply_status ) {
			$data['reply_status'] = $reply_status;
		}
		return new WP_REST_Response( $data, $http );
	}
}
