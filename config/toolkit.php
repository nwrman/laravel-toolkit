<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Preflight Gates
    |--------------------------------------------------------------------------
    |
    | Each gate runs a shell command as part of the preflight check. Gates run
    | in parallel. Override individual gates by publishing this config and
    | changing only the keys you need. Set a gate to null to remove it.
    |
    */

    'gates' => [
        'pest-coverage' => [
            'label' => 'Pest Coverage',
            'command' => 'vendor/bin/pest --type-coverage --min=100 && vendor/bin/pest --parallel --coverage --exactly=100.0',
            'env' => ['XDEBUG_MODE' => 'coverage'],
            'requires_build' => true,
        ],
        'frontend-coverage' => [
            'label' => 'Frontend Coverage',
            'command' => 'bun run test:coverage',
        ],
        'lint' => [
            'label' => 'Lint',
            'command' => 'vendor/bin/pint --parallel --test && vendor/bin/rector --dry-run && bun run test:lint',
        ],
        'types' => [
            'label' => 'Types',
            'command' => 'vendor/bin/phpstan && bun run test:types',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Desktop Notifications
    |--------------------------------------------------------------------------
    |
    | When enabled, commands will send macOS notifications via terminal-notifier
    | (preferred) or afplay (fallback) on completion.
    |
    */

    'notifications' => [
        'desktop' => [
            'enabled' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend Build
    |--------------------------------------------------------------------------
    |
    | Configuration for the frontend build check that runs before gates
    | marked with 'requires_build' => true.
    |
    */

    'build' => [
        'command' => 'vp build',
        'timeout' => 120,
        'stamp_file' => 'public/build/.buildstamp',
        'manifest_file' => 'public/build/manifest.json',
        'watch_paths' => 'resources/ vite.config.* tsconfig.* tailwind.config.* postcss.config.* package.json',
    ],

    /*
    |--------------------------------------------------------------------------
    | File Paths
    |--------------------------------------------------------------------------
    |
    | Paths for state files and reports, relative to the project root.
    |
    */

    'paths' => [
        'preflight_state' => 'storage/logs/preflight-result.json',
        'report_dir' => 'storage/logs',
        'xml_file' => 'storage/logs/test-results.xml',
        'report_file' => 'storage/logs/test-failures.md',
        'json_file' => 'storage/logs/test-failures.json',
    ],

    /*
    |--------------------------------------------------------------------------
    | Test Suites
    |--------------------------------------------------------------------------
    |
    | Named test suites available for the test:report command. Keys are used
    | as identifiers; values are displayed labels.
    |
    */

    'suites' => [
        'unit' => 'Unit',
        'feature' => 'Feature',
        'browser' => 'Browser',
        'frontend' => 'Frontend (Vitest)',
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend Commands
    |--------------------------------------------------------------------------
    */

    'frontend_test_command' => 'bun run test:ui',

];
