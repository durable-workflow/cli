<?php

declare(strict_types=1);

$box = $argv[1] ?? '';
if (! is_file($box) || is_link($box)
    || filesize($box) !== 2947031
    || hash_file('sha256', $box) !== 'aa0966319f709e74bf2bf1d58ddb987903ae4f6d0a9d335ec2261813c189f7fc') {
    throw new RuntimeException('The read-only Box artifact differs from the reviewed release.');
}
array_splice($argv, 0, 2, [$box]);
$argc = count($argv);
$_SERVER['argv'] = $argv;
$_SERVER['argc'] = $argc;
require $box;
