<?php

declare( strict_types=1 );

namespace ProfitKoro\Connector {

	class Settings {

		public const META_CLICK_ID      = '_cpa_click_id';
		public const META_CLICK_MISSING = '_cpa_click_missing_reason';
		public const META_LAST_STATUS   = '_cpa_last_sync_status';
		public const META_LAST_SNAPSHOT = '_cpa_last_snapshot';
		public const META_TRACKING_URL  = '_cpa_shipment_tracking_url';

		public static bool $enabled = true;

		public static function is_enabled(): bool {
			return self::$enabled;
		}
	}

	class PayloadBuilder {

		public static function build( \WC_Order $order, string $event_type, string $event_id ): array {
			return [
				'event_type'        => $event_type,
				'event_id'          => $event_id,
				'occurred_at'       => (string) microtime( true ),
				'merchant_order_id' => (string) $order->get_id(),
				'order_status'      => $order->get_status(),
				'items'             => $order->items,
			];
		}
	}

	class Transmitter {

		public static array $queue = [];

		public static function enqueue( array $payload ): void {
			self::$queue[] = $payload;
		}
	}

	class Logger {

		public static function record(): void {}
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

		public array $items = [ [ 'product_id' => '91', 'quantity' => 2 ] ];

		private array $meta = [];

		public function __construct( private int $id, private string $status, string $click_id = 'click-1' ) {
			$this->meta[ Settings::META_CLICK_ID ] = $click_id;
		}

		public function get_id(): int {
			return $this->id;
		}

		public function get_status(): string {
			return $this->status;
		}

		public function set_status( string $status ): void {
			$this->status = $status;
		}

		public function get_meta( string $key ) {
			return $this->meta[ $key ] ?? '';
		}

		public function update_meta_data( string $key, $value ): void {
			$this->meta[ $key ] = $value;
		}

		public function save_meta_data(): void {}
	}

	$orders = [];

	function wc_get_order( $id ) {
		global $orders;
		return $orders[ (int) $id ] ?? false;
	}

	function get_post_type( $id ): string {
		return 'shop_order';
	}

	class WC_Order_Refund {

		public function __construct( private int $parent_id ) {}

		public function get_parent_id(): int {
			return $this->parent_id;
		}
	}

	$cleared = [];

	function wc_delete_shop_order_transients( $id ): void {
		global $cleared;
		$cleared[] = (int) $id;
	}

	function wp_generate_uuid4(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	function check( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}

	function sent(): array {
		OrderEvents::flush();
		$queue                = Transmitter::$queue;
		Transmitter::$queue   = [];
		return $queue;
	}

	require dirname( __DIR__ ) . '/includes/class-order-events.php';

	$order       = new WC_Order( 10, 'processing' );
	$orders[10]  = $order;

	OrderEvents::on_status_changed( 10, 'pending', 'processing', $order );
	OrderEvents::on_order_saved( 10 );
	$events = sent();
	check( 1 === count( $events ), 'Several changes to one order in a request are sent as one event.' );
	check( 'order.status_changed' === $events[0]['event_type'], 'The event is named after its most significant change.' );
	check( 'processing' === $events[0]['order_status'], 'The event carries the order as it is at the end of the request.' );
	$first = $events[0]['event_id'];

	OrderEvents::on_order_saved( 10 );
	check( [] === sent(), 'A save that changes nothing reported sends nothing.' );

	$order->set_status( 'cancelled' );
	OrderEvents::on_status_changed( 10, 'processing', 'cancelled', $order );
	sent();
	$order->set_status( 'processing' );
	OrderEvents::on_status_changed( 10, 'cancelled', 'processing', $order );
	$events = sent();
	check( 1 === count( $events ), 'Returning to an earlier status is sent.' );
	check( $first !== $events[0]['event_id'], 'Returning to an earlier status is a new change with its own ID.' );

	$order->items = [ [ 'product_id' => '91', 'quantity' => 1 ] ];
	OrderEvents::on_items_removed( 5, null, null, $order );
	$events = sent();
	check( 1 === count( $events ) && 'order.updated' === $events[0]['event_type'], 'Editing items without a status change is sent.' );

	$order->items = [];
	OrderEvents::on_refund_deleted( 7, 10 );
	$events = sent();
	check( 1 === count( $events ) && 'order.refunded' === $events[0]['event_type'], 'Deleting a refund is sent.' );

	$order->items = [ [ 'product_id' => '91', 'quantity' => 1, 'refunded' => 1 ] ];
	check( null === OrderEvents::on_refund_deleting( null, new WC_Order_Refund( 10 ) ), 'Refund deletion is never blocked.' );
	$events = sent();
	check( 1 === count( $events ) && 'order.refunded' === $events[0]['event_type'], 'Deleting a refund in code is sent.' );
	check( in_array( 10, $cleared, true ), 'The order is reread without the deleted refund.' );

	OrderEvents::on_refund_deleting( false, new WC_Order_Refund( 10 ) );
	check( [] === sent(), 'A refund deletion another plugin blocked is not sent.' );

	$order->set_status( 'trash' );
	OrderEvents::on_post_trashed( 10 );
	$events = sent();
	check( 1 === count( $events ) && 'trash' === $events[0]['order_status'], 'Trashing an order is sent.' );

	OrderEvents::on_order_saved( 10 );
	OrderEvents::on_before_delete( 10 );
	check( 1 === count( Transmitter::$queue ), 'Deleting an order is sent at once, before the order is gone.' );
	check( 'deleted' === Transmitter::$queue[0]['order_status'], 'A deleted order is reported as deleted.' );
	unset( $orders[10] );
	OrderEvents::on_before_delete_post( 10 );
	check( 1 === count( sent() ), 'A deletion is sent once, and no later change is sent for the deleted order.' );

	$orders[11] = new WC_Order( 11, 'processing', '' );
	OrderEvents::on_status_changed( 11, 'pending', 'processing' );
	check( [] === sent(), 'Orders without an affiliate click are not sent.' );

	Settings::$enabled = false;
	$orders[12]        = new WC_Order( 12, 'processing' );
	OrderEvents::on_status_changed( 12, 'pending', 'processing' );
	check( [] === sent(), 'Nothing is sent while the connector is disabled.' );

	echo "Order event checks passed.\n";
}
