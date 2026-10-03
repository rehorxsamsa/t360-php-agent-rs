<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$directories = array_values(array_filter(
    [__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/public', __DIR__ . '/config', __DIR__ . '/database'],
    is_dir(...),
));

return (new Config())
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        '@PHP8x4Migration' => true,
        'declare_strict_types' => true,
    ])
    ->setCacheFile(__DIR__ . '/var/.php-cs-fixer.cache')
    ->setFinder(
        (new Finder())
            ->in($directories !== [] ? $directories : [__DIR__ . '/docker'])
            ->name('*.php')
            // Šablony-fixtury testů nejsou samostatné PHP soubory (proměnné z extract(), bez declare).
            ->notPath('Unit/Http/View/templates'),
    );
