<?php

declare(strict_types=1);

use Illuminate\Support\Env;
use Nwrman\LaravelToolkit\Config\ToolkitConfig;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/toolkit-config-'.uniqid();
    mkdir($this->root.'/config', 0755, true);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->root));
    unset($_SERVER['TOOLKIT_CONFIG_TEST_FROM_SHELL']);
});

it('merges one overridden gate into the package gates and removes a gate set to null', function (): void {
    $merged = ToolkitConfig::merge(
        ['gates' => [
            'lint' => ['label' => 'Lint', 'command' => 'pint --test'],
            'types' => ['label' => 'Types', 'command' => 'phpstan'],
            'browser' => ['label' => 'Browser', 'command' => 'pest --testsuite=Browser'],
        ], 'paths' => ['report_file' => 'a.md', 'json_file' => 'a.json']],
        ['gates' => ['lint' => ['command' => 'pint --test --dirty'], 'browser' => null], 'paths' => ['report_file' => 'b.md']],
    );

    expect($merged)->toBe([
        'gates' => [
            'lint' => ['label' => 'Lint', 'command' => 'pint --test --dirty'],
            'types' => ['label' => 'Types', 'command' => 'phpstan'],
        ],
        'paths' => ['report_file' => 'b.md'],
    ]);
});

it('reads the app config over the package config without booting Laravel', function (): void {
    file_put_contents($this->root.'/config/toolkit.php', "<?php return ['gates' => ['lint' => ['command' => 'pint --test --dirty']]];");

    $config = ToolkitConfig::load($this->root);

    expect($config['gates']['lint'])->toBe(['label' => 'Lint', 'command' => 'pint --test --dirty'])
        ->and(array_keys($config['gates']))->toBe(['pest-coverage', 'browser', 'frontend-coverage', 'lint', 'types']);
});

it('resolves env() in config files from the shell first, then .env, and leaves no value behind', function (): void {
    file_put_contents($this->root.'/.env', "TOOLKIT_CONFIG_TEST_FROM_DOTENV=dotenv\nTOOLKIT_CONFIG_TEST_FROM_SHELL=dotenv\n");
    file_put_contents($this->root.'/config/toolkit.php', "<?php return ['probe' => [env('TOOLKIT_CONFIG_TEST_FROM_DOTENV'), env('TOOLKIT_CONFIG_TEST_FROM_SHELL')]];");
    $_SERVER['TOOLKIT_CONFIG_TEST_FROM_SHELL'] = 'shell';

    $config = ToolkitConfig::load($this->root);

    expect($config['probe'])->toBe(['dotenv', 'shell'])
        ->and(getenv('TOOLKIT_CONFIG_TEST_FROM_DOTENV'))->toBeFalse()
        ->and(array_key_exists('TOOLKIT_CONFIG_TEST_FROM_DOTENV', $_ENV))->toBeFalse()
        ->and(array_key_exists('TOOLKIT_CONFIG_TEST_FROM_DOTENV', $_SERVER))->toBeFalse()
        ->and(Env::get('TOOLKIT_CONFIG_TEST_FROM_DOTENV'))->toBeNull();
});

it('reads the package config alone when the app has neither config/toolkit.php nor .env', function (): void {
    expect(ToolkitConfig::load($this->root)['paths']['json_file'])->toBe('storage/logs/test-failures.json');
});
