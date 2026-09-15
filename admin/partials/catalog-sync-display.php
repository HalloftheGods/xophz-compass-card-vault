<?php
/**
 * Catalog & Crawler Synchronization Dashboard Partial.
 *
 * Provides real-time MySQL catalog telemetry, selective TCG category/set ingestion,
 * pacing controls, and crawler orchestration.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/admin/partials
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

$db_status         = Card_Vault_Catalog_DB::get_status();
$crawler_status    = Card_Vault_Catalog_Crawler::get_status();
$all_categories    = Card_Vault_Catalog_Crawler::get_available_categories();
$selected_cats     = Card_Vault_Catalog_DB::get_selected_categories();
$sets_scope        = Card_Vault_Catalog_DB::get_sets_scope();
$synced_groups     = $db_status['synced_groups'] ?? array();
$crawler_state     = $crawler_status['status'] ?? 'idle';
$is_running        = 'running' === $crawler_state;
$is_paused         = 'paused' === $crawler_state;

$total_groups_manifest = (int) ( $crawler_status['total_groups'] ?? 0 );
$completed_manifest    = (int) ( $crawler_status['completed_groups'] ?? 0 );
$progress_pct          = $total_groups_manifest > 0 ? min( 100, round( ( $completed_manifest / $total_groups_manifest ) * 100 ) ) : 0;
?>

<!-- Section 1: Catalog Telemetry Strip -->
<div class="cv-metric-grid" style="margin-bottom: 24px;">
	<div class="cv-metric-card">
		<div class="cv-metric-label">Total Cards in MySQL</div>
		<div class="cv-metric-val accent"><?php echo esc_html( number_format_i18n( (int) ( $db_status['total_cards'] ?? 0 ) ) ); ?></div>
		<span style="font-size: 11px; color: #64748b;">Subsite Table: <code><?php echo esc_html( $db_status['table'] ?? '' ); ?></code></span>
	</div>
	<div class="cv-metric-card">
		<div class="cv-metric-label">Table Storage Size</div>
		<div class="cv-metric-val"><?php echo esc_html( $db_status['size_mb'] ?? 0 ); ?> MB</div>
		<span style="font-size: 11px; color: #64748b;">Engine: InnoDB (Subsite Isolated)</span>
	</div>
	<div class="cv-metric-card">
		<div class="cv-metric-label">Synced Sets</div>
		<div class="cv-metric-val"><?php echo esc_html( number_format_i18n( count( $synced_groups ) ) ); ?></div>
		<span style="font-size: 11px; color: #64748b;">Active Reference Sets</span>
	</div>
	<div class="cv-metric-card">
		<div class="cv-metric-label">Crawler Engine</div>
		<div class="cv-metric-val" style="color: <?php echo $is_running ? '#10b981' : ( $is_paused ? '#f59e0b' : '#64748b' ); ?>;">
			<?php echo esc_html( strtoupper( $crawler_state ) ); ?>
		</div>
		<span style="font-size: 11px; color: #64748b;">
			<?php echo $is_running ? esc_html( $crawler_status['remaining_groups'] . ' sets remaining' ) : 'Background Cron Engine'; ?>
		</span>
	</div>
</div>

<!-- Section 2: Crawler Orchestration Panel -->
<div class="cv-panel" style="margin-bottom: 24px;">
	<div class="cv-panel-header">
		<span>Background Catalog Crawler</span>
		<div>
			<?php if ( $is_running ) : ?>
				<span class="cv-badge-cyan" style="background: rgba(16, 185, 129, 0.2); color: #10b981; border-color: rgba(16, 185, 129, 0.4);">
					Active: <?php echo esc_html( $crawler_status['current_group_name'] ?: 'Processing...' ); ?>
				</span>
			<?php else : ?>
				<span class="cv-badge-cyan">Paced Rate-Limiting</span>
			<?php endif; ?>
		</div>
	</div>
	<div class="cv-panel-body">
		<!-- Progress bar if running or paused -->
		<?php if ( $total_groups_manifest > 0 ) : ?>
			<div style="margin-bottom: 16px;">
				<div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 6px; color: #64748b;">
					<span>Queue Progress: <?php echo esc_html( $completed_manifest ); ?> / <?php echo esc_html( $total_groups_manifest ); ?> Sets</span>
					<span><?php echo esc_html( $progress_pct ); ?>% Complete</span>
				</div>
				<div style="background: #e2e8f0; border-radius: 6px; height: 10px; overflow: hidden;">
					<div style="background: #0284c7; width: <?php echo esc_attr( $progress_pct ); ?>%; height: 100%; transition: width 0.3s ease;"></div>
				</div>
				<?php if ( ! empty( $crawler_status['eta_formatted'] ) ) : ?>
					<p style="font-size: 11px; color: #64748b; margin: 6px 0 0 0;">
						Estimated Completion: <strong><?php echo esc_html( $crawler_status['eta_formatted'] ); ?></strong>
						(<?php echo esc_html( $crawler_status['delay_seconds'] ?? 15 ); ?>s delay between sets)
					</p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<!-- Crawler Actions Form -->
		<div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
			<?php if ( ! $is_running ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display: inline;">
					<?php wp_nonce_field( 'cv_crawler_action_nonce' ); ?>
					<input type="hidden" name="cv_admin_action" value="start_crawler_action">
					<button type="submit" class="button button-primary" style="background: #0284c7; border-color: #0284c7; font-weight: 600;">
						<?php echo $is_paused ? esc_html__( 'Resume Crawler', 'xophz-compass-card-vault' ) : esc_html__( 'Start Sync Crawler', 'xophz-compass-card-vault' ); ?>
					</button>
					<label style="margin-left: 8px; font-size: 12px; color: #64748b;">
						<input type="checkbox" name="force_crawl" value="1"> <?php esc_html_e( 'Force Re-sync Existing Sets', 'xophz-compass-card-vault' ); ?>
					</label>
				</form>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display: inline;">
					<?php wp_nonce_field( 'cv_crawler_action_nonce' ); ?>
					<input type="hidden" name="cv_admin_action" value="pause_crawler_action">
					<button type="submit" class="button" style="font-weight: 600;">
						<?php esc_html_e( 'Pause Crawler', 'xophz-compass-card-vault' ); ?>
					</button>
				</form>
			<?php endif; ?>

			<?php if ( $is_running || $is_paused || $total_groups_manifest > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display: inline;">
					<?php wp_nonce_field( 'cv_crawler_action_nonce' ); ?>
					<input type="hidden" name="cv_admin_action" value="reset_crawler_action">
					<button type="submit" class="button button-link-delete" style="font-size: 12px; margin-left: 12px;" onclick="return confirm('Reset the active crawler queue?');">
						<?php esc_html_e( 'Reset Queue', 'xophz-compass-card-vault' ); ?>
					</button>
				</form>
			<?php endif; ?>
		</div>
	</div>
</div>

<!-- Section 3: Category & Set Ingestion Preferences Form -->
<div class="cv-panel" style="margin-bottom: 24px;">
	<div class="cv-panel-header">
		<span>Category Prioritization & Selective Ingestion</span>
		<span style="font-size: 12px; color: #64748b; font-weight: normal;">Control which TCG games are saved in your subsite MySQL database</span>
	</div>
	<div class="cv-panel-body">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<?php wp_nonce_field( 'cv_catalog_settings_nonce' ); ?>
			<input type="hidden" name="cv_admin_action" value="save_catalog_sync_settings">

			<div style="margin-bottom: 20px;">
				<h4 style="margin: 0 0 10px 0; color: #1e293b;"><?php esc_html_e( '1. Active Games / Categories', 'xophz-compass-card-vault' ); ?></h4>
				<p style="font-size: 13px; color: #64748b; margin: 0 0 14px 0;">
					Select the card games your store inventories. Unchecked games will never be crawled or stored in MySQL.
				</p>

				<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px;">
					<?php
					$priority_cids = array( 3, 71, 68, 1, 2, 73, 63 );
					// Sort priority categories to top
					usort( $all_categories, function( $a, $b ) use ( $priority_cids ) {
						$pos_a = array_search( (int) $a['categoryId'], $priority_cids, true );
						$pos_b = array_search( (int) $b['categoryId'], $priority_cids, true );
						if ( false !== $pos_a && false !== $pos_b ) {
							return $pos_a <=> $pos_b;
						}
						if ( false !== $pos_a ) {
							return -1;
						}
						if ( false !== $pos_b ) {
							return 1;
						}
						return strcmp( $a['displayName'], $b['displayName'] );
					} );

					foreach ( $all_categories as $cat ) :
						$cid = (int) $cat['categoryId'];
						$is_checked = in_array( $cid, $selected_cats, true );
					?>
						<label style="display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; cursor: pointer;">
							<input type="checkbox" name="categories[]" value="<?php echo esc_attr( $cid ); ?>" <?php checked( $is_checked ); ?>>
							<span style="font-size: 13px; font-weight: 500; color: #1e293b;">
								<?php echo esc_html( $cat['displayName'] ); ?>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<div style="margin-bottom: 24px;">
				<h4 style="margin: 0 0 10px 0; color: #1e293b;"><?php esc_html_e( '2. Set Ingestion Scope', 'xophz-compass-card-vault' ); ?></h4>
				<p style="font-size: 13px; color: #64748b; margin: 0 0 14px 0;">
					Limit sets to modern active releases to keep your MySQL storage compact and sync times fast.
				</p>

				<div style="display: flex; gap: 16px; flex-wrap: wrap;">
					<?php
					$scopes = array(
						'10'  => __( 'Latest 10 Sets (~2,500 cards per game)', 'xophz-compass-card-vault' ),
						'25'  => __( 'Latest 25 Sets (~6,000 cards per game) [Recommended]', 'xophz-compass-card-vault' ),
						'50'  => __( 'Latest 50 Sets (~12,000 cards per game)', 'xophz-compass-card-vault' ),
						'all' => __( 'All Historical Sets (Full TCG History)', 'xophz-compass-card-vault' ),
					);
					foreach ( $scopes as $val => $label ) :
					?>
						<label style="display: flex; align-items: center; gap: 6px; font-size: 13px; color: #334155; cursor: pointer;">
							<input type="radio" name="sets_scope" value="<?php echo esc_attr( $val ); ?>" <?php checked( $sets_scope, $val ); ?>>
							<span><?php echo esc_html( $label ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<button type="submit" class="button button-primary" style="background: #0284c7; border-color: #0284c7; font-weight: 600;">
				<?php esc_html_e( 'Save Ingestion Preferences', 'xophz-compass-card-vault' ); ?>
			</button>
		</form>
	</div>
</div>

<!-- Section 4: Synced Sets Table -->
<div class="cv-panel">
	<div class="cv-panel-header">
		<span>Currently Synced Sets in MySQL</span>
		<span class="cv-badge-cyan"><?php echo esc_html( count( $synced_groups ) ); ?> Sets Ingested</span>
	</div>
	<div class="cv-panel-body" style="padding: 0;">
		<?php if ( empty( $synced_groups ) ) : ?>
			<p style="padding: 24px; color: #64748b; margin: 0;">
				<?php esc_html_e( 'No card sets have been synced yet. Click "Start Sync Crawler" above to begin populating your local MySQL catalog.', 'xophz-compass-card-vault' ); ?>
			</p>
		<?php else : ?>
			<table class="cv-table">
				<thead>
					<tr>
						<th>Set Name</th>
						<th>Category</th>
						<th>Group ID</th>
						<th>Card Count</th>
						<th>Last Synced</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $synced_groups as $g ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $g['group_name'] ); ?></strong></td>
							<td><span class="cv-badge-cyan"><?php echo esc_html( $g['category_name'] ); ?></span></td>
							<td><code><?php echo esc_html( $g['group_id'] ); ?></code></td>
							<td><?php echo esc_html( number_format_i18n( (int) $g['card_count'] ) ); ?> cards</td>
							<td style="color: #64748b; font-size: 12px;"><?php echo esc_html( $g['last_synced'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>
