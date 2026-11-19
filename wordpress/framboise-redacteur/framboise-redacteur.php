<?php
/**
 * Plugin Name:       Framboise Rédacteur
 * Description:       Rédige des articles de blog optimisés pour le SEO avec Claude (API Anthropic), directement dans WordPress : analyse des mots-clés, articles structurés, liens internes, images, champs Yoast et programme de publication.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            La Framboise Digitale
 * License:           GPL-2.0-or-later
 * Text Domain:       framboise-redacteur
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FRR_VERSION', '1.0.0' );
define( 'FRR_FILE', __FILE__ );
define( 'FRR_DIR', plugin_dir_path( __FILE__ ) );
define( 'FRR_URL', plugin_dir_url( __FILE__ ) );

require_once FRR_DIR . 'includes/class-frr-claude.php';
require_once FRR_DIR . 'includes/class-frr-writer.php';
require_once FRR_DIR . 'includes/class-frr-admin.php';

add_action(
	'plugins_loaded',
	function () {
		if ( is_admin() ) {
			FRR_Admin::init();
		}
	}
);

register_uninstall_hook( __FILE__, 'frr_uninstall' );
function frr_uninstall() {
	delete_option( 'frr_settings' );
	delete_option( 'frr_usage' );
}
