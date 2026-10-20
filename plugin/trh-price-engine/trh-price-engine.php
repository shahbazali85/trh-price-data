<?php
/**
 * Plugin Name: TRH Price Engine
 * Description: One place for every price, calorie count, holiday date and event on the site. Edit a Google Sheet (or upload a CSV) and every page updates itself — no copy-paste. Adds shortcodes, auto holiday dates, Yoast variables, Menu JSON-LD and honest "last updated" dates.
 * Version: 1.5.0
 * Author: Desire Web Solution
 * Requires PHP: 7.4
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TRHPE_VERSION', '1.5.0' );
define( 'TRHPE_OPT_ITEMS', 'trhpe_items' );
define( 'TRHPE_OPT_META', 'trhpe_meta' );
define( 'TRHPE_OPT_HOLIDAYS', 'trhpe_holidays' );
define( 'TRHPE_OPT_EVENTS', 'trhpe_events' );
define( 'TRHPE_OPT_SETTINGS', 'trhpe_settings' );
define( 'TRHPE_OPT_LOG', 'trhpe_log' );

/* -------------------------------------------------------------------------
 * 1. DATA LAYER
 * ---------------------------------------------------------------------- */

/** CSV columns we understand. Only id, name, category, price are required. */
function trhpe_columns() {
	return array( 'id', 'name', 'category', 'price', 'price_max', 'calories', 'serves', 'note', 'status', 'verified_on', 'force' );
}

function trhpe_items() {
	$items = get_option( TRHPE_OPT_ITEMS, array() );
	return is_array( $items ) ? $items : array();
}

function trhpe_item( $id ) {
	$items = trhpe_items();
	$id    = sanitize_title( $id );
	return isset( $items[ $id ] ) ? $items[ $id ] : null;
}

function trhpe_meta() {
	$m = get_option( TRHPE_OPT_META, array() );
	return wp_parse_args( is_array( $m ) ? $m : array(), array(
		'last_verified' => '',   // Y-m-d — the date a human last checked prices against the official ordering site.
		'last_import'   => '',
		'stores_checked'=> '',   // free text e.g. "Louisville KY, Dallas TX, Phoenix AZ".
		'source_hash'   => '',
	) );
}

function trhpe_settings() {
	$s = get_option( TRHPE_OPT_SETTINGS, array() );
	return wp_parse_args( is_array( $s ) ? $s : array(), array(
		'feed_base_url'   => '',  // Folder URL that holds items.csv, events.csv, meta.json, pages.json (e.g. raw GitHub URL).
		'feed_pages'      => 1,   // Create/update posts listed in pages.json.
		'allow_takeover'  => 0,   // Let the feed overwrite EXISTING pages it did not create (only rows with "takeover": true).
		'force_draft'     => 1,
		'speed_fonts'     => 1,   // Load only the Google Font weights the theme uses, with display=optional (stops font layout shift).
		'speed_delay_ads' => 0,   // Load the AdSense script after first scroll/tap (or 4 s). Off by default — may change ad revenue.
		'speed_lcp'       => 1,   // Preload the featured image on single pages/posts (faster LCP).   // Ignore the feed's publish status and always save as draft.
		'sheet_csv_url'   => '',  // Google Sheet "Publish to web" CSV link for the prices tab.
		'events_csv_url'  => '',  // Optional second tab for events.
		'max_change_pct'  => 30,  // Price moves bigger than this are held back unless force=1.
		'menu_schema'     => 1,
		'touch_modified'  => 1,
	) );
}

function trhpe_log( $msg ) {
	$log   = get_option( TRHPE_OPT_LOG, array() );
	$log   = is_array( $log ) ? $log : array();
	array_unshift( $log, gmdate( 'Y-m-d H:i' ) . ' UTC — ' . $msg );
	update_option( TRHPE_OPT_LOG, array_slice( $log, 0, 50 ), false );
}

/**
 * Parse CSV text into rows of associative arrays (header row required).
 */
function trhpe_parse_csv( $csv ) {
	$csv   = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $csv ); // strip BOM.
	$lines = preg_split( '/\r\n|\n|\r/', trim( $csv ) );
	if ( count( $lines ) < 2 ) {
		return new WP_Error( 'trhpe_csv', 'CSV needs a header row and at least one data row.' );
	}
	$rows   = array();
	$handle = fopen( 'php://temp', 'r+' );
	fwrite( $handle, implode( "\n", $lines ) );
	rewind( $handle );
	$header = array_map( function ( $h ) {
		return strtolower( trim( $h ) );
	}, fgetcsv( $handle, 0, ',', '"', '\\' ) );
	while ( ( $data = fgetcsv( $handle, 0, ',', '"', '\\' ) ) !== false ) {
		if ( count( array_filter( $data, 'strlen' ) ) === 0 ) {
			continue;
		}
		$row = array();
		foreach ( $header as $i => $col ) {
			$row[ $col ] = isset( $data[ $i ] ) ? trim( $data[ $i ] ) : '';
		}
		$rows[] = $row;
	}
	fclose( $handle );
	return $rows;
}

function trhpe_money_to_float( $v ) {
	$v = preg_replace( '/[^0-9.]/', '', (string) $v );
	return ( '' === $v ) ? null : round( (float) $v, 2 );
}

/**
 * Validate + import item rows. Returns a report array.
 * Safety rules: required fields, sane price range, big jumps held back unless force=1.
 */
function trhpe_import_items( array $rows, $source = 'manual' ) {
	$settings = trhpe_settings();
	$old      = trhpe_items();
	$new      = $old;
	$report   = array( 'added' => 0, 'changed' => 0, 'unchanged' => 0, 'held' => array(), 'errors' => array(), 'changes' => array() );
	$seen     = array();

	foreach ( $rows as $n => $r ) {
		$line = $n + 2;
		$id   = sanitize_title( isset( $r['id'] ) ? $r['id'] : '' );
		if ( '' === $id ) {
			$id = sanitize_title( isset( $r['name'] ) ? $r['name'] : '' );
		}
		$name  = isset( $r['name'] ) ? sanitize_text_field( $r['name'] ) : '';
		$cat   = sanitize_title( isset( $r['category'] ) ? $r['category'] : '' );
		$price = trhpe_money_to_float( isset( $r['price'] ) ? $r['price'] : '' );

		if ( '' === $id || '' === $name || '' === $cat || null === $price ) {
			$report['errors'][] = "Row $line: id/name/category/price missing — skipped.";
			continue;
		}
		if ( isset( $seen[ $id ] ) ) {
			$report['errors'][] = "Row $line: duplicate id '$id' — skipped (first one kept).";
			continue;
		}
		$seen[ $id ] = true;
		if ( $price <= 0 || $price > 1000 ) {
			$report['errors'][] = "Row $line ($name): price $price looks wrong — skipped.";
			continue;
		}
		$price_max = trhpe_money_to_float( isset( $r['price_max'] ) ? $r['price_max'] : '' );
		if ( null !== $price_max && $price_max < $price ) {
			$report['errors'][] = "Row $line ($name): price_max is lower than price — price_max ignored.";
			$price_max = null;
		}

		$item = array(
			'id'          => $id,
			'name'        => $name,
			'category'    => $cat,
			'price'       => $price,
			'price_max'   => $price_max,
			'calories'    => sanitize_text_field( isset( $r['calories'] ) ? $r['calories'] : '' ),
			'serves'      => sanitize_text_field( isset( $r['serves'] ) ? $r['serves'] : '' ),
			'note'        => sanitize_text_field( isset( $r['note'] ) ? $r['note'] : '' ),
			'status'      => in_array( isset( $r['status'] ) ? $r['status'] : '', array( 'verified', 'crosschecked', 'estimate', 'seasonal', 'discontinued' ), true ) ? $r['status'] : 'estimate',
			'verified_on' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', isset( $r['verified_on'] ) ? $r['verified_on'] : '' ) ? $r['verified_on'] : '',
			'sort'        => $n,
		);

		if ( isset( $old[ $id ] ) ) {
			$prev = $old[ $id ];
			$pct  = $prev['price'] > 0 ? abs( $price - $prev['price'] ) / $prev['price'] * 100 : 0;
			$force = ! empty( $r['force'] ) && '0' !== $r['force'];
			if ( $pct > (float) $settings['max_change_pct'] && ! $force ) {
				$report['held'][] = sprintf( '%s: $%.2f → $%.2f (%.0f%% jump) held back. Re-check it, then set force=1 to apply.', $name, $prev['price'], $price, $pct );
				continue;
			}
			$compare_old = $prev;
			$compare_new = $item;
			unset( $compare_old['sort'], $compare_new['sort'] );
			if ( $compare_old == $compare_new ) { // phpcs:ignore WordPress.PHP.StrictComparisons
				$report['unchanged']++;
				$new[ $id ]['sort'] = $n;
				continue;
			}
			if ( (float) $prev['price'] !== (float) $price ) {
				$report['changes'][] = sprintf( '%s: $%.2f → $%.2f', $name, $prev['price'], $price );
			}
			$report['changed']++;
		} else {
			$report['added']++;
		}
		$new[ $id ] = $item;
	}

	uasort( $new, function ( $a, $b ) {
		return $a['sort'] - $b['sort'];
	} );

	$changed = ( $report['added'] + $report['changed'] ) > 0;
	if ( $changed ) {
		update_option( TRHPE_OPT_ITEMS, $new, false );
		$meta                 = trhpe_meta();
		$meta['last_import']  = current_time( 'Y-m-d' );
		// The newest verified_on date across items becomes the site-wide "prices checked" date.
		$dates = array_filter( wp_list_pluck( $new, 'verified_on' ) );
		if ( $dates ) {
			$meta['last_verified'] = max( $dates );
		}
		update_option( TRHPE_OPT_META, $meta, false );
		trhpe_after_data_change();
	}
	trhpe_log( sprintf( '[%s] items: +%d added, %d changed, %d unchanged, %d held, %d errors', $source, $report['added'], $report['changed'], $report['unchanged'], count( $report['held'] ), count( $report['errors'] ) ) );
	return $report;
}

/**
 * Events / holidays importer. Columns: id,name,rule,status,offer,details,url,confirmed
 * rule examples: fixed:11-11 | nth:4:thu:11 (4th Thu of Nov) | last:mon:5 | easter | easter:-2
 */
function trhpe_import_events( array $rows, $source = 'manual' ) {
	$out = array();
	foreach ( $rows as $r ) {
		$id = sanitize_title( isset( $r['id'] ) ? $r['id'] : '' );
		if ( '' === $id || empty( $r['rule'] ) ) {
			continue;
		}
		$out[ $id ] = array(
			'id'        => $id,
			'name'      => sanitize_text_field( isset( $r['name'] ) ? $r['name'] : $id ),
			'rule'      => sanitize_text_field( $r['rule'] ),
			'status'    => sanitize_text_field( isset( $r['status'] ) ? $r['status'] : '' ),
			'offer'     => sanitize_text_field( isset( $r['offer'] ) ? $r['offer'] : '' ),
			'details'   => wp_kses_post( isset( $r['details'] ) ? $r['details'] : '' ),
			'url'       => esc_url_raw( isset( $r['url'] ) ? $r['url'] : '' ),
			'confirmed' => sanitize_text_field( isset( $r['confirmed'] ) ? $r['confirmed'] : '' ),
		);
	}
	if ( $out && $out != get_option( TRHPE_OPT_EVENTS ) ) { // phpcs:ignore
		update_option( TRHPE_OPT_EVENTS, $out, false );
		trhpe_after_data_change();
		trhpe_log( sprintf( '[%s] events: %d saved', $source, count( $out ) ) );
	}
	return count( $out );
}

function trhpe_events() {
	$e = get_option( TRHPE_OPT_EVENTS, array() );
	if ( ! is_array( $e ) || ! $e ) {
		$e = trhpe_default_events();
	}
	return $e;
}

/** Sensible defaults. Status text is editable later via the Events tab of the sheet. */
function trhpe_default_events() {
	$rows = array(
		array( 'new-years-day', "New Year's Day", 'fixed:01-01', 'Open — hours vary by location', '', '', '', '' ),
		array( 'mlk-day', 'Martin Luther King Jr. Day', 'nth:3:mon:1', 'Open — regular hours at most locations', '', '', '', '' ),
		array( 'valentines-day', "Valentine's Day", 'fixed:02-14', 'Open — expect long waits; use the app waitlist', '', '', '', '' ),
		array( 'presidents-day', "Presidents' Day", 'nth:3:mon:2', 'Open — regular hours at most locations', '', '', '', '' ),
		array( 'easter', 'Easter Sunday', 'easter', 'Check your local restaurant', '', '', '', '' ),
		array( 'mothers-day', "Mother's Day", 'nth:2:sun:5', 'Open — one of the busiest days of the year', '', '', '', '' ),
		array( 'memorial-day', 'Memorial Day', 'last:mon:5', 'Open — regular hours at most locations', '', '', '', '' ),
		array( 'fathers-day', "Father's Day", 'nth:3:sun:6', 'Open — one of the busiest days of the year', '', '', '', '' ),
		array( 'july-4th', 'Independence Day', 'fixed:07-04', 'Open — hours may be shorter', '', '', '', '' ),
		array( 'labor-day', 'Labor Day', 'nth:1:mon:9', 'Open — many locations open at 11 AM', '', '', '', '' ),
		array( 'halloween', 'Halloween', 'fixed:10-31', 'Open — regular hours', '', '', '', '' ),
		array( 'veterans-day', 'Veterans Day', 'fixed:11-11', 'Open', 'Free meal voucher for veterans & active-duty military (annual tradition)', 'In 2025 vouchers were handed out 11 AM–2 PM on Nov 11 with proof of service and could be redeemed through May 31, 2026. 2026 terms: not yet confirmed by Texas Roadhouse.', 'https://www.texasroadhouse.com', 'no' ),
		array( 'thanksgiving', 'Thanksgiving Day', 'nth:4:thu:11', 'Closed', '', '', '', '' ),
		array( 'black-friday', 'Black Friday', 'nth:4:thu:11+1', 'Open — regular hours', 'Holiday gift card bonus (check current terms)', '', '', 'no' ),
		array( 'christmas-eve', 'Christmas Eve', 'fixed:12-24', 'Open — many locations close early', '', '', '', '' ),
		array( 'christmas-day', 'Christmas Day', 'fixed:12-25', 'Closed', '', '', '', '' ),
		array( 'new-years-eve', "New Year's Eve", 'fixed:12-31', 'Open — hours may be shorter', '', '', '', '' ),
	);
	$out = array();
	foreach ( $rows as $r ) {
		$out[ $r[0] ] = array_combine( array( 'id', 'name', 'rule', 'status', 'offer', 'details', 'url', 'confirmed' ), $r );
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * 2. DATE ENGINE — holidays are calculated, never typed by hand.
 * ---------------------------------------------------------------------- */

/**
 * Resolve a rule to a DateTimeImmutable for a given year.
 * Supports: fixed:MM-DD | nth:N:dow:M | last:dow:M | easter | any rule with +N / -N day offset suffix.
 */
function trhpe_rule_date( $rule, $year ) {
	$tz     = wp_timezone();
	$offset = 0;
	if ( preg_match( '/^(.*?)([+-]\d+)$/', $rule, $m ) && false === strpos( $m[1], 'fixed' ) ) {
		$rule   = $m[1];
		$offset = (int) $m[2];
	} elseif ( preg_match( '/^(easter):?([+-]?\d+)?$/', $rule, $m ) ) {
		$rule   = 'easter';
		$offset = isset( $m[2] ) ? (int) $m[2] : 0;
	}
	$days = array( 'sun' => 'Sunday', 'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday' );
	$p    = explode( ':', $rule );
	$d    = null;
	if ( 'fixed' === $p[0] && isset( $p[1] ) ) {
		$d = new DateTimeImmutable( $year . '-' . $p[1], $tz );
	} elseif ( 'nth' === $p[0] && count( $p ) === 4 && isset( $days[ $p[2] ] ) ) {
		$ord = array( 1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth' );
		$mon = DateTimeImmutable::createFromFormat( '!Y-n-j', $year . '-' . (int) $p[3] . '-1', $tz );
		$d   = $mon->modify( $ord[ (int) $p[1] ] . ' ' . $days[ $p[2] ] . ' of this month' );
	} elseif ( 'last' === $p[0] && count( $p ) === 3 && isset( $days[ $p[1] ] ) ) {
		$mon = DateTimeImmutable::createFromFormat( '!Y-n-j', $year . '-' . (int) $p[2] . '-1', $tz );
		$d   = $mon->modify( 'last ' . $days[ $p[1] ] . ' of this month' );
	} elseif ( 'easter' === $p[0] ) {
		// Anonymous Gregorian algorithm (no ext/calendar dependency).
		$a = $year % 19; $b = intdiv( $year, 100 ); $c = $year % 100; $dd = intdiv( $b, 4 ); $e = $b % 4;
		$f = intdiv( $b + 8, 25 ); $g = intdiv( $b - $f + 1, 3 ); $h = ( 19 * $a + $b - $dd - $g + 15 ) % 30;
		$i = intdiv( $c, 4 ); $k = $c % 4; $l = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
		$mm = intdiv( $a + 11 * $h + 22 * $l, 451 );
		$month = intdiv( $h + $l - 7 * $mm + 114, 31 );
		$day   = ( ( $h + $l - 7 * $mm + 114 ) % 31 ) + 1;
		$d     = DateTimeImmutable::createFromFormat( '!Y-n-j', "$year-$month-$day", $tz );
	}
	if ( ! $d ) {
		return null;
	}
	return $offset ? $d->modify( ( $offset > 0 ? '+' : '' ) . $offset . ' days' ) : $d;
}

/** Next occurrence (today counts) of an event. */
function trhpe_next_date( $rule ) {
	$today = new DateTimeImmutable( 'today', wp_timezone() );
	$y     = (int) $today->format( 'Y' );
	$d     = trhpe_rule_date( $rule, $y );
	if ( $d && $d < $today ) {
		$d = trhpe_rule_date( $rule, $y + 1 );
	}
	return $d;
}

/* -------------------------------------------------------------------------
 * 3. SHORTCODES
 * ---------------------------------------------------------------------- */

function trhpe_fmt_price( $item, $style = 'auto' ) {
	$p = '$' . number_format( (float) $item['price'], 2 );
	if ( ! empty( $item['price_max'] ) && 'single' !== $style ) {
		$p .= '–$' . number_format( (float) $item['price_max'], 2 );
	}
	return $p;
}

/** [trh_price id="cactus-blossom"]  [trh_price id="..." field="calories|name|serves|note"] */
add_shortcode( 'trh_price', function ( $atts ) {
	$a    = shortcode_atts( array( 'id' => '', 'field' => 'price', 'fallback' => '' ), $atts );
	$item = trhpe_item( $a['id'] );
	if ( ! $item ) {
		return current_user_can( 'edit_posts' ) ? '<mark>[TRH: unknown item ' . esc_html( $a['id'] ) . ']</mark>' : esc_html( $a['fallback'] );
	}
	if ( 'price' === $a['field'] ) {
		return esc_html( trhpe_fmt_price( $item ) );
	}
	return isset( $item[ $a['field'] ] ) ? esc_html( $item[ $a['field'] ] ) : '';
} );

/** [trh_range category="family-meals"] → "$34.99–$64.99" — ranges in prose can never drift again. */
add_shortcode( 'trh_range', function ( $atts ) {
	$a      = shortcode_atts( array( 'category' => '', 'ids' => '' ), $atts );
	$prices = array();
	$ids    = array_filter( array_map( 'sanitize_title', explode( ',', $a['ids'] ) ) );
	foreach ( trhpe_items() as $it ) {
		if ( 'discontinued' === $it['status'] ) {
			continue;
		}
		if ( ( $ids && in_array( $it['id'], $ids, true ) ) || ( ! $ids && $it['category'] === sanitize_title( $a['category'] ) ) ) {
			$prices[] = (float) $it['price'];
			if ( ! empty( $it['price_max'] ) ) {
				$prices[] = (float) $it['price_max'];
			}
		}
	}
	if ( ! $prices ) {
		return '';
	}
	$min = min( $prices );
	$max = max( $prices );
	return esc_html( $min === $max ? '$' . number_format( $min, 2 ) : '$' . number_format( $min, 2 ) . '–$' . number_format( $max, 2 ) );
} );

/** Small "prices checked" line used under tables and in [trh_badge]. */
function trhpe_checked_line() {
	$d = do_shortcode( '[trh_updated format="M j, Y"]' );
	return $d ? 'Typical U.S. prices · checked ' . $d . ' · may vary by location' : 'Typical U.S. prices · may vary by location';
}

/** [trh_table category="appetizers" cols="name,price" caption="..." footer="1"]
 *  Item name with its note underneath, price right-aligned. Extra columns: calories, serves. */
add_shortcode( 'trh_table', function ( $atts ) {
	$a      = shortcode_atts( array( 'category' => '', 'ids' => '', 'cols' => 'name,price,note', 'caption' => '', 'footer' => '1' ), $atts );
	$cols   = array_map( 'trim', explode( ',', $a['cols'] ) );
	$extra  = array_values( array_intersect( $cols, array( 'calories', 'serves' ) ) );
	$labels = array( 'calories' => 'Calories', 'serves' => 'Serves' );
	$notes  = in_array( 'note', $cols, true );
	$ids    = array_filter( array_map( 'sanitize_title', explode( ',', $a['ids'] ) ) );
	$rows   = array();
	foreach ( trhpe_items() as $it ) {
		if ( 'discontinued' === $it['status'] ) {
			continue;
		}
		if ( $ids ? in_array( $it['id'], $ids, true ) : $it['category'] === sanitize_title( $a['category'] ) ) {
			$rows[] = $it;
		}
	}
	if ( ! $rows ) {
		return current_user_can( 'edit_posts' ) ? '<mark>[TRH: no items for this table]</mark>' : '';
	}
	$GLOBALS['trhpe_tables_on_page'][] = array( 'category' => $a['category'], 'items' => $rows );

	$h = '<div class="trh-menu google-anno-skip"><table class="trh-table">';
	if ( $a['caption'] ) {
		$h .= '<caption>' . esc_html( $a['caption'] ) . '</caption>';
	}
	$h .= '<thead><tr><th scope="col" class="trh-c">Item</th>';
	foreach ( $extra as $c ) {
		$h .= '<th scope="col" class="trh-c trh-num">' . esc_html( $labels[ $c ] ) . '</th>';
	}
	$h .= '<th scope="col" class="trh-c trh-num">Price</th></tr></thead><tbody>';
	foreach ( $rows as $it ) {
		$h .= '<tr><th scope="row" class="trh-c"><span class="trh-item">' . esc_html( $it['name'] ) . '</span>';
		if ( $notes && '' !== $it['note'] ) {
			$h .= '<span class="trh-note">' . esc_html( $it['note'] ) . '</span>';
		}
		$h .= '</th>';
		foreach ( $extra as $c ) {
			$h .= '<td class="trh-c trh-num">' . esc_html( '' === $it[ $c ] ? '—' : $it[ $c ] ) . '</td>';
		}
		$tag = '';
		if ( 'seasonal' === $it['status'] ) {
			$tag = '<span class="trh-tag">seasonal</span>';
		} elseif ( 'estimate' === $it['status'] ) {
			$tag = '<span class="trh-tag" title="Estimate — sources differ or only one source">est.</span>';
		}
		$h .= '<td class="trh-c trh-num"><span class="trh-price">' . esc_html( trhpe_fmt_price( $it ) ) . '</span>' . $tag . '</td></tr>';
	}
	$h .= '</tbody></table>';
	if ( '0' !== $a['footer'] ) {
		$h .= '<p class="trh-foot">' . esc_html( trhpe_checked_line() ) . '</p>';
	}
	return $h . '</div>';
} );

/** Split "Open — many locations close early" into a pill + note. */
function trhpe_status_parts( $status ) {
	$parts = preg_split( '/\s+[—–-]\s+/u', (string) $status, 2 );
	$head  = trim( $parts[0] );
	$note  = isset( $parts[1] ) ? trim( $parts[1] ) : '';
	if ( stripos( $head, 'closed' ) === 0 ) {
		return array( 'closed', 'Closed', $note );
	}
	if ( stripos( $head, 'open' ) === 0 ) {
		return array( 'open', 'Open', $note );
	}
	return array( 'varies', 'Varies', $note ? $note : $head );
}

/** [trh_holiday_table limit="0"] — next 12 months as a date list with Open / Closed / Varies pills. */
add_shortcode( 'trh_holiday_table', function ( $atts ) {
	$a     = shortcode_atts( array( 'year' => 'rolling', 'limit' => 0 ), $atts );
	$today = new DateTimeImmutable( 'today', wp_timezone() );
	$rows  = array();
	foreach ( trhpe_events() as $ev ) {
		if ( 'rolling' === $a['year'] ) {
			$d = trhpe_next_date( $ev['rule'] );
		} else {
			$y = ( 'current' === $a['year'] ) ? (int) $today->format( 'Y' ) : ( 'next' === $a['year'] ? (int) $today->format( 'Y' ) + 1 : (int) $a['year'] );
			$d = trhpe_rule_date( $ev['rule'], $y );
		}
		if ( $d ) {
			$rows[] = array( $d, $ev );
		}
	}
	usort( $rows, function ( $x, $y ) {
		return $x[0] <=> $y[0];
	} );
	if ( (int) $a['limit'] > 0 ) {
		$rows = array_slice( $rows, 0, (int) $a['limit'] );
	}
	$h        = '<div class="trh-hol-wrap google-anno-skip"><ol class="trh-hol">';
	$lastyear = (int) $today->format( 'Y' );
	foreach ( $rows as $i => $r ) {
		list( $d, $ev ) = $r;
		if ( (int) $d->format( 'Y' ) !== $lastyear ) {
			$lastyear = (int) $d->format( 'Y' );
			$h       .= '<li class="trh-hol__year" aria-hidden="true">' . esc_html( $lastyear ) . '</li>';
		}
		list( $cls, $pill, $note ) = trhpe_status_parts( $ev['status'] );
		$days = (int) $today->diff( $d )->days;
		$next = ( 0 === $i );
		$h   .= '<li class="trh-hol__row is-' . $cls . ( $next ? ' is-next' : '' ) . '">';
		$h   .= '<time class="trh-hol__date" datetime="' . esc_attr( $d->format( 'Y-m-d' ) ) . '"><span class="m">' . esc_html( $d->format( 'M' ) ) . '</span><span class="d">' . esc_html( $d->format( 'j' ) ) . '</span><span class="w">' . esc_html( $d->format( 'D' ) ) . '</span></time>';
		$h   .= '<div class="trh-hol__body"><span class="trh-hol__name">' . esc_html( $ev['name'] ) . '</span>';
		if ( $next ) {
			$h .= ' <span class="trh-next">Next · ' . trhpe_cd_span( $d, $days, 'in' ) . '</span>';
		}
		if ( $note ) {
			$h .= '<span class="trh-hol__note">' . esc_html( $note ) . '</span>';
		}
		if ( $ev['offer'] ) {
			$h .= '<span class="trh-hol__offer">' . esc_html( $ev['offer'] ) . ( 'yes' === $ev['confirmed'] ? ' · confirmed' : ' · not yet confirmed for ' . $d->format( 'Y' ) ) . '</span>';
		}
		$h .= '</div><span class="trh-pill">' . esc_html( $pill ) . '</span></li>';
	}
	return $h . '</ol><p class="trh-foot">Hours are set by each restaurant — call ahead on holidays. Dates update automatically.</p></div>';
} );

/** [trh_event id="veterans-day"] — event card with date, countdown and confirmed / not-confirmed badge. */
add_shortcode( 'trh_event', function ( $atts ) {
	$a  = shortcode_atts( array( 'id' => '' ), $atts );
	$ev = trhpe_events();
	$id = sanitize_title( $a['id'] );
	if ( ! isset( $ev[ $id ] ) ) {
		return '';
	}
	$e     = $ev[ $id ];
	$d     = trhpe_next_date( $e['rule'] );
	$today = new DateTimeImmutable( 'today', wp_timezone() );
	$days  = (int) $today->diff( $d )->days;
	list( $cls, $pill, $note ) = trhpe_status_parts( $e['status'] );
	$h  = '<div class="trh-event google-anno-skip is-' . $cls . '">';
	$h .= '<div class="trh-event__head"><time class="trh-hol__date" datetime="' . esc_attr( $d->format( 'Y-m-d' ) ) . '"><span class="m">' . esc_html( $d->format( 'M' ) ) . '</span><span class="d">' . esc_html( $d->format( 'j' ) ) . '</span><span class="w">' . esc_html( $d->format( 'D' ) ) . '</span></time>';
	$h .= '<div><span class="trh-event__title">' . esc_html( $e['name'] . ' ' . $d->format( 'Y' ) ) . '</span><span class="trh-event__when">' . esc_html( $d->format( 'l, F j, Y' ) ) . ' · ' . trhpe_cd_span( $d, $days, 'in' ) . '</span></div>';
	$h .= '<span class="trh-pill">' . esc_html( $pill ) . '</span></div>';
	if ( $note ) {
		$h .= '<p class="trh-event__note">' . esc_html( $note ) . '</p>';
	}
	if ( $e['offer'] ) {
		$h .= '<p class="trh-event__offer"><strong>' . esc_html( $e['offer'] ) . '</strong> ' . ( 'yes' === $e['confirmed'] ? '<span class="trh-badge is-ok">Confirmed for ' . esc_html( $d->format( 'Y' ) ) . '</span>' : '<span class="trh-badge is-wait">Not yet confirmed for ' . esc_html( $d->format( 'Y' ) ) . '</span>' ) . '</p>';
	}
	if ( $e['details'] ) {
		$h .= '<p class="trh-event__details">' . wp_kses_post( $e['details'] ) . '</p>';
	}
	if ( $e['url'] ) {
		$h .= '<p class="trh-event__src"><a href="' . esc_url( $e['url'] ) . '" rel="nofollow noopener" target="_blank">Official source ↗</a></p>';
	}
	return $h . '</div>';
} );


/** Countdown text that the browser keeps correct even when the page is cached. */
function trhpe_cd_span( $d, $n, $style = 'in' ) {
	$txt = 0 === $n ? 'today' : ( 1 === $n ? 'tomorrow' : ( 'away' === $style ? $n . ' days away' : 'in ' . $n . ' days' ) );
	return '<span class="trh-cd" data-date="' . esc_attr( $d->format( 'Y-m-d' ) ) . '" data-style="' . esc_attr( $style ) . '">' . esc_html( $txt ) . '</span>';
}
add_action( 'wp_footer', function () {
	echo "<script id=\"trhpe-cd\">(function(){var t=new Date();t.setHours(0,0,0,0);document.querySelectorAll('.trh-cd').forEach(function(e){var p=e.getAttribute('data-date').split('-'),d=new Date(+p[0],p[1]-1,+p[2]),n=Math.round((d-t)/864e5);if(n<0){return;}e.textContent=n===0?'today':n===1?'tomorrow':(e.getAttribute('data-style')==='away'?n+' days away':'in '+n+' days');});})();</script>";
}, 30 );

/* Holiday lists roll forward daily: purge page caches once a day (prices/dates untouched). */
add_action( 'trhpe_daily_purge', function () {
	if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); }
	if ( function_exists( 'w3tc_flush_all' ) ) { w3tc_flush_all(); }
	if ( function_exists( 'wp_cache_clear_cache' ) ) { wp_cache_clear_cache(); }
	if ( function_exists( 'sg_cachepress_purge_cache' ) ) { sg_cachepress_purge_cache(); }
	do_action( 'litespeed_purge_all' );
	do_action( 'breeze_clear_all_cache' );
} );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'trhpe_daily_purge' ) ) {
		$midnight = ( new DateTimeImmutable( 'tomorrow 00:05', wp_timezone() ) )->getTimestamp();
		wp_schedule_event( $midnight, 'daily', 'trhpe_daily_purge' );
	}
} );

/** [trh_sum ids="a,b,c" qty="1,2,1"] → total of typical prices, e.g. "$31.97". Keeps example totals in sync with the data. */
add_shortcode( 'trh_sum', function ( $atts ) {
	$a   = shortcode_atts( array( 'ids' => '', 'qty' => '' ), $atts );
	$ids = array_map( 'trim', explode( ',', $a['ids'] ) );
	$qty = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $a['qty'] ) ), 'strlen' ) );
	$sum = 0;
	foreach ( $ids as $i => $id ) {
		$it = trhpe_item( $id );
		if ( ! $it ) {
			return current_user_can( 'edit_posts' ) ? '<mark>[TRH: unknown item ' . esc_html( $id ) . ']</mark>' : '';
		}
		$sum += (float) $it['price'] * ( isset( $qty[ $i ] ) && $qty[ $i ] > 0 ? $qty[ $i ] : 1 );
	}
	return esc_html( '$' . number_format( $sum, 2 ) );
} );

/** [trh_print label="Print or save this menu as PDF"] */
add_shortcode( 'trh_print', function ( $atts ) {
	$a = shortcode_atts( array( 'label' => 'Print or save this menu as a PDF' ), $atts );
	return '<p class="trh-print-wrap google-anno-skip"><button type="button" class="trh-print" onclick="window.print()">' . esc_html( $a['label'] ) . '</button></p>';
} );

/** [trh_badge] — "✓ Prices checked Oct 17, 2026" pill for the top of a page. */
add_shortcode( 'trh_badge', function () {
	$post   = get_post();
	$prices = $post && preg_match( '/\[trh_(table|price|range)\b/', $post->post_content );
	if ( $prices ) {
		$text = trhpe_checked_line();
	} else {
		$d    = $post ? get_the_modified_date( 'M j, Y', $post ) : '';
		$text = $d ? 'Checked and updated ' . $d : 'Checked and updated regularly';
	}
	return '<p class="trh-checked google-anno-skip"><span class="trh-checked__dot" aria-hidden="true">✓</span>' . esc_html( $text ) . '</p>';
} );

/** [trh_updated] → date prices were last checked. format="F j, Y" */
add_shortcode( 'trh_updated', function ( $atts ) {
	$a    = shortcode_atts( array( 'format' => 'F j, Y' ), $atts );
	$meta = trhpe_meta();
	$d    = $meta['last_verified'] ? $meta['last_verified'] : $meta['last_import'];
	return $d ? esc_html( wp_date( $a['format'], strtotime( $d . ' 12:00:00' ) ) ) : '';
} );

/** [trh_stores] → which restaurants were checked. */
add_shortcode( 'trh_stores', function () {
	$meta = trhpe_meta();
	return esc_html( $meta['stores_checked'] );
} );

/** [trh_year] / [trh_year offset="1"] */
add_shortcode( 'trh_year', function ( $atts ) {
	$a = shortcode_atts( array( 'offset' => 0 ), $atts );
	return esc_html( (int) wp_date( 'Y' ) + (int) $a['offset'] );
} );

/** [trh_holiday id="thanksgiving" format="l, F j, Y" year="next|2027"] */
add_shortcode( 'trh_holiday', function ( $atts ) {
	$a  = shortcode_atts( array( 'id' => '', 'format' => 'l, F j, Y', 'year' => 'next', 'field' => 'date', 'offset' => 0 ), $atts );
	$ev = trhpe_events();
	$id = sanitize_title( $a['id'] );
	if ( ! isset( $ev[ $id ] ) ) {
		return '';
	}
	if ( 'date' !== $a['field'] ) {
		return isset( $ev[ $id ][ $a['field'] ] ) ? wp_kses_post( $ev[ $id ][ $a['field'] ] ) : '';
	}
	$d = ( 'next' === $a['year'] ) ? trhpe_next_date( $ev[ $id ]['rule'] ) : trhpe_rule_date( $ev[ $id ]['rule'], (int) $a['year'] );
	if ( $d && (int) $a['offset'] ) {
		$d = $d->modify( sprintf( '%+d days', (int) $a['offset'] ) );
	}
	return $d ? esc_html( $d->format( $a['format'] ) ) : '';
} );

/** [trh_countdown id="veterans-day"] → "26 days away" / "today" */
add_shortcode( 'trh_countdown', function ( $atts ) {
	$a  = shortcode_atts( array( 'id' => '' ), $atts );
	$ev = trhpe_events();
	$id = sanitize_title( $a['id'] );
	if ( ! isset( $ev[ $id ] ) ) {
		return '';
	}
	$d     = trhpe_next_date( $ev[ $id ]['rule'] );
	$today = new DateTimeImmutable( 'today', wp_timezone() );
	$n     = (int) $today->diff( $d )->days;
	return trhpe_cd_span( $d, $n, 'away' );
} );



/* -------------------------------------------------------------------------
 * 4. YOAST VARIABLES — titles & meta descriptions update themselves.
 *    %%trh_month_year%%  → month/year prices were last checked (e.g. "October 2026")
 *    %%trh_year%%        → current year
 *    %%trh_updated%%     → "October 2, 2026"
 * ---------------------------------------------------------------------- */
add_action( 'wpseo_register_extra_replacements', function () {
	if ( ! function_exists( 'wpseo_register_var_replacement' ) ) {
		return;
	}
	wpseo_register_var_replacement( '%%trh_month_year%%', function () {
		$m = trhpe_meta();
		$d = $m['last_verified'] ? $m['last_verified'] : $m['last_import'];
		return $d ? wp_date( 'F Y', strtotime( $d . ' 12:00:00' ) ) : wp_date( 'Y' );
	}, 'advanced', 'Month + year prices were last verified' );
	wpseo_register_var_replacement( '%%trh_year%%', function () {
		return wp_date( 'Y' );
	}, 'advanced', 'Current year' );
	wpseo_register_var_replacement( '%%trh_updated%%', function () {
		return do_shortcode( '[trh_updated]' );
	}, 'advanced', 'Date prices were last verified' );
} );

/** Values for the custom variables (shared by front end and editor preview). */
function trhpe_var_values() {
	$m = trhpe_meta();
	$d = $m['last_verified'] ? $m['last_verified'] : $m['last_import'];
	return array(
		'%%trh_month_year%%' => $d ? wp_date( 'F Y', strtotime( $d . ' 12:00:00' ) ) : wp_date( 'F Y' ),
		'%%trh_year%%'       => wp_date( 'Y' ),
		'%%trh_updated%%'    => $d ? wp_date( 'F j, Y', strtotime( $d . ' 12:00:00' ) ) : '',
	);
}

/* Safety net: if Yoast ever outputs a raw %%trh_...%% variable, replace it before it reaches Google. */
foreach ( array( 'wpseo_title', 'wpseo_metadesc', 'wpseo_opengraph_title', 'wpseo_opengraph_desc', 'wpseo_twitter_title', 'wpseo_twitter_description' ) as $trhpe_f ) {
	add_filter( $trhpe_f, function ( $text ) {
		return is_string( $text ) && false !== strpos( $text, '%%trh_' ) ? strtr( $text, trhpe_var_values() ) : $text;
	}, 99 );
}
add_filter( 'wpseo_schema_webpage', function ( $data ) {
	if ( isset( $data['name'] ) && is_string( $data['name'] ) && false !== strpos( $data['name'], '%%trh_' ) ) {
		$data['name'] = strtr( $data['name'], trhpe_var_values() );
	}
	return $data;
} );

/* Yoast editor: make the Google preview show real values instead of %%trh_...%%. */
add_action( 'admin_footer', function () {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'post' !== $screen->base || ! defined( 'WPSEO_VERSION' ) ) {
		return;
	}
	?>
	<script id="trhpe-yoast-vars">
	(function () {
		var vals = <?php echo wp_json_encode( trhpe_var_values() ); ?>;
		function rep(t) { if (typeof t !== 'string') { return t; } Object.keys(vals).forEach(function (k) { t = t.split(k).join(vals[k]); }); return t; }
		function boot() {
			if (!window.YoastSEO || !YoastSEO.app || !YoastSEO.app.registerPlugin) { return; }
			YoastSEO.app.registerPlugin('trhpeVars', { status: 'ready' });
			['data_page_title', 'data_meta_desc'].forEach(function (f) { YoastSEO.app.registerModification(f, rep, 'trhpeVars', 10); });
		}
		if (window.YoastSEO && window.YoastSEO.app) { boot(); } else { window.addEventListener('YoastSEO:ready', boot); }
	})();
	</script>
	<?php
} );

/* -------------------------------------------------------------------------
 * 5. STRUCTURED DATA — Menu JSON-LD built from the same data (no drift).
 * ---------------------------------------------------------------------- */
add_action( 'wp_footer', function () {
	$s = trhpe_settings();
	if ( empty( $s['menu_schema'] ) || empty( $GLOBALS['trhpe_tables_on_page'] ) || ! is_singular() ) {
		return;
	}
	$sections = array();
	foreach ( $GLOBALS['trhpe_tables_on_page'] as $t ) {
		$items = array();
		foreach ( $t['items'] as $it ) {
			$mi = array(
				'@type'  => 'MenuItem',
				'name'   => $it['name'],
				'offers' => array( '@type' => 'Offer', 'price' => number_format( (float) $it['price'], 2, '.', '' ), 'priceCurrency' => 'USD' ),
			);
			if ( preg_match( '/^\d[\d,]*$/', (string) $it['calories'] ) ) {
				$mi['nutrition'] = array( '@type' => 'NutritionInformation', 'calories' => str_replace( ',', '', $it['calories'] ) . ' calories' );
			}
			$items[] = $mi;
		}
		$sections[] = array( '@type' => 'MenuSection', 'name' => ucwords( str_replace( '-', ' ', $t['category'] ) ), 'hasMenuItem' => $items );
	}
	$meta = trhpe_meta();
	$data = array(
		'@context'       => 'https://schema.org',
		'@type'          => 'Menu',
		'name'           => 'Texas Roadhouse menu prices (independent price guide)',
		'description'    => 'Typical U.S. prices compiled by ' . get_bloginfo( 'name' ) . '. Prices vary by location. Not affiliated with Texas Roadhouse.',
		'url'            => get_permalink(),
		'dateModified'   => $meta['last_verified'] ? $meta['last_verified'] : get_the_modified_date( 'Y-m-d' ),
		'about'          => array( '@type' => 'Restaurant', 'name' => 'Texas Roadhouse', 'url' => 'https://www.texasroadhouse.com/', 'servesCuisine' => 'American steakhouse' ),
		'hasMenuSection' => $sections,
	);
	echo "\n<script type=\"application/ld+json\" class=\"trhpe-schema\">" . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
}, 20 );

/* -------------------------------------------------------------------------
 * 6. AFTER A DATA CHANGE — honest modified dates + cache purge.
 * ---------------------------------------------------------------------- */
function trhpe_after_data_change() {
	$s = trhpe_settings();
	if ( ! empty( $s['touch_modified'] ) ) {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('post','page') AND post_content LIKE '%[trh\\_%'" ); // phpcs:ignore
		$now = current_time( 'mysql' );
		$gmt = current_time( 'mysql', true );
		foreach ( $ids as $id ) {
			$wpdb->update( $wpdb->posts, array( 'post_modified' => $now, 'post_modified_gmt' => $gmt ), array( 'ID' => (int) $id ) ); // phpcs:ignore
			clean_post_cache( (int) $id );
		}
	}
	// Purge common page caches (each call is skipped if that plugin isn't installed).
	if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); }
	if ( function_exists( 'w3tc_flush_all' ) ) { w3tc_flush_all(); }
	if ( function_exists( 'wp_cache_clear_cache' ) ) { wp_cache_clear_cache(); }
	if ( function_exists( 'sg_cachepress_purge_cache' ) ) { sg_cachepress_purge_cache(); }
	do_action( 'litespeed_purge_all' );
	do_action( 'breeze_clear_all_cache' );
	do_action( 'trhpe_data_changed' );
}

/* -------------------------------------------------------------------------
 * 7. GOOGLE SHEET AUTO-SYNC (WP-Cron, twice daily) + manual "Sync now".
 * ---------------------------------------------------------------------- */
function trhpe_fetch_csv( $url ) {
	$res = wp_remote_get( $url, array( 'timeout' => 20 ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	if ( 200 !== wp_remote_retrieve_response_code( $res ) ) {
		return new WP_Error( 'trhpe_http', 'Sheet returned HTTP ' . wp_remote_retrieve_response_code( $res ) );
	}
	$body = wp_remote_retrieve_body( $res );
	if ( stripos( $body, '<html' ) !== false ) {
		return new WP_Error( 'trhpe_html', 'Got a web page, not CSV. Use File → Share → Publish to web → CSV link.' );
	}
	return $body;
}

function trhpe_sync_from_sheet() {
	$s   = trhpe_settings();
	$out = array();
	if ( $s['feed_base_url'] ) {
		return trhpe_sync_from_feed();
	}
	if ( $s['sheet_csv_url'] ) {
		$csv = trhpe_fetch_csv( $s['sheet_csv_url'] );
		if ( is_wp_error( $csv ) ) {
			trhpe_log( 'Sheet sync failed: ' . $csv->get_error_message() );
			$out['items'] = $csv;
		} else {
			$rows         = trhpe_parse_csv( $csv );
			$out['items'] = is_wp_error( $rows ) ? $rows : trhpe_import_items( $rows, 'sheet' );
		}
	}
	if ( $s['events_csv_url'] ) {
		$csv = trhpe_fetch_csv( $s['events_csv_url'] );
		if ( ! is_wp_error( $csv ) ) {
			$rows = trhpe_parse_csv( $csv );
			if ( ! is_wp_error( $rows ) ) {
				$out['events'] = trhpe_import_events( $rows, 'sheet' );
			}
		}
	}
	return $out;
}
add_action( 'trhpe_cron_sync', 'trhpe_sync_from_sheet' );

/* -------------------------------------------------------------------------
 * 7b. DATA FEED (hands-off mode) — one folder URL with:
 *     items.csv · events.csv · meta.json · pages.json (+ pages/*.html)
 *     The publisher (Claude, monthly) updates the folder; this site pulls it twice a day.
 * ---------------------------------------------------------------------- */
function trhpe_feed_url( $file ) {
	$s = trhpe_settings();
	return trailingslashit( $s['feed_base_url'] ) . ltrim( $file, '/' ) . '?t=' . time() . wp_rand( 100, 999 );
}

function trhpe_sync_from_feed() {
	$s   = trhpe_settings();
	$out = array();
	$csv = trhpe_fetch_csv( trhpe_feed_url( 'items.csv' ) );
	if ( is_wp_error( $csv ) ) {
		trhpe_log( 'Feed items.csv failed: ' . $csv->get_error_message() );
		$out['items'] = $csv;
	} else {
		$rows         = trhpe_parse_csv( $csv );
		$out['items'] = is_wp_error( $rows ) ? $rows : trhpe_import_items( $rows, 'feed' );
	}
	$csv = trhpe_fetch_csv( trhpe_feed_url( 'events.csv' ) );
	if ( ! is_wp_error( $csv ) ) {
		$rows = trhpe_parse_csv( $csv );
		if ( ! is_wp_error( $rows ) ) {
			$out['events'] = trhpe_import_events( $rows, 'feed' );
		}
	}
	$meta_json = trhpe_fetch_csv( trhpe_feed_url( 'meta.json' ) );
	if ( ! is_wp_error( $meta_json ) ) {
		$m = json_decode( $meta_json, true );
		if ( is_array( $m ) ) {
			$meta = trhpe_meta();
			$old  = $meta;
			if ( ! empty( $m['last_verified'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $m['last_verified'] ) ) {
				$meta['last_verified'] = $m['last_verified'];
			}
			if ( isset( $m['stores_checked'] ) ) {
				$meta['stores_checked'] = sanitize_text_field( $m['stores_checked'] );
			}
			if ( $meta != $old ) { // phpcs:ignore
				update_option( TRHPE_OPT_META, $meta, false );
				trhpe_after_data_change();
			}
		}
	}
	if ( ! empty( $s['feed_pages'] ) ) {
		$out['pages'] = trhpe_sync_pages_feed();
	}
	trhpe_sync_redirects_feed();
	$sec = trhpe_sync_sections_feed();
	if ( $sec ) {
		$out['pages'] = array_merge( isset( $out['pages'] ) ? (array) $out['pages'] : array(), $sec );
	}
	return $out;
}
/* -------------------------------------------------------------------------
 * 7c. REDIRECTS + SECTION UPDATES from the feed
 *     redirects.json = [ { "from": "/old-slug/", "to": "/new-slug/" } ]
 *       → 301 only once the target is published; old URL leaves the Yoast sitemap.
 *     sections.json  = [ { "id","slug","heading","file","requires" } ]
 *       → replaces ONE H2 section (heading → next H2) of an existing page/post.
 *         Needs "Allow takeover". Applied once per file version; old version stays in Revisions.
 * ---------------------------------------------------------------------- */
function trhpe_path( $url ) {
	$p = wp_parse_url( $url, PHP_URL_PATH );
	return '/' . trim( (string) $p, '/' ) . '/';
}

function trhpe_published_by_path( $path ) {
	$id = url_to_postid( home_url( $path ) );
	return ( $id && 'publish' === get_post_status( $id ) ) ? $id : 0;
}

function trhpe_sync_redirects_feed() {
	$json = trhpe_fetch_csv( trhpe_feed_url( 'redirects.json' ) );
	if ( is_wp_error( $json ) ) {
		return;
	}
	$list = json_decode( $json, true );
	if ( ! is_array( $list ) ) {
		return;
	}
	$clean = array();
	foreach ( $list as $r ) {
		if ( ! empty( $r['from'] ) && ! empty( $r['to'] ) ) {
			$clean[ trhpe_path( $r['from'] ) ] = trhpe_path( $r['to'] );
		}
	}
	if ( $clean !== get_option( 'trhpe_redirects' ) ) {
		update_option( 'trhpe_redirects', $clean, true );
		trhpe_log( '[feed] redirects: ' . count( $clean ) . ' rules' );
	}
}

add_action( 'template_redirect', function () {
	$rules = get_option( 'trhpe_redirects', array() );
	if ( ! $rules || is_admin() ) {
		return;
	}
	$req = trhpe_path( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '' ); // phpcs:ignore
	if ( isset( $rules[ $req ] ) && trhpe_published_by_path( $rules[ $req ] ) ) {
		wp_safe_redirect( home_url( $rules[ $req ] ), 301, 'TRH Price Engine' );
		exit;
	}
}, 1 );

/* Keep redirected (old) URLs out of the Yoast sitemap once their redirect is active. */
add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', function ( $ids ) {
	foreach ( (array) get_option( 'trhpe_redirects', array() ) as $from => $to ) {
		$old = url_to_postid( home_url( $from ) );
		if ( $old && trhpe_published_by_path( $to ) ) {
			$ids[] = $old;
		}
	}
	return $ids;
} );

function trhpe_sync_sections_feed() {
	$s = trhpe_settings();
	if ( empty( $s['allow_takeover'] ) ) {
		return array();
	}
	$json = trhpe_fetch_csv( trhpe_feed_url( 'sections.json' ) );
	if ( is_wp_error( $json ) ) {
		return array();
	}
	$list   = json_decode( $json, true );
	$report = array();
	foreach ( (array) $list as $row ) {
		// SEO-only row: { "id", "slug", "seo_title"?, "meta"? } — updates Yoast fields of an existing page.
		if ( ! empty( $row['id'] ) && ! empty( $row['slug'] ) && empty( $row['file'] ) && ( ! empty( $row['seo_title'] ) || ! empty( $row['meta'] ) ) ) {
			$posts = get_posts( array( 'name' => sanitize_title( $row['slug'] ), 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'numberposts' => 1 ) );
			if ( ! $posts ) {
				continue;
			}
			$key  = '_trhpe_seo_' . sanitize_key( $row['id'] );
			$hash = md5( wp_json_encode( $row ) );
			if ( get_post_meta( $posts[0]->ID, $key, true ) === $hash ) {
				continue;
			}
			if ( ! empty( $row['seo_title'] ) ) {
				update_post_meta( $posts[0]->ID, '_yoast_wpseo_title', sanitize_text_field( $row['seo_title'] ) );
			}
			if ( ! empty( $row['meta'] ) ) {
				update_post_meta( $posts[0]->ID, '_yoast_wpseo_metadesc', sanitize_text_field( $row['meta'] ) );
			}
			update_post_meta( $posts[0]->ID, $key, $hash );
			$report[] = $row['id'] . ': SEO title/meta updated on /' . $posts[0]->post_name . '/';
			continue;
		}
		if ( empty( $row['id'] ) || empty( $row['slug'] ) || empty( $row['heading'] ) || empty( $row['file'] ) || false !== strpos( $row['file'], '..' ) ) {
			continue;
		}
		if ( ! empty( $row['requires'] ) && ! trhpe_published_by_path( '/' . $row['requires'] . '/' ) ) {
			continue; // wait until the page it links to is live.
		}
		$posts = get_posts( array( 'name' => sanitize_title( $row['slug'] ), 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'numberposts' => 1 ) );
		if ( ! $posts ) {
			$report[] = $row['id'] . ': page not found';
			continue;
		}
		$post = $posts[0];
		$res  = wp_remote_get( trhpe_feed_url( $row['file'] ), array( 'timeout' => 20 ) );
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			continue;
		}
		$html = trim( wp_remote_retrieve_body( $res ) );
		$key  = '_trhpe_section_' . sanitize_key( $row['id'] );
		if ( get_post_meta( $post->ID, $key, true ) === md5( $html ) ) {
			continue;
		}
		$c = $post->post_content;
		// Find the H2 whose text contains the heading.
		if ( ! preg_match_all( '#<h2\b[^>]*>(.*?)</h2>#is', $c, $m, PREG_OFFSET_CAPTURE ) ) {
			$report[] = $row['id'] . ': no H2 headings on page';
			continue;
		}
		$start = -1;
		$end   = strlen( $c );
		foreach ( $m[0] as $i => $h ) {
			if ( $start < 0 && false !== stripos( wp_strip_all_tags( $m[1][ $i ][0] ), $row['heading'] ) ) {
				$start = $h[1];
				if ( isset( $m[0][ $i + 1 ] ) ) {
					$end = $m[0][ $i + 1 ][1];
				}
			}
		}
		if ( $start < 0 ) {
			$report[] = $row['id'] . ': heading "' . $row['heading'] . '" not found';
			continue;
		}
		// Step back to include the block comment that opens each heading.
		$back = function ( $pos ) use ( $c ) {
			$open = strrpos( substr( $c, 0, $pos ), '<!-- wp:heading' );
			return ( false !== $open && '' === trim( preg_replace( '#^<!-- wp:heading[^>]*-->#', '', substr( $c, $open, $pos - $open ) ) ) ) ? $open : $pos;
		};
		$start = $back( $start );
		if ( $end < strlen( $c ) ) {
			$end = $back( $end );
		}
		$new = substr( $c, 0, $start ) . $html . "\n\n" . substr( $c, $end );
		$ok  = wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $new ) ), true );
		if ( is_wp_error( $ok ) ) {
			$report[] = $row['id'] . ': ' . $ok->get_error_message();
			continue;
		}
		update_post_meta( $post->ID, $key, md5( $html ) );
		$report[] = $row['id'] . ': section updated on /' . $post->post_name . '/';
	}
	if ( $report ) {
		trhpe_log( '[feed] sections — ' . implode( ' | ', $report ) );
	}
	return $report;
}


/**
 * pages.json = [ { "slug","type","title","seo_title","meta","focus","category","status","file","takeover","date" } ]
 * Rules: new slug → created. Slug created earlier by the feed → updated when the file changes.
 * Slug that exists but was NOT created by the feed → skipped, unless "takeover": true AND the
 * "allow takeover" setting is on (WordPress revisions keep the old version for rollback).
 */
function trhpe_sync_pages_feed() {
	$s    = trhpe_settings();
	$json = trhpe_fetch_csv( trhpe_feed_url( 'pages.json' ) );
	if ( is_wp_error( $json ) ) {
		return array();
	}
	$list = json_decode( $json, true );
	if ( ! is_array( $list ) ) {
		trhpe_log( 'pages.json is not valid JSON' );
		return array();
	}
	$report = array();
	foreach ( $list as $row ) {
		$front = isset( $row['slug'] ) && '__front_page__' === $row['slug'];
		if ( $front ) {
			$fid  = (int) get_option( 'page_on_front' );
			$fp   = $fid ? get_post( $fid ) : null;
			if ( ! $fp ) {
				$report[] = '__front_page__: site has no static front page — skipped';
				continue;
			}
			$row['slug'] = $fp->post_name;
		}
		$slug = sanitize_title( isset( $row['slug'] ) ? $row['slug'] : '' );
		$file = isset( $row['file'] ) ? ltrim( (string) $row['file'], '/' ) : '';
		if ( '' === $slug || '' === $file || false !== strpos( $file, '..' ) ) {
			continue;
		}
		$type = ( isset( $row['type'] ) && 'page' === $row['type'] ) ? 'page' : 'post';
		$html = trhpe_fetch_csv( trhpe_feed_url( $file ) );
		if ( is_wp_error( $html ) && false !== strpos( $html->get_error_message(), 'web page' ) ) {
			// fetch_csv rejects HTML on purpose; fetch pages directly.
			$res  = wp_remote_get( trhpe_feed_url( $file ), array( 'timeout' => 20 ) );
			$html = ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) ? wp_remote_retrieve_body( $res ) : $html;
		}
		if ( is_wp_error( $html ) || '' === trim( $html ) ) {
			$report[] = "$slug: could not fetch $file";
			continue;
		}
		$hash     = md5( $html . wp_json_encode( $row ) );
		// Look for the slug as a post OR a page, so a type mismatch never creates a duplicate ("slug-2").
		$existing = get_posts( array( 'name' => $slug, 'post_type' => array( 'post', 'page' ), 'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private' ), 'numberposts' => 1 ) );
		$post_id  = $existing ? $existing[0]->ID : 0;
		if ( $existing ) {
			$type = $existing[0]->post_type;
		}
		if ( $post_id ) {
			$owned = get_post_meta( $post_id, '_trhpe_feed', true );
			if ( ! $owned && ! ( ! empty( $s['allow_takeover'] ) && ! empty( $row['takeover'] ) ) ) {
				$report[] = "$slug: exists and was not created by the feed — skipped";
				continue;
			}
			if ( get_post_meta( $post_id, '_trhpe_hash', true ) === $hash ) {
				continue;
			}
		}
		$status = isset( $row['status'] ) && in_array( $row['status'], array( 'publish', 'draft', 'future' ), true ) ? $row['status'] : 'draft';
		if ( ! empty( $s['force_draft'] ) ) {
			$status = 'draft';
		}
		$data = array(
			'post_type'    => $type,
			'post_name'    => $slug,
			'post_title'   => sanitize_text_field( isset( $row['title'] ) ? $row['title'] : $slug ),
			'post_content' => wp_kses_post( $html ),
			'post_status'  => $status,
		);
		if ( ! empty( $row['date'] ) ) {
			$data['post_date'] = sanitize_text_field( $row['date'] );
		}
		if ( 'post' === $type && ! empty( $row['category'] ) ) {
			$cat = get_term_by( 'name', $row['category'], 'category' );
			if ( $cat ) {
				$data['post_category'] = array( (int) $cat->term_id );
			}
		}
		if ( $post_id ) {
			$data['ID'] = $post_id;
			if ( 'publish' === get_post_status( $post_id ) && 'draft' === $status ) {
				$data['post_status'] = 'publish'; // never un-publish a live page.
			}
			$result = wp_update_post( wp_slash( $data ), true );
		} else {
			$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
			$data['post_author'] = $admins ? (int) $admins[0] : 1;
			$result = wp_insert_post( wp_slash( $data ), true );
		}
		if ( is_wp_error( $result ) ) {
			$report[] = "$slug: " . $result->get_error_message();
			continue;
		}
		update_post_meta( $result, '_trhpe_feed', 1 );
		update_post_meta( $result, '_trhpe_hash', $hash );
		foreach ( array( 'seo_title' => '_yoast_wpseo_title', 'meta' => '_yoast_wpseo_metadesc', 'focus' => '_yoast_wpseo_focuskw' ) as $k => $mk ) {
			if ( ! empty( $row[ $k ] ) ) {
				update_post_meta( $result, $mk, sanitize_text_field( $row[ $k ] ) );
			}
		}
		$report[] = $slug . ': ' . ( $post_id ? 'updated' : 'created' ) . " ($status)";
	}
	if ( $report ) {
		trhpe_log( '[feed] pages — ' . implode( ' | ', $report ) );
		trhpe_after_data_change();
	}
	return $report;
}


register_activation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'trhpe_cron_sync' );
	wp_schedule_event( time() + 300, 'hourly', 'trhpe_cron_sync' );
	if ( ! get_option( TRHPE_OPT_EVENTS ) ) {
		update_option( TRHPE_OPT_EVENTS, trhpe_default_events(), false );
	}
} );
add_action( 'init', function () {
	if ( get_option( 'trhpe_sched' ) !== 'hourly' ) {
		wp_clear_scheduled_hook( 'trhpe_cron_sync' );
		wp_schedule_event( time() + 300, 'hourly', 'trhpe_cron_sync' );
		update_option( 'trhpe_sched', 'hourly', false );
	}
} );
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'trhpe_cron_sync' );
} );

/* -------------------------------------------------------------------------
 * 8. REST API — push from the Python script / GitHub Actions.
 *    POST /wp-json/trhpe/v1/items   body: {"csv":"..."}  or {"rows":[...]}
 *    POST /wp-json/trhpe/v1/events  same shape
 *    POST /wp-json/trhpe/v1/meta    {"stores_checked":"...", "last_verified":"YYYY-MM-DD"}
 *    GET  /wp-json/trhpe/v1/items   current data (for the audit script)
 *    Auth: WordPress Application Password of an admin user.
 * ---------------------------------------------------------------------- */
add_action( 'rest_api_init', function () {
	$perm = function () {
		return current_user_can( 'manage_options' );
	};
	$rows_from = function ( WP_REST_Request $r ) {
		$p = $r->get_json_params();
		if ( ! empty( $p['csv'] ) ) {
			return trhpe_parse_csv( $p['csv'] );
		}
		return ( ! empty( $p['rows'] ) && is_array( $p['rows'] ) ) ? $p['rows'] : new WP_Error( 'trhpe_empty', 'Send csv or rows.', array( 'status' => 400 ) );
	};
	register_rest_route( 'trhpe/v1', '/items', array(
		array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function () {
			return array( 'meta' => trhpe_meta(), 'items' => array_values( trhpe_items() ) );
		} ),
		array( 'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) use ( $rows_from ) {
			$rows = $rows_from( $r );
			return is_wp_error( $rows ) ? $rows : trhpe_import_items( $rows, 'api' );
		} ),
	) );
	register_rest_route( 'trhpe/v1', '/events', array(
		'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) use ( $rows_from ) {
			$rows = $rows_from( $r );
			return is_wp_error( $rows ) ? $rows : array( 'saved' => trhpe_import_events( $rows, 'api' ) );
		},
	) );
	register_rest_route( 'trhpe/v1', '/meta', array(
		'methods' => 'POST', 'permission_callback' => $perm, 'callback' => function ( WP_REST_Request $r ) {
			$p    = $r->get_json_params();
			$meta = trhpe_meta();
			if ( isset( $p['stores_checked'] ) ) {
				$meta['stores_checked'] = sanitize_text_field( $p['stores_checked'] );
			}
			if ( isset( $p['last_verified'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $p['last_verified'] ) ) {
				$meta['last_verified'] = $p['last_verified'];
			}
			update_option( TRHPE_OPT_META, $meta, false );
			trhpe_after_data_change();
			return $meta;
		},
	) );
} );

/* Let the publish script set Yoast title / meta description / focus keyphrase over the REST API. */
add_action( 'init', function () {
	foreach ( array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw' ) as $key ) {
		foreach ( array( 'post', 'page' ) as $type ) {
			register_post_meta( $type, $key, array(
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			) );
		}
	}
} );

/* -------------------------------------------------------------------------
 * 9. ADMIN SCREEN — Tools → TRH Prices
 * ---------------------------------------------------------------------- */
add_action( 'admin_menu', function () {
	add_management_page( 'TRH Prices', 'TRH Prices', 'manage_options', 'trhpe', 'trhpe_admin_page' );
} );

function trhpe_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$notice = '';
	if ( isset( $_POST['trhpe_action'] ) && check_admin_referer( 'trhpe_admin' ) ) {
		$action = sanitize_key( $_POST['trhpe_action'] );
		if ( 'settings' === $action ) {
			$s                   = trhpe_settings();
			$s['feed_base_url']  = esc_url_raw( wp_unslash( $_POST['feed_base_url'] ?? '' ) );
			$s['feed_pages']     = empty( $_POST['feed_pages'] ) ? 0 : 1;
			$s['allow_takeover'] = empty( $_POST['allow_takeover'] ) ? 0 : 1;
			$s['force_draft']    = empty( $_POST['force_draft'] ) ? 0 : 1;
			$s['speed_fonts']     = empty( $_POST['speed_fonts'] ) ? 0 : 1;
			$s['speed_lcp']       = empty( $_POST['speed_lcp'] ) ? 0 : 1;
			$s['speed_delay_ads'] = empty( $_POST['speed_delay_ads'] ) ? 0 : 1;
			$s['sheet_csv_url']  = esc_url_raw( wp_unslash( $_POST['sheet_csv_url'] ?? '' ) );
			$s['events_csv_url'] = esc_url_raw( wp_unslash( $_POST['events_csv_url'] ?? '' ) );
			$s['max_change_pct'] = max( 5, min( 100, (int) ( $_POST['max_change_pct'] ?? 30 ) ) );
			$s['menu_schema']    = empty( $_POST['menu_schema'] ) ? 0 : 1;
			$s['touch_modified'] = empty( $_POST['touch_modified'] ) ? 0 : 1;
			update_option( TRHPE_OPT_SETTINGS, $s, false );
			$meta                   = trhpe_meta();
			$meta['stores_checked'] = sanitize_text_field( wp_unslash( $_POST['stores_checked'] ?? '' ) );
			update_option( TRHPE_OPT_META, $meta, false );
			$notice = 'Settings saved.';
		} elseif ( 'sync' === $action ) {
			$r      = trhpe_sync_from_sheet();
			$notice = 'Sync finished. ' . ( isset( $r['items'] ) && is_wp_error( $r['items'] ) ? 'Error: ' . $r['items']->get_error_message() : '' );
			if ( isset( $r['items'] ) && is_array( $r['items'] ) ) {
				$notice .= trhpe_report_html( $r['items'] );
			}
			if ( ! empty( $r['pages'] ) ) {
				$notice .= '<br><strong>Pages:</strong><br>' . implode( '<br>', array_map( 'esc_html', $r['pages'] ) );
			}
		} elseif ( 'csv' === $action ) {
			$csv = '';
			if ( ! empty( $_FILES['csv_file']['tmp_name'] ) ) {
				$csv = file_get_contents( $_FILES['csv_file']['tmp_name'] ); // phpcs:ignore
			} elseif ( ! empty( $_POST['csv_text'] ) ) {
				$csv = wp_unslash( $_POST['csv_text'] ); // phpcs:ignore
			}
			$rows   = trhpe_parse_csv( $csv );
			$type   = sanitize_key( $_POST['csv_type'] ?? 'items' );
			if ( is_wp_error( $rows ) ) {
				$notice = $rows->get_error_message();
			} elseif ( 'events' === $type ) {
				$notice = trhpe_import_events( $rows, 'upload' ) . ' events saved.';
			} else {
				$notice = 'Import done.' . trhpe_report_html( trhpe_import_items( $rows, 'upload' ) );
			}
		}
	}
	$s     = trhpe_settings();
	$meta  = trhpe_meta();
	$items = trhpe_items();
	$log   = (array) get_option( TRHPE_OPT_LOG, array() );
	?>
	<div class="wrap">
		<h1>TRH Price Engine</h1>
		<?php if ( $notice ) : ?><div class="notice notice-info"><p><?php echo wp_kses_post( $notice ); ?></p></div><?php endif; ?>
		<p><strong><?php echo count( $items ); ?></strong> items · prices last verified: <strong><?php echo esc_html( $meta['last_verified'] ?: '—' ); ?></strong> · last import: <?php echo esc_html( $meta['last_import'] ?: '—' ); ?> · next auto-sync: <?php $n = wp_next_scheduled( 'trhpe_cron_sync' ); echo $n ? esc_html( wp_date( 'M j, H:i', $n ) ) : 'not scheduled'; ?></p>

		<h2>1. Data feed (hands-off — prices, events and new pages arrive by themselves)</h2>
		<form method="post"><?php wp_nonce_field( 'trhpe_admin' ); ?><input type="hidden" name="trhpe_action" value="settings">
			<table class="form-table">
				<tr><th>Feed folder URL</th><td><input type="url" class="large-text" name="feed_base_url" value="<?php echo esc_attr( $s['feed_base_url'] ); ?>" placeholder="https://raw.githubusercontent.com/shahbazali85/trh-price-data/main/"><p class="description">When set, this replaces the Google Sheet links below.</p></td></tr>
				<tr><th>Pages from feed</th><td><label><input type="checkbox" name="feed_pages" value="1" <?php checked( $s['feed_pages'] ); ?>> Create / update posts listed in pages.json</label><br><label><input type="checkbox" name="force_draft" value="1" <?php checked( $s['force_draft'] ); ?>> Always save as draft (I want to review before anything goes live)</label><br><label><input type="checkbox" name="allow_takeover" value="1" <?php checked( $s['allow_takeover'] ); ?>> Allow the feed to rewrite existing pages marked "takeover" (old version stays in Revisions)</label></td></tr>
				<tr><th>Prices tab CSV link</th><td><input type="url" class="large-text" name="sheet_csv_url" value="<?php echo esc_attr( $s['sheet_csv_url'] ); ?>"><p class="description">Google Sheets → File → Share → Publish to web → choose the "items" tab → CSV → copy link.</p></td></tr>
				<tr><th>Events tab CSV link</th><td><input type="url" class="large-text" name="events_csv_url" value="<?php echo esc_attr( $s['events_csv_url'] ); ?>"></td></tr>
				<tr><th>Restaurants checked</th><td><input type="text" class="large-text" name="stores_checked" value="<?php echo esc_attr( $meta['stores_checked'] ); ?>" placeholder="e.g. Louisville KY, Dallas TX, Phoenix AZ, Columbus OH, Tampa FL"></td></tr>
				<tr><th>Hold back price jumps over</th><td><input type="number" name="max_change_pct" value="<?php echo (int) $s['max_change_pct']; ?>" min="5" max="100">%</td></tr>
				<tr><th>Options</th><td><label><input type="checkbox" name="menu_schema" value="1" <?php checked( $s['menu_schema'] ); ?>> Output Menu JSON-LD</label><br><label><input type="checkbox" name="touch_modified" value="1" <?php checked( $s['touch_modified'] ); ?>> Update "modified" date of pages that use the data when prices change</label></td></tr>
				<tr><th>Speed (Core Web Vitals)</th><td><label><input type="checkbox" name="speed_fonts" value="1" <?php checked( $s['speed_fonts'] ); ?>> Lighter Google Fonts (same fonts, 4 files instead of 36, no layout shift)</label><br><label><input type="checkbox" name="speed_lcp" value="1" <?php checked( $s['speed_lcp'] ); ?>> Preload the featured image (faster LCP)</label><br><label><input type="checkbox" name="speed_delay_ads" value="1" <?php checked( $s['speed_delay_ads'] ); ?>> Start AdSense after first scroll/tap or 4 s (faster page; watch ad revenue for a week)</label></td></tr>
			</table>
			<?php submit_button( 'Save settings' ); ?>
		</form>
		<form method="post"><?php wp_nonce_field( 'trhpe_admin' ); ?><input type="hidden" name="trhpe_action" value="sync"><?php submit_button( 'Sync now', 'secondary' ); ?></form>

		<h2>2. Or upload / paste a CSV</h2>
		<form method="post" enctype="multipart/form-data"><?php wp_nonce_field( 'trhpe_admin' ); ?><input type="hidden" name="trhpe_action" value="csv">
			<p><select name="csv_type"><option value="items">Prices (items)</option><option value="events">Events / holidays</option></select> <input type="file" name="csv_file" accept=".csv"></p>
			<p><textarea name="csv_text" rows="5" class="large-text code" placeholder="id,name,category,price,price_max,calories,serves,note,status,verified_on"></textarea></p>
			<?php submit_button( 'Import CSV', 'secondary' ); ?>
		</form>

		<h2>Current data</h2>
		<table class="widefat striped"><thead><tr><th>id (use in shortcodes)</th><th>Name</th><th>Category</th><th>Price</th><th>Calories</th><th>Status</th><th>Verified</th></tr></thead><tbody>
		<?php foreach ( $items as $it ) : ?>
			<tr><td><code><?php echo esc_html( $it['id'] ); ?></code></td><td><?php echo esc_html( $it['name'] ); ?></td><td><?php echo esc_html( $it['category'] ); ?></td><td><?php echo esc_html( trhpe_fmt_price( $it ) ); ?></td><td><?php echo esc_html( $it['calories'] ); ?></td><td><?php echo esc_html( $it['status'] ); ?></td><td><?php echo esc_html( $it['verified_on'] ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>

		<h2>Shortcode cheat-sheet</h2>
		<pre style="background:#fff;padding:12px;border:1px solid #ccd0d4;white-space:pre-wrap">
[trh_table category="appetizers"]                 full priced table for a category (+ Menu schema)
[trh_table ids="cactus-blossom,fried-pickles" cols="name,price"]
[trh_price id="cactus-blossom"]                   → $9.99
[trh_price id="cactus-blossom" field="calories"]  → 2,250
[trh_range category="family-meals"]               → lowest–highest price in that category
[trh_updated]                                     → date prices were last checked
[trh_stores]                                      → restaurants checked
[trh_year]                                        → current year
[trh_holiday id="thanksgiving"]                   → Thursday, November 26, 2026 (auto every year)
[trh_holiday id="thanksgiving" field="status"]    → Closed
[trh_countdown id="veterans-day"]                 → 25 days away
[trh_holiday_table]                               → next 12 months of holidays, open/closed
[trh_event id="veterans-day"]                     → event box with confirmed / not-confirmed badge
[trh_badge]                                       → "✓ Typical U.S. prices · checked Oct 17, 2026" pill
Yoast title variables: %%trh_month_year%%  %%trh_year%%  %%trh_updated%%</pre>

		<h2>Log</h2>
		<pre style="background:#fff;padding:12px;border:1px solid #ccd0d4;max-height:220px;overflow:auto"><?php echo esc_html( implode( "\n", $log ) ); ?></pre>
	</div>
	<?php
}

function trhpe_report_html( $r ) {
	if ( ! is_array( $r ) ) {
		return '';
	}
	$h = sprintf( '<br>Added %d · changed %d · unchanged %d', $r['added'], $r['changed'], $r['unchanged'] );
	foreach ( array( 'changes' => 'Price changes', 'held' => '⚠ Held back', 'errors' => '✖ Errors' ) as $k => $label ) {
		if ( ! empty( $r[ $k ] ) ) {
			$h .= '<br><strong>' . esc_html( $label ) . ':</strong><br>' . implode( '<br>', array_map( 'esc_html', $r[ $k ] ) );
		}
	}
	return $h;
}


/* -------------------------------------------------------------------------
 * 11. SPEED (Core Web Vitals) — each part can be switched off in Tools → TRH Prices.
 * ---------------------------------------------------------------------- */

/* 11a. Fonts: GeneratePress requests 36 Roboto/Montserrat files with display=auto.
 *      The theme only uses Roboto 400/700 (+ italic) and Montserrat 700. Same look, far fewer files,
 *      and display=optional means text never re-flows when the font arrives (no CLS). */
add_action( 'wp_enqueue_scripts', function () {
	$s = trhpe_settings();
	if ( empty( $s['speed_fonts'] ) ) {
		return;
	}
	global $wp_styles;
	$h = 'generate-google-fonts';
	if ( ! isset( $wp_styles->registered[ $h ] ) ) {
		return;
	}
	$src = (string) $wp_styles->registered[ $h ]->src;
	// Only act on the font set we analysed; if the owner changes fonts in the Customizer, leave it alone.
	if ( false === stripos( $src, 'Roboto' ) || false === stripos( $src, 'Montserrat' ) ) {
		return;
	}
	wp_dequeue_style( $h );
	wp_deregister_style( $h );
	wp_enqueue_style( 'trhpe-fonts', 'https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Roboto:ital,wght@0,400;0,700;1,400&display=optional', array(), null );
}, 100 );

/* 11b. LCP: preload the featured image that GeneratePress shows above the title. */
add_action( 'wp_head', function () {
	$s = trhpe_settings();
	if ( empty( $s['speed_lcp'] ) || ! is_singular() || ! has_post_thumbnail() ) {
		return;
	}
	$id = get_post_thumbnail_id();
	$src = wp_get_attachment_image_src( $id, 'full' );
	if ( ! $src ) {
		return;
	}
	$srcset = wp_get_attachment_image_srcset( $id, 'full' );
	echo '<link rel="preload" as="image" fetchpriority="high" href="' . esc_url( $src[0] ) . '"' . ( $srcset ? ' imagesrcset="' . esc_attr( $srcset ) . '" imagesizes="(max-width: 1280px) 100vw, 1280px"' : '' ) . ">\n";
}, 2 );

/* 11c. Optional: start AdSense after the first scroll/tap/mouse move, or after 4 seconds. */
add_action( 'template_redirect', function () {
	$s = trhpe_settings();
	if ( empty( $s['speed_delay_ads'] ) || is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	ob_start( function ( $html ) {
		if ( false === strpos( $html, 'adsbygoogle.js' ) ) {
			return $html;
		}
		$html = preg_replace( '#<script([^>]*)\ssrc=(["\'])(https://pagead2\.googlesyndication\.com/pagead/js/adsbygoogle\.js[^"\']*)\2([^>]*)></script>#i', '<script type="text/trh-delayed" data-src="$3"></script>', $html, 1 );
		$js = "<script id=\"trhpe-ads\">(function(){var d=false;function go(){if(d)return;d=true;var o=document.querySelector('script[type=\"text/trh-delayed\"]');if(!o)return;var s=document.createElement('script');s.async=true;s.src=o.getAttribute('data-src');s.crossOrigin='anonymous';document.head.appendChild(s);}['scroll','touchstart','mousemove','keydown','click'].forEach(function(e){window.addEventListener(e,go,{once:true,passive:true});});setTimeout(go,4000);})();</script>";
		return str_replace( '</body>', $js . '</body>', $html );
	} );
}, 1 );

/* -------------------------------------------------------------------------
 * 10. Minimal front-end styles (mobile-friendly tables).
 * ---------------------------------------------------------------------- */
add_action( 'wp_head', function () {
	echo '<style id="trhpe-css">'
		. ':root{--trh-red:#b5121b;--trh-ink:#1d1d1f;--trh-mute:#6b6b70;--trh-line:#e7e3dc;--trh-cream:#faf7f2;--trh-ok:#1f7a3a;--trh-okbg:#e8f5ec;--trh-no:#b42318;--trh-nobg:#fdecea;--trh-var:#8a5a00;--trh-varbg:#fff4dc}'
		. '.trh-menu,.trh-hol-wrap{margin:1.4em 0 1.8em}'
		. '.trh-menu .trh-table{width:100%;border-collapse:separate;border-spacing:0;border:1px solid var(--trh-line);border-radius:12px;overflow:hidden;font-size:.97em;margin:0;table-layout:auto}'
		. '.trh-menu .trh-table caption{caption-side:top;text-align:left;font-weight:700;padding:0 0 .5em;color:var(--trh-ink)}'
		. '.trh-menu .trh-table th,.trh-menu .trh-table td{padding:.7em .9em;border:0;border-bottom:1px solid var(--trh-line);text-align:left;vertical-align:middle;background:transparent}'
		. '.trh-menu .trh-table thead th{background:var(--trh-ink);color:#fff;font-size:.78em;letter-spacing:.06em;text-transform:uppercase;font-weight:700}'
		. '.trh-menu .trh-table tbody tr:nth-child(even){background:var(--trh-cream)}'
		. '.trh-menu .trh-table tbody tr:last-child>*{border-bottom:0}'
		. '.trh-menu .trh-table tbody th{font-weight:600;color:var(--trh-ink)}'
		. '.trh-menu .trh-table .trh-num{text-align:right;white-space:nowrap}'
		. '.trh-item{display:block}.trh-note{display:block;font-weight:400;font-size:.82em;color:var(--trh-mute);margin-top:.15em;line-height:1.35}'
		. '.trh-price{font-weight:700;color:var(--trh-red);font-variant-numeric:tabular-nums}'
		. '.trh-tag{display:inline-block;margin-left:.35em;font-size:.7em;font-weight:600;color:var(--trh-mute);border:1px solid var(--trh-line);border-radius:999px;padding:0 .45em;vertical-align:middle}'
		. '.trh-foot{font-size:.8em;color:var(--trh-mute);margin:.5em .2em 0}'
		/* Defense: other plugins that inject widgets into table rows can't add columns. */
		. '.trh-menu .trh-table tr>:not(.trh-c){display:none!important}'
		. '.trh-hol-wrap .trh-hol{list-style:none;margin:0;padding:0;border:1px solid var(--trh-line);border-radius:12px;overflow:hidden}'
		. '.trh-hol-wrap .trh-hol>li{margin:0}'
		. '.trh-hol__year{background:var(--trh-ink);color:#fff;font-weight:700;font-size:.8em;letter-spacing:.08em;padding:.35em 1em}'
		. '.trh-hol__row{display:flex;align-items:center;gap:.9em;padding:.75em 1em;border-bottom:1px solid var(--trh-line);background:#fff}'
		. '.trh-hol__row:last-child{border-bottom:0}'
		. '.trh-hol__row.is-next{background:var(--trh-cream);box-shadow:inset 4px 0 0 var(--trh-red)}'
		. '.trh-hol__date{flex:0 0 3.4em;display:flex;flex-direction:column;align-items:center;border:1px solid var(--trh-line);border-radius:10px;background:#fff;line-height:1.1;padding:.3em 0;text-align:center}'
		. '.trh-hol__date .m{font-size:.66em;font-weight:700;text-transform:uppercase;color:var(--trh-red);letter-spacing:.06em}'
		. '.trh-hol__date .d{font-size:1.25em;font-weight:800;color:var(--trh-ink)}'
		. '.trh-hol__date .w{font-size:.66em;color:var(--trh-mute)}'
		. '.trh-hol__body{flex:1 1 auto;min-width:0}'
		. '.trh-hol__name{font-weight:700;color:var(--trh-ink)}'
		. '.trh-hol__note,.trh-hol__offer{display:block;font-size:.85em;color:var(--trh-mute);line-height:1.35;margin-top:.1em}'
		. '.trh-hol__offer{color:var(--trh-var)}'
		. '.trh-next{display:inline-block;font-size:.72em;font-weight:700;color:#fff;background:var(--trh-red);border-radius:999px;padding:.1em .55em;vertical-align:middle}'
		. '.trh-pill{flex:0 0 auto;font-size:.78em;font-weight:700;border-radius:999px;padding:.25em .7em;white-space:nowrap}'
		. '.is-open .trh-pill{color:var(--trh-ok);background:var(--trh-okbg)}.is-closed .trh-pill{color:var(--trh-no);background:var(--trh-nobg)}.is-varies .trh-pill{color:var(--trh-var);background:var(--trh-varbg)}'
		. '.trh-event{border:1px solid var(--trh-line);border-radius:14px;background:var(--trh-cream);padding:1em 1.1em;margin:1.4em 0}'
		. '.trh-event__head{display:flex;align-items:center;gap:.9em}.trh-event__head>div{flex:1 1 auto}'
		. '.trh-event__title{display:block;font-weight:800;font-size:1.08em;color:var(--trh-ink)}'
		. '.trh-event__when{display:block;font-size:.85em;color:var(--trh-mute)}'
		. '.trh-event p{margin:.7em 0 0;font-size:.95em}'
		. '.trh-badge{display:inline-block;font-size:.75em;font-weight:700;border-radius:999px;padding:.15em .6em;margin-left:.3em;vertical-align:middle}'
		. '.trh-badge.is-ok{color:var(--trh-ok);background:var(--trh-okbg)}.trh-badge.is-wait{color:var(--trh-var);background:var(--trh-varbg)}'
		. '.trh-event__src a{font-weight:600}'
		. '.trh-checked{display:inline-flex;align-items:center;gap:.45em;font-size:.82em;color:var(--trh-ok);background:var(--trh-okbg);border-radius:999px;padding:.3em .85em;margin:0 0 1em}'
		. '.trh-checked__dot{font-weight:800}'
		. '.trh-answer{border-left:4px solid var(--trh-red);background:var(--trh-cream);border-radius:0 12px 12px 0;padding:.9em 1.1em;font-size:1.04em}'
		. '.trh-sources{font-size:.82em;color:var(--trh-mute);border-top:1px solid var(--trh-line);padding-top:.8em;margin-top:2em}'
		. '.wp-block-table.trh-core table{width:100%;border-collapse:separate;border-spacing:0;border:1px solid var(--trh-line);border-radius:12px;overflow:hidden}'
		. '.wp-block-table.trh-core th,.wp-block-table.trh-core td{padding:.7em .9em;border:0;border-bottom:1px solid var(--trh-line);text-align:left}'
		. '.wp-block-table.trh-core thead th{background:var(--trh-ink);color:#fff;font-size:.78em;letter-spacing:.06em;text-transform:uppercase}'
		. '.wp-block-table.trh-core thead{border:0}.wp-block-table.trh-core tbody tr:nth-child(even){background:var(--trh-cream)}.wp-block-table.trh-core tbody tr:last-child>*{border-bottom:0}'
		. '.wp-block-table.trh-core tr>:not(td):not(th){display:none!important}'
		. '.trh-print{background:var(--trh-red);color:#fff;border:0;border-radius:999px;padding:.6em 1.2em;font-weight:700;cursor:pointer}.trh-print:hover{opacity:.9}'
		. '@media print{header,footer,nav,aside,.site-header,.site-footer,.sidebar,.widget-area,.comments-area,.trh-print-wrap,ins,iframe{display:none!important}.trh-menu{break-inside:avoid}}'
		. '@media (max-width:600px){.trh-menu .trh-table{font-size:.92em}.trh-menu .trh-table th,.trh-menu .trh-table td{padding:.6em .65em}.trh-hol__row{gap:.65em;padding:.65em .75em}.trh-pill{font-size:.72em;padding:.2em .55em}}'
		. '</style>';
} );
