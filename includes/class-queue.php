<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Durable lead queue. The WS Form submission is the only copy of the lead's data;
 * this table holds references and status only (no personal data).
 *
 * Flow per lead: enqueue on submit -> background worker (single writer) -> for each Excel table:
 * check the lead ID is not already there -> write -> read back until the ID is found
 * -> only then delete the submission. Leads that keep failing are deleted after MAX_AGE (agreed with Danel).
 */
class Insight_Danel_Queue {

	const DB_VERSION      = 2;
	const RUN_HOOK        = 'insight_danel_leads_process';
	const CRON_HOOK       = 'insight_danel_leads_cron';
	const LOCK            = 'insight_danel_leads';
	const OPT_SINCE       = 'insight_danel_leads_since';
	const OPT_WRITTEN     = 'insight_danel_leads_written';
	const MAX_AGE         = 48 * HOUR_IN_SECONDS;
	const MIN_ATTEMPTS    = 3;
	const STALL_AFTER     = 30 * MINUTE_IN_SECONDS;
	const ORPHAN_AFTER    = 15 * MINUTE_IN_SECONDS;
	const AMBIGUOUS_RETRY = 5 * MINUTE_IN_SECONDS;
	const BATCH           = 20;
	const TIME_BUDGET     = 45;
	const VERIFY_WAITS    = array( 2, 3, 5 );

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
				UNIQUE KEY submit_id (submit_id),
				KEY status_next (status,next_attempt_at)
			) $charset;"
		);
		// Orphan sweep only looks at submissions from this point on, never at older ones.
		add_option( self::OPT_SINCE, gmdate( 'Y-m-d H:i:s' ), '', false );
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

		if ( false === self::insert( (int) $submit->id, (int) $submit->form_id ) ) {
			Insight_Danel_Alerts::send( 'enqueue_failed', 'A lead could not be queued', array( 'Submission ID ' . (int) $submit->id . ' could not be added to the queue (database error).', 'The orphan sweep will retry it within 15 minutes.' ) );
		}

		if ( ! wp_next_scheduled( self::RUN_HOOK ) ) {
			wp_schedule_single_event( time(), self::RUN_HOOK );
		}
		spawn_cron();
		self::check_stalled();
	}

	/** INSERT IGNORE: a second hook firing for the same submission is a no-op. Returns false on DB error. */
	private static function insert( $submit_id, $form_id ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		return $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . self::table() . " (uuid, submit_id, form_id, status, next_attempt_at, created_at, updated_at) VALUES (%s, %d, %d, 'pending', %s, %s, %s)",
				wp_generate_uuid4(),
				$submit_id,
				$form_id,
				$now,
				$now,
				$now
			)
		);
	}

	public static function cron() {
		self::process();
		self::sweep_orphans();
		self::expire_old();
		self::check_stalled();
		Insight_Danel_Alerts::check_secret_expiry();
	}

	private static function lock() {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::LOCK ) );
	}

	/** GET_LOCK is per DB connection; a silent reconnect would drop it. */
	private static function holds_lock() {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', self::LOCK ) );
	}

	private static function unlock() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK ) );
	}

	/** Single writer: Microsoft does not guarantee correct results for parallel writes to one workbook. */
	public static function process() {
		global $wpdb;
		if ( ! Insight_Danel_Graph::is_configured() ) {
			Insight_Danel_Alerts::send( 'not_configured', 'Leads integration is not configured', array( 'One or more DANEL_GRAPH_* constants are missing on the server.' ) );
			return array( 'processed' => 0 );
		}
		if ( ! self::lock() ) {
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
					if ( ! self::holds_lock() ) {
						Insight_Danel_Alerts::send( 'lock_lost', 'Leads worker lost its lock and stopped', array( 'The database connection was reset during a run. The run stopped to avoid two writers; the next run continues.' ), DAY_IN_SECONDS );
						break 2;
					}
					self::process_item( $item );
					++$processed;
				}
			} while ( $items );
		} finally {
			self::unlock();
		}

		if ( time() - $started > self::TIME_BUDGET && ! wp_next_scheduled( self::RUN_HOOK ) ) {
			wp_schedule_single_event( time(), self::RUN_HOOK );
		}
		return array( 'processed' => $processed );
	}

	private static function process_item( $item ) {
		$submit = self::load_submit( (int) $item->submit_id );

		if ( 'error' === $submit ) {
			self::update( $item, 'pending', 'could not read the WS Form submission (will retry)', 5 * MINUTE_IN_SECONDS, (int) $item->attempts + 1 );
			return;
		}
		if ( 'missing' === $submit ) {
			// A run killed between delete and status update leaves a delivered lead looking "missing".
			if ( true === self::delivered_everywhere( $item ) ) {
				self::update( $item, 'done', '' );
				return;
			}
			self::update( $item, 'failed', 'WS Form submission no longer exists' );
			Insight_Danel_Alerts::send( 'missing_' . $item->uuid, 'Lead could not be sent: submission missing', array( 'Lead ID: ' . $item->uuid, 'Form: ' . $item->form_id, 'The submission was removed from the site before it reached the Excel file.' ), 0 );
			return;
		}

		foreach ( Insight_Danel_Mapper::rows( $submit, $item->uuid ) as $table => $row ) {
			$result = self::deliver( $table, $item->uuid, $row );
			if ( true !== $result ) {
				self::fail( $item, $table, $result );
				return;
			}
		}

		if ( ! self::delete_submit( $submit ) ) {
			self::update( $item, 'pending', 'delivered, but deleting the lead from the site failed (will retry)', 10 * MINUTE_IN_SECONDS );
			return;
		}
		self::update( $item, 'done', '' );
	}

	/** Returns the submission, 'missing' (row is gone) or 'error' (could not be read — retry). */
	private static function load_submit( $submit_id ) {
		global $wpdb;
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wsf_submit WHERE id = %d", $submit_id ) );
		if ( null === $exists ) {
			return '' !== $wpdb->last_error ? 'error' : 'missing';
		}
		try {
			$submit = wsf_submit_get_object( $submit_id );
			return empty( $submit->id ) ? 'error' : $submit;
		} catch ( Exception $e ) {
			return 'error';
		}
	}

	private static function delete_submit( $submit ) {
		global $wpdb;
		try {
			$submit->db_delete( true, true, true );
		} catch ( Exception $e ) {
			return false;
		}
		return null === $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wsf_submit WHERE id = %d", (int) $submit->id ) );
	}

	private static function tables_for( $form_id ) {
		return 13 === (int) $form_id ? array( 'tblLeads', 'tblEligibility' ) : array( 'tblLeads' );
	}

	/** True if the lead ID is in every table it belongs in; the failed response if Excel could not be read. */
	private static function delivered_everywhere( $item ) {
		foreach ( self::tables_for( $item->form_id ) as $table ) {
			$count = self::count_id( $table, $item->uuid );
			if ( ! is_int( $count ) ) {
				return $count;
			}
			if ( 0 === $count ) {
				return false;
			}
		}
		return true;
	}

	/** Returns true once the lead ID is in the table, otherwise the failed response. */
	private static function deliver( $table, $uuid, array $row ) {
		$headers = self::headers( $table );
		if ( isset( $headers['code'] ) ) {
			return $headers;
		}
		// A renamed or deleted column would otherwise drop data silently, and the lead would still be deleted.
		$missing = array_diff( array_keys( $row ), $headers );
		if ( $missing ) {
			return array(
				'code'  => 422,
				'data'  => null,
				'error' => 'columns missing in ' . $table . ': ' . implode( ', ', $missing ),
			);
		}

		$count = self::count_id( $table, $uuid );
		if ( ! is_int( $count ) ) {
			return $count;
		}
		if ( $count > 0 ) {
			self::note_duplicates( $table, $uuid, $count );
			return true;
		}

		$values = array();
		foreach ( $headers as $header ) {
			$values[] = isset( $row[ $header ] ) ? (string) $row[ $header ] : '';
		}

		$write = self::write( $table, $values );
		if ( Insight_Danel_Graph::is_locked( $write ) || Insight_Danel_Graph::is_config_error( $write ) || Insight_Danel_Graph::is_data_error( $write ) ) {
			return $write;
		}

		// The write response is not trusted either way: a timeout can follow a committed row,
		// and a success has been reported for a row that never landed. Only the read-back decides.
		foreach ( self::VERIFY_WAITS as $wait ) {
			sleep( $wait );
			$count = self::count_id( $table, $uuid );
			if ( is_int( $count ) && $count > 0 ) {
				update_option( self::OPT_WRITTEN, 1, false );
				self::note_duplicates( $table, $uuid, $count );
				return true;
			}
		}
		return array(
			'code'      => $write['code'],
			'data'      => null,
			'error'     => 'not found after write' . ( $write['error'] ? ' (' . $write['error'] . ')' : '' ),
			'ambiguous' => true,
		);
	}

	/**
	 * Fills the empty row Excel creates with a new table, but only before the first verified write:
	 * afterwards a lagging read could show a stale empty row and the PATCH would overwrite a real lead.
	 */
	private static function write( $table, array $values ) {
		$graph = self::graph();
		if ( ! get_option( self::OPT_WRITTEN ) ) {
			$body = $graph->request( 'GET', '/tables/' . rawurlencode( $table ) . '/dataBodyRange?$select=rowCount,values' );
			if ( Insight_Danel_Graph::is_ok( $body ) && 1 === (int) ( $body['data']['rowCount'] ?? 0 ) ) {
				$only = $body['data']['values'][0] ?? array();
				if ( '' === implode( '', array_map( 'strval', $only ) ) ) {
					return $graph->request( 'PATCH', '/tables/' . rawurlencode( $table ) . '/dataBodyRange', array( 'values' => array( $values ) ) );
				}
			}
		}
		return $graph->request( 'POST', '/tables/' . rawurlencode( $table ) . '/rows', array( 'values' => array( $values ) ) );
	}

	/** Number of rows holding $uuid (data rows only — no header or totals), or the failed response. */
	private static function count_id( $table, $uuid ) {
		$col = self::column_id( $table );
		if ( is_array( $col ) ) {
			return $col;
		}
		$res = self::graph()->request( 'GET', '/tables/' . rawurlencode( $table ) . '/columns/' . rawurlencode( $col ) . '/dataBodyRange?$select=values' );
		if ( ! Insight_Danel_Graph::is_ok( $res ) ) {
			return $res;
		}
		$count = 0;
		foreach ( $res['data']['values'] ?? array() as $cell ) {
			if ( isset( $cell[0] ) && (string) $cell[0] === $uuid ) {
				++$count;
			}
		}
		return $count;
	}

	/** Duplicates are reported, never deleted: deleting by row position is unsafe while people sort or edit the sheet. */
	private static function note_duplicates( $table, $uuid, $count ) {
		if ( $count < 2 ) {
			return;
		}
		Insight_Danel_Alerts::send(
			'dup_' . $uuid,
			'Duplicate row in ' . $table,
			array( 'Lead ID ' . $uuid . ' appears ' . $count . ' times in ' . $table . '.', 'Nothing was deleted automatically. Please delete the extra row(s) in Excel, keeping one.' ),
			30 * DAY_IN_SECONDS
		);
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

	private static function column_id( $table ) {
		$headers = self::headers( $table );
		if ( isset( $headers['code'] ) ) {
			return $headers;
		}
		$id = array_search( Insight_Danel_Mapper::ID_COLUMN, self::$columns[ $table ], true );
		if ( false === $id ) {
			return array(
				'code'  => 422,
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
				Insight_Danel_Alerts::send( 'blocked', 'Excel file is locked — leads are waiting', array( 'The Datafromsite file is locked: usually open in Excel on a computer, checked out, or a sheet is protected.', 'Leads are queued and will be sent automatically once it is released.', 'Oldest waiting lead ID: ' . $item->uuid ) );
			}
			return;
		}

		$delay = min( HOUR_IN_SECONDS, MINUTE_IN_SECONDS * ( 2 ** min( 6, $attempts - 1 ) ) ) + wp_rand( 0, 30 );
		if ( ! empty( $res['ambiguous'] ) ) {
			// Give a slow, possibly committed write time to appear before checking again.
			$delay = max( $delay, self::AMBIGUOUS_RETRY );
		}
		self::update( $item, 'pending', $error, $delay, $attempts );

		if ( Insight_Danel_Graph::is_config_error( $res ) ) {
			Insight_Danel_Alerts::send( 'config', 'Leads cannot reach the Excel file — setup problem', array( 'This usually means a permission, secret, file, or column-name change on Danel\'s side.', 'Error: ' . $error, 'Leads are kept for up to 48 hours and retried automatically.' ) );
		} elseif ( Insight_Danel_Graph::is_data_error( $res ) ) {
			Insight_Danel_Alerts::send( 'data', 'Excel rejected a lead row', array( 'Microsoft refused the row. Common causes: a filter left on the table, a protected sheet, or an unusual value.', 'Error: ' . $error, 'Lead ID: ' . $item->uuid, 'Leads are kept for up to 48 hours and retried automatically.' ) );
		} elseif ( $attempts >= self::MIN_ATTEMPTS && $age > 30 * MINUTE_IN_SECONDS ) {
			Insight_Danel_Alerts::send( 'failing', 'Leads are failing to reach the Excel file', array( 'Error: ' . $error, 'Lead ID: ' . $item->uuid . ' (attempt ' . $attempts . ')', 'Leads are kept for up to 48 hours and retried automatically.' ) );
		}
	}

	/** Submissions that never reached the queue (hook did not fire, insert failed) are queued here. */
	public static function sweep_orphans() {
		global $wpdb;
		$since = get_option( self::OPT_SINCE );
		if ( ! $since ) {
			return;
		}
		$forms   = implode( ',', array_map( 'intval', array_keys( Insight_Danel_Mapper::FORMS ) ) );
		$orphans = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.form_id FROM {$wpdb->prefix}wsf_submit s LEFT JOIN " . self::table() . " q ON q.submit_id = s.id
				WHERE s.form_id IN ($forms) AND s.status = 'publish' AND q.id IS NULL AND s.date_added >= %s AND s.date_added < %s
				ORDER BY s.id ASC LIMIT 50",
				$since,
				gmdate( 'Y-m-d H:i:s', time() - self::ORPHAN_AFTER )
			)
		);
		if ( ! $orphans ) {
			return;
		}
		foreach ( $orphans as $o ) {
			self::insert( (int) $o->id, (int) $o->form_id );
		}
		Insight_Danel_Alerts::send( 'orphans', count( $orphans ) . ' lead(s) had not been queued — now queued', array( 'Some submissions were saved by the form but never reached the queue. They have been queued now and will be sent.', 'No action needed unless this repeats.' ) );
		self::process();
	}

	/** Alerts when leads wait too long — e.g. WP-Cron is not running on the server. */
	public static function check_stalled() {
		global $wpdb;
		$oldest = $wpdb->get_row( 'SELECT COUNT(*) n, MIN(created_at) oldest FROM ' . self::table() . " WHERE status IN ('pending','blocked')" );
		if ( ! $oldest || ! $oldest->n || time() - strtotime( $oldest->oldest . ' UTC' ) < self::STALL_AFTER ) {
			return;
		}
		Insight_Danel_Alerts::send( 'stalled', (int) $oldest->n . ' lead(s) waiting for more than 30 minutes', array( 'Oldest waiting since ' . wp_date( 'd/m/Y H:i', strtotime( $oldest->oldest . ' UTC' ) ) . '.', 'If no other alert explains it, the background worker may not be running (WP-Cron / system cron).' ) );
	}

	/**
	 * Danel agreed: leads that keep failing are deleted after 48 hours, with an alert.
	 * A lead is never expired unless it was genuinely tried several times, and never if it is already in Excel.
	 */
	public static function expire_old() {
		global $wpdb;
		if ( ! self::lock() ) {
			return;
		}
		$ids = array();
		try {
			$items = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . self::table() . " WHERE status IN ('pending','blocked') AND created_at < %s AND attempts >= %d",
					gmdate( 'Y-m-d H:i:s', time() - self::MAX_AGE ),
					self::MIN_ATTEMPTS
				)
			);
			foreach ( $items as $item ) {
				$submit = self::load_submit( (int) $item->submit_id );
				if ( 'error' === $submit ) {
					continue;
				}
				$delivered = self::delivered_everywhere( $item );
				if ( true !== $delivered && false !== $delivered ) {
					continue;
				}
				if ( is_object( $submit ) && ! self::delete_submit( $submit ) ) {
					continue;
				}
				if ( true === $delivered ) {
					self::update( $item, 'done', '' );
					continue;
				}
				self::update( $item, 'expired', $item->last_error );
				$ids[] = $item->uuid . ' (' . ( Insight_Danel_Mapper::FORMS[ (int) $item->form_id ]['label'] ?? $item->form_id ) . ')';
			}
		} finally {
			self::unlock();
		}
		if ( $ids ) {
			Insight_Danel_Alerts::send( 'expired', count( $ids ) . ' lead(s) deleted after 48 hours without reaching Excel', array_merge( array( 'These leads failed repeatedly for 48 hours and were deleted from the site:' ), $ids ), 0 );
		}
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
