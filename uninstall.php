<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'insight_danel_leads' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
delete_option( 'insight_danel_leads_db' );
delete_option( 'insight_danel_leads_alerts' );
delete_transient( 'insight_danel_graph_token' );
