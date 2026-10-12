<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a WS Form submission into rows keyed by the Excel column headers.
 * Field IDs are per form; a rebuilt or duplicated form gets new IDs and must be remapped here.
 */
class Insight_Danel_Mapper {

	const ID_COLUMN = 'מזהה פנייה';

	/** Excel's cell limit is 32,767 characters; longer values are rejected with a 400. */
	const MAX_CELL = 32000;

	const FORMS = array(
		9  => array(
			'label'   => 'טופס כללי',
			'url'     => 153,
			'consent' => 165,
			'fields'  => array(
				'שם מלא'      => 66,
				'טלפון'       => 69,
				'מייל'        => 68,
				'עיר מגורים'  => 70,
				'נושא הפנייה' => 75,
			),
		),
		14 => array(
			'label'   => 'טופס לידים – מאמר',
			'url'     => 150,
			'consent' => 169,
			'fields'  => array(
				'שם מלא' => 143,
				'טלפון'  => 144,
				'מייל'   => 145,
			),
		),
		10 => array(
			'label'   => 'טופס גיוס מטפלת',
			'url'     => 156,
			'consent' => 0,
			'fields'  => array(
				'שם מלא'     => 76,
				'טלפון'      => 77,
				'עיר מגורים' => 80,
			),
		),
		13 => array(
			'label'   => 'מחשבון זכאות',
			'url'     => 162,
			'consent' => 133,
			'fields'  => array(
				'שם מלא' => 139,
				'טלפון'  => 140,
				'מייל'   => 141,
			),
		),
	);

	const ELIGIBILITY_FIELDS = array(
		'מין'              => 101,
		'שנת לידה'         => 102,
		'מגורים'           => 103,
		'ניידות'           => 107,
		'נפילות'           => 108,
		'הלבשה'            => 112,
		'רחצה'             => 113,
		'אכילה ושתייה'     => 117,
		'הפרשות'           => 118,
		'ראייה'            => 119,
		'התמצאות וזיכרון'  => 123,
		'השארות לבד בבית'  => 124,
	);

	const ELIGIBILITY_RESULT = array(
		'pass'   => 'עומד/ת בתנאים',
		'age'    => 'אינו/ה עומד/ת בתנאי הגיל',
		'income' => 'אינו/ה עומד/ת בתנאי ההכנסה',
	);

	public static function handles( $form_id ) {
		return isset( self::FORMS[ (int) $form_id ] );
	}

	/** Returns array( table name => array( header => value ) ). */
	public static function rows( $submit, $uuid ) {
		$form_id = (int) $submit->form_id;
		$cfg     = self::FORMS[ $form_id ];
		// ISO order: Excel reads d/m/Y as US m/d and would swap day and month.
		$date    = wp_date( 'Y-m-d H:i', strtotime( $submit->date_added . ' UTC' ) );

		$lead = array(
			self::ID_COLUMN => $uuid,
			'תאריך ושעה'    => $date,
			'טופס'          => $cfg['label'],
			'עמוד מקור'     => self::source_url( $submit, $cfg ),
		);
		if ( $cfg['consent'] ) {
			$lead['אישור דיוור'] = '' !== self::field( $submit, $cfg['consent'] ) ? 'כן' : 'לא';
		}
		foreach ( $cfg['fields'] as $header => $field_id ) {
			$lead[ $header ] = self::field( $submit, $field_id );
		}
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) as $utm ) {
			$lead[ $utm ] = self::text( self::raw( $submit, 'tracking_' . $utm ) );
		}

		$rows = array();

		if ( 13 === $form_id ) {
			$result                      = self::field( $submit, 104 );
			$lead['תוצאת בדיקת זכאות']   = self::ELIGIBILITY_RESULT[ $result ] ?? $result;

			$elig = array(
				self::ID_COLUMN => $uuid,
				'תאריך ושעה'    => $date,
			);
			foreach ( self::ELIGIBILITY_FIELDS as $header => $field_id ) {
				$elig[ $header ] = self::field( $submit, $field_id );
			}
			$single = self::field( $submit, 129 );
			$couple = self::field( $submit, 130 );
			$elig['הכנסה חודשית'] = '' !== $single ? 'יחיד: ' . $single : ( '' !== $couple ? 'זוג: ' . $couple : '' );

			$rows['tblLeads']       = $lead;
			$rows['tblEligibility'] = $elig;
			return $rows;
		}

		$rows['tblLeads'] = $lead;
		return $rows;
	}

	private static function source_url( $submit, $cfg ) {
		$url = self::field( $submit, $cfg['url'] );
		if ( '' !== $url ) {
			return $url;
		}
		$post_id = (int) self::text( self::raw( $submit, 'post_id' ) );
		$url     = $post_id ? get_permalink( $post_id ) : '';
		return $url ? $url : '';
	}

	private static function field( $submit, $field_id ) {
		return self::text( self::raw( $submit, 'field_' . (int) $field_id ) );
	}

	private static function raw( $submit, $key ) {
		$value = wsf_submit_get_value( $submit, $key, '' );
		if ( '' === $value && isset( $submit->meta[ $key ] ) ) {
			$value = is_array( $submit->meta[ $key ] ) && array_key_exists( 'value', $submit->meta[ $key ] ) ? $submit->meta[ $key ]['value'] : $submit->meta[ $key ];
		}
		return $value;
	}

	private static function text( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			$value = json_decode( wp_json_encode( $value ), true );
			$flat  = array();
			array_walk_recursive(
				$value,
				function ( $v ) use ( &$flat ) {
					if ( is_scalar( $v ) && '' !== (string) $v ) {
						$flat[] = (string) $v;
					}
				}
			);
			$value = implode( ', ', $flat );
		}
		$value = trim( html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, 'UTF-8' ) );
		$value = mb_substr( $value, 0, self::MAX_CELL );

		// Excel would run "=..." (and "+ - @" starts) as a formula. A leading apostrophe makes it
		// literal text and is not stored. The columns are also text-formatted; this covers any cell that isn't.
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			$value = "'" . $value;
		}
		return $value;
	}
}
