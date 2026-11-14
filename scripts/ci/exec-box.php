<?php

declare(strict_types=1);

final class BoxArtifactRefused extends RuntimeException {}

$box = $argv[1] ?? '';
$metadata = @lstat($box);
if (! is_file($box) || is_link($box)
    || $metadata === false || $metadata['uid'] !== posix_getuid()
    || $metadata['nlink'] !== 1 || ($metadata['mode'] & 07777) !== 0400
    || filesize($box) !== 2947031
    || hash_file('sha256', $box) !== 'aa0966319f709e74bf2bf1d58ddb987903ae4f6d0a9d335ec2261813c189f7fc') {
    throw new BoxArtifactRefused('The read-only Box artifact differs from the reviewed release.');
}
array_splice($argv, 0, 2, [$box]);
$argc = count($argv);
$_SERVER['argv'] = $argv;
$_SERVER['argc'] = $argc;
// Box uses this entrypoint for its own child extract/info commands. Those
// children must reopen the verified read-only PHAR, not this guard script.
$_SERVER['SCRIPT_FILENAME'] = $box;
$_SERVER['SCRIPT_NAME'] = $box;
$_SERVER['PHP_SELF'] = $box;
require_once $box;
