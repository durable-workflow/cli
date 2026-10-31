<?php

declare(strict_types=1);

echo json_encode([
    'php_version' => PHP_VERSION,
    'php_version_id' => PHP_VERSION_ID,
    'sapi' => PHP_SAPI,
    'os_family' => PHP_OS_FAMILY,
    'openssl_version' => defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : null,
    'curl' => extension_loaded('curl') ? curl_version() : null,
    'pcre_version' => defined('PCRE_VERSION') ? PCRE_VERSION : null,
    'oniguruma_version' => defined('MB_ONIGURUMA_VERSION') ? MB_ONIGURUMA_VERSION : null,
    'iconv_version' => defined('ICONV_VERSION') ? ICONV_VERSION : null,
], JSON_THROW_ON_ERROR).PHP_EOL;
