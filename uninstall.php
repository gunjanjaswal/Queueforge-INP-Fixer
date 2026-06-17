<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package QueueForge_INP_Fixer
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Clean up plugin options.
delete_option( 'qfinp_settings' );
delete_option( 'qfinp_support_notice_dismissed' );
