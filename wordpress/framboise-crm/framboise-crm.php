<?php
/**
 * Plugin Name:       Framboise CRM
 * Description:       Gestion des clients, contacts, états, prestations, devis (avec cahier des charges de livraison), comptes-rendus et alertes e-mail, directement dans WordPress.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            La Framboise Digitale
 * License:           GPL-2.0-or-later
 * Text Domain:       framboise-crm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FRCRM_VERSION', '1.0.0' );
define( 'FRCRM_FILE', __FILE__ );
define( 'FRCRM_DIR', plugin_dir_path( __FILE__ ) );
define( 'FRCRM_URL', plugin_dir_url( __FILE__ ) );
define( 'FRCRM_CAP', 'frcrm_access' );

require_once FRCRM_DIR . 'includes/class-frcrm-db.php';
require_once FRCRM_DIR . 'includes/class-frcrm-settings.php';
require_once FRCRM_DIR . 'includes/class-frcrm-rest.php';
require_once FRCRM_DIR . 'includes/class-frcrm-notify.php';
require_once FRCRM_DIR . 'includes/class-frcrm-admin.php';
require_once FRCRM_DIR . 'includes/class-frcrm-print.php';

register_activation_hook( __FILE__, array( 'FRCRM_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FRCRM_Notify', 'unschedule' ) );

add_action( 'plugins_loaded', array( 'FRCRM_DB', 'maybe_upgrade' ) );
add_action( 'rest_api_init', array( 'FRCRM_REST', 'routes' ) );
add_action( 'frcrm_daily', array( 'FRCRM_Notify', 'daily' ) );
add_action( 'admin_post_frcrm_print', array( 'FRCRM_Print', 'render' ) );

if ( is_admin() ) {
	FRCRM_Admin::init();
}

/** Who may use the CRM: anyone with the CRM capability, and site administrators. */
function frcrm_can() {
	return current_user_can( FRCRM_CAP ) || current_user_can( 'manage_options' );
}
