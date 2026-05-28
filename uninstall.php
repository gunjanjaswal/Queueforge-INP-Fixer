<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Yieldific_INP_Hyper_Optimizer
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Clean up plugin options.
delete_option( 'yinp_settings' );
delete_option( 'yinp_support_notice_dismissed' );
