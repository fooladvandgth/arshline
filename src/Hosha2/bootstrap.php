<?php
/**
 * Hosha2 Module Bootstrap
 *
 * NOTE: This module is intentionally isolated from legacy Hoosha implementation.
 * It relies 100% on OpenAI for generation / editing / validation.
 * Only minimal structural safety checks locally.
 */

// Placeholder bootstrap. Actual wiring (logger init, menu registration, rest routes)
// will be added in F1+ phases.

if (!defined('ARSHLINE_HOSHA2_LOADED')) {
    define('ARSHLINE_HOSHA2_LOADED', true);
}

// --- Logger Singleton & Helpers (F1) ---
if (!function_exists('Arshline\\Hosha2\\hoosha2_logger')) {
    function hoosha2_logger(): ?Hosha2LoggerInterface {
        static $instance = null;
        if ($instance === null) {
            $upload_dir = wp_upload_dir();
            $baseDir = isset($upload_dir['basedir']) ? $upload_dir['basedir'] . '/arshline_logs' : __DIR__ . '/../../logs';
            // Ensure namespace is imported
            $instance = new Hosha2FileLogger($baseDir, 'hooshyar2-log.txt');
            $instance->setContext(['pid' => getmypid()]);
        }
        return $instance;
    }
}

if (!function_exists('Arshline\\Hosha2\\hoosha2_log')) {
    function hoosha2_log(string $event, array $payload = [], string $level = 'INFO'): void {
        $logger = hoosha2_logger();
        if ($logger) {
            $logger->log($event, $payload, $level);
        }
    }
}

// Example phase emit (can be removed or adjusted later once real phases wire in)
if (function_exists('add_action')) {
    add_action('init', function () {
        $logger = hoosha2_logger();
        if ($logger) {
            $logger->phase('bootstrap_init', [], 'INFO');
        }
        // Register custom post type for version snapshots
        if (function_exists('register_post_type')) {
            register_post_type('hosha2_version', [
                'labels' => [ 'name' => 'Hosha2 Version Snapshots', 'singular_name' => 'Hosha2 Version' ],
                'public' => false,
                'show_ui' => false,
                'supports' => ['title'],
                'capability_type' => 'post',
                'map_meta_cap' => true,
            ]);
        }
    });
}

// Cancellation helper (F4 Task 3)
if (!function_exists('Arshline\Hosha2\hosha2_cancel_request')) {
    function hosha2_cancel_request(string $request_id, int $ttl = 300): bool {
        if (!function_exists('set_transient')) return false; // non-WP test environment
        return set_transient('hosha2_cancel_' . $request_id, 1, $ttl);
    }
}

// Rate Limiter singleton (F4 Task 4)
if (!function_exists('Arshline\Hosha2\hosha2_rate_limiter')) {
    function hosha2_rate_limiter(): Hosha2RateLimiter {
        static $rl = null; if ($rl === null) { $rl = new Hosha2RateLimiter(hoosha2_logger(), ['max_requests'=>10,'window'=>60]); }
        return $rl;
    }
}

// Version repository singleton
if (!function_exists('Arshline\Hosha2\hosha2_version_repository')) {
    function hosha2_version_repository(): Hosha2VersionRepository {
        static $repo = null; if ($repo === null) { $repo = new Hosha2VersionRepository(hoosha2_logger()); }
        return $repo;
    }
}
