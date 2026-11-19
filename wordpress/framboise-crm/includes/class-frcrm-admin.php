<?php
/**
 * CRM admin screen (a single page app inside wp-admin).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FRCRM_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		$cap = current_user_can( 'manage_options' ) ? 'manage_options' : FRCRM_CAP;
		add_menu_page( 'Framboise CRM', 'CRM', $cap, 'frcrm', array( __CLASS__, 'page' ), 'dashicons-groups', 3 );
		$subs = array(
			'/dashboard' => 'Tableau de bord',
			'/clients'   => 'Clients',
			'/quotes'    => 'Devis',
			'/services'  => 'Prestations',
			'/settings'  => 'Réglages',
		);
		foreach ( $subs as $hash => $label ) {
			add_submenu_page( 'frcrm', $label, $label, $cap, 'frcrm' . ( '/dashboard' === $hash ? '' : '#' . $hash ), '/dashboard' === $hash ? array( __CLASS__, 'page' ) : '__return_null' );
		}
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_frcrm' !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'frcrm', FRCRM_URL . 'assets/app.css', array(), FRCRM_VERSION );
		wp_enqueue_script( 'frcrm', FRCRM_URL . 'assets/app.js', array(), FRCRM_VERSION, true );
		wp_localize_script(
			'frcrm',
			'FRCRM',
			array(
				'rest'  => esc_url_raw( rest_url( FRCRM_REST::NS ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'print' => admin_url( 'admin-post.php?action=frcrm_print&_wpnonce=' . wp_create_nonce( 'frcrm_print' ) . '&id=' ),
				'user'  => wp_get_current_user()->display_name,
			)
		);
	}

	public static function page() {
		if ( ! frcrm_can() ) {
			wp_die( 'Accès réservé.' );
		}
		echo '<div class="wrap frcrm-wrap"><div id="frcrm-app" class="frcrm"><p class="frcrm-loading">Chargement du CRM…</p></div></div>';
	}
}
