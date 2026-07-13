<?php
declare(strict_types=1);

/**
 * Router for `php -S` (composer start). The built-in server 404s any dotted
 * path with no matching file on disk WITHOUT invoking PHP — so `/.well-known/*`
 * and similar silently die in local dev. Routing everything through index.php
 * (except real static files) fixes that. Apache/.htaccess + the gateway's
 * in-process mode don't need this.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false; // serve the static asset as-is
}

require __DIR__ . '/index.php';
