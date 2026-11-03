<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Report;

use Closure;
use RuntimeException;

/**
 * `test-failures.json` and `test-failures.md`, shared by every test runner.
 *
 * The JSON holds one section per runner (`backend` from the Pest plugin, `frontend`
 * from the Vitest reporter), each with its failures and its own Markdown. Each runner
 * replaces only its own section, so a Pest run never erases a Vitest failure or the
 * other way round, and the Markdown is rebuilt from every section on each write. With
 * no section left, both files are deleted. Writes take a directory lock the Vitest
 * reporter takes too, because preflight runs both at once.
 */
final readonly class FailureReport
{
    public const string BACKEND = 'backend';

    public const string FRONTEND = 'frontend';

    private const int LOCK_TIMEOUT_SECONDS = 10;

    private const int STALE_LOCK_SECONDS = 60;

    public function __construct(private string $jsonFile, private string $markdownFile) {}

    /**
     * @return array<string, mixed>|null
     */
    public function section(string $name): ?array
    {
        $section = $this->read()['sections'][$name] ?? null;

        if (! is_array($section)) {
            return null;
        }

        /** @var array<string, mixed> $section */
        return $section;
    }

    /**
     * Replaces $name's section, or removes it when $section is null.
     *
     * @param  array<string, mixed>|null  $section
     */
    public function put(string $name, ?array $section): void
    {
        $this->locked(function () use ($name, $section): void {
            $sections = $this->read()['sections'];

            if ($section === null) {
                unset($sections[$name]);
            } else {
                $sections[$name] = $section;
            }

            if ($sections === []) {
                $this->quietly(fn (): bool => unlink($this->jsonFile));
                $this->quietly(fn (): bool => unlink($this->markdownFile));

                return;
            }

            ksort($sections);
            $this->write($this->jsonFile, (string) json_encode(['generated' => date('c'), 'sections' => $sections], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->write($this->markdownFile, self::markdown($sections));
        });
    }

    /**
     * @param  array<string, mixed>  $sections
     */
    public static function markdown(array $sections): string
    {
        $markdown = "# Test Failure Report\n\n**Generated:** ".date('Y-m-d H:i:s')."\n\n";

        foreach ($sections as $section) {
            if (is_array($section) && is_string($section['markdown'] ?? null)) {
                $markdown .= mb_rtrim($section['markdown'])."\n\n";
            }
        }

        return $markdown."## Re-run every failed test\n\n```bash\ncomposer test:retry\n```\n";
    }

    /**
     * @return array{sections: array<string, mixed>}
     */
    private function read(): array
    {
        $data = is_file($this->jsonFile) ? json_decode((string) file_get_contents($this->jsonFile), true) : null;
        $sections = is_array($data) && is_array($data['sections'] ?? null) ? $data['sections'] : [];

        /** @var array<string, mixed> $sections */
        return ['sections' => $sections];
    }

    private function write(string $file, string $contents): void
    {
        $directory = dirname($file);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $temporary = $file.'.tmp-'.getmypid();
        file_put_contents($temporary, $contents);
        rename($temporary, $file);
    }

    /**
     * @param  Closure(): void  $callback
     */
    private function locked(Closure $callback): void
    {
        $lock = $this->jsonFile.'.lock';
        $directory = dirname($lock);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $deadline = microtime(true) + self::LOCK_TIMEOUT_SECONDS;

        while (! $this->quietly(fn (): bool => mkdir($lock))) {
            clearstatcache(true, $lock);

            if (is_dir($lock) && time() - (int) filemtime($lock) > self::STALE_LOCK_SECONDS) {
                $this->quietly(fn (): bool => rmdir($lock));

                continue;
            }

            if (microtime(true) > $deadline) {
                throw new RuntimeException(sprintf('Could not lock %s: another test run holds %s.', $this->jsonFile, $lock));
            }

            usleep(50_000);
        }

        try {
            $callback();
        } finally {
            $this->quietly(fn (): bool => rmdir($lock));
        }
    }

    /**
     * Runs a filesystem call whose failure is an answer, not an error: another run
     * holding the lock, a file already gone.
     *
     * @param  Closure(): bool  $call
     */
    private function quietly(Closure $call): bool
    {
        set_error_handler(fn (): bool => true);

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
