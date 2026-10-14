<?php

declare( strict_types=1 );

namespace ProfitKoro\Connector;

use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OrderEvents {

	// When one request changes an order in several ways, it is sent once, as the most significant of them.
	private const EVENT_RANK = [
		'order.updated'         => 0,
		'order.shipment_linked' => 1,
		'order.refunded'        => 2,
		'order.status_changed'  => 3,
		'order.created'         => 4,
	];

	/** @var array<int, string> */
	private static array $changed = [];

	/** @var array<int, true> */
	private static array $deleted = [];

	/** @var array<int, true> */
	private static array $refunds_removed = [];

	public static function init(): void {
		// Persist attribution before later checkout handlers process the order.
		add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'on_checkout' ], 5, 1 );
		// Block checkout uses the Store API hook instead of the classic checkout hook.
		add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'on_checkout' ], 5, 1 );

		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'on_status_changed' ], 10, 4 );
		// Partial refunds do not trigger a status change and require their own event.
		add_action( 'woocommerce_order_refunded', [ __CLASS__, 'on_refunded' ], 10, 2 );
		add_action( 'woocommerce_refund_deleted', [ __CLASS__, 'on_refund_deleted' ], 10, 2 );
		// Refunds deleted by code skip the admin hook above, and once deleted no longer know their order.
		add_filter( 'woocommerce_pre_delete_order_refund', [ __CLASS__, 'on_refund_deleting' ], 10, 2 );

		// Editing items changes no status, so any save of the order is a possible change.
		add_action( 'woocommerce_update_order', [ __CLASS__, 'on_order_saved' ], 10, 1 );
		add_action( 'woocommerce_saved_order_items', [ __CLASS__, 'on_order_saved' ], 10, 1 );
		add_action( 'woocommerce_ajax_order_items_removed', [ __CLASS__, 'on_items_removed' ], 10, 4 );
		add_action( 'woocommerce_ajax_order_items_added', [ __CLASS__, 'on_items_added' ], 10, 2 );

		add_action( 'woocommerce_trash_order', [ __CLASS__, 'on_order_saved' ], 10, 1 );
		add_action( 'woocommerce_untrash_order', [ __CLASS__, 'on_order_saved' ], 10, 1 );
		add_action( 'woocommerce_before_delete_order', [ __CLASS__, 'on_before_delete' ], 10, 1 );
		// Stores still on WordPress post storage trash and delete orders as posts.
		add_action( 'trashed_post', [ __CLASS__, 'on_post_trashed' ], 10, 1 );
		add_action( 'untrashed_post', [ __CLASS__, 'on_post_trashed' ], 10, 1 );
		add_action( 'before_delete_post', [ __CLASS__, 'on_before_delete_post' ], 10, 1 );

		add_action( 'shutdown', [ __CLASS__, 'flush' ] );
	}

	public static function on_checkout( $order_id ): void {
		$order = self::resolve_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$token = ClickCapture::current_token();
		if ( '' !== $token ) {
			$order->update_meta_data( Settings::META_CLICK_ID, $token );
			$order->add_order_note(
				sprintf(
					/* translators: %s: Affiliate click ID recorded on the order. */
					__( 'ProfitKoro: affiliate click captured (%s).', 'profitkoro-connector' ),
					$token
				)
			);
		} else {
			$order->update_meta_data(
				Settings::META_CLICK_MISSING,
				ClickCapture::current_missing_reason()
			);
		}
		$order->save();

		self::changed( $order->get_id(), 'order.created' );
	}

	public static function on_status_changed( $order_id, $from, $to, $order = null ): void {
		self::changed( $order_id, 'order.status_changed' );
	}

	public static function on_refunded( $order_id, $refund_id ): void {
		self::changed( $order_id, 'order.refunded' );
	}

	public static function on_refund_deleted( $refund_id, $order_id ): void {
		self::$refunds_removed[ (int) $order_id ] = true;
		self::changed( $order_id, 'order.refunded' );
	}

	public static function on_refund_deleting( $check, $refund = null ) {
		if ( null === $check && $refund instanceof \WC_Order_Refund ) {
			self::$refunds_removed[ (int) $refund->get_parent_id() ] = true;
			self::changed( $refund->get_parent_id(), 'order.refunded' );
		}
		return $check;
	}

	public static function on_order_saved( $order_id ): void {
		self::changed( $order_id, 'order.updated' );
	}

	public static function on_items_removed( $item_id, $item = null, $changed_stock = null, $order = null ): void {
		if ( $order instanceof WC_Order ) {
			self::changed( $order->get_id(), 'order.updated' );
		}
	}

	public static function on_items_added( $added_items, $order = null ): void {
		if ( $order instanceof WC_Order ) {
			self::changed( $order->get_id(), 'order.updated' );
		}
	}

	public static function on_post_trashed( $post_id ): void {
		if ( 'shop_order' === get_post_type( $post_id ) ) {
			self::changed( $post_id, 'order.updated' );
		}
	}

	public static function on_before_delete_post( $post_id ): void {
		if ( 'shop_order' === get_post_type( $post_id ) ) {
			self::on_before_delete( $post_id );
		}
	}

	// Sent at once: the order no longer exists by the time the request ends.
	public static function on_before_delete( $order_id ): void {
		$order = self::resolve_order( $order_id );
		if ( ! $order || isset( self::$deleted[ $order->get_id() ] ) ) {
			return;
		}
		self::$deleted[ $order->get_id() ] = true;
		unset( self::$changed[ $order->get_id() ] );

		self::dispatch( $order, 'order.updated', 'deleted' );
	}

	// Saving tracking does not change order status, so the admin form must dispatch a shipment event.
	public static function report_shipment_linked( WC_Order $order ): void {
		self::changed( $order->get_id(), 'order.shipment_linked' );
	}

	// Runs once the request has finished changing orders, so every change is reported in one complete snapshot.
	public static function flush(): void {
		$changed       = self::$changed;
		self::$changed = [];

		foreach ( $changed as $order_id => $event_type ) {
			// The order's cached refund list would still include the deleted refund.
			if ( isset( self::$refunds_removed[ $order_id ] ) && function_exists( 'wc_delete_shop_order_transients' ) ) {
				wc_delete_shop_order_transients( $order_id );
			}
			$order = self::resolve_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			try {
				self::dispatch( $order, $event_type );
			} catch ( \Throwable $error ) {
				// Shutdown is past the point where an error can be shown, and must not break the page.
				Logger::record( '', $event_type, 0, 'event could not be built: ' . $error->getMessage(), 0, (int) $order_id );
			}
		}
	}

	private static function changed( $order_id, string $event_type ): void {
		$order_id = $order_id instanceof WC_Order ? $order_id->get_id() : (int) $order_id;
		if ( $order_id <= 0 || isset( self::$deleted[ $order_id ] ) ) {
			return;
		}
		$current = self::$changed[ $order_id ] ?? null;
		if ( null === $current || self::EVENT_RANK[ $event_type ] > self::EVENT_RANK[ $current ] ) {
			self::$changed[ $order_id ] = $event_type;
		}
	}

	private static function dispatch( WC_Order $order, string $event_type, ?string $status = null ): void {
		if ( ! Settings::is_enabled() ) {
			return;
		}
		// Exclude non-affiliate orders to avoid sharing unrelated customer transactions.
		if ( ! self::is_attributed( $order ) ) {
			return;
		}

		// Each change gets its own ID, so returning to an earlier status is not taken for a repeat.
		// A retry resends the queued payload, which keeps the ID it already has.
		$payload = PayloadBuilder::build( $order, $event_type, wp_generate_uuid4() );
		if ( null !== $status ) {
			$payload['order_status'] = $status;
			Transmitter::enqueue( $payload );
			return;
		}

		// Saves that change nothing reported, such as recording a delivery result, send nothing.
		$snapshot = self::snapshot( $payload );
		if ( $snapshot === (string) $order->get_meta( Settings::META_LAST_SNAPSHOT ) ) {
			return;
		}
		$order->update_meta_data( Settings::META_LAST_SNAPSHOT, $snapshot );
		$order->save_meta_data();

		Transmitter::enqueue( $payload );
	}

	private static function snapshot( array $payload ): string {
		unset( $payload['event_id'], $payload['event_type'], $payload['occurred_at'], $payload['connector'] );
		return hash( 'sha256', serialize( $payload ) );
	}

	private static function is_attributed( WC_Order $order ): bool {
		return '' !== (string) $order->get_meta( Settings::META_CLICK_ID );
	}

	private static function resolve_order( $order_id ): ?WC_Order {
		$order = $order_id instanceof WC_Order ? $order_id : wc_get_order( $order_id );
		return $order instanceof WC_Order ? $order : null;
	}
}
