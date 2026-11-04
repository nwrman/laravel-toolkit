<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Preflight Gates
    |--------------------------------------------------------------------------
    |
    | Each gate runs a shell command as part of `vendor/bin/preflight`. Gates run
    | in parallel; one with `after` starts once that gate has passed. The first
    | gate to fail stops the others. Override individual gates by publishing this
    | config and changing only the keys you need. Set a gate to null to remove it.
    |
    | Gates inherit the shell's environment: nothing from `.env` reaches them, so
    | Pest loads `.env.testing` as it does from a plain shell.
    |
    | The coverage gate runs through Test Impact Analysis when the project has
    | it on, and leaves Browser specs out with --toolkit-no-browser, which keeps
    | the run a full one to TIA (`--exclude-testsuite` would switch TIA off).
    | Coverage comes from unit and feature tests. Browser specs run in their own
    | gate after it, without coverage and on fewer workers. `composer test:ci` is
    | the run without TIA.
    |
    */

    'gates' => [
        'pest-coverage' => [
            'label' => 'Pest Coverage',
            'command' => 'vendor/bin/pest --type-coverage --min=100 && vendor/bin/pest --parallel --coverage --exactly=100.0 --toolkit-no-browser',
            'env' => ['XDEBUG_MODE' => 'coverage'],
        ],
        'browser' => [
            'label' => 'Browser',
            'command' => 'vendor/bin/pest --testsuite=Browser --parallel --processes=4',
            'env' => ['XDEBUG_MODE' => 'off'],
            'after' => 'pest-coverage',
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
    | The build Browser specs render. The Pest plugin rebuilds it before a run
    | that could select a Browser spec, when a watched file is newer than the
    | stamp. Set TOOLKIT_FORCE_BUILD=1 (or `preflight --force-build`) to force it.
    | The dev server's hot file is put back if the build deletes it.
    |
    */

    'build' => [
        'command' => 'vp build',
        'timeout' => 120,
        'stamp_file' => 'public/build/.buildstamp',
        'manifest_file' => 'public/build/manifest.json',
        'watch_paths' => 'resources/ vite.config.* tsconfig.* tailwind.config.* postcss.config.* package.json',
        'hot_file' => 'public/hot',
    ],

    /*
    |--------------------------------------------------------------------------
    | File Paths
    |--------------------------------------------------------------------------
    |
    | Paths for state files and reports, relative to the project root. Every
    | Pest run writes the JUnit file and, when something fails, the report.
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
    | Frontend Commands
    |--------------------------------------------------------------------------
    |
    | The command `toolkit:install` writes into `composer test` and
    | `composer test:frontend`.
    |
    */

    'frontend_test_command' => 'bun run test:ui',

    /*
    |--------------------------------------------------------------------------
    | Test Impact Analysis
    |--------------------------------------------------------------------------
    |
    | Pest's TIA replays only the tests a change can actually reach. It engages
    | on full runs only: `--testsuite`, `--filter` and the other selection flags
    | switch it off. With this on, preflight keeps the coverage cache in step
    | with the commit it matches, and `toolkit:install` runs `composer test`
    | with a coverage driver so TIA can record.
    |
    | Requires a coverage driver (recording costs roughly 2x, once) and
    | `pest()->tia()` in tests/Pest.php. Off by default; the flag alone does
    | nothing without that configuration.
    |
    */

    'tia' => [
        'enabled' => env('TOOLKIT_TIA', false),

        // Records Pest's graph when it has none, before the coverage gate. Pest 5 drops
        // the graph when composer.lock or another fingerprinted file changes, and a
        // coverage run can't record one, so the gate would run the whole suite.
        'record_command' => 'vendor/bin/pest --parallel --toolkit-no-browser',
    ],

];
