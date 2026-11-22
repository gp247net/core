<?php

/**
 * Bootstrap of the gp247/core test suite.
 *
 * The package is tested inside a host Laravel application that has it installed
 * (a blank Laravel app in CI, the S-Cart checkout on a developer machine). This file
 * finds that application, loads its autoloader and registers the package's test
 * namespace — `autoload-dev` of a package has no effect when it is a dependency.
 *
 * Host lookup: GP247_HOST_APP when set, otherwise the nearest parent directory that
 * holds both vendor/autoload.php and bootstrap/app.php. Safe to include more than once.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-public-test-suite
 */

(static function (): void {
    $host = getenv('GP247_HOST_APP') ?: null;
    if ($host === null) {
        $dir = dirname(__DIR__);
        while (($parent = dirname($dir)) !== $dir) {
            if (is_file($parent . '/vendor/autoload.php') && is_file($parent . '/bootstrap/app.php')) {
                $host = $parent;
                break;
            }
            $dir = $parent;
        }
    }
    if ($host === null || !is_file($host . '/vendor/autoload.php')) {
        fwrite(STDERR, "gp247/core tests: no host Laravel application found. Install the package in a Laravel app, "
            . "or set GP247_HOST_APP to its directory.\n");
        exit(1);
    }

    require_once $host . '/vendor/autoload.php';

    // WHY: Laravel's test case boots the application at APP_BASE_PATH; pin it to the host
    // instead of relying on which autoloader registered first.
    if (!isset($_ENV['APP_BASE_PATH']) && !isset($_SERVER['APP_BASE_PATH'])) {
        $_ENV['APP_BASE_PATH'] = $host;
    }

    $prefix = 'GP247\\Core\\Tests\\';
    foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $loader) {
        if (!isset($loader->getPrefixesPsr4()[$prefix])) {
            $loader->addPsr4($prefix, __DIR__ . '/');
        }
        break;
    }
})();
