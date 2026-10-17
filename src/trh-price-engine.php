<?php
/**
 * Plugin Name: TRH Price Engine
 * Description: One place for every price, calorie count, holiday date and event on the site. Edit a Google Sheet (or upload a CSV) and every page updates itself — no copy-paste. Adds shortcodes, auto holiday dates, Yoast variables, Menu JSON-LD and honest "last updated" dates.
 * Version: 1.1.0
 * Author: Desire Web Solution
 * Requires PHP: 7.4
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TRHPE_VERSION', '1.1.0' );
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
		'force_draft'     => 1,   // Ignore the feed's publish status and always save as draft.
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

/** [trh_table category="appetizers" cols="name,price,calories" caption="..."] */
add_shortcode( 'trh_table', function ( $atts ) {
	$a      = shortcode_atts( array( 'category' => '', 'ids' => '', 'cols' => 'name,price,note', 'caption' => '' ), $atts );
	$labels = array( 'name' => 'Item', 'price' => 'Typical price', 'calories' => 'Calories', 'serves' => 'Serves', 'note' => 'Notes', 'status' => 'Status' );
	$cols   = array_values( array_intersect( array_map( 'trim', explode( ',', $a['cols'] ) ), array_keys( $labels ) ) );
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

	$h = '<div class="trh-table-wrap"><table class="trh-table">';
	if ( $a['caption'] ) {
		$h .= '<caption>' . esc_html( $a['caption'] ) . '</caption>';
	}
	$h .= '<thead><tr>';
	foreach ( $cols as $c ) {
		$h .= '<th scope="col">' . esc_html( $labels[ $c ] ) . '</th>';
	}
	$h .= '</tr></thead><tbody>';
	foreach ( $rows as $it ) {
		$h .= '<tr>';
		foreach ( $cols as $c ) {
			if ( 'price' === $c ) {
				$v = trhpe_fmt_price( $it );
				if ( 'seasonal' === $it['status'] ) {
					$v .= ' (seasonal)';
				}
			} else {
				$v = isset( $it[ $c ] ) ? $it[ $c ] : '';
			}
			$h .= ( 'name' === $c ? '<th scope="row">' : '<td>' ) . esc_html( '' === $v ? '—' : $v ) . ( 'name' === $c ? '</th>' : '</td>' );
		}
		$h .= '</tr>';
	}
	$h .= '</tbody></table></div>';
	return $h;
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
	$a  = shortcode_atts( array( 'id' => '', 'format' => 'l, F j, Y', 'year' => 'next', 'field' => 'date' ), $atts );
	$ev = trhpe_events();
	$id = sanitize_title( $a['id'] );
	if ( ! isset( $ev[ $id ] ) ) {
		return '';
	}
	if ( 'date' !== $a['field'] ) {
		return isset( $ev[ $id ][ $a['field'] ] ) ? wp_kses_post( $ev[ $id ][ $a['field'] ] ) : '';
	}
	$d = ( 'next' === $a['year'] ) ? trhpe_next_date( $ev[ $id ]['rule'] ) : trhpe_rule_date( $ev[ $id ]['rule'], (int) $a['year'] );
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
	return esc_html( 0 === $n ? 'today' : ( 1 === $n ? 'tomorrow' : $n . ' days away' ) );
} );

/** [trh_holiday_table year="current|next|2027"] — rolling 12-month table by default. */
add_shortcode( 'trh_holiday_table', function ( $atts ) {
	$a     = shortcode_atts( array( 'year' => 'rolling' ), $atts );
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
	$h = '<div class="trh-table-wrap"><table class="trh-table trh-holidays"><thead><tr><th scope="col">Holiday</th><th scope="col">Date</th><th scope="col">Texas Roadhouse</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		list( $d, $ev ) = $r;
		$status = $ev['status'];
		if ( $ev['offer'] ) {
			$status .= ' · ' . $ev['offer'] . ( 'yes' === $ev['confirmed'] ? '' : ' (unconfirmed)' );
		}
		$h .= '<tr><th scope="row">' . esc_html( $ev['name'] ) . '</th><td>' . esc_html( $d->format( 'D, M j, Y' ) ) . '</td><td>' . esc_html( $status ) . '</td></tr>';
	}
	return $h . '</tbody></table></div>';
} );

/** [trh_event id="veterans-day"] — status box with confirmed / not-yet-confirmed wording. */
add_shortcode( 'trh_event', function ( $atts ) {
	$a  = shortcode_atts( array( 'id' => '' ), $atts );
	$ev = trhpe_events();
	$id = sanitize_title( $a['id'] );
	if ( ! isset( $ev[ $id ] ) ) {
		return '';
	}
	$e = $ev[ $id ];
	$d = trhpe_next_date( $e['rule'] );
	$h = '<div class="trh-event"><p><strong>' . esc_html( $e['name'] ) . ' ' . esc_html( $d->format( 'Y' ) ) . ':</strong> ' . esc_html( $d->format( 'l, F j' ) ) . ' — ' . esc_html( $e['status'] ) . '.</p>';
	if ( $e['offer'] ) {
		$h .= '<p><strong>Offer:</strong> ' . esc_html( $e['offer'] ) . ( 'yes' === $e['confirmed'] ? ' <span class="trh-ok">✔ confirmed</span>' : ' <span class="trh-wait">⏳ not yet confirmed for ' . esc_html( $d->format( 'Y' ) ) . '</span>' ) . '</p>';
	}
	if ( $e['details'] ) {
		$h .= '<p>' . wp_kses_post( $e['details'] ) . '</p>';
	}
	if ( $e['url'] ) {
		$h .= '<p><a href="' . esc_url( $e['url'] ) . '" rel="nofollow noopener" target="_blank">Official source</a></p>';
	}
	return $h . '</div>';
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
	return trailingslashit( $s['feed_base_url'] ) . ltrim( $file, '/' ) . '?t=' . floor( time() / 600 );
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
	return $out;
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
		$existing = get_posts( array( 'name' => $slug, 'post_type' => $type, 'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private' ), 'numberposts' => 1 ) );
		$post_id  = $existing ? $existing[0]->ID : 0;
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
	if ( ! wp_next_scheduled( 'trhpe_cron_sync' ) ) {
		wp_schedule_event( time() + 300, 'twicedaily', 'trhpe_cron_sync' );
	}
	if ( ! get_option( TRHPE_OPT_EVENTS ) ) {
		update_option( TRHPE_OPT_EVENTS, trhpe_default_events(), false );
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
 * 10. Minimal front-end styles (mobile-friendly tables).
 * ---------------------------------------------------------------------- */
add_action( 'wp_head', function () {
	echo '<style id="trhpe-css">.trh-table-wrap{overflow-x:auto;margin:1em 0}.trh-table{width:100%;border-collapse:collapse;font-size:.95em}.trh-table th,.trh-table td{padding:.55em .7em;border-bottom:1px solid #e3e3e3;text-align:left;vertical-align:top}.trh-table thead th{background:#f6f3ee}.trh-table tbody th{font-weight:600}.trh-table caption{text-align:left;font-weight:600;padding:.4em 0}.trh-event{border-left:4px solid #b5121b;background:#fbf7f2;padding:.8em 1em;margin:1em 0}.trh-ok{color:#16793a;font-weight:600}.trh-wait{color:#9a6400;font-weight:600}</style>';
} );
