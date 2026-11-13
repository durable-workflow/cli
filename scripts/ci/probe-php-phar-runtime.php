<?php

declare(strict_types=1);

final class PharRuntimeRefused extends RuntimeException {}

if (PHP_VERSION !== '8.4.27' || ini_get('phar.readonly') !== '0'
    || ini_get('memory_limit') !== '-1' || date_default_timezone_get() !== 'UTC'
    || ! in_array('/usr/local/etc/php/conf.d/zz-cli-phar.ini', explode(",\n", trim((string) php_ini_scanned_files())), true)) {
    throw new PharRuntimeRefused('The exact PHP PHAR profile must be active.');
}
require_once __DIR__.'/probe-php-test-runtime.php';
echo json_encode(['phar_readonly' => ini_get('phar.readonly'), 'memory_limit' => ini_get('memory_limit')], JSON_THROW_ON_ERROR).PHP_EOL;
