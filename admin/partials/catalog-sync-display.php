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
$crawler_state         = $crawler_status['status'] ?? 'idle';
$is_running            = 'running' === $crawler_state;
$is_paused             = 'paused' === $crawler_state;
$is_completed          = 'completed' === $crawler_state;

$total_groups_manifest = (int) ( $crawler_status['total_groups'] ?? 0 );
$completed_manifest    = (int) ( $crawler_status['completed_groups'] ?? 0 );
$remaining_manifest    = (int) ( $crawler_status['remaining_groups'] ?? 0 );
$active_delay_sec      = (int) ( $crawler_status['delay_seconds'] ?? 15 );
$active_target_hours   = (float) ( $crawler_status['target_hours'] ?? 24.0 );
$seconds_until_next    = (int) ( $crawler_status['seconds_until_next'] ?? 0 );
$eta_formatted         = (string) ( $crawler_status['eta_formatted'] ?? '' );
$current_group_name    = (string) ( $crawler_status['current_group_name'] ?? '' );
$current_category_name = (string) ( $crawler_status['current_category_name'] ?? '' );
$recent_queue          = $crawler_status['queue'] ?? array();
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

<!-- Section 2: Crawler Orchestration & Live Queue Panel -->
<div class="cv-panel" style="margin-bottom: 24px;">
	<div class="cv-panel-header">
		<div style="display: flex; align-items: center; gap: 10px;">
			<span style="font-weight: 700;">Mathematical Database Builder & Catalog Queue</span>
			<span class="cv-badge-cyan" style="<?php echo $is_running ? 'background: rgba(16, 185, 129, 0.2); color: #10b981; border-color: rgba(16, 185, 129, 0.4);' : ( $is_paused ? 'background: rgba(245, 158, 11, 0.2); color: #f59e0b; border-color: rgba(245, 158, 11, 0.4);' : '' ); ?>">
				<?php echo $is_running ? 'Active Crawl' : ( $is_paused ? 'Paused' : ( $is_completed ? 'Up to Date' : 'Idle' ) ); ?>
			</span>
		</div>
		<div>
			<?php if ( $is_running && ! empty( $current_group_name ) ) : ?>
				<span style="font-size: 12px; color: #0284c7; font-weight: 600;">
					Ingesting: [<?php echo esc_html( $current_category_name ); ?>] <?php echo esc_html( $current_group_name ); ?>
				</span>
			<?php else : ?>
				<span style="font-size: 12px; color: #64748b;">Rate-Limited Autonomous Sync</span>
			<?php endif; ?>
		</div>
	</div>

	<div class="cv-panel-body">
		<!-- Sub-Telemetry Cards Grid -->
		<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 20px;">
			<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px;">
				<div style="font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase;">Batch Scope</div>
				<div style="font-size: 16px; font-weight: 700; color: #1e293b; margin-top: 2px;">
					<?php echo 'all' === $sets_scope ? 'All Historical Sets' : esc_html( $sets_scope . ' Sets / Game' ); ?>
				</div>
				<span style="font-size: 11px; color: #94a3b8;"><?php echo esc_html( count( $selected_cats ) ); ?> Categories Selected</span>
			</div>

			<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px;">
				<div style="font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase;">Queue Progress</div>
				<div style="font-size: 16px; font-weight: 700; color: #10b981; margin-top: 2px;">
					<?php echo esc_html( $completed_manifest ); ?> / <?php echo esc_html( $total_groups_manifest ); ?> Sets
				</div>
				<span style="font-size: 11px; color: #94a3b8;"><?php echo esc_html( $remaining_manifest ); ?> sets remaining (<?php echo esc_html( $progress_pct ); ?>%)</span>
			</div>

			<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px;">
				<div style="font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase;">Throttle Buffer</div>
				<div style="font-size: 16px; font-weight: 700; color: #d97706; margin-top: 2px;">
					1 Set / <?php echo esc_html( $active_delay_sec >= 60 ? round( $active_delay_sec / 60, 1 ) . ' min' : $active_delay_sec . ' sec' ); ?>
				</div>
				<span style="font-size: 11px; color: #94a3b8;">
					<?php if ( $is_running && $seconds_until_next > 0 ) : ?>
						Buffer: Next set in <strong><?php echo esc_html( $seconds_until_next ); ?>s</strong>
					<?php else : ?>
						Polite rate-limiting
					<?php endif; ?>
				</span>
			</div>

			<div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px;">
				<div style="font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase;">Projected ETA</div>
				<div style="font-size: 15px; font-weight: 700; color: #0284c7; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo esc_attr( $eta_formatted ); ?>">
					<?php echo ! empty( $eta_formatted ) ? esc_html( $eta_formatted ) : 'Calculated on Start'; ?>
				</div>
				<span style="font-size: 11px; color: #94a3b8;">Target: ~<?php echo esc_html( $active_target_hours ); ?>h pacing</span>
			</div>
		</div>

		<!-- Progress bar -->
		<?php if ( $total_groups_manifest > 0 ) : ?>
			<div style="margin-bottom: 20px;">
				<div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 6px; color: #64748b;">
					<span>Queue Manifest: <?php echo esc_html( $completed_manifest ); ?> of <?php echo esc_html( $total_groups_manifest ); ?> Ingested</span>
					<span><strong><?php echo esc_html( $progress_pct ); ?>%</strong></span>
				</div>
				<div style="background: #e2e8f0; border-radius: 6px; height: 10px; overflow: hidden;">
					<div style="background: <?php echo $is_running ? '#0284c7' : '#94a3b8'; ?>; width: <?php echo esc_attr( $progress_pct ); ?>%; height: 100%; transition: width 0.3s ease;"></div>
				</div>
			</div>
		<?php endif; ?>

		<!-- Unified Queue Controls Form -->
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin-bottom: 20px;">
			<?php wp_nonce_field( 'cv_crawler_action_nonce' ); ?>

			<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
				<!-- Control 1: Batch Size -->
				<div>
					<label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;">
						Batch Size (Sets per game)
					</label>
					<select name="batch_size" class="widefat" <?php disabled( $is_running ); ?> style="font-size: 13px;">
						<option value="5" <?php selected( $sets_scope, '5' ); ?>>5 Sets (Gentle Quick Test)</option>
						<option value="10" <?php selected( $sets_scope, '10' ); ?>>10 Sets (~2,500 cards per game)</option>
						<option value="20" <?php selected( $sets_scope, '20' ); ?>>20 Sets (Fast Modern Catalog)</option>
						<option value="25" <?php selected( $sets_scope, '25' ); ?>>25 Sets [Recommended]</option>
						<option value="50" <?php selected( $sets_scope, '50' ); ?>>50 Sets (Aggressive)</option>
						<option value="all" <?php selected( $sets_scope, 'all' ); ?>>All Sets (Complete History)</option>
					</select>
					<span style="font-size: 11px; color: #64748b; display: block; margin-top: 4px;">Sets queued per category</span>
				</div>

				<!-- Control 2: Buffer Delay -->
				<div>
					<label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;">
						Polite Throttle Delay (Buffer)
					</label>
					<select name="delay_seconds" class="widefat" <?php disabled( $is_running ); ?> style="font-size: 13px;">
						<option value="5" <?php selected( $active_delay_sec, 5 ); ?>>5 seconds (Rapid)</option>
						<option value="15" <?php selected( $active_delay_sec, 15 ); ?>>15 seconds (Balanced Default)</option>
						<option value="30" <?php selected( $active_delay_sec, 30 ); ?>>30 seconds (Polite Safe)</option>
						<option value="60" <?php selected( $active_delay_sec, 60 ); ?>>60 seconds / 1 min (Relaxed)</option>
						<option value="120" <?php selected( $active_delay_sec, 120 ); ?>>120 seconds / 2 min (Gentle)</option>
						<option value="300" <?php selected( $active_delay_sec, 300 ); ?>>300 seconds / 5 min (Background)</option>
					</select>
					<span style="font-size: 11px; color: #64748b; display: block; margin-top: 4px;">Delay interval between set requests</span>
				</div>

				<!-- Control 3: Target Hours -->
				<div>
					<label style="display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 6px;">
						Target Completion (Hours)
					</label>
					<input type="number" name="target_hours" min="0.5" max="72" step="0.5" value="<?php echo esc_attr( $active_target_hours ); ?>" class="widefat" <?php disabled( $is_running ); ?> style="font-size: 13px;">
					<span style="font-size: 11px; color: #64748b; display: block; margin-top: 4px;">Pacing target for full queue</span>
				</div>
			</div>

			<!-- Button Bar -->
			<div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
				<?php if ( ! $is_running ) : ?>
					<button type="submit" name="cv_admin_action" value="start_crawler_action" class="button button-primary" style="background: #0284c7; border-color: #0284c7; font-weight: 600; padding: 4px 14px;">
						<?php echo $is_paused ? esc_html__( 'Resume Crawler', 'xophz-compass-card-vault' ) : esc_html__( 'Start Sync Crawler', 'xophz-compass-card-vault' ); ?>
					</button>
				<?php else : ?>
					<button type="submit" name="cv_admin_action" value="pause_crawler_action" class="button button-secondary" style="font-weight: 600; padding: 4px 14px;">
						<?php esc_html_e( 'Pause Crawler', 'xophz-compass-card-vault' ); ?>
					</button>
				<?php endif; ?>

				<!-- Step 1 Set Button -->
				<button type="submit" name="cv_admin_action" value="step_crawler_action" class="button" style="font-weight: 600; padding: 4px 12px;" title="<?php esc_attr_e( 'Ingest exactly 1 pending set right now without starting continuous background cron', 'xophz-compass-card-vault' ); ?>">
					<?php esc_html_e( 'Step Next Set &rarr;', 'xophz-compass-card-vault' ); ?>
				</button>

				<?php if ( $is_running || $is_paused || $total_groups_manifest > 0 ) : ?>
					<button type="submit" name="cv_admin_action" value="reset_crawler_action" class="button button-link-delete" style="font-size: 12px; margin-left: 8px;" onclick="return confirm('Reset the active crawler queue back to idle?');">
						<?php esc_html_e( 'Reset Queue', 'xophz-compass-card-vault' ); ?>
					</button>
				<?php endif; ?>

				<label style="margin-left: auto; font-size: 12px; color: #64748b; cursor: pointer;">
					<input type="checkbox" name="force_crawl" value="1" <?php disabled( $is_running ); ?>> <?php esc_html_e( 'Force Re-sync Existing Sets', 'xophz-compass-card-vault' ); ?>
				</label>
			</div>
		</form>

		<!-- Queue Breakdown Table -->
		<?php if ( ! empty( $recent_queue ) ) : ?>
			<div style="border-top: 1px solid #e2e8f0; padding-top: 16px;">
				<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
					<span style="font-size: 13px; font-weight: 600; color: #334155;">Active Queue Manifest (Next <?php echo esc_html( count( $recent_queue ) ); ?> Sets)</span>
					<span style="font-size: 11px; color: #64748b;">Auto-advanced by polite cron throttle</span>
				</div>
				<div style="max-height: 240px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 6px;">
					<table class="cv-table" style="font-size: 12px;">
						<thead>
							<tr style="position: sticky; top: 0; background: #f8fafc; z-index: 1;">
								<th style="padding: 6px 12px;">Status</th>
								<th style="padding: 6px 12px;">Game</th>
								<th style="padding: 6px 12px;">Set Name</th>
								<th style="padding: 6px 12px;">Cards</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( array_slice( $recent_queue, 0, 25 ) as $q_item ) : ?>
								<?php
								$q_status = $q_item['status'] ?? 'pending';
								$q_badge_style = 'color: #64748b; background: #f1f5f9;';
								if ( 'processing' === $q_status ) {
									$q_badge_style = 'color: #0284c7; background: rgba(2, 132, 199, 0.15); font-weight: 700;';
								} elseif ( 'completed' === $q_status ) {
									$q_badge_style = 'color: #10b981; background: rgba(16, 185, 129, 0.15);';
								} elseif ( 'failed' === $q_status ) {
									$q_badge_style = 'color: #ef4444; background: rgba(239, 68, 68, 0.15);';
								}
								?>
								<tr>
									<td style="padding: 6px 12px;">
										<span style="display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 10px; text-transform: uppercase; <?php echo esc_attr( $q_badge_style ); ?>">
											<?php echo esc_html( $q_status ); ?>
										</span>
									</td>
									<td style="padding: 6px 12px; color: #475569;"><?php echo esc_html( $q_item['category_name'] ); ?></td>
									<td style="padding: 6px 12px; font-weight: 500; color: #1e293b;"><?php echo esc_html( $q_item['group_name'] ); ?></td>
									<td style="padding: 6px 12px; color: #64748b;">
										<?php echo ! empty( $q_item['imported_cards'] ) ? esc_html( number_format_i18n( (int) $q_item['imported_cards'] ) ) : '-'; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php endif; ?>
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
