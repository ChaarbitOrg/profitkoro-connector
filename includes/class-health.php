<?php

declare( strict_types=1 );

namespace ProfitKoro\Connector;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Health {

	public const OPTION = 'profitkoro_connector_health';
	public const HOOK   = 'profitkoro_connector_health_check';

	// A single timeout is common on shared hosting; only repeated failures are worth alarming the merchant.
	private const FAILURES_BEFORE_WARNING = 2;

	public static function init(): void {
		add_action( self::HOOK, [ __CLASS__, 'check' ] );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	public static function check(): void {
		if ( empty( Settings::connection_state()['verified_at'] ) ) {
			return;
		}
		$result = ConnectionTest::verify( Settings::store_key(), Settings::secret() );
		if ( is_wp_error( $result ) ) {
			self::record_failure( $result );
		} else {
			self::record_success();
		}
	}

	public static function record_success(): void {
		if ( [] !== get_option( self::OPTION, [] ) ) {
			delete_option( self::OPTION );
		}
	}

	public static function record_failure( WP_Error $error ): void {
		$state = self::state();
		update_option(
			self::OPTION,
			[
				'fingerprint' => Settings::connection_fingerprint(),
				'failures'    => (int) ( $state['failures'] ?? 0 ) + 1,
				'rejected'    => ConnectionTest::REJECTED === $error->get_error_code(),
				'message'     => $error->get_error_message(),
				'checked_at'  => time(),
			],
			false
		);
	}

	public static function reset(): void {
		delete_option( self::OPTION );
	}

	public static function state(): array {
		$state = (array) get_option( self::OPTION, [] );
		// Failures recorded for earlier credentials say nothing about the ones saved now.
		if ( ( $state['fingerprint'] ?? '' ) !== Settings::connection_fingerprint() ) {
			return [];
		}
		return $state;
	}

	public static function is_failing(): bool {
		$state = self::state();
		return ! empty( $state['rejected'] ) || (int) ( $state['failures'] ?? 0 ) >= self::FAILURES_BEFORE_WARNING;
	}
}
