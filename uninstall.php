<?php
/**
 * Fires only when the plugin is deleted from the Plugins screen (not on
 * deactivation). Removes everything this plugin ever wrote to the options
 * table, including the security token, so nothing is left behind.
 *
 * @package CV_Lead_To_Sheet_Bridge
 */

// If uninstall.php is not called by WordPress, bail.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'cv_lts_webhook_url' );
delete_option( 'cv_lts_security_token' );

// In case the site is Multisite and the plugin was network-activated.
if ( is_multisite() ) {
    $site_ids = get_sites( array( 'fields' => 'ids' ) );
    foreach ( $site_ids as $site_id ) {
        switch_to_blog( $site_id );
        delete_option( 'cv_lts_webhook_url' );
        delete_option( 'cv_lts_security_token' );
        restore_current_blog();
    }
}
