<?php
/**
 * Plugin Name:       Framboise Studio Publisher
 * Description:       Ajoute au connecteur MCP Adapter les actions dont Social Studio a besoin : lister les catégories et les articles, envoyer une image, créer et modifier un article (brouillon, programmé ou publié) avec les champs Yoast. Aucune action de suppression ni de réglage du site.
 * Version:           1.2.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            La Framboise Digitale
 * License:           GPL-2.0-or-later
 * Text Domain:       framboise-studio-publisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Some shared hosts (OVH among them) drop the standard Authorization header before PHP sees it.
 * Social Studio's connector therefore also sends the same credentials in X-Framboise-Auth.
 * They are handed to WordPress's own application-password check, exactly as if the standard
 * header had arrived: nothing is trusted here, WordPress still verifies the password.
 */
if ( empty( $_SERVER['PHP_AUTH_USER'] ) && ! empty( $_SERVER['HTTP_X_FRAMBOISE_AUTH'] ) ) {
	$fsp_auth = trim( wp_unslash( $_SERVER['HTTP_X_FRAMBOISE_AUTH'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( 0 === stripos( $fsp_auth, 'basic ' ) ) {
		$fsp_auth = base64_decode( substr( $fsp_auth, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( is_string( $fsp_auth ) && false !== strpos( $fsp_auth, ':' ) ) {
			list( $fsp_user, $fsp_pass )  = explode( ':', $fsp_auth, 2 );
			$_SERVER['PHP_AUTH_USER']     = $fsp_user;
			$_SERVER['PHP_AUTH_PW']       = $fsp_pass;
		}
	}
	unset( $fsp_auth, $fsp_user, $fsp_pass );
}

/*
 * If another extension looked up the current user before the REST request was identified
 * as such, WordPress skipped the application-password check and kept "logged out".
 * Run that same core check again once the REST API is loading.
 */
function fsp_rest_app_password_check() {
	if ( empty( $_SERVER['PHP_AUTH_USER'] ) || ! function_exists( 'wp_authenticate_application_password' ) ) {
		return null;
	}
	return wp_authenticate_application_password( null, wp_unslash( $_SERVER['PHP_AUTH_USER'] ), isset( $_SERVER['PHP_AUTH_PW'] ) ? wp_unslash( $_SERVER['PHP_AUTH_PW'] ) : '' ); // phpcs:ignore
}

add_action(
	'rest_api_init',
	function () {
		if ( is_user_logged_in() ) {
			return;
		}
		$user = fsp_rest_app_password_check();
		if ( $user instanceof WP_User ) {
			wp_set_current_user( $user->ID );
		}
	},
	0
);

/* Connection check for Social Studio: says only what arrived, never any credential. */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'framboise-studio/v1',
			'/diag',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function () {
					$check = 'aucun identifiant reçu';
					if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) ) {
						$r     = fsp_rest_app_password_check();
						$check = $r instanceof WP_User ? 'ok' : ( is_wp_error( $r ) ? $r->get_error_code() : 'non vérifié' );
					}
					return array(
						'version'          => '1.2.0',
						'entete_standard'  => ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) || ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ),
						'entete_framboise' => ! empty( $_SERVER['HTTP_X_FRAMBOISE_AUTH'] ),
						'identifiant_lu'   => ! empty( $_SERVER['PHP_AUTH_USER'] ),
						'mdp_app_actifs'   => function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available(),
						'mdp_app_utilises' => class_exists( 'WP_Application_Passwords' ) && WP_Application_Passwords::is_in_use(),
						'connecte'         => is_user_logged_in(),
						'verification'     => $check,
					);
				},
			)
		);
	}
);

/* Exposure flags: the unified "public" flag, plus the MCP-specific one used by earlier MCP Adapter versions. */
function fsp_meta( $readonly, $destructive = false, $idempotent = false ) {
	return array(
		'public'      => true,
		'mcp'         => array( 'public' => true ),
		'annotations' => array(
			'readonly'    => $readonly,
			'destructive' => $destructive,
			'idempotent'  => $idempotent,
		),
	);
}

add_action(
	'wp_abilities_api_categories_init',
	function () {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'framboise-studio',
			array(
				'label'       => 'Framboise Studio',
				'description' => 'Publication d\'articles depuis Social Studio : catégories, articles, images et champs SEO.',
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	function () {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		/* ---------- read: categories ---------- */
		wp_register_ability(
			'framboise-studio/list-categories',
			array(
				'label'               => 'Lister les catégories',
				'description'         => 'Renvoie toutes les catégories d\'articles du site (id, nom, slug, nombre d\'articles).',
				'category'            => 'framboise-studio',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => new stdClass(),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'array' ),
				'execute_callback'    => function () {
					$terms = get_terms(
						array(
							'taxonomy'   => 'category',
							'hide_empty' => false,
						)
					);
					if ( is_wp_error( $terms ) ) {
						return $terms;
					}
					return array_map(
						function ( $t ) {
							return array(
								'id'    => $t->term_id,
								'name'  => $t->name,
								'slug'  => $t->slug,
								'count' => $t->count,
							);
						},
						$terms
					);
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => fsp_meta( true, false, true ),
				'show_in_rest'        => true,
			)
		);

		/* ---------- read: posts (for internal links and duplicates) ---------- */
		wp_register_ability(
			'framboise-studio/list-posts',
			array(
				'label'               => 'Lister les articles',
				'description'         => 'Renvoie les articles et pages du site (id, titre, lien, date, statut), avec une recherche facultative. Sert aux liens internes et à éviter les doublons.',
				'category'            => 'framboise-studio',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'search'    => array( 'type' => 'string' ),
						'post_type' => array(
							'type' => 'string',
							'enum' => array( 'post', 'page', 'any' ),
						),
						'per_page'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'array' ),
				'execute_callback'    => function ( $input = array() ) {
					$input = (array) $input;
					$type  = isset( $input['post_type'] ) ? $input['post_type'] : 'post';
					$q     = new WP_Query(
						array(
							'post_type'      => 'any' === $type ? array( 'post', 'page' ) : $type,
							'post_status'    => array( 'publish', 'future', 'draft' ),
							's'              => isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '',
							'posts_per_page' => isset( $input['per_page'] ) ? (int) $input['per_page'] : 50,
							'no_found_rows'  => true,
						)
					);
					$out   = array();
					foreach ( $q->posts as $p ) {
						$out[] = array(
							'id'     => $p->ID,
							'title'  => get_the_title( $p ),
							'link'   => get_permalink( $p ),
							'date'   => get_post_time( 'c', true, $p ),
							'status' => $p->post_status,
							'type'   => $p->post_type,
						);
					}
					return $out;
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => fsp_meta( true, false, true ),
				'show_in_rest'        => true,
			)
		);

		/* ---------- write: image into the media library ---------- */
		wp_register_ability(
			'framboise-studio/upload-image',
			array(
				'label'               => 'Envoyer une image',
				'description'         => 'Ajoute une image à la médiathèque, à partir d\'une adresse publique (url) ou de son contenu en base64 (data_base64). Renvoie l\'id et l\'adresse de l\'image.',
				'category'            => 'framboise-studio',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'url'         => array( 'type' => 'string' ),
						'data_base64' => array( 'type' => 'string' ),
						'filename'    => array( 'type' => 'string' ),
						'alt'         => array( 'type' => 'string' ),
						'title'       => array( 'type' => 'string' ),
						'caption'     => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => function ( $input = array() ) {
					$input = (array) $input;
					require_once ABSPATH . 'wp-admin/includes/file.php';
					require_once ABSPATH . 'wp-admin/includes/media.php';
					require_once ABSPATH . 'wp-admin/includes/image.php';

					$name = ! empty( $input['filename'] ) ? sanitize_file_name( $input['filename'] ) : 'image-studio-' . time() . '.jpg';
					if ( ! empty( $input['data_base64'] ) ) {
						$raw = base64_decode( preg_replace( '#^data:[^;]+;base64,#', '', $input['data_base64'] ), true );
						if ( false === $raw || strlen( $raw ) < 100 ) {
							return new WP_Error( 'fsp_bad_image', 'Contenu d\'image invalide.' );
						}
						if ( strlen( $raw ) > 15 * 1024 * 1024 ) {
							return new WP_Error( 'fsp_too_big', 'Image trop lourde (15 Mo maximum).' );
						}
						$tmp = wp_tempnam( $name );
						file_put_contents( $tmp, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					} elseif ( ! empty( $input['url'] ) ) {
						$tmp = download_url( esc_url_raw( $input['url'] ), 60 );
						if ( is_wp_error( $tmp ) ) {
							return $tmp;
						}
					} else {
						return new WP_Error( 'fsp_no_image', 'Indiquez url ou data_base64.' );
					}

					$check = wp_check_filetype_and_ext( $tmp, $name );
					if ( empty( $check['type'] ) || 0 !== strpos( $check['type'], 'image/' ) ) {
						@unlink( $tmp ); // phpcs:ignore
						return new WP_Error( 'fsp_not_image', 'Le fichier n\'est pas une image acceptée.' );
					}

					$id = media_handle_sideload(
						array(
							'name'     => $name,
							'tmp_name' => $tmp,
						),
						0,
						! empty( $input['title'] ) ? sanitize_text_field( $input['title'] ) : null
					);
					if ( is_wp_error( $id ) ) {
						@unlink( $tmp ); // phpcs:ignore
						return $id;
					}
					if ( ! empty( $input['alt'] ) ) {
						update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt'] ) );
					}
					if ( ! empty( $input['caption'] ) ) {
						wp_update_post(
							array(
								'ID'           => $id,
								'post_excerpt' => sanitize_text_field( $input['caption'] ),
							)
						);
					}
					return array(
						'id'  => $id,
						'url' => wp_get_attachment_url( $id ),
					);
				},
				'permission_callback' => function () {
					return current_user_can( 'upload_files' );
				},
				'meta'                => fsp_meta( false ),
				'show_in_rest'        => true,
			)
		);

		/* ---------- write: create or update an article ---------- */
		$post_props = array(
			'title'             => array( 'type' => 'string' ),
			'content'           => array(
				'type'        => 'string',
				'description' => 'Contenu HTML ou blocs Gutenberg.',
			),
			'excerpt'           => array( 'type' => 'string' ),
			'slug'              => array( 'type' => 'string' ),
			'status'            => array(
				'type' => 'string',
				'enum' => array( 'draft', 'pending', 'future', 'publish' ),
			),
			'date'              => array(
				'type'        => 'string',
				'description' => 'Date de publication ISO 8601 (heure de Paris), obligatoire pour le statut future.',
			),
			'category_ids'      => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer' ),
			),
			'tags'              => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'featured_image_id' => array( 'type' => 'integer' ),
			'seo_title'         => array( 'type' => 'string' ),
			'seo_description'   => array( 'type' => 'string' ),
			'focus_keyword'     => array( 'type' => 'string' ),
		);

		$save = function ( $input, $post_id = 0 ) {
			$input  = (array) $input;
			$status = isset( $input['status'] ) ? $input['status'] : 'draft';
			if ( in_array( $status, array( 'publish', 'future' ), true ) && ! current_user_can( 'publish_posts' ) ) {
				return new WP_Error( 'fsp_cannot_publish', 'Ce compte ne peut que créer des brouillons.' );
			}
			$data = array( 'post_type' => 'post' );
			if ( $post_id ) {
				$data['ID'] = $post_id;
			} else {
				$data['post_status'] = $status;
			}
			if ( isset( $input['title'] ) ) {
				$data['post_title'] = wp_strip_all_tags( $input['title'] );
			}
			if ( isset( $input['content'] ) ) {
				$data['post_content'] = wp_kses_post( $input['content'] );
			}
			if ( isset( $input['excerpt'] ) ) {
				$data['post_excerpt'] = sanitize_textarea_field( $input['excerpt'] );
			}
			if ( ! empty( $input['slug'] ) ) {
				$data['post_name'] = sanitize_title( $input['slug'] );
			}
			if ( $post_id && isset( $input['status'] ) ) {
				$data['post_status'] = $status;
			}
			if ( ! empty( $input['date'] ) ) {
				$ts = strtotime( $input['date'] );
				if ( ! $ts ) {
					return new WP_Error( 'fsp_bad_date', 'Date invalide.' );
				}
				$data['post_date']     = wp_date( 'Y-m-d H:i:s', $ts );
				$data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $ts );
				$data['edit_date']     = true;
			} elseif ( 'future' === $status ) {
				return new WP_Error( 'fsp_no_date', 'Une date est nécessaire pour programmer un article.' );
			}
			if ( isset( $input['category_ids'] ) ) {
				$data['post_category'] = array_map( 'intval', (array) $input['category_ids'] );
			}
			$id = $post_id ? wp_update_post( wp_slash( $data ), true ) : wp_insert_post( wp_slash( $data ), true );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			if ( isset( $input['tags'] ) ) {
				wp_set_post_tags( $id, array_map( 'sanitize_text_field', (array) $input['tags'] ), false );
			}
			if ( ! empty( $input['featured_image_id'] ) ) {
				set_post_thumbnail( $id, (int) $input['featured_image_id'] );
			}
			/* Yoast SEO fields (stored even if Yoast is later replaced) */
			if ( isset( $input['seo_title'] ) ) {
				update_post_meta( $id, '_yoast_wpseo_title', sanitize_text_field( $input['seo_title'] ) );
			}
			if ( isset( $input['seo_description'] ) ) {
				update_post_meta( $id, '_yoast_wpseo_metadesc', sanitize_textarea_field( $input['seo_description'] ) );
			}
			if ( isset( $input['focus_keyword'] ) ) {
				update_post_meta( $id, '_yoast_wpseo_focuskw', sanitize_text_field( $input['focus_keyword'] ) );
			}
			return array(
				'id'        => $id,
				'status'    => get_post_status( $id ),
				'link'      => get_permalink( $id ),
				'edit_link' => admin_url( 'post.php?post=' . $id . '&action=edit' ),
				'date'      => get_post_time( 'c', true, $id ),
			);
		};

		wp_register_ability(
			'framboise-studio/create-post',
			array(
				'label'               => 'Créer un article',
				'description'         => 'Crée un article : brouillon (draft), en attente de relecture (pending), programmé (future, avec date) ou publié (publish). Accepte le contenu HTML, l\'extrait, le slug, les catégories, les étiquettes, l\'image à la une et les champs Yoast (titre SEO, méta-description, mot-clé principal).',
				'category'            => 'framboise-studio',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => $post_props,
					'required'             => array( 'title', 'content' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => function ( $input = array() ) use ( $save ) {
					return $save( $input, 0 );
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => fsp_meta( false ),
				'show_in_rest'        => true,
			)
		);

		wp_register_ability(
			'framboise-studio/update-post',
			array(
				'label'               => 'Modifier un article',
				'description'         => 'Modifie un article créé par ce compte ou modifiable par lui : mêmes champs que la création, plus post_id. Ne supprime jamais rien.',
				'category'            => 'framboise-studio',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array_merge( array( 'post_id' => array( 'type' => 'integer' ) ), $post_props ),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => function ( $input = array() ) use ( $save ) {
					$input = (array) $input;
					$pid   = (int) $input['post_id'];
					if ( ! get_post( $pid ) ) {
						return new WP_Error( 'fsp_not_found', 'Article introuvable.' );
					}
					unset( $input['post_id'] );
					return $save( $input, $pid );
				},
				'permission_callback' => function ( $input = array() ) {
					$input = (array) $input;
					return ! empty( $input['post_id'] ) && current_user_can( 'edit_post', (int) $input['post_id'] );
				},
				'meta'                => fsp_meta( false, false, true ),
				'show_in_rest'        => true,
			)
		);
	}
);
