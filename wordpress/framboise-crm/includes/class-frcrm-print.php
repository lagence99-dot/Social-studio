<?php
/**
 * Printable quote (the browser saves it as PDF).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FRCRM_Print {

	private static function money( $v ) {
		return number_format( (float) $v, 2, ',', "\u{202F}" ) . "\u{00A0}€";
	}

	private static function nl( $t ) {
		return nl2br( esc_html( (string) $t ) );
	}

	/** Plain text with "- " lines turned into a list. */
	private static function rich( $t ) {
		$lines = preg_split( '/\R/', trim( (string) $t ) );
		$html  = '';
		$list  = array();
		$para  = array();
		$flush = function () use ( &$html, &$list, &$para ) {
			if ( $para ) {
				$html .= '<p>' . implode( '<br>', array_map( 'esc_html', $para ) ) . '</p>';
				$para  = array();
			}
			if ( $list ) {
				$html .= '<ul>' . implode( '', array_map( function ( $l ) { return '<li>' . esc_html( $l ) . '</li>'; }, $list ) ) . '</ul>';
				$list  = array();
			}
		};
		foreach ( $lines as $l ) {
			$l = rtrim( $l );
			if ( '' === trim( $l ) ) {
				$flush();
				continue;
			}
			if ( preg_match( '/^\s*[-•*]\s+(.+)$/u', $l, $m ) ) {
				if ( $para ) {
					$html .= '<p>' . implode( '<br>', array_map( 'esc_html', $para ) ) . '</p>';
					$para  = array();
				}
				$list[] = $m[1];
				continue;
			}
			if ( $list ) {
				$flush();
			}
			$para[] = $l;
		}
		$flush();
		return $html;
	}

	public static function render() {
		if ( ! frcrm_can() || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'frcrm_print' ) ) {
			wp_die( 'Lien expiré : rouvrez le devis depuis le CRM.' );
		}
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$r  = new WP_REST_Request();
		$r->set_param( 'id', $id );
		$q = FRCRM_REST::quote_get( $r );
		if ( is_wp_error( $q ) ) {
			wp_die( 'Devis introuvable.' );
		}
		$e       = FRCRM_Settings::entity( $q['entity'] );
		$s       = FRCRM_Settings::get();
		$c       = $q['client'];
		$contact = null;
		foreach ( $c['contacts'] as $k ) {
			if ( (int) $k['id'] === (int) $q['contact_id'] ) {
				$contact = $k;
			}
		}
		if ( ! $contact && $c['contacts'] ) {
			$contact = $c['contacts'][0];
		}
		$t       = $q['totals'];
		$color   = $e['color'] ? $e['color'] : '#b0124f';
		$exempt  = ! empty( $e['vat_exempt'] );
		$deposit = (float) $q['deposit'];
		$specs   = array_values( array_filter( (array) $q['specs'], function ( $sp ) { return ! empty( $sp['on'] ) && '' !== trim( $sp['text'] ); } ) );
		$date    = function ( $d ) { return $d ? date_i18n( 'j F Y', strtotime( $d ) ) : ''; };
		$title   = $q['number'] . ' · ' . $c['company'];

		header( 'Content-Type: text/html; charset=utf-8' );
		?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( $title ); ?></title>
<style>
@page{size:A4;margin:16mm 15mm 18mm}
*{box-sizing:border-box}
body{font-family:-apple-system,"Segoe UI",Helvetica,Arial,sans-serif;color:#1c1c22;font-size:10.5pt;line-height:1.5;margin:0;background:#f2f2f4}
.sheet{max-width:210mm;margin:24px auto;background:#fff;padding:18mm 16mm;box-shadow:0 2px 16px rgba(0,0,0,.08)}
.bar{position:sticky;top:0;background:#1c1c22;color:#fff;padding:10px 16px;display:flex;gap:12px;align-items:center;justify-content:center;font-size:14px;z-index:2}
.bar button{background:<?php echo esc_attr( $color ); ?>;color:#fff;border:0;border-radius:6px;padding:8px 16px;font-size:14px;cursor:pointer}
header{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;border-bottom:3px solid <?php echo esc_attr( $color ); ?>;padding-bottom:14px;margin-bottom:18px}
.brand img{max-height:56px;max-width:220px;display:block;margin-bottom:6px}
.brand b{font-size:15pt;color:<?php echo esc_attr( $color ); ?>}
.brand p,.to p{margin:2px 0;font-size:9.5pt;color:#55555f}
.doc{text-align:right}
.doc h1{margin:0;font-size:20pt;letter-spacing:.04em;color:<?php echo esc_attr( $color ); ?>}
.doc p{margin:2px 0;font-size:9.5pt}
.parties{display:flex;justify-content:flex-end;margin-bottom:18px}
.to{border:1px solid #e3e3e8;border-radius:6px;padding:10px 14px;min-width:46%}
.to b{font-size:11pt}
h2{font-size:12pt;color:<?php echo esc_attr( $color ); ?>;margin:22px 0 8px;break-after:avoid}
h2 small{color:#888;font-weight:400}
.obj{font-size:12.5pt;font-weight:600;margin:0 0 10px}
p{margin:0 0 8px}
ul{margin:0 0 8px;padding-left:18px}
table{width:100%;border-collapse:collapse;margin:6px 0 4px;font-size:9.5pt}
th{background:<?php echo esc_attr( $color ); ?>;color:#fff;text-align:left;padding:7px 8px;font-weight:600}
th.n,td.n{text-align:right;white-space:nowrap}
td{padding:7px 8px;border-bottom:1px solid #ececf0;vertical-align:top}
tr{break-inside:avoid}
td small{display:block;color:#666;white-space:pre-line}
tr.sec td{background:#f6f6f8;font-weight:700}
.tot{margin-left:auto;width:52%;margin-top:8px;font-size:10pt}
.tot td{border:0;padding:4px 8px}
.tot tr.big td{font-size:12pt;font-weight:700;border-top:2px solid <?php echo esc_attr( $color ); ?>;padding-top:8px}
.spec{break-inside:avoid-page}
.sign{display:flex;gap:18px;margin-top:22px;break-inside:avoid}
.sign div{flex:1;border:1px solid #d8d8de;border-radius:6px;padding:10px 12px;min-height:110px;font-size:9.5pt}
.legal{font-size:8.5pt;color:#666;margin-top:18px;border-top:1px solid #e3e3e8;padding-top:10px}
@media print{body{background:#fff}.bar{display:none}.sheet{box-shadow:none;margin:0;padding:0;max-width:none}}
</style></head><body>
<div class="bar">Devis <?php echo esc_html( $q['number'] ); ?> <button onclick="window.print()">Imprimer ou enregistrer en PDF</button></div>
<div class="sheet">
<header>
	<div class="brand">
		<?php if ( $e['logo'] ) : ?><img src="<?php echo esc_url( $e['logo'] ); ?>" alt="<?php echo esc_attr( $e['name'] ); ?>"><?php else : ?><b><?php echo esc_html( $e['name'] ); ?></b><?php endif; ?>
		<p><?php echo self::nl( $e['address'] ); // phpcs:ignore ?></p>
		<p><?php echo esc_html( implode( ' · ', array_filter( array( $e['phone'], $e['email'], preg_replace( '#^https?://#', '', (string) $e['website'] ) ) ) ) ); ?></p>
		<?php if ( $e['siret'] ) : ?><p>SIRET <?php echo esc_html( $e['siret'] ); ?><?php echo $e['vat_number'] ? ' · TVA ' . esc_html( $e['vat_number'] ) : ''; ?></p><?php endif; ?>
	</div>
	<div class="doc">
		<h1>DEVIS</h1>
		<p><strong>N° <?php echo esc_html( $q['number'] ); ?></strong></p>
		<p>Date : <?php echo esc_html( $date( $q['issue_date'] ) ); ?></p>
		<p>Valable jusqu'au <?php echo esc_html( $date( $q['valid_until'] ) ); ?></p>
	</div>
</header>

<div class="parties"><div class="to">
	<b><?php echo esc_html( $c['company'] ); ?></b>
	<?php if ( $contact ) : ?><p>À l'attention de <?php echo esc_html( trim( $contact['first_name'] . ' ' . $contact['last_name'] ) ); ?><?php echo $contact['role'] ? ', ' . esc_html( $contact['role'] ) : ''; ?></p><?php endif; ?>
	<p><?php echo self::nl( $c['address'] ); // phpcs:ignore ?></p>
	<?php if ( $contact && ( $contact['email'] || $contact['phone'] ) ) : ?><p><?php echo esc_html( implode( ' · ', array_filter( array( $contact['email'], $contact['phone'] ) ) ) ); ?></p><?php endif; ?>
</div></div>

<p class="obj">Objet : <?php echo esc_html( $q['title'] ); ?></p>
<?php if ( $q['intro'] ) : ?><?php echo self::rich( $q['intro'] ); // phpcs:ignore ?><?php endif; ?>

<?php foreach ( $specs as $i => $sp ) : ?>
	<div class="spec"><h2><?php echo esc_html( ( $i + 1 ) . '. ' . $sp['title'] ); ?></h2><?php echo self::rich( $sp['text'] ); // phpcs:ignore ?></div>
<?php endforeach; ?>

<h2><?php echo esc_html( ( count( $specs ) + 1 ) . '. Proposition financière' ); ?></h2>
<table>
	<thead><tr><th>Désignation</th><th class="n">Qté</th><th class="n">Prix unit. HT</th><?php if ( ! $exempt ) : ?><th class="n">TVA</th><?php endif; ?><th class="n">Total HT</th></tr></thead>
	<tbody>
	<?php foreach ( $q['items'] as $it ) : ?>
		<?php if ( 'title' === $it['type'] ) : ?>
			<tr class="sec"><td colspan="<?php echo $exempt ? 4 : 5; ?>"><?php echo esc_html( $it['name'] ); ?></td></tr>
		<?php else : ?>
			<?php $line = (float) $it['qty'] * (float) $it['price'] * ( 1 - (float) $it['discount'] / 100 ); ?>
			<tr>
				<td><strong><?php echo esc_html( $it['name'] ); ?></strong><?php if ( $it['description'] ) : ?><small><?php echo esc_html( $it['description'] ); ?></small><?php endif; ?><?php if ( (float) $it['discount'] > 0 ) : ?><small>Remise <?php echo esc_html( rtrim( rtrim( number_format( (float) $it['discount'], 2, ',', '' ), '0' ), ',' ) ); ?> %</small><?php endif; ?></td>
				<td class="n"><?php echo esc_html( rtrim( rtrim( number_format( (float) $it['qty'], 2, ',', '' ), '0' ), ',' ) . ( $it['unit'] ? ' ' . $it['unit'] : '' ) ); ?></td>
				<td class="n"><?php echo esc_html( self::money( $it['price'] ) ); ?></td>
				<?php if ( ! $exempt ) : ?><td class="n"><?php echo esc_html( rtrim( rtrim( number_format( (float) $it['vat'], 2, ',', '' ), '0' ), ',' ) ); ?> %</td><?php endif; ?>
				<td class="n"><?php echo esc_html( self::money( $line ) ); ?></td>
			</tr>
		<?php endif; ?>
	<?php endforeach; ?>
	</tbody>
</table>
<table class="tot">
	<?php if ( $t['discount'] > 0 ) : ?>
		<tr><td>Sous-total HT</td><td class="n"><?php echo esc_html( self::money( $t['subtotal'] ) ); ?></td></tr>
		<tr><td>Remise <?php echo esc_html( rtrim( rtrim( number_format( (float) $q['discount'], 2, ',', '' ), '0' ), ',' ) ); ?> %</td><td class="n">−<?php echo esc_html( self::money( $t['discount'] ) ); ?></td></tr>
	<?php endif; ?>
	<tr><td>Total HT</td><td class="n"><?php echo esc_html( self::money( $t['ht'] ) ); ?></td></tr>
	<?php if ( $exempt ) : ?>
		<tr><td colspan="2"><small>TVA non applicable, article 293 B du CGI</small></td></tr>
	<?php else : ?>
		<?php foreach ( $t['vat'] as $rate => $amount ) : ?>
			<tr><td>TVA <?php echo esc_html( rtrim( rtrim( number_format( (float) $rate, 2, ',', '' ), '0' ), ',' ) ); ?> %</td><td class="n"><?php echo esc_html( self::money( $amount ) ); ?></td></tr>
		<?php endforeach; ?>
	<?php endif; ?>
	<tr class="big"><td><?php echo $exempt ? 'Total à payer' : 'Total TTC'; ?></td><td class="n"><?php echo esc_html( self::money( $t['ttc'] ) ); ?></td></tr>
	<?php if ( $deposit > 0 ) : ?>
		<tr><td>Acompte à la signature (<?php echo esc_html( rtrim( rtrim( number_format( $deposit, 2, ',', '' ), '0' ), ',' ) ); ?> %)</td><td class="n"><?php echo esc_html( self::money( $t['ttc'] * $deposit / 100 ) ); ?></td></tr>
	<?php endif; ?>
</table>

<?php if ( $q['payment_terms'] ) : ?><h2>Conditions de règlement</h2><?php echo self::rich( $q['payment_terms'] ); // phpcs:ignore ?><?php endif; ?>
<?php if ( $e['bank'] ) : ?><p><small><?php echo self::nl( $e['bank'] ); // phpcs:ignore ?></small></p><?php endif; ?>
<?php if ( $q['notes'] ) : ?><h2>Remarques</h2><?php echo self::rich( $q['notes'] ); // phpcs:ignore ?><?php endif; ?>

<div class="sign">
	<div><strong>Pour <?php echo esc_html( $e['name'] ); ?></strong></div>
	<div><strong>Bon pour accord</strong><br>Date, nom, signature et cachet du client,<br>précédés de la mention « Bon pour accord »</div>
</div>
<?php if ( $s['legal'] ) : ?><div class="legal"><?php echo self::nl( $s['legal'] ); // phpcs:ignore ?></div><?php endif; ?>
</div>
</body></html>
		<?php
		exit;
	}
}
