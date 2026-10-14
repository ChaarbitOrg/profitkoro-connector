<?php

declare( strict_types=1 );

namespace ProfitKoro\Connector {

	class Settings {

		public const META_CLICK_ID     = '_cpa_click_id';
		public const META_LAST_STATUS  = '_cpa_last_sync_status';
		public const META_TRACKING_URL = '_cpa_shipment_tracking_url';

		public static bool $enabled = true;

		public static function is_enabled(): bool {
			return self::$enabled;
		}
	}

	class PayloadBuilder {

		public static function build( \WC_Order $order, string $event_type, string $event_id ): array {
			return [
				'event_type'   => $event_type,
				'event_id'     => $event_id,
				'order_status' => $order->get_status(),
			];
		}
	}

	class Transmitter {

		public static array $queue = [];

		public static function enqueue( array $payload ): void {
			self::$queue[] = $payload;
		}
	}
}

namespace {

	use ProfitKoro\Connector\OrderEvents;
	use ProfitKoro\Connector\Settings;
	use ProfitKoro\Connector\Transmitter;

	if ( PHP_SAPI !== 'cli' ) {
		exit;
	}

	define( 'ABSPATH', __DIR__ . '/' );

	class WC_Order {

		public function __construct( private string $status, private string $click_id = 'click-1' ) {}

		public function get_id(): int {
			return 10;
		}

		public function get_status(): string {
			return $this->status;
		}

		public function get_total(): string {
			return '100.00';
		}

		public function get_total_refunded(): string {
			return '0.00';
		}

		public function get_meta( string $key ): string {
			return Settings::META_CLICK_ID === $key ? $this->click_id : '';
		}
	}

	function check( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}

	require dirname( __DIR__ ) . '/includes/class-order-events.php';

	foreach ( [ 'processing', 'on-hold' ] as $status ) {
		$order = new WC_Order( $status );
		OrderEvents::on_status_changed( 10, 'pending', $status, $order );
		$event = Transmitter::$queue[ array_key_last( Transmitter::$queue ) ] ?? null;
		check( null !== $event && $status === $event['order_status'], 'Send the first status change even before the order event is delivered.' );
		check( 'order.status_changed' === $event['event_type'], 'A status change keeps its event type.' );

		OrderEvents::on_status_changed( 10, 'pending', $status, $order );
		$retry = Transmitter::$queue[ array_key_last( Transmitter::$queue ) ];
		check( $event['event_id'] === $retry['event_id'], 'Repeating the same change reuses its event ID.' );
	}

	$count = count( Transmitter::$queue );
	OrderEvents::on_status_changed( 10, 'pending', 'processing', new WC_Order( 'processing', '' ) );
	check( count( Transmitter::$queue ) === $count, 'Orders without an affiliate click are not sent.' );

	Settings::$enabled = false;
	OrderEvents::on_status_changed( 10, 'pending', 'processing', new WC_Order( 'processing' ) );
	check( count( Transmitter::$queue ) === $count, 'Nothing is sent while the connector is disabled.' );

	echo "Order event checks passed.\n";
}
