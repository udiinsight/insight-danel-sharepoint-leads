<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal Microsoft Graph client for the Datafromsite workbook (app-only, sessionless).
 * Never logs or returns the secret or the access token.
 */
class Insight_Danel_Graph {

	const TOKEN_TRANSIENT = 'insight_danel_graph_token';

	public static function is_configured() {
		foreach ( array( 'DANEL_GRAPH_TENANT_ID', 'DANEL_GRAPH_CLIENT_ID', 'DANEL_GRAPH_CLIENT_SECRET', 'DANEL_GRAPH_DRIVE_ID', 'DANEL_GRAPH_ITEM_ID' ) as $c ) {
			if ( ! defined( $c ) || '' === constant( $c ) ) {
				return false;
			}
		}
		return true;
	}

	private function token( $force = false ) {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( ! $force && is_array( $cached ) && ! empty( $cached['token'] ) && $cached['expires_at'] > time() + 300 ) {
			return $cached['token'];
		}

		$r = wp_remote_post(
			'https://login.microsoftonline.com/' . rawurlencode( DANEL_GRAPH_TENANT_ID ) . '/oauth2/v2.0/token',
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type'    => 'client_credentials',
					'client_id'     => DANEL_GRAPH_CLIENT_ID,
					'client_secret' => DANEL_GRAPH_CLIENT_SECRET,
					'scope'         => 'https://graph.microsoft.com/.default',
				),
			)
		);
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'token_transport', $r->get_error_message() );
		}
		$data = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $r ) || empty( $data['access_token'] ) ) {
			$desc = strtok( (string) ( $data['error_description'] ?? '' ), "\r\n" );
			return new WP_Error( 'token_' . ( $data['error'] ?? 'http_' . wp_remote_retrieve_response_code( $r ) ), $desc );
		}

		$expires_in = (int) ( $data['expires_in'] ?? 3599 );
		set_transient(
			self::TOKEN_TRANSIENT,
			array(
				'token'      => $data['access_token'],
				'expires_at' => time() + $expires_in,
			),
			max( 60, $expires_in - 300 )
		);
		return $data['access_token'];
	}

	/**
	 * Calls a path relative to the workbook. Returns code (0 = transport failure),
	 * data, and a "code/innerCode: message" error string.
	 */
	public function request( $method, $path, $body = null ) {
		$url        = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode( DANEL_GRAPH_DRIVE_ID ) . '/items/' . rawurlencode( DANEL_GRAPH_ITEM_ID ) . '/workbook' . $path;
		$idempotent = 'POST' !== $method && 'DELETE' !== $method;
		$refreshed  = false;

		for ( $try = 1; $try <= 3; $try++ ) {
			$token = $this->token( $refreshed );
			if ( is_wp_error( $token ) ) {
				return array(
					'code'  => 0,
					'data'  => null,
					'error' => $token->get_error_code() . ': ' . $token->get_error_message(),
				);
			}

			$r = wp_remote_request(
				$url,
				array(
					'method'      => $method,
					'timeout'     => 30,
					'redirection' => 0,
					'httpversion' => '1.1',
					'headers'     => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
						'Accept'        => 'application/json',
					),
					'body'        => null === $body ? null : wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
				)
			);

			$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
			$data = is_wp_error( $r ) ? null : json_decode( wp_remote_retrieve_body( $r ), true );

			if ( 401 === $code && ! $refreshed ) {
				$refreshed = true;
				--$try;
				continue;
			}
			if ( ! $idempotent || ! in_array( $code, array( 0, 429, 502, 503, 504 ), true ) || 3 === $try ) {
				break;
			}
			$wait = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_header( $r, 'retry-after' );
			sleep( min( 30, $wait > 0 ? $wait : 2 * $try ) );
		}

		$error = '';
		if ( 0 === $code ) {
			$error = 'transport: ' . $r->get_error_message();
		} elseif ( $code >= 300 ) {
			$inner = $data['error']['innerError']['code'] ?? '';
			$error = 'http_' . $code . ' ' . ( $data['error']['code'] ?? '' ) . ( $inner ? '/' . $inner : '' ) . ': ' . mb_substr( (string) ( $data['error']['message'] ?? '' ), 0, 150 );
		}

		return array(
			'code'  => $code,
			'data'  => $data,
			'error' => $error,
		);
	}

	public static function is_ok( $res ) {
		return $res['code'] >= 200 && $res['code'] < 300;
	}

	/** A human has the workbook locked (open in Excel desktop, checked out). Do not hammer it. */
	public static function is_locked( $res ) {
		return in_array( $res['code'], array( 409, 423 ), true ) || false !== stripos( $res['error'], 'accessConflict' ) || false !== stripos( $res['error'], 'resourceLocked' );
	}

	/** Permission, auth, addressing or file-structure problem that needs a human to fix. 422 = Excel columns missing. */
	public static function is_config_error( $res ) {
		$token_error = 0 === strpos( $res['error'], 'token_' ) && 0 !== strpos( $res['error'], 'token_transport' );
		return in_array( $res['code'], array( 401, 403, 404, 422 ), true ) || $token_error;
	}

	/** Microsoft rejected the request content (bad value, filtered table, protected sheet). */
	public static function is_data_error( $res ) {
		return 400 === $res['code'];
	}
}
