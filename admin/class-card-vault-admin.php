<?php
/**
 * Admin dashboard and role-based portal controller.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/admin
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_Admin {

	/**
	 * Initialize admin hooks and actions.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_form_submissions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Register Card Vault admin menu and Settings sub-page.
	 */
	public function register_admin_menu() {
		// Top-level Dealer Portal menu
		add_menu_page(
			__( 'Card Vault', 'xophz-compass-card-vault' ),
			__( 'Card Vault', 'xophz-compass-card-vault' ),
			'read',
			'xophz-compass-card-vault',
			array( $this, 'render_portal_page' ),
			'dashicons-tickets-alt',
			56
		);

		// Settings page under WordPress Settings (options-general.php)
		add_options_page(
			__( 'Card Vault Settings', 'xophz-compass-card-vault' ),
			__( 'Card Vault', 'xophz-compass-card-vault' ),
			'manage_options',
			'xophz-compass-card-vault',
			array( $this, 'display_plugin_setup_page' )
		);
	}

	/**
	 * Register settings for deployment, routing, margins, and consignment.
	 */
	public function register_settings() {
		register_setting( 'xophz_compass_card_vault_options', 'xophz_compass_card_vault_load_mode' );
		register_setting( 'xophz_compass_card_vault_options', 'xophz_compass_card_vault_custom_slug' );
		register_setting( 'xophz_compass_card_vault_options', 'xophz_compass_card_vault_load_page_id' );
		register_setting( 'xophz_compass_card_vault_options', 'xophz_compass_card_vault_cash_buyout_pct' );
		register_setting( 'xophz_compass_card_vault_options', 'xophz_compass_card_vault_trade_buyout_pct' );
		register_setting( 'xophz_compass_card_vault_options', 'xophz_compass_card_vault_default_split_rate' );
		register_setting( 'xophz_compass_card_vault_options', 'xophz_compass_card_vault_auto_sync_wc' );
	}

	/**
	 * Flush rewrite rules when slug setting changes.
	 *
	 * @param mixed $old_value Old slug.
	 * @param mixed $new_value New slug.
	 */
	public function flush_rewrites_on_save( $old_value, $new_value ) {
		if ( $old_value !== $new_value ) {
			flush_rewrite_rules();
		}
	}

	/**
	 * Enqueue admin styles with Starship aesthetic accents.
	 *
	 * @param string $hook_suffix The current admin screen.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( 'toplevel_page_xophz-compass-card-vault' !== $hook_suffix ) {
			return;
		}

		wp_add_inline_style( 'wp-admin', '
			.cv-portal-wrap {
				max-width: 1280px;
				margin: 20px 20px 40px 0;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
			}
			.cv-header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 24px;
				padding: 16px 20px;
				background: #0d131f;
				border-radius: 8px;
				border-left: 4px solid #62c9ff;
				color: #fff;
			}
			.cv-header h1 {
				color: #fff;
				margin: 0;
				font-size: 22px;
				display: flex;
				align-items: center;
				gap: 10px;
			}
			.cv-badge-cyan {
				background: rgba(98, 201, 255, 0.15);
				color: #62c9ff;
				border: 1px solid rgba(98, 201, 255, 0.3);
				padding: 2px 8px;
				border-radius: 4px;
				font-size: 11px;
				font-weight: 600;
				text-transform: uppercase;
				letter-spacing: 0.5px;
			}
			.cv-metric-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
				gap: 16px;
				margin-bottom: 24px;
			}
			.cv-metric-card {
				background: #fff;
				padding: 16px 20px;
				border-radius: 8px;
				border: 1px solid #e2e8f0;
				box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
			}
			.cv-metric-label {
				font-size: 12px;
				font-weight: 600;
				color: #64748b;
				text-transform: uppercase;
				margin-bottom: 6px;
			}
			.cv-metric-val {
				font-size: 24px;
				font-weight: 700;
				color: #0f172a;
			}
			.cv-metric-val.accent {
				color: #0284c7;
			}
			.cv-panel {
				background: #fff;
				border-radius: 8px;
				border: 1px solid #e2e8f0;
				margin-bottom: 24px;
				box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
				overflow: hidden;
			}
			.cv-panel-header {
				padding: 14px 20px;
				background: #f8fafc;
				border-bottom: 1px solid #e2e8f0;
				font-size: 15px;
				font-weight: 600;
				color: #1e293b;
				display: flex;
				justify-content: space-between;
				align-items: center;
			}
			.cv-panel-body {
				padding: 20px;
			}
			.cv-table {
				width: 100%;
				border-collapse: collapse;
			}
			.cv-table th {
				text-align: left;
				padding: 10px 14px;
				background: #f1f5f9;
				font-size: 12px;
				text-transform: uppercase;
				color: #475569;
				border-bottom: 1px solid #e2e8f0;
			}
			.cv-table td {
				padding: 12px 14px;
				border-bottom: 1px solid #f1f5f9;
				font-size: 13px;
				color: #334155;
			}
			.cv-table tr:hover td {
				background: #f8fafc;
			}
			.cv-status-pill {
				display: inline-block;
				padding: 2px 8px;
				border-radius: 9999px;
				font-size: 11px;
				font-weight: 600;
			}
			.cv-status-pill.paid {
				background: #dcfce7;
				color: #166534;
			}
			.cv-status-pill.unpaid {
				background: #fef3c7;
				color: #92400e;
			}
			.cv-status-pill.active {
				background: #e0f2fe;
				color: #0369a1;
			}
		' );
	}

	/**
	 * Handle admin POST submissions (add consignor, mark payout paid).
	 */
	public function handle_form_submissions() {
		if ( ! is_admin() || empty( $_POST['cv_admin_action'] ) ) {
			return;
		}

		$action = sanitize_text_field( $_POST['cv_admin_action'] );

		// 1. Add Consignor
		if ( 'add_consignor' === $action ) {
			check_admin_referer( 'cv_add_consignor_nonce' );

			if ( ! current_user_can( 'manage_card_vault' ) && ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Permission denied.', 'xophz-compass-card-vault' ) );
			}

			$result = Card_Vault_Consignments::create_consignor( array(
				'name'               => $_POST['name'] ?? '',
				'email'              => $_POST['email'] ?? '',
				'phone'              => $_POST['phone'] ?? '',
				'wp_user_id'         => ! empty( $_POST['wp_user_id'] ) ? (int) $_POST['wp_user_id'] : 0,
				'default_split_rate' => ! empty( $_POST['default_split_rate'] ) ? floatval( $_POST['default_split_rate'] ) : 85.00,
				'payout_method'      => $_POST['payout_method'] ?? 'Cash',
				'payout_handle'      => $_POST['payout_handle'] ?? '',
				'notes'              => $_POST['notes'] ?? '',
			) );

			$redirect_url = add_query_arg(
				array(
					'page'    => 'xophz-compass-card-vault',
					'cv_msg'  => is_wp_error( $result ) ? 'error' : 'consignor_added',
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect_url );
			exit;
		}

		// 2. Mark Payout as Paid
		if ( 'mark_payout_paid' === $action ) {
			check_admin_referer( 'cv_mark_paid_nonce' );

			if ( ! current_user_can( 'manage_card_vault' ) && ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Permission denied.', 'xophz-compass-card-vault' ) );
			}

			$payout_id   = ! empty( $_POST['payout_id'] ) ? (int) $_POST['payout_id'] : 0;
			$method      = sanitize_text_field( $_POST['payment_method'] ?? 'Cash' );
			$reference   = sanitize_text_field( $_POST['payment_reference'] ?? '' );

			if ( $payout_id > 0 ) {
				Card_Vault_Consignments::mark_payout_paid( $payout_id, $method, $reference );
			}

			$redirect_url = add_query_arg(
				array(
					'page'   => 'xophz-compass-card-vault',
					'tab'    => 'payouts',
					'cv_msg' => 'payout_marked_paid',
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect_url );
			exit;
		}
	}

	/**
	 * Dispatch role-based portal view: Dealer / Admin Portal vs Consignor Dashboard.
	 */
	public function render_portal_page() {
		$current_user = wp_get_current_user();
		$is_dealer    = current_user_can( 'manage_card_vault' ) || current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
		$is_consignor = current_user_can( 'view_consignor_dashboard' ) || in_array( 'card_vault_consignor', (array) $current_user->roles, true );

		if ( $is_dealer ) {
			require_once XOPHZ_COMPASS_CARD_VAULT_PATH . 'admin/partials/dealer-portal-display.php';
		} elseif ( $is_consignor ) {
			require_once XOPHZ_COMPASS_CARD_VAULT_PATH . 'admin/partials/consignor-dashboard-display.php';
		} else {
			wp_die( esc_html__( 'You do not have permission to access the Card Vault dashboard.', 'xophz-compass-card-vault' ) );
		}
	}

	/**
	 * Render the Card Vault plugin settings page under Settings -> Card Vault.
	 */
	public function display_plugin_setup_page() {
		$load_mode       = get_option( 'xophz_compass_card_vault_load_mode', 'routes_only' );
		$custom_slug     = get_option( 'xophz_compass_card_vault_custom_slug', 'card-vault' );
		$load_page_id    = (int) get_option( 'xophz_compass_card_vault_load_page_id', 0 );
		$cash_buyout     = (int) get_option( 'xophz_compass_card_vault_cash_buyout_pct', 70 );
		$trade_buyout    = (int) get_option( 'xophz_compass_card_vault_trade_buyout_pct', 80 );
		$def_split_rate  = (float) get_option( 'xophz_compass_card_vault_default_split_rate', 85.0 );
		$auto_sync_wc    = get_option( 'xophz_compass_card_vault_auto_sync_wc', '1' );
		$pages           = get_pages();
		$wc_active       = Card_Vault_WC_Sync::is_wc_active();
		$portal_url      = admin_url( 'admin.php?page=xophz-compass-card-vault' );
		$app_url         = home_url( '/' . $custom_slug );
		?>
		<div class="wrap">
			<h2><?php esc_html_e( 'Xophz Compass Card Vault Settings', 'xophz-compass-card-vault' ); ?></h2>
			<p><?php esc_html_e( 'Configure public web app deployment, Trade Desk margin thresholds, and WooCommerce sync.', 'xophz-compass-card-vault' ); ?></p>

			<div style="margin-bottom: 20px; display: flex; gap: 12px;">
				<a href="<?php echo esc_url( $portal_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Open Dealer HQ Portal', 'xophz-compass-card-vault' ); ?> &rarr;
				</a>
				<a href="<?php echo esc_url( $app_url ); ?>" target="_blank" class="button">
					<?php esc_html_e( 'Launch Card Vault POS', 'xophz-compass-card-vault' ); ?> &rarr;
				</a>
			</div>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'xophz_compass_card_vault_options' );
				do_settings_sections( 'xophz_compass_card_vault_options' );
				?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Deployment & Routing Mode', 'xophz-compass-card-vault' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="xophz_compass_card_vault_load_mode" value="routes_only" <?php checked( $load_mode, 'routes_only' ); ?> />
									<strong><?php esc_html_e( 'Default Route:', 'xophz-compass-card-vault' ); ?></strong> Load at <code>/<?php echo esc_html( $custom_slug ); ?></code>
								</label><br />
								<label>
									<input type="radio" name="xophz_compass_card_vault_load_mode" value="custom_slug" <?php checked( $load_mode, 'custom_slug' ); ?> />
									<strong><?php esc_html_e( 'Custom Slug:', 'xophz-compass-card-vault' ); ?></strong> Load at a designated custom URL slug
								</label><br />
								<label>
									<input type="radio" name="xophz_compass_card_vault_load_mode" value="homepage" <?php checked( $load_mode, 'homepage' ); ?> />
									<strong><?php esc_html_e( 'Site Homepage:', 'xophz-compass-card-vault' ); ?></strong> Override the front page with Card Vault
								</label><br />
								<label>
									<input type="radio" name="xophz_compass_card_vault_load_mode" value="specific_page" <?php checked( $load_mode, 'specific_page' ); ?> />
									<strong><?php esc_html_e( 'Specific Page:', 'xophz-compass-card-vault' ); ?></strong> Target an existing WordPress page
								</label>
							</fieldset>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Custom Deployment Slug', 'xophz-compass-card-vault' ); ?></th>
						<td>
							<input type="text" name="xophz_compass_card_vault_custom_slug" value="<?php echo esc_attr( $custom_slug ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'URL path where Card Vault is accessible (e.g. card-vault for /card-vault or vault for /vault).', 'xophz-compass-card-vault' ); ?></p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Target WordPress Page', 'xophz-compass-card-vault' ); ?></th>
						<td>
							<select name="xophz_compass_card_vault_load_page_id">
								<option value="0"><?php esc_html_e( 'None Selected', 'xophz-compass-card-vault' ); ?></option>
								<?php foreach ( $pages as $page ) : ?>
									<option value="<?php echo (int) $page->ID; ?>" <?php selected( $load_page_id, $page->ID ); ?>>
										<?php echo esc_html( $page->post_title ); ?> (ID: <?php echo (int) $page->ID; ?>)
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Active when Deployment Mode is set to "Specific Page".', 'xophz-compass-card-vault' ); ?></p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Trade Desk Cash Buyout %', 'xophz-compass-card-vault' ); ?></th>
						<td>
							<input type="number" name="xophz_compass_card_vault_cash_buyout_pct" value="<?php echo esc_attr( $cash_buyout ); ?>" min="10" max="100" class="small-text" /> %
							<p class="description"><?php esc_html_e( 'Default percentage of retail market comp offered for cash card buyouts (default: 70%).', 'xophz-compass-card-vault' ); ?></p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Trade Desk Trade Credit %', 'xophz-compass-card-vault' ); ?></th>
						<td>
							<input type="number" name="xophz_compass_card_vault_trade_buyout_pct" value="<?php echo esc_attr( $trade_buyout ); ?>" min="10" max="100" class="small-text" /> %
							<p class="description"><?php esc_html_e( 'Default percentage of retail market comp offered for trade credit buyouts (default: 80%).', 'xophz-compass-card-vault' ); ?></p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'Default Consignor Split Rate %', 'xophz-compass-card-vault' ); ?></th>
						<td>
							<input type="number" step="0.5" name="xophz_compass_card_vault_default_split_rate" value="<?php echo esc_attr( $def_split_rate ); ?>" min="50" max="99" class="small-text" /> %
							<p class="description"><?php esc_html_e( 'Default consignor payout split percentage (e.g. 85% means consignor receives 85%, store retains 15%).', 'xophz-compass-card-vault' ); ?></p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><?php esc_html_e( 'WooCommerce Auto-Sync & Delist', 'xophz-compass-card-vault' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="xophz_compass_card_vault_auto_sync_wc" value="1" <?php checked( '1', $auto_sync_wc ); ?> />
								<?php esc_html_e( 'Automatically register physical card inventory as WooCommerce products and auto-delist when sold offline at card shows.', 'xophz-compass-card-vault' ); ?>
							</label>
							<p class="description">
								<?php
								if ( $wc_active ) {
									echo '<strong style="color: #16a34a;">' . esc_html__( 'WooCommerce is Active.', 'xophz-compass-card-vault' ) . '</strong>';
								} else {
									echo '<span style="color: #64748b;">' . esc_html__( 'WooCommerce is not installed or active.', 'xophz-compass-card-vault' ) . '</span>';
								}
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
