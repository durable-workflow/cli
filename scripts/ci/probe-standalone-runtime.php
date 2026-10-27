<?php

declare(strict_types=1);

echo json_encode([
    'php_version' => PHP_VERSION,
    'php_version_id' => PHP_VERSION_ID,
    'sapi' => PHP_SAPI,
], JSON_THROW_ON_ERROR).PHP_EOL;
