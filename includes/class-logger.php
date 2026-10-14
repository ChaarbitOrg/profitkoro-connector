<?php

declare( strict_types=1 );

namespace ProfitKoro\Connector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logger {

	public static function table(): string {
		global $wpdb;
		// Preserve the existing table name so delivery history remains available after upgrades.
		return $wpdb->prefix . 'cpa_connector_log';
	}

	// Keep delivery attempts separate from order notes to prevent retries from cluttering merchant records.
	public static function install_table(): void {
		global $wpdb;
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				event_id VARCHAR(64) NOT NULL DEFAULT '',
				event_type VARCHAR(40) NOT NULL DEFAULT '',
				http_code SMALLINT NOT NULL DEFAULT 0,
				message TEXT NULL,
				attempt SMALLINT NOT NULL DEFAULT 0,
				order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY event_id (event_id),
				KEY created_at (created_at)
			) {$collate};"
		);
	}

	public static function record(
		string $event_id,
		string $event_type,
		int $http_code,
		string $message,
		int $attempt = 0,
		int $order_id = 0
	): void {
		global $wpdb;

		// Limit stored responses because proxy error pages can otherwise produce oversized log entries.
		$wpdb->insert(
			self::table(),
			[
				'event_id'   => substr( $event_id, 0, 64 ),
				'event_type' => substr( $event_type, 0, 40 ),
				'http_code'  => $http_code,
				'message'    => substr( $message, 0, 2000 ),
				'attempt'    => $attempt,
				'order_id'   => $order_id,
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%d', '%s', '%d', '%d', '%s' ]
		);
	}

	/** @return array<int, object> */
	public static function recent( int $limit = 20, int $offset = 0 ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from the trusted WordPress prefix.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $limit, $offset ) );
	}

	public static function count(): int {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from the trusted WordPress prefix.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	public static function clear(): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from the trusted WordPress prefix.
		$wpdb->query( "DELETE FROM {$table}" );
	}
}
