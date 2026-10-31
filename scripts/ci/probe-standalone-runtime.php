<?php

declare(strict_types=1);

$opensslLibraryVersion = null;
if (extension_loaded('openssl')) {
    ob_start();
    phpinfo(INFO_MODULES);
    $moduleInfo = ob_get_clean();
    if (is_string($moduleInfo) && preg_match(
        '/OpenSSL Library Version(?:\s*=>)?\s*(OpenSSL \d+\.\d+\.\d+)(?:\s|$)/',
        strip_tags($moduleInfo),
        $matches,
    ) === 1) {
        $opensslLibraryVersion = $matches[1];
    }
}

echo json_encode([
    'php_version' => PHP_VERSION,
    'php_version_id' => PHP_VERSION_ID,
    'sapi' => PHP_SAPI,
    'os_family' => PHP_OS_FAMILY,
    'openssl_version' => $opensslLibraryVersion,
    'openssl_header_version' => defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : null,
    'curl' => extension_loaded('curl') ? curl_version() : null,
    'pcre_version' => defined('PCRE_VERSION') ? PCRE_VERSION : null,
    'oniguruma_version' => defined('MB_ONIGURUMA_VERSION') ? MB_ONIGURUMA_VERSION : null,
    'iconv_version' => defined('ICONV_VERSION') ? ICONV_VERSION : null,
], JSON_THROW_ON_ERROR).PHP_EOL;
