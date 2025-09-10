<?php
/*
Plugin Name: Enhanced Interaction & Debug Logger Pro
Plugin URI: https://github.com/volkansah/interaction-debug-logger
Description: Professional real-time logging with WordPress debug integration, copy functionality, and comprehensive error tracking including fatal errors.
Version: 3.0
Author: Volkan Kücükbudak
Author URI: https://aicodecraft.io
License: DBAD
License URI:
Text Domain: enhanced-debug-logger
Domain Path: /languages
Tags: debug, logger, interactions, real-time, monitoring, WordPress, development, admin-tools, fatal-errors, copy-function
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class EnhancedDebugLogger
{
    private static $instance = null;
    private $log_file;
    private $wp_debug_log;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->log_file = WP_CONTENT_DIR . '/enhanced-debug-log.txt';
        $this->wp_debug_log = WP_CONTENT_DIR . '/debug-log.txt';
        $this->init();
    }

    private function init()
    {
        add_action('init', [$this, 'load_plugin_textdomain']);
        add_action('init', [$this, 'log_wordpress_requests']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'settings_init']);
        add_action('admin_init', [$this, 'handle_wp_config_update']);
        add_action('wp_footer', [$this, 'add_console']);
        add_action('admin_footer', [$this, 'add_console']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // AJAX Actions
        add_action('wp_ajax_refresh_debug_log', [$this, 'refresh_log']);
        add_action('wp_ajax_clean_debug_log', [$this, 'clean_log']);
        add_action('wp_ajax_get_wp_debug_log', [$this, 'get_wp_debug_log']);
        add_action('wp_ajax_get_combined_logs', [$this, 'get_combined_logs']);
        add_action('wp_ajax_clean_wp_debug_log', [$this, 'clean_wp_debug_log']);
    }

    public function load_plugin_textdomain()
    {
        load_plugin_textdomain('enhanced-debug-logger', false, basename(dirname(__FILE__)) . '/languages/');
    }

    public function handle_wp_config_update()
    {
        if (isset($_POST['option_page']) && $_POST['option_page'] == 'enhanced_debug_logger') {
            $wp_debug_option = get_option('edl_enable_wp_debug');
            $this->update_wp_config_debug($wp_debug_option === '1');
        }
    }
    
    public function update_wp_config_debug($enabled)
    {
        $config_path = ABSPATH . 'wp-config.php';
        if (!file_exists($config_path) || !is_writable($config_path)) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(__('Could not write to wp-config.php. Please update WP_DEBUG manually.', 'enhanced-debug-logger')) . '</p></div>';
            });
            return;
        }

        $config_content = file_get_contents($config_path);
        
        $debug_log_setting = $enabled ? "define('WP_DEBUG_LOG', true);" : "define('WP_DEBUG_LOG', false);";
        $debug_display_setting = $enabled ? "define('WP_DEBUG_DISPLAY', false);" : "define('WP_DEBUG_DISPLAY', true);";

        $config_content = preg_replace("/define\('WP_DEBUG',\s*(true|false)\s*\);/i", "define('WP_DEBUG', " . ($enabled ? 'true' : 'false') . ");", $config_content);
        $config_content = preg_replace("/define\('WP_DEBUG_LOG',\s*(true|false)\s*\);/i", $debug_log_setting, $config_content);
        $config_content = preg_replace("/define\('WP_DEBUG_DISPLAY',\s*(true|false)\s*\);/i", $debug_display_setting, $config_content);
        
        file_put_contents($config_path, $config_content);
    }

    public function custom_error_handler($errno, $errstr, $errfile, $errline)
    {
        if (!(error_reporting() & $errno)) {
            return false;
        }

        $error_types = [
            E_ERROR => __('Fatal Error', 'enhanced-debug-logger'),
            E_WARNING => __('Warning', 'enhanced-debug-logger'),
            E_PARSE => __('Parse Error', 'enhanced-debug-logger'),
            E_NOTICE => __('Notice', 'enhanced-debug-logger'),
            E_CORE_ERROR => __('Core Error', 'enhanced-debug-logger'),
            E_CORE_WARNING => __('Core Warning', 'enhanced-debug-logger'),
            E_COMPILE_ERROR => __('Compile Error', 'enhanced-debug-logger'),
            E_COMPILE_WARNING => __('Compile Warning', 'enhanced-debug-logger'),
            E_USER_ERROR => __('User Error', 'enhanced-debug-logger'),
            E_USER_WARNING => __('User Warning', 'enhanced-debug-logger'),
            E_USER_NOTICE => __('User Notice', 'enhanced-debug-logger'),
            E_STRICT => __('Strict Standards', 'enhanced-debug-logger'),
            E_RECOVERABLE_ERROR => __('Recoverable Error', 'enhanced-debug-logger'),
            E_DEPRECATED => __('Deprecated', 'enhanced-debug-logger'),
            E_USER_DEPRECATED => __('User Deprecated', 'enhanced-debug-logger')
        ];

        $error_type = isset($error_types[$errno]) ? $error_types[$errno] : __('Unknown Error', 'enhanced-debug-logger');
        $this->log_error($error_type, $errstr, $errfile, $errline);

        return false;
    }

    public function catch_fatal_error()
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
            $this->log_error(__('Fatal Error', 'enhanced-debug-logger'), $error['message'], $error['file'], $error['line']);
        }
    }

    private function log_error($type, $message, $file, $line)
    {
        if (get_option('edl_enabled', '0') === '1') {
            $current_time = current_time('mysql');
            $user_ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
            $log_message = sprintf(
                "[%s] %s: %s in %s on line %d | IP: %s\n",
                $current_time,
                $type,
                $message,
                $file,
                $line,
                $user_ip
            );
            file_put_contents($this->log_file, $log_message, FILE_APPEND | LOCK_EX);
        }
    }

    public function log_wordpress_requests()
    {
        if (get_option('edl_enabled', '0') === '1') {
            $current_time = current_time('mysql');
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            $request_method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
            $user_ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

            if (strpos($request_uri, 'admin-ajax.php') === false || get_option('edl_log_ajax', '0') === '1') {
                $log_message = sprintf(
                    "[%s] REQUEST: %s %s | IP: %s | UA: %s\n",
                    $current_time,
                    $request_method,
                    $request_uri,
                    $user_ip,
                    substr($user_agent, 0, 100)
                );
                file_put_contents($this->log_file, $log_message, FILE_APPEND | LOCK_EX);
            }
        }
    }

    public function add_admin_menu()
    {
        add_management_page(
            __('Enhanced Debug Logger', 'enhanced-debug-logger'),
            __('Debug Logger Pro', 'enhanced-debug-logger'),
            'manage_options',
            'enhanced-debug-logger',
            [$this, 'admin_page']
        );
    }

    public function settings_init()
    {
        register_setting('enhanced_debug_logger', 'edl_enabled');
        register_setting('enhanced_debug_logger', 'edl_refresh_interval', ['default' => 1000]);
        register_setting('enhanced_debug_logger', 'edl_enable_wp_debug');
        register_setting('enhanced_debug_logger', 'edl_log_ajax');
        register_setting('enhanced_debug_logger', 'edl_max_log_size', ['default' => 10]);
    }

    public function admin_page()
    {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(__('Enhanced Debug Logger Pro', 'enhanced-debug-logger')); ?></h1>

            <div id="edl-tabs" style="margin-top: 20px;">
                <nav class="nav-tab-wrapper">
                    <a href="#settings" class="nav-tab nav-tab-active" onclick="switchTab(event, 'settings')"><?php echo esc_html(__('Settings', 'enhanced-debug-logger')); ?></a>
                    <a href="#logs" class="nav-tab" onclick="switchTab(event, 'logs')"><?php echo esc_html(__('Live Logs', 'enhanced-debug-logger')); ?></a>
                    <a href="#wp-debug" class="nav-tab" onclick="switchTab(event, 'wp-debug')"><?php echo esc_html(__('WordPress Debug', 'enhanced-debug-logger')); ?></a>
                    <a href="#combined" class="nav-tab" onclick="switchTab(event, 'combined')"><?php echo esc_html(__('Combined View', 'enhanced-debug-logger')); ?></a>
                </nav>

                <div id="settings" class="tab-content">
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('enhanced_debug_logger');
                        do_settings_sections('enhanced_debug_logger');
                        ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php echo esc_html(__('Enable Debug Logging', 'enhanced-debug-logger')); ?></th>
                                <td><input type="checkbox" name="edl_enabled" value="1" <?php checked('1', get_option('edl_enabled')); ?> /></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html(__('Enable WordPress Debug Mode', 'enhanced-debug-logger')); ?></th>
                                <td>
                                    <input type="checkbox" name="edl_enable_wp_debug" value="1" <?php checked('1', get_option('edl_enable_wp_debug')); ?> />
                                    <p class="description"><?php echo esc_html(__('This will load WP_DEBUG LOGS, normaly not needed, please modify wp-config.php to enable WP_DEBUG, WP_DEBUG_LOG to use it. .', 'enhanced-debug-logger')); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html(__('Log AJAX Requests', 'enhanced-debug-logger')); ?></th>
                                <td><input type="checkbox" name="edl_log_ajax" value="1" <?php checked('1', get_option('edl_log_ajax')); ?> /></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html(__('Refresh Interval (ms)', 'enhanced-debug-logger')); ?></th>
                                <td><input type="number" name="edl_refresh_interval" value="<?php echo esc_attr(get_option('edl_refresh_interval', '1000')); ?>" min="100" step="100" /></td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html(__('Max Log Size (MB)', 'enhanced-debug-logger')); ?></th>
                                <td><input type="number" name="edl_max_log_size" value="<?php echo esc_attr(get_option('edl_max_log_size', '10')); ?>" min="1" max="100" /></td>
                            </tr>
                        </table>
                        <?php submit_button(__('Save Settings', 'enhanced-debug-logger')); ?>
                    </form>
                </div>

                <div id="logs" class="tab-content" style="display: none;">
                    <div style="margin-bottom: 15px;">
                        <button id="clean-log" class="button button-secondary"><?php echo esc_html(__('Clean Log', 'enhanced-debug-logger')); ?></button>
                        <button id="copy-log" class="button button-secondary"><?php echo esc_html(__('Copy to Clipboard', 'enhanced-debug-logger')); ?></button>
                        <button id="refresh-log" class="button button-secondary"><?php echo esc_html(__('Refresh', 'enhanced-debug-logger')); ?></button>
                    </div>
                    <div id="debug-log-content" class="log-container"></div>
                </div>

                <div id="wp-debug" class="tab-content" style="display: none;">
                    <div style="margin-bottom: 15px;">
                        <button id="clean-wp-log" class="button button-secondary"><?php echo esc_html(__('Clean WP Debug Log', 'enhanced-debug-logger')); ?></button>
                        <button id="copy-wp-log" class="button button-secondary"><?php echo esc_html(__('Copy to Clipboard', 'enhanced-debug-logger')); ?></button>
                        <button id="refresh-wp-log" class="button button-secondary"><?php echo esc_html(__('Refresh', 'enhanced-debug-logger')); ?></button>
                    </div>
                    <div id="wp-debug-log-content" class="log-container"></div>
                </div>

                <div id="combined" class="tab-content" style="display: none;">
                    <div style="margin-bottom: 15px;">
                        <button id="copy-combined-log" class="button button-secondary"><?php echo esc_html(__('Copy to Clipboard', 'enhanced-debug-logger')); ?></button>
                        <button id="refresh-combined-log" class="button button-secondary"><?php echo esc_html(__('Refresh', 'enhanced-debug-logger')); ?></button>
                        <label>
                            <input type="checkbox" id="auto-scroll"> <?php echo esc_html(__('Auto-scroll to bottom', 'enhanced-debug-logger')); ?>
                        </label>
                        <select id="log-filter-select">
                            <option value="all">Alle</option>
                            <option value="fatal">Fatal Errors</option>
                            <option value="error">Errors</option>
                            <option value="warning">Warnings</option>
                            <option value="notice">Notices</option>
                        </select>
                    </div>
                    <div id="combined-log-content" class="log-container"></div>
                </div>
            </div>
        </div>

        <style>
            .log-container {
                max-height: 500px;
                overflow-y: auto;
                border: 1px solid #ccc;
                padding: 15px;
                background: #f9f9f9;
                font-family: 'Courier New', monospace;
                font-size: 12px;
                white-space: pre-wrap;
            }
            .tab-content {
                padding: 20px 0;
            }
            .nav-tab-active {
                background: #fff !important;
                border-bottom: 1px solid #fff !important;
            }
            .error-line {
                background-color: #ffebee;
                border-left: 4px solid #f44336;
                padding-left: 8px;
            }
            .warning-line {
                background-color: #fff3e0;
                border-left: 4px solid #ff9800;
                padding-left: 8px;
            }
            .fatal-line {
                background-color: #fce4ec;
                border-left: 4px solid #e91e63;
                padding-left: 8px;
                font-weight: bold;
            }





    
    /* Ubuntu Terminal Style */
    @import url('https://fonts.googleapis.com/css2?family=Ubuntu+Mono:wght@400;700&display=swap');
    
    .ubuntu-terminal {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        height: 40px;
        background-color: #300A24; /* Tiefer, dunkler Purpur-Hintergrund */
        border-top: 2px solid #555753;
        overflow: hidden;
        transition: height 0.3s ease-in-out;
        z-index: 999999;
        color: #F8F8F8; /* Helles Grau für Text */
        font-family: 'Ubuntu Mono', monospace;
        box-shadow: 0 -4px 10px rgba(0, 0, 0, 0.5);
    }

    .terminal-header {
        padding: 10px;
        cursor: pointer;
        background-color: #2D2D2D;
        text-align: center;
        font-weight: bold;
        color: #E8E8E8;
        border-bottom: 1px solid #444;
    }

    .ubuntu-terminal:not(.minimized) {
        height: 400px;
    }
    
    .terminal-content {
        padding: 15px;
        height: calc(100% - 40px);
        overflow: auto;
        display: none;
    }
    
    .terminal-toolbar {
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        background-color: #2D2D2D;
        padding: 5px;
        border-radius: 4px;
    }
    
    .terminal-button {
        font-family: 'Ubuntu Mono', monospace;
        font-size: 12px;
        background-color: #3A3A3A;
        color: #E8E8E8;
        border: 1px solid #5A5A5A;
        padding: 6px 10px;
        cursor: pointer;
        border-radius: 3px;
        margin-right: 5px;
        transition: background-color 0.2s;
    }
    
    .terminal-button:hover {
        background-color: #4A4A4A;
    }

    .terminal-select {
        font-family: 'Ubuntu Mono', monospace;
        font-size: 12px;
        background-color: #3A3A3A;
        color: #E8E8E8;
        border: 1px solid #5A5A5A;
        padding: 5px;
        border-radius: 3px;
    }
    
    .terminal-log-output {
        max-height: 300px;
        overflow-y: auto;
        background-color: #2D2D2D;
        padding: 10px;
        border-radius: 3px;
        font-size: 12px;
        white-space: pre-wrap;
    }

    /* Log-Farben für den Terminal-Stil */
    .log-fatal {
        color: #FF5555; /* Leuchtendes Rot */
    }
    
    .log-error {
        color: #F8B400; /* Gelb-Orange */
    }
    
    .log-warning {
        color: #FFE100; /* Helles Gelb */
    }
    
    .log-notice {
        color: #A3C9FF; /* Hellblau */
    }
    
    .log-info {
        color: #E8E8E8; /* Helles Grau */
    }
</style>
        </style>

        <script>
            jQuery(document).ready(function($) {
                var refreshInterval = <?php echo intval(get_option('edl_refresh_interval', '1000')); ?>;
                var autoRefreshTimer;

                function switchTab(evt, tabName) {
                    var i, tabcontent, tablinks;
                    tabcontent = document.getElementsByClassName("tab-content");
                    for (i = 0; i < tabcontent.length; i++) {
                        tabcontent[i].style.display = "none";
                    }
                    tablinks = document.getElementsByClassName("nav-tab");
                    for (i = 0; i < tablinks.length; i++) {
                        tablinks[i].className = tablinks[i].className.replace(" nav-tab-active", "");
                    }
                    document.getElementById(tabName).style.display = "block";
                    evt.currentTarget.className += " nav-tab-active";

                    clearInterval(autoRefreshTimer);
                    if (tabName === 'logs') {
                        refreshLogContent();
                        autoRefreshTimer = setInterval(refreshLogContent, refreshInterval);
                    } else if (tabName === 'wp-debug') {
                        refreshWPLogContent();
                        autoRefreshTimer = setInterval(refreshWPLogContent, refreshInterval);
                    } else if (tabName === 'combined') {
                        refreshCombinedLogContent();
                        autoRefreshTimer = setInterval(refreshCombinedLogContent, refreshInterval);
                    }
                }
                window.switchTab = switchTab;

                function formatLogContent(content) {
                    return content.split('\n').filter(line => line.trim() !== '').map(function(line) {
                        if (line.includes('Fatal Error') || line.includes('FATAL')) {
                            return '<div class="fatal-line">' + escapeHtml(line) + '</div>';
                        } else if (line.includes('Error') || line.includes('ERROR') || line.includes('Parse Error')) {
                            return '<div class="error-line">' + escapeHtml(line) + '</div>';
                        } else if (line.includes('Warning') || line.includes('WARNING')) {
                            return '<div class="warning-line">' + escapeHtml(line) + '</div>';
                        }
                        return '<div>' + escapeHtml(line) + '</div>';
                    }).reverse().join('');
                }

                function filterLogContent(content, filterType) {
                    if (filterType === 'all') {
                        return content;
                    }
                    var filteredLines = content.split('\n').filter(function(line) {
                        var logType;
                        if (line.includes('Fatal Error') || line.includes('FATAL')) {
                            logType = 'fatal';
                        } else if (line.includes('Error') || line.includes('ERROR') || line.includes('Parse Error')) {
                            logType = 'error';
                        } else if (line.includes('Warning') || line.includes('WARNING')) {
                            logType = 'warning';
                        } else if (line.includes('Notice') || line.includes('NOTICE')) {
                            logType = 'notice';
                        } else {
                            logType = 'other';
                        }
                        return logType === filterType;
                    });
                    return filteredLines.join('\n');
                }

                function escapeHtml(text) {
                    var map = {
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#039;'
                    };
                    return text.replace(/[&<>"']/g, function(m) {
                        return map[m];
                    });
                }

                function refreshLogContent() {
                    $.ajax({
                        url: ajaxurl,
                        data: {
                            action: 'refresh_debug_log'
                        },
                        success: function(response) {
                            $('#debug-log-content').html(formatLogContent(response));
                        }
                    });
                }

                function refreshWPLogContent() {
                    $.ajax({
                        url: ajaxurl,
                        data: {
                            action: 'get_wp_debug_log'
                        },
                        success: function(response) {
                            $('#wp-debug-log-content').html(formatLogContent(response));
                        }
                    });
                }

                function refreshCombinedLogContent() {
                    $.ajax({
                        url: ajaxurl,
                        data: {
                            action: 'get_combined_logs'
                        },
                        success: function(response) {
                            var filter = $('#log-filter-select').val();
                            var filteredResponse = filterLogContent(response, filter);
                            var container = $('#combined-log-content');
                            container.html(formatLogContent(filteredResponse));

                            if ($('#auto-scroll').is(':checked')) {
                                container.scrollTop(container[0].scrollHeight);
                            }
                        }
                    });
                }

                // Copy functions
                function copyToClipboard(text) {
                    navigator.clipboard.writeText(text).then(function() {
                        alert('Log copied to clipboard!');
                    }, function(err) {
                        var textArea = document.createElement("textarea");
                        textArea.value = text;
                        document.body.appendChild(textArea);
                        textArea.focus();
                        textArea.select();
                        try {
                            document.execCommand('copy');
                            alert('Log copied to clipboard!');
                        } catch (err) {
                            alert('Copy failed. Please select and copy manually.');
                        }
                        document.body.removeChild(textArea);
                    });
                }

                // Event handlers
                $('#clean-log').click(function() {
                    if (confirm('<?php echo esc_js(__('Are you sure you want to clean the log?', 'enhanced-debug-logger')); ?>')) {
                        $.ajax({
                            url: ajaxurl,
                            data: {
                                action: 'clean_debug_log'
                            },
                            success: function() {
                                refreshLogContent();
                            }
                        });
                    }
                });

                $('#clean-wp-log').click(function() {
                     if (confirm('<?php echo esc_js(__('Are you sure you want to clean the WP debug log?', 'enhanced-debug-logger')); ?>')) {
                        $.ajax({
                            url: ajaxurl,
                            data: {
                                action: 'clean_wp_debug_log'
                            },
                            success: function() {
                                refreshWPLogContent();
                            }
                        });
                    }
                });
                
                $('#copy-log').click(function() {
                    var text = $('#debug-log-content').text();
                    copyToClipboard(text);
                });

                $('#copy-wp-log').click(function() {
                    var text = $('#wp-debug-log-content').text();
                    copyToClipboard(text);
                });

                $('#copy-combined-log').click(function() {
                    var text = $('#combined-log-content').text();
                    copyToClipboard(text);
                });

                $('#refresh-log').click(refreshLogContent);
                $('#refresh-wp-log').click(refreshWPLogContent);
                $('#refresh-combined-log').click(refreshCombinedLogContent);
                $('#log-filter-select').change(refreshCombinedLogContent);

                // Initial load
                refreshLogContent();
            });
        </script>
        <?php
    }

    public function refresh_log()
    {
        if (file_exists($this->log_file)) {
            $log_content = file_get_contents($this->log_file);
            echo $log_content;
        } else {
            echo esc_html(__('No log file found.', 'enhanced-debug-logger'));
        }
        wp_die();
    }

    public function get_wp_debug_log()
    {
        if (file_exists($this->wp_debug_log)) {
            $log_content = file_get_contents($this->wp_debug_log);
            echo $log_content;
        } else {
            echo esc_html(__('No WordPress debug log found. Make sure WP_DEBUG_LOG is enabled.', 'enhanced-debug-logger'));
        }
        wp_die();
    }

    public function get_combined_logs()
    {
        $combined_log = '';

        if (file_exists($this->log_file)) {
            $our_log = file_get_contents($this->log_file);
            $combined_log .= "=== " . __('ENHANCED DEBUG LOG', 'enhanced-debug-logger') . " ===\n" . $our_log . "\n\n";
        }

        if (file_exists($this->wp_debug_log)) {
            $wp_log = file_get_contents($this->wp_debug_log);
            $combined_log .= "=== " . __('WORDPRESS DEBUG LOG', 'enhanced-debug-logger') . " ===\n" . $wp_log;
        }

        if (empty($combined_log)) {
            $combined_log = __('No log files found.', 'enhanced-debug-logger');
        }

        echo $combined_log;
        wp_die();
    }

    public function clean_log()
    {
        file_put_contents($this->log_file, '');
        wp_die();
    }

    public function clean_wp_debug_log() {
        if (file_exists($this->wp_debug_log)) {
            file_put_contents($this->wp_debug_log, '');
        }
        wp_die();
    }

   public function add_console()
{
    if (current_user_can('manage_options') && get_option('edl_enabled', '0') === '1') {
        $refresh_interval = intval(get_option('edl_refresh_interval', '1000'));
        ?>
        <div id="edl-console" class="ubuntu-terminal minimized">
            <div class="terminal-header" onclick="edlToggleConsole()">
                🔍 <?php echo esc_html(__('Enhanced Debug Console (Click to expand)', 'enhanced-debug-logger')); ?> 🔍
            </div>
            <div id="edl-console-content" class="terminal-content">
                <div class="terminal-toolbar">
                    <button id="edl-clear-log" class="terminal-button clear-btn"><?php echo esc_html(__('Clear', 'enhanced-debug-logger')); ?></button>
                    <button id="edl-copy-log" class="terminal-button copy-btn"><?php echo esc_html(__('Copy', 'enhanced-debug-logger')); ?></button>
                    <button id="edl-toggle-auto" class="terminal-button toggle-btn"><?php echo esc_html(__('Auto: ON', 'enhanced-debug-logger')); ?></button>
                    <select id="edl-log-filter-select" class="terminal-select">
                        <option value="all">Alle</option>
                        <option value="fatal">Fatal Errors</option>
                        <option value="error">Errors</option>
                        <option value="warning">Warnings</option>
                        <option value="notice">Notices</option>
                    </select>
                </div>
                <div id="edl-log-content" class="terminal-log-output"></div>
            </div>
        </div>

        <script>
            var edlAutoRefresh = true;
            var edlRefreshTimer;

            function edlToggleConsole() {
                var consoleDiv = document.getElementById('edl-console');
                var consoleContent = document.getElementById('edl-console-content');
                
                if (consoleDiv.classList.contains('minimized')) {
                    consoleDiv.classList.remove('minimized');
                } else {
                    consoleDiv.classList.add('minimized');
                }
            }

            function edlRefreshConsoleContent() {
                jQuery.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    data: {
                        action: 'get_combined_logs'
                    },
                    success: function(response) {
                        var logs = response.split('\n').filter(line => line.trim() !== '');
                        var filter = jQuery('#edl-log-filter-select').val();
                        var filteredLogs = edlFilterLogs(logs, filter);
                        var formatted = filteredLogs.map(function(line) {
                            if (line.includes('Fatal') || line.includes('FATAL')) {
                                return '<div class="log-fatal">' + line + '</div>';
                            } else if (line.includes('Error') || line.includes('ERROR') || line.includes('Parse Error')) {
                                return '<div class="log-error">' + line + '</div>';
                            } else if (line.includes('Warning') || line.includes('WARNING')) {
                                return '<div class="log-warning">' + line + '</div>';
                            } else if (line.includes('Notice') || line.includes('NOTICE')) {
                                return '<div class="log-notice">' + line + '</div>';
                            }
                            return '<div class="log-info">' + line + '</div>';
                        }).reverse().join('');
                        jQuery('#edl-log-content').html(formatted);
                    }
                });
            }

            function edlFilterLogs(logs, filter) {
                if (filter === 'all') {
                    return logs;
                }
                var filterKey = '[' + filter.toUpperCase() + ']';
                return logs.filter(line => line.includes(filterKey));
            }

            function edlStartAutoRefresh() {
                edlRefreshTimer = setInterval(edlRefreshConsoleContent, <?php echo $refresh_interval; ?>);
            }

            function edlStopAutoRefresh() {
                clearInterval(edlRefreshTimer);
            }

            jQuery(document).ready(function($) {
                edlRefreshConsoleContent();
                edlStartAutoRefresh();

                $('#edl-clear-log').click(function() {
                    if (confirm('Are you sure you want to clear the debug log?')) {
                        $.ajax({
                            url: '<?php echo admin_url('admin-ajax.php'); ?>',
                            data: {
                                action: 'clean_debug_log'
                            },
                            success: function() {
                                edlRefreshConsoleContent();
                            }
                        });
                    }
                });

                $('#edl-copy-log').click(function() {
                    var text = $('#edl-log-content').text();
                    navigator.clipboard.writeText(text).then(function() {
                        alert('Log copied to clipboard!');
                    });
                });

                $('#edl-toggle-auto').click(function() {
                    edlAutoRefresh = !edlAutoRefresh;
                    $(this).text('Auto: ' + (edlAutoRefresh ? 'ON' : 'OFF'));
                    if (edlAutoRefresh) {
                        edlStartAutoRefresh();
                    } else {
                        edlStopAutoRefresh();
                    }
                });

                $('#edl-log-filter-select').change(edlRefreshConsoleContent);
            });
        </script>
        <?php
        }
    }

    public function enqueue_admin_scripts($hook)
    {
        if ('tools_page_enhanced-debug-logger' !== $hook) {
            return;
        }
        wp_enqueue_script('jquery');
    }
}

// Initialize the plugin
EnhancedDebugLogger::getInstance();
