<?php
/**
 * Plugin Name: Insight - Danel - SharePoint Leads
 * Plugin URI: https://github.com/udiinsight/insight-danel-sharepoint-leads
 * Description: Sends WS Form leads to the Datafromsite Excel file on Danel's SharePoint via Microsoft Graph, verifies each write, then deletes the lead from the site.
 * Version: 1.0.0
 * Author: Insight Marketing
 * Author URI: https://insight-marketing.co.il
 * Text Domain: insight-danel-sharepoint-leads
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'INSIGHT_DANEL_LEADS_VERSION', '1.0.0' );
define( 'INSIGHT_DANEL_LEADS_DIR', plugin_dir_path( __FILE__ ) );

if ( ! defined( 'DANEL_GRAPH_SECRET_EXPIRES' ) ) {
	define( 'DANEL_GRAPH_SECRET_EXPIRES', '2028-09-27' );
}

require_once INSIGHT_DANEL_LEADS_DIR . 'includes/class-graph.php';
require_once INSIGHT_DANEL_LEADS_DIR . 'includes/class-mapper.php';
require_once INSIGHT_DANEL_LEADS_DIR . 'includes/class-alerts.php';
require_once INSIGHT_DANEL_LEADS_DIR . 'includes/class-queue.php';
require_once INSIGHT_DANEL_LEADS_DIR . 'includes/class-admin.php';

register_activation_hook( __FILE__, array( 'Insight_Danel_Queue', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Insight_Danel_Queue', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Insight_Danel_Queue', 'init' ) );
add_action( 'plugins_loaded', array( 'Insight_Danel_Admin', 'init' ) );
