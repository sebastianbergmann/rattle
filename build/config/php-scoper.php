<?php declare(strict_types=1);
return [
    'prefix'             => 'RattlePHAR',
    // rattle calls PHPUnit's API for running the examples of properties, and
    // PHPUnit is not bundled
    'exclude-namespaces' => ['SebastianBergmann\Rattle', 'PHPUnit'],
    'expose-constants'   => ['/^__RATTLE_.+$/'],
];
