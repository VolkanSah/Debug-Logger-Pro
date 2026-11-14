<?php
/**
 * Plugin Name:       Enhanced Interaction & Debug Logger Pro
 * Plugin URI:        https://github.com/volkansah/interaction-debug-logger
 * Description:       Lightweight live log viewer for PHP errors, fatal errors and requests, right inside WordPress.
 * Version:           4.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Volkan Kücükbudak
 * Author URI:        https://aicodecraft.io
 * License:           DBAD
 * Text Domain:       enhanced-debug-logger
 * Domain Path:       /languages
 */

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

final class EnhancedDebugLogger
{
    public const VERSION = '4.0.0';

    private const LEVELS      = ['fatal', 'error', 'warning', 'notice', 'deprecated', 'request'];
    private const TAIL_BYTES  = 524288; // read at most the last 512 KB of each log file
    private const MAX_ENTRIES = 500;    // entries sent to the browser per refresh

    private static ?self $instance = null;
    private static bool $busy      = false; // stops the logger from logging its own problems

    private string $dir;
    private string $key = '';
    private bool $enabled;
    private bool $log_requests;
    private bool $log_ajax;
    private bool $log_ip;
    private int $max_bytes;
    private bool $dir_ready  = false;
    private bool $rotated    = false;
    private string $page_hook = '';
    private string $mode      = '';
    /** @var callable|null */
    private $prev_handler = null;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->dir          = WP_CONTENT_DIR . '/edl-logs';
        $this->enabled      = '1' === (string) get_option('edl_enabled', '0');
        $this->log_requests = '1' === (string) get_option('edl_log_requests', '1');
        $this->log_ajax     = '1' === (string) get_option('edl_log_ajax', '0');
        $this->log_ip       = '1' === (string) get_option('edl_log_ip', '0');
        $this->max_bytes    = max(1, (int) get_option('edl_max_log_size', '5')) * 1048576;

        if ($this->enabled) {
            $this->key(); // create the random file name now, not in the middle of an error
            $this->prev_handler = set_error_handler([$this, 'handle_error']);
            register_shutdown_function([$this, 'handle_shutdown']);
        }

        add_action('init', [$this, 'load_textdomain']);
        add_action('init', [$this, 'log_request']);
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_front']);
        add_action('admin_footer', [$this, 'print_root']);
        add_action('wp_footer', [$this, 'print_root']);
        add_action('wp_ajax_edl_logs', [$this, 'ajax_logs']);
        add_action('wp_ajax_edl_clear', [$this, 'ajax_clear']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'action_links']);
    }

    public function load_textdomain(): void
    {
        load_plugin_textdomain('enhanced-debug-logger', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /* --------------------------------------------------------------------
     * Capturing
     * ------------------------------------------------------------------ */

    public function handle_error($errno, $errstr, $errfile = '', $errline = 0)
    {
        // Respect error_reporting() and the @ operator.
        if (error_reporting() & $errno) {
            $this->log($this->level_for((int) $errno), (string) $errstr, (string) $errfile, (int) $errline);
        }

        // Hand over to whoever was registered before us, or to PHP's default handling.
        if (is_callable($this->prev_handler)) {
            return call_user_func($this->prev_handler, $errno, $errstr, $errfile, $errline);
        }
        return false;
    }

    public function handle_shutdown(): void
    {
        $error = error_get_last();
        // Only errors that never reach handle_error(). E_USER_ERROR is already logged there.
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
            $this->log('fatal', (string) $error['message'], (string) $error['file'], (int) $error['line']);
        }
    }

    public function log_request(): void
    {
        if (!$this->enabled || !$this->log_requests) {
            return;
        }
        if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return;
        }

        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if (wp_doing_ajax()) {
            // Never log our own polling, and other AJAX only when asked to.
            if (0 === strpos($action, 'edl_') || !$this->log_ajax) {
                return;
            }
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : 'GET';
        $uri    = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path   = explode('?', $uri, 2)[0]; // query strings can contain tokens, so they are never stored

        $message = $method . ' ' . $path;
        if (wp_doing_ajax() && '' !== $action) {
            $message .= ' (action: ' . $action . ')';
        }
        $this->log('request', $message);
    }

    private function level_for(int $errno): string
    {
        return match (true) {
            (bool) ($errno & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_PARSE))                       => 'fatal',
            (bool) ($errno & (E_USER_ERROR | E_RECOVERABLE_ERROR))                                         => 'error',
            (bool) ($errno & (E_WARNING | E_CORE_WARNING | E_COMPILE_WARNING | E_USER_WARNING))            => 'warning',
            (bool) ($errno & (E_DEPRECATED | E_USER_DEPRECATED))                                           => 'deprecated',
            default                                                                                        => 'notice',
        };
    }

    /* --------------------------------------------------------------------
     * Writing
     * ------------------------------------------------------------------ */

    private function log(string $level, string $message, string $file = '', int $line = 0): void
    {
        if (!$this->enabled || self::$busy) {
            return;
        }
        self::$busy = true;

        try {
            if (!$this->dir_ready) {
                $this->dir_ready = $this->ensure_dir();
            }
            if (!$this->dir_ready) {
                return;
            }

            $entry = ['ts' => time(), 'l' => $level, 'm' => substr($message, 0, 2000)];
            if ('' !== $file) {
                $entry['f'] = $file;
                $entry['n'] = $line;
            }
            if ($this->log_ip) {
                $ip = isset($_SERVER['REMOTE_ADDR']) ? filter_var(wp_unslash($_SERVER['REMOTE_ADDR']), FILTER_VALIDATE_IP) : false;
                if ($ip) {
                    $entry['ip'] = $ip;
                }
            }

            // One JSON object per line: newlines inside messages can't forge extra log lines.
            $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (false === $json) {
                return;
            }

            $file_path = $this->plugin_log();
            $this->rotate_if_needed($file_path);
            @file_put_contents($file_path, $json . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // A logger must never take the site down.
        } finally {
            self::$busy = false;
        }
    }

    private function key(): string
    {
        if ('' === $this->key) {
            $key = (string) get_option('edl_key', '');
            if (!preg_match('/^[a-f0-9]{16}$/', $key)) {
                $key = bin2hex(random_bytes(8));
                update_option('edl_key', $key, false);
            }
            $this->key = $key;
        }
        return $this->key;
    }

    private function plugin_log(): string
    {
        return $this->dir . '/edl-' . $this->key() . '.log';
    }

    private function ensure_dir(): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            return false;
        }

        $htaccess = $this->dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents(
                $htaccess,
                "# Debug Logger: no direct access to log files\n"
                . "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
            );
        }
        $index = $this->dir . '/index.php';
        if (!is_file($index)) {
            @file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        return is_writable($this->dir);
    }

    private function rotate_if_needed(string $file): void
    {
        if ($this->rotated) {
            return;
        }
        $this->rotated = true; // check once per request, that's enough

        clearstatcache(true, $file);
        if (is_file($file) && filesize($file) > $this->max_bytes) {
            @rename($file, $file . '.old'); // keeps exactly one old file around
        }
    }

    /* --------------------------------------------------------------------
     * Reading
     * ------------------------------------------------------------------ */

    /** Returns the lines from the end of a file without loading all of it. */
    private function tail(string $file): array
    {
        if ('' === $file || !is_file($file) || !is_readable($file)) {
            return [];
        }
        $size = filesize($file);
        if (!$size) {
            return [];
        }
        $handle = @fopen($file, 'rb');
        if (!$handle) {
            return [];
        }
        $start = max(0, $size - self::TAIL_BYTES);
        fseek($handle, $start);
        $data = (string) fread($handle, $size - $start);
        fclose($handle);

        $lines = preg_split('/\r\n|\n|\r/', $data);
        if ($start > 0) {
            array_shift($lines); // first line is probably cut in half
        }
        return array_values(array_filter($lines, static fn($l) => '' !== trim((string) $l)));
    }

    private function entry(int $ts, string $level, string $src, string $msg, string $loc, string $ip = ''): array
    {
        return [
            'ts'    => $ts,
            'time'  => $ts > 0 ? (string) wp_date('Y-m-d H:i:s', $ts) : '',
            'level' => $level,
            'src'   => $src,
            'msg'   => $msg,
            'loc'   => $loc,
            'ip'    => $ip,
        ];
    }

    private function shorten(string $path): string
    {
        $path = wp_normalize_path($path);
        $root = wp_normalize_path(ABSPATH);
        return 0 === strpos($path, $root) ? substr($path, strlen($root)) : $path;
    }

    private function read_plugin(): array
    {
        $out = [];
        foreach ($this->tail($this->plugin_log()) as $line) {
            $d = json_decode($line, true);
            if (!is_array($d) || !isset($d['ts'], $d['l'], $d['m'])) {
                continue;
            }
            $level = in_array($d['l'], self::LEVELS, true) ? $d['l'] : 'notice';
            $loc   = '';
            if (!empty($d['f'])) {
                $loc = $this->shorten((string) $d['f']) . (!empty($d['n']) ? ':' . (int) $d['n'] : '');
            }
            $out[] = $this->entry((int) $d['ts'], $level, 'plugin', (string) $d['m'], $loc, isset($d['ip']) ? (string) $d['ip'] : '');
        }
        return $out;
    }

    private function read_wp(): array
    {
        $out = [];
        foreach ($this->tail($this->wp_log_path()) as $line) {
            $ts = false;
            if (preg_match('/^\[([^\]]+)\]\s?(.*)$/s', $line, $m)) {
                $ts = strtotime($m[1]);
            }

            // No timestamp: it's a stack trace line or other continuation of the previous entry.
            if (false === $ts) {
                if ($out) {
                    $last = count($out) - 1;
                    if (strlen($out[$last]['msg']) < 4000) {
                        $out[$last]['msg'] .= "\n" . $line;
                    }
                } else {
                    $out[] = $this->entry(0, 'notice', 'wp', $line, '');
                }
                continue;
            }

            $msg   = $m[2];
            $level = 'notice';
            if (preg_match('/^PHP (Fatal error|Parse error|Warning|Notice|Deprecated|Strict Standards):\s*(.*)$/s', $msg, $mm)) {
                $level = match ($mm[1]) {
                    'Fatal error', 'Parse error' => 'fatal',
                    'Warning'                    => 'warning',
                    'Deprecated'                 => 'deprecated',
                    default                      => 'notice',
                };
                $msg = $mm[2];
            } elseif (false !== stripos($msg, 'database error')) {
                $level = 'error';
            }

            $loc = '';
            if (preg_match('/^(.*) in (.+?) on line (\d+)\s*$/s', $msg, $lm)) {
                $msg = $lm[1];
                $loc = $this->shorten($lm[2]) . ':' . $lm[3];
            }
            $out[] = $this->entry((int) $ts, $level, 'wp', $msg, $loc);
        }
        return $out;
    }

    /** Path of WordPress' own debug log, or '' when WP_DEBUG_LOG is off. */
    private function wp_log_path(): string
    {
        if (!defined('WP_DEBUG_LOG')) {
            return '';
        }
        $value = WP_DEBUG_LOG;
        if (is_string($value)) {
            $lower = strtolower($value);
            if (in_array($lower, ['true', '1'], true)) {
                return WP_CONTENT_DIR . '/debug.log';
            }
            return in_array($lower, ['false', '0', ''], true) ? '' : $value;
        }
        return $value ? WP_CONTENT_DIR . '/debug.log' : '';
    }

    /* --------------------------------------------------------------------
     * AJAX (admins only, nonce required)
     * ------------------------------------------------------------------ */

    private function ajax_guard(): void
    {
        if (!current_user_can('manage_options') || !check_ajax_referer('edl_ajax', 'nonce', false)) {
            wp_send_json_error(['message' => __('Not allowed.', 'enhanced-debug-logger')], 403);
        }
    }

    private function requested_source(): string
    {
        $source = isset($_REQUEST['source']) ? sanitize_key(wp_unslash($_REQUEST['source'])) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification
        return in_array($source, ['all', 'plugin', 'wp'], true) ? $source : 'all';
    }

    public function ajax_logs(): void
    {
        $this->ajax_guard();
        $source = $this->requested_source();

        $entries = [];
        if ('wp' !== $source) {
            $entries = array_merge($entries, $this->read_plugin());
        }
        if ('plugin' !== $source) {
            $entries = array_merge($entries, $this->read_wp());
        }

        usort($entries, static fn($a, $b) => $a['ts'] <=> $b['ts']); // stable sort since PHP 8.0
        $entries = array_slice($entries, -self::MAX_ENTRIES);

        $counts = array_fill_keys(self::LEVELS, 0);
        foreach ($entries as $e) {
            $counts[$e['level']]++;
        }

        wp_send_json_success([
            'entries' => $entries,
            'counts'  => $counts,
            'wpLog'   => '' !== $this->wp_log_path(),
        ]);
    }

    public function ajax_clear(): void
    {
        $this->ajax_guard();

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
        if ('POST' !== $method) {
            wp_send_json_error(['message' => 'POST required.'], 405);
        }

        $source = $this->requested_source();
        $ok     = true;

        if ('wp' !== $source) {
            $file = $this->plugin_log();
            if (is_file($file)) {
                $ok = (false !== @file_put_contents($file, '', LOCK_EX)) && $ok;
            }
            if (is_file($file . '.old')) {
                @unlink($file . '.old');
            }
        }
        if ('plugin' !== $source) {
            $file = $this->wp_log_path();
            if ('' !== $file && is_file($file)) {
                $ok = (false !== @file_put_contents($file, '', LOCK_EX)) && $ok;
            }
        }

        if ($ok) {
            wp_send_json_success();
        }
        wp_send_json_error(['message' => __('Could not clear the log.', 'enhanced-debug-logger')], 500);
    }

    /* --------------------------------------------------------------------
     * Assets
     * ------------------------------------------------------------------ */

    private function console_wanted(): bool
    {
        return $this->enabled
            && '1' === (string) get_option('edl_console', '1')
            && current_user_can('manage_options')
            && !is_customize_preview();
    }

    public function enqueue_admin(string $hook): void
    {
        if ($hook === $this->page_hook) {
            $this->enqueue_assets('page');
        } elseif ($this->console_wanted()) {
            $this->enqueue_assets('console');
        }
    }

    public function enqueue_front(): void
    {
        if ($this->console_wanted()) {
            $this->enqueue_assets('console');
        }
    }

    private function enqueue_assets(string $mode): void
    {
        $this->mode = $mode;

        wp_enqueue_style('edl', plugins_url('assets/edl.css', __FILE__), [], self::VERSION);
        wp_register_script('edl', plugins_url('assets/edl.js', __FILE__), [], self::VERSION, true);
        wp_add_inline_script(
            'edl',
            'window.EDL = ' . wp_json_encode($this->js_config($mode), JSON_HEX_TAG | JSON_HEX_AMP) . ';',
            'before'
        );
        wp_enqueue_script('edl');
    }

    private function js_config(string $mode): array
    {
        $td = 'enhanced-debug-logger';
        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('edl_ajax'),
            'refresh' => max(1, (int) get_option('edl_refresh', '2')) * 1000,
            'mode'    => $mode,
            'enabled' => $this->enabled,
            'i18n'    => [
                'title'         => __('Debug Logger', $td),
                'source'        => __('Source', $td),
                'sources'       => [
                    'all'    => __('All logs', $td),
                    'plugin' => __('Plugin log', $td),
                    'wp'     => __('WordPress debug.log', $td),
                ],
                'levelsLabel'   => __('Show levels', $td),
                'levels'        => [
                    'fatal'      => __('Fatal', $td),
                    'error'      => __('Error', $td),
                    'warning'    => __('Warning', $td),
                    'notice'     => __('Notice', $td),
                    'deprecated' => __('Deprecated', $td),
                    'request'    => __('Request', $td),
                ],
                'live'          => __('Live', $td),
                'paused'        => __('Paused', $td),
                'search'        => __('Search', $td),
                'copy'          => __('Copy', $td),
                'copied'        => __('Copied to clipboard.', $td),
                'copyFailed'    => __('Copy failed. Select the text and copy it by hand.', $td),
                'download'      => __('Download', $td),
                'clear'         => __('Clear', $td),
                'clearConfirm'  => __('Delete all entries from the selected log?', $td),
                'cleared'       => __('Log cleared.', $td),
                'clearFailed'   => __('Could not clear the log. Check the file permissions.', $td),
                'of'            => __('of', $td),
                'entries'       => __('entries', $td),
                'issues'        => __('issues', $td),
                'empty'         => __('Nothing logged yet. Errors show up here the moment they happen.', $td),
                'emptyFiltered' => __('No entries match the current filters.', $td),
                'loadError'     => __('Could not load the log. Reload the page and try again.', $td),
            ],
        ];
    }

    public function print_root(): void
    {
        if ('console' === $this->mode) {
            echo '<div id="edl-app" data-mode="console"></div>';
        }
    }

    /* --------------------------------------------------------------------
     * Admin pages
     * ------------------------------------------------------------------ */

    public function add_menu(): void
    {
        $this->page_hook = (string) add_management_page(
            __('Debug Logger', 'enhanced-debug-logger'),
            __('Debug Logger', 'enhanced-debug-logger'),
            'manage_options',
            'enhanced-debug-logger',
            [$this, 'render_page']
        );
    }

    public function action_links(array $links): array
    {
        $url = admin_url('tools.php?page=enhanced-debug-logger');
        array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Logs', 'enhanced-debug-logger') . '</a>');
        return $links;
    }

    public function register_settings(): void
    {
        $bool = static fn(string $default): array => [
            'type'              => 'string',
            'default'           => $default,
            'sanitize_callback' => [self::class, 'sanitize_bool'],
        ];

        register_setting('edl_settings', 'edl_enabled', $bool('0'));
        register_setting('edl_settings', 'edl_log_requests', $bool('1'));
        register_setting('edl_settings', 'edl_log_ajax', $bool('0'));
        register_setting('edl_settings', 'edl_log_ip', $bool('0'));
        register_setting('edl_settings', 'edl_console', $bool('1'));
        register_setting('edl_settings', 'edl_refresh', [
            'type'              => 'string',
            'default'           => '2',
            'sanitize_callback' => static function ($v): string {
                $n = absint($v);
                return (string) ($n < 1 ? 2 : min(60, $n));
            },
        ]);
        register_setting('edl_settings', 'edl_max_log_size', [
            'type'              => 'string',
            'default'           => '5',
            'sanitize_callback' => static function ($v): string {
                $n = absint($v);
                return (string) ($n < 1 ? 5 : min(100, $n));
            },
        ]);
    }

    public static function sanitize_bool($value): string
    {
        return '1' === (string) $value ? '1' : '0';
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tab  = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'logs'; // phpcs:ignore WordPress.Security.NonceVerification
        $tab  = 'settings' === $tab ? 'settings' : 'logs';
        $base = admin_url('tools.php?page=enhanced-debug-logger');

        echo '<div class="wrap edl-wrap">';
        echo '<h1>' . esc_html__('Debug Logger', 'enhanced-debug-logger') . '</h1>';

        if (is_file(WP_CONTENT_DIR . '/enhanced-debug-log.txt')) {
            echo '<div class="notice notice-warning inline"><p>'
                . esc_html__('Found the old log file wp-content/enhanced-debug-log.txt from version 3. It may be readable from the internet. Delete it via FTP or your file manager.', 'enhanced-debug-logger')
                . '</p></div>';
        }

        echo '<nav class="nav-tab-wrapper">';
        printf(
            '<a href="%s" class="nav-tab%s">%s</a>',
            esc_url($base),
            'logs' === $tab ? ' nav-tab-active' : '',
            esc_html__('Logs', 'enhanced-debug-logger')
        );
        printf(
            '<a href="%s" class="nav-tab%s">%s</a>',
            esc_url(add_query_arg('tab', 'settings', $base)),
            'settings' === $tab ? ' nav-tab-active' : '',
            esc_html__('Settings', 'enhanced-debug-logger')
        );
        echo '</nav>';

        if ('settings' === $tab) {
            $this->render_settings();
        } else {
            if (!$this->enabled) {
                printf(
                    '<div class="notice notice-info inline"><p>%s <a href="%s">%s</a></p></div>',
                    esc_html__('Logging is off, so nothing new is being recorded.', 'enhanced-debug-logger'),
                    esc_url(add_query_arg('tab', 'settings', $base)),
                    esc_html__('Turn it on in Settings.', 'enhanced-debug-logger')
                );
            }
            echo '<div id="edl-app" data-mode="page"></div>';
        }

        echo '</div>';
    }

    private function checkbox(string $name, string $label, string $default, string $help = ''): void
    {
        $checked = '1' === (string) get_option($name, $default);
        echo '<p><label><input type="checkbox" name="' . esc_attr($name) . '" value="1"' . checked($checked, true, false) . '> ' . esc_html($label) . '</label></p>';
        if ('' !== $help) {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
    }

    private function render_settings(): void
    {
        $td = 'enhanced-debug-logger';

        settings_errors();
        echo '<form method="post" action="options.php">';
        settings_fields('edl_settings');
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">' . esc_html__('Recording', $td) . '</th><td>';
        $this->checkbox('edl_enabled', __('Record PHP errors, warnings and fatal errors', $td), '0',
            __('Fatal errors are caught too, for example when a plugin crashes during activation.', $td));
        $this->checkbox('edl_log_requests', __('Record every page request (method and path)', $td), '1',
            __('Query strings are never stored, because they can contain tokens.', $td));
        $this->checkbox('edl_log_ajax', __('Include AJAX requests', $td), '0');
        $this->checkbox('edl_log_ip', __('Store visitor IP addresses', $td), '0',
            __('Off by default. IP addresses count as personal data under the GDPR.', $td));
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Display', $td) . '</th><td>';
        $this->checkbox('edl_console', __('Show the Debug Logger button on the site and in wp-admin (administrators only)', $td), '1');
        echo '<p><label>' . esc_html__('Refresh every', $td) . ' <input type="number" class="small-text" name="edl_refresh" min="1" max="60" value="'
            . esc_attr((string) get_option('edl_refresh', '2')) . '"> ' . esc_html__('seconds', $td) . '</label></p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Storage', $td) . '</th><td>';
        echo '<p><label>' . esc_html__('Rotate the log at', $td) . ' <input type="number" class="small-text" name="edl_max_log_size" min="1" max="100" value="'
            . esc_attr((string) get_option('edl_max_log_size', '5')) . '"> MB</label></p>';
        echo '<p class="description">' . esc_html__('Log files live in wp-content/edl-logs/ with a random file name and an .htaccess block. On nginx, add a rule that denies that folder.', $td) . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        submit_button(__('Save changes', $td));
        echo '</form>';

        // Status of WordPress' own debug log.
        echo '<h2>' . esc_html__('WordPress debug log', $td) . '</h2>';
        $path = $this->wp_log_path();
        if ('' === $path) {
            echo '<p>' . esc_html__("WordPress' own debug log is off, so its messages are not shown here. To include them, add this to wp-config.php:", $td) . '</p>';
            echo '<pre><code>' . esc_html("define( 'WP_DEBUG', true );\ndefine( 'WP_DEBUG_LOG', true );\ndefine( 'WP_DEBUG_DISPLAY', false );") . '</code></pre>';
        } else {
            printf(
                '<p>%s <code>%s</code> (%s)</p>',
                esc_html__('Reading', $td),
                esc_html($this->shorten($path)),
                is_file($path) ? esc_html__('found', $td) : esc_html__('not created yet', $td)
            );
            if (0 === strpos(wp_normalize_path($path), wp_normalize_path(WP_CONTENT_DIR) . '/')) {
                echo '<p class="description">' . esc_html__('This file is inside wp-content and is often reachable from the internet. Consider pointing WP_DEBUG_LOG to a path outside the web root.', $td) . '</p>';
            }
        }
    }

    /* --------------------------------------------------------------------
     * Uninstall
     * ------------------------------------------------------------------ */

    public static function uninstall(): void
    {
        $options = [
            'edl_enabled', 'edl_log_requests', 'edl_log_ajax', 'edl_log_ip', 'edl_console',
            'edl_refresh', 'edl_max_log_size', 'edl_key',
            // options of version 3
            'edl_refresh_interval', 'edl_enable_wp_debug',
        ];
        foreach ($options as $option) {
            delete_option($option);
        }

        $dir = WP_CONTENT_DIR . '/edl-logs';
        if (is_dir($dir)) {
            foreach (scandir($dir) ?: [] as $file) {
                if ('.' !== $file && '..' !== $file) {
                    @unlink($dir . '/' . $file);
                }
            }
            @rmdir($dir);
        }
        $legacy = WP_CONTENT_DIR . '/enhanced-debug-log.txt';
        if (is_file($legacy)) {
            @unlink($legacy);
        }
    }
}

register_uninstall_hook(__FILE__, ['EnhancedDebugLogger', 'uninstall']);
EnhancedDebugLogger::instance();
