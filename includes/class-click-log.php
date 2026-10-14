<?php

declare( strict_types=1 );

namespace ProfitKoro\Connector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ClickLog {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'profitkoro_clicks';
	}

	public static function install_table(): void {
		global $wpdb;
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				click_id CHAR(36) NOT NULL,
				landing_path VARCHAR(255) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY click_id (click_id),
				KEY created_at (created_at)
			) {$collate};"
		);
	}

	public static function record( string $click_id, string $landing_path ): void {
		global $wpdb;
		$table = self::table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from the trusted WordPress prefix.
		// Reloading the landing page repeats the click ID; only the first visit is kept.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (click_id, landing_path, created_at) VALUES (%s, %s, %s)",
				$click_id,
				mb_substr( $landing_path, 0, 255 ),
				current_time( 'mysql', true )
			)
		);
		// Clicks older than the attribution cookie can no longer lead to an order.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - Settings::cookie_days() * DAY_IN_SECONDS )
			)
		);
		// phpcs:enable
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
