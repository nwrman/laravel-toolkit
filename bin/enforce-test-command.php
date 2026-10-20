<?php

declare(strict_types=1);

/**
 * Claude Code PreToolUse guard (Bash), shipped by nwrman/laravel-toolkit.
 *
 * Runs from vendor/ — `.claude/settings.json` points at this file, so a
 * `composer update` keeps every app on the current version instead of a copy
 * frozen at install time.
 *
 * Backstop for the "Running Tests" AI guideline: blocks raw test runners so runs go
 * through the toolkit's composer wrappers, which produce the structured failure
 * report. Steering lives in the guideline; this only fires when the agent ignores it.
 *
 * Allowed (never blocked): every `composer test*` script — including the bare
 * `composer test`, which Test Impact Analysis turned into the fast, safe default —
 * `composer preflight`, single-test debugging via `--filter`, and frontend runners
 * (vitest / bun).
 *
 * Contract: reads the PreToolUse JSON from stdin. On a blocked command it prints a
 * `deny` decision and exits 0; otherwise it stays silent and exits 0. It fails open
 * — malformed or empty input never breaks the agent's Bash.
 *
 * Only the command itself is inspected: quoted strings and heredoc bodies are
 * removed first, so a commit message, a `grep 'pest'` or a script written to
 * disk that merely mentions a runner is not mistaken for running one.
 */
$raw = file_get_contents('php://stdin');

if (! is_string($raw) || mb_trim($raw) === '') {
    exit(0);
}

$payload = json_decode($raw, true);

if (! is_array($payload)) {
    exit(0);
}

// Defensive: only govern Bash, even though settings.json already scopes the matcher.
$toolName = $payload['tool_name'] ?? 'Bash';

if (is_string($toolName) && $toolName !== 'Bash') {
    exit(0);
}

$command = $payload['tool_input']['command'] ?? '';

if (! is_string($command) || mb_trim($command) === '') {
    exit(0);
}

// Split into command segments on shell separators so we inspect each invocation —
// after dropping the text that is data rather than a command.
$segments = preg_split('/(?:&&|\|\||;|\||\n|\(|\))/', withoutQuotedText($command)) ?: [$command];

foreach ($segments as $segment) {
    if (isBlockedTestCommand((string) $segment)) {
        denyWithGuidance();
    }
}

exit(0);

/**
 * Remove heredoc bodies and the contents of quoted strings. What is left is the
 * shell's own words, which is all a runner check should look at. `--filter` stays
 * visible: the flag itself is never inside the quotes, only its value.
 */
function withoutQuotedText(string $command): string
{
    $command = (string) preg_replace('/<<-?\s*([\'"]?)(\w+)\1[^\n]*\n.*?(?:\n\s*\2\b|\z)/s', '', $command);

    // One left-to-right pass, the way the shell reads it. Two separate regex passes
    // (single quotes, then double) let an apostrophe inside a double-quoted commit
    // message pair with a later single quote, which unbalanced the double quotes and
    // exposed a message line starting with "Pest" as if it were a command.
    $kept = '';
    $quote = null;
    $length = strlen($command);

    for ($i = 0; $i < $length; $i++) {
        $char = $command[$i];

        if ($quote === null) {
            if ($char === "'" || $char === '"') {
                $quote = $char;
            }

            $kept .= $char;

            continue;
        }

        // Inside double quotes a backslash escapes the next character; inside single
        // quotes nothing does.
        if ($quote === '"' && $char === '\\') {
            $i++;

            continue;
        }

        if ($char === $quote) {
            $kept .= $char;
            $quote = null;
        }
    }

    return $kept;
}

/**
 * A segment is blocked when it runs a raw PHP test runner, unless it scopes to a
 * single test with `--filter`.
 */
function isBlockedTestCommand(string $segment): bool
{
    $segment = mb_trim($segment);

    if ($segment === '') {
        return false;
    }

    // Single-test debugging is always allowed.
    if (str_contains($segment, '--filter')) {
        return false;
    }

    // `pest --baseline` prints the TIA cache directory and exits; it runs no tests.
    if (preg_match('/(?:^|\s)--baseline(?:\s|$)/', $segment) === 1) {
        return false;
    }

    // Strip leading env-var assignments (e.g. `XDEBUG_MODE=coverage pest`).
    $segment = preg_replace('/^(?:[A-Za-z_][A-Za-z0-9_]*=\S*\s+)+/', '', $segment) ?? $segment;

    // Raw pest / phpunit (full or per-suite runs).
    if (preg_match('#^(?:php[\d.]*\s+)?(?:\./)?(?:vendor/bin/)?(?:pest|phpunit)\b#i', $segment) === 1) {
        return true;
    }

    // `php artisan test` (full or per-suite runs).
    if (preg_match('#^(?:php[\d.]*\s+)?artisan\s+test\b#i', $segment) === 1) {
        return true;
    }

    // `composer test` used to be blocked as the slow full sweep. Test Impact Analysis
    // inverted that: a full run replays only the tests the working tree can affect, so
    // it is now both the fastest option and the only one that can't under-test by
    // guessing the wrong suite. Allowed.

    return false;
}

/**
 * Emit the PreToolUse deny decision (pointing back to the wrappers) and exit.
 */
function denyWithGuidance(): never
{
    $reason = <<<'TXT'
        Run tests through the toolkit's composer wrappers, which write the failure report. Check in this order:
          1. the test you touched:      php artisan test --filter='Name'
          2. what the change reaches:   composer test     (TIA picks the affected tests; -- --filter=X narrows)
          3. the ship gate, once:       composer preflight
          4. after a failure, only it:  php artisan toolkit:preflight --retry   (or --gate=lint|types|pest-coverage)
        composer test:ci is the "would CI pass?" check — once, never to confirm a fix.
        See the "Running Tests" guideline.
        TXT;

    echo json_encode([
        'hookSpecificOutput' => [
            'hookEventName' => 'PreToolUse',
            'permissionDecision' => 'deny',
            'permissionDecisionReason' => $reason,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    exit(0);
}
