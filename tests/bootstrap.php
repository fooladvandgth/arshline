<?php
// Minimal WP stubs via Brain Monkey
require_once __DIR__ . '/../vendor/autoload.php';

Brain\Monkey\setUp();

// Generic WP function stubs used in code under test
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {}
}

// Minimal REST classes
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private array $params = [];
        public function __construct($method = 'GET', $route = '') {}
        public function set_param($name, $value) { $this->params[$name] = $value; }
        public function get_param($name) { return $this->params[$name] ?? null; }
        public function __get($name) { return null; }
        public function __set($name, $value) {}
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        protected $data; protected $status;
        public function __construct($data, $status = 200) { $this->data = $data; $this->status = $status; }
        public function get_data() { return $this->data; }
        public function get_status() { return $this->status; }
    }
}
if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args = []) {}
}
if (!function_exists('__return_true')) {
    function __return_true() { return true; }
}

// $wpdb stub
global $wpdb;
if (!$wpdb) {
    $wpdb = new class {
        public $prefix = 'wp_';
        public function get_results($query, $output = OBJECT) { return []; }
        public function prepare($query, ...$args) { return $query; }
        public function insert($table, $data) { $this->insert_id = 1; return true; }
        public function update($table, $data, $where) { return true; }
        public $insert_id = 1;
    };
}
