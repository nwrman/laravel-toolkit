<?php

declare(strict_types=1);

use Nwrman\LaravelToolkit\Report\FailureReport;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/toolkit-report-'.uniqid();
    $this->report = new FailureReport($this->dir.'/logs/failures.json', $this->dir.'/logs/failures.md');
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->dir));
});

function markdownWithoutDate(string $file): string
{
    return (string) preg_replace('/^\*\*Generated:\*\* .*$/m', '**Generated:** <date>', (string) file_get_contents($file));
}

it('keeps each runner\'s section when the other writes, and renders both', function (): void {
    $this->report->put(FailureReport::FRONTEND, ['failed' => 1, 'markdown' => "## Frontend (Vitest)\n\nfront"]);
    $this->report->put(FailureReport::BACKEND, ['failed' => 2, 'markdown' => "## Backend (Pest)\n\nback\n"]);

    expect($this->report->section(FailureReport::FRONTEND))->toBe(['failed' => 1, 'markdown' => "## Frontend (Vitest)\n\nfront"])
        ->and($this->report->section(FailureReport::BACKEND))->toBe(['failed' => 2, 'markdown' => "## Backend (Pest)\n\nback\n"])
        ->and(markdownWithoutDate($this->dir.'/logs/failures.md'))->toBe(
            "# Test Failure Report\n\n**Generated:** <date>\n\n## Backend (Pest)\n\nback\n\n## Frontend (Vitest)\n\nfront\n\n## Re-run every failed test\n\n```bash\ncomposer test:retry\n```\n",
        );
});

it('removes only the passing runner\'s section', function (): void {
    $this->report->put(FailureReport::BACKEND, ['markdown' => 'back']);
    $this->report->put(FailureReport::FRONTEND, ['markdown' => 'front']);

    $this->report->put(FailureReport::BACKEND, null);

    expect($this->report->section(FailureReport::BACKEND))->toBeNull()
        ->and($this->report->section(FailureReport::FRONTEND))->toBe(['markdown' => 'front'])
        ->and(markdownWithoutDate($this->dir.'/logs/failures.md'))->not->toContain('back');
});

it('deletes both files once no section is left', function (): void {
    $this->report->put(FailureReport::BACKEND, ['markdown' => 'back']);

    $this->report->put(FailureReport::BACKEND, null);

    expect(file_exists($this->dir.'/logs/failures.json'))->toBeFalse()
        ->and(file_exists($this->dir.'/logs/failures.md'))->toBeFalse();
});

it('takes over a lock a crashed run left behind', function (): void {
    mkdir($this->dir.'/logs/failures.json.lock', 0755, true);
    touch($this->dir.'/logs/failures.json.lock', time() - 120);

    $this->report->put(FailureReport::BACKEND, ['markdown' => 'back']);

    expect($this->report->section(FailureReport::BACKEND))->toBe(['markdown' => 'back'])
        ->and(is_dir($this->dir.'/logs/failures.json.lock'))->toBeFalse();
});

it('reads no section from a report that is not JSON', function (): void {
    mkdir($this->dir.'/logs', 0755, true);
    file_put_contents($this->dir.'/logs/failures.json', 'not json');

    expect($this->report->section(FailureReport::BACKEND))->toBeNull();
});
