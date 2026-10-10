<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()->in([__DIR__ . '/src', __DIR__ . '/tests']);

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const']],
    ])
    ->setFinder($finder);
