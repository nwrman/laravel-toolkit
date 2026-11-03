<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Pest;

use SimpleXMLElement;

/**
 * The failure report's Pest section, built from the run's JUnit file. Paratest merges
 * the workers' files into it before Pest's plugins add their output, so a parallel run
 * reads the same as a serial one.
 */
final class BackendSection
{
    /**
     * The section after this run: the failures it found, plus the earlier failures of
     * tests it did not run, so a narrow re-run of one test keeps the others on record.
     * Null when nothing is left to report.
     *
     * @param  array<int, array{file: string, class: string, name: string, line: int, type: string, message: string}>  $earlier
     * @return array<string, mixed>|null
     */
    public static function from(string $junitFile, int $startedAt, int $exitCode, string $command, array $earlier = []): ?array
    {
        clearstatcache(true, $junitFile);
        $written = is_file($junitFile) && (int) filemtime($junitFile) >= $startedAt;
        $xml = $written ? @simplexml_load_file($junitFile) : false;

        if (! $xml instanceof SimpleXMLElement) {
            if ($exitCode === 0) {
                return $earlier === [] ? null : self::section($command, 0, $earlier, []);
            }

            return self::section($command, 0, $earlier, [
                sprintf('Pest exited with code %d and wrote no test results: it stopped before the run ended (a fatal error, a crash, or a bootstrap failure). Read the terminal output above the summary.', $exitCode),
            ]);
        }

        $failures = [];
        $ran = [];
        $total = 0;
        self::collect($xml, $failures, $ran, $total);

        $kept = array_values(array_filter($earlier, fn (array $failure): bool => ! isset($ran[$failure['class'].'::'.$failure['name']])));
        $notes = $kept === [] ? [] : [sprintf('%d failure(s) below are from an earlier run: this run did not run those tests.', count($kept))];

        if ($failures === [] && $exitCode !== 0) {
            $notes[] = $total === 0
                ? sprintf('Pest exited with code %d and ran no test: the selection matched none.', $exitCode)
                : sprintf('Pest exited with code %d, but no test failed. Look for a risky test, a warning or a deprecation the configuration treats as a failure, in the terminal output.', $exitCode);
        }

        if ($failures === [] && $kept === [] && $exitCode === 0) {
            return null;
        }

        return self::section($command, $total, [...$failures, ...$kept], $notes);
    }

    /**
     * A section that reports no test, only why the run produced none.
     *
     * @return array<string, mixed>
     */
    public static function notice(string $command, string $message): array
    {
        return self::section($command, 0, [], [$message]);
    }

    /**
     * @param  array<int, array{file: string, class: string, name: string, line: int, type: string, message: string}>  $failures
     * @param  array<string, true>  $ran
     */
    private static function collect(SimpleXMLElement $suite, array &$failures, array &$ran, int &$total): void
    {
        foreach ($suite->testcase as $testcase) {
            $total++;
            $ran[$testcase['class'].'::'.$testcase['name']] = true;
            $failure = $testcase->failure->count() > 0 ? $testcase->failure : ($testcase->error->count() > 0 ? $testcase->error : null);

            if ($failure === null) {
                continue;
            }

            $file = explode('::', (string) $testcase['file'], 2)[0];
            $name = (string) $testcase['name'];
            $message = mb_trim((string) $failure);

            $failures[] = [
                'file' => $file,
                'class' => (string) $testcase['class'],
                'name' => $name,
                'line' => (int) $testcase['line'],
                'type' => ($testcase->failure->count() > 0 ? 'Failure' : 'Error').((string) $failure['type'] !== '' ? ' ('.$failure['type'].')' : ''),
                // Pest starts the message with the test's name.
                'message' => $name !== '' && str_starts_with($message, $name) ? mb_ltrim(mb_substr($message, mb_strlen($name))) : $message,
            ];
        }

        foreach ($suite->testsuite as $nested) {
            self::collect($nested, $failures, $ran, $total);
        }
    }

    /**
     * @param  array<int, array{file: string, class: string, name: string, line: int, type: string, message: string}>  $failures
     * @param  array<int, string>  $notes
     * @return array<string, mixed>
     */
    private static function section(string $command, int $total, array $failures, array $notes): array
    {
        $markdown = "## Backend (Pest)\n\n";
        $markdown .= sprintf("**Command:** `%s` | **Ran:** %s | **Tests run:** %d | **Failed:** %d\n\n", $command, date('Y-m-d H:i:s'), $total, count($failures));

        foreach ($notes as $note) {
            $markdown .= "> [!WARNING]\n> {$note}\n\n";
        }

        foreach ($failures as $index => $failure) {
            $location = $failure['file'].($failure['line'] > 0 ? ':'.$failure['line'] : '');
            $markdown .= sprintf("### %d. `%s`\n\n", $index + 1, $failure['name']);
            $markdown .= sprintf("- **File:** `%s`\n- **Class:** `%s`\n- **Type:** %s\n\n", $location, $failure['class'], $failure['type']);
            $markdown .= "```\n".$failure['message']."\n```\n\n";
            $markdown .= "Re-run it:\n\n```bash\n".RetryFilter::command($failure['file'], $failure['class'], $failure['name'])."\n```\n\n";
        }

        return [
            'command' => $command,
            'ran_at' => date('c'),
            'total' => $total,
            'failed' => count($failures),
            'failures' => $failures,
            'notes' => $notes,
            'markdown' => $markdown,
        ];
    }
}
