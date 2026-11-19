<?php
/**
 * Database tables and data access.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FRCRM_DB {

	const SCHEMA = 1;

	public static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'frcrm_' . $name;
	}

	public static function activate() {
		self::install();
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( FRCRM_CAP );
		}
		FRCRM_Notify::schedule();
		if ( ! get_option( 'frcrm_seeded' ) ) {
			self::seed();
			update_option( 'frcrm_seeded', 1, false );
		}
	}

	public static function maybe_upgrade() {
		if ( (int) get_option( 'frcrm_schema' ) !== self::SCHEMA ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::t( 'clients' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			company varchar(190) NOT NULL DEFAULT '',
			sector varchar(100) NOT NULL DEFAULT '',
			source varchar(100) NOT NULL DEFAULT '',
			status varchar(40) NOT NULL DEFAULT 'prospect',
			entity varchar(40) NOT NULL DEFAULT '',
			problem longtext NULL,
			website varchar(255) NOT NULL DEFAULT '',
			address text NULL,
			notes longtext NULL,
			budget varchar(100) NOT NULL DEFAULT '',
			next_action varchar(255) NOT NULL DEFAULT '',
			next_date date NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::t( 'contacts' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			client_id bigint(20) unsigned NOT NULL,
			first_name varchar(100) NOT NULL DEFAULT '',
			last_name varchar(100) NOT NULL DEFAULT '',
			role varchar(150) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(60) NOT NULL DEFAULT '',
			is_main tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY client_id (client_id)
		) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::t( 'services' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			category varchar(100) NOT NULL DEFAULT '',
			description text NULL,
			deliverables text NULL,
			unit varchar(40) NOT NULL DEFAULT 'forfait',
			price decimal(12,2) NOT NULL DEFAULT 0,
			vat decimal(5,2) NOT NULL DEFAULT 20,
			active tinyint(1) NOT NULL DEFAULT 1,
			sort int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id)
		) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::t( 'quotes' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			client_id bigint(20) unsigned NOT NULL,
			contact_id bigint(20) unsigned NOT NULL DEFAULT 0,
			entity varchar(40) NOT NULL DEFAULT '',
			number varchar(60) NOT NULL DEFAULT '',
			title varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'brouillon',
			issue_date date NULL,
			valid_until date NULL,
			intro longtext NULL,
			specs longtext NULL,
			items longtext NULL,
			discount decimal(5,2) NOT NULL DEFAULT 0,
			deposit decimal(5,2) NOT NULL DEFAULT 0,
			payment_terms text NULL,
			notes longtext NULL,
			total_ht decimal(12,2) NOT NULL DEFAULT 0,
			total_ttc decimal(12,2) NOT NULL DEFAULT 0,
			sent_at datetime NULL,
			decided_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY client_id (client_id),
			KEY status (status)
		) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::t( 'reports' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			client_id bigint(20) unsigned NOT NULL,
			meeting_date date NULL,
			type varchar(40) NOT NULL DEFAULT 'rdv',
			title varchar(255) NOT NULL DEFAULT '',
			participants text NULL,
			notes longtext NULL,
			decisions longtext NULL,
			actions longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY client_id (client_id)
		) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::t( 'activity' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			client_id bigint(20) unsigned NOT NULL,
			type varchar(40) NOT NULL DEFAULT 'note',
			message text NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY client_id (client_id)
		) $c;"
		);
		update_option( 'frcrm_schema', self::SCHEMA, false );
	}

	/** Example services so the catalogue is not empty; prices are left at 0 to be set by the agency. */
	private static function seed() {
		global $wpdb;
		$rows = array(
			array( 'Audit UX et expérience client', 'Conseil', 'Analyse du parcours client en ligne et en point de vente, entretiens, benchmark et recommandations priorisées.', "Rapport d'audit\nCartographie du parcours client\nPlan d'actions priorisé", 'forfait' ),
			array( 'Atelier de cadrage', 'Conseil', 'Atelier collaboratif pour définir les objectifs, les cibles et le périmètre du projet.', "Compte-rendu d'atelier\nNote de cadrage", 'jour' ),
			array( 'Conception UX/UI (maquettes)', 'Design', 'Arborescence, parcours, wireframes et maquettes graphiques des pages clés.', "Arborescence\nWireframes\nMaquettes haute fidélité\nGuide de styles", 'forfait' ),
			array( 'Création de site WordPress', 'Développement', "Intégration du site, mise en place des contenus, optimisation SEO de base et formation à l'administration.", "Site en ligne\nAccès administrateur\nSession de formation", 'forfait' ),
			array( 'Stratégie de contenus et réseaux sociaux', 'Communication', 'Ligne éditoriale, calendrier de publication et formats adaptés à chaque réseau.', "Ligne éditoriale\nCalendrier éditorial sur 3 mois", 'forfait' ),
			array( 'Accompagnement mensuel', 'Accompagnement', 'Suivi, optimisation continue et points réguliers.', 'Point mensuel et tableau de bord', 'mois' ),
		);
		foreach ( $rows as $i => $r ) {
			$wpdb->insert(
				self::t( 'services' ),
				array(
					'name'         => $r[0],
					'category'     => $r[1],
					'description'  => $r[2],
					'deliverables' => $r[3],
					'unit'         => $r[4],
					'price'        => 0,
					'vat'          => 20,
					'active'       => 1,
					'sort'         => $i,
				)
			);
		}
	}

	public static function now() {
		return current_time( 'mysql' );
	}

	public static function log( $client_id, $type, $message ) {
		global $wpdb;
		$wpdb->insert(
			self::t( 'activity' ),
			array(
				'client_id'  => (int) $client_id,
				'type'       => $type,
				'message'    => $message,
				'user_id'    => get_current_user_id(),
				'created_at' => self::now(),
			)
		);
	}

	/** Deletes a client and everything attached to it (RGPD). */
	public static function delete_client( $id ) {
		global $wpdb;
		$id = (int) $id;
		foreach ( array( 'contacts', 'quotes', 'reports', 'activity' ) as $t ) {
			$wpdb->delete( self::t( $t ), array( 'client_id' => $id ) );
		}
		return (bool) $wpdb->delete( self::t( 'clients' ), array( 'id' => $id ) );
	}
}
