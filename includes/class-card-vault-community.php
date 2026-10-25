<?php
/**
 * Community Vault, Consignment Intake, and Card Show Bidding Engine.
 *
 * @package    Xophz_Compass_Card_Vault
 * @subpackage Xophz_Compass_Card_Vault/includes
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class Card_Vault_Community {

	const SETTINGS_OPTION_KEY = 'xophz_vault_community_settings';

	/** User meta holding the collector's bid transparency choice. Only 'ticker' makes offers public. */
	const BID_TRANSPARENCY_META_KEY = 'cv_show_bid_transparency_mode';

	/**
	 * Key inside card_payload that holds the item fields the table has no column for.
	 * It is removed before card_payload is returned as the card.
	 */
	const ITEM_META_KEY = '_cardVaultItem';

	/**
	 * Optional item fields stored under ITEM_META_KEY and returned as item fields.
	 * dateAdded and lastModified are stored there too and returned separately.
	 */
	/** Largest collection a single sync request may carry. */
	const MAX_SYNC_ITEMS = 5000;

	/** Largest stored card_payload for one synced item, in bytes. */
	const MAX_ITEM_PAYLOAD_BYTES = 200000;

	/** Largest number of lines in one intake batch. */
	const MAX_INTAKE_LINES = 500;

	/** Intake batches one user may submit per hour. */
	const MAX_INTAKE_BATCHES_PER_HOUR = 20;

	const ITEM_META_FIELDS = array( 'consignorId', 'consignorName', 'consignorSplitRate', 'customValuation', 'certificationStatus', 'aiGradeReport' );

	/**
	 * Retrieve community settings with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = array(
			'allow_community_vaults'     => (bool) get_option( 'users_can_register', 0 ),
			'default_consignor_split'    => 80.00,
			'min_consignment_card_value' => 10.00,
			'store_credit_bonus_percent' => 15.00,
			'auto_approve_intake'        => false,
			'enable_public_showcases'    => true,
		);

		$saved = get_option( self::SETTINGS_OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Update community settings and synchronize core WP registration option.
	 *
	 * @param array $new_settings Settings to merge.
	 * @return array Updated settings.
	 */
	public static function update_settings( $new_settings ) {
		$current = self::get_settings();

		if ( isset( $new_settings['allow_community_vaults'] ) ) {
			$allowed = (bool) $new_settings['allow_community_vaults'];
			$current['allow_community_vaults'] = $allowed;
			// Core lets only manage_options change this option: a shop manager changes the plugin flag only.
			if ( current_user_can( 'manage_options' ) ) {
				update_option( 'users_can_register', $allowed ? 1 : 0 );
			}
		}

		if ( isset( $new_settings['default_consignor_split'] ) ) {
			$current['default_consignor_split'] = max( 1.0, min( 99.0, (float) $new_settings['default_consignor_split'] ) );
		}

		if ( isset( $new_settings['min_consignment_card_value'] ) ) {
			$current['min_consignment_card_value'] = max( 0.0, (float) $new_settings['min_consignment_card_value'] );
		}

		if ( isset( $new_settings['store_credit_bonus_percent'] ) ) {
			$current['store_credit_bonus_percent'] = max( 0.0, min( 100.0, (float) $new_settings['store_credit_bonus_percent'] ) );
		}

		if ( isset( $new_settings['auto_approve_intake'] ) ) {
			$current['auto_approve_intake'] = (bool) $new_settings['auto_approve_intake'];
		}

		if ( isset( $new_settings['enable_public_showcases'] ) ) {
			$current['enable_public_showcases'] = (bool) $new_settings['enable_public_showcases'];
		}

		update_option( self::SETTINGS_OPTION_KEY, $current );
		return $current;
	}

	/**
	 * Get collector items for a specific WordPress user.
	 *
	 * @param int $user_id WordPress User ID.
	 * @return array
	 */
	public static function get_collector_items( $user_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'xophz_vault_collector_items';

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE wp_user_id = %d ORDER BY updated_at DESC",
				$user_id
			),
			ARRAY_A
		);

		if ( empty( $results ) ) {
			return array();
		}

		$formatted = array();
		foreach ( $results as $row ) {
			list( $card_data, $item_meta ) = self::split_card_payload( $row['card_payload'] );
			$formatted_item = array(
				'id'               => $row['item_id'],
				'cardId'           => $row['card_id'],
				'card'             => $card_data ? $card_data : array(
					'id'       => $row['card_id'],
					'name'     => $row['card_name'],
					'setName'  => $row['set_name'],
					'number'   => $row['card_number'],
					'rarity'   => $row['rarity'],
					'pricing'  => array(
						'rawMarketPrice' => (float) $row['market_price'],
					),
				),
				'condition'        => $row['condition_grade'],
				'quantity'         => (int) $row['quantity'],
				'isFoil'           => (bool) $row['is_foil'],
				'acquiredPrice'    => $row['acquired_price'] !== null ? (float) $row['acquired_price'] : null,
				'askingPrice'      => $row['asking_price'] !== null ? (float) $row['asking_price'] : null,
				'marketPrice'      => (float) $row['market_price'],
				'userNotes'        => $row['user_notes'],
				'isPublicShowcase' => (bool) $row['is_public_showcase'],
				'dateAdded'        => self::stored_date_added( $item_meta, $row ),
				// A row written before the client time was stored only has updated_at, which
				// is the time of the last push, not of the last edit: report no lastModified.
				'lastModified'     => isset( $item_meta['lastModified'] ) ? $item_meta['lastModified'] : '',
			);
			$formatted[] = array_merge( $formatted_item, self::item_meta_fields( $item_meta ) );
		}

		return $formatted;
	}

	/**
	 * Split a stored card_payload into the card and the item fields kept under ITEM_META_KEY.
	 *
	 * @param string|null $payload_json Stored card_payload.
	 * @return array Two entries: the card array (or null) and the item fields (or null for a row without them).
	 */
	private static function split_card_payload( $payload_json ) {
		$decoded = ! empty( $payload_json ) ? json_decode( $payload_json, true ) : null;
		if ( ! is_array( $decoded ) ) {
			return array( null, null );
		}
		$item_meta = isset( $decoded[ self::ITEM_META_KEY ] ) && is_array( $decoded[ self::ITEM_META_KEY ] ) ? $decoded[ self::ITEM_META_KEY ] : null;
		unset( $decoded[ self::ITEM_META_KEY ] );
		return array( ! empty( $decoded ) ? $decoded : null, $item_meta );
	}

	/**
	 * The client acquisition date when stored, else the row creation day.
	 *
	 * @param array|null $item_meta Stored item fields.
	 * @param array      $row       Table row.
	 * @return string
	 */
	private static function stored_date_added( $item_meta, $row ) {
		if ( ! empty( $item_meta['dateAdded'] ) ) {
			return $item_meta['dateAdded'];
		}
		return ! empty( $row['created_at'] ) ? gmdate( 'Y-m-d', strtotime( $row['created_at'] ) ) : gmdate( 'Y-m-d' );
	}

	/**
	 * Item fields to return for a row. A row with stored item fields returns every
	 * field, null when it is not set, so a field cleared on one device is cleared on
	 * the others. A row without them returns none, so the local copy keeps its own.
	 *
	 * @param array|null $item_meta Stored item fields.
	 * @return array
	 */
	private static function item_meta_fields( $item_meta ) {
		if ( ! is_array( $item_meta ) ) {
			return array();
		}
		$fields = array();
		foreach ( self::ITEM_META_FIELDS as $field ) {
			$fields[ $field ] = isset( $item_meta[ $field ] ) ? $item_meta[ $field ] : null;
		}
		return $fields;
	}

	/**
	 * Validate the item fields a client sends that the table has no column for.
	 *
	 * @param array $item Client inventory item.
	 * @return array Values for ITEM_META_KEY; a missing or invalid field is null.
	 */
	private static function sanitize_item_meta( $item ) {
		$text = function ( $key ) use ( $item ) {
			return isset( $item[ $key ] ) && is_scalar( $item[ $key ] ) && '' !== trim( (string) $item[ $key ] ) ? sanitize_text_field( (string) $item[ $key ] ) : null;
		};
		$date = function ( $key ) use ( $text ) {
			$value = $text( $key );
			return null !== $value && strlen( $value ) <= 40 && false !== strtotime( $value ) ? $value : null;
		};
		$split_rate = isset( $item['consignorSplitRate'] ) && is_numeric( $item['consignorSplitRate'] ) ? max( 0, min( 100, (float) $item['consignorSplitRate'] ) ) : null;
		$valuation  = isset( $item['customValuation'] ) && is_numeric( $item['customValuation'] ) && (float) $item['customValuation'] > 0 ? (float) $item['customValuation'] : null;
		$status     = isset( $item['certificationStatus'] ) && in_array( $item['certificationStatus'], array( 'raw', 'certified' ), true ) ? $item['certificationStatus'] : null;

		return array(
			'dateAdded'           => $date( 'dateAdded' ),
			'lastModified'        => $date( 'lastModified' ),
			'consignorId'         => $text( 'consignorId' ),
			'consignorName'       => $text( 'consignorName' ),
			'consignorSplitRate'  => $split_rate,
			'customValuation'     => $valuation,
			'certificationStatus' => $status,
			'aiGradeReport'       => isset( $item['aiGradeReport'] ) && is_array( $item['aiGradeReport'] ) ? $item['aiGradeReport'] : null,
		);
	}

	/**
	 * Sync and upsert collector items from client state.
	 *
	 * A row that already exists is updated in place, so created_at survives a push;
	 * a new id is inserted. The client dateAdded and lastModified, and the consignor,
	 * grade and valuation fields, are stored under ITEM_META_KEY in card_payload.
	 * Rows whose id is missing from the push are deleted, as before, except that an
	 * empty push over a non-empty collection is refused unless $replace_all is true.
	 * A push of more than MAX_SYNC_ITEMS items is refused. An item whose stored payload
	 * would exceed MAX_ITEM_PAYLOAD_BYTES is skipped and its existing row is kept.
	 * A new row is private unless the item sets isPublicShowcase; a known row keeps its
	 * flag unless the item sets it.
	 *
	 * @param int   $user_id     WordPress User ID.
	 * @param array $items       Array of inventory items.
	 * @param bool  $replace_all True when the client explicitly means to replace the whole collection.
	 * @return bool False when nothing was written because the push was refused.
	 */
	public static function sync_collector_items( $user_id, $items, $replace_all = false ) {
		global $wpdb;
		$table = $wpdb->prefix . 'xophz_vault_collector_items';

		if ( ! is_array( $items ) || count( $items ) > self::MAX_SYNC_ITEMS ) {
			return false;
		}
		// Refuse only when an admin explicitly saved the setting as off, so the users_can_register
		// default does not lock out existing collectors. Dealers are exempt, as for intake.
		$saved_settings = get_option( self::SETTINGS_OPTION_KEY, array() );
		if ( is_array( $saved_settings ) && array_key_exists( 'allow_community_vaults', $saved_settings ) && empty( $saved_settings['allow_community_vaults'] )
			&& ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_card_vault' ) ) {
			return false;
		}

		$existing_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT item_id FROM $table WHERE wp_user_id = %d", $user_id )
		);

		if ( empty( $items ) && ! empty( $existing_ids ) && true !== $replace_all ) {
			return false;
		}

		$incoming_ids = array();
		$stored_ids   = array_fill_keys( $existing_ids, true );

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$item_id = ! empty( $item['id'] ) ? sanitize_text_field( $item['id'] ) : wp_generate_uuid4();
			$incoming_ids[] = $item_id;

			$card = isset( $item['card'] ) && is_array( $item['card'] ) ? $item['card'] : array();
			$card_id = ! empty( $item['cardId'] ) ? sanitize_text_field( $item['cardId'] ) : ( ! empty( $card['id'] ) ? sanitize_text_field( $card['id'] ) : '' );
			$card_name = ! empty( $card['name'] ) ? sanitize_text_field( $card['name'] ) : 'Unknown Card';
			$set_name = ! empty( $card['setName'] ) ? sanitize_text_field( $card['setName'] ) : '';
			$card_number = ! empty( $card['number'] ) ? sanitize_text_field( $card['number'] ) : '';
			$rarity = ! empty( $card['rarity'] ) ? sanitize_text_field( $card['rarity'] ) : '';

			$condition = ! empty( $item['condition'] ) ? sanitize_text_field( $item['condition'] ) : 'NM';
			$quantity = isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1;
			$is_foil = ! empty( $item['isFoil'] ) ? 1 : 0;
			$acquired_price = isset( $item['acquiredPrice'] ) && is_numeric( $item['acquiredPrice'] ) ? (float) $item['acquiredPrice'] : null;
			$asking_price = isset( $item['askingPrice'] ) && is_numeric( $item['askingPrice'] ) ? (float) $item['askingPrice'] : null;
			$market_price = isset( $card['pricing']['rawMarketPrice'] ) ? (float) $card['pricing']['rawMarketPrice'] : 0.00;
			$user_notes = ! empty( $item['userNotes'] ) ? sanitize_textarea_field( $item['userNotes'] ) : null;
			$payload = $card;
			$payload[ self::ITEM_META_KEY ] = self::sanitize_item_meta( $item );
			$payload_json = wp_json_encode( $payload );
			if ( ! is_string( $payload_json ) || strlen( $payload_json ) > self::MAX_ITEM_PAYLOAD_BYTES ) {
				continue;
			}

			$key = array(
				'wp_user_id' => $user_id,
				'item_id'    => $item_id,
			);
			$fields = array(
				'card_id'            => $card_id,
				'card_name'          => $card_name,
				'set_name'           => $set_name,
				'card_number'        => $card_number,
				'rarity'             => $rarity,
				'condition_grade'    => $condition,
				'quantity'           => $quantity,
				'is_foil'            => $is_foil,
				'acquired_price'     => $acquired_price,
				'asking_price'       => $asking_price,
				'market_price'       => $market_price,
				'card_payload'       => $payload_json,
				'user_notes'         => $user_notes,
			);
			$field_formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%f', '%f', '%s', '%s' );
			// Private unless the client says otherwise; a known row keeps its flag when the item does not set one.
			if ( isset( $item['isPublicShowcase'] ) ) {
				$fields['is_public_showcase'] = $item['isPublicShowcase'] ? 1 : 0;
				$field_formats[]              = '%d';
			} elseif ( ! isset( $stored_ids[ $item_id ] ) ) {
				$fields['is_public_showcase'] = 0;
				$field_formats[]              = '%d';
			}
			// wpdb writes a null value as NULL whatever its format.

			// REPLACE deletes and reinserts the row, which resets created_at: update in place instead.
			if ( isset( $stored_ids[ $item_id ] ) ) {
				$wpdb->update( $table, $fields, $key, $field_formats, array( '%d', '%s' ) );
			} else {
				$wpdb->insert( $table, array_merge( $key, $fields ), array_merge( array( '%d', '%s' ), $field_formats ) );
				$stored_ids[ $item_id ] = true;
			}
		}

		// Clean up deleted items
		$to_delete = array_diff( $existing_ids, $incoming_ids );
		if ( ! empty( $to_delete ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $to_delete ), '%s' ) );
			$query = $wpdb->prepare(
				"DELETE FROM $table WHERE wp_user_id = %d AND item_id IN ($placeholders)",
				array_merge( array( $user_id ), array_values( $to_delete ) )
			);
			$wpdb->query( $query );
		}

		return true;
	}

	/**
	 * Whether the admin allows public QR showcases and guest bids (default on).
	 *
	 * @return bool
	 */
	private static function public_showcases_enabled() {
		$settings = self::get_settings();
		return ! empty( $settings['enable_public_showcases'] );
	}

	/**
	 * @return WP_Error
	 */
	private static function showcases_disabled_error() {
		return new WP_Error( 'showcases_disabled', __( 'Public showcases are turned off.', 'xophz-compass-card-vault' ), array( 'status' => 403 ) );
	}

	/**
	 * Resolve a showcase slug to a user that has at least one public showcase item.
	 *
	 * Matches user_nicename or user_login only. Numeric user IDs and any
	 * administrator fallback are deliberately not resolved, so the slug cannot be
	 * used to enumerate accounts. Returns null when nothing public exists.
	 *
	 * @param string $slug Username or user_nicename.
	 * @return WP_User|null
	 */
	private static function resolve_showcase_user( $slug ) {
		global $wpdb;
		$slug = is_string( $slug ) ? trim( $slug ) : '';
		if ( '' === $slug ) {
			return null;
		}

		$user = get_user_by( 'slug', sanitize_title( $slug ) );
		if ( ! $user ) {
			$user = get_user_by( 'login', sanitize_user( $slug ) );
		}
		if ( ! $user ) {
			return null;
		}

		$table      = $wpdb->prefix . 'xophz_vault_collector_items';
		$has_public = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM $table WHERE wp_user_id = %d AND is_public_showcase = 1 LIMIT 1",
				$user->ID
			)
		);

		return $has_public ? $user : null;
	}

	/**
	 * Retrieve a public showcase payload by collector username.
	 *
	 * @param string $slug Username or user_nicename.
	 * @return array|WP_Error
	 */
	public static function get_public_showcase( $slug ) {
		global $wpdb;
		$table = $wpdb->prefix . 'xophz_vault_collector_items';

		if ( ! self::public_showcases_enabled() ) {
			return self::showcases_disabled_error();
		}

		$user = self::resolve_showcase_user( $slug );

		if ( ! $user ) {
			return new WP_Error( 'showcase_not_found', __( 'Collector showcase not found.', 'xophz-compass-card-vault' ), array( 'status' => 404 ) );
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE wp_user_id = %d AND is_public_showcase = 1 ORDER BY market_price DESC",
				$user->ID
			),
			ARRAY_A
		);

		$items = array();
		$total_market_value = 0.0;
		$total_card_count = 0;

		foreach ( $results as $row ) {
			// The stored item fields (consignor, grade, valuation) are private: only the card is shown.
			list( $card_data ) = self::split_card_payload( $row['card_payload'] );
			$qty = (int) $row['quantity'];
			$m_price = (float) $row['market_price'];

			$total_card_count += $qty;
			$total_market_value += ( $m_price * $qty );

			$items[] = array(
				'id'          => $row['item_id'],
				'cardId'      => $row['card_id'],
				'card'        => $card_data ? $card_data : array(
					'id'      => $row['card_id'],
					'name'    => $row['card_name'],
					'setName' => $row['set_name'],
					'number'  => $row['card_number'],
					'rarity'  => $row['rarity'],
					'pricing' => array( 'rawMarketPrice' => $m_price ),
				),
				'condition'   => $row['condition_grade'],
				'quantity'    => $qty,
				'isFoil'      => (bool) $row['is_foil'],
				'askingPrice' => $row['asking_price'] !== null ? (float) $row['asking_price'] : null,
				'marketPrice' => $m_price,
			);
		}

		// Vendor offers are public only when the collector chose the 'ticker' mode, stored server-side
		// in user meta. Absent or any other value means private, so the default exposes nothing.
		$top_bids = array();
		if ( 'ticker' === get_user_meta( $user->ID, self::BID_TRANSPARENCY_META_KEY, true ) ) {
			$bids_table = $wpdb->prefix . 'xophz_vault_show_bids';
			$top_bids   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT bid_code, vendor_name, vendor_booth, offer_type, offer_cash_amount, bid_type, target_item_ids, created_at
					FROM $bids_table
					WHERE collector_user_id = %d AND status IN ('accepted', 'countered')
					ORDER BY offer_cash_amount DESC
					LIMIT 10",
					$user->ID
				),
				ARRAY_A
			);
		}

		return array(
			'collector' => array(
				'id'          => $user->ID,
				'displayName' => $user->display_name,
			),
			'summary' => array(
				'totalCards'  => $total_card_count,
				'totalValue'  => $total_market_value,
			),
			'items'   => $items,
			'topBids' => $top_bids,
		);
	}

	/**
	 * Create an in-store consignment intake batch.
	 *
	 * @param int   $user_id User ID of collector.
	 * @param array $payload Batch submission data.
	 * @return array|WP_Error
	 */
	public static function submit_intake_batch( $user_id, $payload ) {
		global $wpdb;
		$batches_table = $wpdb->prefix . 'xophz_vault_intake_batches';
		$items_table = $wpdb->prefix . 'xophz_vault_intake_items';

		$items = isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();
		if ( empty( $items ) ) {
			return new WP_Error( 'empty_batch', __( 'No cards provided in consignment batch.', 'xophz-compass-card-vault' ), array( 'status' => 400 ) );
		}
		if ( count( $items ) > self::MAX_INTAKE_LINES ) {
			return new WP_Error( 'batch_too_large', __( 'Too many cards in one consignment batch.', 'xophz-compass-card-vault' ), array( 'status' => 400 ) );
		}

		$settings = self::get_settings();
		// Dealers file batches on a collector's behalf whatever the community setting says.
		if ( empty( $settings['allow_community_vaults'] ) && ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_card_vault' ) ) {
			return new WP_Error( 'community_vaults_disabled', __( 'Consignment submissions are turned off.', 'xophz-compass-card-vault' ), array( 'status' => 403 ) );
		}

		// Best-effort throttle per user (a transient, so it is not exact under concurrent requests).
		$throttle_key = 'cv_intake_' . (int) $user_id;
		$submitted    = (int) get_transient( $throttle_key );
		if ( $submitted >= self::MAX_INTAKE_BATCHES_PER_HOUR ) {
			return new WP_Error( 'intake_rate_limited', __( 'Too many consignment batches submitted. Please try again later.', 'xophz-compass-card-vault' ), array( 'status' => 429 ) );
		}
		set_transient( $throttle_key, $submitted + 1, 3600 );

		// The collector does not set the split: only the dealer-configured default applies.
		$split_rate = max( 1.0, min( 99.0, (float) $settings['default_consignor_split'] ) );
		$payout_pref = isset( $payload['payoutPreference'] ) ? sanitize_text_field( $payload['payoutPreference'] ) : 'consignment';
		$dropoff_type = isset( $payload['dropoffType'] ) ? sanitize_text_field( $payload['dropoffType'] ) : 'counter';
		$notes = isset( $payload['notes'] ) ? sanitize_textarea_field( $payload['notes'] ) : '';

		// Get or link consignor profile
		$consignor_id = '';
		if ( class_exists( 'Card_Vault_Consignments' ) ) {
			$consignor = Card_Vault_Consignments::get_consignor_by_user_id( $user_id );
			if ( $consignor ) {
				$consignor_id = $consignor['consignor_id'];
			} else {
				$user = get_user_by( 'id', $user_id );
				$consignor_id = 'c_' . substr( md5( (string) $user_id . time() ), 0, 12 );
				Card_Vault_Consignments::create_consignor( array(
					'consignor_id'       => $consignor_id,
					'wp_user_id'         => $user_id,
					'name'               => $user ? $user->display_name : 'Collector #' . $user_id,
					'email'              => $user ? $user->user_email : '',
					'default_split_rate' => $split_rate,
					'payout_method'      => $payout_pref === 'store_credit' ? 'Store Credit' : 'Cash',
				) );
			}
		}

		$batch_code = 'CV-INTK-' . strtoupper( wp_generate_password( 6, false ) );
		$total_claimed_value = 0.0;
		$total_quantity = 0;

		foreach ( $items as $item ) {
			$qty = isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1;
			$val = isset( $item['claimedValue'] ) ? (float) $item['claimedValue'] : ( isset( $item['marketPrice'] ) ? (float) $item['marketPrice'] : 0.0 );
			$total_quantity += $qty;
			$total_claimed_value += ( $val * $qty );
		}

		$inserted = $wpdb->insert(
			$batches_table,
			array(
				'batch_code'          => $batch_code,
				'wp_user_id'          => $user_id,
				'consignor_id'        => $consignor_id,
				'status'              => 'submitted',
				'dropoff_type'        => $dropoff_type,
				'payout_preference'   => $payout_pref,
				'total_items'         => $total_quantity,
				'total_claimed_value' => $total_claimed_value,
				'agreed_split_rate'   => $split_rate,
				'notes'               => $notes,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%f', '%f', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'db_error', __( 'Failed to create intake batch.', 'xophz-compass-card-vault' ), array( 'status' => 500 ) );
		}

		$batch_id = $wpdb->insert_id;

		foreach ( $items as $item ) {
			$card = isset( $item['card'] ) && is_array( $item['card'] ) ? $item['card'] : array();
			$card_id = ! empty( $item['cardId'] ) ? sanitize_text_field( $item['cardId'] ) : ( ! empty( $card['id'] ) ? sanitize_text_field( $card['id'] ) : '' );
			$card_name = ! empty( $card['name'] ) ? sanitize_text_field( $card['name'] ) : ( ! empty( $item['cardName'] ) ? sanitize_text_field( $item['cardName'] ) : 'Unknown Card' );
			$set_raw = ! empty( $card['setName'] ) ? $card['setName'] : ( $item['setName'] ?? '' );
			$num_raw = ! empty( $card['number'] ) ? $card['number'] : ( $item['cardNumber'] ?? '' );
			$set_name = is_scalar( $set_raw ) ? sanitize_text_field( (string) $set_raw ) : '';
			$card_number = is_scalar( $num_raw ) ? sanitize_text_field( (string) $num_raw ) : '';
			$condition = ! empty( $item['condition'] ) ? sanitize_text_field( $item['condition'] ) : 'NM';
			$quantity = isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1;
			$is_foil = ! empty( $item['isFoil'] ) ? 1 : 0;
			$val = isset( $item['claimedValue'] ) ? (float) $item['claimedValue'] : ( isset( $item['marketPrice'] ) ? (float) $item['marketPrice'] : 0.0 );

			$wpdb->insert(
				$items_table,
				array(
					'batch_id'          => $batch_id,
					'batch_code'        => $batch_code,
					'card_id'           => $card_id,
					'card_name'         => $card_name,
					'set_name'          => $set_name,
					'card_number'       => $card_number,
					'claimed_condition' => $condition,
					'quantity'          => $quantity,
					'is_foil'           => $is_foil,
					'claimed_value'     => $val,
					'status'            => 'pending',
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%s' )
			);
		}

		return array(
			'id'                => $batch_id,
			'batchCode'         => $batch_code,
			'status'            => 'submitted',
			'totalItems'        => $total_quantity,
			'totalClaimedValue' => $total_claimed_value,
			'agreedSplitRate'   => $split_rate,
			'dropoffType'       => $dropoff_type,
		);
	}

	/**
	 * Retrieve intake batches for dealer or user.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function get_intake_batches( $args = array() ) {
		global $wpdb;
		$batches_table = $wpdb->prefix . 'xophz_vault_intake_batches';
		$items_table = $wpdb->prefix . 'xophz_vault_intake_items';

		$where = array( '1=1' );
		$params = array();

		if ( ! empty( $args['wp_user_id'] ) ) {
			$where[] = 'b.wp_user_id = %d';
			$params[] = (int) $args['wp_user_id'];
		}

		if ( ! empty( $args['status'] ) ) {
			$where[] = 'b.status = %s';
			$params[] = sanitize_text_field( $args['status'] );
		}

		if ( ! empty( $args['batch_code'] ) ) {
			$where[] = 'b.batch_code = %s';
			$params[] = sanitize_text_field( $args['batch_code'] );
		}

		$where_clause = implode( ' AND ', $where );
		$query = "SELECT b.*, u.display_name as collector_name, u.user_email as collector_email 
			FROM $batches_table b 
			LEFT JOIN {$wpdb->users} u ON b.wp_user_id = u.ID 
			WHERE $where_clause 
			ORDER BY b.created_at DESC LIMIT 50";

		$batches = ! empty( $params ) ? $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A ) : $wpdb->get_results( $query, ARRAY_A );

		if ( empty( $batches ) ) {
			return array();
		}

		$formatted = array();
		foreach ( $batches as $b ) {
			$batch_id = (int) $b['id'];
			$line_items = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM $items_table WHERE batch_id = %d", $batch_id ),
				ARRAY_A
			);

			$formatted[] = array(
				'id'                => $batch_id,
				'batchCode'         => $b['batch_code'],
				'collectorId'       => (int) $b['wp_user_id'],
				'collectorName'     => $b['collector_name'] ? $b['collector_name'] : 'Collector #' . $b['wp_user_id'],
				'collectorEmail'    => $b['collector_email'],
				'consignorId'       => $b['consignor_id'],
				'status'            => $b['status'],
				'dropoffType'       => $b['dropoff_type'],
				'payoutPreference'  => $b['payout_preference'],
				'totalItems'        => (int) $b['total_items'],
				'totalClaimedValue' => (float) $b['total_claimed_value'],
				'appraisedValue'    => $b['appraised_value'] !== null ? (float) $b['appraised_value'] : null,
				'agreedSplitRate'   => (float) $b['agreed_split_rate'],
				'notes'             => $b['notes'],
				'createdAt'         => $b['created_at'],
				'items'             => array_map( function( $item ) {
					return array(
						'id'                 => (int) $item['id'],
						'cardId'             => $item['card_id'],
						'cardName'           => $item['card_name'],
						'setName'            => $item['set_name'],
						'cardNumber'         => $item['card_number'],
						'claimedCondition'   => $item['claimed_condition'],
						'appraisedCondition' => $item['appraised_condition'],
						'quantity'           => (int) $item['quantity'],
						'isFoil'             => (bool) $item['is_foil'],
						'claimedValue'       => (float) $item['claimed_value'],
						'appraisedValue'     => $item['appraised_value'] !== null ? (float) $item['appraised_value'] : null,
						'status'             => $item['status'],
						'wcProductId'        => (int) $item['wc_product_id'],
					);
				}, $line_items ),
			);
		}

		return $formatted;
	}

	/**
	 * Dealer appraisals & line-item review of an intake batch.
	 *
	 * @param int   $batch_id Batch ID.
	 * @param array $payload  Appraisal items and updated values.
	 * @return bool|WP_Error
	 */
	public static function appraise_intake_batch( $batch_id, $payload ) {
		global $wpdb;
		$batches_table = $wpdb->prefix . 'xophz_vault_intake_batches';
		$items_table = $wpdb->prefix . 'xophz_vault_intake_items';

		$batch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $batches_table WHERE id = %d", $batch_id ), ARRAY_A );
		if ( ! $batch ) {
			return new WP_Error( 'not_found', __( 'Batch not found.', 'xophz-compass-card-vault' ), array( 'status' => 404 ) );
		}

		$items = isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();
		$total_appraised = 0.0;

		foreach ( $items as $item_data ) {
			if ( empty( $item_data['id'] ) ) continue;

			$item_id = (int) $item_data['id'];
			$appraised_cond = ! empty( $item_data['appraisedCondition'] ) ? sanitize_text_field( $item_data['appraisedCondition'] ) : null;
			$appraised_val = isset( $item_data['appraisedValue'] ) ? (float) $item_data['appraisedValue'] : 0.0;
			$status = ! empty( $item_data['status'] ) ? sanitize_text_field( $item_data['status'] ) : 'approved';

			// Quantity comes from the stored line, not the payload; lines outside this batch are skipped.
			$stored_qty = $wpdb->get_var(
				$wpdb->prepare( "SELECT quantity FROM $items_table WHERE id = %d AND batch_id = %d", $item_id, $batch_id )
			);
			if ( null === $stored_qty ) {
				continue;
			}

			if ( 'rejected' !== $status ) {
				$total_appraised += ( $appraised_val * max( 1, (int) $stored_qty ) );
			}

			$wpdb->update(
				$items_table,
				array(
					'appraised_condition' => $appraised_cond,
					'appraised_value'     => $appraised_val,
					'status'              => $status,
				),
				array( 'id' => $item_id, 'batch_id' => $batch_id ),
				array( '%s', '%f', '%s' ),
				array( '%d', '%d' )
			);
		}

		$new_status = ! empty( $payload['status'] ) ? sanitize_text_field( $payload['status'] ) : 'appraised';
		$agreed_split = isset( $payload['agreedSplitRate'] ) ? (float) $payload['agreedSplitRate'] : (float) $batch['agreed_split_rate'];
		$agreed_split = max( 1.0, min( 99.0, $agreed_split ) );

		$wpdb->update(
			$batches_table,
			array(
				'appraised_value'   => $total_appraised,
				'agreed_split_rate' => $agreed_split,
				'status'            => $new_status,
			),
			array( 'id' => $batch_id ),
			array( '%f', '%f', '%s' ),
			array( '%d' )
		);

		return true;
	}

	/**
	 * Accept intake batch into active store inventory and synchronize with WooCommerce.
	 *
	 * @param int $batch_id Batch ID.
	 * @return array|WP_Error
	 */
	public static function accept_intake_batch( $batch_id ) {
		global $wpdb;
		$batches_table = $wpdb->prefix . 'xophz_vault_intake_batches';
		$items_table = $wpdb->prefix . 'xophz_vault_intake_items';

		$batch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $batches_table WHERE id = %d", $batch_id ), ARRAY_A );
		if ( ! $batch ) {
			return new WP_Error( 'not_found', __( 'Batch not found.', 'xophz-compass-card-vault' ), array( 'status' => 404 ) );
		}
		if ( in_array( $batch['status'], array( 'accepted', 'accepting' ), true ) ) {
			return new WP_Error( 'already_accepted', __( 'This batch has already been accepted.', 'xophz-compass-card-vault' ), array( 'status' => 409 ) );
		}
		if ( 'appraised' !== $batch['status'] ) {
			return new WP_Error( 'not_appraised', __( 'Only an appraised batch can be accepted.', 'xophz-compass-card-vault' ), array( 'status' => 409 ) );
		}

		// Claim the batch in one statement so a concurrent accept cannot also sync its lines.
		// A PHP exception during the sync restores 'appraised'; a fatal error or killed request is not
		// covered and leaves the batch 'accepting' until someone resets it.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $batches_table SET status = 'accepting' WHERE id = %d AND status = 'appraised'",
				$batch_id
			)
		);
		if ( 1 !== (int) $claimed ) {
			return new WP_Error( 'already_accepted', __( 'This batch has already been accepted.', 'xophz-compass-card-vault' ), array( 'status' => 409 ) );
		}

		try {
			$line_items = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM $items_table WHERE batch_id = %d AND status != 'rejected'", $batch_id ),
				ARRAY_A
			);

			$synced_products = array();

			// Convert to WooCommerce products if WooCommerce sync class is active
			if ( class_exists( 'Card_Vault_WC_Sync' ) ) {
			foreach ( $line_items as $item ) {
				$cond = ! empty( $item['appraised_condition'] ) ? $item['appraised_condition'] : $item['claimed_condition'];
				$price = $item['appraised_value'] !== null && (float) $item['appraised_value'] > 0 ? (float) $item['appraised_value'] : (float) $item['claimed_value'];

				// Keys are the flat shape Card_Vault_WC_Sync::sync_inventory_item() reads.
				$sync_item = array(
					'id'                 => 'intake_' . $item['id'],
					'cardId'             => $item['card_id'],
					'cardName'           => $item['card_name'],
					'setName'            => $item['set_name'],
					'cardNumber'         => $item['card_number'],
					'rarity'             => 'Consigned',
					'condition'          => $cond,
					'quantity'           => (int) $item['quantity'],
					'isFoil'             => (bool) $item['is_foil'],
					'askingPrice'        => $price,
					'consignorId'        => $batch['consignor_id'],
					'consignorSplitRate' => (float) $batch['agreed_split_rate'],
				);

				$wc_product_id = Card_Vault_WC_Sync::sync_inventory_item( $sync_item );
				if ( $wc_product_id && ! is_wp_error( $wc_product_id ) ) {
					$wpdb->update(
						$items_table,
						array( 'wc_product_id' => $wc_product_id, 'status' => 'approved' ),
						array( 'id' => $item['id'] ),
						array( '%d', '%s' ),
						array( '%d' )
					);
					$synced_products[] = $wc_product_id;
				}
			}
			}
		} catch ( \Throwable $e ) {
			$wpdb->update( $batches_table, array( 'status' => 'appraised' ), array( 'id' => $batch_id ), array( '%s' ), array( '%d' ) );
			return new WP_Error( 'accept_failed', __( 'The batch could not be accepted. It was returned to appraised.', 'xophz-compass-card-vault' ), array( 'status' => 500 ) );
		}

		$wpdb->update(
			$batches_table,
			array( 'status' => 'accepted' ),
			array( 'id' => $batch_id ),
			array( '%s' ),
			array( '%d' )
		);

		return array(
			'batchId'        => $batch_id,
			'status'         => 'accepted',
			'syncedProducts' => $synced_products,
		);
	}

	/**
	 * Submit a card show vendor bid.
	 *
	 * @param array $payload Bid data.
	 * @return array|WP_Error
	 */
	public static function submit_show_bid( $payload ) {
		global $wpdb;
		$table = $wpdb->prefix . 'xophz_vault_show_bids';

		if ( ! self::public_showcases_enabled() ) {
			return self::showcases_disabled_error();
		}

		$showcase_slug = ! empty( $payload['showcaseSlug'] ) ? sanitize_title( $payload['showcaseSlug'] ) : '';
		$vendor_name = ! empty( $payload['vendorName'] ) ? sanitize_text_field( $payload['vendorName'] ) : '';
		$vendor_booth = ! empty( $payload['vendorBooth'] ) ? sanitize_text_field( $payload['vendorBooth'] ) : 'Floor / Table';
		$vendor_phone = ! empty( $payload['vendorPhone'] ) ? sanitize_text_field( $payload['vendorPhone'] ) : 'N/A';

		if ( empty( $vendor_name ) ) {
			return new WP_Error( 'missing_fields', __( 'Vendor or buyer name is required.', 'xophz-compass-card-vault' ), array( 'status' => 400 ) );
		}

		// The target comes only from the slug (payload collectorUserId is ignored), and only
		// users with a public showcase can receive bids.
		$target_user       = self::resolve_showcase_user( $showcase_slug );
		$collector_user_id = $target_user ? (int) $target_user->ID : 0;

		if ( ! $collector_user_id ) {
			return new WP_Error( 'invalid_collector', __( 'Target collector not found.', 'xophz-compass-card-vault' ), array( 'status' => 404 ) );
		}

		// Throttle: at most 5 bids per 10 minutes per client address and target. Best effort;
		// the address is REMOTE_ADDR, which is a shared proxy address behind some setups.
		$remote_addr  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$throttle_key = 'cv_bid_' . md5( $remote_addr . '|' . $collector_user_id );
		$bid_count    = (int) get_transient( $throttle_key );
		if ( $bid_count >= 5 ) {
			return new WP_Error( 'bid_rate_limited', __( 'Too many bids submitted. Please try again later.', 'xophz-compass-card-vault' ), array( 'status' => 429 ) );
		}
		set_transient( $throttle_key, $bid_count + 1, 10 * MINUTE_IN_SECONDS );

		$bid_type = ! empty( $payload['bidType'] ) ? sanitize_text_field( $payload['bidType'] ) : 'single_card';
		$target_item_ids = null;
		if ( isset( $payload['targetItemIds'] ) && is_array( $payload['targetItemIds'] ) ) {
			$clean_ids = array();
			foreach ( array_slice( $payload['targetItemIds'], 0, 500 ) as $target_id ) {
				if ( is_scalar( $target_id ) ) {
					$clean_ids[] = substr( sanitize_text_field( (string) $target_id ), 0, 64 );
				}
			}
			$target_item_ids = wp_json_encode( $clean_ids );
		}
		$target_card_summary = ! empty( $payload['targetCardSummary'] ) ? sanitize_text_field( $payload['targetCardSummary'] ) : '';

		$offer_type = ! empty( $payload['offerType'] ) ? sanitize_text_field( $payload['offerType'] ) : 'cash';
		$offer_cash = isset( $payload['offerCashAmount'] ) ? max( 0.0, (float) $payload['offerCashAmount'] ) : 0.0;
		$offer_trade_desc = ! empty( $payload['offerTradeDescription'] ) ? sanitize_textarea_field( $payload['offerTradeDescription'] ) : null;
		$notes = ! empty( $payload['notes'] ) ? sanitize_textarea_field( $payload['notes'] ) : null;

		$bid_code = 'BID-' . strtoupper( wp_generate_password( 6, false ) );

		$inserted = $wpdb->insert(
			$table,
			array(
				'bid_code'                => $bid_code,
				'collector_user_id'       => $collector_user_id,
				'showcase_slug'           => $showcase_slug,
				'vendor_name'             => $vendor_name,
				'vendor_booth'            => $vendor_booth,
				'vendor_phone'            => $vendor_phone,
				'bid_type'                => $bid_type,
				'target_item_ids'         => $target_item_ids,
				'target_card_summary'     => $target_card_summary,
				'offer_type'              => $offer_type,
				'offer_cash_amount'       => $offer_cash,
				'offer_trade_description' => $offer_trade_desc,
				'status'                  => 'pending',
				'notes'                   => $notes,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'db_error', __( 'Failed to record vendor bid.', 'xophz-compass-card-vault' ), array( 'status' => 500 ) );
		}

		return array(
			'id'        => $wpdb->insert_id,
			'bidCode'   => $bid_code,
			'status'    => 'pending',
			'offerCash' => $offer_cash,
		);
	}

	/**
	 * Retrieve all bids for a collector.
	 *
	 * @param int $collector_user_id Collector user ID.
	 * @return array
	 */
	public static function get_bids_for_collector( $collector_user_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'xophz_vault_show_bids';

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE collector_user_id = %d ORDER BY created_at DESC LIMIT 100",
				$collector_user_id
			),
			ARRAY_A
		);

		if ( empty( $results ) ) {
			return array();
		}

		$formatted = array();
		foreach ( $results as $row ) {
			$item_ids = ! empty( $row['target_item_ids'] ) ? json_decode( $row['target_item_ids'], true ) : array();
			$formatted[] = array(
				'id'                    => (int) $row['id'],
				'bidCode'               => $row['bid_code'],
				'showcaseSlug'          => $row['showcase_slug'],
				'vendorName'            => $row['vendor_name'],
				'vendorBooth'           => $row['vendor_booth'],
				'vendorPhone'           => $row['vendor_phone'],
				'bidType'               => $row['bid_type'],
				'targetItemIds'         => $item_ids,
				'targetCardSummary'     => $row['target_card_summary'],
				'offerType'             => $row['offer_type'],
				'offerCashAmount'       => (float) $row['offer_cash_amount'],
				'offerTradeDescription' => $row['offer_trade_description'],
				'status'                => $row['status'],
				'counterAmount'         => $row['counter_amount'] !== null ? (float) $row['counter_amount'] : null,
				'notes'                 => $row['notes'],
				'createdAt'             => $row['created_at'],
			);
		}

		return $formatted;
	}

	/**
	 * Perform an action on a bid (accept, counter, decline).
	 *
	 * @param int    $bid_id         Bid ID.
	 * @param int    $collector_id   Collector User ID (for ownership check).
	 * @param string $action         'accepted', 'countered', or 'declined'.
	 * @param float  $counter_amount Optional counter offer amount.
	 * @return bool|WP_Error
	 */
	public static function action_show_bid( $bid_id, $collector_id, $action, $counter_amount = null ) {
		global $wpdb;
		$table = $wpdb->prefix . 'xophz_vault_show_bids';

		$bid = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $table WHERE id = %d AND collector_user_id = %d", $bid_id, $collector_id ),
			ARRAY_A
		);

		if ( ! $bid ) {
			return new WP_Error( 'not_found', __( 'Bid not found.', 'xophz-compass-card-vault' ), array( 'status' => 404 ) );
		}

		$valid_actions = array( 'accepted', 'countered', 'declined' );
		if ( ! in_array( $action, $valid_actions, true ) ) {
			return new WP_Error( 'invalid_action', __( 'Invalid bid action.', 'xophz-compass-card-vault' ), array( 'status' => 400 ) );
		}

		if ( 'countered' === $action && null !== $counter_amount ) {
			$counter_amount = round( (float) $counter_amount, 2 );
			if ( ! is_finite( $counter_amount ) || $counter_amount <= 0 ) {
				return new WP_Error( 'invalid_counter_amount', __( 'Counter amount must be greater than zero.', 'xophz-compass-card-vault' ), array( 'status' => 400 ) );
			}
		}

		$update_data = array( 'status' => $action );
		$update_formats = array( '%s' );

		if ( 'countered' === $action && $counter_amount !== null ) {
			$update_data['counter_amount'] = $counter_amount;
			$update_formats[] = '%f';
		}

		$updated = $wpdb->update(
			$table,
			$update_data,
			array( 'id' => $bid_id ),
			$update_formats,
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Initialize community hooks for WooCommerce My Account integration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_wc_endpoints' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_wc_account_menu_item' ) );
		add_action( 'woocommerce_account_card-vault_endpoint', array( __CLASS__, 'render_wc_account_content' ) );
	}

	/**
	 * Register rewrite endpoint for WooCommerce My Account.
	 */
	public static function register_wc_endpoints() {
		add_rewrite_endpoint( 'card-vault', EP_ROOT | EP_PAGES );
	}

	/**
	 * Add "Card Vault Collection" tab to WooCommerce My Account menu.
	 *
	 * @param array $items Existing navigation menu items.
	 * @return array
	 */
	public static function add_wc_account_menu_item( $items ) {
		$reordered = array();
		foreach ( $items as $key => $label ) {
			$reordered[ $key ] = $label;
			if ( 'dashboard' === $key ) {
				$reordered['card-vault'] = __( 'Card Collection', 'xophz-compass-card-vault' );
			}
		}
		if ( ! isset( $reordered['card-vault'] ) ) {
			$reordered['card-vault'] = __( 'Card Collection', 'xophz-compass-card-vault' );
		}
		return $reordered;
	}

	/**
	 * Render user's synced collection inside WooCommerce My Account.
	 */
	public static function render_wc_account_content() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			echo '<p>' . esc_html__( 'Please log in to view your collection.', 'xophz-compass-card-vault' ) . '</p>';
			return;
		}

		$user = wp_get_current_user();
		$items = self::get_collector_items( $user_id );
		$custom_slug = get_option( 'xophz_compass_card_vault_custom_slug', 'card-vault' );
		$app_url = home_url( '/' . $custom_slug );
		$showcase_url = add_query_arg( 'showcase', $user->user_login, $app_url );

		$total_cards = 0;
		$total_value = 0.0;
		foreach ( $items as $item ) {
			$qty = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
			$total_cards += $qty;
			$price = isset( $item['marketPrice'] ) ? (float) $item['marketPrice'] : 0.0;
			$total_value += ( $price * $qty );
		}
		?>
		<div class="cv-wc-profile-container" style="background: #0f172a; border: 1px solid #1e293b; border-radius: 12px; padding: 24px; color: #f1f5f9; margin-bottom: 24px;">
			<div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; border-bottom: 1px solid #334155; padding-bottom: 16px; margin-bottom: 20px;">
				<div>
					<h3 style="margin: 0 0 4px 0; color: #f8fafc; font-size: 18px;"><?php esc_html_e( 'My Card Vault Collection', 'xophz-compass-card-vault' ); ?></h3>
					<div style="font-size: 12px; color: #94a3b8;"><?php printf( esc_html__( 'Portfolio: %s cards | Est. Value: $%s', 'xophz-compass-card-vault' ), number_format( $total_cards ), number_format( $total_value, 2 ) ); ?></div>
				</div>
				<div style="display: flex; flex-wrap: wrap; gap: 10px;">
					<a href="<?php echo esc_url( add_query_arg( 'action', 'add', $app_url ) ); ?>" target="_blank" class="button" style="background: #059669; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600;">
						+ <?php esc_html_e( 'Add to Collection', 'xophz-compass-card-vault' ); ?>
					</a>
					<a href="<?php echo esc_url( add_query_arg( 'action', 'import', $app_url ) ); ?>" target="_blank" class="button" style="background: #d97706; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600;">
						<?php esc_html_e( 'Import More (CSV)', 'xophz-compass-card-vault' ); ?>
					</a>
					<a href="<?php echo esc_url( $app_url ); ?>" target="_blank" class="button" style="background: #0284c7; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600;">
						<?php esc_html_e( 'Open Card Vault', 'xophz-compass-card-vault' ); ?> &rarr;
					</a>
					<?php if ( ! empty( $items ) ) : ?>
						<a href="<?php echo esc_url( $showcase_url ); ?>" target="_blank" class="button" style="background: #1e293b; color: #38bdf8; border: 1px solid #334155; text-decoration: none; border-radius: 8px;">
							<?php esc_html_e( 'View Public Showcase', 'xophz-compass-card-vault' ); ?> &nearr;
						</a>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( empty( $items ) ) : ?>
				<div style="text-align: center; padding: 32px 16px; color: #94a3b8;">
					<p style="margin-bottom: 8px;"><?php esc_html_e( 'No cards synced yet.', 'xophz-compass-card-vault' ); ?></p>
					<p style="font-size: 13px; color: #64748b; margin-bottom: 16px;"><?php esc_html_e( 'Launch Card Vault and click "Push to Cloud" to sync your physical card inventory.', 'xophz-compass-card-vault' ); ?></p>
					<a href="<?php echo esc_url( add_query_arg( 'action', 'add', $app_url ) ); ?>" target="_blank" class="button" style="background: #059669; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600;">
						+ <?php esc_html_e( 'Add Your First Cards', 'xophz-compass-card-vault' ); ?>
					</a>
				</div>
			<?php else : ?>
				<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; max-height: 520px; overflow-y: auto;">
					<?php foreach ( $items as $card_item ) : ?>
						<?php
						$card_meta = isset( $card_item['card'] ) && is_array( $card_item['card'] ) ? $card_item['card'] : array();
						$card_name = $card_meta['name'] ?? ( $card_item['cardName'] ?? 'Card' );
						$set_name  = $card_meta['setName'] ?? '';
						$card_img  = $card_meta['images']['small'] ?? ( $card_meta['images']['large'] ?? '' );
						$condition = $card_item['condition'] ?? 'NM';
						$price     = isset( $card_item['marketPrice'] ) ? (float) $card_item['marketPrice'] : 0.0;
						$qty       = isset( $card_item['quantity'] ) ? (int) $card_item['quantity'] : 1;
						?>
						<div style="background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 12px; font-size: 12px; display: flex; flex-direction: column; justify-content: space-between;">
							<?php if ( ! empty( $card_img ) ) : ?>
								<div style="text-align: center; margin-bottom: 8px;">
									<img src="<?php echo esc_url( $card_img ); ?>" alt="<?php echo esc_attr( $card_name ); ?>" style="max-height: 120px; border-radius: 4px; object-fit: contain;" />
								</div>
							<?php endif; ?>
							<div>
								<div style="font-weight: 700; color: #f8fafc; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo esc_attr( $card_name ); ?>">
									<?php echo esc_html( $card_name ); ?>
								</div>
								<div style="font-size: 11px; color: #94a3b8; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
									<?php echo esc_html( $set_name ); ?>
								</div>
							</div>
							<div style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px; padding-top: 6px; border-top: 1px solid #334155; font-size: 11px;">
								<span style="background: #0f172a; color: #cbd5e1; padding: 1px 6px; border-radius: 4px; font-family: monospace;"><?php echo esc_html( $condition ); ?> &times;<?php echo esc_html( $qty ); ?></span>
								<span style="font-weight: 700; color: #34d399; font-family: monospace;">$<?php echo esc_html( number_format( $price, 2 ) ); ?></span>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
