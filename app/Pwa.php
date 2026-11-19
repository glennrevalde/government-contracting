<?php
declare(strict_types=1);

/**
 * The system as an installable app (Progressive Web App): an icon on the phone or computer that opens the system
 * full screen, without the browser's address bar. Nothing is installed from an app store and nothing is kept on the
 * device but the stylesheets, scripts, fonts and icons; every page still comes from the server.
 *
 *   manifest.php     the app's name, colors and icons (read by the browser when it offers to install)
 *   sw.js            the service worker: the offline page, and the cached assets/ files
 *   offline.html     shown instead of a page when there is no connection
 *   assets/icons/    the app icons
 *   assets/js/pwa.js the "Install app" button and box, and the steps for browsers that do not offer the install
 */
final class Pwa
{
    /** The color of the side menu: the title bar of the installed app and its splash screen */
    public const THEME_COLOR = '#f7f8fa';
    public const BACKGROUND_COLOR = '#f7f8fa';

    /** Used when the system's name cannot be read */
    public const DEFAULT_NAME = 'G-SMaRT';

    /** The system's name, as set for the system (config/config.php), or the product name */
    public static function name(): string
    {
        $name = function_exists('app_name') ? trim(app_name()) : '';
        return $name !== '' ? $name : self::DEFAULT_NAME;
    }

    /** The name under the icon: "G-SMaRT", or a name of at most 12 characters, so phones do not cut it off */
    public static function shortName(): string
    {
        $name = self::name();
        if (defined('APP_PRODUCT_NAME') && defined('APP_PRODUCT_SHORT') && $name === APP_PRODUCT_NAME) {
            return APP_PRODUCT_SHORT;
        }
        if (mb_strlen($name) <= 12) {
            return $name;
        }
        $first = (string)preg_split('/\s+/u', $name)[0];
        return mb_strlen($first) <= 12 ? $first : rtrim(mb_substr($first, 0, 12));
    }

    /** The web app manifest; its URLs are relative to manifest.php, which sits next to index.php */
    public static function manifest(): array
    {
        $icon = fn(string $file, int $size, string $purpose) => [
            'src' => 'assets/icons/' . $file, 'sizes' => $size . 'x' . $size, 'type' => 'image/png', 'purpose' => $purpose,
        ];
        return [
            'id'               => './',
            'name'             => self::name(),
            'short_name'       => self::shortName(),
            'description'      => 'Government contracting and accounting, on one set of books.',
            'lang'             => 'en',
            'dir'              => 'ltr',
            'start_url'        => './index.php',
            'scope'            => './',
            'display'          => 'standalone',
            'orientation'      => 'any',
            'theme_color'      => self::THEME_COLOR,
            'background_color' => self::BACKGROUND_COLOR,
            'categories'       => ['business', 'finance', 'productivity'],
            'prefer_related_applications' => false,
            'icons'            => [
                $icon('icon-192.png', 192, 'any'),
                $icon('icon-512.png', 512, 'any'),
                $icon('maskable-192.png', 192, 'maskable'),
                $icon('maskable-512.png', 512, 'maskable'),
            ],
        ];
    }

    /** The tags of the page head: manifest, icons, theme color, the iPhone / iPad tags and the install script */
    public static function head(): string
    {
        $base = base_url();
        $short = self::shortName();
        $icon = fn(string $file) => h($base . '/assets/icons/' . $file);
        return '<link rel="manifest" href="' . h($base . '/manifest.php') . '">' . "\n"
            . '<meta name="theme-color" content="' . h(self::THEME_COLOR) . '">' . "\n"
            . '<link rel="icon" type="image/png" sizes="32x32" href="' . $icon('favicon-32.png') . '">' . "\n"
            . '<link rel="apple-touch-icon" sizes="180x180" href="' . $icon('apple-touch-icon.png') . '">' . "\n"
            . '<meta name="application-name" content="' . h($short) . '">' . "\n"
            . '<meta name="mobile-web-app-capable" content="yes">' . "\n"
            . '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
            . '<meta name="apple-mobile-web-app-title" content="' . h($short) . '">' . "\n"
            . '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n"
            . '<script src="' . h(asset('js/pwa.js')) . '" data-base="' . h($base . '/') . '" data-name="' . h($short) . '" defer></script>' . "\n";
    }

    /** The "Install app" button of the top bar (hidden inside the installed app) */
    public static function installButton(): string
    {
        return '<button type="button" class="bell-btn find-btn app-install-btn pwa-install no-print" data-pwa-install title="Install this system as an app on this device" aria-label="Install app">'
            . '<i class="bi bi-download"></i><span class="find-btn-text">Install app</span></button>';
    }

    /** The "Install the app" box of the log-in page (hidden inside the installed app) */
    public static function installBox(): string
    {
        return '<div class="install-box pwa-install">'
            . '<img class="install-box-icon" src="' . h(base_url() . '/assets/icons/icon-192.png') . '" alt="" width="44" height="44">'
            . '<div class="install-box-text"><strong>Install the app</strong>'
            . '<span>Open ' . h(self::shortName()) . ' from an icon on your phone or computer, full screen.</span></div>'
            . '<button type="button" class="btn btn-sm btn-outline-primary install-box-btn" data-pwa-install><i class="bi bi-download"></i> Install</button>'
            . '</div>';
    }
}
