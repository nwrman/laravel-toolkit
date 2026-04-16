<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/test-report-'.uniqid();
    File::ensureDirectoryExists($this->tempDir);

    config([
        'toolkit.paths.report_dir' => $this->tempDir,
        'toolkit.paths.xml_file' => $this->tempDir.'/test-results.xml',
        'toolkit.paths.report_file' => $this->tempDir.'/test-failures.md',
        'toolkit.paths.json_file' => $this->tempDir.'/test-failures.json',
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->tempDir);
});

it('runs backend suites successfully', function (): void {
    Process::fake([
        'pest *' => Process::result(output: 'Backend Output', exitCode: 0),
    ]);

    $this->artisan('toolkit:report', ['--suite' => 'unit,feature', '--no-notify' => true])
        ->expectsOutput('Running tests for suites: unit, feature')
        ->expectsOutput('✓ All tests passed!')
        ->assertExitCode(0);

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'pest --testsuite=Unit,Feature'));
});

it('generates report when a suite fails', function (): void {
    Process::fake([
        'pest *' => Process::result(output: 'Failures', exitCode: 1),
        'command -v terminal-notifier' => Process::result(exitCode: 0),
        'terminal-notifier *' => Process::result(exitCode: 0),
    ]);

    $xml = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <testsuites>
        <testsuite name="Feature" tests="3">
            <testcase name="it fails something" class="Tests\Feature\ExampleTest" file="Tests/Feature/ExampleTest.php" line="12">
                <failure type="Exception">Failed asserting that false is true.</failure>
            </testcase>
            <testcase name="another fail" class="Tests\Feature\ExampleTest" file="Tests/Feature/ExampleTest.php" line="20">
                <error type="Exception">Error triggered.</error>
            </testcase>
            <testcase name="passed test" class="Tests\Feature\ExampleTest" file="" line=""/>
        </testsuite>
    </testsuites>
    XML;

    File::put($this->tempDir.'/test-results.xml', $xml);

    $this->artisan('toolkit:report', ['--suite' => 'feature', '--no-notify' => true])
        ->expectsOutput('✗ Some tests failed. Generating report...')
        ->assertExitCode(1);

    expect(File::exists($this->tempDir.'/test-failures.md'))->toBeTrue()
        ->and(File::exists($this->tempDir.'/test-failures.json'))->toBeTrue();

    $json = json_decode(File::get($this->tempDir.'/test-failures.json'), true);
    expect($json['backend_failures'])->toContain('it fails something', 'another fail');
});

it('triggers frontend build before browser tests if forced', function (): void {
    Process::fake([
        'vp build' => Process::result(output: 'Build Output', exitCode: 0),
        'pest *' => Process::result(exitCode: 0),
    ]);

    $this->artisan('toolkit:report', ['--suite' => 'browser', '--no-notify' => true, '--force-build' => true])
        ->expectsOutputToContain('rebuilding frontend assets...')
        ->assertExitCode(0);

    Process::assertRan('vp build');
});

it('aborts when no suites are selected', function (): void {
    $this->artisan('toolkit:report', ['--suite' => ' ', '--no-notify' => true])
        ->expectsOutput('No suites selected. Exiting.')
        ->assertExitCode(0);
});

it('aborts when browser suite is selected but build fails', function (): void {
    Process::fake([
        'vp build' => Process::result(errorOutput: 'Build Failed', exitCode: 1),
    ]);

    $this->artisan('toolkit:report', ['--suite' => 'browser', '--force-build' => true, '--no-notify' => true])
        ->expectsOutput('✗ Frontend build failed. Fix build errors before proceeding.')
        ->assertExitCode(1);
});

it('runs frontend tests and logs failure', function (): void {
    Process::fake([
        'bun run test:ui' => Process::result(output: "Vitest \x1b[31mOutput Error\x1b[0m", exitCode: 1),
    ]);

    $this->artisan('toolkit:report', ['--suite' => 'frontend', '--no-notify' => true])
        ->expectsOutput('Running tests for suites: frontend')
        ->expectsOutput('✗ Some tests failed. Generating report...')
        ->assertExitCode(1);

    $json = json_decode(File::get($this->tempDir.'/test-failures.json'), true);
    expect($json['frontend_failed'])->toBeTrue();
    expect(File::get($this->tempDir.'/test-failures.md'))->toContain('Vitest Output Error');
});

it('handles missing backend xml file', function (): void {
    Process::fake([
        'pest *' => Process::result(exitCode: 1),
    ]);

    $this->artisan('toolkit:report', ['--suite' => 'unit', '--no-notify' => true])
        ->assertExitCode(1);

    expect(File::get($this->tempDir.'/test-failures.md'))->toContain('Backend test results XML not found');
});

it('handles unparseable backend xml file', function (): void {
    File::put($this->tempDir.'/test-results.xml', '<broken xml>>');

    Process::fake([
        'pest *' => Process::result(exitCode: 1),
    ]);

    $this->artisan('toolkit:report', ['--suite' => 'unit', '--no-notify' => true])
        ->assertExitCode(1);

    expect(File::get($this->tempDir.'/test-failures.md'))->toContain('Could not parse Backend test results XML');
});

it('selects all suites non-interactively when no suite is provided', function (): void {
    Process::fake([
        'find *' => Process::result(output: '', exitCode: 0),
        'vp build' => Process::result(exitCode: 0),
        'pest *' => Process::result(exitCode: 0),
        'bun run test:ui' => Process::result(exitCode: 0),
    ]);

    $this->artisan('toolkit:report', ['--no-notify' => true, '--no-interaction' => true])
        ->expectsOutput('Running tests for suites: unit, feature, browser, frontend')
        ->assertExitCode(0);
});

it('triggers terminal-notifier when tests finish and notify is not skipped', function (): void {
    Process::fake([
        'pest *' => Process::result(exitCode: 0),
        'command -v terminal-notifier' => Process::result(exitCode: 0),
        'terminal-notifier *' => Process::result(exitCode: 0),
    ]);

    $this->artisan('toolkit:report', ['--suite' => 'unit'])
        ->assertExitCode(0);

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'terminal-notifier'));
});

it('triggers afplay when terminal-notifier is missing', function (): void {
    Process::fake([
        'pest *' => Process::result(exitCode: 0),
        'command -v terminal-notifier' => Process::result(exitCode: 1),
        'afplay *' => Process::result(exitCode: 0),
    ]);

    $this->artisan('toolkit:report', ['--suite' => 'unit'])
        ->assertExitCode(0);

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'afplay'));
});

it('increments total tests when tests attribute is missing in xml', function (): void {
    Process::fake([
        'pest *' => Process::result(exitCode: 1),
    ]);

    $xml = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <testsuites>
        <testsuite name="Feature">
            <testcase name="it fails something" class="Tests\Feature\ExampleTest" file="Tests\Feature\ExampleTest.php" line="12">
                <failure type="Exception">Failed asserting that false is true.</failure>
            </testcase>
        </testsuite>
    </testsuites>
    XML;

    File::put($this->tempDir.'/test-results.xml', $xml);

    $this->artisan('toolkit:report', ['--suite' => 'feature', '--no-notify' => true])
        ->assertExitCode(1);

    expect(File::get($this->tempDir.'/test-failures.md'))->toContain('**Total:** 1');
});
