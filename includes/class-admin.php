<?php

declare( strict_types=1 );

namespace ProfitKoro\Connector;

use WC_Order;
use WC_Order_Item_Product;
use WC_Product;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	private const PAGE_SLUG    = 'profitkoro-connector';
	private const NONCE        = 'profitkoro_connector_save';
	private const ORDER_NONCE  = 'profitkoro_connector_order_save';
	private const RESET_NONCE  = 'profitkoro_connector_reset';
	private const RESET_WORD   = 'RESET';
	private const PER_PAGE     = 20;

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_post_profitkoro_connector_save', [ __CLASS__, 'handle_save' ] );
		add_action( 'admin_post_profitkoro_connector_reset', [ __CLASS__, 'handle_reset' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'add_order_meta_box' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), [ __CLASS__, 'add_settings_link' ] );
		add_action( 'admin_notices', [ __CLASS__, 'render_notices' ] );

		// Register both save paths to persist tracking under HPOS and legacy order storage.
		add_action( 'woocommerce_process_shop_order_meta', [ __CLASS__, 'save_order_fields' ], 10, 1 );
		add_action( 'woocommerce_update_order', [ __CLASS__, 'save_order_fields' ], 10, 1 );
	}

	// Tracking edits do not change order status, so they require a separate shipment event.
	public static function save_order_fields( $order_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The order nonce is verified before this field is read.
		if ( ! isset( $_POST['profitkoro_shipment_tracking_url'] ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		check_admin_referer( self::ORDER_NONCE, 'profitkoro_order_nonce' );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$raw = trim( (string) wp_unslash( $_POST['profitkoro_shipment_tracking_url'] ) );
		$new = '' !== $raw ? esc_url_raw( $raw ) : '';

		// Reject invalid tracking links because the platform uses them to verify shipment claims.
		if ( '' !== $new && ! wp_http_validate_url( $new ) ) {
			return;
		}

		$old = trim( (string) $order->get_meta( Settings::META_TRACKING_URL ) );
		if ( $old === $new ) {
			return;
		}

		$order->update_meta_data( Settings::META_TRACKING_URL, $new );
		$order->add_order_note(
			'' !== $new
				/* translators: %s: Courier tracking URL supplied by the merchant. */
				? sprintf( __( 'ProfitKoro: shipment tracking set (%s).', 'profitkoro-connector' ), $new )
				: __( 'ProfitKoro: shipment tracking cleared.', 'profitkoro-connector' )
		);
		$order->save();

		OrderEvents::report_shipment_linked( $order );
	}

	public static function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'ProfitKoro Connector', 'profitkoro-connector' ),
			__( 'ProfitKoro Connector', 'profitkoro-connector' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function render_notices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$screen = get_current_screen();
		$on_plugin_page = $screen && 'woocommerce_page_' . self::PAGE_SLUG === $screen->id;

		if ( empty( Settings::connection_state()['verified_at'] ) ) {
			// The setup screen already says this; repeating it above the form is noise.
			if ( $on_plugin_page ) {
				return;
			}
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
				esc_html__( 'ProfitKoro Connector is not set up.', 'profitkoro-connector' ),
				esc_html__( 'Affiliate orders from this store are not being tracked.', 'profitkoro-connector' ),
				esc_url( self::page_url() ),
				esc_html__( 'Finish setup', 'profitkoro-connector' )
			);
			return;
		}

		if ( ! Health::is_failing() ) {
			return;
		}
		$state = Health::state();
		$detail = ! empty( $state['rejected'] )
			? __( 'ProfitKoro is rejecting this store key or signing secret, so order updates are not being recorded.', 'profitkoro-connector' )
			/* translators: %s: The last connection error, such as a timeout message. */
			: sprintf( __( 'Order updates from this store are not reaching ProfitKoro. Last error: %s', 'profitkoro-connector' ), (string) ( $state['message'] ?? '' ) );
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'ProfitKoro Connector has a connection problem.', 'profitkoro-connector' ),
			esc_html( $detail ),
			esc_url( add_query_arg( 'setup', '1', self::page_url() ) ),
			esc_html__( 'Run setup again', 'profitkoro-connector' )
		);
	}

	public static function add_settings_link( array $links ): array {
		array_unshift(
			$links,
			'<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'profitkoro-connector' ) . '</a>'
		);
		return $links;
	}

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'profitkoro-connector' ) );
		}
		check_admin_referer( self::NONCE );

		$store_key = sanitize_text_field( wp_unslash( $_POST['store_key'] ?? '' ) );
		$secret = sanitize_text_field( wp_unslash( $_POST['secret'] ?? '' ) );
		if ( '' === $secret && '' !== $store_key && $store_key === Settings::store_key() ) {
			$secret = Settings::secret();
		}

		$result = ConnectionTest::connect( $store_key, $secret );
		if ( is_wp_error( $result ) ) {
			set_transient(
				self::setup_error_key(),
				[ 'message' => $result->get_error_message(), 'store_key' => $store_key ],
				5 * MINUTE_IN_SECONDS
			);
			wp_safe_redirect( add_query_arg( 'setup', '1', self::page_url() ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'connected', '1', self::page_url() ) );
		exit;
	}

	public static function handle_reset(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'profitkoro-connector' ) );
		}
		check_admin_referer( self::RESET_NONCE );
		if ( self::RESET_WORD !== sanitize_text_field( wp_unslash( $_POST['confirm'] ?? '' ) ) ) {
			wp_safe_redirect( add_query_arg( 'tab', 'settings', self::page_url() ) );
			exit;
		}

		delete_option( Settings::OPT_STORE_KEY );
		delete_option( Settings::OPT_SECRET );
		delete_option( Settings::OPT_CONNECTION );
		Health::reset();
		ClickLog::clear();
		Logger::clear();

		wp_safe_redirect( self::page_url() );
		exit;
	}

	private static function page_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	private static function setup_error_key(): string {
		return 'profitkoro_connector_setup_' . get_current_user_id();
	}

	public static function enqueue_assets( string $hook ): void {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'profitkoro-connector-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), [], VERSION );
		wp_enqueue_script( 'profitkoro-connector-admin', plugins_url( 'assets/admin.js', PLUGIN_FILE ), [], VERSION, true );
		wp_localize_script( 'profitkoro-connector-admin', 'ProfitKoroConnector', [
			'show' => __( 'Show', 'profitkoro-connector' ),
			'hide' => __( 'Hide', 'profitkoro-connector' ),
		] );
	}

	public static function render_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- These parameters only choose which view to display.
		$verified = ! empty( Settings::connection_state()['verified_at'] );
		$tab = sanitize_key( $_GET['tab'] ?? 'overview' );
		?>
		<div class="wrap pk">
			<h1><?php esc_html_e( 'ProfitKoro Connector', 'profitkoro-connector' ); ?></h1>
			<hr class="wp-header-end" />
			<?php
			if ( $verified && isset( $_GET['connected'] ) ) {
				self::render_onboarding( 'done' );
			} elseif ( ! $verified || isset( $_GET['setup'] ) ) {
				self::render_onboarding( 'connect' );
			} else {
				self::render_tabs( 'settings' === $tab ? 'settings' : 'overview' );
			}
			// phpcs:enable
			?>
		</div>
		<?php
	}

	private static function render_onboarding( string $screen ): void {
		$steps = [
			'connect' => __( 'Configure', 'profitkoro-connector' ),
			'verify'  => __( 'Verify', 'profitkoro-connector' ),
			'done'    => __( 'Ready', 'profitkoro-connector' ),
		];
		$failed = get_transient( self::setup_error_key() );
		delete_transient( self::setup_error_key() );
		$error = is_array( $failed ) ? (string) $failed['message'] : '';
		$store_key = is_array( $failed ) ? (string) $failed['store_key'] : Settings::store_key();
		$has_secret = Settings::is_configured() && $store_key === Settings::store_key();
		$rerun = ! empty( Settings::connection_state()['verified_at'] );
		$number = 0;
		?>
		<div class="pk-shell" id="pk-onboarding" data-screen="<?php echo esc_attr( $screen ); ?>">
			<ol class="pk-steps">
				<?php foreach ( $steps as $key => $label ) : ?>
					<li data-step="<?php echo esc_attr( $key ); ?>" <?php echo $key === $screen ? 'aria-current="step"' : ''; ?>>
						<span class="pk-steps__dot" aria-hidden="true">
							<span class="pk-steps__number"><?php echo (int) ++$number; ?></span>
							<svg class="pk-steps__tick" viewBox="0 0 16 16"><path d="M3.5 8.5l3 3 6-6.5" /></svg>
						</span>
						<span class="pk-steps__label"><?php echo esc_html( $label ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>

			<div class="pk-stage">
				<section class="pk-screen" data-screen-name="connect" aria-labelledby="pk-connect-title">
					<h2 class="pk-title" id="pk-connect-title" tabindex="-1"><?php esc_html_e( 'Connect your store', 'profitkoro-connector' ); ?></h2>
					<p class="pk-lead"><?php esc_html_e( 'Paste the store key and signing secret from your ProfitKoro merchant panel.', 'profitkoro-connector' ); ?></p>
					<?php if ( '' !== $error ) : ?>
						<div class="pk-alert" role="alert"><?php echo esc_html( $error ); ?></div>
					<?php endif; ?>
					<form class="pk-form" id="pk-connect-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="profitkoro_connector_save" />
						<?php wp_nonce_field( self::NONCE ); ?>
						<div class="pk-field">
							<label for="store_key"><?php esc_html_e( 'Store key', 'profitkoro-connector' ); ?></label>
							<input name="store_key" id="store_key" type="text" required autocomplete="off" spellcheck="false"
								value="<?php echo esc_attr( $store_key ); ?>" />
						</div>
						<div class="pk-field">
							<label for="secret"><?php esc_html_e( 'Signing secret', 'profitkoro-connector' ); ?></label>
							<input name="secret" id="secret" type="password" autocomplete="new-password" spellcheck="false"
								<?php echo $has_secret ? 'placeholder="' . esc_attr__( 'Saved — leave blank to keep', 'profitkoro-connector' ) . '"' : 'required'; ?> />
						</div>
						<button type="submit" class="button button-primary pk-button"><?php esc_html_e( 'Continue', 'profitkoro-connector' ); ?></button>
						<?php if ( $rerun ) : ?>
							<a class="pk-back" href="<?php echo esc_url( add_query_arg( 'tab', 'settings', self::page_url() ) ); ?>"><?php esc_html_e( 'Cancel', 'profitkoro-connector' ); ?></a>
						<?php endif; ?>
					</form>
				</section>

				<section class="pk-screen pk-verify" data-screen-name="verify" aria-labelledby="pk-verify-title">
					<span class="pk-spinner" aria-hidden="true"></span>
					<h2 class="pk-title" id="pk-verify-title" tabindex="-1"><?php esc_html_e( 'Checking your credentials', 'profitkoro-connector' ); ?></h2>
					<p class="pk-lead"><?php esc_html_e( 'This takes a few seconds.', 'profitkoro-connector' ); ?></p>
				</section>

				<section class="pk-screen pk-done" data-screen-name="done" aria-labelledby="pk-done-title">
					<svg class="pk-done__mark" viewBox="0 0 56 56" aria-hidden="true">
						<circle class="pk-done__ring" cx="28" cy="28" r="26" />
						<path class="pk-done__tick" d="M17 29l7.5 7.5L39.5 21" />
					</svg>
					<h2 class="pk-title" id="pk-done-title" tabindex="-1"><?php esc_html_e( 'Congratulations!', 'profitkoro-connector' ); ?></h2>
					<p class="pk-lead"><?php esc_html_e( 'Your store is connected. Affiliate orders, refunds and shipment updates now sync automatically.', 'profitkoro-connector' ); ?></p>
					<a class="button button-primary pk-button" href="<?php echo esc_url( self::page_url() ); ?>"><?php esc_html_e( 'Finish', 'profitkoro-connector' ); ?></a>
				</section>
			</div>
		</div>
		<?php
	}

	private static function render_tabs( string $active ): void {
		$tabs = [
			'overview' => __( 'Overview', 'profitkoro-connector' ),
			'settings' => __( 'Settings', 'profitkoro-connector' ),
		];
		?>
		<nav class="pk-tabs" aria-label="<?php esc_attr_e( 'ProfitKoro Connector sections', 'profitkoro-connector' ); ?>">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'tab', $key, self::page_url() ) ); ?>" <?php echo $key === $active ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
		if ( 'settings' === $active ) {
			self::render_settings();
		} else {
			self::render_overview();
		}
	}

	private static function render_overview(): void {
		?>
		<div class="pk-stack pk-stack--wide">
			<?php
			self::render_clicks();
			self::render_events();
			?>
		</div>
		<?php
	}

	private static function render_clicks(): void {
		$page = self::current_page( 'clicks_page' );
		$total = ClickLog::count();
		$clicks = ClickLog::recent( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
		$orders = self::orders_by_click( wp_list_pluck( $clicks, 'click_id' ) );
		?>
		<section class="pk-card" id="pk-clicks">
			<header class="pk-card__head">
				<div>
					<h2 class="pk-card__title"><?php esc_html_e( 'Recent clicks', 'profitkoro-connector' ); ?></h2>
					<p class="pk-card__sub"><?php esc_html_e( 'Visitors who arrived through a ProfitKoro affiliate link.', 'profitkoro-connector' ); ?></p>
				</div>
				<?php self::render_status_badge(); ?>
			</header>
			<?php if ( empty( $clicks ) ) : ?>
				<div class="pk-empty">
					<p class="pk-empty__title"><?php esc_html_e( 'No clicks yet', 'profitkoro-connector' ); ?></p>
					<p><?php esc_html_e( 'When someone opens your store from a ProfitKoro affiliate link, the visit shows up here.', 'profitkoro-connector' ); ?></p>
				</div>
			<?php else : ?>
				<table class="pk-table">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Time', 'profitkoro-connector' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Landing page', 'profitkoro-connector' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Order', 'profitkoro-connector' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $clicks as $click ) : ?>
						<tr>
							<td class="pk-table__time"><?php echo esc_html( self::format_time( $click->created_at ) ); ?></td>
							<td class="pk-table__path"><a href="<?php echo esc_url( home_url( $click->landing_path ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $click->landing_path ); ?></a></td>
							<td><?php self::render_order_cell( $orders[ $click->click_id ] ?? null ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php self::render_pagination( 'clicks_page', $page, $total, 'pk-clicks' ); ?>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_events(): void {
		$page = self::current_page( 'events_page' );
		$total = Logger::count();
		$events = Logger::recent( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
		?>
		<section class="pk-card" id="pk-events">
			<header class="pk-card__head">
				<div>
					<h2 class="pk-card__title"><?php esc_html_e( 'Sent events', 'profitkoro-connector' ); ?></h2>
					<p class="pk-card__sub"><?php esc_html_e( 'Order updates this store sent to ProfitKoro.', 'profitkoro-connector' ); ?></p>
				</div>
			</header>
			<?php if ( empty( $events ) ) : ?>
				<div class="pk-empty">
					<p class="pk-empty__title"><?php esc_html_e( 'Nothing sent yet', 'profitkoro-connector' ); ?></p>
					<p><?php esc_html_e( 'Orders that came through an affiliate link are sent automatically and listed here.', 'profitkoro-connector' ); ?></p>
				</div>
			<?php else : ?>
				<table class="pk-table">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Time', 'profitkoro-connector' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Event', 'profitkoro-connector' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Order', 'profitkoro-connector' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Result', 'profitkoro-connector' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $events as $event ) : ?>
						<?php [ $result_label, $result_tone ] = self::event_result( (int) $event->http_code ); ?>
						<tr>
							<td class="pk-table__time"><?php echo esc_html( self::format_time( $event->created_at ) ); ?></td>
							<td><?php echo esc_html( self::event_label( (string) $event->event_type ) ); ?></td>
							<td><?php self::render_order_cell( ! empty( $event->order_id ) ? wc_get_order( (int) $event->order_id ) : null ); ?></td>
							<td>
								<span class="pk-pill pk-pill--<?php echo esc_attr( $result_tone ); ?>"><?php echo esc_html( $result_label ); ?></span>
								<?php if ( 'success' !== $result_tone && '' !== (string) $event->message ) : ?>
									<span class="pk-table__detail"><?php echo esc_html( mb_substr( (string) $event->message, 0, 140 ) ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php self::render_pagination( 'events_page', $page, $total, 'pk-events' ); ?>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_status_badge(): void {
		if ( Health::is_failing() ) {
			echo '<span class="pk-badge pk-badge--error">' . esc_html__( 'Connection problem', 'profitkoro-connector' ) . '</span>';
			return;
		}
		echo '<span class="pk-badge">' . esc_html__( 'Connected', 'profitkoro-connector' ) . '</span>';
	}

	private static function current_page( string $param ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagination only chooses which rows to display.
		return max( 1, absint( $_GET[ $param ] ?? 1 ) );
	}

	private static function render_pagination( string $param, int $page, int $total, string $anchor ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages <= 1 ) {
			return;
		}
		$link = static fn( int $target ): string => add_query_arg( $param, $target, remove_query_arg( $param ) ) . '#' . $anchor;
		?>
		<nav class="pk-pager" aria-label="<?php esc_attr_e( 'Pages', 'profitkoro-connector' ); ?>">
			<span class="pk-pager__count">
				<?php
				/* translators: 1: Current page number, 2: Total number of pages. */
				echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'profitkoro-connector' ), $page, $pages ) );
				?>
			</span>
			<span class="pk-pager__links">
				<?php if ( $page > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( $link( $page - 1 ) ); ?>"><?php esc_html_e( 'Newer', 'profitkoro-connector' ); ?></a>
				<?php else : ?>
					<span class="button" aria-disabled="true"><?php esc_html_e( 'Newer', 'profitkoro-connector' ); ?></span>
				<?php endif; ?>
				<?php if ( $page < $pages ) : ?>
					<a class="button" href="<?php echo esc_url( $link( $page + 1 ) ); ?>"><?php esc_html_e( 'Older', 'profitkoro-connector' ); ?></a>
				<?php else : ?>
					<span class="button" aria-disabled="true"><?php esc_html_e( 'Older', 'profitkoro-connector' ); ?></span>
				<?php endif; ?>
			</span>
		</nav>
		<?php
	}

	private static function render_order_cell( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			echo '<span class="pk-muted">—</span>';
			return;
		}
		echo '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>'
			. ' <span class="pk-muted">' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</span>';
	}

	private static function format_time( string $utc ): string {
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $utc . ' UTC' ) );
	}

	private static function event_label( string $type ): string {
		$labels = [
			'order.created'         => __( 'Order placed', 'profitkoro-connector' ),
			'order.status_changed'  => __( 'Status changed', 'profitkoro-connector' ),
			'order.refunded'        => __( 'Refund', 'profitkoro-connector' ),
			'order.shipment_linked' => __( 'Tracking added', 'profitkoro-connector' ),
		];
		return $labels[ $type ] ?? $type;
	}

	/** @return array{0: string, 1: string} */
	private static function event_result( int $http_code ): array {
		if ( $http_code >= 200 && $http_code < 300 ) {
			return [ __( 'Delivered', 'profitkoro-connector' ), 'success' ];
		}
		if ( $http_code >= 400 && $http_code < 500 ) {
			return [ __( 'Rejected', 'profitkoro-connector' ), 'error' ];
		}
		return [ __( 'Not delivered', 'profitkoro-connector' ), 'warning' ];
	}

	/**
	 * @param array<int, string> $click_ids
	 * @return array<string, WC_Order>
	 */
	private static function orders_by_click( array $click_ids ): array {
		if ( empty( $click_ids ) ) {
			return [];
		}
		$orders = wc_get_orders(
			[
				'type'       => 'shop_order',
				'limit'      => -1,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded by one page of clicks.
					[
						'key'     => Settings::META_CLICK_ID,
						'value'   => $click_ids,
						'compare' => 'IN',
					],
				],
			]
		);
		$by_click = [];
		foreach ( $orders as $order ) {
			$by_click[ (string) $order->get_meta( Settings::META_CLICK_ID ) ] ??= $order;
		}
		return $by_click;
	}

	private static function render_settings(): void {
		?>
		<div class="pk-stack">
			<section class="pk-card">
				<header class="pk-card__head">
					<h2 class="pk-card__title"><?php esc_html_e( 'Store connection', 'profitkoro-connector' ); ?></h2>
					<?php self::render_status_badge(); ?>
				</header>
				<div class="pk-field">
					<label for="pk-store-key"><?php esc_html_e( 'Store key', 'profitkoro-connector' ); ?></label>
					<input id="pk-store-key" type="text" readonly spellcheck="false" value="<?php echo esc_attr( Settings::store_key() ); ?>" />
				</div>
				<div class="pk-field">
					<label for="pk-secret"><?php esc_html_e( 'Signing secret', 'profitkoro-connector' ); ?></label>
					<div class="pk-reveal">
						<input id="pk-secret" type="password" readonly autocomplete="off" spellcheck="false" value="<?php echo esc_attr( Settings::secret() ); ?>" />
						<button type="button" class="button" data-reveal="pk-secret" aria-controls="pk-secret"><?php esc_html_e( 'Show', 'profitkoro-connector' ); ?></button>
					</div>
				</div>
			</section>

			<section class="pk-card">
				<h2 class="pk-card__title pk-card__title--spaced"><?php esc_html_e( 'Setup', 'profitkoro-connector' ); ?></h2>
				<div class="pk-row">
					<div>
						<p class="pk-row__title"><?php esc_html_e( 'Run setup again', 'profitkoro-connector' ); ?></p>
						<p class="pk-row__text"><?php esc_html_e( 'Connect a different store or replace your credentials. The current connection keeps working until the new one is verified.', 'profitkoro-connector' ); ?></p>
					</div>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'setup', '1', self::page_url() ) ); ?>"><?php esc_html_e( 'Run setup', 'profitkoro-connector' ); ?></a>
				</div>
				<div class="pk-row">
					<div>
						<p class="pk-row__title"><?php esc_html_e( 'Reset plugin', 'profitkoro-connector' ); ?></p>
						<p class="pk-row__text"><?php esc_html_e( 'Remove the saved credentials and history from this site. Orders stop syncing until you connect again.', 'profitkoro-connector' ); ?></p>
					</div>
					<button type="button" class="button pk-button-danger-outline" data-dialog="pk-reset-dialog"><?php esc_html_e( 'Reset plugin', 'profitkoro-connector' ); ?></button>
				</div>
			</section>

		</div>

		<dialog class="pk-dialog" id="pk-reset-dialog" aria-labelledby="pk-reset-title">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="profitkoro_connector_reset" />
				<?php wp_nonce_field( self::RESET_NONCE ); ?>
				<h2 class="pk-dialog__title" id="pk-reset-title"><?php esc_html_e( 'Reset the plugin?', 'profitkoro-connector' ); ?></h2>
				<ul class="pk-dialog__list">
					<li><?php esc_html_e( 'The store key and signing secret are removed from this site.', 'profitkoro-connector' ); ?></li>
					<li><?php esc_html_e( 'New orders will not sync with ProfitKoro until you connect again.', 'profitkoro-connector' ); ?></li>
					<li><?php esc_html_e( 'The click and sent event history is deleted.', 'profitkoro-connector' ); ?></li>
				</ul>
				<div class="pk-field">
					<label for="pk-reset-confirm">
						<?php
						/* translators: %s: The word the merchant must type to confirm the reset. */
						printf( esc_html__( 'Type %s to confirm', 'profitkoro-connector' ), '<strong>' . esc_html( self::RESET_WORD ) . '</strong>' );
						?>
					</label>
					<input id="pk-reset-confirm" name="confirm" type="text" autocomplete="off" spellcheck="false" required
						pattern="<?php echo esc_attr( self::RESET_WORD ); ?>" data-confirm-word="<?php echo esc_attr( self::RESET_WORD ); ?>" />
				</div>
				<div class="pk-dialog__actions">
					<button type="button" class="button" data-dialog-close autofocus><?php esc_html_e( 'Cancel', 'profitkoro-connector' ); ?></button>
					<button type="submit" class="button pk-button-danger" disabled><?php esc_html_e( 'Reset plugin', 'profitkoro-connector' ); ?></button>
				</div>
			</form>
		</dialog>
		<?php
	}

	// HPOS and legacy storage use different admin screen IDs for order meta boxes.
	public static function add_order_meta_box(): void {
		$screen = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
				? wc_get_page_screen_id( 'shop-order' )
				: 'shop_order';

		add_meta_box(
			'profitkoro-connector-attribution',
			__( 'ProfitKoro Attribution', 'profitkoro-connector' ),
			[ __CLASS__, 'render_order_meta_box' ],
			$screen,
			'side',
			'default'
		);
	}

	public static function render_order_meta_box( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}

		$click   = (string) $order->get_meta( Settings::META_CLICK_ID );
		$missing = (string) $order->get_meta( Settings::META_CLICK_MISSING );
		$status  = (string) $order->get_meta( Settings::META_LAST_STATUS );

		echo '<p><strong>' . esc_html__( 'Click ID', 'profitkoro-connector' ) . ':</strong><br />';
		if ( '' !== $click ) {
			echo '<code>' . esc_html( $click ) . '</code>';
		} else {
			echo '<em>' . esc_html( $missing !== '' ? $missing : __( 'none', 'profitkoro-connector' ) ) . '</em>';
		}
		echo '</p>';

		echo '<p><strong>' . esc_html__( 'Sync status', 'profitkoro-connector' ) . ':</strong><br />'
			. esc_html( '' !== $status ? $status : __( 'not sent', 'profitkoro-connector' ) ) . '</p>';

		self::render_tracking_field( $order );

	}

	// Hide tracking for digital orders because they have no shipment to link.
	private static function render_tracking_field( WC_Order $order ): void {
		if ( ! self::order_requires_shipping( $order ) ) {
			echo '<p><em>' . esc_html__( 'Digital order — no shipment needed.', 'profitkoro-connector' ) . '</em></p>';
			return;
		}

		$url = (string) $order->get_meta( Settings::META_TRACKING_URL );
		wp_nonce_field( self::ORDER_NONCE, 'profitkoro_order_nonce' );

		echo '<p><label for="profitkoro_shipment_tracking_url"><strong>'
			. esc_html__( 'Courier tracking URL', 'profitkoro-connector' )
			. '</strong></label><br />';
		echo '<input type="url" id="profitkoro_shipment_tracking_url" name="profitkoro_shipment_tracking_url" '
			. 'class="widefat" placeholder="https://courier.example/track/ABC123" value="'
			. esc_attr( $url ) . '" />';
		echo '</p>';

		if ( '' === $url ) {
			echo '<p class="description">'
				. esc_html__( 'Add a courier tracking link to keep shipment details in sync with ProfitKoro.', 'profitkoro-connector' )
				. '</p>';
		}
	}

	// Match payload shipping rules so mixed orders and deleted products retain the same shipment requirement.
	private static function order_requires_shipping( WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product instanceof WC_Product || $product->needs_shipping() ) {
				return true;
			}
		}
		return false;
	}
}
