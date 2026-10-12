<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Durable lead queue. The WS Form submission is the only copy of the lead's data;
 * this table holds references and status only (no personal data).
 *
 * Flow per lead: enqueue on submit -> background worker (single writer) -> for each Excel table:
 * check the lead ID is not already there -> write -> read back until the ID is found exactly once
 * -> only then delete the submission. Undelivered leads are deleted after MAX_AGE (agreed with Danel).
 */
class Insight_Danel_Queue {

	const DB_VERSION   = 1;
	const RUN_HOOK     = 'insight_danel_leads_process';
	const CRON_HOOK    = 'insight_danel_leads_cron';
	const MAX_AGE      = 48 * HOUR_IN_SECONDS;
	const BATCH        = 20;
	const TIME_BUDGET  = 45;
	const VERIFY_WAITS = array( 2, 3, 5 );

	private static $graph;
	private static $columns = array();

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'insight_danel_leads';
	}

	public static function init() {
		add_action( 'wsf_submit_post_complete', array( __CLASS__, 'enqueue' ) );
		add_action( self::RUN_HOOK, array( __CLASS__, 'process' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );

		if ( (int) get_option( 'insight_danel_leads_db', 0 ) < self::DB_VERSION ) {
			self::install();
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'insight_danel_5min', self::CRON_HOOK );
		}
	}

	public static function schedules( $schedules ) {
		$schedules['insight_danel_5min'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'Every 5 minutes (Danel leads)',
		);
		return $schedules;
	}

	public static function activate() {
		self::install();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::RUN_HOOK );
	}

	private static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				submit_id bigint(20) unsigned NOT NULL,
				form_id int(11) unsigned NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				next_attempt_at datetime NOT NULL,
				last_error varchar(255) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				done_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				KEY status_next (status,next_attempt_at)
			) $charset;"
		);
		update_option( 'insight_danel_leads_db', self::DB_VERSION, false );
	}

	/** Runs inside the visitor's form submission request — keep it fast. Never calls Microsoft here. */
	public static function enqueue( $submit ) {
		if ( empty( $submit->id ) || ! Insight_Danel_Mapper::handles( $submit->form_id ) ) {
			return;
		}
		if ( isset( $submit->post_mode ) && 'submit' !== $submit->post_mode ) {
			return;
		}
		if ( isset( $submit->status ) && 'spam' === $submit->status ) {
			return;
		}

		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			self::table(),
			array(
				'uuid'            => wp_generate_uuid4(),
				'submit_id'       => (int) $submit->id,
				'form_id'         => (int) $submit->form_id,
				'status'          => 'pending',
				'next_attempt_at' => $now,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		if ( ! wp_next_scheduled( self::RUN_HOOK ) ) {
			wp_schedule_single_event( time(), self::RUN_HOOK );
		}
		spawn_cron();
	}

	public static function cron() {
		self::process();
		self::expire_old();
		Insight_Danel_Alerts::check_secret_expiry();
	}

	/** Single writer: Microsoft does not guarantee correct results for parallel writes to one workbook. */
	public static function process() {
		global $wpdb;
		if ( ! Insight_Danel_Graph::is_configured() ) {
			Insight_Danel_Alerts::send( 'not_configured', 'Leads integration is not configured', array( 'One or more DANEL_GRAPH_* constants are missing on the server.' ) );
			return array( 'processed' => 0 );
		}
		if ( '1' !== (string) $wpdb->get_var( "SELECT GET_LOCK('insight_danel_leads', 0)" ) ) {
			return array( 'processed' => 0, 'locked' => true );
		}

		$started   = time();
		$processed = 0;
		try {
			// Re-query until nothing is due: leads that arrive while this run holds the lock
			// would otherwise wait for the next cron tick.
			do {
				$items = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT * FROM ' . self::table() . " WHERE status IN ('pending','blocked') AND next_attempt_at <= %s ORDER BY id ASC LIMIT %d",
						current_time( 'mysql', true ),
						self::BATCH
					)
				);
				foreach ( $items as $item ) {
					if ( time() - $started > self::TIME_BUDGET ) {
						break 2;
					}
					self::process_item( $item );
					++$processed;
				}
			} while ( $items );
		} finally {
			$wpdb->query( "SELECT RELEASE_LOCK('insight_danel_leads')" );
		}

		if ( time() - $started > self::TIME_BUDGET && ! wp_next_scheduled( self::RUN_HOOK ) ) {
			wp_schedule_single_event( time(), self::RUN_HOOK );
		}
		return array( 'processed' => $processed );
	}

	private static function process_item( $item ) {
		$submit = self::load_submit( (int) $item->submit_id );
		if ( ! $submit ) {
			self::update( $item, 'failed', 'WS Form submission no longer exists' );
			Insight_Danel_Alerts::send( 'missing_' . $item->uuid, 'Lead could not be sent: submission missing', array( 'Lead ID: ' . $item->uuid, 'Form: ' . $item->form_id, 'The submission was deleted from the site before it reached the Excel file.' ), 0 );
			return;
		}

		foreach ( Insight_Danel_Mapper::rows( $submit, $item->uuid ) as $table => $row ) {
			$result = self::deliver( $table, $item->uuid, $row );
			if ( true !== $result ) {
				self::fail( $item, $table, $result );
				return;
			}
		}

		try {
			$submit->db_delete( true, true, true );
		} catch ( Exception $e ) {
			self::update( $item, 'pending', 'delivered but local delete failed: ' . $e->getMessage(), 10 * MINUTE_IN_SECONDS );
			return;
		}
		self::update( $item, 'done', '' );
	}

	private static function load_submit( $submit_id ) {
		try {
			$submit = wsf_submit_get_object( $submit_id );
			return empty( $submit->id ) ? null : $submit;
		} catch ( Exception $e ) {
			return null;
		}
	}

	/** Returns true once the lead ID is in the table exactly once, otherwise the failed response. */
	private static function deliver( $table, $uuid, array $row ) {
		$found = self::find( $table, $uuid );
		if ( is_array( $found ) && isset( $found['code'] ) ) {
			return $found;
		}
		if ( count( $found ) > 0 ) {
			return self::dedupe( $table, $found );
		}

		$headers = self::headers( $table );
		if ( isset( $headers['code'] ) ) {
			return $headers;
		}
		$values = array();
		foreach ( $headers as $header ) {
			$values[] = isset( $row[ $header ] ) ? (string) $row[ $header ] : '';
		}

		$write = self::write( $table, $values );
		if ( Insight_Danel_Graph::is_locked( $write ) || Insight_Danel_Graph::is_config_error( $write ) ) {
			return $write;
		}

		// The write response is not trusted either way: a timeout can follow a committed row,
		// and a success has been reported for a row that never landed. Only the read-back decides.
		foreach ( self::VERIFY_WAITS as $wait ) {
			sleep( $wait );
			$found = self::find( $table, $uuid );
			if ( is_array( $found ) && isset( $found['code'] ) ) {
				continue;
			}
			if ( count( $found ) > 0 ) {
				return self::dedupe( $table, $found );
			}
		}
		return array(
			'code'  => $write['code'],
			'data'  => null,
			'error' => 'not found after write' . ( $write['error'] ? ' (' . $write['error'] . ')' : '' ),
		);
	}

	/** Fills the empty row Excel creates with a new table; otherwise appends. */
	private static function write( $table, array $values ) {
		$graph = self::graph();
		$body  = $graph->request( 'GET', '/tables/' . rawurlencode( $table ) . '/dataBodyRange?$select=rowCount,values' );
		if ( Insight_Danel_Graph::is_ok( $body ) && 1 === (int) ( $body['data']['rowCount'] ?? 0 ) ) {
			$only = $body['data']['values'][0] ?? array();
			if ( '' === implode( '', array_map( 'strval', $only ) ) ) {
				return $graph->request( 'PATCH', '/tables/' . rawurlencode( $table ) . '/dataBodyRange', array( 'values' => array( $values ) ) );
			}
		}
		return $graph->request( 'POST', '/tables/' . rawurlencode( $table ) . '/rows', array( 'values' => array( $values ) ) );
	}

	/** Returns the zero-based data-row indexes holding $uuid, or the failed response. */
	private static function find( $table, $uuid ) {
		$col = self::id_column( $table );
		if ( is_array( $col ) ) {
			return $col;
		}
		$res = self::graph()->request( 'GET', '/tables/' . rawurlencode( $table ) . '/columns/' . rawurlencode( $col ) . '?$select=values' );
		if ( ! Insight_Danel_Graph::is_ok( $res ) ) {
			return $res;
		}
		$indexes = array();
		foreach ( array_slice( $res['data']['values'] ?? array(), 1 ) as $i => $cell ) {
			if ( isset( $cell[0] ) && (string) $cell[0] === $uuid ) {
				$indexes[] = $i;
			}
		}
		return $indexes;
	}

	/** Keeps the first copy, deletes extra copies bottom-up so indexes stay valid. */
	private static function dedupe( $table, array $indexes ) {
		if ( count( $indexes ) < 2 ) {
			return true;
		}
		rsort( $indexes );
		array_pop( $indexes );
		foreach ( $indexes as $index ) {
			self::graph()->request( 'DELETE', '/tables/' . rawurlencode( $table ) . '/rows/$/itemAt(index=' . (int) $index . ')' );
		}
		Insight_Danel_Alerts::send( 'dedupe', 'Duplicate rows removed in ' . $table, array( count( $indexes ) . ' duplicate row(s) were detected and removed automatically. No action needed.' ), DAY_IN_SECONDS );
		return true;
	}

	private static function headers( $table ) {
		if ( ! isset( self::$columns[ $table ] ) ) {
			$res = self::graph()->request( 'GET', '/tables/' . rawurlencode( $table ) . '/columns?$select=id,name' );
			if ( ! Insight_Danel_Graph::is_ok( $res ) ) {
				return $res;
			}
			self::$columns[ $table ] = array_column( $res['data']['value'] ?? array(), 'name', 'id' );
		}
		return array_values( self::$columns[ $table ] );
	}

	private static function id_column( $table ) {
		$headers = self::headers( $table );
		if ( isset( $headers['code'] ) ) {
			return $headers;
		}
		$id = array_search( Insight_Danel_Mapper::ID_COLUMN, self::$columns[ $table ], true );
		if ( false === $id ) {
			return array(
				'code'  => 400,
				'data'  => null,
				'error' => 'column "' . Insight_Danel_Mapper::ID_COLUMN . '" not found in ' . $table,
			);
		}
		return (string) $id;
	}

	private static function fail( $item, $table, $res ) {
		$attempts = (int) $item->attempts + 1;
		$error    = mb_substr( $table . ': ' . $res['error'], 0, 250 );
		$age      = time() - strtotime( $item->created_at . ' UTC' );

		if ( Insight_Danel_Graph::is_locked( $res ) ) {
			self::update( $item, 'blocked', $error, 10 * MINUTE_IN_SECONDS, $attempts );
			if ( $age > HOUR_IN_SECONDS ) {
				Insight_Danel_Alerts::send( 'blocked', 'Excel file is locked — leads are waiting', array( 'The Datafromsite file is locked (probably open in Excel desktop or checked out).', 'Leads are queued and will be sent automatically once it is released.', 'Oldest waiting lead ID: ' . $item->uuid ) );
			}
			return;
		}

		$delay = min( HOUR_IN_SECONDS, MINUTE_IN_SECONDS * ( 2 ** min( 6, $attempts - 1 ) ) ) + wp_rand( 0, 30 );
		self::update( $item, 'pending', $error, $delay, $attempts );

		if ( Insight_Danel_Graph::is_config_error( $res ) ) {
			Insight_Danel_Alerts::send( 'config', 'Leads cannot reach the Excel file — access problem', array( 'Microsoft rejected the request. This usually means a permission, secret or file change on Danel\'s side.', 'Error: ' . $error, 'Leads are kept for up to 48 hours and retried automatically.' ) );
		} elseif ( $attempts >= 3 && $age > 30 * MINUTE_IN_SECONDS ) {
			Insight_Danel_Alerts::send( 'failing', 'Leads are failing to reach the Excel file', array( 'Error: ' . $error, 'Lead ID: ' . $item->uuid . ' (attempt ' . $attempts . ')', 'Leads are kept for up to 48 hours and retried automatically.' ) );
		}
	}

	/** Danel agreed: undelivered leads are deleted after 48 hours, with an alert. */
	public static function expire_old() {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::MAX_AGE );
		$items  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE status IN ('pending','blocked') AND created_at < %s", $cutoff ) );
		if ( ! $items || '1' !== (string) $wpdb->get_var( "SELECT GET_LOCK('insight_danel_leads', 0)" ) ) {
			return;
		}
		$ids = array();
		foreach ( $items as $item ) {
			$submit = self::load_submit( (int) $item->submit_id );
			if ( $submit ) {
				try {
					$submit->db_delete( true, true, true );
				} catch ( Exception $e ) {
					continue;
				}
			}
			self::update( $item, 'expired', $item->last_error );
			$ids[] = $item->uuid . ' (' . ( Insight_Danel_Mapper::FORMS[ (int) $item->form_id ]['label'] ?? $item->form_id ) . ')';
		}
		$wpdb->query( "SELECT RELEASE_LOCK('insight_danel_leads')" );
		if ( ! $ids ) {
			return;
		}
		Insight_Danel_Alerts::send( 'expired_' . md5( implode( ',', $ids ) ), count( $ids ) . ' lead(s) deleted after 48 hours without reaching Excel', array_merge( array( 'These leads could not be delivered within 48 hours and were deleted from the site:' ), $ids ), 0 );
	}

	private static function update( $item, $status, $error, $delay = 0, $attempts = null ) {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$data = array(
			'status'          => $status,
			'last_error'      => mb_substr( (string) $error, 0, 255 ),
			'updated_at'      => $now,
			'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
		);
		if ( null !== $attempts ) {
			$data['attempts'] = $attempts;
		}
		if ( in_array( $status, array( 'done', 'expired', 'failed' ), true ) ) {
			$data['done_at'] = $now;
		}
		$wpdb->update( self::table(), $data, array( 'id' => (int) $item->id ) );
	}

	private static function graph() {
		if ( ! self::$graph ) {
			self::$graph = new Insight_Danel_Graph();
		}
		return self::$graph;
	}
}
