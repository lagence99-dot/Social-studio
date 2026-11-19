<?php
/**
 * CRM settings, statuses and the quote template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FRCRM_Settings {

	const OPT = 'frcrm_settings';

	/** Client pipeline: key => [label, colour group]. */
	public static function statuses() {
		return array(
			'prospect'    => array( 'Prospect', 'grey' ),
			'contacte'    => array( 'Contacté', 'blue' ),
			'rdv'         => array( 'RDV planifié', 'blue' ),
			'qualifie'    => array( 'Besoin qualifié', 'violet' ),
			'devis'       => array( 'Devis envoyé', 'amber' ),
			'negociation' => array( 'Négociation', 'amber' ),
			'signe'       => array( 'Signé', 'green' ),
			'production'  => array( 'En production', 'green' ),
			'livre'       => array( 'Livré', 'teal' ),
			'perdu'       => array( 'Perdu', 'red' ),
			'pause'       => array( 'En pause', 'grey' ),
		);
	}

	public static function quote_statuses() {
		return array(
			'brouillon' => 'Brouillon',
			'envoye'    => 'Envoyé',
			'accepte'   => 'Accepté',
			'refuse'    => 'Refusé',
			'expire'    => 'Expiré',
		);
	}

	public static function sectors() {
		return array( 'Luxe', 'Hôtellerie', 'Textile & mode', 'Commerces & magasins', 'Beauté & esthétique', 'Restauration', 'Immobilier', 'Santé', 'Artisans du bâtiment', 'Avocats & notaires', 'Architectes & déco', 'Sport & bien-être', 'Événementiel & mariage', 'Tourisme & loisirs', 'Startups & tech', 'Industrie', 'Banque & assurance', 'Associations', 'Autre' );
	}

	public static function sources() {
		return array( 'Recommandation', 'Réseau personnel', 'LinkedIn', 'Instagram', 'Site internet', 'Prospection e-mail', 'Prospection téléphone', 'Salon / événement', 'Apporteur d\'affaires', 'Client existant', 'Autre' );
	}

	/** Sections of the delivery specification ("cahier des charges de livraison"). */
	public static function default_specs() {
		return array(
			array( 'key' => 'contexte', 'title' => 'Contexte et objectifs', 'text' => "Rappel du contexte du client et de sa problématique.\nObjectifs attendus de la mission et indicateurs de réussite." ),
			array( 'key' => 'perimetre', 'title' => 'Périmètre de la mission', 'text' => 'Ce qui est inclus dans la mission : supports, canaux, pages, nombre de déclinaisons, langues.' ),
			array( 'key' => 'livrables', 'title' => 'Livrables', 'text' => '' ),
			array( 'key' => 'methodo', 'title' => 'Méthodologie et étapes', 'text' => "1. Cadrage et collecte des éléments\n2. Conception\n3. Production\n4. Recette et ajustements\n5. Livraison et passation" ),
			array( 'key' => 'planning', 'title' => 'Planning et jalons', 'text' => "Démarrage : à la signature du devis et réception de l'acompte.\nDurée estimée : à préciser.\nJalons de validation : fin de cadrage, fin de conception, recette." ),
			array( 'key' => 'recette', 'title' => 'Modalités de livraison et de recette', 'text' => "Les livrables sont remis par voie numérique. Le client dispose de 10 jours ouvrés pour formuler ses remarques. Deux allers-retours de corrections sont inclus par livrable. Sans retour dans ce délai, le livrable est réputé validé." ),
			array( 'key' => 'hypotheses', 'title' => 'Hypothèses et exclusions', 'text' => "Non inclus sauf mention contraire : achat de licences, hébergement, photographies, traductions, contenus rédactionnels supplémentaires, frais de déplacement hors Côte d'Azur." ),
			array( 'key' => 'client', 'title' => 'Engagements du client', 'text' => "Désigner un interlocuteur unique, fournir les contenus et accès nécessaires dans les délais convenus et participer aux réunions de validation." ),
		);
	}

	public static function defaults() {
		return array(
			'entities'       => array(
				array(
					'key'        => 'framboise',
					'name'       => 'La Framboise Digitale',
					'address'    => '',
					'siret'      => '',
					'vat_number' => '',
					'vat_exempt' => 0,
					'email'      => '',
					'phone'      => '',
					'website'    => 'https://laframboisedigitale.fr',
					'logo'       => '',
					'color'      => '#b0124f',
					'prefix'     => 'LFD',
					'bank'       => '',
				),
				array(
					'key'        => 'agence99',
					'name'       => "L'Agence 99",
					'address'    => "99 boulevard de la République\n06400 Cannes",
					'siret'      => '',
					'vat_number' => '',
					'vat_exempt' => 0,
					'email'      => '',
					'phone'      => '',
					'website'    => 'https://lagence99.fr',
					'logo'       => '',
					'color'      => '#1d2b5b',
					'prefix'     => 'A99',
					'bank'       => '',
				),
			),
			'default_entity' => 'framboise',
			'validity_days'  => 30,
			'deposit'        => 30,
			'payment_terms'  => "Acompte de 30 % à la signature, solde à la livraison.\nPaiement par virement à 30 jours date de facture.\nEn cas de retard de paiement : pénalités au taux de trois fois le taux d'intérêt légal et indemnité forfaitaire de 40 € pour frais de recouvrement.",
			'legal'          => "Le présent devis est valable pendant la durée indiquée. Toute commande implique l'acceptation des conditions générales de vente. Les droits de propriété intellectuelle sur les livrables sont cédés au client après paiement intégral.",
			'specs'          => self::default_specs(),
			'notify_to'      => get_option( 'admin_email' ),
			'notify_status'  => 1,
			'notify_quote'   => 1,
			'notify_daily'   => 1,
			'notify_weekly'  => 1,
			'stale_days'     => 21,
			'expire_warn'    => 3,
		);
	}

	public static function get() {
		$s = get_option( self::OPT, array() );
		$s = wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() );
		if ( empty( $s['entities'] ) ) {
			$s['entities'] = self::defaults()['entities'];
		}
		if ( empty( $s['specs'] ) ) {
			$s['specs'] = self::default_specs();
		}
		return $s;
	}

	public static function entity( $key ) {
		foreach ( self::get()['entities'] as $e ) {
			if ( $e['key'] === $key ) {
				return $e;
			}
		}
		return self::get()['entities'][0];
	}

	public static function save( $in ) {
		$d   = self::defaults();
		$out = self::get();

		if ( isset( $in['entities'] ) && is_array( $in['entities'] ) ) {
			$ents = array();
			foreach ( $in['entities'] as $i => $e ) {
				$name = sanitize_text_field( $e['name'] ?? '' );
				if ( '' === $name ) {
					continue;
				}
				$key    = sanitize_key( $e['key'] ?? '' );
				$ents[] = array(
					'key'        => $key ? $key : 'e' . $i,
					'name'       => $name,
					'address'    => sanitize_textarea_field( $e['address'] ?? '' ),
					'siret'      => sanitize_text_field( $e['siret'] ?? '' ),
					'vat_number' => sanitize_text_field( $e['vat_number'] ?? '' ),
					'vat_exempt' => empty( $e['vat_exempt'] ) ? 0 : 1,
					'email'      => sanitize_email( $e['email'] ?? '' ),
					'phone'      => sanitize_text_field( $e['phone'] ?? '' ),
					'website'    => esc_url_raw( $e['website'] ?? '' ),
					'logo'       => esc_url_raw( $e['logo'] ?? '' ),
					'color'      => sanitize_hex_color( $e['color'] ?? '' ) ? sanitize_hex_color( $e['color'] ) : '#b0124f',
					'prefix'     => strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $e['prefix'] ?? '' ) ),
					'bank'       => sanitize_textarea_field( $e['bank'] ?? '' ),
				);
			}
			if ( $ents ) {
				$out['entities'] = $ents;
			}
		}
		if ( isset( $in['specs'] ) && is_array( $in['specs'] ) ) {
			$specs = array();
			foreach ( $in['specs'] as $i => $sp ) {
				$title = sanitize_text_field( $sp['title'] ?? '' );
				if ( '' === $title ) {
					continue;
				}
				$specs[] = array(
					'key'   => sanitize_key( $sp['key'] ?? '' ) ? sanitize_key( $sp['key'] ) : 's' . $i,
					'title' => $title,
					'text'  => sanitize_textarea_field( $sp['text'] ?? '' ),
				);
			}
			$out['specs'] = $specs ? $specs : $d['specs'];
		}
		foreach ( array( 'payment_terms', 'legal' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_textarea_field( $in[ $k ] );
			}
		}
		foreach ( array( 'validity_days', 'deposit', 'stale_days', 'expire_warn' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = max( 0, (int) $in[ $k ] );
			}
		}
		foreach ( array( 'notify_status', 'notify_quote', 'notify_daily', 'notify_weekly' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
			}
		}
		if ( isset( $in['notify_to'] ) ) {
			$mails            = array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) $in['notify_to'] ) ) );
			$out['notify_to'] = implode( ', ', $mails );
		}
		if ( isset( $in['default_entity'] ) ) {
			$out['default_entity'] = sanitize_key( $in['default_entity'] );
		}
		update_option( self::OPT, $out, false );
		return $out;
	}
}
