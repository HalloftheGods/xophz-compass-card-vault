<?php
/**
 * Scoped Consignor Dashboard View.
 * Displays only the logged-in consignor's active items, sales history, and pending payouts.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/admin/partials
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

$current_user = wp_get_current_user();
$consignor    = Card_Vault_Consignments::get_consignor_by_user_id( $current_user->ID );

if ( ! $consignor && ! empty( $current_user->user_email ) ) {
	$matches = Card_Vault_Consignments::get_consignors( array( 'search' => $current_user->user_email ) );
	if ( ! empty( $matches ) ) {
		$consignor = $matches[0];
	}
}

?>

<div class="wrap cv-portal-wrap">
	<?php if ( ! $consignor ) : ?>
		<div class="cv-header">
			<div>
				<h1>
					<span>My Card Vault</span>
					<span class="cv-badge-cyan">Consignor Portal</span>
				</h1>
			</div>
		</div>
		<div class="cv-panel">
			<div class="cv-panel-body" style="text-align: center; padding: 48px 24px;">
				<div style="font-size: 40px; margin-bottom: 12px;">📇</div>
				<h2 style="margin-top: 0; color: #1e293b;">No Linked Consignor Profile</h2>
				<p style="color: #64748b; max-width: 480px; margin: 0 auto 20px auto;">
					Your WordPress account is not yet connected to an active consignor profile. Please contact the shop manager or dealer to link your account.
				</p>
			</div>
		</div>
	<?php else : ?>
		<?php
		$summary          = Card_Vault_Consignments::get_consignor_summary( $consignor['consignor_id'] );
		$active_inventory = Card_Vault_WC_Sync::get_consignor_active_inventory( $consignor['consignor_id'] );
		$payouts          = Card_Vault_Consignments::get_payouts( array(
			'consignor_id' => $consignor['consignor_id'],
			'limit'        => 50,
		) );
		?>

		<div class="cv-header">
			<div>
				<h1>
					<span>Consignor Dashboard: <?php echo esc_html( $consignor['name'] ); ?></span>
					<span class="cv-badge-cyan">Consignor Portal</span>
				</h1>
				<p style="margin: 4px 0 0 0; color: #94a3b8; font-size: 13px;">
					Consignor ID: <code><?php echo esc_html( $consignor['consignor_id'] ); ?></code> &bull;
					Default Split: <strong><?php echo esc_html( number_format( (float) $consignor['default_split_rate'], 1 ) ); ?>%</strong> &bull;
					Payout: <?php echo esc_html( $consignor['payout_method'] ); ?> (<code><?php echo esc_html( $consignor['payout_handle'] ?: 'Standard' ); ?></code>)
				</p>
			</div>
		</div>

		<!-- Consignor Top Metrics -->
		<div class="cv-metric-grid">
			<div class="cv-metric-card">
				<div class="cv-metric-label">Active Cards Listed</div>
				<div class="cv-metric-val"><?php echo esc_html( number_format_i18n( $summary['active_items_count'] ) ); ?></div>
			</div>
			<div class="cv-metric-card">
				<div class="cv-metric-label">Total Cards Sold</div>
				<div class="cv-metric-val"><?php echo esc_html( number_format_i18n( $summary['total_sold_count'] ) ); ?></div>
			</div>
			<div class="cv-metric-card">
				<div class="cv-metric-label">Gross Sales Generated</div>
				<div class="cv-metric-val">$<?php echo esc_html( number_format_i18n( $summary['total_gross_sales'], 2 ) ); ?></div>
			</div>
			<div class="cv-metric-card">
				<div class="cv-metric-label">Pending Payout Balance</div>
				<div class="cv-metric-val" style="color: #ea580c;">$<?php echo esc_html( number_format_i18n( $summary['pending_payout_balance'], 2 ) ); ?></div>
			</div>
			<div class="cv-metric-card">
				<div class="cv-metric-label">Total Earnings Paid Out</div>
				<div class="cv-metric-val" style="color: #16a34a;">$<?php echo esc_html( number_format_i18n( $summary['total_paid_out'], 2 ) ); ?></div>
			</div>
		</div>

		<!-- Active Consigned Inventory Table -->
		<div class="cv-panel">
			<div class="cv-panel-header">
				<span>Your Active Inventory on Display</span>
				<span class="cv-badge-cyan"><?php echo esc_html( count( $active_inventory ) ); ?> Items</span>
			</div>
			<div class="cv-panel-body" style="padding: 0;">
				<?php if ( empty( $active_inventory ) ) : ?>
					<p style="padding: 24px; color: #64748b; margin: 0;">No active consigned cards are currently in stock.</p>
				<?php else : ?>
					<table class="cv-table">
						<thead>
							<tr>
								<th>Card Title</th>
								<th>Set & Number</th>
								<th>Condition / Grade</th>
								<th>Asking Price</th>
								<th>Stock Quantity</th>
								<th>Status</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $active_inventory as $item ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $item['title'] ); ?></strong></td>
									<td>
										<?php echo esc_html( $item['setName'] ); ?>
										<?php if ( ! empty( $item['cardNumber'] ) ) : ?>
											<span style="color: #64748b;">(#<?php echo esc_html( $item['cardNumber'] ); ?>)</span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( ! empty( $item['isGraded'] ) && ! empty( $item['gradeLabel'] ) ) : ?>
											<span class="cv-status-pill active"><?php echo esc_html( $item['gradeLabel'] ); ?></span>
										<?php else : ?>
											<span class="cv-status-pill"><?php echo esc_html( $item['condition'] ?: 'NM' ); ?></span>
										<?php endif; ?>
									</td>
									<td><strong>$<?php echo esc_html( number_format( (float) $item['askingPrice'], 2 ) ); ?></strong></td>
									<td><?php echo esc_html( $item['quantity'] ); ?></td>
									<td>
										<span class="cv-status-pill <?php echo 'instock' === $item['stockStatus'] ? 'paid' : 'unpaid'; ?>">
											<?php echo 'instock' === $item['stockStatus'] ? 'In Stock' : 'Out of Stock'; ?>
										</span>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<!-- Sales & Payout History Table -->
		<div class="cv-panel">
			<div class="cv-panel-header">
				<span>Sales & Earnings History</span>
				<span class="cv-badge-cyan"><?php echo esc_html( count( $payouts ) ); ?> Transactions</span>
			</div>
			<div class="cv-panel-body" style="padding: 0;">
				<?php if ( empty( $payouts ) ) : ?>
					<p style="padding: 24px; color: #64748b; margin: 0;">No sales transactions have been logged yet.</p>
				<?php else : ?>
					<table class="cv-table">
						<thead>
							<tr>
								<th>Date</th>
								<th>Card Item</th>
								<th>Qty</th>
								<th>Sale Price</th>
								<th>Split %</th>
								<th>Your Payout</th>
								<th>Status</th>
								<th>Payment Info</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $payouts as $p ) : ?>
								<tr>
									<td><?php echo esc_html( substr( $p['created_at'], 0, 10 ) ); ?></td>
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
									<td>
										<span class="cv-status-pill <?php echo 'paid' === $p['payout_status'] ? 'paid' : 'unpaid'; ?>">
											<?php echo esc_html( ucfirst( $p['payout_status'] ) ); ?>
										</span>
									</td>
									<td>
										<?php if ( 'paid' === $p['payout_status'] ) : ?>
											<span style="font-size: 11px; color: #166534;">
												Paid via <?php echo esc_html( $p['payment_method'] ?: 'Cash' ); ?>
												<?php if ( ! empty( $p['payment_reference'] ) ) : ?>
													(Ref: <?php echo esc_html( $p['payment_reference'] ); ?>)
												<?php endif; ?>
											</span>
										<?php else : ?>
											<span style="font-size: 11px; color: #92400e;">Pending Settlement</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>
</div>
