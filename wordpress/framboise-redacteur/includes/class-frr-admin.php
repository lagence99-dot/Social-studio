<?php
/**
 * Admin screens and AJAX endpoints.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FRR_Admin {

	const OPT = 'frr_settings';

	public static function defaults() {
		return array(
			'api_key'    => '',
			'model'      => 'claude-sonnet-5-5',
			'brand'      => '',
			'audience'   => '',
			'voice'      => '',
			'cta'        => '',
			'targets'    => '',
			'max_links'  => 4,
			'web_search' => 1,
			'status'     => 'draft',
			'tone'       => 'expert',
			'length'     => 'moyen',
		);
	}

	public static function settings() {
		$s = get_option( self::OPT, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() );
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_frr_analyze', array( __CLASS__, 'ajax_analyze' ) );
		add_action( 'wp_ajax_frr_write', array( __CLASS__, 'ajax_write' ) );
		add_action( 'wp_ajax_frr_test', array( __CLASS__, 'ajax_test' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FRR_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=frr-settings' ) ) . '">Réglages</a>' );
		return $links;
	}

	public static function menu() {
		add_menu_page( 'Rédacteur IA', 'Rédacteur IA', 'edit_posts', 'frr', array( __CLASS__, 'page_write' ), 'dashicons-edit-page', 6 );
		add_submenu_page( 'frr', 'Rédiger des articles', 'Rédiger', 'edit_posts', 'frr', array( __CLASS__, 'page_write' ) );
		add_submenu_page( 'frr', 'Réglages du Rédacteur IA', 'Réglages', 'manage_options', 'frr-settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'frr' ) ) {
			return;
		}
		wp_enqueue_style( 'frr-admin', FRR_URL . 'assets/admin.css', array(), FRR_VERSION );
		if ( 'toplevel_page_frr' === $hook ) {
			wp_enqueue_media();
		}
		wp_enqueue_script( 'frr-admin', FRR_URL . 'assets/admin.js', array( 'jquery' ), FRR_VERSION, true );
		wp_localize_script(
			'frr-admin',
			'FRR',
			array(
				'ajax'   => admin_url( 'admin-ajax.php' ),
				'nonce'  => wp_create_nonce( 'frr' ),
				'tz'     => wp_timezone_string(),
				'hasKey' => '' !== self::settings()['api_key'],
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                           */
	/* ------------------------------------------------------------------ */

	public static function register() {
		register_setting(
			'frr',
			self::OPT,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	public static function sanitize( $in ) {
		$old = self::settings();
		$in  = is_array( $in ) ? $in : array();
		$out = $old;

		$key = isset( $in['api_key'] ) ? trim( $in['api_key'] ) : '';
		if ( ! empty( $in['api_key_clear'] ) ) {
			$out['api_key'] = '';
		} elseif ( '' !== $key ) {
			$out['api_key'] = sanitize_text_field( $key );
		}
		$out['model']      = isset( FRR_Claude::models()[ $in['model'] ?? '' ] ) ? $in['model'] : 'claude-sonnet-5-5';
		$out['brand']      = sanitize_textarea_field( $in['brand'] ?? '' );
		$out['audience']   = sanitize_textarea_field( $in['audience'] ?? '' );
		$out['voice']      = sanitize_textarea_field( $in['voice'] ?? '' );
		$out['cta']        = sanitize_textarea_field( $in['cta'] ?? '' );
		$out['targets']    = sanitize_textarea_field( $in['targets'] ?? '' );
		$out['max_links']  = max( 0, min( 10, (int) ( $in['max_links'] ?? 4 ) ) );
		$out['web_search'] = empty( $in['web_search'] ) ? 0 : 1;
		$out['status']     = in_array( $in['status'] ?? '', array( 'draft', 'pending', 'publish' ), true ) ? $in['status'] : 'draft';
		$out['tone']       = isset( FRR_Writer::TONES[ $in['tone'] ?? '' ] ) ? $in['tone'] : 'expert';
		$out['length']     = isset( FRR_Writer::LENGTHS[ $in['length'] ?? '' ] ) ? $in['length'] : 'moyen';
		return $out;
	}

	public static function page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = self::settings();
		$key   = $s['api_key'];
		$usage = (array) get_option( 'frr_usage', array() );
		$req   = array_sum( wp_list_pluck( $usage, 'requests' ) );
		?>
		<div class="wrap frr">
			<h1>Réglages du Rédacteur IA</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'frr' ); ?>

				<div class="frr-card">
					<h2>Connexion à Claude</h2>
					<p class="frr-help">La clé se crée sur <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com</a>, rubrique API Keys. Elle reste sur ce serveur et n'est jamais réaffichée en entier.</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="frr-key">Clé API Anthropic</label></th>
							<td>
								<input type="password" id="frr-key" name="frr_settings[api_key]" class="regular-text" autocomplete="new-password" placeholder="<?php echo $key ? esc_attr( 'Enregistrée (…' . substr( $key, -4 ) . '), laisser vide pour la garder' ) : 'sk-ant-…'; ?>">
								<?php if ( $key ) : ?>
									<label class="frr-inline"><input type="checkbox" name="frr_settings[api_key_clear]" value="1"> Supprimer la clé</label>
								<?php endif; ?>
								<p><button type="button" class="button" id="frr-test">Tester la clé</button> <span id="frr-test-out" class="frr-note"></span></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="frr-model">Modèle</label></th>
							<td><select id="frr-model" name="frr_settings[model]">
								<?php foreach ( FRR_Claude::models() as $id => $label ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['model'], $id ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></td>
						</tr>
						<tr>
							<th scope="row">Recherche web</th>
							<td><label><input type="checkbox" name="frr_settings[web_search]" value="1" <?php checked( $s['web_search'] ); ?>> Consulter le web pendant l'analyse SEO (tendances, sujets qui se cherchent). Quelques centimes de plus par analyse.</label></td>
						</tr>
					</table>
					<p class="frr-note">Consommation enregistrée : <?php echo esc_html( number_format_i18n( $req ) ); ?> demandes, environ <?php echo esc_html( number_format_i18n( FRR_Claude::estimated_cost(), 2 ) ); ?> $ (estimation, le montant exact figure sur console.anthropic.com).</p>
				</div>

				<div class="frr-card">
					<h2>Voix de la marque</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="frr-brand">Qui écrit</label></th>
							<td><textarea id="frr-brand" name="frr_settings[brand]" rows="2" class="large-text" placeholder="Ex. : La Framboise Digitale, agence de communication et d'expérience client pour les marques premium, Paris et Cannes."><?php echo esc_textarea( $s['brand'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="frr-aud">Lecteurs visés</label></th>
							<td><textarea id="frr-aud" name="frr_settings[audience]" rows="2" class="large-text" placeholder="Ex. : dirigeants et responsables marketing de maisons de luxe, hôtels et boutiques haut de gamme."><?php echo esc_textarea( $s['audience'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="frr-voice">Charte éditoriale</label></th>
							<td><textarea id="frr-voice" name="frr_settings[voice]" rows="4" class="large-text" placeholder="Ex. : vouvoiement, ton expert mais accessible, vocabulaire précis, pas de jargon anglais inutile…"><?php echo esc_textarea( $s['voice'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="frr-cta">Appel à l'action</label></th>
							<td><textarea id="frr-cta" name="frr_settings[cta]" rows="2" class="large-text" placeholder="Ex. : proposer un échange de 30 minutes avec l'agence."><?php echo esc_textarea( $s['cta'] ); ?></textarea></td>
						</tr>
					</table>
				</div>

				<div class="frr-card">
					<h2>Liens</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="frr-targets">Pages à mettre en avant</label></th>
							<td>
								<textarea id="frr-targets" name="frr_settings[targets]" rows="5" class="large-text code" placeholder="https://laframboisedigitale.fr/services/ | accompagnement digital des marques de luxe&#10;https://lagence99.fr/ | conseil en expérience utilisateur"><?php echo esc_textarea( $s['targets'] ); ?></textarea>
								<p class="frr-help">Une page par ligne : l'adresse, puis après une barre verticale le sujet de la page. Ces liens peuvent viser un autre de vos sites. Les articles du site sont, eux, proposés automatiquement comme liens internes.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="frr-max">Liens par article (maximum)</label></th>
							<td><input type="number" id="frr-max" name="frr_settings[max_links]" min="0" max="10" value="<?php echo esc_attr( $s['max_links'] ); ?>" class="small-text"></td>
						</tr>
					</table>
				</div>

				<div class="frr-card">
					<h2>Valeurs par défaut</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">Statut</th>
							<td><select name="frr_settings[status]">
								<option value="draft" <?php selected( $s['status'], 'draft' ); ?>>Brouillon (recommandé)</option>
								<option value="pending" <?php selected( $s['status'], 'pending' ); ?>>En attente de relecture</option>
								<option value="publish" <?php selected( $s['status'], 'publish' ); ?>>Publié directement</option>
							</select></td>
						</tr>
						<tr>
							<th scope="row">Ton</th>
							<td><select name="frr_settings[tone]">
								<?php foreach ( FRR_Writer::TONES as $id => $label ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['tone'], $id ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></td>
						</tr>
						<tr>
							<th scope="row">Longueur</th>
							<td><select name="frr_settings[length]">
								<?php foreach ( FRR_Writer::LENGTHS as $id => $l ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['length'], $id ); ?>><?php echo esc_html( $l['label'] ); ?></option>
								<?php endforeach; ?>
							</select></td>
						</tr>
					</table>
				</div>

				<?php submit_button( 'Enregistrer les réglages' ); ?>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Writing screen                                                     */
	/* ------------------------------------------------------------------ */

	public static function page_write() {
		$s    = self::settings();
		$cats = get_categories( array( 'hide_empty' => false ) );
		$recent = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'draft', 'pending', 'publish', 'future' ),
				'meta_key'       => '_frr_generated', // phpcs:ignore WordPress.DB.SlowDBQuery
				'posts_per_page' => 12,
			)
		);
		$labels = array(
			'draft'   => 'Brouillon',
			'pending' => 'En attente',
			'publish' => 'Publié',
			'future'  => 'Programmé',
		);
		?>
		<div class="wrap frr">
			<h1>Rédiger des articles</h1>

			<?php if ( '' === $s['api_key'] ) : ?>
				<div class="notice notice-warning"><p>Ajoutez d'abord votre clé API Anthropic dans <a href="<?php echo esc_url( admin_url( 'admin.php?page=frr-settings' ) ); ?>">Réglages</a>.</p></div>
			<?php endif; ?>

			<div class="frr-grid">
				<div class="frr-main">

					<section class="frr-card">
						<h2><span class="frr-step">1</span> Sujet et analyse SEO</h2>
						<label class="frr-label" for="frr-theme">Thématique</label>
						<textarea id="frr-theme" rows="3" class="large-text" placeholder="Ex. : l'expérience client en boutique de luxe et le lien avec le digital"></textarea>
						<label class="frr-label" for="frr-kw">Mots-clés à cibler <span class="frr-opt">facultatif</span></label>
						<input type="text" id="frr-kw" class="large-text" placeholder="Ex. : expérience client luxe, parcours omnicanal">
						<p><button type="button" class="button button-secondary" id="frr-analyze">Analyser les mots-clés</button> <span class="frr-note" id="frr-analyze-note"></span></p>
						<div id="frr-seo" hidden></div>
					</section>

					<section class="frr-card">
						<h2><span class="frr-step">2</span> Articles à produire</h2>
						<div class="frr-row">
							<div>
								<label class="frr-label" for="frr-count">Nombre d'articles</label>
								<input type="number" id="frr-count" min="1" max="15" value="1" class="small-text">
							</div>
							<div>
								<label class="frr-label" for="frr-length">Longueur</label>
								<select id="frr-length">
									<?php foreach ( FRR_Writer::LENGTHS as $id => $l ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['length'], $id ); ?>><?php echo esc_html( $l['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div>
								<label class="frr-label" for="frr-tone">Ton</label>
								<select id="frr-tone">
									<?php foreach ( FRR_Writer::TONES as $id => $label ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['tone'], $id ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div>
								<label class="frr-label" for="frr-cat">Catégorie</label>
								<select id="frr-cat">
									<option value="">Par défaut</option>
									<?php foreach ( $cats as $c ) : ?>
										<option value="<?php echo esc_attr( $c->term_id ); ?>"><?php echo esc_html( $c->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						<p class="frr-help" id="frr-plan-help">Les sujets cochés à l'étape 1 sont utilisés en priorité. Sans analyse, les sujets sont choisis automatiquement à partir de la thématique.</p>
						<label class="frr-check"><input type="checkbox" id="frr-links" checked> Liens internes automatiques vers les articles et pages du site</label>
						<label class="frr-check"><input type="checkbox" id="frr-target" <?php checked( '' !== trim( $s['targets'] ) ); ?>> Liens vers les pages à mettre en avant (définies dans Réglages)</label>
					</section>

					<section class="frr-card">
						<h2><span class="frr-step">3</span> Photos <span class="frr-opt">facultatif</span></h2>
						<p class="frr-help">Choisissez une ou plusieurs images dans la médiathèque. La première devient l'image à la une. Avec un seul article, les suivantes sont placées dans le texte. Avec plusieurs articles, elles sont réparties entre eux.</p>
						<p><button type="button" class="button" id="frr-images">Choisir des images</button> <button type="button" class="button-link" id="frr-images-clear" hidden>Retirer toutes les images</button></p>
						<ul id="frr-thumbs" class="frr-thumbs"></ul>
					</section>

					<section class="frr-card">
						<h2><span class="frr-step">4</span> Publication</h2>
						<div class="frr-seg" role="radiogroup" aria-label="Mode de publication">
							<label><input type="radio" name="frr-mode" value="now" checked> En une fois</label>
							<label><input type="radio" name="frr-mode" value="plan"> Programme étalé</label>
						</div>
						<div id="frr-now">
							<label class="frr-label" for="frr-status">Statut des articles</label>
							<select id="frr-status">
								<option value="draft" <?php selected( $s['status'], 'draft' ); ?>>Brouillon (pour relire avant publication)</option>
								<option value="pending" <?php selected( $s['status'], 'pending' ); ?>>En attente de relecture</option>
								<option value="publish" <?php selected( $s['status'], 'publish' ); ?>>Publié tout de suite</option>
							</select>
						</div>
						<div id="frr-plan" hidden>
							<div class="frr-row">
								<div>
									<label class="frr-label" for="frr-start">Premier article le</label>
									<input type="date" id="frr-start">
								</div>
								<div>
									<label class="frr-label" for="frr-hour">à</label>
									<input type="time" id="frr-hour" value="09:00">
								</div>
								<div>
									<label class="frr-label" for="frr-every">Rythme</label>
									<select id="frr-every">
										<option value="1">Tous les jours</option>
										<option value="2" selected>Tous les 2 jours</option>
										<option value="3">Tous les 3 jours</option>
										<option value="7">Chaque semaine</option>
										<option value="14">Toutes les 2 semaines</option>
									</select>
								</div>
							</div>
							<p class="frr-help" id="frr-plan-summary"></p>
							<p class="frr-help">Les articles sont tous rédigés maintenant et programmés aux dates prévues : vous pouvez les relire et les modifier d'ici là. WordPress les publie seul, même ordinateur éteint.</p>
						</div>
						<p class="frr-actions"><button type="button" class="button button-primary button-hero" id="frr-go">Rédiger les articles</button> <button type="button" class="button" id="frr-stop" hidden>Arrêter après l'article en cours</button></p>
						<div id="frr-progress" hidden>
							<div class="frr-bar"><span id="frr-bar"></span></div>
							<p class="frr-note" id="frr-progress-note"></p>
							<ol id="frr-results" class="frr-results"></ol>
						</div>
					</section>
				</div>

				<aside class="frr-side">
					<div class="frr-card">
						<h2>Derniers articles rédigés</h2>
						<?php if ( ! $recent ) : ?>
							<p class="frr-note">Aucun pour l'instant.</p>
						<?php else : ?>
							<ul class="frr-recent">
								<?php foreach ( $recent as $p ) : ?>
									<li>
										<a href="<?php echo esc_url( get_edit_post_link( $p->ID ) ); ?>"><?php echo esc_html( get_the_title( $p ) ); ?></a>
										<span class="frr-badge frr-<?php echo esc_attr( $p->post_status ); ?>"><?php echo esc_html( $labels[ $p->post_status ] ?? $p->post_status ); ?></span>
										<?php if ( 'future' === $p->post_status ) : ?>
											<span class="frr-note"><?php echo esc_html( get_the_date( 'j M Y, H:i', $p ) ); ?></span>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
					<div class="frr-card">
						<h2>Bon à savoir</h2>
						<p class="frr-help">Chaque article s'ouvre dans l'éditeur WordPress habituel : gras, italique, liens, titres et images se modifient comme d'habitude. Pour recadrer une photo, utilisez « Modifier l'image » dans la médiathèque.</p>
						<p class="frr-help">Un article long prend en général 1 à 2 minutes. Gardez cette page ouverte pendant la rédaction.</p>
					</div>
				</aside>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                               */
	/* ------------------------------------------------------------------ */

	private static function guard( $cap = 'edit_posts' ) {
		check_ajax_referer( 'frr', 'nonce' );
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( array( 'message' => 'Droits insuffisants.' ), 403 );
		}
	}

	private static function post_text( $k, $multi = false ) {
		$v = isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		return $multi ? sanitize_textarea_field( $v ) : sanitize_text_field( $v );
	}

	public static function ajax_analyze() {
		self::guard();
		$theme = self::post_text( 'theme', true );
		if ( '' === $theme && '' === self::post_text( 'keywords' ) ) {
			wp_send_json_error( array( 'message' => 'Indiquez une thématique ou des mots-clés.' ) );
		}
		$r = FRR_Writer::analyze( $theme, self::post_text( 'keywords' ), (int) self::post_text( 'count' ) );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		wp_send_json_success( $r );
	}

	public static function ajax_write() {
		self::guard();
		$images = isset( $_POST['images'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['images'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		$images = array_values(
			array_filter(
				$images,
				function ( $id ) {
					return $id > 0 && wp_attachment_is_image( $id );
				}
			)
		);
		$r = FRR_Writer::write(
			array(
				'title'     => self::post_text( 'title' ),
				'keyword'   => self::post_text( 'keyword' ),
				'secondary' => self::post_text( 'secondary' ),
				'theme'     => self::post_text( 'theme', true ),
				'angle'     => self::post_text( 'angle' ),
				'length'    => self::post_text( 'length' ),
				'tone'      => self::post_text( 'tone' ),
				'links'     => '1' === self::post_text( 'links' ),
				'target'    => '1' === self::post_text( 'target' ),
				'category'  => (int) self::post_text( 'category' ),
				'status'    => self::post_text( 'status' ),
				'date'      => self::post_text( 'date' ),
				'images'    => $images,
			)
		);
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		wp_send_json_success( $r );
	}

	public static function ajax_test() {
		self::guard( 'manage_options' );
		$r = FRR_Claude::ask( 'Tu réponds en un seul mot.', 'Réponds simplement : OK', 10 );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => 'La clé fonctionne. Claude a répondu : ' . wp_strip_all_tags( $r['text'] ) ) );
	}
}
