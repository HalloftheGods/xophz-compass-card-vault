<?php
/**
 * Dealer and Administrator Management Portal.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/admin/partials
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

$summary      = Card_Vault_Consignments::get_dealer_aggregate_summary();
$active_tab   = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'consignors';
$slug         = get_option( 'xophz_compass_card_vault_custom_slug', 'card-vault' );
$consignors   = Card_Vault_Consignments::get_consignors( array( 'limit' => 100 ) );
$payouts      = Card_Vault_Consignments::get_payouts( array( 'limit' => 100 ) );
$wc_active    = Card_Vault_WC_Sync::is_wc_active();
$portal_url   = admin_url( 'admin.php?page=xophz-compass-card-vault' );
$app_url      = home_url( '/' . $slug );
?>

<div class="wrap cv-portal-wrap">
	<div class="cv-header">
		<div>
			<h1>
				<span>My Card Vault</span>
				<span class="cv-badge-cyan">Dealer HQ</span>
			</h1>
			<p style="margin: 4px 0 0 0; color: #94a3b8; font-size: 13px;">
				Offline-First Trade Desk, Optical Grading, Consignment Accounting & WooCommerce Sync
			</p>
		</div>
		<div style="display: flex; gap: 10px;">
			<a href="<?php echo esc_url( $app_url ); ?>" target="_blank" class="button button-primary" style="background: #0284c7; border-color: #0284c7; font-weight: 600;">
				Open Card Vault POS &rarr;
			</a>
		</div>
	</div>

	<!-- Top Metrics -->
	<div class="cv-metric-grid">
		<div class="cv-metric-card">
			<div class="cv-metric-label">Active Consignors</div>
			<div class="cv-metric-val"><?php echo esc_html( number_format_i18n( $summary['active_consignors_count'] ) ); ?></div>
		</div>
		<div class="cv-metric-card">
			<div class="cv-metric-label">Items Sold</div>
			<div class="cv-metric-val"><?php echo esc_html( number_format_i18n( $summary['total_items_sold'] ) ); ?></div>
		</div>
		<div class="cv-metric-card">
			<div class="cv-metric-label">Gross Sales</div>
			<div class="cv-metric-val accent">$<?php echo esc_html( number_format_i18n( $summary['total_gross_sales'], 2 ) ); ?></div>
		</div>
		<div class="cv-metric-card">
			<div class="cv-metric-label">Pending Liability</div>
			<div class="cv-metric-val" style="color: #ea580c;">$<?php echo esc_html( number_format_i18n( $summary['total_pending_liability'], 2 ) ); ?></div>
		</div>
		<div class="cv-metric-card">
			<div class="cv-metric-label">Total Paid Out</div>
			<div class="cv-metric-val" style="color: #16a34a;">$<?php echo esc_html( number_format_i18n( $summary['total_paid_out'], 2 ) ); ?></div>
		</div>
		<div class="cv-metric-card">
			<div class="cv-metric-label">Dealer Profit</div>
			<div class="cv-metric-val" style="color: #0284c7;">$<?php echo esc_html( number_format_i18n( $summary['total_dealer_profit'], 2 ) ); ?></div>
		</div>
		<div class="cv-metric-card">
			<div class="cv-metric-label">Active Stock (WC)</div>
			<div class="cv-metric-val"><?php echo esc_html( number_format_i18n( $summary['active_inventory_count'] ) ); ?></div>
		</div>
	</div>

	<!-- Navigation Tabs -->
	<nav class="nav-tab-wrapper" style="margin-bottom: 20px;">
		<a href="<?php echo esc_url( add_query_arg( 'tab', 'consignors', $portal_url ) ); ?>" class="nav-tab <?php echo 'consignors' === $active_tab ? 'nav-tab-active' : ''; ?>">
			Consignors (<?php echo esc_html( count( $consignors ) ); ?>)
		</a>
		<a href="<?php echo esc_url( add_query_arg( 'tab', 'payouts', $portal_url ) ); ?>" class="nav-tab <?php echo 'payouts' === $active_tab ? 'nav-tab-active' : ''; ?>">
			Payouts Ledger (<?php echo esc_html( count( $payouts ) ); ?>)
		</a>
		<a href="<?php echo esc_url( add_query_arg( 'tab', 'settings', $portal_url ) ); ?>" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
			Settings & Integration
		</a>
	</nav>

	<?php if ( 'consignors' === $active_tab ) : ?>
		<!-- Tab 1: Consignors -->
		<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
			<div class="cv-panel">
				<div class="cv-panel-header">
					<span>Registered Consignors</span>
					<span class="cv-badge-cyan"><?php echo esc_html( count( $consignors ) ); ?> Active</span>
				</div>
				<div class="cv-panel-body" style="padding: 0;">
					<?php if ( empty( $consignors ) ) : ?>
						<p style="padding: 24px; color: #64748b; margin: 0;">No consignors registered yet. Register your first consignor using the form on the right.</p>
					<?php else : ?>
						<table class="cv-table">
							<thead>
								<tr>
									<th>ID</th>
									<th>Name</th>
									<th>Split Rate</th>
									<th>Payout Handle</th>
									<th>Status</th>
									<th>WP User</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $consignors as $c ) : ?>
									<tr>
										<td><code><?php echo esc_html( $c['consignor_id'] ); ?></code></td>
										<td>
											<strong><?php echo esc_html( $c['name'] ); ?></strong>
											<?php if ( ! empty( $c['email'] ) ) : ?>
												<br><span style="font-size: 11px; color: #64748b;"><?php echo esc_html( $c['email'] ); ?></span>
											<?php endif; ?>
										</td>
										<td><strong><?php echo esc_html( number_format( (float) $c['default_split_rate'], 1 ) ); ?>%</strong></td>
										<td>
											<span style="font-size: 11px;"><?php echo esc_html( $c['payout_method'] ); ?></span>:
											<code><?php echo esc_html( $c['payout_handle'] ?: 'N/A' ); ?></code>
										</td>
										<td>
											<span class="cv-status-pill <?php echo 'active' === $c['status'] ? 'paid' : 'unpaid'; ?>">
												<?php echo esc_html( ucfirst( $c['status'] ) ); ?>
											</span>
										</td>
										<td>
											<?php
											if ( ! empty( $c['wp_user_id'] ) ) {
												$u = get_userdata( $c['wp_user_id'] );
												echo esc_html( $u ? $u->user_login : '#' . $c['wp_user_id'] );
											} else {
												echo '<span style="color: #94a3b8;">None</span>';
											}
											?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>

			<!-- Add Consignor Form -->
			<div class="cv-panel">
				<div class="cv-panel-header">
					<span>Register New Consignor</span>
				</div>
				<div class="cv-panel-body">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
						<?php wp_nonce_field( 'cv_add_consignor_nonce' ); ?>
						<input type="hidden" name="cv_admin_action" value="add_consignor" />

						<p style="margin-top: 0;">
							<label for="cv_name"><strong>Full Name *</strong></label><br>
							<input type="text" id="cv_name" name="name" required class="widefat" placeholder="e.g. Satoshi Tajiri" />
						</p>

						<p>
							<label for="cv_email"><strong>Email Address</strong></label><br>
							<input type="email" id="cv_email" name="email" class="widefat" />
						</p>

						<p>
							<label for="cv_phone"><strong>Phone Number</strong></label><br>
							<input type="text" id="cv_phone" name="phone" class="widefat" />
						</p>

						<p>
							<label for="cv_split"><strong>Default Split Rate (%)</strong></label><br>
							<input type="number" step="0.1" min="0" max="100" id="cv_split" name="default_split_rate" value="85.0" class="widefat" />
							<span style="font-size: 11px; color: #64748b;">Consignor receives this percentage of gross sale.</span>
						</p>

						<p>
							<label for="cv_method"><strong>Payout Method</strong></label><br>
							<select id="cv_method" name="payout_method" class="widefat">
								<option value="Cash">Cash</option>
								<option value="Venmo">Venmo</option>
								<option value="Zelle">Zelle</option>
								<option value="PayPal">PayPal</option>
								<option value="Check">Check</option>
							</select>
						</p>

						<p>
							<label for="cv_handle"><strong>Payout Handle / Account</strong></label><br>
							<input type="text" id="cv_handle" name="payout_handle" class="widefat" placeholder="@handle or phone/email" />
						</p>

						<p>
							<label for="cv_user"><strong>Link to WordPress User</strong></label><br>
							<select id="cv_user" name="wp_user_id" class="widefat">
								<option value="0">None (Standalone Consignor)</option>
								<?php
								$users = get_users( array( 'fields' => array( 'ID', 'user_login', 'display_name' ) ) );
								foreach ( $users as $u ) {
									echo '<option value="' . esc_attr( $u->ID ) . '">' . esc_html( $u->display_name . ' (' . $u->user_login . ')' ) . '</option>';
								}
								?>
							</select>
						</p>

						<p>
							<label for="cv_notes"><strong>Internal Notes</strong></label><br>
							<textarea id="cv_notes" name="notes" rows="3" class="widefat"></textarea>
						</p>

						<p>
							<input type="submit" class="button button-primary" value="Save Consignor" style="width: 100%;" />
						</p>
					</form>
				</div>
			</div>
		</div>

	<?php elseif ( 'payouts' === $active_tab ) : ?>
		<!-- Tab 2: Payouts Ledger -->
		<div class="cv-panel">
			<div class="cv-panel-header">
				<span>Consignment Payouts Ledger</span>
				<span class="cv-badge-cyan"><?php echo esc_html( count( $payouts ) ); ?> Transactions</span>
			</div>
			<div class="cv-panel-body" style="padding: 0;">
				<?php if ( empty( $payouts ) ) : ?>
					<p style="padding: 24px; color: #64748b; margin: 0;">No payout records found. Transactions will appear here as sales occur on the POS or Web storefront.</p>
				<?php else : ?>
					<table class="cv-table">
						<thead>
							<tr>
								<th>Sale ID</th>
								<th>Consignor</th>
								<th>Card Item</th>
								<th>Qty</th>
								<th>Gross Sale</th>
								<th>Split %</th>
								<th>Consignor Payout</th>
								<th>Dealer Profit</th>
								<th>Status</th>
								<th>Source</th>
								<th>Action</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $payouts as $p ) : ?>
								<tr>
									<td><code><?php echo esc_html( $p['sale_record_id'] ); ?></code></td>
									<td>
										<?php
										$consignor = Card_Vault_Consignments::get_consignor( $p['consignor_id'] );
										echo esc_html( $consignor ? $consignor['name'] : $p['consignor_id'] );
										?>
									</td>
									<td>
										<strong><?php echo esc_html( $p['card_name'] ); ?></strong>
										<?php if ( ! empty( $p['set_name'] ) ) : ?>
											<span style="font-size: 11px; color: #64748b;">(<?php echo esc_html( $p['set_name'] ); ?>)</span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $p['quantity'] ); ?></td>
									<td>$<?php echo esc_html( number_format( (float) $p['total_sale_amount'], 2 ) ); ?></td>
									<td><?php echo esc_html( number_format( (float) $p['consignor_split_rate'], 1 ) ); ?>%</td>
									<td><strong style="color: #0284c7;">$<?php echo esc_html( number_format( (float) $p['consignor_payout_amount'], 2 ) ); ?></strong></td>
									<td><strong style="color: #16a34a;">$<?php echo esc_html( number_format( (float) $p['dealer_profit_amount'], 2 ) ); ?></strong></td>
									<td>
										<span class="cv-status-pill <?php echo 'paid' === $p['payout_status'] ? 'paid' : 'unpaid'; ?>">
											<?php echo esc_html( ucfirst( $p['payout_status'] ) ); ?>
										</span>
									</td>
									<td><code><?php echo esc_html( $p['sale_source'] ); ?></code></td>
									<td>
										<?php if ( 'unpaid' === $p['payout_status'] ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display: inline-block;">
												<?php wp_nonce_field( 'cv_mark_paid_nonce' ); ?>
												<input type="hidden" name="cv_admin_action" value="mark_payout_paid" />
												<input type="hidden" name="payout_id" value="<?php echo esc_attr( $p['id'] ); ?>" />
												<input type="hidden" name="payment_method" value="<?php echo esc_attr( $p['payment_method'] ?: 'Cash' ); ?>" />
												<input type="submit" class="button button-small" value="Mark Paid" />
											</form>
										<?php else : ?>
											<span style="font-size: 11px; color: #64748b;">
												Paid <?php echo esc_html( substr( $p['payout_date'] ?? '', 0, 10 ) ); ?>
											</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

	<?php elseif ( 'settings' === $active_tab ) : ?>
		<!-- Tab 3: Settings & Integration -->
		<div class="cv-panel" style="max-width: 720px;">
			<div class="cv-panel-header">
				<span>Integration Status & Deployment Configuration</span>
			</div>
			<div class="cv-panel-body">
				<table class="form-table">
					<tr>
						<th scope="row">WooCommerce Integration</th>
						<td>
							<?php if ( $wc_active ) : ?>
								<span class="cv-status-pill paid">Active & Synchronized</span>
								<p class="description">Products are automatically registered as WooCommerce CPTs. Sold inventory is automatically delisted to prevent double-selling.</p>
							<?php else : ?>
								<span class="cv-status-pill unpaid">Inactive</span>
								<p class="description">WooCommerce is not active. The standalone Card Vault POS functions independently in local mode.</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">Deployment Route Slug</th>
						<td>
							<code><?php echo esc_html( home_url( '/' . $slug ) ); ?></code>
							<p class="description">Access the full-screen Vue 3 + Vite Card Show POS and public QR acrylic showcase stand at this URL.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Master Catalog Optimization</th>
						<td>
							<span class="cv-status-pill active">Zero-Bloat Mode Enabled</span>
							<p class="description">The 20,000+ reference card catalog is retained in lightweight client memory. WooCommerce products are only created for physical active inventory.</p>
						</td>
					</tr>
				</table>
			</div>
		</div>
	<?php endif; ?>
</div>
