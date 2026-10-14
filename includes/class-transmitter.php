<?php

declare( strict_types=1 );

namespace ProfitKoro\Connector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Transmitter {

	public const ACTION_HOOK = 'profitkoro_connector_send_event';
	public const GROUP       = 'profitkoro-connector';

	private const RETRY_DELAYS = [ 60, 300, 900, 3600, 21600 ];

	public static function init(): void {
		add_action( self::ACTION_HOOK, [ __CLASS__, 'handle' ], 10, 2 );
		// Process events queued before the hook was renamed so upgrades do not lose pending deliveries.
		add_action( 'cpa_connector_send_event', [ __CLASS__, 'handle' ], 10, 2 );
	}

	// Queue the event snapshot so later order edits cannot change the facts being reported.
	public static function enqueue( array $payload, int $attempt = 0 ): void {
		if ( ! Settings::is_enabled() ) {
			Logger::record( $payload['event_id'] ?? '', $payload['event_type'] ?? '', 0, 'connector disabled or unconfigured', $attempt, (int) ( $payload['merchant_order_id'] ?? 0 ) );
			return;
		}

		// Schedule delivery so platform latency does not block checkout.
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time(),
				self::ACTION_HOOK,
				[ 'payload' => $payload, 'attempt' => $attempt ],
				self::GROUP
			);
			return;
		}

		// Deliver directly if Action Scheduler is unavailable to avoid silently dropping the event.
		self::handle( $payload, $attempt );
	}

	public static function handle( array $payload, int $attempt = 0 ): void {
		$body      = wp_json_encode( $payload );
		$timestamp = (string) time();

		if ( false === $body ) {
			Logger::record( $payload['event_id'] ?? '', $payload['event_type'] ?? '', 0, 'payload could not be encoded', $attempt, (int) ( $payload['merchant_order_id'] ?? 0 ) );
			return;
		}

		// Send the exact encoded bytes used for HMAC verification.
		$response = wp_remote_post(
			Settings::events_endpoint(),
			[
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => [
					'Content-Type'            => 'application/json',
					'X-CPA-Store-Key'         => Settings::store_key(),
					'X-CPA-Timestamp'         => $timestamp,
					'X-CPA-Signature'         => self::sign( $timestamp, $body ),
					'X-CPA-Connector-Version' => VERSION,
				],
				'body'        => $body,
			]
		);

		if ( is_wp_error( $response ) ) {
			self::on_failure( $payload, $attempt, 0, $response->get_error_message() );
			return;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$message = (string) wp_remote_retrieve_body( $response );

		if ( $code >= 200 && $code < 300 ) {
			Logger::record( $payload['event_id'] ?? '', $payload['event_type'] ?? '', $code, $message, $attempt, (int) ( $payload['merchant_order_id'] ?? 0 ) );
			self::mark_order_synced( $payload, 'sent' );
			Health::record_success();
			return;
		}

		if ( 401 === $code ) {
			Health::record_failure( new \WP_Error( ConnectionTest::REJECTED, __( 'ProfitKoro rejected this store key or signing secret.', 'profitkoro-connector' ) ) );
		}

		// Stop retrying rejected requests; credentials or payload errors require merchant intervention.
		if ( $code >= 400 && $code < 500 ) {
			Logger::record( $payload['event_id'] ?? '', $payload['event_type'] ?? '', $code, 'permanent failure: ' . $message, $attempt, (int) ( $payload['merchant_order_id'] ?? 0 ) );
			self::mark_order_synced( $payload, 'failed' );
			return;
		}

		self::on_failure( $payload, $attempt, $code, $message );
	}

	private static function on_failure( array $payload, int $attempt, int $code, string $message ): void {
		Health::record_failure( new \WP_Error( 'unreachable', 0 === $code ? $message : sprintf( 'HTTP %d', $code ) ) );
		Logger::record( $payload['event_id'] ?? '', $payload['event_type'] ?? '', $code, $message, $attempt, (int) ( $payload['merchant_order_id'] ?? 0 ) );

		if ( $attempt >= count( self::RETRY_DELAYS ) ) {
			self::mark_order_synced( $payload, 'abandoned' );
			return;
		}

		$delay = self::RETRY_DELAYS[ $attempt ];
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action(
				time() + $delay,
				self::ACTION_HOOK,
				[ 'payload' => $payload, 'attempt' => $attempt + 1 ],
				self::GROUP
			);
		}
		self::mark_order_synced( $payload, 'retrying' );
	}

	// The separator prevents ambiguous timestamp and body concatenations from sharing a signature.
	public static function sign( string $timestamp, string $body, ?string $secret = null ): string {
		return hash_hmac( 'sha256', $timestamp . '.' . $body, $secret ?? Settings::secret() );
	}

	private static function mark_order_synced( array $payload, string $status ): void {
		$order_id = isset( $payload['merchant_order_id'] ) ? (int) $payload['merchant_order_id'] : 0;
		if ( $order_id <= 0 ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$order->update_meta_data( Settings::META_LAST_STATUS, $status );
		$order->update_meta_data( Settings::META_LAST_EVENT, (string) ( $payload['event_id'] ?? '' ) );
		$order->save();
	}
}
