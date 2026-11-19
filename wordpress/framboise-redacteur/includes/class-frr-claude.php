<?php
/**
 * Calls to the Anthropic Messages API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FRR_Claude {

	const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	/** Approximate list prices in USD per million tokens, used only for the usage estimate shown in Réglages. */
	const PRICES = array(
		'claude-sonnet-5-5' => array( 2, 10 ),
		'claude-opus-5-5'   => array( 4, 20 ),
		'claude-haiku-5-5'  => array( 0.1, 0.5 ),
	);

	public static function models() {
		return array(
			'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (recommandé)',
			'claude-opus-5-5'   => 'Claude Opus 5.5 (qualité max., 2 fois plus cher)',
			'claude-haiku-5-5'  => 'Claude Haiku 5.5 (très économique)',
		);
	}

	/**
	 * Sends one request. Returns array( 'text' => string, 'usage' => array ) or WP_Error.
	 *
	 * @param string $system     System prompt.
	 * @param string $user       User message.
	 * @param int    $max_tokens Output budget.
	 * @param bool   $web_search Allow the server-side web search tool.
	 */
	public static function ask( $system, $user, $max_tokens = 4000, $web_search = false ) {
		$s   = FRR_Admin::settings();
		$key = trim( (string) $s['api_key'] );
		if ( '' === $key ) {
			return new WP_Error( 'frr_no_key', 'Aucune clé API Anthropic n\'est enregistrée. Ajoutez-la dans Rédacteur IA → Réglages.' );
		}
		$model = isset( self::models()[ $s['model'] ] ) ? $s['model'] : 'claude-sonnet-5-5';

		$body = array(
			'model'      => $model,
			'max_tokens' => (int) $max_tokens,
			'system'     => $system,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
		);
		if ( $web_search ) {
			$body['tools'] = array(
				array(
					'type'     => 'web_search_20250305',
					'name'     => 'web_search',
					'max_uses' => 3,
				),
			);
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$res = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 240,
				'headers' => array(
					'x-api-key'         => $key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'frr_network', 'Impossible de joindre Anthropic : ' . $res->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( 200 !== $code ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'réponse inattendue';
			// The web search tool may be unavailable for this account: retry once without it.
			if ( $web_search && 400 === $code && false !== stripos( $msg, 'tool' ) ) {
				return self::ask( $system, $user, $max_tokens, false );
			}
			return new WP_Error( 'frr_api_' . $code, self::explain( $code, $msg ) );
		}

		$text = '';
		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
				$text .= $block['text'];
			}
		}
		$usage = array(
			'input'  => (int) ( $data['usage']['input_tokens'] ?? 0 ),
			'output' => (int) ( $data['usage']['output_tokens'] ?? 0 ),
		);
		self::record_usage( $model, $usage );

		if ( '' === trim( $text ) ) {
			return new WP_Error( 'frr_empty', 'Claude n\'a renvoyé aucun texte. Réessayez.' );
		}
		return array(
			'text'      => $text,
			'usage'     => $usage,
			'truncated' => isset( $data['stop_reason'] ) && 'max_tokens' === $data['stop_reason'],
		);
	}

	private static function explain( $code, $msg ) {
		if ( 401 === $code ) {
			return 'Clé API refusée par Anthropic. Vérifiez-la dans Réglages.';
		}
		if ( 400 === $code && false !== stripos( $msg, 'credit' ) ) {
			return 'Crédits Anthropic insuffisants. Rechargez votre compte sur console.anthropic.com.';
		}
		if ( 429 === $code ) {
			return 'Trop de demandes en même temps chez Anthropic. Patientez une minute puis réessayez.';
		}
		if ( 529 === $code || 503 === $code ) {
			return 'Les serveurs d\'Anthropic sont surchargés. Réessayez dans quelques minutes.';
		}
		return 'Erreur Anthropic (' . $code . ') : ' . $msg;
	}

	private static function record_usage( $model, $usage ) {
		$all = get_option( 'frr_usage', array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$m = isset( $all[ $model ] ) ? $all[ $model ] : array(
			'input'    => 0,
			'output'   => 0,
			'requests' => 0,
		);
		$m['input']    += $usage['input'];
		$m['output']   += $usage['output'];
		$m['requests'] += 1;
		$all[ $model ]  = $m;
		update_option( 'frr_usage', $all, false );
	}

	/** Estimated spend in USD from recorded usage. */
	public static function estimated_cost() {
		$total = 0.0;
		foreach ( (array) get_option( 'frr_usage', array() ) as $model => $u ) {
			$p      = isset( self::PRICES[ $model ] ) ? self::PRICES[ $model ] : self::PRICES['claude-sonnet-5-5'];
			$total += ( $u['input'] * $p[0] + $u['output'] * $p[1] ) / 1000000;
		}
		return $total;
	}

	/** Pulls the first JSON object out of a model answer. */
	public static function json( $text ) {
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}
		$data = json_decode( substr( $text, $start, $end - $start + 1 ), true );
		return is_array( $data ) ? $data : null;
	}
}
