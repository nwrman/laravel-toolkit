<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Preflight Gates
    |--------------------------------------------------------------------------
    |
    | Define the CI gates that `test:preflight` runs in parallel. Each gate
    | has a label (for display), a command (shell string), and an optional
    | env array for environment variables.
    |
    | The defaults match a typical Laravel + React + Inertia project with
    | Pest, Vitest, Pint, Rector, PHPStan, and TypeScript.
    |
    */

    'gates' => [
        'pest-coverage' => [
            'label' => 'Pest Coverage',
            'command' => 'vendor/bin/pest --type-coverage --min=100 && vendor/bin/pest --parallel --coverage --exactly=100.0',
            'env' => ['XDEBUG_MODE' => 'coverage'],
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
    | Test Suites
    |--------------------------------------------------------------------------
    |
    | Define the test suites available in `test:report`. The keys are used
    | as identifiers and the values as display labels.
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
    | Frontend Build Command
    |--------------------------------------------------------------------------
    |
    | The command used to build frontend assets when a build is needed.
    |
    */

    'frontend_build_command' => env('TOOLKIT_BUILD_COMMAND', 'bun run build'),

    /*
    |--------------------------------------------------------------------------
    | Frontend Test Command
    |--------------------------------------------------------------------------
    |
    | The command used to run frontend tests.
    |
    */

    'frontend_test_command' => env('TOOLKIT_FRONTEND_TEST_COMMAND', 'bun run test:ui'),

    /*
    |--------------------------------------------------------------------------
    | Frontend Coverage Command
    |--------------------------------------------------------------------------
    |
    | The command used to run frontend tests with coverage.
    |
    */

    'frontend_coverage_command' => env('TOOLKIT_FRONTEND_COVERAGE_COMMAND', 'bun run test:coverage'),

    /*
    |--------------------------------------------------------------------------
    | Notification Title
    |--------------------------------------------------------------------------
    |
    | The title used in macOS notifications and Telegram messages.
    | Defaults to the app name.
    |
    */

    'notification_title' => null,

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Enable or disable macOS native notifications for test results.
    |
    */

    'notifications' => true,

];
