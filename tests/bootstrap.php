<?php

// Test harness only: never execute over HTTP. These files deliberately stub
// WordPress, so a web-reachable copy would run outside WordPress entirely.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
/**
 * Minimal WP + Gravity Forms stubs so klypHubspot's payload building can be
 * exercised without a WordPress install.
 */

define('ABSPATH', __DIR__);

// The plugin logs every submission via error_log(); keep test output clean.
ini_set('error_log', '/dev/null');

$GLOBALS['options'] = array(
    'klyp_gftohs_access_token' => 'pat-na1-test',
    'klyp_gftohs_portal_id'    => '1234567',
);
$GLOBALS['http_calls'] = array();
$GLOBALS['http_next']  = null;

function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['options'][$k]); return true; }
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t) { $GLOBALS['transients'][$k] = $v; return true; }
function trailingslashit($s) { return rtrim($s, '/\\') . '/'; }
function apply_filters($tag, $value, ...$args) {
    if (isset($GLOBALS['filters'][$tag]) && is_callable($GLOBALS['filters'][$tag])) {
        return $GLOBALS['filters'][$tag]($value, ...$args);
    }
    return $value;
}
function wp_json_encode($d) { return json_encode($d); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_unslash($s) { return $s; }
function esc_url_raw($u) { return $u; }
function wp_get_referer() { return false; }
function url_to_postid($u) { return $u === 'https://example.test/contact/' ? 42 : 0; }
function get_the_title($id) { return $id === 42 ? 'Contact Us' : ''; }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function is_wp_error($t) { return $t instanceof WP_Error; }
function maybe_unserialize($v) { $r = @unserialize((string) $v); return $r === false && $v !== 'b:0;' ? $v : $r; }
function __($s, $d = null) { return $s; }
function rgar($a, $k, $default = '') {
    if (is_array($a) && isset($a[$k])) { return $a[$k]; }
    if (is_object($a) && isset($a->{$k})) { return $a->{$k}; }
    return $default;
}
function rgobj($o, $n) { return isset($o->{$n}) ? $o->{$n} : ''; }

class WP_Error {
    public $code; public $message;
    public function __construct($c = '', $m = '') { $this->code = $c; $this->message = $m; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

function wp_remote_get($url, $args = array()) { return klyp_http('GET', $url, $args); }
function wp_remote_post($url, $args = array()) { return klyp_http('POST', $url, $args); }
function klyp_http($method, $url, $args) {
    $GLOBALS['http_calls'][] = array('method' => $method, 'url' => $url, 'args' => $args);
    $next = $GLOBALS['http_next'];
    $GLOBALS['http_next'] = null;
    return $next ?? array('response' => array('code' => 200), 'body' => '{}');
}
function wp_remote_retrieve_response_code($r) { return is_array($r) ? $r['response']['code'] : 0; }
function wp_remote_retrieve_body($r) { return is_array($r) ? $r['body'] : ''; }

class GFCommon {
    public static $log = array();
    public static function log_error($m) { self::$log[] = 'ERROR ' . $m; }
    public static function log_debug($m) { self::$log[] = 'DEBUG ' . $m; }
}

/** Stand-in for GF_Field: array access + a declared input type. */
class GF_Field implements ArrayAccess {
    public $id; public $type; public $label = ''; public $inputs = null;
    public $field_gf_to_hs_map = '';
    public function __construct($props) { foreach ($props as $k => $v) { $this->{$k} = $v; } }
    public function get_input_type() { return $this->type; }
    public function offsetExists($o): bool { return isset($this->{$o}); }
    #[\ReturnTypeWillChange]
    public function offsetGet($o) { return $this->{$o} ?? null; }
    public function offsetSet($o, $v): void { $this->{$o} = $v; }
    public function offsetUnset($o): void { unset($this->{$o}); }
}

require_once dirname(__DIR__) . '/inc/hubspot-api.php';
