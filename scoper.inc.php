<?php

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

// Prefix only the converter; `composer build` then writes vendor_prefixed/vendor/autoload.php.
return [
    'prefix' => 'AcceptMarkdown\\Vendor',
    'finders' => [
        Finder::create()->files()->in('vendor/league/html-to-markdown')->path(['src/', 'composer.json', 'LICENSE'])->notPath('bin'),
    ],
    'patchers' => [
        // A fixed autoloader suffix keeps the build reproducible (Composer otherwise picks a random one).
        static function (string $filePath, string $prefix, string $contents): string {
            if (basename($filePath) !== 'composer.json') {
                return $contents;
            }
            $json = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            $json['config']['autoloader-suffix'] = 'AcceptMarkdown';

            return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        },
    ],
];
