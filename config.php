<?php

declare(strict_types=1);


$releaseTypes     = ['patch', 'minor', 'major'];
$releaseFunctions = ['getNextPatchVersion', 'getNextMinorVersion', 'getNextMajorVersion'];

$columnTypes = [
    'todo'  => 'To do',
    'doing' => 'In progress',
    'done'  => 'Done',
];
