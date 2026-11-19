<?php
/**
 * E-mail alerts and the daily check.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FRCRM_Notify {

	public static function schedule() {
		if ( ! wp_next_scheduled( 'frcrm_daily' ) ) {
			$tz    = wp_timezone();
			$first = new DateTime( 'tomorrow 08:00', $tz );
			wp_schedule_event( $first->getTimestamp(), 'daily', 'frcrm_daily' );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( 'frcrm_daily' );
	}

	private static function to() {
		return FRCRM_Settings::get()['notify_to'];
	}

	private static function link( $hash ) {
		return admin_url( 'admin.php?page=frcrm#' . $hash );
	}

	/** Sends a simple, readable HTML e-mail. */
	public static function send( $subject, $title, $blocks ) {
		$to = self::to();
		if ( ! $to ) {
			return false;
		}
		$html  = '<div style="font-family:-apple-system,Segoe UI,Arial,sans-serif;font-size:15px;line-height:1.5;color:#1d2327;max-width:620px">';
		$html .= '<p style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#b0124f;margin:0 0 6px">Framboise CRM</p>';
		$html .= '<h1 style="font-size:20px;margin:0 0 16px">' . esc_html( $title ) . '</h1>';
		$html .= implode( '', $blocks );
		$html .= '<p style="margin-top:24px"><a href="' . esc_url( self::link( '/dashboard' ) ) . '" style="background:#b0124f;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none">Ouvrir le CRM</a></p></div>';
		return wp_mail( $to, '[CRM] ' . $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	private static function para( $t ) {
		return '<p style="margin:0 0 10px">' . $t . '</p>';
	}

	private static function list_block( $title, $items ) {
		if ( ! $items ) {
			return '';
		}
		$h = '<h2 style="font-size:15px;margin:18px 0 6px">' . esc_html( $title ) . '</h2><ul style="margin:0;padding-left:18px">';
		foreach ( $items as $i ) {
			$h .= '<li style="margin:3px 0">' . $i . '</li>';
		}
		return $h . '</ul>';
	}

	public static function status_changed( $client, $from, $to ) {
		if ( ! FRCRM_Settings::get()['notify_status'] ) {
			return;
		}
		$name = esc_html( $client['company'] );
		self::send(
			$client['company'] . ' : ' . $to,
			'Changement d\'état client',
			array(
				self::para( '<strong>' . $name . '</strong> passe de « ' . esc_html( $from ) . ' » à « <strong>' . esc_html( $to ) . '</strong> ».' ),
				self::para( '<a href="' . esc_url( self::link( '/client/' . (int) $client['id'] ) ) . '">Voir la fiche client</a>' ),
			)
		);
	}

	public static function quote_decided( $quote, $client, $label ) {
		if ( ! FRCRM_Settings::get()['notify_quote'] ) {
			return;
		}
		self::send(
			'Devis ' . $quote['number'] . ' ' . strtolower( $label ),
			'Devis ' . strtolower( $label ),
			array(
				self::para( 'Le devis <strong>' . esc_html( $quote['number'] ) . '</strong> (' . esc_html( $quote['title'] ) . ') pour <strong>' . esc_html( $client['company'] ) . '</strong> est ' . esc_html( strtolower( $label ) ) . '.' ),
				self::para( 'Montant : ' . esc_html( number_format( (float) $quote['total_ht'], 2, ',', ' ' ) ) . ' € HT.' ),
				self::para( '<a href="' . esc_url( self::link( '/quote/' . (int) $quote['id'] ) ) . '">Ouvrir le devis</a>' ),
			)
		);
	}

	/**
	 * Items needing attention within $days: overdue or upcoming report actions, expiring quotes,
	 * planned client follow-ups and clients without news for a while.
	 */
	public static function todo( $days = 0 ) {
		global $wpdb;
		$s     = FRCRM_Settings::get();
		$today = wp_date( 'Y-m-d' );
		$limit = wp_date( 'Y-m-d', strtotime( $today . ' +' . max( 0, (int) $days ) . ' days' ) );
		$C     = FRCRM_DB::t( 'clients' );
		$out   = array(
			'actions'  => array(),
			'quotes'   => array(),
			'followup' => array(),
			'stale'    => array(),
		);

		$reports = $wpdb->get_results( "SELECT r.id, r.client_id, r.title, r.meeting_date, r.actions, c.company FROM " . FRCRM_DB::t( 'reports' ) . " r JOIN $C c ON c.id = r.client_id", ARRAY_A ); // phpcs:ignore
		foreach ( $reports as $r ) {
			foreach ( (array) json_decode( (string) $r['actions'], true ) as $i => $a ) {
				if ( empty( $a['done'] ) && ! empty( $a['due'] ) && $a['due'] <= $limit ) {
					$out['actions'][] = array(
						'report_id' => (int) $r['id'],
						'index'     => $i,
						'client_id' => (int) $r['client_id'],
						'company'   => $r['company'],
						'text'      => $a['text'],
						'who'       => $a['who'] ?? '',
						'due'       => $a['due'],
						'late'      => $a['due'] < $today,
					);
				}
			}
		}
		usort( $out['actions'], function ( $a, $b ) { return strcmp( $a['due'], $b['due'] ); } );

		$warn          = wp_date( 'Y-m-d', strtotime( $today . ' +' . max( (int) $s['expire_warn'], (int) $days ) . ' days' ) );
		$out['quotes'] = $wpdb->get_results( $wpdb->prepare( 'SELECT q.id, q.client_id, q.number, q.title, q.valid_until, q.total_ht, c.company FROM ' . FRCRM_DB::t( 'quotes' ) . " q JOIN $C c ON c.id = q.client_id WHERE q.status = 'envoye' AND q.valid_until <= %s ORDER BY q.valid_until ASC", $warn ), ARRAY_A ); // phpcs:ignore

		$out['followup'] = $wpdb->get_results( $wpdb->prepare( "SELECT id, company, status, next_action, next_date FROM $C WHERE next_date IS NOT NULL AND next_date <= %s AND status NOT IN ('perdu','livre') ORDER BY next_date ASC", $limit ), ARRAY_A ); // phpcs:ignore

		$stale        = wp_date( 'Y-m-d H:i:s', strtotime( $today . ' -' . max( 1, (int) $s['stale_days'] ) . ' days' ) );
		$out['stale'] = $wpdb->get_results( $wpdb->prepare( "SELECT id, company, status, updated_at FROM $C WHERE updated_at < %s AND status IN ('contacte','rdv','qualifie','devis','negociation') ORDER BY updated_at ASC LIMIT 20", $stale ), ARRAY_A ); // phpcs:ignore
		return $out;
	}

	public static function daily() {
		$s = FRCRM_Settings::get();
		global $wpdb;

		// Quotes past their validity become "expiré".
		$expired = $wpdb->get_results( $wpdb->prepare( 'SELECT id, client_id, number FROM ' . FRCRM_DB::t( 'quotes' ) . " WHERE status = 'envoye' AND valid_until < %s", wp_date( 'Y-m-d' ) ), ARRAY_A ); // phpcs:ignore
		foreach ( $expired as $q ) {
			$wpdb->update( FRCRM_DB::t( 'quotes' ), array( 'status' => 'expire' ), array( 'id' => (int) $q['id'] ) );
			FRCRM_DB::log( $q['client_id'], 'quote', 'Devis ' . $q['number'] . ' expiré sans réponse' );
		}

		$weekly = $s['notify_weekly'] && '1' === wp_date( 'N' );
		if ( ! $s['notify_daily'] && ! $weekly ) {
			return;
		}
		$t      = self::todo( $weekly ? 7 : 0 );
		$blocks = array();
		$blocks[] = self::list_block(
			'Actions à faire',
			array_map(
				function ( $a ) {
					return '<a href="' . esc_url( self::link( '/client/' . $a['client_id'] ) ) . '">' . esc_html( $a['company'] ) . '</a> : ' . esc_html( $a['text'] ) . ( $a['who'] ? ' (' . esc_html( $a['who'] ) . ')' : '' ) . ' · ' . ( $a['late'] ? '<strong style="color:#b32d2e">en retard, ' : 'pour le ' ) . esc_html( date_i18n( 'j M', strtotime( $a['due'] ) ) ) . ( $a['late'] ? '</strong>' : '' );
				},
				$t['actions']
			)
		);
		$blocks[] = self::list_block(
			'Devis à relancer avant expiration',
			array_map(
				function ( $q ) {
					return '<a href="' . esc_url( self::link( '/quote/' . $q['id'] ) ) . '">' . esc_html( $q['number'] ) . '</a> · ' . esc_html( $q['company'] ) . ' · expire le ' . esc_html( date_i18n( 'j M', strtotime( $q['valid_until'] ) ) );
				},
				$t['quotes']
			)
		);
		$blocks[] = self::list_block(
			'Relances prévues',
			array_map(
				function ( $c ) {
					return '<a href="' . esc_url( self::link( '/client/' . $c['id'] ) ) . '">' . esc_html( $c['company'] ) . '</a> : ' . esc_html( $c['next_action'] ? $c['next_action'] : 'relance' ) . ' · ' . esc_html( date_i18n( 'j M', strtotime( $c['next_date'] ) ) );
				},
				$t['followup']
			)
		);
		if ( $weekly ) {
			$blocks[] = self::list_block(
				'Sans nouvelles depuis plus de ' . (int) $s['stale_days'] . ' jours',
				array_map(
					function ( $c ) {
						return '<a href="' . esc_url( self::link( '/client/' . $c['id'] ) ) . '">' . esc_html( $c['company'] ) . '</a>';
					},
					$t['stale']
				)
			);
		}
		$blocks = array_filter( $blocks );
		if ( ! $blocks ) {
			return;
		}
		self::send(
			$weekly ? 'Le point de la semaine' : 'À faire aujourd\'hui',
			$weekly ? 'Le point de la semaine' : 'À faire aujourd\'hui',
			$blocks
		);
	}
}
