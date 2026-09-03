<?php
/**
 * Minimal PSR-4 autoloader so the analyzer core runs under plain `php`
 * (no `composer install` needed for tests/demo).
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'Lexa\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $rel  = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
