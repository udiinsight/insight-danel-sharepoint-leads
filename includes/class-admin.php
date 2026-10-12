<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools → Danel Leads: queue status without personal data, plus "process now" and "test connection".
 */
class Insight_Danel_Admin {

	const SLUG = 'insight-danel-leads';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_insight_danel_leads_action', array( __CLASS__, 'handle' ) );
	}

	public static function menu() {
		add_management_page( 'Danel Leads → Excel', 'Danel Leads → Excel', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'insight-danel-sharepoint-leads' ) );
		}
		check_admin_referer( 'insight_danel_leads_action' );

		$do = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		if ( 'process' === $do ) {
			$r      = Insight_Danel_Queue::process();
			$notice = ! empty( $r['locked'] ) ? 'Another run is in progress.' : 'Processed ' . (int) $r['processed'] . ' lead(s).';
		} elseif ( 'test' === $do ) {
			$res    = ( new Insight_Danel_Graph() )->request( 'GET', '/tables?$select=name' );
			$notice = Insight_Danel_Graph::is_ok( $res )
				? 'Connection OK. Tables: ' . implode( ', ', array_column( $res['data']['value'] ?? array(), 'name' ) )
				: 'Connection failed: ' . $res['error'];
		} else {
			$notice = 'Unknown action.';
		}
		set_transient( 'insight_danel_leads_notice_' . get_current_user_id(), $notice, 60 );
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::SLUG ) );
		exit;
	}

	public static function render() {
		global $wpdb;
		$table  = Insight_Danel_Queue::table();
		$counts = $wpdb->get_results( "SELECT status, COUNT(*) n FROM $table GROUP BY status", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$recent = $wpdb->get_results( "SELECT uuid, form_id, status, attempts, last_error, created_at, done_at FROM $table ORDER BY id DESC LIMIT 50" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$notice = get_transient( 'insight_danel_leads_notice_' . get_current_user_id() );
		delete_transient( 'insight_danel_leads_notice_' . get_current_user_id() );
		$tz = static fn( $utc ) => $utc ? wp_date( 'd/m/Y H:i', strtotime( $utc . ' UTC' ) ) : '—';
		?>
		<div class="wrap">
			<h1>Danel Leads → Excel</h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-info"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! Insight_Danel_Graph::is_configured() ) : ?>
				<div class="notice notice-error"><p>DANEL_GRAPH_* constants are missing — leads are queued but not sent.</p></div>
			<?php endif; ?>

			<p>
				<?php
				foreach ( array( 'pending', 'blocked', 'done', 'expired', 'failed' ) as $s ) {
					echo '<strong>' . esc_html( $s ) . ':</strong> ' . (int) ( $counts[ $s ]->n ?? 0 ) . ' &nbsp; ';
				}
				?>
			</p>
			<p>Alerts go to: <code><?php echo esc_html( Insight_Danel_Alerts::recipient() ); ?></code> &nbsp; Secret expires: <code><?php echo esc_html( DANEL_GRAPH_SECRET_EXPIRES ); ?></code></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-inline-end:8px">
				<?php wp_nonce_field( 'insight_danel_leads_action' ); ?>
				<input type="hidden" name="action" value="insight_danel_leads_action">
				<button class="button button-primary" name="do" value="process">Process queue now</button>
				<button class="button" name="do" value="test">Test connection</button>
			</form>

			<h2>Recent leads</h2>
			<p class="description">Lead IDs only — no personal data is stored here. The ID matches the "מזהה פנייה" column in Excel.</p>
			<table class="widefat striped">
				<thead><tr><th>Lead ID</th><th>Form</th><th>Status</th><th>Attempts</th><th>Received</th><th>Finished</th><th>Last error</th></tr></thead>
				<tbody>
				<?php foreach ( $recent as $r ) : ?>
					<tr>
						<td><code><?php echo esc_html( $r->uuid ); ?></code></td>
						<td><?php echo esc_html( Insight_Danel_Mapper::FORMS[ (int) $r->form_id ]['label'] ?? $r->form_id ); ?></td>
						<td><?php echo esc_html( $r->status ); ?></td>
						<td><?php echo (int) $r->attempts; ?></td>
						<td><?php echo esc_html( $tz( $r->created_at ) ); ?></td>
						<td><?php echo esc_html( $tz( $r->done_at ) ); ?></td>
						<td><?php echo esc_html( $r->last_error ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $recent ) : ?>
					<tr><td colspan="7">No leads yet.</td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
