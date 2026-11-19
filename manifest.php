<?php
declare(strict_types=1);

/**
 * The web app manifest: what a phone or computer reads to install the system as an app (app/Pwa.php).
 * It needs no log-in and no database. Everything is written to a buffer that is thrown away before the JSON goes
 * out, so a PHP notice or warning can never end up inside it.
 */

ob_start();
ini_set('display_errors', '0');
set_error_handler(fn() => true);

$manifest = null;
try {
    if (!defined('APP_ROOT')) {
        define('APP_ROOT', __DIR__);
    }
    $GLOBALS['CONFIG'] = require __DIR__ . '/config/config.php';
    require_once __DIR__ . '/app/helpers.php';
    require_once __DIR__ . '/app/Pwa.php';
    $manifest = Pwa::manifest();
} catch (Throwable $e) {
    $manifest = null;
}

// the system's files could not be read: the app can still be installed under the default name
if (!is_array($manifest)) {
    $manifest = [
        'id' => './', 'name' => 'G-SMaRT', 'short_name' => 'G-SMaRT', 'start_url' => './index.php', 'scope' => './',
        'display' => 'standalone', 'theme_color' => '#f7f8fa', 'background_color' => '#f7f8fa',
        'icons' => [
            ['src' => 'assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => 'assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => 'assets/icons/maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
            ['src' => 'assets/icons/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ];
}

$json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);

while (ob_get_level() > 0) {
    ob_end_clean();
}
restore_error_handler();

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');
echo $json;
