<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->append([__FILE__, __DIR__ . '/bin/vending-machine']);

return (new Config())
    ->setRules([
        '@PSR12' => true,
    ])
    ->setFinder($finder);
