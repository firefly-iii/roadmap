<?php

$content = file_get_contents('roadmap.json');
$json    = json_decode($content, true);

// sort categories
usort($json['categories'], function (array $a, array $b) {
    $keyA = null === $a['parent'] ? sprintf('0%s',$a['order']) : sprintf('%s%s',$a['parent'],$a['order']);
    $keyB = null === $b['parent'] ? sprintf('0%s',$b['order']) : sprintf('%s%s',$b['parent'],$b['order']);
    return strcmp($keyA, $keyB);
});

// sort items:
usort($json['info'], function (array $a, array $b) {
    return strcmp($a['parent'].$a['type'], $b['parent'].$b['type']);
});



$res = json_encode($json, JSON_PRETTY_PRINT);

file_put_contents('roadmap.json', $res);
