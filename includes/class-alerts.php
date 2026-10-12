<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Throttled email alerts. Alerts never contain lead data — only lead IDs, form names and error codes.
 */
class Insight_Danel_Alerts {

	const OPTION = 'insight_danel_leads_alerts';

	public static function recipient() {
		return defined( 'DANEL_LEADS_ALERT_EMAIL' ) && is_email( DANEL_LEADS_ALERT_EMAIL ) ? DANEL_LEADS_ALERT_EMAIL : get_option( 'admin_email' );
	}

	/** Sends at most once per $throttle seconds for the same $key. */
	public static function send( $key, $subject, array $lines, $throttle = 6 * HOUR_IN_SECONDS ) {
		$sent = get_option( self::OPTION, array() );
		if ( $throttle > 0 && isset( $sent[ $key ] ) && $sent[ $key ] > time() - $throttle ) {
			return;
		}
		if ( $throttle > 0 ) {
			$sent[ $key ] = time();
			update_option( self::OPTION, $sent, false );
		}

		$lines[] = '';
		$lines[] = 'Status page: ' . admin_url( 'tools.php?page=insight-danel-leads' );
		wp_mail( self::recipient(), '[' . wp_parse_url( home_url(), PHP_URL_HOST ) . '] ' . $subject, implode( "\n", $lines ) );
	}

	/** Warns 60, 30 and 7 days before the Graph client secret expires. */
	public static function check_secret_expiry() {
		$expires = strtotime( DANEL_GRAPH_SECRET_EXPIRES . ' 00:00:00 UTC' );
		if ( ! $expires ) {
			return;
		}
		$days  = (int) floor( ( $expires - time() ) / DAY_IN_SECONDS );
		$lines = array(
			'The client secret of the "Danel-Siud-External-Site" app registration expires on ' . DANEL_GRAPH_SECRET_EXPIRES . '.',
			'When it expires, leads stop reaching the Excel file.',
			'Danel IT must create a new secret, and the new value must be placed on the server.',
		);
		if ( $days < 0 ) {
			self::send( 'secret_expired', 'Graph client secret has EXPIRED', $lines, DAY_IN_SECONDS );
			return;
		}
		foreach ( array( 7, 30, 60 ) as $threshold ) {
			if ( $days <= $threshold ) {
				self::send( 'secret_expiry_' . $threshold, "Graph client secret expires in $days days", $lines, 400 * DAY_IN_SECONDS );
				return;
			}
		}
	}
}
