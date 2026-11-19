<?php
/**
 * SEO analysis, article writing and post creation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FRR_Writer {

	const LENGTHS = array(
		'court' => array(
			'label'  => 'Court (environ 800 mots)',
			'words'  => 800,
			'tokens' => 3500,
		),
		'moyen' => array(
			'label'  => 'Moyen (environ 1 400 mots)',
			'words'  => 1400,
			'tokens' => 6000,
		),
		'long'  => array(
			'label'  => 'Long (environ 2 200 mots)',
			'words'  => 2200,
			'tokens' => 9000,
		),
	);

	const TONES = array(
		'expert'      => 'Expert et pédagogue',
		'premium'     => 'Premium, élégant (univers luxe)',
		'chaleureux'  => 'Chaleureux et proche',
		'direct'      => 'Direct et percutant',
		'inspirant'   => 'Inspirant, storytelling',
	);

	/* ------------------------------------------------------------------ */
	/* Shared writing rules                                               */
	/* ------------------------------------------------------------------ */

	private static function voice_block() {
		$s   = FRR_Admin::settings();
		$out = '';
		if ( '' !== trim( $s['brand'] ) ) {
			$out .= "\nQui écrit : " . $s['brand'];
		}
		if ( '' !== trim( $s['audience'] ) ) {
			$out .= "\nLecteurs visés : " . $s['audience'];
		}
		if ( '' !== trim( $s['voice'] ) ) {
			$out .= "\nCharte éditoriale à respecter : " . $s['voice'];
		}
		return $out;
	}

	private static function human_rules() {
		return <<<TXT
Règles d'écriture (obligatoires) :
- Français naturel, écrit par un humain expert. Phrases de longueurs variées, paragraphes de 2 à 4 phrases.
- Une accroche forte dès la première phrase : un constat concret, une question ou une situation vécue. Jamais « Dans un monde où », « À l'ère du numérique », « Dans cet article, nous allons ».
- Pas de tiret long (—) ni de flèche. Pas de « En conclusion », « En somme », « Il est important de noter », « N'hésitez pas ».
- Les listes à puces seulement quand elles aident vraiment (étapes, critères), pas plus de deux par article. Le reste en prose.
- Gras avec parcimonie : 3 à 5 expressions clés dans tout l'article.
- Des exemples concrets et des conseils actionnables. N'invente ni chiffres précis, ni études, ni citations, ni noms de clients : reste factuel ou formule de manière générale.
- Pas d'emojis.
TXT;
	}

	/* ------------------------------------------------------------------ */
	/* SEO analysis                                                       */
	/* ------------------------------------------------------------------ */

	public static function analyze( $theme, $keywords, $count ) {
		$s        = FRR_Admin::settings();
		$existing = implode( "\n", array_slice( wp_list_pluck( self::site_links(), 'title' ), 0, 60 ) );
		$count    = max( 1, min( 15, (int) $count ) );

		$system = "Tu es consultant SEO senior francophone. Tu réponds uniquement par un objet JSON valide, sans texte autour." . self::voice_block();
		$user   = "Site : " . home_url() . "\nThématique : {$theme}\n"
			. ( $keywords ? "Mots-clés souhaités par le client : {$keywords}\n" : '' )
			. "Articles déjà publiés (à ne pas dupliquer) :\n{$existing}\n\n"
			. ( $s['web_search'] ? "Utilise la recherche web pour repérer ce qui se cherche et se publie actuellement sur ce sujet en France.\n" : '' )
			. "Produis l'analyse au format JSON suivant :\n"
			. '{"intention":"intention de recherche dominante en une phrase","mot_cle_principal":"…","mots_cles_secondaires":["…"],"longue_traine":["…"],"questions":["questions que se pose l\'internaute"],"segments":[{"nom":"groupe thématique","mots_cles":["…"]}],"idees":[{"titre":"titre d\'article accrocheur, 55 à 65 caractères","mot_cle":"mot-clé principal de cet article","angle":"angle en une phrase"}]}'
			. "\nDonne 8 à 12 mots-clés secondaires, 6 à 10 expressions de longue traîne, 4 à 6 questions, 3 à 5 segments et exactement {$count} idées d'articles complémentaires (pas de cannibalisation entre elles).";

		$r = FRR_Claude::ask( $system, $user, 3000, (bool) $s['web_search'] );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$data = FRR_Claude::json( $r['text'] );
		if ( ! $data || empty( $data['idees'] ) ) {
			return new WP_Error( 'frr_bad_json', 'L\'analyse n\'a pas pu être lue. Relancez-la.' );
		}
		return $data;
	}

	/* ------------------------------------------------------------------ */
	/* Article                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array $a title, keyword, secondary, theme, angle, length, tone, links (bool), target (bool), category, status, date, images[]
	 */
	public static function write( $a ) {
		$s       = FRR_Admin::settings();
		$len     = isset( self::LENGTHS[ $a['length'] ] ) ? self::LENGTHS[ $a['length'] ] : self::LENGTHS['moyen'];
		$tone    = isset( self::TONES[ $a['tone'] ] ) ? self::TONES[ $a['tone'] ] : self::TONES['expert'];
		$allowed = array();

		$links_txt = '';
		if ( ! empty( $a['links'] ) ) {
			$posts = array_slice( self::site_links(), 0, 40 );
			if ( $posts ) {
				$links_txt .= "\nPages du site que tu peux lier (liens internes) :\n";
				foreach ( $posts as $p ) {
					$links_txt              .= '- ' . $p['title'] . ' : ' . $p['url'] . "\n";
					$allowed[ $p['url'] ]    = true;
				}
			}
		}
		if ( ! empty( $a['target'] ) ) {
			$targets = self::target_links();
			if ( $targets ) {
				$links_txt .= "\nPages prioritaires à lier au moins une fois chacune quand le sujet s'y prête :\n";
				foreach ( $targets as $t ) {
					$links_txt            .= '- ' . $t['url'] . ( $t['hint'] ? ' (sujet : ' . $t['hint'] . ')' : '' ) . "\n";
					$allowed[ $t['url'] ]  = true;
				}
			}
		}
		$max_links = max( 0, (int) $s['max_links'] );
		if ( $allowed ) {
			$links_txt .= "Règles des liens : {$max_links} liens au maximum, au format [ancre](URL), uniquement vers les URL listées ci-dessus. Ancres naturelles de 2 à 6 mots, toutes différentes, jamais « cliquez ici », jamais le mot-clé exact répété en ancre. Un lien est placé dans une phrase où il apporte vraiment quelque chose.\n";
		}

		$system = "Tu es rédacteur web SEO senior et tu écris pour le blog de ce site." . self::voice_block() . "\nTon : {$tone}.\n\n" . self::human_rules();

		$user = "Écris un article de blog complet.\n"
			. ( $a['title'] ? "Titre de travail : {$a['title']}\n" : '' )
			. "Mot-clé principal : {$a['keyword']}\n"
			. ( $a['secondary'] ? "Mots-clés secondaires à intégrer naturellement : {$a['secondary']}\n" : '' )
			. ( $a['theme'] ? "Contexte / thématique : {$a['theme']}\n" : '' )
			. ( $a['angle'] ? "Angle : {$a['angle']}\n" : '' )
			. "Longueur visée : environ {$len['words']} mots.\n"
			. $links_txt
			. ( '' !== trim( $s['cta'] ) ? "Termine par un appel à l'action sobre, dans l'esprit suivant : {$s['cta']}\n" : '' )
			. "\nStructure SEO attendue : le mot-clé principal dans le titre, dans la première phrase ou le premier paragraphe et dans au moins un intertitre. Un intertitre (##) toutes les 250 à 350 mots, des sous-parties (###) si utile. Une courte section « Questions fréquentes » avant la fin, avec 3 ou 4 questions en ### et des réponses de 2 à 3 phrases.\n"
			. "\nRéponds exactement dans ce format, sans rien avant :\n"
			. "TITRE: titre de 55 à 65 caractères\n"
			. "SLUG: slug-court-sans-mots-vides\n"
			. "META_TITRE: titre SEO de 60 caractères maximum\n"
			. "META_DESCRIPTION: 140 à 155 caractères, incitative, avec le mot-clé\n"
			. "EXTRAIT: résumé de 1 à 2 phrases\n"
			. "ETIQUETTES: 3 à 5 étiquettes séparées par des virgules\n"
			. "ALT_IMAGE: texte alternatif descriptif pour l'image principale\n"
			. "---\n"
			. "(corps de l'article en Markdown : paragraphes, ## et ### pour les intertitres, - pour les listes, **gras**, *italique*, [ancre](URL). Ne répète pas le titre.)";

		$r = FRR_Claude::ask( $system, $user, $len['tokens'] );
		if ( is_wp_error( $r ) ) {
			return $r;
		}

		$parsed = self::parse( $r['text'] );
		if ( '' === $parsed['body'] ) {
			return new WP_Error( 'frr_bad_format', 'Le texte reçu n\'a pas le format attendu. Relancez cet article.' );
		}
		if ( '' === $parsed['title'] ) {
			$parsed['title'] = $a['title'] ? $a['title'] : $a['keyword'];
		}

		$images  = array_values( array_filter( array_map( 'intval', (array) $a['images'] ) ) );
		$content = self::blocks( $parsed['body'], $allowed, array_slice( $images, 1 ), $parsed['alt'] );

		$status = in_array( $a['status'], array( 'draft', 'pending', 'publish', 'future' ), true ) ? $a['status'] : 'draft';
		if ( in_array( $status, array( 'publish', 'future' ), true ) && ! current_user_can( 'publish_posts' ) ) {
			$status = 'pending';
		}

		$post = array(
			'post_type'    => 'post',
			'post_title'   => $parsed['title'],
			'post_name'    => sanitize_title( $parsed['slug'] ? $parsed['slug'] : $parsed['title'] ),
			'post_content' => $content,
			'post_excerpt' => $parsed['excerpt'],
			'post_status'  => $status,
			'post_author'  => get_current_user_id(),
		);
		if ( ! empty( $a['category'] ) ) {
			$post['post_category'] = array( (int) $a['category'] );
		}
		if ( 'future' === $status ) {
			$ts = strtotime( $a['date'] . ' ' . wp_timezone_string() );
			if ( ! $ts || $ts <= time() ) {
				$post['post_status'] = 'draft';
			} else {
				$post['post_date']     = wp_date( 'Y-m-d H:i:s', $ts );
				$post['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}

		$id = wp_insert_post( wp_slash( $post ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		if ( $parsed['tags'] ) {
			wp_set_post_tags( $id, $parsed['tags'], false );
		}
		if ( $images ) {
			set_post_thumbnail( $id, $images[0] );
			foreach ( $images as $img ) {
				if ( '' === (string) get_post_meta( $img, '_wp_attachment_image_alt', true ) && $parsed['alt'] ) {
					update_post_meta( $img, '_wp_attachment_image_alt', sanitize_text_field( $parsed['alt'] ) );
				}
			}
		}
		update_post_meta( $id, '_yoast_wpseo_title', sanitize_text_field( $parsed['meta_title'] ) );
		update_post_meta( $id, '_yoast_wpseo_metadesc', sanitize_text_field( $parsed['meta_desc'] ) );
		update_post_meta( $id, '_yoast_wpseo_focuskw', sanitize_text_field( $a['keyword'] ) );
		update_post_meta( $id, '_frr_generated', gmdate( 'c' ) );
		update_post_meta( $id, '_frr_usage', $r['usage'] );

		return array(
			'id'        => $id,
			'title'     => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'status'    => get_post_status( $id ),
			'date'      => get_the_date( 'j F Y à H:i', $id ),
			'edit'      => get_edit_post_link( $id, 'raw' ),
			'view'      => get_permalink( $id ),
			'words'     => count( preg_split( '/\s+/u', trim( wp_strip_all_tags( $content ) ), -1, PREG_SPLIT_NO_EMPTY ) ),
			'truncated' => ! empty( $r['truncated'] ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Parsing and conversion                                             */
	/* ------------------------------------------------------------------ */

	public static function parse( $text ) {
		$out   = array(
			'title'      => '',
			'slug'       => '',
			'meta_title' => '',
			'meta_desc'  => '',
			'excerpt'    => '',
			'tags'       => array(),
			'alt'        => '',
			'body'       => '',
		);
		$text  = str_replace( "\r", '', trim( $text ) );
		$text  = preg_replace( '/^```[a-z]*\n|\n```$/', '', $text );
		$parts = preg_split( '/^\s*---\s*$/m', $text, 2 );
		if ( count( $parts ) < 2 ) {
			$parts = array( '', $text );
		}
		$map = array(
			'TITRE'            => 'title',
			'SLUG'             => 'slug',
			'META_TITRE'       => 'meta_title',
			'META_DESCRIPTION' => 'meta_desc',
			'EXTRAIT'          => 'excerpt',
			'ALT_IMAGE'        => 'alt',
		);
		foreach ( explode( "\n", $parts[0] ) as $line ) {
			if ( preg_match( '/^\s*\**([A-Z_]+)\**\s*:\s*(.+)$/', $line, $m ) ) {
				$k = $m[1];
				$v = trim( self::clean( $m[2] ), " \t\"*" );
				if ( isset( $map[ $k ] ) ) {
					$out[ $map[ $k ] ] = $v;
				} elseif ( 'ETIQUETTES' === $k ) {
					$out['tags'] = array_values( array_filter( array_map( 'trim', explode( ',', $v ) ) ) );
				}
			}
		}
		$body = trim( $parts[1] );
		// A repeated title as first heading is dropped.
		$body        = preg_replace( '/^#\s+.+\n+/', '', $body );
		$out['body'] = $body;
		return $out;
	}

	/** Typographic clean-up shared by headings and paragraphs. */
	public static function clean( $t ) {
		$t = preg_replace( '/\s*—\s*/u', ', ', $t );
		$t = preg_replace( '/\s+–\s+/u', ', ', $t );
		$t = preg_replace( '/\s?(→|➡️|👉)\s?/u', ' ', $t );
		$t = preg_replace( '/,\s*,/', ',', $t );
		$t = preg_replace( '/\s+([?!;:»])/u', "\u{00A0}$1", $t ); // French non-breaking space before high punctuation.
		$t = preg_replace( '/«\s*/u', "«\u{00A0}", $t );
		return trim( $t );
	}

	private static function inline( $t, $allowed ) {
		$t     = self::clean( $t );
		$links = array();
		$t     = preg_replace_callback(
			'/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/',
			function ( $m ) use ( &$links, $allowed ) {
				$url = untrailingslashit( $m[2] );
				$ok  = isset( $allowed[ $m[2] ] ) || isset( $allowed[ $url ] ) || isset( $allowed[ trailingslashit( $url ) ] );
				if ( ! $ok ) {
					return $m[1]; // Unknown URL: keep the words, drop the link.
				}
				$links[] = array( $m[1], $m[2] );
				return "\x01" . ( count( $links ) - 1 ) . "\x02";
			},
			$t
		);
		$t = htmlspecialchars( $t, ENT_NOQUOTES, 'UTF-8' );
		$t = preg_replace( '/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $t );
		$t = preg_replace( '/(?<![\*\w])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![\*\w])/u', '<em>$1</em>', $t );
		$t = preg_replace_callback(
			"/\x01(\d+)\x02/",
			function ( $m ) use ( $links ) {
				list( $label, $url ) = $links[ (int) $m[1] ];
				$label = preg_replace( '/\*\*(.+?)\*\*/u', '<strong>$1</strong>', htmlspecialchars( $label, ENT_NOQUOTES, 'UTF-8' ) );
				return '<a href="' . esc_url( $url ) . '">' . $label . '</a>';
			},
			$t
		);
		return $t;
	}

	/**
	 * Markdown subset to Gutenberg block markup. Extra images are placed after the 2nd, 4th… H2.
	 */
	public static function blocks( $md, $allowed = array(), $images = array(), $alt = '' ) {
		$lines  = explode( "\n", str_replace( "\r", '', $md ) );
		$out    = array();
		$para   = array();
		$list   = array();
		$listo  = false;
		$h2     = 0;
		$imgq   = $images;

		$flush_para = function () use ( &$para, &$out, $allowed ) {
			if ( $para ) {
				$out[] = "<!-- wp:paragraph -->\n<p>" . self::inline( implode( ' ', $para ), $allowed ) . "</p>\n<!-- /wp:paragraph -->";
				$para  = array();
			}
		};
		$flush_list = function () use ( &$list, &$listo, &$out, $allowed ) {
			if ( $list ) {
				$items = '';
				foreach ( $list as $li ) {
					$items .= "<!-- wp:list-item -->\n<li>" . self::inline( $li, $allowed ) . "</li>\n<!-- /wp:list-item -->";
				}
				$tag   = $listo ? 'ol' : 'ul';
				$attr  = $listo ? ' {"ordered":true}' : '';
				$out[] = "<!-- wp:list{$attr} -->\n<{$tag} class=\"wp-block-list\">{$items}</{$tag}>\n<!-- /wp:list -->";
				$list  = array();
			}
		};

		foreach ( $lines as $raw ) {
			$line = rtrim( $raw );
			if ( '' === trim( $line ) ) {
				$flush_para();
				$flush_list();
				continue;
			}
			if ( preg_match( '/^(#{2,4})\s+(.+)$/', $line, $m ) ) {
				$flush_para();
				$flush_list();
				$level = min( 4, strlen( $m[1] ) );
				$text  = self::inline( trim( $m[2], " *" ), array() );
				if ( 2 === $level ) {
					$h2++;
					if ( $imgq && $h2 > 1 && 0 === ( $h2 - 1 ) % 2 ) {
						$out[] = self::image_block( array_shift( $imgq ), $alt );
					}
				}
				$attr  = 2 === $level ? '' : ' {"level":' . $level . '}';
				$out[] = "<!-- wp:heading{$attr} -->\n<h{$level} class=\"wp-block-heading\">{$text}</h{$level}>\n<!-- /wp:heading -->";
				continue;
			}
			if ( preg_match( '/^\s*[-*•]\s+(.+)$/', $line, $m ) ) {
				$flush_para();
				if ( $list && $listo ) {
					$flush_list();
				}
				$listo  = false;
				$list[] = $m[1];
				continue;
			}
			if ( preg_match( '/^\s*\d+[.)]\s+(.+)$/', $line, $m ) ) {
				$flush_para();
				if ( $list && ! $listo ) {
					$flush_list();
				}
				$listo  = true;
				$list[] = $m[1];
				continue;
			}
			if ( preg_match( '/^>\s?(.*)$/', $line, $m ) ) {
				$flush_para();
				$flush_list();
				$out[] = "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><!-- wp:paragraph -->\n<p>" . self::inline( $m[1], $allowed ) . "</p>\n<!-- /wp:paragraph --></blockquote>\n<!-- /wp:quote -->";
				continue;
			}
			if ( $list ) {
				$flush_list();
			}
			$para[] = trim( $line );
		}
		$flush_para();
		$flush_list();
		foreach ( $imgq as $img ) {
			$out[] = self::image_block( $img, $alt );
		}
		return implode( "\n\n", $out );
	}

	private static function image_block( $id, $alt ) {
		$src = wp_get_attachment_image_url( $id, 'large' );
		if ( ! $src ) {
			return '';
		}
		$a = get_post_meta( $id, '_wp_attachment_image_alt', true );
		$a = $a ? $a : $alt;
		return '<!-- wp:image {"id":' . (int) $id . ',"sizeSlug":"large","linkDestination":"none"} -->' . "\n"
			. '<figure class="wp-block-image size-large"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $a ) . '" class="wp-image-' . (int) $id . '"/></figure>' . "\n"
			. '<!-- /wp:image -->';
	}

	/* ------------------------------------------------------------------ */
	/* Link sources                                                       */
	/* ------------------------------------------------------------------ */

	public static function site_links() {
		$q   = new WP_Query(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 80,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( $q->posts as $p ) {
			$out[] = array(
				'title' => html_entity_decode( get_the_title( $p ), ENT_QUOTES ),
				'url'   => get_permalink( $p ),
			);
		}
		return $out;
	}

	/** Target pages from Réglages, one per line: "URL | sujet". */
	public static function target_links() {
		$s   = FRR_Admin::settings();
		$out = array();
		foreach ( preg_split( '/\R/', (string) $s['targets'] ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			$url   = esc_url_raw( $parts[0] );
			if ( $url && preg_match( '#^https?://#', $url ) ) {
				$out[] = array(
					'url'  => $url,
					'hint' => isset( $parts[1] ) ? sanitize_text_field( $parts[1] ) : '',
				);
			}
		}
		return $out;
	}
}
