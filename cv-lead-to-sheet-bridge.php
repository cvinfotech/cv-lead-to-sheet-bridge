<?php
/**
 * Plugin Name: CV Lead-to-Sheet Bridge
 * Plugin URI:  https://github.com/cv-infotech/cv-lead-to-sheet-bridge
 * Description: Automatically send WordPress form submissions to Google Sheets via Webhooks. Zero-mapping Smart-Match field detection, no Zapier required. Supports CF7, WPForms, Gravity Forms, and Elementor Pro Forms.
 * Version:     1.4.0
 * Author:      CV Infotech
 * Author URI:  https://cvinfotech.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cv-lts
 * Requires at least: 5.0
 * Requires PHP: 7.2
 *
 * @package   CV_Lead_To_Sheet_Bridge
 * @author    CV Infotech
 * @link      https://cvinfotech.com
 * @copyright 2026 CV Infotech
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define Constants
define( 'CV_LTS_VERSION', '1.4.0' );
define( 'CV_LTS_PATH', plugin_dir_path( __FILE__ ) );
define( 'CV_LTS_URL', plugin_dir_url( __FILE__ ) );

// Include required files
require_once CV_LTS_PATH . 'includes/admin-settings.php';
require_once CV_LTS_PATH . 'includes/integrations.php';

// Add a settings link on the plugin page
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'cv_lts_settings_link' );
function cv_lts_settings_link( $links ) {
    $settings_link = '<a href="options-general.php?page=cv-lts-settings">' . __( 'Settings', 'cv-lts' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}