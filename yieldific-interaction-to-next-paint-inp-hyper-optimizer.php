<?php
/**
 * Plugin Name: Yieldific – Interaction to Next Paint (INP) Hyper-Optimizer
 * Plugin URI: https://github.com/gunjanjaswal/yieldific-inp-hyper-optimizer
 * Description: Lowers Interaction to Next Paint (INP) by delaying heavy third-party / theme JavaScript until the first real user interaction and yielding the main thread between each script so the browser stays responsive. Includes a live INP + long-task debug overlay.
 * Version: 1.0.0
 * Author: Gunjan Jaswal
 * Author URI: https://www.gunjanjaswal.me
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: yieldific-interaction-to-next-paint-inp-hyper-optimizer
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Tested up to: 7.0
 */

if (!defined('WPINC')) {
    die;
}

define('YINP_VERSION', '1.0.0');
define('YINP_FILE', __FILE__);

/**
 * Activation: clear the support-notice flag so it shows once after (re)install.
 */
function yinp_activate()
{
    delete_option('yinp_support_notice_dismissed');
}
register_activation_hook(__FILE__, 'yinp_activate');

/* -------------------------------------------------------------------------
 * Settings storage
 * ---------------------------------------------------------------------- */

function yinp_default_settings()
{
    return array(
        'enable_delay'     => 1,
        'enable_yield'     => 1,
        'delay_fallback'   => 8,
        'delay_jquery'     => 0,
        'skip_logged_in'   => 1,
        'enable_debug'     => 0,
        'exclusions'       => '',
    );
}

function yinp_get_settings()
{
    $stored = get_option('yinp_settings', array());
    if (!is_array($stored)) {
        $stored = array();
    }
    return wp_parse_args($stored, yinp_default_settings());
}

function yinp_sanitize_settings($input)
{
    $clean = array();

    $bool_keys = array('enable_delay', 'enable_yield', 'delay_jquery', 'skip_logged_in', 'enable_debug');
    foreach ($bool_keys as $key) {
        $clean[$key] = !empty($input[$key]) ? 1 : 0;
    }

    $fallback = isset($input['delay_fallback']) ? (int) $input['delay_fallback'] : 8;
    $clean['delay_fallback'] = max(0, min(60, $fallback));

    $exclusions = isset($input['exclusions']) ? (string) $input['exclusions'] : '';
    // Keep it as newline-separated keywords; strip tags, normalise line endings.
    $exclusions = wp_strip_all_tags($exclusions);
    $exclusions = str_replace(array("\r\n", "\r"), "\n", $exclusions);
    $clean['exclusions'] = trim($exclusions);

    return $clean;
}

function yinp_register_settings()
{
    register_setting('yinp_settings_group', 'yinp_settings', array(
        'type'              => 'array',
        'sanitize_callback' => 'yinp_sanitize_settings',
        'default'           => yinp_default_settings(),
    ));
}
add_action('admin_init', 'yinp_register_settings');

/* -------------------------------------------------------------------------
 * Exclusion keywords (scripts that must never be delayed)
 * ---------------------------------------------------------------------- */

/**
 * Build the list of substrings; a script tag matching any of them is left
 * untouched (executes normally, not delayed).
 *
 * @return string[]
 */
function yinp_get_exclusions()
{
    $s = yinp_get_settings();

    // Hard defaults the plugin must never break.
    $defaults = array(
        'yieldific',     // this plugin's own runtime + config object
        'admin-bar',     // WP admin bar interactions
        'wp-admin',
        'data-no-optimize',
    );

    $user = array();
    if (!empty($s['exclusions'])) {
        foreach (preg_split('/\n+/', $s['exclusions']) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $user[] = $line;
            }
        }
    }

    $list = array_merge($defaults, $user);

    /**
     * Filter the script-exclusion keyword list.
     *
     * @param string[] $list Substrings matched against each <script> tag.
     */
    return apply_filters('yinp_exclusions', array_values(array_unique($list)));
}

/* -------------------------------------------------------------------------
 * Front-end eligibility
 * ---------------------------------------------------------------------- */

function yinp_should_optimize()
{
    if (is_admin() || wp_doing_ajax()) {
        return false;
    }
    if (is_feed() || (function_exists('is_embed') && is_embed())) {
        return false;
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return false;
    }
    if (defined('DOING_CRON') && DOING_CRON) {
        return false;
    }
    if (function_exists('is_customize_preview') && is_customize_preview()) {
        return false;
    }

    $s = yinp_get_settings();
    if (empty($s['enable_delay'])) {
        return false;
    }
    if (!empty($s['skip_logged_in']) && is_user_logged_in() && current_user_can('edit_posts')) {
        return false;
    }
    // Manual bypass for debugging: append ?yieldific_off to any URL.
    // Presence check only; no form data read, so nonce verification N/A.
    if (isset($_GET['yieldific_off'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return false;
    }

    return true;
}

/* -------------------------------------------------------------------------
 * Output-buffer rewrite: defer eligible <script> tags
 * ---------------------------------------------------------------------- */

/**
 * Decide whether a single matched <script> should be delayed.
 *
 * @param string $attrs Raw attribute string from inside the opening tag.
 * @param string $inner Inline script body (empty for external scripts).
 * @return bool
 */
function yinp_script_is_eligible($attrs, $inner)
{
    $s = yinp_get_settings();

    // Already processed, or explicitly opted out.
    if (preg_match('/data-no-optimize|data-yieldific/i', $attrs)) {
        return false;
    }

    // Only delay executable JavaScript. Leave JSON-LD, importmap, modules, etc.
    if (preg_match('/type\s*=\s*["\']([^"\']+)["\']/i', $attrs, $tm)) {
        $type = strtolower(trim($tm[1]));
        $ok = array('text/javascript', 'application/javascript', 'application/ecmascript', 'text/ecmascript', '');
        if (!in_array($type, $ok, true)) {
            return false;
        }
    }

    // jQuery is opt-in to delay (many themes rely on it at load).
    if (empty($s['delay_jquery']) && preg_match('#jquery[-.]?(core|migrate|migrate\.min|\.min)?\.js|/jquery#i', $attrs)) {
        return false;
    }

    foreach (yinp_get_exclusions() as $kw) {
        if ($kw !== '' && stripos($attrs . $inner, $kw) !== false) {
            return false;
        }
    }

    return true;
}

/**
 * Output-buffer callback. Rewrites eligible script tags so the browser will
 * not execute them until the front-end runtime restores them on first input.
 *
 * @param string $html Full buffered page HTML.
 * @return string
 */
function yinp_optimize_html($html)
{
    // Only touch full HTML documents.
    if (stripos($html, '</body>') === false || stripos($html, '<html') === false) {
        return $html;
    }

    return preg_replace_callback(
        '#<script\b([^>]*)>(.*?)</script>#is',
        function ($m) {
            $attrs = $m[1];
            $inner = $m[2];

            if (!yinp_script_is_eligible($attrs, $inner)) {
                return $m[0];
            }

            // Rename src so the browser does not fetch/execute it yet.
            $new_attrs = preg_replace('/\bsrc\s*=/i', 'data-yieldific-src=', $attrs, 1);
            // Drop any original type attribute (we set our own dummy type).
            $new_attrs = preg_replace('/\btype\s*=\s*["\'][^"\']*["\']/i', '', $new_attrs);

            return '<script type="yieldific/javascript"' . $new_attrs . '>' . $inner . '</script>';
        },
        $html
    );
}

function yinp_start_buffer()
{
    if (yinp_should_optimize()) {
        ob_start('yinp_optimize_html');
    }
}
add_action('template_redirect', 'yinp_start_buffer', 1);

/* -------------------------------------------------------------------------
 * Front-end runtime
 * ---------------------------------------------------------------------- */

function yinp_enqueue_runtime()
{
    $s = yinp_get_settings();

    // The debug overlay can run even when delay is off (so admins can measure
    // INP), but the delay runtime needs the buffer to have rewritten scripts.
    $debug = (!empty($s['enable_debug']) && current_user_can('manage_options')) ? 1 : 0;

    if (!yinp_should_optimize() && !$debug) {
        return;
    }

    wp_enqueue_script(
        'yinp-runtime',
        plugins_url('assets/yieldific-runtime.js', __FILE__),
        array(),
        YINP_VERSION,
        true
    );

    wp_localize_script('yinp-runtime', 'YieldificINP', array(
        'delay'    => yinp_should_optimize() ? 1 : 0,
        'yield'    => !empty($s['enable_yield']) ? 1 : 0,
        'fallback' => (int) $s['delay_fallback'],
        'debug'    => $debug,
    ));
}
add_action('wp_enqueue_scripts', 'yinp_enqueue_runtime');

/* -------------------------------------------------------------------------
 * Settings page UI
 * ---------------------------------------------------------------------- */

function yinp_settings_menu()
{
    add_options_page(
        __('Yieldific – Interaction to Next Paint (INP) Hyper-Optimizer', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'),
        __('Yieldific INP', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'),
        'manage_options',
        'yieldific-interaction-to-next-paint-inp-hyper-optimizer',
        'yinp_render_settings_page'
    );
}
add_action('admin_menu', 'yinp_settings_menu');

function yinp_render_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $s = yinp_get_settings();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Yieldific – Interaction to Next Paint (INP) Hyper-Optimizer', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></h1>
        <p><?php esc_html_e('Delay heavy third-party and theme JavaScript until the visitor actually interacts, then load each script while yielding the main thread. This is the most direct lever on Interaction to Next Paint (INP) and Total Blocking Time.', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></p>

        <form method="post" action="options.php">
            <?php settings_fields('yinp_settings_group'); ?>

            <h2><?php esc_html_e('JavaScript delay', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Delay until interaction', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></th>
                    <td>
                        <label><input type="checkbox" name="yinp_settings[enable_delay]" value="1" <?php checked($s['enable_delay']); ?>> <?php esc_html_e('Hold eligible scripts until the first scroll, tap, key, or mouse move.', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></label>
                        <p class="description"><?php esc_html_e('Cuts the JavaScript that blocks the main thread during initial load, which is the main cause of high INP on mobile.', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Yield between scripts', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></th>
                    <td>
                        <label><input type="checkbox" name="yinp_settings[enable_yield]" value="1" <?php checked($s['enable_yield']); ?>> <?php esc_html_e('Yield the main thread between each delayed script (scheduler.yield() with a setTimeout fallback).', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></label>
                        <p class="description"><?php esc_html_e('Stops the deferred scripts from re-blocking the thread in one long task when they finally run.', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="yinp_fallback"><?php esc_html_e('Fallback timeout (seconds)', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></label></th>
                    <td>
                        <input type="number" id="yinp_fallback" name="yinp_settings[delay_fallback]" value="<?php echo esc_attr($s['delay_fallback']); ?>" min="0" max="60" class="small-text">
                        <p class="description"><?php esc_html_e('Load delayed scripts automatically after this many seconds even with no interaction (protects analytics / ads). 0 = only on interaction. Default: 8.', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Delay jQuery', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></th>
                    <td>
                        <label><input type="checkbox" name="yinp_settings[delay_jquery]" value="1" <?php checked($s['delay_jquery']); ?>> <?php esc_html_e('Also delay jQuery core/migrate. Off by default — only enable if your theme works without jQuery at load.', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></label>
                        <p class="description"><?php esc_html_e('Test thoroughly. Some themes throw "jQuery is not defined" if their inline scripts run before jQuery loads.', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Exclusions', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="yinp_exclusions"><?php esc_html_e('Never delay these', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></label></th>
                    <td>
                        <textarea id="yinp_exclusions" name="yinp_settings[exclusions]" rows="6" class="large-text code" placeholder="recaptcha&#10;/wp-includes/js/&#10;gtag"><?php echo esc_textarea($s['exclusions']); ?></textarea>
                        <p class="description"><?php esc_html_e('One keyword per line. Any script whose tag or inline code contains the keyword runs normally. Useful for scripts that must execute before interaction (e.g. consent banners, reCAPTCHA). You can also add data-no-optimize to a single tag in your HTML.', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Safety & debugging', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Skip logged-in editors', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></th>
                    <td>
                        <label><input type="checkbox" name="yinp_settings[skip_logged_in]" value="1" <?php checked($s['skip_logged_in']); ?>> <?php esc_html_e('Do not delay scripts for users who can edit posts.', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></label>
                        <p class="description"><?php esc_html_e('Keeps page builders and admin tooling working while editing. Visitors still get the optimisation.', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Live INP overlay', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></th>
                    <td>
                        <label><input type="checkbox" name="yinp_settings[enable_debug]" value="1" <?php checked($s['enable_debug']); ?>> <?php esc_html_e('Show a floating badge on the front end with measured INP and long-task blocking time.', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></label>
                        <p class="description"><?php esc_html_e('Visible only to users with the manage_options capability. Append ?yieldific_off to any URL to bypass the delay for a single page load.', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <hr>

        <h2><?php esc_html_e('Upgrade to Pro', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></h2>
        <p class="description" style="max-width:46em;"><?php esc_html_e('The free version delays all eligible scripts on first interaction. Yieldific Pro gives you per-device, per-element, and per-URL control:', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></p>

        <ul style="max-width:46em;list-style:disc;margin-left:1.5em;">
            <li><?php esc_html_e('Separate mobile / desktop fallback timeouts — aggressive on mobile, relaxed on desktop.', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></li>
            <li><?php esc_html_e('Load-on-visible triggers (IntersectionObserver) for comments, maps, and embeds.', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></li>
            <li><?php esc_html_e('WooCommerce-safe mode — never delay cart, checkout, or my-account pages.', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></li>
            <li><?php esc_html_e('Per-URL disable rules for forms, schedulers, and payment gateways.', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></li>
            <li><?php esc_html_e('Licensing, auto-updates, and priority support via Freemius.', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></li>
        </ul>

        <p>
            <a href="https://checkout.freemius.com/plugin/30738/plan/50449/" target="_blank" class="button button-primary"><?php esc_html_e('Personal — 1 site', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></a>
            <a href="https://checkout.freemius.com/plugin/30738/plan/50450/" target="_blank" class="button button-primary"><?php esc_html_e('Professional — 5 sites', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></a>
            <a href="https://checkout.freemius.com/plugin/30738/plan/50451/" target="_blank" class="button button-primary"><?php esc_html_e('Agency — 25 sites', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></a>
        </p>

        <hr>

        <p>
            <a href="https://ko-fi.com/gunjanjaswal" target="_blank" class="button button-secondary"><?php esc_html_e('Support on Ko-fi', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></a>
            <a href="https://github.com/gunjanjaswal/yieldific-inp-hyper-optimizer" target="_blank" class="button button-secondary"><?php esc_html_e('GitHub', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'); ?></a>
        </p>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * Plugin meta + support notice
 * ---------------------------------------------------------------------- */

function yinp_plugin_action_links($links)
{
    $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=yieldific-interaction-to-next-paint-inp-hyper-optimizer')) . '">' . __('Settings', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer') . '</a>';
    $coffee_link = '<a href="https://ko-fi.com/gunjanjaswal" target="_blank" style="color: #0073aa; font-weight: bold;">' . __('Support on Ko-fi', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer') . '</a>';
    array_unshift($links, $settings_link, $coffee_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'yinp_plugin_action_links');

function yinp_plugin_meta_links($links, $file)
{
    if (plugin_basename(__FILE__) === $file) {
        $links[] = '<a href="https://wordpress.org/support/plugin/yieldific-interaction-to-next-paint-inp-hyper-optimizer/" target="_blank">' . __('Plugin Support', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer') . '</a>';
        $links[] = '<a href="mailto:hello@gunjanjaswal.me">' . __('Contact Developer', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer') . '</a>';
    }
    return $links;
}
add_filter('plugin_row_meta', 'yinp_plugin_meta_links', 10, 2);

function yinp_enqueue_admin_scripts($hook)
{
    if ('plugins.php' !== $hook) {
        return;
    }
    if (get_option('yinp_support_notice_dismissed')) {
        return;
    }
    wp_enqueue_script('jquery');
    $inline_script = sprintf(
        '
        jQuery(document).ready(function($) {
            $(document).on("click", ".yinp-support-notice .notice-dismiss", function() {
                $.ajax({
                    url: ajaxurl,
                    data: {
                        action: "yinp_dismiss_support_notice",
                        nonce: "%s"
                    }
                });
            });
        });
        ',
        wp_create_nonce('yinp_dismiss_notice')
    );
    wp_add_inline_script('jquery', $inline_script);
}
add_action('admin_enqueue_scripts', 'yinp_enqueue_admin_scripts');

function yinp_admin_notice()
{
    if (get_option('yinp_support_notice_dismissed')) {
        return;
    }
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'plugins') {
        return;
    }
    ?>
    <div class="notice notice-info is-dismissible yinp-support-notice">
        <p><?php echo wp_kses(
            sprintf(
                /* translators: %s: Link to Ko-fi support page */
                __('Thank you for using Yieldific – Interaction to Next Paint (INP) Hyper-Optimizer! If it helped you pass Core Web Vitals, please consider %s.', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'),
                '<a href="https://ko-fi.com/gunjanjaswal" target="_blank">supporting on Ko-fi</a>'
            ),
            array(
                'a' => array(
                    'href'   => array(),
                    'target' => array(),
                ),
            )
        ); ?></p>
    </div>
    <?php
    update_option('yinp_support_notice_dismissed', true);
}
add_action('admin_notices', 'yinp_admin_notice');

function yinp_dismiss_support_notice()
{
    if (!isset($_REQUEST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['nonce'])), 'yinp_dismiss_notice')) {
        wp_die(esc_html__('Security check failed', 'yieldific-interaction-to-next-paint-inp-hyper-optimizer'), 403);
    }
    update_option('yinp_support_notice_dismissed', true);
    wp_die();
}
add_action('wp_ajax_yinp_dismiss_support_notice', 'yinp_dismiss_support_notice');
