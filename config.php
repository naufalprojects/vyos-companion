<?php
/**
 * VyOS Companion - Connection Settings
 * No database, supports environment variables (Docker friendly)
 */

if (!function_exists('vyos_env')) {
    function vyos_env(string $key, $default = null) {
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
        $v = getenv($key);
        if ($v !== false && $v !== '' && $v !== null) return $v;
        return $default;
    }
}

if (!function_exists('vyos_env_bool')) {
    function vyos_env_bool(string $key, bool $default = true): bool {
        $v = vyos_env($key, null);
        if ($v === null) return $default;
        if (is_bool($v)) return $v;
        $v = strtolower(trim((string)$v));
        if (in_array($v, ['1', 'true', 'yes', 'on'], true)) return true;
        if (in_array($v, ['0', 'false', 'no', 'off'], true)) return false;
        return $default;
    }
}

define('APP_NAME',    'VyOS Companion');
define('APP_VERSION', '1.0.0');

// true  = use simulated data (no router needed)
// false = real SSH connection to VyOS
define('DEMO_MODE', vyos_env_bool('DEMO_MODE', true));

// SSH connection to VyOS (used when DEMO_MODE = false)
define('VYOS_HOST', trim((string) vyos_env('VYOS_HOST', '192.168.1.1')));
define('VYOS_PORT', (int) vyos_env('VYOS_PORT', 22));
define('VYOS_USER', trim((string) vyos_env('VYOS_USER', 'vyos')));
define('VYOS_PASS', (string) vyos_env('VYOS_PASS', 'vyos'));

// SSH connection timeout (seconds)
define('SSH_TIMEOUT', max(3, (int) vyos_env('SSH_TIMEOUT', 10)));

// Interface monitor auto-refresh interval (seconds)
define('REFRESH_INTERVAL', 2);

// Local storage path (JSON file, mounted as a Docker volume)
define('DATA_DIR', __DIR__ . '/data');
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0777, true);
}
