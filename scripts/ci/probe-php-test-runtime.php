<?php

declare(strict_types=1);

final class PhpTestRuntimeRefused extends RuntimeException {}

if (PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION !== ($argv[1] ?? null)) {
    throw new PhpTestRuntimeRefused('The PHP test minor does not match the image.');
}
foreach (['curl', 'dom', 'mbstring', 'openssl', 'Phar', 'pcntl', 'posix', 'xml', 'xmlwriter'] as $extension) {
    if (! extension_loaded($extension)) {
        throw new PhpTestRuntimeRefused('Required extension missing: '.$extension);
    }
}
foreach (['pcntl_signal', 'pcntl_async_signals', 'posix_kill', 'posix_getpgid', 'posix_setsid'] as $function) {
    if (! function_exists($function)) {
        throw new PhpTestRuntimeRefused('Required function missing: '.$function);
    }
}
if (extension_loaded('xdebug') || extension_loaded('pcov')) {
    throw new PhpTestRuntimeRefused('Coverage must remain none.');
}
if (php_ini_loaded_file() !== '/usr/local/etc/php/php.ini') {
    throw new PhpTestRuntimeRefused('The container production INI must be loaded.');
}
echo json_encode([
    'php' => PHP_VERSION,
    'architecture' => php_uname('m'),
    'extensions' => get_loaded_extensions(),
    'ini' => php_ini_loaded_file(),
    'coverage' => 'none',
], JSON_THROW_ON_ERROR).PHP_EOL;
