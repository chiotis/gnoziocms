<?php

declare(strict_types=1);

// Loaded by both the bundled loader and Composer's autoload.files. Publish the old name as soon as the new class loads:
// PHP does not autoload parameter type names, so legacy custom code must be able to accept a new GnozioCMS instance.
spl_autoload_register(static function (string $class): void {
    $prefix = str_starts_with($class, 'GnozioCMS\\') ? 'GnozioCMS\\' : (str_starts_with($class, 'FarosCMS\\') ? 'FarosCMS\\' : '');
    if ($prefix === '') {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $current = 'GnozioCMS\\' . $relative;
    $legacy = 'FarosCMS\\' . $relative;
    if (!class_exists($current, false)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
        if (!is_file($file)) {
            return;
        }
        require_once $file;
    }
    if (class_exists($current, false) && !class_exists($legacy, false)) {
        class_alias($current, $legacy);
    }
}, true, true);
