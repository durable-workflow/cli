<?php
// Regenerate src/input/php-vectors.json: php src/input/php-vectors.php > src/input/php-vectors.json
$json = ['', ' ', '1', ' 1 ', '"a"', 'true', 'null', '[]', '{}', '{"a":1,"a":2}', '{"a":', 'tru', "\"a\x01\"", '"\ud800"', '"\udc00x"', "\"\xff\"", '[1,]', '01', '1.0', '-0', '-0.0', '1e2', '9223372036854775807', '9223372036854775808', '18446744073709551616', '1E400', '{"":1}', '{"_empty_":1}', str_repeat('[',512).str_repeat(']',512), str_repeat('[',513).str_repeat(']',513), str_repeat('[',200).str_repeat(']',200), '"\u0000"', "\xef\xbb\xbf1", '[1] x', 'NaN', '"\/"', '{"a":{"b":[1,2,{"c":null}]}}', "[\t1\n,\r2 ]", '"é"', "1\x00"];
$out = [];
foreach ($json as $j) {
  try { $v = json_decode($j, true, 512, JSON_THROW_ON_ERROR); $out[] = ['in'=>base64_encode($j), 'ok'=>json_encode($v, JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION)]; }
  catch (JsonException $e) { $out[] = ['in'=>base64_encode($j), 'err'=>$e->getMessage()]; }
}
$b64 = ['', 'YQ==', 'YQ', 'YQ=', 'Y Q==', "YQ==\n", " YQ== ", 'YWJj', 'YW Jj', "YW\nJj", 'Y', 'YQ===', '=YQ', 'Y=Q=', 'YQ==YQ==', '@@@@', 'YWJ', '_-8=', '+/8=', "YWJj\t", 'YQ=x'];
$b = [];
foreach ($b64 as $s) { $d = base64_decode(trim($s), true); $b[] = ['in'=>$s, 'out'=> $d === false ? null : base64_encode($d)]; }
echo json_encode(['json'=>$out,'base64'=>$b], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
